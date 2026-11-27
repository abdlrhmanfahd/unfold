"""Floor plan of a mapped session by ray tracing (for the viewer's Clean look).

    python plan_session.py <out_folder>          # uses path.json + scans.json/bin made by map_session

Why ray tracing: a cell is a wall only if laser rays keep stopping in it, and floor only if rays
passed over it. People walking next to the car are erased by later rays that pass through where
they stood. Heights are measured from the local floor (the floor under the car), because a long
LiDAR-only map drifts slowly in height.

Writes:
  plan.bin   uint8 state[H*W] (0 unknown, 1 floor, 2 wall), then int16 floor_cm[H*W] (local floor height)
  plan.json  {cell, x0, y0, W, H, lidar_height_m, wall_h_m, counts}
Row-major, row j = y0 + j*cell, column i = x0 + i*cell (world frame, metres).
"""
from __future__ import annotations

import json
import os
import sys

import numpy as np
from scipy import ndimage
from scipy.spatial import cKDTree

CELL = 0.08
BAND = (0.25, 1.8)      # m above the local floor: what counts as an obstacle
FREE_RANGE = 15.0       # m: rays longer than this do not clear space
HIT_MIN, OCC_RATIO = 3, 0.35


def build_plan(out: str, progress=None) -> dict:
    say = progress or (lambda f, s: None)
    path = json.load(open(os.path.join(out, "path.json")))
    sc = json.load(open(os.path.join(out, "scans.json")))
    px, py, pz, pt = (np.array(path[k]) for k in ("x", "y", "z", "t"))
    meta = json.load(open(os.path.join(out, "map.json")))

    # LiDAR height above the floor is fixed on the car: measure it once, under the car.
    b = open(os.path.join(out, "map.bin"), "rb").read()
    n = len(b) // 8
    mx = np.frombuffer(b, "<i2", n, 0) / 100
    my = np.frombuffer(b, "<i2", n, 2 * n) / 100
    mz = np.frombuffer(b, "<i2", n, 4 * n) / 100
    tree = cKDTree(np.c_[mx, my])
    drops = []
    for i in range(0, len(px), 25):
        zz = mz[tree.query_ball_point([px[i], py[i]], 0.5)]
        zz = zz[zz < pz[i] - 0.1]
        if len(zz) >= 5:
            drops.append(pz[i] - np.percentile(zz, 50))
    h0 = float(np.median(drops)) if drops else 0.33
    path_tree = cKDTree(np.c_[px, py])
    floor_at = lambda q: pz[path_tree.query(q)[1]] - h0  # local floor = nearest pose height - h0

    x0, y0 = mx.min() - 0.5, my.min() - 0.5
    W, H = int((mx.max() + 0.5 - x0) / CELL) + 1, int((my.max() + 0.5 - y0) / CELL) + 1
    hit = np.zeros(W * H, np.int32)
    free = np.zeros(W * H, np.int32)

    chunks = {}
    total = len(sc["t"])
    for k in range(total):
        c = sc["c"][k]
        if c not in chunks:
            chunks = {c: open(os.path.join(out, sc["chunks"][c]), "rb").read()}
        buf, o, m = chunks[c], sc["o"][k], sc["n"][k]
        x = np.frombuffer(buf, "<i2", m, o) / 100
        y = np.frombuffer(buf, "<i2", m, o + 2 * m) / 100
        z = np.frombuffer(buf, "<i2", m, o + 4 * m) / 100
        j = min(len(pt) - 1, int(np.searchsorted(pt, sc["t"][k])))
        sx, sy, sz = px[j], py[j], pz[j]
        rel = z - (sz - h0)
        sel = (rel > BAND[0]) & (rel < BAND[1])
        x, y = x[sel], y[sel]
        if not len(x):
            continue
        dx, dy = x - sx, y - sy
        L = np.hypot(dx, dy)
        # hits
        hi = ((x - x0) / CELL).astype(int) + ((y - y0) / CELL).astype(int) * W
        np.add.at(hit, hi, 1)
        # free: samples along each ray up to one cell before the end
        Lc = np.minimum(L, FREE_RANGE)
        steps = np.maximum(0, (Lc / (CELL * 0.5)).astype(int) - 2)
        tot = int(steps.sum())
        if tot:
            ray = np.repeat(np.arange(len(x)), steps)
            first = np.repeat(np.cumsum(steps) - steps, steps)
            s = (np.arange(tot) - first + 0.5) * (CELL * 0.5) / np.maximum(L[ray], 1e-6)
            fx, fy = sx + dx[ray] * s, sy + dy[ray] * s
            fi = np.unique(((fx - x0) / CELL).astype(int) + ((fy - y0) / CELL).astype(int) * W)
            free[fi] += 1
        if k % 100 == 0:
            say(k / total, "plan")

    occ = (hit >= HIT_MIN) & (hit >= OCC_RATIO * (hit + free))
    flo = (free >= 2) & ~occ
    occ, flo = occ.reshape(H, W), flo.reshape(H, W)
    # clean up: drop specks, close small gaps in walls, fill pin holes in the floor
    lab, nl = ndimage.label(occ, structure=np.ones((3, 3)))
    if nl:
        sizes = ndimage.sum(occ, lab, range(1, nl + 1))
        occ = np.isin(lab, 1 + np.flatnonzero(sizes >= 6))
    occ = ndimage.binary_closing(occ, structure=np.ones((2, 2)))
    flo = ndimage.binary_closing(flo, structure=np.ones((3, 3))) & ~occ
    state = np.zeros((H, W), np.uint8)
    state[flo] = 1
    state[occ] = 2

    # local floor height per cell (for the clean mesh and the car)
    jj, ii = np.mgrid[0:H, 0:W]
    known = state > 0
    q = np.c_[x0 + (ii[known] + 0.5) * CELL, y0 + (jj[known] + 0.5) * CELL]
    floor_cm = np.zeros((H, W), np.int16)
    floor_cm[known] = np.round(floor_at(q) * 100).astype(np.int16)

    with open(os.path.join(out, "plan.bin"), "wb") as f:
        f.write(state.tobytes())
        f.write(floor_cm.astype("<i2").tobytes())
    info = {"format": "uint8 state[H*W] (0 unknown, 1 floor, 2 wall), int16 floor_cm[H*W]; row j = y0 + j*cell",
            "cell": CELL, "x0": round(float(x0), 3), "y0": round(float(y0), 3), "W": W, "H": H,
            "lidar_height_m": round(h0, 3), "wall_h_m": 1.1,
            "counts": {"floor": int(flo.sum()), "wall": int(occ.sum())}}
    json.dump(info, open(os.path.join(out, "plan.json"), "w"), indent=1)
    meta["lidar_height_m"] = round(h0, 3)
    json.dump(meta, open(os.path.join(out, "map.json"), "w"), indent=1)

    # quick look
    try:
        from PIL import Image
        a = np.full((H, W, 3), 250, np.uint8)
        a[state == 1] = (226, 231, 236)
        a[state == 2] = (40, 50, 60)
        for i in range(0, len(px), 3):
            ci, cj = int((px[i] - x0) / CELL), int((py[i] - y0) / CELL)
            if 0 <= ci < W and 0 <= cj < H:
                a[cj, ci] = (11, 138, 122)
        Image.fromarray(a[::-1]).save(os.path.join(out, "preview_plan.png"))
    except Exception:
        pass
    write_cover(out, state, x0, y0, px, py)
    return info


def write_cover(out, state, x0, y0, px, py, W_OUT=960, H_OUT=600):
    """Card picture: the floor plan cropped to what was seen, walls bold, the drive in teal."""
    from PIL import Image, ImageDraw
    H, W = state.shape
    jj, ii = np.nonzero(state > 0)
    if not len(jj):
        return
    j0, j1, i0, i1 = jj.min(), jj.max() + 1, ii.min(), ii.max() + 1
    st = state[j0:j1, i0:i1]
    walls = ndimage.binary_dilation(st == 2, iterations=1)
    a = np.full(st.shape + (3,), (245, 247, 249), np.uint8)
    a[st == 1] = (255, 255, 255)
    a[walls] = (15, 23, 32)
    img = Image.fromarray(a[::-1])
    sc = min((W_OUT - 60) / img.width, (H_OUT - 60) / img.height)
    img = img.resize((max(1, int(img.width * sc)), max(1, int(img.height * sc))), Image.LANCZOS)
    canvas = Image.new("RGB", (W_OUT, H_OUT), (245, 247, 249))
    ox, oy = (W_OUT - img.width) // 2, (H_OUT - img.height) // 2
    canvas.paste(img, (ox, oy))
    d = ImageDraw.Draw(canvas)
    hh = j1 - j0
    pts = [(ox + ((x - x0) / CELL - i0) * sc, oy + (hh - ((y - y0) / CELL - j0)) * sc) for x, y in zip(px[::5], py[::5])]
    if len(pts) > 1:
        d.line(pts, fill=(18, 179, 156), width=4, joint="curve")
    canvas.save(os.path.join(out, "cover.png"), optimize=True)


if __name__ == "__main__":
    print(json.dumps(build_plan(sys.argv[1]), indent=1))
