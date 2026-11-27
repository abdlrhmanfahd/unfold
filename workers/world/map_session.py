"""Build a 3D map and the car's path from one QCar 2 session (LiDAR + IMU), without ROS.

    python map_session.py <session_folder> <out_folder> [--start S] [--duration D]

Method (LiDAR odometry, scan-to-local-map):
  1. Each scan is de-skewed with the gyro (rotation during the 0.1 s sweep) and levelled with gravity.
  2. It is aligned to a local map of recent keyframes with point-to-plane ICP (6 DoF).
     The motion model (constant velocity + gyro rotation) is the starting guess and a weak prior,
     so long featureless corridors do not make the solution slide.
  3. All scans are put in one frame at their final poses and voxelised into the map.

Outputs in <out_folder>:
  map.bin    planar little-endian: int16 x[n], y[n], z[n] (cm, world frame: x fwd at start, z up),
             uint8 reflectivity[n] (mean), uint8 hits[n] (scans that saw the voxel, capped 255); n = size / 8
  path.json  {t, x, y, z, yaw, qw, qx, qy, qz} per scan, seconds since t0_epoch
  map.json   summary (counts, voxel size, extent, run time, quality numbers)
  preview_top.png, preview_side.png  quick look
"""
from __future__ import annotations

import argparse
import io
import json
import math
import os
import sys
import time

import numpy as np
from PIL import Image, ImageDraw
from scipy.spatial import cKDTree

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from export_session import img_time_offset, level_rotation, read_notes  # noqa: E402
from rosbag_cdr import TOPIC_IMAGE, TOPIC_IMU, TOPIC_LIDAR, Bag, decode_image, decode_imu, decode_lidar  # noqa: E402

SCAN_VOXEL = 0.15      # m, ICP source
LOCAL_VOXEL = 0.12     # m, local map
MAP_VOXEL = 0.06       # m, final map
RANGE_MIN, RANGE_MAX = 0.8, 30.0
KEY_TRANS, KEY_ROT = 0.35, math.radians(8)
LOCAL_KEYFRAMES = 40
PRIOR_W = 40.0         # weight of the motion-model prior on translation
SCAN_POINTS = 5000     # live scan points kept per scan
SCAN_CHUNK = 50        # scans per bundle (10 s at 5 Hz)
FRAME_W = 480          # camera frame width
FRAME_CHUNK = 50       # frames per bundle (10 s at 5 fps)


# ---------- small SE(3) helpers ----------
def skew(v):
    return np.array([[0, -v[2], v[1]], [v[2], 0, -v[0]], [-v[1], v[0], 0]])


def so3_exp(w):
    th = np.linalg.norm(w)
    if th < 1e-9:
        return np.eye(3) + skew(w)
    k = skew(w / th)
    return np.eye(3) + math.sin(th) * k + (1 - math.cos(th)) * k @ k


def so3_log(R):
    c = max(-1.0, min(1.0, (np.trace(R) - 1) / 2))
    th = math.acos(c)
    if th < 1e-9:
        return np.zeros(3)
    return th / (2 * math.sin(th)) * np.array([R[2, 1] - R[1, 2], R[0, 2] - R[2, 0], R[1, 0] - R[0, 1]])


def quat(R):
    w = math.sqrt(max(0.0, 1 + R[0, 0] + R[1, 1] + R[2, 2])) / 2
    x = math.copysign(math.sqrt(max(0.0, 1 + R[0, 0] - R[1, 1] - R[2, 2])) / 2, R[2, 1] - R[1, 2])
    y = math.copysign(math.sqrt(max(0.0, 1 - R[0, 0] + R[1, 1] - R[2, 2])) / 2, R[0, 2] - R[2, 0])
    z = math.copysign(math.sqrt(max(0.0, 1 - R[0, 0] - R[1, 1] + R[2, 2])) / 2, R[1, 0] - R[0, 1])
    return w, x, y, z


def voxel(p, size, extra=None):
    k = np.floor(p / size).astype(np.int64)
    _, idx = np.unique(k, axis=0, return_index=True)
    return p[idx] if extra is None else (p[idx], extra[idx])


def normals(tree, pts, k=10):
    _, nb = tree.query(pts, k=k)
    q = pts[nb]                              # (n, k, 3)
    q = q - q.mean(1, keepdims=True)
    cov = np.einsum("nki,nkj->nij", q, q) / k
    w, v = np.linalg.eigh(cov)
    n = v[:, :, 0]                           # smallest eigenvalue
    planar = w[:, 0] < 0.25 * w[:, 1]        # reject non-planar neighbourhoods
    return n, planar


# ---------- ICP ----------
def icp(src, tree, mp, mn, T0, iters=20):
    T = T0.copy()
    used = 0
    for it in range(iters):
        maxd = 1.0 if it < 5 else 0.5 if it < 12 else 0.3
        P = src @ T[:3, :3].T + T[:3, 3]
        d, idx = tree.query(P, distance_upper_bound=maxd)
        ok = np.isfinite(d)
        if ok.sum() < 50:
            break
        p, q, n = P[ok], mp[idx[ok]], mn[idx[ok]]
        r = ((p - q) * n).sum(1)
        a = np.abs(r)
        w = np.where(a < 0.1, 1.0, 0.1 / np.maximum(a, 1e-9))  # Huber
        J = np.hstack([np.cross(p, n), n])
        H = J.T @ (J * w[:, None])
        b = -J.T @ (w * r)
        H[3:, 3:] += PRIOR_W * np.eye(3)
        b[3:] += -PRIOR_W * (T[:3, 3] - T0[:3, 3])
        dx = np.linalg.solve(H + 1e-6 * np.eye(6), b)
        dT = np.eye(4)
        dT[:3, :3] = so3_exp(dx[:3])
        dT[:3, 3] = dx[3:]
        T = dT @ T
        used = int(ok.sum())
        if np.linalg.norm(dx[:3]) < 1e-4 and np.linalg.norm(dx[3:]) < 1e-3:
            break
    rms = float(np.sqrt(np.mean(r ** 2))) if used else float("nan")
    return T, used, rms


def build_map(session_folder: str, out_folder: str, start: float = 0.0, duration: float = 0.0, progress=None) -> dict:
    """Map + path + live-scan bundles for one session. progress(fraction 0..1, stage) is optional."""
    a = argparse.Namespace(session_folder=session_folder, out_folder=out_folder, start=start, duration=duration)
    say = progress or (lambda f, s: None)
    t_run = time.time()
    os.makedirs(a.out_folder, exist_ok=True)

    bag = Bag(os.path.join(a.session_folder, "bag", "bag_0.db3"))
    s0, s1 = bag.span_ns()

    # IMU: gravity level + gyro for de-skew and rotation prior
    imu = [decode_imu(b) for b in bag.iter(TOPIC_IMU)]
    it = np.array([s.t for s in imu])
    gyro = np.array([s.gyro for s in imu])
    acc = np.array([s.accel for s in imu])
    R_level, tilt, roll = level_rotation(acc)
    t0 = it[0]

    q0 = s0 + int(a.start * 1e9)
    q1 = s1 if a.duration <= 0 else q0 + int(a.duration * 1e9)

    poses, stamps, scans = [], [], []
    keyframes = []
    tree = mp = mn = None
    T = np.eye(4)
    v_prev = np.zeros(3)
    last_key = None
    rms_all, used_all = [], []
    n_scans = 0
    for blob in bag.iter(TOPIC_LIDAR, q0, q1):
        stamp, timebase, pts = decode_lidar(blob)
        if len(pts) == 0:
            continue
        xyz = np.stack([pts["x"], pts["y"], pts["z"]], 1).astype(np.float64)
        rng = np.linalg.norm(xyz, axis=1)
        keep = (rng > RANGE_MIN) & (rng < RANGE_MAX)
        xyz, refl, dt = xyz[keep], pts["reflectivity"][keep], pts["offset_time"][keep] * 1e-9
        # de-skew: rotate every point back to the scan start with the gyro over the sweep
        j = np.clip(np.searchsorted(it, stamp), 0, len(it) - 1)
        jj = slice(j, min(len(it), j + 22))
        w = gyro[jj].mean(0)
        xyz = xyz + np.cross(w[None, :] * dt[:, None], xyz)
        lv = xyz @ R_level.T  # level frame, sensor at origin

        # motion model guess
        if poses:
            dt_scan = stamp - stamps[-1]
            w_lvl = R_level @ w
            R_guess = poses[-1][:3, :3] @ so3_exp(w_lvl * dt_scan)
            T0 = np.eye(4)
            T0[:3, :3] = R_guess
            T0[:3, 3] = poses[-1][:3, 3] + v_prev * dt_scan
        else:
            T0 = np.eye(4)

        src = voxel(lv, SCAN_VOXEL)
        if tree is None:
            T = T0
        else:
            T, used, rms = icp(src, tree, mp, mn, T0)
            rms_all.append(rms)
            used_all.append(used)
        if poses:
            v_prev = 0.5 * v_prev + 0.5 * (T[:3, 3] - poses[-1][:3, 3]) / max(1e-3, stamp - stamps[-1])
        poses.append(T)
        stamps.append(stamp)
        scans.append((voxel(lv, MAP_VOXEL * 0.75, refl.astype(np.float32))))
        n_scans += 1

        # keyframes -> local map
        need = last_key is None
        if not need:
            dtr = np.linalg.norm(T[:3, 3] - last_key[:3, 3])
            drot = np.linalg.norm(so3_log(last_key[:3, :3].T @ T[:3, :3]))
            need = dtr > KEY_TRANS or drot > KEY_ROT
        if need:
            keyframes.append(voxel(lv, LOCAL_VOXEL) @ T[:3, :3].T + T[:3, 3])
            keyframes = keyframes[-LOCAL_KEYFRAMES:]
            mp_all = voxel(np.vstack(keyframes), LOCAL_VOXEL)
            t_ = cKDTree(mp_all)
            nrm, planar = normals(t_, mp_all)
            mp, mn = mp_all[planar], nrm[planar]
            tree = cKDTree(mp)
            last_key = T.copy()
        if n_scans % 50 == 0:
            say(min(1.0, (stamp * 1e9 - q0) / max(1, q1 - q0)), "mapping")
        if n_scans % 200 == 0:
            p = T[:3, 3]
            print(f"  {n_scans} scans  t={stamp - t0:6.1f}s  pos=({p[0]:6.2f},{p[1]:6.2f},{p[2]:5.2f})  "
                  f"rms={np.nanmean(rms_all[-200:]) * 100:4.1f}cm", flush=True)

    # ---------- global map ----------
    P, Rf = [], []
    for T, (p, r) in zip(poses, scans):
        P.append(p @ T[:3, :3].T + T[:3, 3])
        Rf.append(r)
    P, Rf = np.vstack(P), np.concatenate(Rf)
    k = np.floor(P / MAP_VOXEL).astype(np.int64)
    uk, inv, cnt = np.unique(k, axis=0, return_inverse=True, return_counts=True)
    inv = inv.ravel()
    cen = np.stack([np.bincount(inv, P[:, d]) for d in range(3)], 1) / cnt[:, None]
    rf = np.bincount(inv, Rf) / cnt
    keep = cnt >= 2
    cen, rf, cnt = cen[keep], rf[keep], cnt[keep]
    n = len(cen)
    c = np.clip(np.round(cen * 100), -32768, 32767).astype("<i2")
    with open(os.path.join(a.out_folder, "map.bin"), "wb") as f:
        f.write(c[:, 0].tobytes()); f.write(c[:, 1].tobytes()); f.write(c[:, 2].tobytes())
        f.write(np.clip(rf, 0, 255).astype(np.uint8).tobytes())
        f.write(np.clip(cnt, 0, 255).astype(np.uint8).tobytes())

    path = {"t": [], "x": [], "y": [], "z": [], "yaw": [], "qw": [], "qx": [], "qy": [], "qz": []}
    for T, s in zip(poses, stamps):
        qw, qx, qy, qz = quat(T[:3, :3])
        for key, v in zip(("t", "x", "y", "z", "yaw", "qw", "qx", "qy", "qz"),
                          (s - t0, *T[:3, 3], math.atan2(T[1, 0], T[0, 0]), qw, qx, qy, qz)):
            path[key].append(round(float(v), 4))
    json.dump(path, open(os.path.join(a.out_folder, "path.json"), "w"), separators=(",", ":"))

    xyz = np.array([[path["x"][i], path["y"][i], path["z"][i]] for i in range(len(stamps))])

    # floor under the car: map points within 0.8 m (xy) of the path and below the LiDAR
    say(1.0, "scans")
    tree_xy = cKDTree(cen[:, :2])
    under = []
    for q in xyz[::10]:
        idx = tree_xy.query_ball_point(q[:2], 0.8)
        zz = cen[idx, 2]
        zz = zz[zz < q[2] - 0.12]
        if len(zz) >= 5:
            under.append(np.percentile(zz, 20))
    floor_z = float(np.median(under)) if under else float(np.percentile(cen[:, 2], 5))

    # live scans: every 2nd scan (5 Hz), world frame, at most SCAN_POINTS points, bundled per SCAN_CHUNK
    sc = {"rate_hz": 5, "format": "per scan planar little-endian int16 x[n], y[n], z[n] (cm, world frame)",
          "chunks": [], "t": [], "c": [], "o": [], "n": []}
    buf, chunk = bytearray(), 0
    keep_idx = list(range(0, len(poses), 2))
    for k, i in enumerate(keep_idx):
        T, (p, _r) = poses[i], scans[i]
        if len(p) > SCAN_POINTS:
            p = p[np.linspace(0, len(p) - 1, SCAN_POINTS).astype(int)]
        w = np.clip(np.round((p @ T[:3, :3].T + T[:3, 3]) * 100), -32768, 32767).astype("<i2")
        sc["t"].append(round(stamps[i] - t0, 3)); sc["c"].append(chunk); sc["o"].append(len(buf)); sc["n"].append(len(w))
        buf += w[:, 0].tobytes() + w[:, 1].tobytes() + w[:, 2].tobytes()
        if (k + 1) % SCAN_CHUNK == 0 or k == len(keep_idx) - 1:
            name = f"scans-{chunk:03d}.bin"
            open(os.path.join(a.out_folder, name), "wb").write(buf)
            sc["chunks"].append(name); buf, chunk = bytearray(), chunk + 1
    json.dump(sc, open(os.path.join(a.out_folder, "scans.json"), "w"), separators=(",", ":"))
    length = float(np.linalg.norm(np.diff(xyz, axis=0), axis=1).sum()) if len(xyz) > 1 else 0.0
    yaw_icp = np.unwrap(np.array(path["yaw"]))
    # gyro-integrated heading for comparison
    sel = (it >= stamps[0]) & (it <= stamps[-1])
    yaw_rate = (gyro[sel] @ R_level.T)[:, 2]
    yaw_gyro = float(np.sum(yaw_rate[:-1] * np.diff(it[sel])))
    summary = {
        "format_version": "0.1",
        "session": os.path.basename(os.path.normpath(a.session_folder)),
        "t0_epoch": t0,
        "scans": n_scans, "map_points": n, "map_voxel_m": MAP_VOXEL,
        "map_format": "planar little-endian: int16 x[n], y[n], z[n] (cm), uint8 reflectivity[n], uint8 hits[n]; n = size / 8",
        "frame": "world = level frame of the first scan: x forward, y left, z up (metres); origin = LiDAR at the first scan",
        "R_sensor_to_level": np.round(R_level, 6).tolist(), "tilt_deg": round(tilt, 2), "roll_deg": round(roll, 2),
        "extent_m": {"min": np.round(cen.min(0), 2).tolist(), "max": np.round(cen.max(0), 2).tolist()},
        "path_length_m": round(length, 2),
        "duration_s": round(stamps[-1] - stamps[0], 2),
        "floor_z": round(floor_z, 3),
        "ceiling_cut_m": 1.9,
        "end_position_m": np.round(xyz[-1], 2).tolist(),
        "heading_change_deg": {"lidar": round(math.degrees(yaw_icp[-1] - yaw_icp[0]), 1), "gyro": round(math.degrees(yaw_gyro), 1)},
        "icp_rms_cm": {"median": round(float(np.nanmedian(rms_all)) * 100, 2), "p95": round(float(np.nanpercentile(rms_all, 95)) * 100, 2)} if rms_all else None,
        "run_s": round(time.time() - t_run, 1),
    }
    json.dump(summary, open(os.path.join(a.out_folder, "map.json"), "w"), indent=1)

    # ---------- previews ----------
    def preview(ax_u, ax_v, name, flip_v=True, px=0.05):
        u, v = cen[:, ax_u], cen[:, ax_v]
        u0, v0 = u.min(), v.min()
        W, H = int((u.max() - u0) / px) + 21, int((v.max() - v0) / px) + 21
        sc = min(1.0, 2400 / max(W, H))
        W, H = int(W * sc), int(H * sc)
        img = Image.new("RGB", (W, H), (250, 251, 252))
        px2 = px / sc
        uu = ((u - u0) / px2 + 10).astype(int)
        vv = ((v - v0) / px2 + 10).astype(int)
        if flip_v:
            vv = H - 1 - vv
        a_ = np.asarray(img).copy()
        ok = (uu >= 0) & (uu < W) & (vv >= 0) & (vv < H)
        a_[vv[ok], uu[ok]] = (90, 100, 112)
        img = Image.fromarray(a_)
        d = ImageDraw.Draw(img)
        pu = ((xyz[:, ax_u] - u0) / px2 + 10)
        pv = ((xyz[:, ax_v] - v0) / px2 + 10)
        if flip_v:
            pv = H - 1 - pv
        d.line(list(zip(pu.tolist(), pv.tolist())), fill=(11, 138, 122), width=3)
        d.ellipse([pu[0] - 6, pv[0] - 6, pu[0] + 6, pv[0] + 6], fill=(11, 138, 122))
        d.ellipse([pu[-1] - 6, pv[-1] - 6, pu[-1] + 6, pv[-1] + 6], fill=(217, 72, 15))
        img.save(os.path.join(a.out_folder, name))

    sel_mid = (cen[:, 2] > floor_z + 0.15) & (cen[:, 2] < floor_z + 1.6)
    cen_all = cen
    cen = cen_all[sel_mid]
    preview(0, 1, "preview_top.png")
    cen = cen_all
    preview(0, 2, "preview_side.png")

    return summary


def export_frames(session_folder: str, out_folder: str, t0: float, progress=None) -> dict:
    """Front camera at 5 fps, FRAME_W px wide, JPEGs bundled per FRAME_CHUNK, timed on the map clock."""
    say = progress or (lambda f, s: None)
    off = img_time_offset(read_notes(session_folder))
    bag = Bag(os.path.join(session_folder, "bag", "bag_0.db3"))
    total = max(1, bag.count(TOPIC_IMAGE))
    fr = {"fps": 5, "width": FRAME_W, "height": None, "img_time_offset_s": off, "chunks": [], "t": [], "c": [], "o": [], "s": []}
    buf, chunk, kept = bytearray(), 0, 0
    for k, blob in enumerate(bag.iter(TOPIC_IMAGE)):
        if k % 3:
            continue
        stamp, _fmt, data = decode_image(blob)
        im = Image.open(io.BytesIO(data))
        im.draft("RGB", (FRAME_W * 2, FRAME_W * 2))
        im = im.convert("RGB")
        h = round(im.height * FRAME_W / im.width)
        im = im.resize((FRAME_W, h), Image.LANCZOS)
        fr["height"] = h
        b = io.BytesIO()
        im.save(b, "JPEG", quality=70, optimize=True)
        jpg = b.getvalue()
        fr["t"].append(round(stamp + off - t0, 3)); fr["c"].append(chunk); fr["o"].append(len(buf)); fr["s"].append(len(jpg))
        buf += jpg
        kept += 1
        if kept % FRAME_CHUNK == 0:
            name = f"frames-{chunk:03d}.bin"
            open(os.path.join(out_folder, name), "wb").write(buf)
            fr["chunks"].append(name); buf, chunk = bytearray(), chunk + 1
            say(k / total, "frames")
    if buf:
        name = f"frames-{chunk:03d}.bin"
        open(os.path.join(out_folder, name), "wb").write(buf)
        fr["chunks"].append(name)
    json.dump(fr, open(os.path.join(out_folder, "frames.json"), "w"), separators=(",", ":"))
    return {"frames": kept}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("session_folder")
    ap.add_argument("out_folder")
    ap.add_argument("--start", type=float, default=0.0, help="seconds from the recording start")
    ap.add_argument("--duration", type=float, default=0.0, help="seconds (0 = to the end)")
    ap.add_argument("--no-frames", action="store_true", help="skip the camera frames")
    a = ap.parse_args()
    summary = build_map(a.session_folder, a.out_folder, a.start, a.duration)
    if not a.no_frames:
        summary.update(export_frames(a.session_folder, a.out_folder, summary["t0_epoch"]))
    print(json.dumps({k: v for k, v in summary.items() if k != "R_sensor_to_level"}, indent=1))


if __name__ == "__main__":
    main()
