"""Place a new recording in its world by matching what it saw against the recordings already there.

    python link_session.py <new_out> <ref_out> [<ref_out> ...] [--truth <full_out>]

Each recording has its own frame (origin = where its car started). To put recording B into the
world of A we need the rotation and shift that make B's walls land on A's walls:

  1. Coarse: floor plans (plan.bin) of both. Try every heading (1.5 deg steps); for each, slide
     B over A with an FFT cross-correlation and score "B's walls on A's walls" minus
     "B's walls on A's open floor". Best heading + shift wins.
  2. Fine: 3D point-to-plane ICP of B's map against A's map, started from the coarse answer.
  3. Check: share of B's walls (inside the part A has seen) that land on A's walls, and how many.
     Too little shared area -> not placed (the instructor can place it by hand).

Output: 4x4 row-major T (B frame -> world), placement 'matched' or 'unaligned', and the numbers.
"""
from __future__ import annotations

import argparse
import json
import math
import os
import sys

import numpy as np
from scipy import ndimage, signal
from scipy.spatial import cKDTree

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from map_session import icp, normals, voxel  # noqa: E402

COARSE = 0.16          # m, search grid
ROT_STEP = 1.5         # degrees
MIN_SHARED_M = 15.0    # metres of shared wall needed to trust a match
MIN_AGREE = 0.55       # share of B's walls (inside A's seen area) that must land on A's walls


def load_plan(out):
    info = json.load(open(os.path.join(out, "plan.json")))
    b = open(os.path.join(out, "plan.bin"), "rb").read()
    W, H, c = info["W"], info["H"], info["cell"]
    st = np.frombuffer(b, np.uint8, W * H).reshape(H, W)
    jj, ii = np.nonzero(st == 2)
    walls = np.c_[info["x0"] + (ii + 0.5) * c, info["y0"] + (jj + 0.5) * c]
    jj, ii = np.nonzero(st == 1)
    free = np.c_[info["x0"] + (ii + 0.5) * c, info["y0"] + (jj + 0.5) * c]
    return walls, free


def load_map(out, vox=0.15):
    b = open(os.path.join(out, "map.bin"), "rb").read()
    n = len(b) // 8
    p = np.stack([np.frombuffer(b, "<i2", n, k * 2 * n) / 100 for k in range(3)], 1)
    return voxel(p, vox)


def T4(T):
    return np.array(T, float).reshape(4, 4)


def apply2(T, p):
    return p @ T[:2, :2].T + T[:2, 3]


def raster(p, x0, y0, W, H, c):
    g = np.zeros((H, W), np.float32)
    i = ((p[:, 0] - x0) / c).astype(int)
    j = ((p[:, 1] - y0) / c).astype(int)
    ok = (i >= 0) & (i < W) & (j >= 0) & (j < H)
    np.add.at(g, (j[ok], i[ok]), 1)
    return np.minimum(g, 1)


def link(new_out: str, refs: list, progress=None) -> dict:
    """refs: [(out_dir, T 16 floats row-major), ...] already placed in the world."""
    say = progress or (lambda f, s: None)
    # reference world: union of placed recordings
    rw, rf, rmaps = [], [], []
    for out, T in refs:
        T = T4(T)
        w, f = load_plan(out)
        rw.append(apply2(T, w)); rf.append(apply2(T, f))
        m = load_map(out)
        rmaps.append(m @ T[:3, :3].T + T[:3, 3])
    rw, rf, rmap = np.vstack(rw), np.vstack(rf), np.vstack(rmaps)
    nw, nf = load_plan(new_out)
    nw = voxel(nw, COARSE)                       # one point per coarse cell

    # reference grids (walls blurred a little for tolerance; free space for conflicts)
    allp = np.vstack([rw, rf])
    x0, y0 = allp.min(0) - 2
    W = int((allp[:, 0].max() + 2 - x0) / COARSE) + 1
    H = int((allp[:, 1].max() + 2 - y0) / COARSE) + 1
    Rw = raster(rw, x0, y0, W, H, COARSE)
    Rw = np.maximum(Rw, ndimage.gaussian_filter(Rw, 0.8) * 2.5).clip(0, 1)
    Rf = raster(rf, x0, y0, W, H, COARSE) * (1 - raster(rw, x0, y0, W, H, COARSE))
    Rf = ndimage.binary_erosion(Rf > 0, iterations=1).astype(np.float32)  # only clearly open floor

    best = []
    angles = np.arange(0, 360, ROT_STEP)
    for k, a in enumerate(angles):
        th = math.radians(a)
        R = np.array([[math.cos(th), -math.sin(th)], [math.sin(th), math.cos(th)]])
        q = nw @ R.T
        qx0, qy0 = q.min(0)
        w = int((q[:, 0].max() - qx0) / COARSE) + 1
        h = int((q[:, 1].max() - qy0) / COARSE) + 1
        N = raster(q, qx0, qy0, w, h, COARSE)
        Nf = N[::-1, ::-1]
        Cw = signal.fftconvolve(Rw, Nf, mode="full")
        Cf = signal.fftconvolve(Rf, Nf, mode="full")
        S = Cw - 1.0 * Cf
        m = np.unravel_index(np.argmax(S), S.shape)
        # shift: new cell u lands on ref cell u + kk, kk = m - (n - 1)
        kj, ki = m[0] - (h - 1), m[1] - (w - 1)
        tx, ty = x0 + ki * COARSE - qx0, y0 + kj * COARSE - qy0
        best.append((float(S[m]), a, tx, ty))
        if k % 24 == 0:
            say(k / len(angles) * 0.6, "linking")
    best.sort(reverse=True)
    score, a, tx, ty = best[0]
    # ambiguity: best score with a clearly different heading
    other = next((b for b in best[1:] if min(abs(b[1] - a), 360 - abs(b[1] - a)) > 10), (0, 0, 0, 0))

    th = math.radians(a)
    T0 = np.eye(4)
    T0[:2, :2] = [[math.cos(th), -math.sin(th)], [math.sin(th), math.cos(th)]]
    T0[:2, 3] = [tx, ty]
    say(0.65, "linking")

    # fine: 3D ICP of the new map against the reference maps
    src = load_map(new_out, 0.2)
    tree0 = cKDTree(rmap)
    nrm, planar = normals(tree0, rmap)
    mp, mn = rmap[planar], nrm[planar]
    tree = cKDTree(mp)
    T = T0
    for _ in range(3):
        T, used, rms = icp(src, tree, mp, mn, T, iters=25)
    say(0.95, "linking")

    # check: B's walls inside A's seen area that land on A's walls
    nw_all, _ = load_plan(new_out)
    q = apply2(T, nw_all)
    known = cKDTree(np.vstack([rw, rf]))
    inside = known.query(q, distance_upper_bound=0.2)[0] < np.inf
    on_wall = cKDTree(rw).query(q[inside], distance_upper_bound=0.2)[0] < np.inf
    agree = float(on_wall.mean()) if inside.any() else 0.0
    shared_m = float(on_wall.sum()) * 0.08
    ok = agree >= MIN_AGREE and shared_m >= MIN_SHARED_M
    return {
        "T": [round(float(v), 6) for v in T.ravel()],
        "placement": "matched" if ok else "unaligned",
        "agree": round(agree, 3), "shared_wall_m": round(shared_m, 1),
        "heading_deg": round(math.degrees(math.atan2(T[1, 0], T[0, 0])), 2),
        "shift_m": [round(float(T[0, 3]), 3), round(float(T[1, 3]), 3), round(float(T[2, 3]), 3)],
        "coarse": {"heading_deg": a, "shift_m": [round(tx, 2), round(ty, 2)], "score": round(score, 1),
                   "runner_up": {"heading_deg": other[1], "score": round(other[0], 1)}},
        "icp_rms_cm": round(rms * 100, 2), "icp_points": used,
    }


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("new_out")
    ap.add_argument("refs", nargs="+")
    ap.add_argument("--truth", help="out folder of a full map that contains both (for testing)")
    a = ap.parse_args()
    ident = [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1]
    res = link(a.new_out, [(r, ident) for r in a.refs], progress=lambda f, s: print(f"  {s} {f * 100:.0f}%", flush=True))
    if a.truth:
        full = json.load(open(os.path.join(a.truth, "path.json")))
        newp = json.load(open(os.path.join(a.new_out, "path.json")))
        i = int(np.argmin(np.abs(np.array(full["t"]) - newp["t"][0])))
        res["truth"] = {"heading_deg": round(math.degrees(full["yaw"][i]), 2), "shift_m": [full["x"][i], full["y"][i], full["z"][i]]}
        e = np.array(res["shift_m"][:2]) - np.array(res["truth"]["shift_m"][:2])
        dh = (res["heading_deg"] - res["truth"]["heading_deg"] + 180) % 360 - 180
        res["error"] = {"position_cm": round(float(np.hypot(*e)) * 100, 1), "heading_deg": round(dh, 2)}
    print(json.dumps(res, indent=1))


if __name__ == "__main__":
    main()
