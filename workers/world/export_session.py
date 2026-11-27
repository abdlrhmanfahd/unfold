"""Export a QCar 2 recording (ROS 2 bag) to web-sized assets for the Unfold session viewer.

    python export_session.py <session_folder> <out_folder> [--start S] [--duration D] [--id ID]

<session_folder> holds bag/bag_0.db3, calib/ and notes.txt. Without --start, the tool picks the
window of --duration seconds (default 30) with the most turning, from the IMU yaw rate.
Only sqlite3 (built in), numpy and Pillow are needed - no ROS. See README.md for the formats.
"""
from __future__ import annotations

import argparse
import io
import json
import math
import os
import re
import shutil
import sys
import time

import numpy as np
from PIL import Image

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from rosbag_cdr import TOPIC_IMAGE, TOPIC_IMU, TOPIC_LIDAR, Bag, decode_image, decode_imu, decode_lidar  # noqa: E402

G = 9.80665
FORMAT_VERSION = 1
FRAME_STEP = 3          # keep every 3rd camera frame: 15 Hz -> 5 fps
FRAME_WIDTH = 640
JPEG_QUALITY = 75
MAX_POINTS = 8000
IMU_RATE = 20           # Hz after averaging
IMG_TIME_OFFSET_DEFAULT = -0.0265


def read_notes(folder: str) -> dict:
    out = {}
    p = os.path.join(folder, "notes.txt")
    if os.path.exists(p):
        for line in open(p, encoding="utf-8", errors="replace"):
            m = re.match(r"\s*([A-Za-z_ ]+?)\s*:\s*(.+)", line)
            if m:
                out[m.group(1).strip()] = m.group(2).strip()
    return out


def img_time_offset(notes: dict) -> float:
    m = re.search(r"(-?\d+(?:\.\d+)?)\s*s", notes.get("img_time_offset", ""))
    return float(m.group(1)) if m else IMG_TIME_OFFSET_DEFAULT


def read_intrinsics(folder: str) -> dict:
    p = os.path.join(folder, "calib", "intrinsics.yaml")
    out = {}
    if os.path.exists(p):
        for line in open(p, encoding="utf-8"):
            m = re.match(r"\s*([a-z_0-9]+)\s*:\s*([^#\n]+)", line)
            if m:
                v = m.group(2).strip().strip('"')
                try:
                    out[m.group(1)] = float(v) if "." in v or v.lstrip("-").isdigit() else v
                except ValueError:
                    out[m.group(1)] = v
    return out


def level_rotation(accel_g: np.ndarray) -> tuple[np.ndarray, float, float]:
    """Rotation from the sensor frame to a level frame (z up, x = sensor x projected on the ground).

    The accelerometer measures the reaction to gravity, so its long-run mean points up.
    """
    up = accel_g.mean(0)
    up /= np.linalg.norm(up)
    x = np.array([1.0, 0.0, 0.0]) - up[0] * up
    x /= np.linalg.norm(x)
    y = np.cross(up, x)
    R = np.vstack([x, y, up])
    tilt = math.degrees(math.acos(max(-1.0, min(1.0, up[2]))))
    roll = math.degrees(math.atan2(up[1], up[2]))
    return R, tilt, roll


def pick_window(t: np.ndarray, yaw: np.ndarray, duration: float, lo: float, hi: float) -> float:
    """Start time (absolute) of the window with the largest integrated |yaw rate|."""
    if hi - lo <= duration:
        return lo
    k = max(1, int(0.5 * 200))
    smooth = np.convolve(np.abs(yaw), np.ones(k) / k, mode="same")
    best, best_s = -1.0, lo
    for s in np.arange(lo, hi - duration, 0.5):
        m = (t >= s) & (t < s + duration)
        v = float(smooth[m].sum())
        if v > best:
            best, best_s = v, float(s)
    return best_s


def write_scan(path: str, pts: np.ndarray) -> int:
    """Planar int16 x, y, z in cm (sensor frame) then uint8 reflectivity. Returns the point count."""
    keep = (pts["x"] != 0) | (pts["y"] != 0) | (pts["z"] != 0)
    pts = pts[keep]
    if len(pts) > MAX_POINTS:
        pts = pts[np.linspace(0, len(pts) - 1, MAX_POINTS).astype(np.int64)]
    q = lambda a: np.clip(np.round(a * 100.0), -32768, 32767).astype("<i2")  # noqa: E731
    with open(path, "wb") as fh:
        fh.write(q(pts["x"]).tobytes())
        fh.write(q(pts["y"]).tobytes())
        fh.write(q(pts["z"]).tobytes())
        fh.write(pts["reflectivity"].astype("u1").tobytes())
    return len(pts)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("session_folder")
    ap.add_argument("out_folder")
    ap.add_argument("--start", type=float, help="clip start in seconds from the beginning of the recording")
    ap.add_argument("--duration", type=float, default=30.0, help="clip length in seconds (default 30; 0 = whole session)")
    ap.add_argument("--id", help="session id used in the manifest (default: the out folder name)")
    args = ap.parse_args()

    t_start_wall = time.time()
    src = os.path.abspath(args.session_folder)
    bag_path = os.path.join(src, "bag", "bag_0.db3")
    bag = Bag(bag_path)
    notes = read_notes(src)
    offset = img_time_offset(notes)
    rec0_ns, rec1_ns = bag.span_ns()
    rec0 = rec0_ns * 1e-9

    # --- IMU for the whole session: small, needed for the level frame and the window choice
    imu_t, gyro, acc = [], [], []
    for blob in bag.iter(TOPIC_IMU):
        s = decode_imu(blob)
        imu_t.append(s.t); gyro.append(s.gyro); acc.append(s.accel)
    imu_t = np.array(imu_t); gyro = np.array(gyro); acc = np.array(acc)
    order = np.argsort(imu_t)
    imu_t, gyro, acc = imu_t[order], gyro[order], acc[order]
    acc_units_g = 0.8 < float(np.linalg.norm(acc, axis=1).mean()) < 1.2
    acc_g = acc if acc_units_g else acc / G
    R, tilt, roll = level_rotation(acc_g)
    yaw_rate = gyro @ R[2]
    fwd_accel = (acc_g @ R[0]) * G

    # --- Clip window (absolute seconds); only messages inside it (plus a margin) are read below
    lo = max(rec0, float(imu_t[0])) + 1.0
    hi = rec1_ns * 1e-9 - 1.0
    duration = (hi - lo) if args.duration == 0 else args.duration
    if args.start is not None:
        w0 = rec0 + args.start
    else:
        w0 = pick_window(imu_t, yaw_rate, duration, lo, hi)
    w1 = w0 + duration
    margin_ns = int(2e9)
    q0, q1 = int(w0 * 1e9) - margin_ns, int(w1 * 1e9) + margin_ns

    out = os.path.abspath(args.out_folder)
    if os.path.isdir(out):
        shutil.rmtree(out)
    os.makedirs(os.path.join(out, "frames"))
    os.makedirs(os.path.join(out, "lidar"))

    # --- LiDAR scans in the window (stamps first, decode the kept ones afterwards)
    scans = []
    for blob in bag.iter(TOPIC_LIDAR, q0, q1):
        st, _, _ = decode_lidar(blob)
        scans.append((st, blob))
    scans.sort(key=lambda s: s[0])
    scan_t = np.array([s[0] for s in scans])

    # --- Camera frames: corrected capture time, every FRAME_STEP-th inside the window
    cams = []
    for blob in bag.iter(TOPIC_IMAGE, q0, q1):
        st, fmt, jpg = decode_image(blob)
        cams.append((st + offset, jpg))
    cams.sort(key=lambda c: c[0])
    cams = [c for c in cams if w0 <= c[0] < w1][::FRAME_STEP]
    if not cams or not len(scan_t):
        sys.exit("No camera frames or LiDAR scans in the chosen window.")
    t0 = cams[0][0]  # clip time 0 = first kept frame (corrected capture time)

    frames, used_scans, errors = [], {}, []
    for i, (tc, jpg) in enumerate(cams):
        j = int(np.argmin(np.abs(scan_t - tc)))
        errors.append(abs(float(scan_t[j]) - tc))
        if j not in used_scans:
            used_scans[j] = len(used_scans)
        im = Image.open(io.BytesIO(jpg)).convert("RGB")
        h = round(im.height * FRAME_WIDTH / im.width)
        im.resize((FRAME_WIDTH, h), Image.LANCZOS).save(os.path.join(out, "frames", f"{i:05d}.jpg"), quality=JPEG_QUALITY, optimize=True)
        frames.append({"t": round(tc - t0, 4), "file": f"frames/{i:05d}.jpg", "scan": used_scans[j]})

    scan_list = [None] * len(used_scans)
    for j, k in used_scans.items():
        st, _, pts = decode_lidar(scans[j][1])
        n = write_scan(os.path.join(out, "lidar", f"{k:05d}.bin"), pts)
        scan_list[k] = {"t": round(float(st) - t0, 4), "file": f"lidar/{k:05d}.bin", "n": n}

    # --- IMU for the clip, averaged to IMU_RATE
    m = (imu_t >= w0) & (imu_t < w1)
    bins = np.floor((imu_t[m] - w0) * IMU_RATE).astype(int)
    imu_out = {k: [] for k in ("t", "gx", "gy", "gz", "ax", "ay", "az", "yaw_rate", "fwd_accel")}
    for b in np.unique(bins):
        sel = bins == b
        g = gyro[m][sel].mean(0); a = acc_g[m][sel].mean(0) * G
        imu_out["t"].append(round(float(imu_t[m][sel].mean()) - t0, 3))
        for key, v in zip(("gx", "gy", "gz"), g): imu_out[key].append(round(float(v), 4))
        for key, v in zip(("ax", "ay", "az"), a): imu_out[key].append(round(float(v), 3))
        imu_out["yaw_rate"].append(round(float(yaw_rate[m][sel].mean()), 4))
        imu_out["fwd_accel"].append(round(float(fwd_accel[m][sel].mean()), 3))
    imu_doc = {
        "rate_hz": IMU_RATE,
        "units": {"t": "s (clip time)", "gx/gy/gz": "rad/s, sensor frame", "ax/ay/az": "m/s^2, sensor frame (driver reports g; converted)",
                  "yaw_rate": "rad/s about the gravity-aligned up axis (+ = turning left)", "fwd_accel": "m/s^2 along the level forward axis, gravity removed"},
        **imu_out,
    }
    with open(os.path.join(out, "imu.json"), "w") as fh:
        json.dump(imu_doc, fh, separators=(",", ":"))

    # --- Calibration (recorded for Stage 2)
    calib = {}
    cj = os.path.join(src, "calib", "calib_final.json")
    if os.path.exists(cj):
        cal = json.load(open(cj, encoding="utf-8"))
        calib["T_lidar_camera"] = {
            "value": cal.get("results", {}).get("T_lidar_camera"),
            "layout": "[x, y, z, qx, qy, qz, qw] (metres, unit quaternion)",
            "convention": "from koide3/direct_visual_lidar_calibration: by its naming it maps camera-frame points into the LiDAR frame (p_lidar = T * p_camera). Verify before Stage 2.",
            "source": "calib/calib_final.json results.T_lidar_camera",
        }
    intr = read_intrinsics(src)
    if intr:
        calib["camera"] = {
            "model": "fisheye / equidistant (Kannala-Brandt, OpenCV cv2.fisheye)",
            "width": intr.get("image_width"), "height": intr.get("image_height"),
            "fx": intr.get("fx"), "fy": intr.get("fy"), "cx": intr.get("cx"), "cy": intr.get("cy"),
            "k": [intr.get("k1"), intr.get("k2"), intr.get("k3"), intr.get("k4")],
            "rms_px": intr.get("rms_reprojection_error_px"),
            "exported_frame_scale": FRAME_WIDTH / float(intr.get("image_width") or FRAME_WIDTH),
            "note": "Intrinsics are for the full 1640 x 1232 image; multiply fx, fy, cx, cy by exported_frame_scale for the exported frames.",
        }

    err_ms = np.array(errors) * 1000.0
    manifest = {
        "format_version": FORMAT_VERSION,
        "id": args.id or os.path.basename(out),
        "session": notes.get("session", os.path.basename(src)),
        "platform": notes.get("platform"),
        "recorded": {"start_epoch": round(rec0, 3), "end_epoch": round(rec1_ns * 1e-9, 3), "duration_s": round((rec1_ns - rec0_ns) * 1e-9, 2),
                     "counts": {TOPIC_IMAGE: bag.count(TOPIC_IMAGE), TOPIC_LIDAR: bag.count(TOPIC_LIDAR), TOPIC_IMU: bag.count(TOPIC_IMU)}},
        "clip": {"start_s": round(w0 - rec0, 2), "duration_s": round(cams[-1][0] - t0, 3), "selected": "manual (--start)" if args.start is not None else "auto: most turning (IMU yaw rate)"},
        "time_base": {
            "t0_epoch": round(t0, 6),
            "clock": "Every t in this manifest and in imu.json is seconds since t0_epoch.",
            "master": "camera header stamp + img_time_offset (true capture time)",
            "img_time_offset_s": offset,
            "lidar": "header stamp of the scan (scan start); points carry offset_time within the scan, not exported",
            "imu": "header stamp of each sample",
        },
        "rates": {"frames_fps": round(15 / FRAME_STEP, 2), "camera_source_hz": 15, "lidar_source_hz": 10, "imu_source_hz": 200, "imu_export_hz": IMU_RATE},
        "sync": {"rule": "each kept frame is paired with the LiDAR scan whose header stamp is nearest", "error_ms": {"max": round(float(err_ms.max()), 2), "median": round(float(np.median(err_ms)), 2), "mean": round(float(err_ms.mean()), 2)}},
        "frames": {"count": len(frames), "width": FRAME_WIDTH, "height": round(1232 * FRAME_WIDTH / 1640), "jpeg_quality": JPEG_QUALITY, "items": frames},
        "lidar": {
            "count": len(scan_list),
            "format": "planar little-endian: int16 x[n], int16 y[n], int16 z[n] (centimetres, sensor frame), then uint8 reflectivity[n]; n = file_size / 7",
            "max_points": MAX_POINTS,
            "downsampling": "zero points dropped, then evenly spaced in scan order",
            "items": scan_list,
        },
        "level_frame": {
            "R_sensor_to_level": [[round(float(v), 6) for v in row] for row in R],
            "note": "Rows are the level x (forward), y (left), z (up) axes in sensor coordinates, from the mean accelerometer direction. p_level = R * p_sensor.",
            "measured_tilt_deg": round(tilt, 2), "measured_roll_deg": round(roll, 2),
        },
        "imu": {"file": "imu.json", "rate_hz": IMU_RATE, "accel_units_source": "g" if acc_units_g else "m/s^2", "accel_units_exported": "m/s^2"},
        "calibration": calib,
        "decoding": {
            "cdr": "4-byte encapsulation header, then payload; alignment relative to the payload start",
            "livox_custom_msg": "header, align 8, timebase u64, point_num u32, lidar_id u8, rsvd u8[3], sequence u32 at payload offset 40, points at 44; 19-byte points padded to 20 except the last",
            "imu": "header, align 8, orientation f64[4], cov[9], angular_velocity f64[3], cov[9], linear_acceleration f64[3], cov[9]",
        },
        "stages": {"projection": None, "detections": None, "map": None},
    }
    with open(os.path.join(out, "manifest.json"), "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, indent=1, ensure_ascii=False)

    size = sum(os.path.getsize(os.path.join(dp, f)) for dp, _, fs in os.walk(out) for f in fs)
    pts = [s["n"] for s in scan_list]
    print(f"session   {manifest['session']}  ({manifest['recorded']['duration_s']} s recorded)")
    print(f"clip      starts {manifest['clip']['start_s']} s, {manifest['clip']['duration_s']} s long ({manifest['clip']['selected']})")
    print(f"frames    {len(frames)} at {manifest['rates']['frames_fps']} fps, {FRAME_WIDTH}x{manifest['frames']['height']}")
    print(f"lidar     {len(scan_list)} scans, {min(pts)}-{max(pts)} points each")
    print(f"imu       {len(imu_out['t'])} samples at {IMU_RATE} Hz (accel source units: {'g' if acc_units_g else 'm/s^2'})")
    print(f"level     tilt {tilt:.1f} deg, roll {roll:.1f} deg (from gravity)")
    print(f"sync      frame-to-scan error: median {manifest['sync']['error_ms']['median']} ms, max {manifest['sync']['error_ms']['max']} ms")
    print(f"output    {size / 1048576:.1f} MB in {out}  ({time.time() - t_start_wall:.0f} s)")


if __name__ == "__main__":
    main()
