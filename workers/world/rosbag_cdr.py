"""Read a ROS 2 sqlite3 bag (CDR serialization) without ROS.

Decoders for the three QCar 2 session topics:
  sensor_msgs/msg/CompressedImage, sensor_msgs/msg/Imu, livox_ros_driver2/msg/CustomMsg.

CDR notes: every blob starts with a 4-byte encapsulation header (byte 1: 0x01 = little endian);
alignment is relative to the payload that follows it. Times come from the header stamp inside
each message, never from the bag's receive-time column.
"""
from __future__ import annotations

import sqlite3
import struct
from dataclasses import dataclass

import numpy as np

TOPIC_LIDAR = "/livox/lidar"
TOPIC_IMU = "/livox/imu"
TOPIC_IMAGE = "/csi_front/image_raw/compressed"


class Cdr:
    """Sequential little-endian CDR reader over one message payload."""

    def __init__(self, blob: bytes):
        if len(blob) < 4 or blob[1] != 0x01:
            raise ValueError("expected little-endian CDR encapsulation")
        self.b = memoryview(blob)[4:]
        self.p = 0

    def align(self, n: int) -> None:
        self.p += (-self.p) % n

    def u8(self) -> int:
        v = self.b[self.p]
        self.p += 1
        return v

    def u32(self) -> int:
        self.align(4)
        v = struct.unpack_from("<I", self.b, self.p)[0]
        self.p += 4
        return v

    def i32(self) -> int:
        self.align(4)
        v = struct.unpack_from("<i", self.b, self.p)[0]
        self.p += 4
        return v

    def u64(self) -> int:
        self.align(8)
        v = struct.unpack_from("<Q", self.b, self.p)[0]
        self.p += 8
        return v

    def f64s(self, n: int) -> tuple:
        self.align(8)
        v = struct.unpack_from(f"<{n}d", self.b, self.p)
        self.p += 8 * n
        return v

    def string(self) -> str:
        n = self.u32()  # length includes the trailing NUL
        s = bytes(self.b[self.p:self.p + n - 1]).decode("utf-8", "replace")
        self.p += n
        return s

    def header(self) -> tuple[float, str]:
        sec = self.i32()
        nsec = self.u32()
        return sec + nsec * 1e-9, self.string()


def decode_image(blob: bytes) -> tuple[float, str, bytes]:
    """CompressedImage -> (stamp, format, JPEG bytes)."""
    r = Cdr(blob)
    stamp, _ = r.header()
    fmt = r.string()
    n = r.u32()
    data = bytes(r.b[r.p:r.p + n])
    if data[:2] != b"\xff\xd8":
        raise ValueError(f"image payload is not a JPEG (format {fmt!r})")
    return stamp, fmt, data


@dataclass
class ImuSample:
    t: float
    gyro: tuple  # rad/s
    accel: tuple  # as reported by the driver (Livox: g)


def decode_imu(blob: bytes) -> ImuSample:
    r = Cdr(blob)
    stamp, _ = r.header()
    r.f64s(4)  # orientation (unused by the Livox driver)
    r.f64s(9)
    gyro = r.f64s(3)
    r.f64s(9)
    accel = r.f64s(3)
    return ImuSample(stamp, gyro, accel)


# One Livox point: offset_time u32, x y z f32, reflectivity u8, tag u8, line u8 = 19 bytes,
# padded by CDR to 20 bytes per element except after the last one.
POINT_DTYPE = np.dtype([("offset_time", "<u4"), ("x", "<f4"), ("y", "<f4"), ("z", "<f4"),
                        ("reflectivity", "u1"), ("tag", "u1"), ("line", "u1"), ("_pad", "u1")])


def decode_lidar(blob: bytes) -> tuple[float, int, np.ndarray]:
    """Livox CustomMsg -> (stamp, timebase ns, structured point array)."""
    r = Cdr(blob)
    stamp, _ = r.header()
    timebase = r.u64()
    point_num = r.u32()
    r.u8()  # lidar_id
    r.p += 3  # rsvd[3]
    n = r.u32()  # sequence length
    if n != point_num:
        raise ValueError(f"point_num {point_num} != sequence length {n}")
    start = r.p
    if n == 0:
        return stamp, timebase, np.zeros(0, POINT_DTYPE)
    body = r.b[start:]
    if len(body) < 20 * (n - 1) + 19:
        raise ValueError("lidar message shorter than its point count")
    pts = np.empty(n, POINT_DTYPE)
    if n > 1:
        pts[:-1] = np.frombuffer(body, POINT_DTYPE, count=n - 1)
    last = bytes(body[20 * (n - 1):20 * (n - 1) + 19]) + b"\x00"
    pts[-1] = np.frombuffer(last, POINT_DTYPE, count=1)[0]
    return stamp, timebase, pts


class Bag:
    """Read-only access to bag_0.db3."""

    def __init__(self, path: str):
        self.db = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
        self.topics = {name: tid for tid, name in self.db.execute("select id, name from topics")}

    def span_ns(self) -> tuple[int, int]:
        return self.db.execute("select min(timestamp), max(timestamp) from messages").fetchone()

    def iter(self, topic: str, t0_ns: int | None = None, t1_ns: int | None = None):
        """Yield raw blobs of one topic, in receive order, optionally within a receive-time window."""
        tid = self.topics[topic]
        if t0_ns is None:
            q = self.db.execute("select data from messages where topic_id = ? order by timestamp", (tid,))
        else:
            q = self.db.execute("select data from messages where topic_id = ? and timestamp between ? and ? order by timestamp",
                                (tid, t0_ns, t1_ns))
        for (blob,) in q:
            yield blob

    def count(self, topic: str) -> int:
        return self.db.execute("select count(*) from messages where topic_id = ?", (self.topics[topic],)).fetchone()[0]
