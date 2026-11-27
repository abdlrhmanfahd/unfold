# Session replay: export + viewer (Stage 1)

Turns a QCar 2 recording folder (ROS 2 bag in sqlite3) into small static files, and plays them on an
Unfold page with the camera, the LiDAR and the IMU in sync. No ROS needed.

## 1. Export

One-time setup (Python 3.10+):

```bash
python -m venv "%LOCALAPPDATA%\unfold-session-export\venv"
"%LOCALAPPDATA%\unfold-session-export\venv\Scripts\pip" install numpy pillow
```

Run:

```bash
python export_session.py <session_folder> <out_folder> [--start S] [--duration D] [--id NAME]
```

- `session_folder`: the recording folder (holds the `.db3`, `notes.txt`, `intrinsics.yaml`, `calib_final.json`).
- `out_folder`: put it in `wp-content/uploads/unfold/sessions/<id>/` of the site.
- `--start`: clip start in seconds from the recording start. Leave it out to pick the 30 s with the most turning.
- `--duration`: clip length in seconds (default 30, `0` = whole recording).
- `--id`: id written in the manifest (default: the out folder name). Lowercase letters, digits, `-`, `_`.

Example (the first clip):

```bash
python export_session.py "C:/Users/bodya/Downloads/Session_1/Session_1/multi_left_20261105_101728" "C:/Users/bodya/Studio/unfold/wp-content/uploads/unfold/sessions/session_1" --duration 30 --id session_1
```

It prints a summary: clip window, frame/scan/IMU counts, size on disk, measured tilt, and the
frame→scan sync error (max and median).

Files: `rosbag_cdr.py` (bag reader + CDR decoders for Image, Imu, Livox CustomMsg),
`export_session.py` (the export).

## 2. Embed

```
[unfold_session id="session_1" title="Hallway U-turn"]
```

The shortcode (plugin `unfold`, `includes/class-unfold-session.php`) loads only files from
`wp-content/uploads/unfold/sessions/<id>/`. It is Unfold's own code, runs on the page, and does not
touch the student-sim sandbox. If the folder is missing, admins see a note; visitors see nothing.
The Recordings page (`/recordings/`) uses it, with the wide template `page-recordings.html`.

Viewer: camera (left), LiDAR 3D with orbit (right, colour by height or reflectivity, car at the
origin), IMU plot (yaw rate, forward acceleration; drag on it to seek), timeline with play/pause and
0.5×/1×/2×. Frames and scans are fetched on demand, 10 ahead, 40 kept in memory. Playback pauses when
the viewer is off screen. The plot smooths for display only (0.3 s yaw, 0.6 s accel; raw accel drawn
faint); `imu.json` stays raw.

## 3. Output format

```
<id>/
  manifest.json
  imu.json
  frames/000000.jpg …   640 px wide, JPEG q75, 5 fps (every 3rd camera frame)
  lidar/000000.bin …    one file per kept frame's scan
```

**Clock.** Every `t` is seconds since `time_base.t0_epoch`. Master clock = camera header stamp +
`img_time_offset` (from `notes.txt`, default −0.0265 s). LiDAR `t` = scan header stamp (scan start);
IMU `t` = sample header stamp.

**LiDAR `.bin`** (little-endian, planar):

| block | type | count | meaning |
|---|---|---|---|
| x | int16 | n | centimetres, LiDAR sensor frame |
| y | int16 | n | centimetres |
| z | int16 | n | centimetres |
| reflectivity | uint8 | n | Livox: 0–150 diffuse, 151–255 retro-reflective |

`n = file_size / 7`. Zero points are dropped; at most 8000 points, evenly spaced in scan order.
Read in JS: `new Int16Array(buf, 0, n)`, `…(buf, 2n, n)`, `…(buf, 4n, n)`, `new Uint8Array(buf, 6n, n)`.
To draw level, multiply by `manifest.level_frame.R_sensor_to_level` (rows: forward, left, up; from gravity).

**`imu.json`**: `rate_hz` (20), `units`, arrays `t`, `gx gy gz` (rad/s), `ax ay az` (m/s², converted
from g), `yaw_rate` (rad/s about up), `fwd_accel` (m/s² along forward, gravity removed). 200 Hz
source averaged down to 20 Hz.

**`manifest.json`** main keys:

- `session`, `platform`, `recorded` (epoch span, duration, message counts), `clip` (start, length, how chosen)
- `time_base` (above), `rates`
- `sync`: rule (nearest scan stamp per frame) and `error_ms` max / median / mean
- `frames`: size, quality, `items[] = {t, file, scan}` (`scan` = index into `lidar.items`)
- `lidar`: format, `items[] = {t, file, n}`
- `level_frame`: rotation, measured tilt and roll
- `calibration`: `T_lidar_camera` (from `calib_final.json`) and camera intrinsics (+ scale for the 640 px frames)
- `decoding`: notes on the CDR and Livox layouts
- `stages`: `projection`, `detections`, `map`, all `null` for now (Stage 2–4 fill these)

## First clip (session_1)

30 s from 137.0 s (picked for turning, about a 172° U-turn): 150 frames 640×481, 150 scans × 8000
points, 600 IMU samples, 12 MB. Sync error median 8.25 ms, max 12.61 ms. LiDAR tilt 29.6°, roll 0.7°.
Export time about 8 s.

---

# 3D worlds (instructors only)

A **world** is one place in one frame (the lab building). It is built from **recordings**: session
folders exactly as they come off the car (`bag/bag_0.db3`, `calib/`, `notes.txt`), zipped. One ZIP may
hold several session folders; each becomes its own recording.

Instructor portal → **3D worlds** → create a world → **Add a recording** (chunked upload, up to 16 GB)
→ the worker builds it → **Open 3D**.

Everything is private: the raw ZIP and the outputs live in `wp-content/unfold-private/worlds/drive-<id>/`
and are served only through `GET /unfold/v1/drives/<id>/file?name=…` to users who can review.

## Worker

**Started by the upload.** When an instructor finishes uploading, the site writes `job.json` next to
the recording and starts `world_worker.py --job job.json` itself (one recording at a time; when one
finishes, the next waiting one starts). It needs two lines in `wp-config.php`:

```php
define( 'UNFOLD_WORLD_PYTHON', '<path to the venv python.exe>' );
define( 'UNFOLD_WORLD_WORKER', '<path to world_worker.py>' );
```

The worker's log is `wp-content/unfold-private/worlds/drive-<id>/worker.log`.

**Polling (optional).** For a worker on a different machine, run it in polling mode instead.
Same venv as the export tool, plus scipy:

```bash
"%LOCALAPPDATA%\unfold-session-export\venv\Scripts\pip" install scipy
set UNFOLD_SITE=http://localhost:8881
set UNFOLD_WORKER_TOKEN=<token from wp-admin -> Projects -> Settings>
"%LOCALAPPDATA%\unfold-session-export\venv\Scripts\python" world_worker.py
```

Per recording (Session 1: 6 min drive, ~8 min to build): unpack → map (`map_session.build_map`) →
live scans → camera frames (`map_session.export_frames`) → result.

Mapping is LiDAR odometry with no ROS: gyro de-skew, gravity levelling, point-to-plane ICP against
a local map of recent keyframes, constant-velocity prior. `map_session.py` also runs on its own:
`python map_session.py <session_folder> <out_folder> [--start S] [--duration D] [--no-frames]`.

## Files per recording

| file | content |
|---|---|
| `map.bin` | planar int16 x[n], y[n], z[n] (cm), uint8 reflectivity[n], uint8 hits[n]; n = size / 8; 6 cm voxels |
| `map.json` | summary: `floor_z`, `duration_s`, `path_length_m`, ICP RMS, tilt, … |
| `path.json` | per LiDAR scan (10 Hz): `t` (s since `t0_epoch`), `x y z` (m), `yaw`, quaternion |
| `scans.json`, `scans-NNN.bin` | live scans at 5 Hz, ≤5000 points, planar int16 cm in the world frame, 50 per bundle; located by `t c o n` |
| `plan.json`, `plan.bin` | floor plan: uint8 state[H*W] (0 unknown, 1 floor, 2 wall), int16 floor_cm[H*W] |
| `frames.json`, `frames-NNN.bin` | front camera at 5 fps, 480 px JPEG, 50 per bundle; located by `t c o s` |

The viewer has three looks: **LiDAR points**, **Clean** (from `plan.bin`, made by `plan_session.py` by
tracing every laser ray on an 8 cm grid: a cell is wall where rays keep stopping, floor where rays
passed; heights are taken from the local floor under the car, since a long LiDAR map drifts slowly in
height; walls are cut at 1.1 m) and **Side by side** (both, same camera).

World frame: x forward at the first scan of the first recording, y left, z up, metres. Each
recording has a 4×4 placement `T` (row-major, recording frame → world).

## Linking recordings (`link_session.py`)

The first recording of a world sits at the origin (`placement: origin`). Every later one is placed
by matching what it saw against the recordings already placed:

1. **Coarse:** floor plans of both; every heading in 1.5° steps; for each, an FFT cross-correlation
   slides the new plan over the world and scores "walls on walls" minus "walls on open floor".
2. **Fine:** 3D point-to-plane ICP of the new map against the placed maps, from the coarse answer.
3. **Check:** share of the new walls (inside the area the world has seen) that land on world walls,
   and how many metres of them. Below 55 % or 15 m: `placement: unaligned`; it is shown on its own.

Needs: the car passed through at least one shared place (a corridor, a doorway) in both recordings.

Test without a second session: map two halves of one session separately and link them back,
comparing with the full map: `python link_session.py <half_b_out> <half_a_out> --truth <full_out>`.

## Deleting

Instructors can delete a recording or a whole world from the 3D worlds tab (not while one is being
built). Raw ZIP and outputs are removed; recordings split from one ZIP hand the file on.
