"""Colour the map with the front camera (Stage 2: LiDAR -> camera projection).

    python colour_session.py <session_folder> <out_folder>

Uses what map_session already wrote (map.bin, path.json, map.json, frames.json/bin) plus the
session's calibration:
  calib/calib_final.json  results.T_lidar_camera = [x, y, z, qx, qy, qz, qw], camera -> LiDAR
  calib/intrinsics.yaml   fisheye (Kannala-Brandt / OpenCV cv2.fisheye) fx fy cx cy k1..k4

For every camera frame: car pose at the frame's time -> map points within RANGE in front of the
camera -> fisheye projection -> keep only the nearest point per small pixel block (so walls do not
paint what is behind them) -> add the pixel colour, weighted towards close, central views.
At the end a grey-world white balance over the whole session removes the camera's colour cast.

Writes map_rgb.bin: uint8 r[n], g[n], b[n], seen[n] (frames that coloured the point, capped 255),
in the same order as map.bin.
"""
from __future__ import annotations

import io
import json
import math
import os
import re
import sys

import numpy as np
from PIL import Image
from scipy.spatial import cKDTree

RANGE = 9.0            # m from the camera
MAX_THETA = math.radians(80)
BLOCK = 3              # px: z-buffer block size on the 480 px frames
FRAME_STEP = 1         # use every exported frame (5 fps)


def quat_to_R(qx, qy, qz, qw):
    n = math.sqrt(qx * qx + qy * qy + qz * qz + qw * qw)
    qx, qy, qz, qw = qx / n, qy / n, qz / n, qw / n
    return np.array([
        [1 - 2 * (qy * qy + qz * qz), 2 * (qx * qy - qz * qw), 2 * (qx * qz + qy * qw)],
        [2 * (qx * qy + qz * qw), 1 - 2 * (qx * qx + qz * qz), 2 * (qy * qz - qx * qw)],
        [2 * (qx * qz - qy * qw), 2 * (qy * qz + qx * qw), 1 - 2 * (qx * qx + qy * qy)]])


def read_yaml_numbers(p):
    out = {}
    for line in open(p, encoding="utf-8"):
        m = re.match(r"\s*([a-z_0-9]+)\s*:\s*(-?[0-9.]+)\s*$", line)
        if m:
            out[m.group(1)] = float(m.group(2))
    return out


def build_colours(session_folder: str, out: str, progress=None) -> dict:
    say = progress or (lambda f, s: None)
    meta = json.load(open(os.path.join(out, "map.json")))
    path = json.load(open(os.path.join(out, "path.json")))
    fr = json.load(open(os.path.join(out, "frames.json")))
    cal = json.load(open(os.path.join(session_folder, "calib", "calib_final.json")))
    K = read_yaml_numbers(os.path.join(session_folder, "calib", "intrinsics.yaml"))

    b = open(os.path.join(out, "map.bin"), "rb").read()
    n = len(b) // 8
    P = np.stack([np.frombuffer(b, "<i2", n, k * 2 * n) / 100 for k in range(3)], 1)
    tree = cKDTree(P)

    R_level = np.array(meta["R_sensor_to_level"])               # sensor -> level
    x, y, z, qx, qy, qz, qw = cal["results"]["T_lidar_camera"]  # camera -> LiDAR
    R_lc, t_lc = quat_to_R(qx, qy, qz, qw), np.array([x, y, z])
    s = fr["width"] / K.get("image_width", 1640)
    fx, fy, cx, cy = K["fx"] * s, K["fy"] * s, K["cx"] * s, K["cy"] * s
    k1, k2, k3, k4 = K["k1"], K["k2"], K["k3"], K["k4"]
    Wf, Hf = fr["width"], fr["height"]

    pt = np.array(path["t"])
    pos = np.c_[path["x"], path["y"], path["z"]]
    quats = np.c_[path["qx"], path["qy"], path["qz"], path["qw"]]

    acc = np.zeros((n, 3), np.float64)
    wsum = np.zeros(n, np.float64)
    seen = np.zeros(n, np.int32)
    chunks = {}
    total = len(fr["t"])
    for k in range(0, total, FRAME_STEP):
        t = fr["t"][k]
        if t < pt[0] or t > pt[-1]:
            continue
        i = int(np.clip(np.searchsorted(pt, t), 1, len(pt) - 1))
        f = (t - pt[i - 1]) / max(1e-6, pt[i] - pt[i - 1])
        q = quats[i - 1] * (1 - f) + quats[i] * f * (1 if np.dot(quats[i - 1], quats[i]) >= 0 else -1)
        R_wl = quat_to_R(*q)                                    # level LiDAR -> world
        t_wl = pos[i - 1] * (1 - f) + pos[i] * f
        # camera centre in the world, to pick candidate points
        R_ws = R_wl @ R_level                                    # sensor -> world
        cam_w = R_ws @ t_lc + t_wl
        idx = np.asarray(tree.query_ball_point(cam_w, RANGE), dtype=np.int64)
        if not len(idx):
            continue
        ps = (P[idx] - t_wl) @ R_ws                              # world -> sensor (R_ws^T)
        pc = (ps - t_lc) @ R_lc                                  # sensor -> camera (R_lc^T)
        Z = pc[:, 2]
        front = Z > 0.15
        idx, pc, Z = idx[front], pc[front], Z[front]
        a, bb = pc[:, 0] / Z, pc[:, 1] / Z
        r = np.hypot(a, bb)
        th = np.arctan(r)
        ok = th < MAX_THETA
        idx, pc, Z, a, bb, r, th = idx[ok], pc[ok], Z[ok], a[ok], bb[ok], r[ok], th[ok]
        th2 = th * th
        thd = th * (1 + k1 * th2 + k2 * th2 ** 2 + k3 * th2 ** 3 + k4 * th2 ** 4)
        sc = np.where(r > 1e-9, thd / np.maximum(r, 1e-9), 1.0)
        u, v = fx * sc * a + cx, fy * sc * bb + cy
        inb = (u >= 0) & (u < Wf - 1) & (v >= 0) & (v < Hf - 1)
        idx, u, v, dist = idx[inb], u[inb], v[inb], np.linalg.norm(pc[inb], axis=1)
        if not len(idx):
            continue
        # z-buffer per pixel block: only points close to the nearest one in their block
        bi = (v // BLOCK).astype(np.int64) * (Wf // BLOCK + 1) + (u // BLOCK).astype(np.int64)
        near = np.full(bi.max() + 1, np.inf)
        np.minimum.at(near, bi, dist)
        vis = dist <= near[bi] * 1.04 + 0.08
        idx, u, v, dist = idx[vis], u[vis], v[vis], dist[vis]

        c = fr["c"][k]
        if c not in chunks:
            chunks = {c: open(os.path.join(out, fr["chunks"][c]), "rb").read()}
        jpg = chunks[c][fr["o"][k]:fr["o"][k] + fr["s"][k]]
        img = np.asarray(Image.open(io.BytesIO(jpg)).convert("RGB"), np.float64)
        col = img[v.astype(int), u.astype(int)]
        # trust close points and the middle of the lens more
        rc = np.hypot((u - cx) / Wf, (v - cy) / Hf)
        w = 1.0 / (0.5 + dist) * np.clip(1.2 - 1.6 * rc, 0.1, 1.0)
        np.add.at(acc, idx, col * w[:, None])
        np.add.at(wsum, idx, w)
        np.add.at(seen, idx, 1)
        if k % 50 == 0:
            say(k / total, "colour")

    has = wsum > 0
    rgb = np.zeros((n, 3))
    rgb[has] = acc[has] / wsum[has, None]
    # grey-world white balance over the coloured points
    if has.any():
        mean = rgb[has].mean(0)
        gain = mean.mean() / np.maximum(mean, 1)
        rgb[has] = rgb[has] * gain
        # gentle contrast stretch (2nd..98th percentile of brightness)
        lum = rgb[has].mean(1)
        lo, hi = np.percentile(lum, [2, 98])
        rgb[has] = (rgb[has] - lo) * (235 / max(1, hi - lo)) + 10
    rgb = np.clip(rgb, 0, 255).astype(np.uint8)
    with open(os.path.join(out, "map_rgb.bin"), "wb") as f:
        f.write(rgb[:, 0].tobytes()); f.write(rgb[:, 1].tobytes()); f.write(rgb[:, 2].tobytes())
        f.write(np.clip(seen, 0, 255).astype(np.uint8).tobytes())
    info = {"coloured_points": int(has.sum()), "share": round(float(has.mean()), 3),
            "white_balance_gain": [round(float(g), 3) for g in (gain if has.any() else [1, 1, 1])]}
    meta["colour"] = info
    json.dump(meta, open(os.path.join(out, "map.json"), "w"), indent=1)
    return info


if __name__ == "__main__":
    print(json.dumps(build_colours(sys.argv[1], sys.argv[2], progress=lambda f, s: print(f"  {s} {f * 100:.0f}%", flush=True)), indent=1))
