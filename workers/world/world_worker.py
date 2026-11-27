"""Unfold 3D world worker.

Polls the site for recordings an instructor uploaded (3D worlds tab), builds each one
(map, path, live LiDAR scans, camera frames) and reports back. It only makes outgoing requests.

    set UNFOLD_SITE=http://localhost:8881
    set UNFOLD_WORKER_TOKEN=<token from wp-admin -> Projects -> Settings>
    python world_worker.py            # keep polling (for a separate lab server)
    python world_worker.py --once     # one recording, then exit
    python world_worker.py --job F    # what the site runs by itself after an upload (no token needed)

Today the worker runs on the same computer as the site, so it reads the uploaded ZIP and writes
the outputs straight into the site's private folder (paths come with the job). The lab server
will work the same way.
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
import tempfile
import time
import traceback
import urllib.error
import urllib.request
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from map_session import build_map, export_frames  # noqa: E402
from plan_session import build_plan  # noqa: E402
from link_session import link  # noqa: E402
from colour_session import build_colours  # noqa: E402
from mesh_session import build_mesh  # noqa: E402

POLL_S = 5


class Site:
    def __init__(self, base: str, token: str):
        self.base, self.token = base.rstrip("/"), token

    def call(self, method: str, path: str, body: dict | None = None):
        url = f"{self.base}/?rest_route=/unfold/v1{path}"
        data = json.dumps(body).encode() if body is not None else None
        headers = {"Content-Type": "application/json", "User-Agent": "unfold-world-worker"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        req = urllib.request.Request(url, data=data, method=method, headers=headers)
        with urllib.request.urlopen(req, timeout=60) as r:
            raw = r.read()
            return json.loads(raw) if raw else None


def extract_session(zip_path: str, folder: str, dest: str) -> str:
    """Copy <folder>/bag/*, calib/*, notes.txt out of the ZIP; returns the local session folder."""
    prefix = (folder.strip("/") + "/") if folder else ""
    with zipfile.ZipFile(zip_path) as z:
        for info in z.infolist():
            name = info.filename.replace("\\", "/")
            if not name.startswith(prefix) or info.is_dir():
                continue
            rel = name[len(prefix):]
            if not (rel.startswith("bag/") or rel.startswith("calib/") or rel == "notes.txt"):
                continue
            if ".." in rel.split("/"):
                continue
            out = os.path.join(dest, *rel.split("/"))
            os.makedirs(os.path.dirname(out), exist_ok=True)
            with z.open(info) as src, open(out, "wb") as dst:
                shutil.copyfileobj(src, dst, 16 * 1024 * 1024)
    if not os.path.isfile(os.path.join(dest, "bag", "bag_0.db3")):
        raise RuntimeError("bag/bag_0.db3 is missing in that recording")
    return dest


def handle(site: Site, job: dict) -> None:
    did, jid = job["drive_id"], job["job_id"]
    last = [0.0]

    def progress(pct: float, stage: str, force: bool = False):
        if force or time.time() - last[0] > 3:
            last[0] = time.time()
            try:
                site.call("POST", f"/worker/drives/{did}/progress", {"job_id": jid, "pct": round(pct), "stage": stage})
            except urllib.error.URLError:
                pass

    print(f"recording {did} ({job['name']}): start", flush=True)
    zip_path = job.get("zip_path")
    if not zip_path or not os.path.isfile(zip_path):
        raise RuntimeError("The worker cannot read the uploaded file. Run it on the same computer as the site.")
    out = job["out_dir"]
    for f in os.listdir(out) if os.path.isdir(out) else []:
        os.remove(os.path.join(out, f))
    os.makedirs(out, exist_ok=True)
    tmp = tempfile.mkdtemp(prefix="unfold-drive-")
    try:
        progress(2, "unpacking", True)
        sess = extract_session(zip_path, job.get("session", ""), tmp)
        # % by how long each step takes: map 8-55, plan 55-57, frames 57-62, colour 62-68, 3D model 68-97
        summary = build_map(sess, out, progress=lambda f, s: progress(8 + 47 * f if s == "mapping" else 55, s))
        plan = build_plan(out, progress=lambda f, s: progress(55 + 2 * f, "plan"))
        summary["lidar_height_m"] = plan["lidar_height_m"]
        summary.update(export_frames(sess, out, summary["t0_epoch"], progress=lambda f, s: progress(57 + 5 * f, "frames")))
        if os.path.isfile(os.path.join(sess, "calib", "calib_final.json")) and os.path.isfile(os.path.join(sess, "calib", "intrinsics.yaml")):
            summary["colour"] = build_colours(sess, out, progress=lambda f, s: progress(62 + 6 * f, "colour"))
        # Solid model for the Clean look. Optional: if it fails the recording is still usable (the
        # viewer falls back to the floor-plan blocks), so report it and carry on.
        try:
            m = build_mesh(out, sess, progress=lambda f, s: progress(68 + 29 * f, "3D model"))
            summary["mesh"] = {k: m[k] for k in ("nt", "run_s")}
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            summary["mesh"] = {"error": str(e)[:200]}
        # First recording of a world sits at the origin; later ones are placed by matching walls
        # against the recordings already placed (see link_session.py).
        T, placement = [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1], "origin"
        refs = [(o["out_dir"], o["T"]) for o in job.get("others", []) if o.get("placement", "origin") in ("origin", "matched", "manual")
                and os.path.isfile(os.path.join(o["out_dir"], "plan.bin"))]
        if job.get("others"):
            placement = "unaligned"
            if refs:
                progress(97, "placing", True)
                res = link(out, refs)
                summary["link"] = {k: res[k] for k in ("agree", "shared_wall_m", "heading_deg", "shift_m", "icp_rms_cm")}
                if res["placement"] == "matched":
                    T, placement = res["T"], "matched"
        progress(99, "placing", True)
        keep = ("session", "scans", "frames", "lidar_height_m", "link", "colour", "map_points", "path_length_m", "duration_s", "floor_z", "tilt_deg", "roll_deg", "icp_rms_cm", "run_s", "extent_m", "mesh")
        site.call("POST", f"/worker/drives/{did}/result", {
            "job_id": jid, "summary": {k: summary.get(k) for k in keep},
            "T": T, "placement": placement})
        print(f"recording {did}: ready ({summary['map_points']} map points, {summary['path_length_m']} m)", flush=True)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


def read_token_file() -> str | None:
    places = [
        os.path.join(os.environ.get("LOCALAPPDATA", ""), "unfold-extractor", "worker-token.txt"),
        os.path.join(os.path.dirname(os.path.abspath(__file__)), "worker-token.txt"),
    ]
    for p in places:
        if p and os.path.isfile(p):
            with open(p, encoding="utf-8") as fh:
                t = fh.read().strip()
            if t:
                return t
    return None


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--once", action="store_true")
    ap.add_argument("--until-empty", action="store_true", help="process all queued recordings, then exit")
    ap.add_argument("--job", help="job file written by the site after an upload: build that one recording and exit")
    a = ap.parse_args()
    if a.job:
        with open(a.job, encoding="utf-8") as fh:
            job = json.load(fh)
        site = Site(job["site"], "")
        try:
            handle(site, job)
        except Exception as e:
            traceback.print_exc()
            site.call("POST", f"/worker/drives/{job['drive_id']}/result", {"job_id": job["job_id"], "error": str(e)[:400]})
        return
    token = os.environ.get("UNFOLD_WORKER_TOKEN") or read_token_file() or ""
    site = Site(os.environ.get("UNFOLD_SITE", "http://localhost:8881"), token)
    if not site.token:
        sys.exit("Set UNFOLD_WORKER_TOKEN (wp-admin -> Projects -> Settings), or save it in worker-token.txt.")
    print("3D world worker: waiting for recordings", flush=True)
    delay = POLL_S
    while True:
        try:
            job = site.call("POST", "/worker/drives/next", {})
            delay = POLL_S
        except urllib.error.HTTPError as e:
            sys.exit(f"The site refused the worker ({e.code}). Check UNFOLD_WORKER_TOKEN.") if e.code in (401, 403) else print("site error", e.code)
            job = None
        except urllib.error.URLError as e:
            print("site not reachable:", e.reason, flush=True)
            job = None
            delay = min(30, delay * 2)
        except KeyboardInterrupt:
            print("3D world worker stopped.", flush=True)
            return
        if job:
            try:
                handle(site, job)
            except Exception as e:  # report and keep going
                traceback.print_exc()
                try:
                    site.call("POST", f"/worker/drives/{job['drive_id']}/result", {"job_id": job["job_id"], "error": str(e)[:400]})
                except urllib.error.URLError:
                    pass
            if a.once:
                return
            continue
        if a.once or a.until_empty:
            return
        try:
            time.sleep(delay)
        except KeyboardInterrupt:
            print("3D world worker stopped.", flush=True)
            return


if __name__ == "__main__":
    main()
