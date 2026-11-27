"""Solid 3D model of a mapped session (for the viewer's Clean look), by TSDF fusion.

    python mesh_session.py <out_folder>     # uses path.json, scans.json/bin, map.json (+ map.bin, map_rgb.bin for colour)

How (TSDF = truncated signed distance): space is cut into 5 cm cubes and every laser ray votes.
Cubes the ray passed through vote "empty" (+1), cubes just in front of where it stopped vote a small +,
cubes just behind it a small -. Each cube keeps the average of its votes. The surface is where the
average flips from + to - (marching cubes). Many slightly offset views of a wall average into one thin
surface, and someone walking next to the car is erased by later rays that pass through where they stood.

Writes:
  mesh.bin   int16 pos[3*nv] (cm, world frame), uint8 rgb[3*nv], padding to 4 bytes, uint32 idx[3*nt]
  mesh.json  {nv, nt, voxel_m, ...}
"""
from __future__ import annotations

import json
import math
import os
import sys
import time

import numpy as np
import pyfqmr
from scipy import ndimage, sparse
from scipy.sparse import csgraph
from scipy.spatial import cKDTree
from skimage.measure import marching_cubes

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from rosbag_cdr import TOPIC_IMU, TOPIC_LIDAR, Bag, decode_imu, decode_lidar  # noqa: E402
from map_session import RANGE_MAX, RANGE_MIN  # noqa: E402

VOXEL = 0.05          # m
TRUNC = 0.12          # m: how far in front of / behind a hit a ray votes with a distance
CARVE_MAX = 12.0      # m: rays clear space up to this far from the car
CARVE_STEP = 0.06     # m between "empty" votes along a ray
W_MIN = 3             # a cube needs this many votes before it can hold a surface
TARGET_TRIS = 1_500_000
BATCH = 40            # scans per batch


def quat_to_R(w, x, y, z):
    n = math.sqrt(w * w + x * x + y * y + z * z)
    w, x, y, z = w / n, x / n, y / n, z / n
    return np.array([[1 - 2 * (y * y + z * z), 2 * (x * y - w * z), 2 * (x * z + w * y)],
                     [2 * (x * y + w * z), 1 - 2 * (x * x + z * z), 2 * (y * z - w * x)],
                     [2 * (x * z - w * y), 2 * (y * z + w * x), 1 - 2 * (x * x + y * y)]], np.float32)


def scans_from_bag(session: str, out: str, say):
    """Every LiDAR scan at full density, placed with the poses map_session already found."""
    path = json.load(open(os.path.join(out, "path.json")))
    meta = json.load(open(os.path.join(out, "map.json")))
    R_level = np.array(meta["R_sensor_to_level"], np.float64)
    t0 = meta["t0_epoch"]
    pt = np.array(path["t"]) + t0
    bag = Bag(os.path.join(session, "bag", "bag_0.db3"))
    imu = [decode_imu(b) for b in bag.iter(TOPIC_IMU)]
    it = np.array([s.t for s in imu])
    gyro = np.array([s.gyro for s in imu])
    total = max(1, bag.count(TOPIC_LIDAR))
    pts, orig = [], []
    for k, blob in enumerate(bag.iter(TOPIC_LIDAR)):
        stamp, _, p = decode_lidar(blob)
        if len(p) == 0:
            continue
        j = int(np.argmin(np.abs(pt - stamp)))
        if abs(pt[j] - stamp) > 0.03:
            continue
        xyz = np.stack([p["x"], p["y"], p["z"]], 1).astype(np.float64)
        rng = np.linalg.norm(xyz, axis=1)
        keep = (rng > RANGE_MIN) & (rng < RANGE_MAX)
        xyz, dt = xyz[keep], p["offset_time"][keep] * 1e-9
        g = np.clip(np.searchsorted(it, stamp), 0, len(it) - 1)
        w = gyro[g:g + 22].mean(0)
        xyz = xyz + np.cross(w[None, :] * dt[:, None], xyz)          # same de-skew as the map
        R = quat_to_R(path["qw"][j], path["qx"][j], path["qy"][j], path["qz"][j])
        o = np.array([path["x"][j], path["y"][j], path["z"][j]], np.float32)
        P = ((xyz @ R_level.T).astype(np.float32)) @ R.T + o
        # one point per 2.5 cm cube is plenty for 5 cm voxels
        q = np.floor(P / 0.025).astype(np.int64)
        _, first = np.unique(q, axis=0, return_index=True)
        pts.append(P[first])
        orig.append(o)
        if k % 100 == 0:
            say(0.15 * k / total, "reading")
    return pts, orig


def scans_from_bundles(out: str):
    """The lighter live-scan bundles (every 2nd scan, 5000 points) when the recording is not at hand."""
    path = json.load(open(os.path.join(out, "path.json")))
    sc = json.load(open(os.path.join(out, "scans.json")))
    px, py, pz, pt = (np.array(path[k]) for k in ("x", "y", "z", "t"))
    pts, orig = [], []
    chunk_id, buf = -1, None
    for k in range(len(sc["t"])):
        if sc["c"][k] != chunk_id:
            chunk_id = sc["c"][k]
            buf = open(os.path.join(out, sc["chunks"][chunk_id]), "rb").read()
        o, m = sc["o"][k], sc["n"][k]
        p = np.stack([np.frombuffer(buf, "<i2", m, o + 2 * m * a) for a in range(3)], 1).astype(np.float32) / 100
        j = min(len(pt) - 1, int(np.searchsorted(pt, sc["t"][k])))
        pts.append(p)
        orig.append(np.array([px[j], py[j], pz[j]], np.float32))
    return pts, orig


def build_mesh(out: str, session: str | None = None, progress=None) -> dict:
    say = progress or (lambda f, s: None)
    t_start = time.time()
    full = bool(session) and os.path.isfile(os.path.join(session, "bag", "bag_0.db3"))
    pts, orig = scans_from_bag(session, out, say) if full else scans_from_bundles(out)
    carve_every = 6 if full else 2

    allp = np.concatenate(pts)
    zlo, zhi = np.percentile(allp[:, 2], [0.3, 99.7])
    lo = np.array([allp[:, 0].min(), allp[:, 1].min(), zlo], np.float32) - 4 * VOXEL
    hi = np.array([allp[:, 0].max(), allp[:, 1].max(), zhi], np.float32) + 4 * VOXEL
    dims = np.ceil((hi - lo) / VOXEL).astype(np.int64) + 1
    nvox = int(np.prod(dims))
    del allp
    tsum = np.zeros(nvox, np.float32)
    wsum = np.zeros(nvox, np.uint16)

    def vote(X, val):
        g = np.floor((X - lo) / VOXEL).astype(np.int64)
        ok = (g >= 0).all(1) & (g < dims).all(1)
        g, val = g[ok], val[ok]
        key = (g[:, 0] * dims[1] + g[:, 1]) * dims[2] + g[:, 2]
        uk, inv = np.unique(key, return_inverse=True)
        tsum[uk] += np.bincount(inv, val, len(uk)).astype(np.float32)
        wsum[uk] = np.minimum(wsum[uk].astype(np.int64) + np.bincount(inv, None, len(uk)), 65000).astype(np.uint16)

    band = np.arange(-TRUNC, TRUNC + 1e-6, VOXEL / 2, dtype=np.float32)   # offsets around the hit
    nb = len(band)
    for b0 in range(0, len(pts), BATCH):
        P = np.concatenate(pts[b0:b0 + BATCH])
        O = np.concatenate([np.repeat(orig[k][None], len(pts[k]), 0) for k in range(b0, min(b0 + BATCH, len(pts)))])
        D = P - O
        L = np.linalg.norm(D, axis=1)
        keep = L > 0.3
        P, O, D, L = P[keep], O[keep], D[keep] / L[keep, None], L[keep]
        # near the hit: signed distance votes
        X = P[:, None, :] + D[:, None, :] * band[None, :, None]       # band < 0 = in front of the hit
        val = np.clip(-band / TRUNC, -1, 1)
        vote(X.reshape(-1, 3), np.tile(val, len(P)))
        # in front of that: "empty" votes, every other ray is enough
        sel = np.arange(len(P)) % carve_every == 0
        Oc, Dc, Lc = O[sel], D[sel], np.minimum(L[sel], CARVE_MAX) - TRUNC
        steps = np.maximum(0, (Lc / CARVE_STEP).astype(np.int64))
        tot = int(steps.sum())
        if tot:
            ray = np.repeat(np.arange(len(Lc)), steps)
            first = np.repeat(np.cumsum(steps) - steps, steps)
            s = ((np.arange(tot) - first) + 0.5).astype(np.float32) * CARVE_STEP
            vote(Oc[ray] + Dc[ray] * s[:, None], np.ones(tot, np.float32))
        say(0.15 + min(b0 + BATCH, len(pts)) / len(pts) * 0.65, "fusing")

    tsum = tsum.reshape(dims)
    wsum = wsum.reshape(dims)
    cache = os.environ.get("UNFOLD_MESH_CACHE")
    if cache:
        np.savez(cache, tsum=tsum, wsum=wsum, lo=lo, dims=dims, n=len(pts), full=full)
    return finish(out, tsum, wsum, lo, dims, len(pts), full, t_start, say)


def vertex_normals(V, F):
    fn = np.cross(V[F[:, 1]] - V[F[:, 0]], V[F[:, 2]] - V[F[:, 0]])
    N = np.zeros_like(V)
    for k in range(3):
        for c in range(3):
            N[:, c] += np.bincount(F[:, k], fn[:, c], len(V))
    return N / np.maximum(np.linalg.norm(N, axis=1, keepdims=True), 1e-12)


def taubin(V, F, iters=6, lam=0.5, mu=-0.53):
    """Smooths small bumps without shrinking the model."""
    e = np.concatenate([F[:, [0, 1]], F[:, [1, 2]], F[:, [2, 0]]])
    e = np.concatenate([e, e[:, ::-1]])
    A = sparse.csr_matrix((np.ones(len(e), np.float32), (e[:, 0], e[:, 1])), shape=(len(V), len(V)))
    A.data[:] = 1.0
    deg = np.asarray(A.sum(1)).ravel()[:, None]
    deg[deg == 0] = 1
    for _ in range(iters):
        V = V + lam * (A @ V / deg - V)
        V = V + mu * (A @ V / deg - V)
    return V.astype(np.float32)


def flatten_floor(V, N, out):
    """Floor patches get one smooth height per 0.5 m (the floor under the car drifts slowly, so not one plane)."""
    path = json.load(open(os.path.join(out, "path.json")))
    pj = os.path.join(out, "plan.json")
    h = json.load(open(pj)).get("lidar_height_m", 0.31) if os.path.isfile(pj) else 0.31
    pxy = np.c_[path["x"], path["y"]]
    pz = np.array(path["z"])
    ptree = cKDTree(pxy)
    pose_floor = lambda q: pz[ptree.query(q)[1]] - h
    near_floor = pose_floor(V[:, :2])
    cand = (np.abs(N[:, 2]) > 0.8) & (np.abs(V[:, 2] - near_floor) < 0.2)
    C = 0.5
    x0, y0 = V[:, 0].min() - 2, V[:, 1].min() - 2
    W, H = int((V[:, 0].max() + 2 - x0) / C) + 2, int((V[:, 1].max() + 2 - y0) / C) + 2
    ci = ((V[:, 0] - x0) / C).astype(int)
    cj = ((V[:, 1] - y0) / C).astype(int)
    cell = cj * W + ci
    s = np.bincount(cell[cand], V[cand, 2], W * H).reshape(H, W)
    n = np.bincount(cell[cand], None, W * H).reshape(H, W).astype(float)
    s2, n2 = ndimage.gaussian_filter(s, 1.0), ndimage.gaussian_filter(n, 1.0)
    zgrid = s2 / np.maximum(n2, 1e-9)
    have = n2 > 0.5

    def floor_z(q):
        """Smooth floor height at any xy: measured floor where there is some, else the height under the car."""
        fx, fy = (q[:, 0] - x0) / C - 0.5, (q[:, 1] - y0) / C - 0.5
        z = ndimage.map_coordinates(zgrid, [fy, fx], order=1, mode="nearest")
        w = ndimage.map_coordinates(have.astype(float), [fy, fx], order=1, mode="nearest")
        return np.where(w > 0.5, z, pose_floor(q))

    ok = cand & have.ravel()[cell]
    V = V.copy()
    V[ok, 2] = floor_z(V[ok, :2])
    return V, ok, floor_z


def drop_specks(V, F, min_area=0.04):
    """Loose bits (pieces not joined to anything, smaller than min_area m2) are noise: remove them."""
    e = np.concatenate([F[:, [0, 1]], F[:, [1, 2]]])
    G = sparse.coo_matrix((np.ones(len(e), np.int8), (e[:, 0], e[:, 1])), shape=(len(V), len(V)))
    _, lab = csgraph.connected_components(G, directed=False)
    area = 0.5 * np.linalg.norm(np.cross(V[F[:, 1]] - V[F[:, 0]], V[F[:, 2]] - V[F[:, 0]]), axis=1)
    comp_area = np.bincount(lab[F[:, 0]], area)
    keep = comp_area[lab[F[:, 0]]] >= min_area
    return F[keep], int((comp_area < min_area).sum())


def floor_sheet(out, floor_z, F_old, V_old, on_floor):
    """A clean, continuous floor wherever rays passed over the floor (plan.bin), replacing the patchy scanned one."""
    pj = os.path.join(out, "plan.json")
    if not os.path.isfile(pj):
        return V_old, F_old, 0
    plan = json.load(open(pj))
    W, H, cell, x0, y0 = plan["W"], plan["H"], plan["cell"], plan["x0"], plan["y0"]
    st = np.frombuffer(open(os.path.join(out, "plan.bin"), "rb").read(), np.uint8, W * H).reshape(H, W)
    use = ndimage.binary_dilation(st > 0, np.ones((3, 3)))          # floor + under walls + one cell around
    use = ndimage.binary_closing(use, np.ones((5, 5)))               # close pinholes
    # corners of used cells
    cu = np.zeros((H + 1, W + 1), bool)
    for dj in (0, 1):
        for di in (0, 1):
            cu[dj:dj + H, di:di + W] |= use
    cj, ci = np.nonzero(cu)
    vid = -np.ones((H + 1, W + 1), np.int64)
    vid[cj, ci] = np.arange(len(cj))
    P = np.c_[x0 + ci * cell, y0 + cj * cell].astype(np.float32)
    Vf = np.c_[P, floor_z(P)].astype(np.float32)
    j, i = np.nonzero(use)
    a, b, c, d = vid[j, i], vid[j, i + 1], vid[j + 1, i + 1], vid[j + 1, i]
    Ff = np.concatenate([np.c_[a, b, c], np.c_[a, c, d]])
    # remove the scanned floor: triangles pressed onto the floor, and any flat-ish scrap lying
    # within 12 cm of it (the new sheet covers that band; upright wall bottoms stay)
    low = V_old[:, 2] < floor_z(V_old[:, :2]) + 0.12
    fn = np.cross(V_old[F_old[:, 1]] - V_old[F_old[:, 0]], V_old[F_old[:, 2]] - V_old[F_old[:, 0]])
    flat = np.abs(fn[:, 2]) > 0.5 * np.maximum(np.linalg.norm(fn, axis=1), 1e-12)
    drop = on_floor[F_old].all(1) | (low[F_old].all(1) & flat)
    F_keep = F_old[~drop]
    return np.concatenate([V_old, Vf]), np.concatenate([F_keep, Ff + len(V_old)]), int(len(Ff))


def fill_small_holes(V, F, max_perimeter=0.35, max_edges=24):
    """Closes small holes: boundary loops shorter than max_perimeter get a fan of triangles to their centre."""
    e = np.concatenate([F[:, [0, 1]], F[:, [1, 2]], F[:, [2, 0]]])
    key = np.minimum(e[:, 0], e[:, 1]) * len(V) + np.maximum(e[:, 0], e[:, 1])
    uk, cnt = np.unique(key, return_counts=True)
    border = e[np.isin(key, uk[cnt == 1])]
    nxt = {}
    bad = set()
    for a, b in border:
        if a in nxt:
            bad.add(a)
        nxt[a] = b
    seen, newV, newF = set(), [], []
    nv = len(V)
    for s in list(nxt):
        if s in seen or s in bad:
            continue
        loop, v = [], s
        while v not in seen and v in nxt and v not in bad and len(loop) <= max_edges:
            seen.add(v)
            loop.append(v)
            v = nxt[v]
        if v != s or len(loop) < 3 or len(loop) > max_edges:
            continue
        L = np.array(loop)
        per = np.linalg.norm(V[L] - V[np.roll(L, -1)], axis=1).sum()
        if per > max_perimeter:
            continue
        c = nv + len(newV)
        newV.append(V[L].mean(0))
        for k in range(len(L)):                 # border edges run a->b, so the cap runs b->a
            newF.append((L[(k + 1) % len(L)], L[k], c))
    if not newV:
        return V, F, 0
    return np.concatenate([V, np.array(newV, np.float32)]), np.concatenate([F, np.array(newF, F.dtype)]), len(newV)


def flatten_walls(V, N, skip, tol=0.12, max_tilt_deg=35, min_verts=200):
    """Upright surfaces that share a plane are pressed onto that plane.

    Planes are found by voting: every upright corner votes for (direction, distance from origin) in
    2 degree x 3 cm bins; the biggest bins become planes, fitted to their corners, and every corner
    within tol of a plane whose own direction is within max_tilt_deg is moved onto it."""
    cand = (np.abs(N[:, 2]) < 0.4) & ~skip
    idx = np.flatnonzero(cand)
    n2 = N[idx, :2] / np.maximum(np.linalg.norm(N[idx, :2], axis=1, keepdims=True), 1e-9)
    th = np.arctan2(n2[:, 1], n2[:, 0])
    flip = th < 0
    n2[flip] *= -1
    th = np.where(flip, th + np.pi, th) % np.pi
    p2 = V[idx, :2].astype(np.float64)
    d = (n2 * p2).sum(1)
    TB, DB = np.radians(2), 0.03
    tb = np.round(th / TB).astype(np.int64) % 90
    db = np.round(d / DB).astype(np.int64)
    key = tb * 1_000_000 + (db + 500_000)
    order = np.argsort(key, kind="stable")
    sk = key[order]
    uk, start, cnt = np.unique(sk, return_index=True, return_counts=True)
    bucket = {int(k): order[s:s + c] for k, s, c in zip(uk, start, cnt)}
    by_dir = [np.flatnonzero(tb == t) for t in range(90)]
    done = np.zeros(len(idx), bool)
    V = V.copy()
    planes = 0
    found = []
    cos_tilt = np.cos(np.radians(max_tilt_deg))
    tspan, dspan = int(np.ceil(max_tilt_deg / 2)), int(np.ceil(tol / DB))

    def gather(t0, d0, tr, dr):
        parts = [bucket.get(int(((t0 + a) % 90) * 1_000_000 + d0 + b + 500_000)) for a in range(-tr, tr + 1) for b in range(-dr, dr + 1)]
        parts = [p for p in parts if p is not None]
        return np.concatenate(parts) if parts else np.empty(0, np.int64)

    for k in uk[np.argsort(-cnt)]:
        if len(bucket[int(k)]) < min_verts // 4:
            break
        t0, d0 = int(k // 1_000_000), int(k % 1_000_000 - 500_000)
        core = gather(t0, d0, 1, 1)
        core = core[~done[core]]
        if len(core) < min_verts:
            continue
        P = p2[core]
        c = P.mean(0)
        _, evec = np.linalg.eigh(np.cov((P - c).T))
        n = evec[:, 0]
        if n @ np.array([np.cos(t0 * TB), np.sin(t0 * TB)]) < 0:
            n = -n
        dd = n @ c
        # a tilted corner's own offset says little, so test every corner whose direction is close enough
        near = np.concatenate([by_dir[(t0 + a) % 90] for a in range(-tspan, tspan + 1)])
        near = near[~done[near]]
        dist = p2[near] @ n - dd
        ok = (np.abs(dist) < tol) & (np.abs(n2[near] @ n) > cos_tilt)
        near, dist = near[ok], dist[ok]
        if len(near) < min_verts:
            continue
        V[idx[near], :2] -= (dist[:, None] * n[None, :]).astype(V.dtype)
        done[near] = True
        planes += 1
        found.append((n.copy(), float(dd), idx[near]))
    return V, planes, int(done.sum()), found


def wall_blocks(V, F, planes, floor_z, solid, lo, dims, path_xy, thick=0.10, gap=0.35, low=(0.1, 1.6), clear=0.5):
    """Clean wall blocks in place of the scanned skin, only where a flat wall was clearly found.

    Openings are looked for low down (between low[0] and low[1] above the floor), because a doorway
    has wall above it: wherever the low part of a wall has a gap wider than `gap`, the wall is split.
    Wherever the car drove through the wall line, it is split too, `clear` m either side. Every
    remaining run becomes a straight block from the floor to the top the wall was seen at, `thick`
    deep on the solid side. Everything else (lintels, furniture, odd shapes) keeps its scanned shape."""
    flat = solid.ravel()

    def is_solid(X):
        g = np.floor((X - lo) / VOXEL).astype(np.int64)
        ok = (g >= 0).all(-1) & (g < dims).all(-1)
        g = np.where(ok[..., None], g, 0)
        return ok & flat[(g[..., 0] * dims[1] + g[..., 1]) * dims[2] + g[..., 2]]

    replaced = np.zeros(len(V), bool)
    BV, BF = [], []
    for n, dd, ids in planes:
        u = np.array([-n[1], n[0]])
        P = V[ids]
        s_all = P[:, :2] @ u
        h = P[:, 2] - floor_z(P[:, :2])
        lowm = (h > low[0]) & (h < low[1])
        if lowm.sum() < 40:
            continue
        sl = np.sort(s_all[lowm])
        cuts = np.flatnonzero(np.diff(sl) > gap) + 1
        runs = [(sl[a], sl[b - 1]) for a, b in zip(np.r_[0, cuts], np.r_[cuts, len(sl)]) if b - a >= 40]
        # where the car crossed this wall line, there is no wall
        dpath = path_xy @ n - dd
        cross = np.flatnonzero(np.abs(dpath) < 0.3)
        if len(cross):
            sp = np.sort(path_xy[cross] @ u)
            pieces = []
            for r0, r1 in runs:
                cur = r0
                for c in sp[(sp > r0 - clear) & (sp < r1 + clear)]:
                    if c - clear > cur:
                        pieces.append((cur, c - clear))
                    cur = max(cur, c + clear)
                if r1 > cur:
                    pieces.append((cur, r1))
            runs = pieces
        for r0, r1 in runs:
            if r1 - r0 < 0.25:
                continue
            inrun = (s_all >= r0) & (s_all <= r1)
            seg = P[inrun]
            if len(seg) < 40:
                continue
            a_ids = ids[inrun]
            s = np.array([r0, r1])
            a, b = 0, 2
            mid = np.r_[n * dd + u * (0.5 * (r0 + r1)), 0]
            zb = float(floor_z(np.array([mid[:2]]))[0]) - 0.02
            zt = float(np.percentile(seg[:, 2], 97))
            if zt - zb < 0.3:
                continue
            # which side is the inside of the wall? probe a few points just off the plane
            probe = n * dd + u[None, :] * np.linspace(s[a], s[b - 1], 7)[:, None]
            zc = np.full((7, 1), 0.5 * (zb + zt))
            front = is_solid(np.c_[probe + 0.08 * n, zc]).sum()
            back = is_solid(np.c_[probe - 0.08 * n, zc]).sum()
            depth = -n if back >= front else n
            c0, c1 = n * dd + u * s[a], n * dd + u * s[b - 1]
            q = [c0, c1, c1 + depth * thick, c0 + depth * thick]
            C8 = np.array([[*p, zb] for p in q] + [[*p, zt] for p in q], np.float32)
            # every face gets its own 4 corners so the block keeps sharp, flat-lit edges
            quads = [(0, 3, 2, 1), (4, 5, 6, 7), (0, 1, 5, 4), (1, 2, 6, 5), (2, 3, 7, 6), (3, 0, 4, 7)]
            k = sum(len(x) for x in BV)
            BV.append(np.concatenate([C8[list(qd)] for qd in quads]))
            BF.append(np.concatenate([np.array([[0, 1, 2], [0, 2, 3]]) + 4 * j for j in range(6)]) + k)
            replaced[a_ids] = True
    F = F[~replaced[F].all(1)]
    if not BV:
        return F, np.zeros((0, 3), np.float32), np.zeros((0, 3), np.int64), 0
    return F, np.concatenate(BV), np.concatenate(BF), len(BV)


def bake_ao(V, N, solid, lo, dims, chunk=60_000):
    """How open the space around each corner is: 16 short rays into the fused cubes. 1 = open, darker in corners."""
    rng = np.random.default_rng(7)
    u, v = rng.random(16), rng.random(16)
    r, ph = np.sqrt(u), 2 * np.pi * v
    local = np.stack([r * np.cos(ph), r * np.sin(ph), np.sqrt(1 - u)], 1).astype(np.float32)   # cosine-weighted
    steps = np.array([0.08, 0.14, 0.22, 0.32, 0.45, 0.6, 0.8], np.float32)
    ao = np.empty(len(V), np.float32)
    flat = solid.ravel()

    def is_solid(X):
        g = np.floor((X - lo) / VOXEL).astype(np.int64)
        ok = (g >= 0).all(-1) & (g < dims).all(-1)
        g = np.where(ok[..., None], g, 0)
        return ok & flat[(g[..., 0] * dims[1] + g[..., 1]) * dims[2] + g[..., 2]]

    for a in range(0, len(V), chunk):
        p, n = V[a:a + chunk], N[a:a + chunk].copy()
        # point every normal at the open side
        n[is_solid(p + 0.07 * n)] *= -1
        t = np.cross(n, np.where(np.abs(n[:, :1]) < 0.9, [[1, 0, 0]], [[0, 1, 0]]))
        t /= np.maximum(np.linalg.norm(t, axis=1, keepdims=True), 1e-9)
        b = np.cross(n, t)
        dirs = local[None, :, 0:1] * t[:, None] + local[None, :, 1:2] * b[:, None] + local[None, :, 2:3] * n[:, None]
        hit = np.zeros(dirs.shape[:2], bool)
        for s in steps:
            hit |= is_solid(p[:, None, :] + dirs * s)
        ao[a:a + chunk] = 1 - hit.mean(1)
    return ao


def finish(out, tsum, wsum, lo, dims, n_scans, full, t_start, say):
    # --- surface: where the average flips sign, only among well-seen cubes
    verts, faces, nv = [], [], 0
    TILE = 150
    for a in range(0, dims[0] - 1, TILE):
        b = min(a + TILE, dims[0] - 1)
        w = wsum[a:b + 1].astype(np.float32)
        obs = w >= W_MIN
        if not obs.any():
            continue
        f = np.where(obs, tsum[a:b + 1] / np.maximum(w, 1), 1.0).astype(np.float32)
        # a cube is used only if all 8 corners were well seen
        m = obs.copy()
        m[:-1] &= obs[1:]
        m[:, :-1] &= m[:, 1:].copy() & obs[:, 1:]
        m[:, :, :-1] &= m[:, :, 1:].copy() & obs[:, :, 1:]
        if not ((f < 0) & m).any():
            continue
        try:
            v, fc, _, _ = marching_cubes(f, 0.0, mask=m, allow_degenerate=False)
        except (ValueError, RuntimeError):
            continue
        v[:, 0] += a
        verts.append(v.astype(np.float32))
        faces.append(fc.astype(np.int64) + nv)
        nv += len(v)
        say(0.8 + 0.1 * b / dims[0], "surface")
    solid = (wsum >= W_MIN) & (tsum < 0)
    del tsum, wsum
    V = np.concatenate(verts) * VOXEL + lo
    F = np.concatenate(faces)

    # weld the corners where two tiles meet, drop tiny loose bits
    q = np.round(V / (VOXEL * 0.01)).astype(np.int64)
    _, first, inv = np.unique(q, axis=0, return_index=True, return_inverse=True)
    V, F = V[first], inv.reshape(-1)[F]
    F = F[(F[:, 0] != F[:, 1]) & (F[:, 1] != F[:, 2]) & (F[:, 0] != F[:, 2])]
    raw_nt = len(F)

    # --- tidy: smooth small bumps, then press floors and walls flat
    say(0.91, "flatten")
    F, n_specks = drop_specks(V, F)
    V = taubin(V.astype(np.float32), F, iters=12)
    N = vertex_normals(V, F)
    V, on_floor, floor_z = flatten_floor(V, N, out)
    # walls drift a little over long distances, so planes are found per 4 m tile (twice, offset by
    # half a tile, so a wall cut by a tile edge is caught whole the second time)
    n_planes = n_wall = 0
    done = on_floor.copy()
    planes = []
    for off in (0.0, 2.0):
        tx = np.floor((V[:, 0] + off) / 4.0).astype(np.int64)
        ty = np.floor((V[:, 1] + off) / 4.0).astype(np.int64)
        tile = tx * 100_000 + ty
        order = np.argsort(tile, kind="stable")
        ut, st, ct = np.unique(tile[order], return_index=True, return_counts=True)
        for s, c in zip(st, ct):
            ids = order[s:s + c]
            sub = ids[~done[ids]]
            if len(sub) < 200:
                continue
            Vs, p, w, pl = flatten_walls(V[sub], N[sub], np.zeros(len(sub), bool), min_verts=120)
            V[sub] = Vs
            for n, dd, loc in pl:
                done[sub[loc]] = True
                planes.append((n, dd, sub[loc]))
            n_planes += p
            n_wall += w
    V, F, n_floor_tris = floor_sheet(out, floor_z, F, V, on_floor)
    pth = json.load(open(os.path.join(out, "path.json")))
    pxy = np.c_[pth["x"], pth["y"]]
    F, BV, BF, n_blocks = wall_blocks(V, F, planes, floor_z, solid, lo, dims, pxy)
    # Nothing can stand where the car drove: clear a lane along the path (car width + margin,
    # up to door height) of any scanned surface, e.g. a half-open door or a door frame.
    cen = V[F].mean(1)
    dlane, _ = cKDTree(pxy).query(cen[:, :2], distance_upper_bound=0.25)
    hz = cen[:, 2] - floor_z(cen[:, :2])
    in_lane = (dlane < 0.2) & (hz > 0.04) & (hz < 1.8)
    F = F[~in_lane]
    n_lane = int(in_lane.sum())
    F, n_specks2 = drop_specks(V, F, min_area=0.15)
    n_specks += n_specks2
    V, F, n_holes = fill_small_holes(V, F)
    used = np.zeros(len(V), bool)
    used[F.ravel()] = True
    remap = np.cumsum(used) - 1
    V, F = V[used], remap[F]

    if len(F) > TARGET_TRIS:
        say(0.96, "simplify")
        s = pyfqmr.Simplify()
        s.setMesh(V.astype(np.float64), F.astype(np.int32))
        s.simplify_mesh(target_count=TARGET_TRIS, aggressiveness=4, preserve_border=True, verbose=False)
        V, F, _ = s.getMesh()
    # the clean wall blocks join after simplifying, so their edges stay exact
    F = np.concatenate([F.astype(np.int64), BF + len(V)])
    V = np.concatenate([V.astype(np.float32), BV])
    V, F = V.astype(np.float32), F.astype(np.uint32)

    say(0.97, "shading")
    ao = bake_ao(V, vertex_normals(V, F.astype(np.int64)), solid, lo, dims)
    del solid

    # colour every corner from the nearest camera-coloured map point
    col = np.full((len(V), 3), 150, np.uint8)
    coloured = 0.0
    mb, rb = os.path.join(out, "map.bin"), os.path.join(out, "map_rgb.bin")
    if os.path.isfile(mb) and os.path.isfile(rb):
        b = open(mb, "rb").read()
        n = len(b) // 8
        if os.path.getsize(rb) == 4 * n:
            MP = np.stack([np.frombuffer(b, "<i2", n, k * 2 * n) for k in range(3)], 1).astype(np.float32) / 100
            c = np.frombuffer(open(rb, "rb").read(), np.uint8)
            seen = c[3 * n:] > 0
            C = np.stack([c[:n], c[n:2 * n], c[2 * n:3 * n]], 1)[seen]
            d, j = cKDTree(MP[seen]).query(V, k=1, distance_upper_bound=0.2)
            ok = np.isfinite(d)
            col[ok] = C[j[ok]]
            coloured = float(ok.mean())

    pos = np.round(V * 100).astype("<i2")
    with open(os.path.join(out, "mesh.bin"), "wb") as fh:
        fh.write(pos.tobytes())
        fh.write(col.tobytes())
        fh.write(np.round(np.clip(ao, 0, 1) * 255).astype(np.uint8).tobytes())
        fh.write(b"\0" * ((-fh.tell()) % 4))
        fh.write(F.astype("<u4").tobytes())
    meta = {"nv": int(len(V)), "nt": int(len(F)), "nt_before_simplify": int(raw_nt), "voxel_m": VOXEL,
            "method": "tsdf", "source": "bag" if full else "scan bundles", "scans": n_scans, "coloured": round(coloured, 3),
            "ao": True, "wall_planes": n_planes, "flattened": {"floor": int(on_floor.sum()), "wall": n_wall},
            "tidy": {"wall_blocks": n_blocks, "lane_cleared_tris": n_lane, "specks_removed": n_specks, "holes_filled": n_holes, "floor_sheet_tris": n_floor_tris},
            "format": "int16 pos[3*nv] cm, uint8 rgb[3*nv], uint8 ao[nv], pad to 4, uint32 idx[3*nt]",
            "run_s": round(time.time() - t_start, 1)}
    json.dump(meta, open(os.path.join(out, "mesh.json"), "w"), indent=1)
    return meta


if __name__ == "__main__":
    # python mesh_session.py <out_folder> [<session_folder with bag/bag_0.db3>]
    # set UNFOLD_MESH_CACHE=<file.npz> to keep the fused cubes; --from-cache reuses them (for tuning)
    if "--from-cache" in sys.argv:
        z = np.load(os.environ["UNFOLD_MESH_CACHE"])
        print(json.dumps(finish(sys.argv[1], z["tsum"], z["wsum"], z["lo"], z["dims"], int(z["n"]), bool(z["full"]), time.time(),
                                lambda f, s: print(f"\r{s} {f * 100:.0f}%   ", end="", flush=True)), indent=1))
        sys.exit()
    print(json.dumps(build_mesh(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else None,
                                lambda f, s: print(f"\r{s} {f * 100:.0f}%   ", end="", flush=True)), indent=1))
