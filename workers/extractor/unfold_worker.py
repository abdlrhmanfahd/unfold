"""Unfold extraction worker (free-AI and local-ready).

Pulls extraction jobs from the Unfold WordPress site, reads the project report and
files using a free AI provider (Google Gemini Free Tier, local Ollama, OpenAI-compatible
free endpoints like Groq/OpenRouter, or zero-AI deterministic local fallback), and sends
back a project description matching the Unfold JSON Schema.

Environment:
  UNFOLD_SITE          Site URL, e.g. http://localhost:8881
  UNFOLD_WORKER_TOKEN  Token from wp-admin -> Projects -> Settings (or worker-token.txt)
  UNFOLD_AI_PROVIDER   gemini | ollama | openai_compat | local_rules | anthropic (auto-detected if unset)
  GEMINI_API_KEY       Free-tier key from Google AI Studio (or gemini-key.txt)
  UNFOLD_OLLAMA_URL    Ollama endpoint (default http://localhost:11434)
  UNFOLD_AI_MODEL      Override default model for the active provider
  UNFOLD_AI_BASE_URL   Base URL for openai_compat (e.g. https://api.groq.com/openai/v1)
  UNFOLD_AI_API_KEY    API key for openai_compat
"""

from __future__ import annotations

import argparse
import ast
import base64
import copy
import csv
import html
import io
import json
import logging
import math
import os
import re
import sys
import time
import zipfile
import zlib
from pathlib import PurePosixPath
from urllib.parse import quote

import requests

DEFAULT_GEMINI_MODEL = "gemini-2.5-flash"
DEFAULT_OLLAMA_URL = "http://localhost:11434"
DEFAULT_OLLAMA_MODEL = "qwen3:14b"
DEFAULT_OPENAI_MODEL = "llama-3.3-70b-versatile"
DEFAULT_ANTHROPIC_MODEL = "claude-opus-4-1"

_THINKING_MODEL_RE = re.compile(r"(?:^|/)(?:qwen3|deepseek-r1|qwq|gpt-oss|phi4-reasoning|cogito)", re.I)


def _is_thinking_model(model: str) -> bool:
    """Return True when the active Ollama model supports native reasoning traces."""
    override = os.environ.get("UNFOLD_OLLAMA_THINK")
    if override == "1":
        return True
    if override == "0":
        return False
    return bool(_THINKING_MODEL_RE.search((model or "").strip()))

POLL_SECONDS = 10
MAX_PDF_BYTES = 30 * 1024 * 1024
MAX_CONTEXT_CHARS = 120_000
BRIEF_VERSION = "1"

log = logging.getLogger("unfold-worker")

SYSTEM_PROMPT = """You turn an engineering project's report and files into a structured \
project description for Unfold, a website that presents projects as interactive, explorable systems: \
a block diagram of subsystems, each with its equations and parameters, plus results compared with targets.

Your output is reviewed by the people who did the project, who confirm or correct every value. Your job is to be faithful \
to what the report says, never to complete it.

The rule that matters most: never guess. Any number, name, or fact the report and files do not state \
is null. A plausible typical value is still a guess. Never fill team names, IDs, terms, or dates from \
placeholders such as "[TEAM TO COMPLETE]" - those are null.

How to fill the description:
- Tracked values ({value, source_ref, status}): value is the number exactly as reported (convert to SI \
units only when the report gives the unit), source_ref says where it comes from in the report \
("Sec 2.1, settings table", "p.4, Table 2", "Appendix A") or in a file ("p8_config.json"), and status \
is always "extracted". If the value is not stated: value null, source_ref null.
- Every project runs on a Quanser QCar 2. Subsystems are the software parts that run on the car \
(estimation, planning, control, safety logic...). Test procedures such as calibration runs, scoring \
or statistics are not parts: describe them in the goal, descriptions and results instead. Use short ids \
(lowercase, digits, underscores, e.g. "speed_ctrl"). name: what the part is in 1-3 plain words \
("Speed controller", "Position tracker"); the technical method goes in method ("PI control", "EKF"). \
plain: what it does in one short line under 10 words ("Holds the car at 0.6 m/s."). Describe each in \
2-4 plain sentences a non-specialist engineer can follow. Only list equations that appear in the report, \
as LaTeX. Only list parameters with values the report or config files give.
- on: the QCar 2 hardware the part runs on, reads or drives, from: chassis, wheels, servo (steering), \
motor (drive), encoder (speed), battery, board (main board), imu, compute (Jetson computer), lidar, \
depth (RealSense depth camera), csi (360 cameras), lights, wifi.
- meta.question: the project's question in one short plain line (at most 90 characters, e.g. "Can software fix bent wheels?"). \
meta.short_description: always write it - 2-3 plain sentences shown right under the title on the \
project page: what the project builds or tests, how, and on what (a non-specialist must follow it), from \
the report only. meta.headline: the one primary evaluation result that answers it (always pick the main \
overall objective/loss/safety benchmark from the abstract or primary results table before secondary counts), \
as before/after numbers from the report with a short label (at most 60 characters, e.g. \
"Mean paired block loss, immediate -> 1.0 s dwell"); null if the report has no such pair.
- sim: choose a template only when it models the part; otherwise null. Templates and their inputs:
  * path_follow (Stanley-type steering along a route, with an optional steering bias and its correction). \
Inputs: k, v_soft, dmax (rad), b (bias, rad), bhat (correction, rad), ref_off (m), corridor (m), \
duration (s), wheelbase (m), lr_frac, start_lateral (m), start_heading (rad), v_ref (m/s). \
Data: role "route" (CSV with x, y columns), role "speed" (CSV with time and measured speed), \
role "run" (CSV with time and signed lateral error, one per recorded run, with a label such as \
"Recorded: no fix" and set listing the inputs that run used, e.g. bhat = 0).
  * speed_loop (PI speed control with anti-windup). Inputs: v_ref, kp, ki, umax. Data: role "run" \
(CSV with time and measured speed).
  Every input is a tracked value from the report, a config file or a log's metadata file, with its \
source_ref; leave out inputs no file gives. data[].file is the exact path from the inventory and \
columns are exact header names, time first. type is "data_backed" when at least one run is given, \
else "conceptual". student_approved is true (the owner unticks it if the simulation is wrong).
- Connections: one entry for each signal that flows from one subsystem to another, so a block \
diagram can be drawn. Every from/to must be a subsystem id.
- Inputs/outputs: "from"/"to" is a subsystem id, a sensor type (lidar, rgb_camera, depth_camera, imu, \
wheel_encoder), "actuator", or a short plain name.
- Sensors: only sensors the project uses; used_by lists subsystem ids.
- data_files: only files that exist in the inventory, with their exact file name. For CSV files list \
the 1-4 columns worth plotting (first one is the x axis), using the exact header names shown. \
Prefer a few representative files over hundreds of repeated logs.
- results.media: only image or video files that exist in the inventory, with exact file names; use the \
report's figure captions when they match. part is the subsystem id the figure or video shows, or null when it shows the whole project.
- goal.success_criteria and results.metrics: use the same metric wording in both when a result answers \
a target, so they can be compared.
- results.limitations and future_work: as the report states them.
- Text may use inline LaTeX between $...$ for symbols (e.g. "$\\hat{b}$").
- Describe the project itself. Never mention the team, group, members, course, term or university in \
any text.
- schema_version is "0.2"."""

UNSUPPORTED = {"minLength", "maxLength", "minimum", "maximum", "pattern", "minItems", "maxItems", "$schema", "$id", "title"}


def output_schema(project_schema: dict) -> dict:
    """The Unfold JSON Schema, reduced to what structured outputs accept."""
    s = copy.deepcopy(project_schema)

    def clean(node):
        if isinstance(node, list):
            return [clean(n) for n in node]
        if not isinstance(node, dict):
            return node
        props = node.get("properties")
        node = {k: clean(v) for k, v in node.items() if k not in UNSUPPORTED and k != "properties"}
        if isinstance(props, dict):
            node["properties"] = {k: clean(v) for k, v in props.items()}
        t = node.get("type")
        if isinstance(t, list):
            enum = node.pop("enum", None)
            node.pop("type")
            variants = []
            for one in t:
                v = {"type": one}
                if enum is not None and one != "null":
                    v["enum"] = [e for e in enum if e is not None]
                if one == "object":
                    for k in ("properties", "required", "additionalProperties"):
                        if k in node:
                            v[k] = node.pop(k)
                if one == "array" and "items" in node:
                    v["items"] = node.pop("items")
                variants.append(v)
            node["anyOf"] = variants
        if node.get("type") == "object" or "properties" in node:
            props = node.get("properties", {})
            node["required"] = list(props.keys())
            node["additionalProperties"] = False
        for v in node.get("anyOf", []):
            if v.get("type") == "object" and "properties" in v:
                v["required"] = list(v["properties"].keys())
                v["additionalProperties"] = False
        return node

    s = clean(s)
    s["properties"].pop("review", None)
    s["required"] = [k for k in s["required"] if k != "review"]
    meta = s["properties"]["meta"]
    for k in ("term", "team", "credit"):
        meta["properties"].pop(k, None)
    meta["required"] = [k for k in meta["required"] if k not in ("term", "team", "credit")]
    s["$defs"] = s.pop("$defs")
    return s


TEXT_RUN = re.compile(r"<w:t(?:\s[^>]*)?>(.*?)</w:t>", flags=re.S)


def docx_text(data: bytes) -> str:
    """Paragraph and table text from a .docx, in document order."""
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        xml = z.read("word/document.xml").decode("utf-8", "replace")
    out = []
    for block in re.findall(r"<w:p[ >].*?</w:p>|<w:tbl>.*?</w:tbl>", xml, flags=re.S):
        if block.startswith("<w:tbl"):
            for row in re.findall(r"<w:tr[ >].*?</w:tr>", block, flags=re.S):
                cells = ["".join(TEXT_RUN.findall(c)) for c in re.findall(r"<w:tc>.*?</w:tc>", row, flags=re.S)]
                out.append(" | ".join(html.unescape(c).strip() for c in cells))
        else:
            t = html.unescape("".join(TEXT_RUN.findall(block)))
            if t.strip():
                out.append(t)
    return "\n".join(out)


def _is_clean_text_chunk(text: str) -> bool:
    """True when text looks like readable language rather than raw compressed/binary bytes."""
    s = (text or "").strip()
    if len(s) < 3:
        return False
    # Reject strings containing control characters or heavy extended-Latin1 binary noise.
    bad = sum(1 for ch in s if (ord(ch) < 32 and ch not in "\n\r\t") or ord(ch) > 126)
    if bad / len(s) > 0.08:
        return False
    good = sum(1 for ch in s if ch.isalnum() or ch in " \n\r\t.,;:!?()-_/+*=%[]'\"")
    if good / len(s) < 0.78:
        return False
    return bool(re.search(r"[A-Za-z]{2,}", s))


def _pdf_literal_strings(stream_bytes: bytes) -> list[str]:
    """Pull readable literal strings out of PDF BT..ET text blocks."""
    out: list[str] = []
    blocks = re.findall(rb"BT\b(.*?)\bET", stream_bytes, flags=re.S) or [stream_bytes]
    for blk in blocks:
        for raw in re.findall(rb"\(([^()]{3,240})\)", blk):
            # Ignore octal/binary escapes and non-ASCII junk.
            decoded = re.sub(r"\\[nrtbf\\()]", " ", raw.decode("latin1", "ignore"))
            decoded = re.sub(r"\s+", " ", decoded).strip()
            if _is_clean_text_chunk(decoded):
                out.append(decoded)
    return out


def pdf_text(data: bytes, max_chars: int = 120_000) -> str:
    """Extract readable text from a PDF, never returning compressed binary gibberish."""
    try:
        from pypdf import PdfReader  # type: ignore
        reader = PdfReader(io.BytesIO(data))
        chunks = []
        total = 0
        for idx, page in enumerate(reader.pages, start=1):
            t = (page.extract_text() or "").replace("\x00", " ").strip()
            if t and _is_clean_text_chunk(t):
                chunk = f"[Page {idx}]\n{t}"
                chunks.append(chunk)
                total += len(chunk)
                if total >= max_chars:
                    break
        if chunks:
            return "\n\n".join(chunks)[:max_chars]
    except Exception:  # noqa: BLE001
        pass

    # Safe fallback: only read BT..ET text operators inside decompressed or plain streams.
    collected: list[str] = []
    for raw_stream in re.findall(rb"stream\r?\n(.*?)\r?\nendstream", data, flags=re.S):
        candidate = raw_stream
        try:
            candidate = zlib.decompress(raw_stream)
        except Exception:  # noqa: BLE001
            pass
        collected.extend(_pdf_literal_strings(candidate))
        if sum(len(c) for c in collected) >= max_chars:
            break
    if not collected and b"BT" in data:
        collected.extend(_pdf_literal_strings(data))
    text = re.sub(r"\s+", " ", " ".join(collected)).strip()
    return text[:max_chars] if _is_clean_text_chunk(text) else ""


def human_size(n: int) -> str:
    return f"{n / 1048576:.1f} MB" if n > 1048576 else f"{max(1, n // 1024)} KB"


def _summarize_csv(z: zipfile.ZipFile, path: str) -> str:
    """Summarize headers, sample rows, numeric min/mean/max, and sample rate from a CSV/TSV."""
    delim = "\t" if path.lower().endswith(".tsv") else ","
    try:
        with z.open(path) as fh:
            raw_bytes = fh.read(262_144)
    except Exception:  # noqa: BLE001
        return ""
    raw = raw_bytes.decode("utf-8", "replace")
    lines = raw.splitlines()
    if len(raw_bytes) == 262_144 and len(lines) > 2:
        lines = lines[:-1]
    rows = list(csv.reader(lines[:2001], delimiter=delim))
    if not rows:
        return ""
    header = [c.strip() for c in rows[0][:12]]
    data_rows = rows[1:]
    out = [f"columns: {', '.join(header)} (sampled rows: {len(data_rows)})"]
    for row in data_rows[:3]:
        out.append("  sample: " + ", ".join(c.strip()[:60] for c in row[:12]))

    stats_lines = []
    time_names = {"t", "time", "t_s", "timestamp", "t_send", "t_req", "sec"}
    sample_hz = None
    for idx, col in enumerate(header):
        vals: list[float] = []
        for row in data_rows:
            if idx >= len(row):
                continue
            try:
                v = float(row[idx].strip())
            except ValueError:
                continue
            if math.isfinite(v):
                vals.append(v)
        if not vals:
            continue
        if sample_hz is None and col.lower() in time_names and len(vals) >= 2:
            dts = [vals[i + 1] - vals[i] for i in range(len(vals) - 1) if vals[i + 1] > vals[i]]
            if dts:
                avg_dt = sum(dts) / len(dts)
                if avg_dt > 0:
                    sample_hz = round(1.0 / avg_dt, 2)
        stats_lines.append(
            f"  {col}: min={min(vals):.4g}, mean={sum(vals) / len(vals):.4g}, max={max(vals):.4g}, last={vals[-1]:.4g}"
        )
    if sample_hz is not None:
        out.append(f"estimated_sample_rate_hz: {sample_hz}")
    if stats_lines:
        out.append("numeric_summary:\n" + "\n".join(stats_lines))
    return "\n".join(out)


def build_context(z: zipfile.ZipFile, files: list[dict], report: str | None) -> str:
    """Inventory plus extra PDFs/DOCXs, MD files, CSV stats, and runtime code within budget."""
    parts: list[str] = []
    budget = MAX_CONTEXT_CHARS

    def add(title: str, body: str) -> bool:
        nonlocal budget
        clean = (body or "").strip()
        if not clean:
            return False
        chunk = f'<file name="{title}">\n{clean}\n</file>\n'
        if len(chunk) > budget:
            return False
        parts.append(chunk)
        budget -= len(chunk)
        return True

    by_dir: dict[str, list[dict]] = {}
    for f in files:
        by_dir.setdefault(str(PurePosixPath(f["path"]).parent), []).append(f)
    inv = []
    for d, items in sorted(by_dir.items()):
        if len(items) > 12:
            kinds: dict[str, int] = {}
            for it in items:
                kinds[it["kind"]] = kinds.get(it["kind"], 0) + 1
            examples = ", ".join(PurePosixPath(it["path"]).name for it in items[:4])
            inv.append(f"{d}/ - {len(items)} files ({', '.join(f'{v} {k}' for k, v in kinds.items())}), e.g. {examples}, ...")
        else:
            for it in items:
                inv.append(f"{it['path']}  [{it['kind']}, {human_size(it['size'])}]")
    inventory = "\n".join(inv)[:25_000]
    parts.append(f"<inventory count=\"{len(files)}\">\n{inventory}\n</inventory>\n")
    budget -= len(inventory)

    readable = {f["path"]: f for f in files if not f.get("blocked")}

    # Extra PDF / DOCX documents besides the primary report (appendices, slides, design docs).
    extra_docs = [
        (p, f) for p, f in readable.items()
        if p != report and p.lower().endswith((".pdf", ".docx")) and f["size"] <= MAX_PDF_BYTES
    ]

    def _doc_priority(item: tuple[str, dict]) -> tuple[int, str]:
        low = item[0].lower()
        score = 0
        if any(k in low for k in ("report", "result", "appendix", "slide", "summary", "design", "analysis", "final", "problem", "method")):
            score -= 20
        if any(k in low for k in ("template", "rubric", "blank")):
            score += 20
        return (score, low)

    added_docs = 0
    for path, _f in sorted(extra_docs, key=_doc_priority):
        try:
            raw_doc = z.read(path)
            doc_txt = pdf_text(raw_doc, max_chars=12_000) if path.lower().endswith(".pdf") else docx_text(raw_doc)[:12_000]
        except Exception:  # noqa: BLE001
            doc_txt = ""
        if doc_txt and add(path, doc_txt):
            added_docs += 1
        if added_docs >= 4:
            break

    for path, f in readable.items():
        name = PurePosixPath(path).name.lower()
        if path == report:
            continue
        if ((name.startswith("readme") or name.endswith(".md")) and f["size"] < 40_000) or (
            name.endswith(".json") and f["size"] < 16_000 and any(k in name for k in ("config", "calibration", "result", "analysis", "metric"))
        ):
            add(path, z.read(path).decode("utf-8", "replace"))

    csv_paths = [p for p in readable if p.lower().endswith((".csv", ".tsv"))]

    def _csv_priority(p: str) -> tuple[int, str]:
        low = PurePosixPath(p).name.lower()
        score = 0 if any(k in low for k in ("summary", "result", "metric", "score", "run", "block", "calib", "eval")) else 10
        return (score, low)

    seen_headers: set[str] = set()
    added_csv = 0
    for path in sorted(csv_paths, key=_csv_priority):
        try:
            with z.open(path) as fh:
                first_line = fh.read(2048).decode("utf-8", "replace").splitlines()[0]
        except Exception:  # noqa: BLE001
            first_line = ""
        if first_line in seen_headers:
            continue
        seen_headers.add(first_line)
        summary = _summarize_csv(z, path)
        if summary and add(path, summary):
            added_csv += 1
        if added_csv >= 15:
            break

    code_paths = [p for p, f in readable.items() if f["kind"] == "code" and p.lower().endswith((".py", ".m")) and f["size"] < 200_000]
    runtime_paths = [p for p in code_paths if not _HELPER_CODE_RE.search(p)] or code_paths
    for path in runtime_paths:
        lines = z.read(path).decode("utf-8", "replace").splitlines()[:120]
        if not add(path, f"first {len(lines)} lines:\n" + "\n".join(lines)):
            break

    return "".join(parts)


def parse_json(text: str) -> dict:
    """The JSON object in a reply, tolerating <think> tags, code fences, or stray words."""
    t = re.sub(r"<think>.*?</think>", "", text, flags=re.S).strip()
    if t.startswith("```"):
        t = re.sub(r"^```[a-zA-Z]*\s*|\s*```$", "", t)
    start, end = t.find("{"), t.rfind("}")
    if start == -1 or end <= start:
        raise json.JSONDecodeError("no JSON object found", t, 0)
    data = json.loads(t[start:end + 1])
    if not isinstance(data, dict):
        raise json.JSONDecodeError("the reply is not a JSON object", t, 0)
    return data


def _trim_chars(text: str, limit: int, preserve_qmark: bool = False) -> str:
    s = re.sub(r"\s+", " ", text).strip()
    if len(s) <= limit:
        return s
    if preserve_qmark and s.endswith("?") and limit > 1:
        cap = limit - 1
        cut = s[:cap]
        if " " in cut:
            cut = cut.rsplit(" ", 1)[0]
        cut = cut.rstrip(" ,;:-.")
        return (cut or s[:cap].rstrip()) + "?"
    cut = s[:limit]
    if " " in cut:
        cut = cut.rsplit(" ", 1)[0]
    cut = cut.rstrip(" ,;:-")
    return cut or s[:limit]


def enforce_rules(data: dict) -> dict:
    data["schema_version"] = "0.2"

    def walk(node):
        if isinstance(node, dict):
            if {"value", "status", "source_ref"} <= node.keys():
                node["status"] = "extracted"
                if node["value"] is None:
                    node["source_ref"] = node.get("source_ref") or None
            for v in node.values():
                walk(v)
        elif isinstance(node, list):
            for v in node:
                walk(v)

    walk(data)
    meta = data.get("meta")
    if isinstance(meta, dict):
        q = meta.get("question")
        if isinstance(q, str):
            trimmed_q = _trim_chars(q, 90, preserve_qmark=True)
            meta["question"] = trimmed_q if trimmed_q else None
        hl = meta.get("headline")
        if hl is not None:
            if not isinstance(hl, dict) or not isinstance(hl.get("label"), str) or not hl["label"].strip():
                meta["headline"] = None
            else:
                hl["label"] = _trim_chars(hl["label"], 60)

    subsystems = data.get("subsystems")
    valid_sub_ids: set[str] = set()
    if isinstance(subsystems, list):
        for s in subsystems:
            if not isinstance(s, dict):
                continue
            sid = s.get("id")
            if isinstance(sid, str) and sid:
                valid_sub_ids.add(sid)
            if isinstance(s.get("name"), str):
                words = s["name"].split()
                if len(words) > 3:
                    s["name"] = " ".join(words[:3])
            if isinstance(s.get("plain"), str):
                p_words = s["plain"].split()
                if len(p_words) > 12:
                    s["plain"] = " ".join(p_words[:11]).rstrip(".,;:") + "."
            sim = s.get("sim")
            if not isinstance(sim, dict) or not sim.get("template"):
                s["sim"] = None
            else:
                sim["student_approved"] = True

    if valid_sub_ids:
        for df in (data.get("data_files") or []):
            if isinstance(df, dict) and df.get("subsystem") not in valid_sub_ids:
                df["subsystem"] = None
        results = data.get("results")
        if isinstance(results, dict):
            for m in (results.get("media") or []):
                if isinstance(m, dict) and m.get("part") not in valid_sub_ids:
                    m["part"] = None
        if isinstance(data.get("connections"), list):
            data["connections"] = [
                c for c in data["connections"]
                if isinstance(c, dict) and c.get("from") in valid_sub_ids and c.get("to") in valid_sub_ids
            ]
        for sr in (data.get("sensors") or []):
            if isinstance(sr, dict) and isinstance(sr.get("used_by"), list):
                sr["used_by"] = [u for u in sr["used_by"] if u in valid_sub_ids]

    return data


def read_secret_file(filename: str) -> str | None:
    places = [
        os.path.join(os.environ.get("LOCALAPPDATA", ""), "unfold-extractor", filename),
        os.path.join(os.path.dirname(os.path.abspath(__file__)), filename),
    ]
    for p in places:
        if p and os.path.isfile(p):
            with open(p, encoding="utf-8") as fh:
                t = fh.read().strip()
            if t:
                return t
    return None


def read_token_file() -> str | None:
    return read_secret_file("worker-token.txt")


def _tracked_null() -> dict:
    return {"value": None, "source_ref": None, "status": "extracted"}


_HELPER_CODE_RE = re.compile(r"(?:^|/)(?:make_|setup\.py$|__init__\.py$|.*submission.*\.py$)", re.I)

_SUBSYSTEM_RULES: list[tuple[str, str, str, str, list[str], tuple[str, ...]]] = [
    ("perception", "Perception & sensing", "Reads environment sensors and detects obstacles.", "perception", ["lidar"], ("lidar", "scan", "cam", "vision", "detect", "percept", "sensor")),
    ("localization", "State localization", "Estimates vehicle pose and map state.", "localization", ["imu"], ("localiz", "slam", "odom", "ekf", "kalman", "pose", "imu", "map")),
    ("planning", "Path planning", "Computes trajectories and driving decisions.", "planning", ["compute"], ("plan", "route", "logic", "path", "nav", "decision", "patch", "avoid", "traj")),
    ("control", "Vehicle control", "Drives speed, steering, and simulation execution.", "control", ["compute", "motor"], ("run", "ctrl", "control", "pid", "mpc", "steer", "speed", "motor", "sim", "common", "drive")),
    ("fleet_coordination", "Fleet coordination", "Coordinates tasks and multi-vehicle state.", "fleet_coordination", ["wifi"], ("fleet", "coord", "multi", "dispatch", "alloc", "sched", "actor")),
    ("communication", "Communication", "Exchanges messages across nodes.", "communication", ["wifi"], ("comm", "msg", "socket", "net", "bridge", "ros")),
    ("evaluation_analysis", "Data evaluation", "Evaluates metrics and logs experiment runs.", "other", ["compute"], ("analys", "score", "study", "summar", "eval", "metric", "io", "log")),
]


def _infer_param_unit(name: str) -> str | None:
    low = name.lower()
    if low.endswith(("_hz", "freq", "rate")):
        return "Hz"
    if low.endswith(("_mps", "speed", "vel")):
        return "m/s"
    if low.endswith(("_ms",)):
        return "ms"
    if low.endswith(("_sec", "_s", "_dt", "timeout", "horizon")):
        return "s"
    if low.endswith(("_deg", "angle")):
        return "deg"
    if low.endswith(("_m", "_dist", "_radius", "width", "length")):
        return "m"
    return None


def _parse_report_pages(z: zipfile.ZipFile, report: str | None) -> list[tuple[int, str]]:
    if not report:
        return []
    try:
        raw = z.read(report)
    except Exception:  # noqa: BLE001
        return []
    low = report.lower()
    if low.endswith(".docx"):
        txt = docx_text(raw)
        return [(1, txt)] if _is_clean_text_chunk(txt) else []
    if low.endswith(".pdf"):
        txt = pdf_text(raw, max_chars=80_000)
        if not txt:
            return []
        markers = list(re.finditer(r"\[Page (\d+)\]\n", txt))
        if not markers:
            return [(1, txt)]
        pages: list[tuple[int, str]] = []
        for idx, m in enumerate(markers):
            start = m.end()
            end = markers[idx + 1].start() if idx + 1 < len(markers) else len(txt)
            body = txt[start:end].strip()
            if body:
                pages.append((int(m.group(1)), body))
        return pages
    return []


def _pick_title(pages: list[tuple[int, str]], report: str | None) -> str:
    fallback = PurePosixPath(report).stem.replace("_", " ").replace("-", " ").strip() if report else "Engineering Project"
    if len(fallback) < 2:
        fallback = "Engineering Project"
    if pages:
        for ln in pages[0][1].splitlines()[:16]:
            s = re.sub(r"\s+", " ", ln).strip()
            if len(s) < 6 or len(s) > 110 or "|" in s:
                continue
            if re.match(r"^(?:page\s+\d+|table of contents|\d+[./-]\d+|figure\s+\d+|table\s+\d+|contents$|team and problem|member\s+\d|king abdulaziz)", s, re.I):
                continue
            if _is_clean_text_chunk(s) and len(re.findall(r"[A-Za-z]{3,}", s)) >= 2:
                return s
    return fallback


def _split_sections(pages: list[tuple[int, str]]) -> dict[str, tuple[str, int]]:
    heading_map = [
        ("abstract", re.compile(r"^(?:\d+(?:\.\d+)*\.?\s+)?(abstract|executive\s+summary|summary|overview)\b[:.\-\s]*$", re.I)),
        ("problem", re.compile(r"^(?:\d+(?:\.\d+)*\.?\s+)?(problem\s+statement|introduction|background|motivation|challenge)\b[:.\-\s]*$", re.I)),
        ("objectives", re.compile(r"^(?:\d+(?:\.\d+)*\.?\s+)?(objectives?|goals?|aims?|requirements?|design\s+requirements?)\b[:.\-\s]*$", re.I)),
        ("results", re.compile(r"^(?:\d+(?:\.\d+)*\.?\s+)?(results?|experimental\s+results?|evaluation|discussion|findings?)\b[:.\-\s]*$", re.I)),
        ("limitations", re.compile(r"^(?:\d+(?:\.\d+)*\.?\s+)?(limitations?|constraints?|known\s+issues?|shortcomings?)\b[:.\-\s]*$", re.I)),
        ("future_work", re.compile(r"^(?:\d+(?:\.\d+)*\.?\s+)?(future\s+work|recommendations?|next\s+steps|conclusions?\s*(?:&|and)\s*future\s+work)\b[:.\-\s]*$", re.I)),
    ]
    sections: dict[str, tuple[list[str], int]] = {}
    cur_key: str | None = None
    cur_page = 1
    for pno, body in pages:
        for raw_line in body.splitlines():
            line = re.sub(r"\s+", " ", raw_line).strip()
            if not line:
                continue
            matched = None
            if len(line) <= 70:
                for key, pat in heading_map:
                    if pat.match(line):
                        matched = key
                        break
                if not matched and re.match(r"^(?:\d+(?:\.\d+)*\.?\s+)?[A-Z][A-Za-z &/\-]{3,45}$", line) and not line.endswith("."):
                    cur_key = None
                    continue
            if matched:
                cur_key = matched
                cur_page = pno
                sections.setdefault(cur_key, ([], cur_page))
                continue
            if cur_key and _is_clean_text_chunk(line):
                sections[cur_key][0].append(line)
    return {k: ("\n".join(v[0]).strip(), v[1]) for k, v in sections.items() if v[0]}


def _first_clean_paragraph(pages: list[tuple[int, str]], max_chars: int = 420) -> str | None:
    paras: list[str] = []
    for _, body in pages[:4]:
        for line in body.splitlines():
            s = re.sub(r"\s+", " ", line).strip()
            if "|" in s or re.match(r"^(?:page\s+\d+|figure\s+\d+|table\s+\d+|\d+\s*$|team and problem|member\s+\d|king abdulaziz)", s, re.I):
                continue
            if len(s) >= 35 and _is_clean_text_chunk(s) and (s.endswith((".", "?", "!")) or len(s.split()) >= 7):
                paras.append(s)
            if sum(len(x) for x in paras) >= max_chars:
                break
        if len(paras) >= 2:
            break
    joined = re.sub(r"\s+", " ", " ".join(paras)).strip()
    if len(joined) > max_chars:
        cut = joined[:max_chars].rsplit(" ", 1)[0]
        joined = cut + "…"
    return joined or None


def _extract_bullets(text: str, limit: int = 6) -> list[str]:
    items: list[str] = []
    for raw in text.splitlines():
        s = re.sub(r"^(?:[-*•▪●]|\(?\d+[.)])\s*", "", raw.strip())
        s = re.sub(r"\s+", " ", s).strip()
        if 12 <= len(s) <= 220 and _is_clean_text_chunk(s):
            items.append(s)
        if len(items) >= limit:
            break
    if not items and text:
        for sent in re.split(r"(?<=[.!?])\s+", re.sub(r"\s+", " ", text)):
            s = sent.strip()
            if 15 <= len(s) <= 200 and _is_clean_text_chunk(s):
                items.append(s)
            if len(items) >= limit:
                break
    return items


def _inspect_code_subsystems(z: zipfile.ZipFile, files: list[dict]) -> tuple[list[dict], list[dict], list[str], dict[str, str]]:
    """Build AST-grounded subsystems, parameters, module connections, and software stack."""
    all_code = [f["path"] for f in files if not f.get("blocked") and f.get("kind") == "code"]
    runtime_code = [p for p in all_code if not _HELPER_CODE_RE.search(p)] or all_code
    stack_set: set[str] = set()
    code_texts: dict[str, str] = {}
    parsed_info: dict[str, dict] = {}

    for p in runtime_code:
        low = p.lower()
        if low.endswith(".py"):
            stack_set.add("python")
            try:
                src = z.read(p).decode("utf-8", "replace")
            except Exception:  # noqa: BLE001
                src = ""
            code_texts[p] = src
            info = {"doc": "", "params": [], "imports": set()}
            if len(src) <= 250_000 and src:
                try:
                    tree = ast.parse(src)
                    doc = ast.get_docstring(tree) or ""
                    if doc:
                        info["doc"] = re.sub(r"\s+", " ", doc.splitlines()[0]).strip()[:220]
                    for node in tree.body:
                        if isinstance(node, ast.Import):
                            for alias in node.names:
                                info["imports"].add(alias.name.split(".")[0])
                        elif isinstance(node, ast.ImportFrom) and node.module:
                            info["imports"].add(node.module.split(".")[0])
                        elif isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
                            name = node.targets[0].id
                            val_node = node.value
                            if not name.startswith("_") and isinstance(val_node, ast.Constant) and isinstance(val_node.value, (int, float)) and not isinstance(val_node.value, bool):
                                info["params"].append({
                                    "name": name,
                                    "value": float(val_node.value),
                                    "unit": _infer_param_unit(name),
                                    "source_ref": p,
                                    "status": "extracted",
                                })
                except SyntaxError:
                    pass
            if any(lib in info["imports"] for lib in ("rclpy", "rospy", "sensor_msgs", "geometry_msgs")):
                stack_set.add("ros2")
            parsed_info[p] = info
        elif low.endswith((".m", ".slx", ".mdl")):
            stack_set.add("matlab_simulink")
        elif low.endswith((".cpp", ".c", ".hpp", ".h", ".js", ".ts")):
            stack_set.add("other")

    buckets: dict[str, dict] = {}
    stem_to_sub: dict[str, str] = {}
    for p in runtime_code[:40]:
        stem = PurePosixPath(p).stem.lower()
        doc = (parsed_info.get(p) or {}).get("doc", "").lower()
        hay = f"{p.lower()} {doc}"
        chosen = ("main_system", "System Software", "Core project software pipeline.", "other", ["compute"])
        for sid, sname, splain, scat, son, keywords in _SUBSYSTEM_RULES:
            if any(k in hay for k in keywords):
                chosen = (sid, sname, splain, scat, son)
                break
        sid, sname, splain, scat, son = chosen
        if sid not in buckets:
            buckets[sid] = {
                "id": sid,
                "name": sname,
                "plain": splain,
                "on": list(son),
                "category": scat,
                "docs": [],
                "parameters": [],
                "code_files": [],
            }
        buckets[sid]["code_files"].append(p)
        if parsed_info.get(p, {}).get("doc"):
            buckets[sid]["docs"].append(parsed_info[p]["doc"])
        for prm in parsed_info.get(p, {}).get("params", []):
            if len(buckets[sid]["parameters"]) < 6 and prm["name"] not in {x["name"] for x in buckets[sid]["parameters"]}:
                buckets[sid]["parameters"].append(prm)
        stem_to_sub[stem] = sid

    if not buckets:
        buckets["main_system"] = {
            "id": "main_system",
            "name": "System Software",
            "plain": "Main project pipeline.",
            "on": [],
            "category": "other",
            "docs": [],
            "parameters": [],
            "code_files": runtime_code[:20],
        }

    subsystems: list[dict] = []
    for b in list(buckets.values())[:6]:
        desc = " ".join(b["docs"][:2]).strip()
        subsystems.append({
            "id": b["id"],
            "name": b["name"],
            "plain": b["plain"],
            "on": b["on"],
            "category": b["category"],
            "description": desc or f"Implements {b['name'].lower()} across {len(b['code_files'])} code file(s).",
            "inputs": [],
            "outputs": [],
            "method": desc or None,
            "equations": [],
            "parameters": b["parameters"],
            "rate_hz": _tracked_null(),
            "code_files": b["code_files"][:20],
            "sim": None,
        })

    valid_ids = {s["id"] for s in subsystems}
    connections: list[dict] = []
    seen_edges: set[tuple[str, str]] = set()
    for p, info in parsed_info.items():
        dst_sub = stem_to_sub.get(PurePosixPath(p).stem.lower())
        if not dst_sub or dst_sub not in valid_ids:
            continue
        for imp in sorted(info["imports"]):
            src_sub = stem_to_sub.get(imp.lower())
            if src_sub and src_sub in valid_ids and src_sub != dst_sub and (src_sub, dst_sub) not in seen_edges:
                seen_edges.add((src_sub, dst_sub))
                connections.append({"from": src_sub, "to": dst_sub, "signal": f"{imp} module data"})

    return subsystems, connections[:12], sorted(stack_set), code_texts


def _extract_sensors(pages: list[tuple[int, str]], subsystems: list[dict], full_code: str) -> list[dict]:
    sensor_specs = [
        ("lidar", re.compile(r"\blidar\b", re.I), "Range scanning and obstacle detection.", ("perception", "localization")),
        ("depth_camera", re.compile(r"\b(?:depth\s+camera|rgbd|realsense)\b", re.I), "Depth and visual environment sensing.", ("perception",)),
        ("rgb_camera", re.compile(r"\b(?:rgb\s+camera|csi\s+camera|camera)\b", re.I), "Visual lane and scene capture.", ("perception",)),
        ("imu", re.compile(r"\bimu\b|inertial\s+measurement", re.I), "Angular rate and acceleration estimation.", ("localization", "control")),
        ("wheel_encoder", re.compile(r"\b(?:wheel\s+encoder|encoders?|odometry)\b", re.I), "Wheel speed and distance measurement.", ("localization", "control")),
    ]
    sub_ids = [s["id"] for s in subsystems]
    sensors: list[dict] = []
    for stype, pat, default_purpose, pref_cats in sensor_specs:
        found_page: int | None = None
        snippet = ""
        for pno, body in pages:
            m = pat.search(body)
            if m:
                found_page = pno
                start = max(0, m.start() - 40)
                snippet = body[start:m.end() + 80]
                break
        if found_page is None and not pat.search(full_code):
            continue
        rate = _tracked_null()
        hm = re.search(r"\b(\d+(?:\.\d+)?)\s*Hz\b", snippet, re.I)
        if hm and found_page is not None:
            rate = {"value": float(hm.group(1)), "source_ref": f"p. {found_page}", "status": "extracted"}
        used = [s["id"] for s in subsystems if s["category"] in pref_cats or s["id"] in pref_cats]
        sensors.append({
            "type": stype,
            "purpose": default_purpose,
            "rate_hz": rate,
            "used_by": used or ([sub_ids[0]] if sub_ids else []),
        })
    return sensors


def _extract_metrics(pages: list[tuple[int, str]]) -> list[dict]:
    pat = re.compile(r"\b([A-Za-z][A-Za-z0-9 _/\-]{3,38}?)\s*[:=]\s*([-+]?\d+(?:\.\d+)?)\s*(%|ms|sec|s|m/s|cm|m|Hz|deg)?\b")
    skip = re.compile(r"^(?:page|figure|fig|table|section|step|team|problem|version|appendix|phase)\b", re.I)
    metrics: list[dict] = []
    seen: set[str] = set()
    for pno, body in pages:
        for m in pat.finditer(body):
            label = re.sub(r"\s+", " ", m.group(1)).strip(" -_:")
            if len(label) < 4 or skip.match(label) or not re.search(r"[A-Za-z]{3,}", label):
                continue
            val = float(m.group(2))
            if 1990 <= val <= 2035 and not m.group(3):
                continue
            key = label.lower()
            if key in seen:
                continue
            seen.add(key)
            metrics.append({
                "metric": label,
                "value": {"value": val, "source_ref": f"p. {pno}", "status": "extracted"},
                "unit": m.group(3) or None,
                "conditions": None,
            })
            if len(metrics) >= 8:
                return metrics
    return metrics


def local_rules_extract(zip_bytes: bytes, files: list[dict], report: str | None) -> tuple[dict, dict]:
    """Rich zero-AI local extractor producing schema-valid project structure from report & AST."""
    z = zipfile.ZipFile(io.BytesIO(zip_bytes))
    pages = _parse_report_pages(z, report)
    full_text = "\n\n".join(body for _, body in pages)
    title = _pick_title(pages, report)
    sections = _split_sections(pages)

    short_desc = None
    if "abstract" in sections:
        txt = re.sub(r"\s+", " ", sections["abstract"][0]).strip()
        short_desc = (txt[:417].rsplit(" ", 1)[0] + "…") if len(txt) > 420 else txt
    if not short_desc:
        short_desc = _first_clean_paragraph(pages, max_chars=420)

    prob_stmt = None
    if "problem" in sections:
        txt = re.sub(r"\s+", " ", sections["problem"][0]).strip()
        prob_stmt = (txt[:697].rsplit(" ", 1)[0] + "…") if len(txt) > 700 else txt
    elif short_desc:
        prob_stmt = short_desc

    objectives = _extract_bullets(sections["objectives"][0], limit=6) if "objectives" in sections else []
    limitations = _extract_bullets(sections["limitations"][0], limit=5) if "limitations" in sections else []
    future_work = _extract_bullets(sections["future_work"][0], limit=5) if "future_work" in sections else []

    subsystems, connections, software_stack, code_texts = _inspect_code_subsystems(z, files)
    full_code = "\n".join(code_texts.values())
    combined = f"{full_text}\n{full_code}"

    # Platform & environment signals.
    has_qlabs = bool(re.search(r"\bqlabs\b|\bsimulat", combined, re.I))
    has_phys = bool(re.search(r"\bphysical\s+qcar|\breal\s+qcar|\bphysical\s+track\b", combined, re.I))
    plat_mode = "both" if (has_qlabs and has_phys) else ("qlabs_sim" if has_qlabs else ("physical_qcar2" if has_phys else None))

    veh_count = _tracked_null()
    for pno, body in pages:
        vm = re.search(r"\b([1-9]\d?)\s+(?:qcars?|vehicles?|robots?|actors?)\b", body, re.I)
        if vm:
            n_val = int(vm.group(1))
            if 1 <= n_val <= 20:
                veh_count = {"value": n_val, "source_ref": f"p. {pno}", "status": "extracted"}
                break

    has_dyn = bool(re.search(r"\bdynamic\s+obstacles?\b", full_text, re.I))
    has_stat = bool(re.search(r"\bstatic\s+obstacles?\b", full_text, re.I))
    obstacles = "both" if (has_dyn and has_stat) else ("dynamic" if has_dyn else ("static" if has_stat else None))
    map_source = "qlabs_builtin" if has_qlabs else ("physical_track" if has_phys else None)

    kw_candidates = [
        ("LiDAR", r"\blidar\b"),
        ("Autonomous Vehicle", r"\bautonomous\b"),
        ("Obstacle Avoidance", r"\bobstacle\s+avoid"),
        ("Path Planning", r"\bpath\s+plan|\broute\b"),
        ("QCar 2", r"\bqcar"),
        ("QLabs", r"\bqlabs\b"),
        ("ROS 2", r"\bros\s*2\b|\brclpy\b"),
        ("Simulation", r"\bsimulat"),
    ]
    keywords = [label for label, pat in kw_candidates if re.search(pat, combined, re.I)][:8]

    sensors = _extract_sensors(pages, subsystems, full_code)
    metrics = _extract_metrics(pages)

    # Prioritize summary/calibration/results CSVs and always satisfy schema format="csv".
    csv_candidates = [f for f in files if not f.get("blocked") and f.get("kind") == "data" and f["path"].lower().endswith(".csv")]

    def csv_rank(item: dict) -> tuple[int, str]:
        low = PurePosixPath(item["path"]).name.lower()
        score = 0
        if any(k in low for k in ("summary", "result", "metric", "score", "run", "block", "calib", "eval")):
            score -= 20
        if "frame" in low or "raw" in low:
            score += 10
        return (score, low)

    default_sub = subsystems[0]["id"]
    data_files = []
    for f in sorted(csv_candidates, key=csv_rank):
        cols = []
        try:
            with z.open(f["path"]) as fh:
                first = fh.read(2048).decode("utf-8", "replace").splitlines()[0]
                for c in [x.strip().strip('"') for x in first.split(",") if x.strip()][:6]:
                    cols.append({"name": c, "unit": _infer_param_unit(c)})
        except Exception:  # noqa: BLE001
            pass
        low_name = PurePosixPath(f["path"]).name.lower()
        sub_match = next((s["id"] for s in subsystems if s["id"].split("_")[0] in low_name), default_sub)
        data_files.append({
            "file": PurePosixPath(f["path"]).name,
            "description": None,
            "subsystem": sub_match,
            "format": "csv",
            "sample_rate_hz": _tracked_null(),
            "columns": cols,
        })
        if len(data_files) >= 10:
            break

    fig_captions: list[str] = [
        re.sub(r"\s+", " ", m.group(1)).strip()
        for m in re.finditer(r"(?im)^fig(?:ure)?\.?\s*\d+[:.\-\s]+([^\n]{6,180})", full_text)
        if _is_clean_text_chunk(m.group(1))
    ]
    media_candidates = [
        f for f in files
        if not f.get("blocked") and (f.get("kind") in ("image", "video") or f["path"].lower().endswith((".png", ".jpg", ".jpeg", ".webp", ".gif", ".mp4", ".webm", ".mov")))
    ]

    def media_rank(item: dict) -> tuple[int, str]:
        low = PurePosixPath(item["path"]).name.lower()
        score = 0
        if any(k in low for k in ("panel", "calib", "summary", "demo", "fig", "plot", "result", "overview", "g1", "g2")):
            score -= 20
        if "frame" in low or re.search(r"_\d{4,}\.", low):
            score += 20
        return (score, low)

    media = []
    for idx, f in enumerate(sorted(media_candidates, key=media_rank)):
        base = PurePosixPath(f["path"]).name
        low_base = base.lower()
        stem_caption = PurePosixPath(base).stem.replace("_", " ").replace("-", " ").strip().title()
        caption = fig_captions[idx] if idx < len(fig_captions) else stem_caption
        part = next((s["id"] for s in subsystems if s["id"].split("_")[0] in low_base), None)
        media.append({
            "file": base,
            "caption": caption or None,
            "part": part,
        })
        if len(media) >= 15:
            break

    data = {
        "schema_version": "0.2",
        "meta": {
            "title": title,
            "question": None,
            "headline": None,
            "short_description": short_desc or "Extracted locally without an LLM. Review and complete any missing details below.",
            "platform": {
                "mode": plat_mode,
                "vehicle_count": veh_count,
                "software_stack": software_stack,
            },
            "keywords": keywords,
        },
        "goal": {
            "problem_statement": prob_stmt,
            "objectives": objectives,
            "success_criteria": [],
        },
        "environment": {
            "scenario": None,
            "map_source": map_source,
            "map_file": None,
            "obstacles": obstacles,
            "other_agents": None,
        },
        "sensors": sensors,
        "subsystems": subsystems,
        "connections": connections,
        "data_files": data_files,
        "results": {
            "metrics": metrics,
            "media": media,
            "limitations": limitations,
            "future_work": future_work,
        },
    }
    meta = {"model": "local_rules", "usage": {"input_tokens": 0, "output_tokens": 0, "cache_read_input_tokens": 0}}
    return enforce_rules(data), meta


class LLMClient:
    """Unified client supporting free Gemini, local Ollama, OpenAI-compatible endpoints, local rules, or optional Anthropic."""

    def __init__(self, provider: str | None = None, model: str | None = None, api_key: str | None = None, base_url: str | None = None):
        self.provider = (provider or os.environ.get("UNFOLD_AI_PROVIDER") or self.detect_provider()).strip().lower()
        self.api_key = (api_key or "").strip() or None
        self.base_url = (base_url or "").strip().rstrip("/") or None
        self.model = self._resolve_model(model)

    @staticmethod
    def detect_provider() -> str:
        if os.environ.get("GEMINI_API_KEY") or read_secret_file("gemini-key.txt"):
            return "gemini"
        if os.environ.get("UNFOLD_AI_API_KEY") or os.environ.get("UNFOLD_AI_BASE_URL"):
            return "openai_compat"
        ollama_url = os.environ.get("UNFOLD_OLLAMA_URL", DEFAULT_OLLAMA_URL).rstrip("/")
        try:
            r = requests.get(f"{ollama_url}/api/tags", timeout=1)
            if r.ok:
                return "ollama"
        except requests.RequestException:
            pass
        if os.environ.get("ANTHROPIC_API_KEY") or read_secret_file("anthropic-key.txt"):
            try:
                import anthropic  # noqa: F401
                return "anthropic"
            except ImportError:
                pass
        return "local_rules"

    def _resolve_model(self, model_override: str | None = None) -> str:
        override = (model_override or "").strip() or os.environ.get("UNFOLD_AI_MODEL")
        if override:
            return override
        if self.provider == "gemini":
            return DEFAULT_GEMINI_MODEL
        if self.provider == "ollama":
            return DEFAULT_OLLAMA_MODEL
        if self.provider == "openai_compat":
            return DEFAULT_OPENAI_MODEL
        if self.provider == "anthropic":
            return DEFAULT_ANTHROPIC_MODEL
        return "local_rules"

    def _gemini_json(self, system: str, parts: list[dict], max_tokens: int, on_progress=None, timeout: int = 120) -> tuple[dict, dict]:
        key = self.api_key or os.environ.get("GEMINI_API_KEY") or read_secret_file("gemini-key.txt")
        if not key:
            raise RuntimeError("Missing GEMINI_API_KEY (or gemini-key.txt). Get a free key from Google AI Studio.")
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{self.model}:generateContent"
        g_parts: list[dict] = []
        for p in parts:
            if p["type"] == "pdf":
                g_parts.append({"inline_data": {"mime_type": "application/pdf", "data": base64.standard_b64encode(p["bytes"]).decode()}})
            else:
                g_parts.append({"text": p["text"]})

        messages = [{"role": "user", "parts": g_parts}]
        usage = {"input_tokens": 0, "output_tokens": 0, "cache_read_input_tokens": 0}
        for attempt in (1, 2):
            if on_progress:
                detail = "Sending PDF & files…" if attempt == 1 else "Repairing JSON formatting…"
                on_progress("ai", f"AI reading with {self.model}…", 45 if attempt == 1 else 75, detail, 0)
            payload = {
                "system_instruction": {"parts": [{"text": system}]},
                "contents": messages,
                "generationConfig": {
                    "responseMimeType": "application/json",
                    "maxOutputTokens": max_tokens,
                    "temperature": 0.0,
                },
            }
            r = None
            last_req_err = None
            for try_idx in range(3):
                try:
                    r = requests.post(url, headers={"x-goog-api-key": key}, json=payload, timeout=timeout)
                    last_req_err = None
                except (requests.Timeout, requests.ConnectionError) as exc:
                    last_req_err = exc
                    r = None
                    log.warning("gemini request error (%s), retrying...", type(exc).__name__)
                    if try_idx < 2:
                        if on_progress:
                            on_progress("ai", f"Waiting for {self.model}…", 50, "Connection timed out, retrying…", 0)
                        time.sleep(3 * (try_idx + 1))
                        continue
                    raise
                if r.status_code not in (500, 502, 503, 504) or try_idx == 2:
                    break
                log.warning("gemini returned HTTP %s, retrying...", r.status_code)
                if on_progress:
                    on_progress("ai", f"Waiting for {self.model}…", 50, f"Temporary HTTP {r.status_code}, retrying…", 0)
                time.sleep(3 * (try_idx + 1))
            if last_req_err is not None:
                raise last_req_err
            if r.status_code in (401, 403):
                raise RuntimeError("Gemini API key is invalid or unauthorized. Check GEMINI_API_KEY.")
            if r.status_code == 429:
                raise RuntimeError("Gemini free-tier rate limit reached. Try again in a minute.")
            r.raise_for_status()
            body = r.json()
            um = body.get("usageMetadata") or {}
            usage["input_tokens"] += int(um.get("promptTokenCount") or 0)
            usage["output_tokens"] += int(um.get("candidatesTokenCount") or 0)
            cands = body.get("candidates") or []
            if not cands:
                raise RuntimeError("Gemini returned no candidates.")
            text = "".join(pt.get("text", "") for pt in (cands[0].get("content", {}).get("parts") or []))
            try:
                return parse_json(text), {"model": self.model, "usage": usage}
            except json.JSONDecodeError as e:
                if attempt == 2:
                    raise RuntimeError(f"Gemini returned invalid JSON ({e.msg}).")
                messages = messages + [
                    {"role": "model", "parts": [{"text": text}]},
                    {"role": "user", "parts": [{"text": f"Invalid JSON ({e.msg}). Reply with the complete corrected JSON object only."}]},
                ]
        raise RuntimeError("unreachable")

    def _ollama_json(self, system: str, parts: list[dict], on_progress=None) -> tuple[dict, dict]:
        base = (self.base_url or os.environ.get("UNFOLD_OLLAMA_URL", DEFAULT_OLLAMA_URL)).rstrip("/")
        thinking = _is_thinking_model(self.model)
        report_cap = 16_000 if thinking else 5_500
        context_cap = 24_000 if thinking else 5_500
        total_cap = 40_000 if thinking else 11_500
        text_chunks = []
        for p in parts:
            if p["type"] == "pdf":
                text_chunks.append(f'<report name="{p["title"]}">\n{pdf_text(p["bytes"], max_chars=report_cap)}\n</report>')
            else:
                cap = report_cap if p["text"].lstrip().startswith("<report ") else context_cap
                text_chunks.append(p["text"][:cap])
        user_text = "\n\n".join(text_chunks)[:total_cap]
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": user_text},
        ]
        num_ctx = int(os.environ.get("UNFOLD_OLLAMA_NUM_CTX", "16384" if thinking else "8192"))
        num_predict = int(os.environ.get("UNFOLD_OLLAMA_NUM_PREDICT", "8192" if thinking else "2048"))
        for attempt in (1, 2):
            if on_progress:
                on_progress("ai", f"AI reading with {self.model}…", 42 if attempt == 1 else 78, "Loading model & prompt…" if attempt == 1 else "Repairing JSON…", 0)
            payload = {
                "model": self.model,
                "messages": messages,
                "format": "json",
                "stream": True,
                "options": {"num_ctx": num_ctx, "num_predict": num_predict, "temperature": 0.1},
            }
            if thinking:
                payload["think"] = True
            r = requests.post(f"{base}/api/chat", json=payload, stream=True, timeout=(15, 300))
            if r.status_code == 400 and payload.get("think") is True and "think" in (r.text or "").lower():
                payload.pop("think", None)
                r = requests.post(f"{base}/api/chat", json=payload, stream=True, timeout=(15, 300))
            r.raise_for_status()
            chunks: list[str] = []
            thinking_chars = 0
            out_chars = 0
            prompt_tokens = 0
            eval_tokens = 0
            last_report = time.monotonic()
            for raw_line in r.iter_lines(decode_unicode=True):
                if not raw_line:
                    continue
                chunk = json.loads(raw_line)
                msg = chunk.get("message") or {}
                think_piece = msg.get("thinking") or ""
                if think_piece:
                    thinking_chars += len(think_piece)
                piece = msg.get("content") or ""
                if piece:
                    chunks.append(piece)
                    out_chars += len(piece)
                if on_progress and (thinking_chars + out_chars) > 0 and (time.monotonic() - last_report >= 2.5):
                    last_report = time.monotonic()
                    total_ch = thinking_chars + out_chars
                    phase = "Reasoning step-by-step…" if thinking_chars > 0 and out_chars == 0 else "Writing structured fields…"
                    pct = min(84, 45 + int(total_ch / 100))
                    on_progress("ai", f"Extracting with {self.model}…", pct, phase, total_ch)
                if chunk.get("done"):
                    prompt_tokens = int(chunk.get("prompt_eval_count") or 0)
                    eval_tokens = int(chunk.get("eval_count") or 0)
            if thinking_chars > 0:
                log.info("ollama thinking: %d chars (%s)", thinking_chars, self.model)
            text = "".join(chunks)
            try:
                usage = {
                    "input_tokens": prompt_tokens,
                    "output_tokens": eval_tokens,
                    "cache_read_input_tokens": 0,
                }
                return parse_json(text), {"model": self.model, "usage": usage}
            except json.JSONDecodeError as e:
                if attempt == 2:
                    raise RuntimeError(f"Ollama returned invalid JSON ({e.msg}).")
                messages = messages + [
                    {"role": "assistant", "content": text},
                    {"role": "user", "content": f"Invalid JSON ({e.msg}). Reply with the corrected JSON object only."},
                ]
        raise RuntimeError("unreachable")

    def _openai_compat_json(self, system: str, parts: list[dict], max_tokens: int) -> tuple[dict, dict]:
        base = (self.base_url or os.environ.get("UNFOLD_AI_BASE_URL") or "https://api.groq.com/openai/v1").rstrip("/")
        key = self.api_key or os.environ.get("UNFOLD_AI_API_KEY") or read_secret_file("ai-key.txt") or ""
        text_chunks = []
        for p in parts:
            if p["type"] == "pdf":
                text_chunks.append(f'<report name="{p["title"]}">\n{pdf_text(p["bytes"])}\n</report>')
            else:
                text_chunks.append(p["text"])
        headers = {"Content-Type": "application/json"}
        if key:
            headers["Authorization"] = f"Bearer {key}"
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": "\n\n".join(text_chunks)},
        ]
        for attempt in (1, 2):
            r = requests.post(
                f"{base}/chat/completions",
                headers=headers,
                json={"model": self.model, "messages": messages, "max_tokens": max_tokens, "temperature": 0.1, "response_format": {"type": "json_object"}},
                timeout=180,
            )
            r.raise_for_status()
            body = r.json()
            text = ((body.get("choices") or [{}])[0].get("message") or {}).get("content") or ""
            try:
                u = body.get("usage") or {}
                usage = {"input_tokens": int(u.get("prompt_tokens") or 0), "output_tokens": int(u.get("completion_tokens") or 0), "cache_read_input_tokens": 0}
                return parse_json(text), {"model": self.model, "usage": usage}
            except json.JSONDecodeError as e:
                if attempt == 2:
                    raise RuntimeError(f"AI endpoint returned invalid JSON ({e.msg}).")
                messages = messages + [
                    {"role": "assistant", "content": text},
                    {"role": "user", "content": f"Invalid JSON ({e.msg}). Reply with the corrected JSON object only."},
                ]
        raise RuntimeError("unreachable")

    def _anthropic_json(self, system: str, parts: list[dict], max_tokens: int) -> tuple[dict, dict]:
        import anthropic  # optional legacy fallback
        key = self.api_key or os.environ.get("ANTHROPIC_API_KEY") or read_secret_file("anthropic-key.txt")
        client = anthropic.Anthropic(max_retries=3, api_key=key) if key else anthropic.Anthropic(max_retries=3)
        content = []
        for p in parts:
            if p["type"] == "pdf":
                content.append({"type": "document", "source": {"type": "base64", "media_type": "application/pdf", "data": base64.standard_b64encode(p["bytes"]).decode()}, "title": p["title"]})
            else:
                content.append({"type": "text", "text": p["text"]})
        messages = [{"role": "user", "content": content}]
        for attempt in (1, 2):
            msg = client.messages.create(model=self.model, max_tokens=max_tokens, system=system, messages=messages)
            text = "".join(b.text for b in msg.content if b.type == "text")
            try:
                u = msg.usage
                usage = {"input_tokens": getattr(u, "input_tokens", 0) or 0, "output_tokens": getattr(u, "output_tokens", 0) or 0, "cache_read_input_tokens": 0}
                return parse_json(text), {"model": msg.model, "usage": usage}
            except json.JSONDecodeError as e:
                if attempt == 2:
                    raise RuntimeError(f"Model returned invalid JSON ({e.msg}).")
                messages = messages + [{"role": "assistant", "content": text}, {"role": "user", "content": f"Invalid JSON ({e.msg}). Reply with corrected JSON only."}]
        raise RuntimeError("unreachable")

    def ask_json(self, system: str, parts: list[dict], max_tokens: int = 32768, on_progress=None, timeout: int | None = None) -> tuple[dict, dict]:
        if self.provider == "gemini":
            return self._gemini_json(system, parts, max_tokens, on_progress=on_progress, timeout=timeout or 120)
        if self.provider == "ollama":
            return self._ollama_json(system, parts, on_progress=on_progress)
        if self.provider == "openai_compat":
            if on_progress:
                on_progress("ai", f"AI reading with {self.model}…", 45, "Waiting for endpoint response…", 0)
            return self._openai_compat_json(system, parts, max_tokens)
        if self.provider == "anthropic":
            if on_progress:
                on_progress("ai", f"AI reading with {self.model}…", 45, "Sending PDF to Claude…", 0)
            return self._anthropic_json(system, parts, max_tokens)
        raise RuntimeError(f"Unsupported provider: {self.provider}")


def build_parts(zip_bytes: bytes, files: list[dict], report: str | None) -> list[dict]:
    z = zipfile.ZipFile(io.BytesIO(zip_bytes))
    if not report:
        raise ValueError("No report (PDF or Word) was found in the ZIP.")
    data = z.read(report)
    low = report.lower()
    parts: list[dict] = []
    if low.endswith(".pdf"):
        if len(data) > MAX_PDF_BYTES:
            raise ValueError(f"The report PDF is {human_size(len(data))}; the limit is 30 MB. Upload a smaller PDF or a .docx.")
        parts.append({"type": "pdf", "bytes": data, "title": report})
        report_note = f"The team report is the attached PDF ({report})."
    elif low.endswith(".docx"):
        parts.append({"type": "text", "text": f'<report name="{report}">\n{docx_text(data)}\n</report>'})
        report_note = f"The team report ({report}) is given as text above (tables flattened as rows separated by |)."
    else:
        raise ValueError("The report must be a PDF or a .docx file (old .doc is not supported).")

    context = build_context(z, files, report)
    parts.append({
        "type": "text",
        "text": (
            f"{report_note}\n\nOther files in the team's ZIP (inventory first, then excerpts of the most informative small files):\n\n"
            f"{context}\n\n"
            "Fill in the Unfold project description for this project. Remember: anything not stated is null, "
            "every tracked status is \"extracted\", and source_ref points to where the value is stated."
        ),
    })
    return parts


_OLLAMA_COMPACT_TEMPLATE = """Answer with ONE valid JSON object using ONLY these top-level keys:
{
  "schema_version": "0.2",
  "meta": {
    "title": null,
    "question": null,
    "headline": null,
    "short_description": null,
    "platform": {"mode": null, "vehicle_count": {"value": null, "source_ref": null, "status": "extracted"}, "software_stack": []},
    "keywords": []
  },
  "goal": {
    "problem_statement": null,
    "objectives": [],
    "success_criteria": []
  },
  "environment": {"scenario": null, "map_source": null, "map_file": null, "obstacles": null, "other_agents": null},
  "sensors": [],
  "subsystems": [],
  "connections": [],
  "data_files": [],
  "results": {
    "metrics": [],
    "media": [],
    "limitations": [],
    "future_work": []
  }
}
Important rules:
- NEVER invent filenames or copy example text. Only use exact filenames that appear in the ZIP file inventory below; if none match, use [].
- Valid enum values (or null when unstated):
  - meta.platform.mode: "qlabs_sim", "physical_qcar2", "both", or null
  - meta.platform.software_stack items: "python", "matlab_simulink", "ros2", "other"
  - environment.map_source: "qlabs_builtin", "custom", "physical_track", "other", or null
  - environment.obstacles: "static", "dynamic", "both", "none", or null
  - sensors items: {"type": "lidar|rgb_camera|depth_camera|imu|wheel_encoder|other", "purpose": null, "rate_hz": {"value": null, "source_ref": null, "status": "extracted"}, "used_by": []}
  - subsystems items: {"id": "lowercase_id", "name": "1-3 Words", "plain": "Under 9 words.", "on": [], "category": "perception|localization|planning|control|task_allocation|fleet_coordination|communication|other", "description": null, "inputs": [], "outputs": [], "method": null, "equations": [], "parameters": [], "rate_hz": {"value": null, "source_ref": null, "status": "extracted"}, "code_files": [], "sim": null}
  - connections items: {"from": "sub_id", "to": "sub_id", "signal": null}
  - data_files items: {"file": "<exact file from inventory>", "description": null, "subsystem": null, "format": "csv", "sample_rate_hz": {"value": null, "source_ref": null, "status": "extracted"}, "columns": [{"name": "<exact header>", "unit": null}]}
  - results.metrics items: {"metric": "<name>", "value": {"value": 0, "source_ref": null, "status": "extracted"}, "unit": null, "conditions": null}
  - results.media items: {"file": "<exact image/video from inventory>", "caption": null, "part": null}
- subsystem "name" MUST be 1-3 words; "plain" MUST be 1 short sentence under 10 words; "id" must match ^[a-z][a-z0-9_]*$."""

_VALID_ROOT_KEYS = {"schema_version", "meta", "goal", "environment", "sensors", "subsystems", "connections", "data_files", "results"}
_VALID_CATEGORIES = {"perception", "localization", "planning", "control", "task_allocation", "fleet_coordination", "communication", "other"}
_VALID_FORMATS = {"csv", "mat", "bag", "json", "mp4", "other"}
_VALID_ON = {"chassis", "wheels", "servo", "motor", "encoder", "battery", "board", "imu", "compute", "lidar", "depth", "csi", "lights", "wifi"}
_VALID_SENSORS = {"lidar", "rgb_camera", "depth_camera", "imu", "wheel_encoder", "other"}
_VALID_MODES = {"qlabs_sim", "physical_qcar2", "both", None}
_VALID_STACKS = {"python", "matlab_simulink", "ros2", "other"}
_VALID_MAP_SOURCES = {"qlabs_builtin", "custom", "physical_track", "other", None}
_VALID_OBSTACLES = {"static", "dynamic", "both", "none", None}
_PLACEHOLDER_LITERALS = {"can software fix bent wheels?", "algorithm or null", "caption", "..."}
_PLACEHOLDER_RE = re.compile(r"^(?:limitation|future improvement|objective|metric|caption|short project title)\s*\d*$", re.I)


def _is_placeholder_text(val) -> bool:
    if not isinstance(val, str):
        return False
    text = val.strip()
    if not text:
        return True
    low = text.lower()
    return low in _PLACEHOLDER_LITERALS or bool(_PLACEHOLDER_RE.match(low))


def _build_valid_file_maps(skeleton: dict, files: list[dict] | None) -> tuple[dict[str, str], dict[str, str]]:
    """Return lowercase-to-real lookups for ZIP basenames and exact paths."""
    by_basename: dict[str, str] = {}
    by_path: dict[str, str] = {}
    if files is not None:
        for item in files:
            if item.get("blocked"):
                continue
            path = str(item.get("path") or "").strip()
            if not path:
                continue
            base = PurePosixPath(path).name
            by_basename.setdefault(path.lower(), base)
            by_basename.setdefault(base.lower(), base)
            by_path.setdefault(path.lower(), path)
            by_path.setdefault(base.lower(), path)
        return by_basename, by_path

    for df in skeleton.get("data_files") or []:
        f = str(df.get("file") or "").strip()
        if f:
            by_basename.setdefault(f.lower(), PurePosixPath(f).name)
    for m in (skeleton.get("results") or {}).get("media") or []:
        f = str(m.get("file") or "").strip()
        if f:
            by_basename.setdefault(f.lower(), PurePosixPath(f).name)
    for s in skeleton.get("subsystems") or []:
        for c in s.get("code_files") or []:
            c_str = str(c).strip()
            if c_str:
                base = PurePosixPath(c_str).name
                by_path.setdefault(c_str.lower(), c_str)
                by_path.setdefault(base.lower(), c_str)
    return by_basename, by_path


def _resolve_valid_file(raw_name, lookup: dict[str, str]) -> str | None:
    if not isinstance(raw_name, str) or not raw_name.strip():
        return None
    cleaned = raw_name.strip().replace("\\", "/")
    if cleaned.lower() in lookup:
        return lookup[cleaned.lower()]
    base = PurePosixPath(cleaned).name.lower()
    return lookup.get(base)


def _deep_merge_prefer_ai(ai_val, skel_val):
    """Merge dicts preferring non-empty AI values while guaranteeing every required skeleton key exists."""
    if isinstance(skel_val, dict):
        if not isinstance(ai_val, dict):
            return copy.deepcopy(skel_val)
        if {"value", "status", "source_ref"} <= skel_val.keys():
            val = ai_val.get("value")
            if val is None or isinstance(val, bool) or not isinstance(val, (int, float)):
                return copy.deepcopy(skel_val)
            return {
                "value": val,
                "source_ref": ai_val.get("source_ref") if isinstance(ai_val.get("source_ref"), str) else skel_val.get("source_ref"),
                "status": "extracted",
            }
        out = {}
        for k, sv in skel_val.items():
            out[k] = _deep_merge_prefer_ai(ai_val.get(k), sv)
        return out
    if isinstance(skel_val, list):
        return ai_val if isinstance(ai_val, list) and len(ai_val) > 0 else copy.deepcopy(skel_val)
    if ai_val is not None and ai_val != "":
        return ai_val
    return copy.deepcopy(skel_val)


def merge_with_skeleton(ai_data: dict, skeleton: dict, files: list[dict] | None = None) -> dict:
    """Normalize AI output against schema-valid skeleton and real ZIP files."""
    if not isinstance(ai_data, dict):
        return copy.deepcopy(skeleton)
    cleaned = {k: v for k, v in ai_data.items() if k in _VALID_ROOT_KEYS}
    valid_by_base, valid_by_path = _build_valid_file_maps(skeleton, files)

    # Rescue fields if a tiny model nested them under a hallucinated wrapper like project_description.
    if "meta" not in cleaned and isinstance(ai_data.get("project_description"), dict):
        pd = ai_data["project_description"]
        cleaned["meta"] = {
            "title": pd.get("project_name") or pd.get("title"),
            "short_description": pd.get("project_description") or pd.get("description"),
        }

    merged: dict = {
        "schema_version": "0.2",
        "meta": _deep_merge_prefer_ai(cleaned.get("meta"), skeleton["meta"]),
        "goal": _deep_merge_prefer_ai(cleaned.get("goal"), skeleton["goal"]),
        "environment": _deep_merge_prefer_ai(cleaned.get("environment"), skeleton["environment"]),
    }
    if _is_placeholder_text(merged["meta"].get("question")):
        merged["meta"]["question"] = skeleton["meta"].get("question")
    if _is_placeholder_text(merged["meta"].get("title")):
        merged["meta"]["title"] = skeleton["meta"].get("title")
    if _is_placeholder_text(merged["meta"].get("short_description")):
        merged["meta"]["short_description"] = skeleton["meta"].get("short_description")

    objectives = [o for o in (merged["goal"].get("objectives") or []) if isinstance(o, str) and not _is_placeholder_text(o)]
    merged["goal"]["objectives"] = objectives if objectives else copy.deepcopy(skeleton["goal"].get("objectives") or [])

    plat = merged["meta"].get("platform") or {}
    if plat.get("mode") not in _VALID_MODES:
        plat["mode"] = skeleton["meta"]["platform"]["mode"]
    stack = [x for x in (plat.get("software_stack") or []) if x in _VALID_STACKS]
    plat["software_stack"] = stack if stack else copy.deepcopy(skeleton["meta"]["platform"]["software_stack"])
    if merged["environment"].get("map_source") not in _VALID_MAP_SOURCES:
        merged["environment"]["map_source"] = skeleton["environment"]["map_source"]
    if merged["environment"].get("obstacles") not in _VALID_OBSTACLES:
        merged["environment"]["obstacles"] = skeleton["environment"]["obstacles"]

    # Normalize subsystems and backfill AST details from skeleton.
    skel_subs = copy.deepcopy(skeleton.get("subsystems") or [])
    skel_by_id = {s["id"]: s for s in skel_subs}
    ai_subs = cleaned.get("subsystems")
    norm_subs: list[dict] = []
    if isinstance(ai_subs, list):
        used_ids: set[str] = set()
        for raw_s in ai_subs:
            if not isinstance(raw_s, dict) or not str(raw_s.get("name") or "").strip():
                continue
            sid = re.sub(r"[^a-z0-9_]", "_", str(raw_s.get("id") or raw_s.get("name") or "part").lower()).strip("_") or "part"
            if not sid[0].isalpha():
                sid = "p_" + sid
            if sid in used_ids:
                continue
            used_ids.add(sid)
            words = str(raw_s["name"]).strip().split()
            name = " ".join(words[:3]) if len(words) > 3 else str(raw_s["name"]).strip()
            plain = str(raw_s.get("plain") or raw_s.get("description") or f"{name} module.").strip()
            p_words = plain.split()
            if len(p_words) > 9:
                plain = " ".join(p_words[:8]).rstrip(".,;:") + "."
            cat = raw_s.get("category") if raw_s.get("category") in _VALID_CATEGORIES else "other"
            on_hw = [x for x in (raw_s.get("on") or []) if x in _VALID_ON]
            method = raw_s.get("method") if isinstance(raw_s.get("method"), str) and not _is_placeholder_text(raw_s.get("method")) else None
            raw_code = [c for c in (raw_s.get("code_files") or []) if isinstance(c, str) and c.strip()]
            if valid_by_path:
                code_files = [resolved for c in raw_code if (resolved := _resolve_valid_file(c, valid_by_path)) is not None]
            else:
                code_files = raw_code

            sub_obj = {
                "id": sid,
                "name": name,
                "plain": plain,
                "on": on_hw,
                "category": cat,
                "description": raw_s.get("description") or plain,
                "inputs": raw_s.get("inputs") if isinstance(raw_s.get("inputs"), list) else [],
                "outputs": raw_s.get("outputs") if isinstance(raw_s.get("outputs"), list) else [],
                "method": method,
                "equations": [e for e in (raw_s.get("equations") or []) if isinstance(e, str) and e.strip()],
                "parameters": raw_s.get("parameters") if isinstance(raw_s.get("parameters"), list) else [],
                "rate_hz": _deep_merge_prefer_ai(raw_s.get("rate_hz"), _tracked_null()),
                "code_files": code_files,
                "sim": raw_s.get("sim") if isinstance(raw_s.get("sim"), dict) else None,
            }
            if sid in skel_by_id:
                sk = skel_by_id[sid]
                if not sub_obj["code_files"]:
                    sub_obj["code_files"] = copy.deepcopy(sk.get("code_files") or [])
                if not sub_obj["parameters"]:
                    sub_obj["parameters"] = copy.deepcopy(sk.get("parameters") or [])
                if not sub_obj["on"]:
                    sub_obj["on"] = copy.deepcopy(sk.get("on") or [])
                if sub_obj["rate_hz"].get("value") is None:
                    sub_obj["rate_hz"] = copy.deepcopy(sk.get("rate_hz") or _tracked_null())
            norm_subs.append(sub_obj)

    if not norm_subs:
        merged["subsystems"] = skel_subs
    else:
        if len(norm_subs) < len(skel_subs):
            seen_sub_ids = {s["id"] for s in norm_subs}
            for sk in skel_subs:
                if sk["id"] not in seen_sub_ids:
                    norm_subs.append(sk)
                    seen_sub_ids.add(sk["id"])
        merged["subsystems"] = norm_subs
    valid_sub_ids = {s["id"] for s in merged["subsystems"]}

    # Connections referencing valid subsystems.
    ai_conns = cleaned.get("connections")
    norm_conns = [
        {"from": c["from"], "to": c["to"], "signal": c.get("signal") if isinstance(c.get("signal"), str) else None}
        for c in (ai_conns if isinstance(ai_conns, list) else [])
        if isinstance(c, dict) and c.get("from") in valid_sub_ids and c.get("to") in valid_sub_ids
    ]
    merged["connections"] = norm_conns if norm_conns else [
        c for c in skeleton["connections"] if c["from"] in valid_sub_ids and c["to"] in valid_sub_ids
    ]

    # Sensors (keep AI sensors, backfill empty used_by/rate_hz, and include AST/text-detected skeleton sensors).
    skel_sensors_by_type = {sr["type"]: sr for sr in (skeleton.get("sensors") or []) if sr.get("type") in _VALID_SENSORS}
    ai_sensors = cleaned.get("sensors")
    norm_sensors = []
    seen_sensor_types: set[str] = set()
    if isinstance(ai_sensors, list):
        for sr in ai_sensors:
            if not isinstance(sr, dict) or sr.get("type") not in _VALID_SENSORS:
                continue
            stype = sr["type"]
            used_by = [u for u in (sr.get("used_by") or []) if u in valid_sub_ids]
            rate_hz = _deep_merge_prefer_ai(sr.get("rate_hz"), _tracked_null())
            if stype in skel_sensors_by_type:
                sk_sr = skel_sensors_by_type[stype]
                if not used_by:
                    used_by = [u for u in (sk_sr.get("used_by") or []) if u in valid_sub_ids]
                if rate_hz.get("value") is None:
                    rate_hz = copy.deepcopy(sk_sr.get("rate_hz") or _tracked_null())
            seen_sensor_types.add(stype)
            norm_sensors.append({
                "type": stype,
                "purpose": sr.get("purpose") if isinstance(sr.get("purpose"), str) else None,
                "rate_hz": rate_hz,
                "used_by": used_by,
            })
    for stype, sk_sr in skel_sensors_by_type.items():
        if stype not in seen_sensor_types:
            sr_copy = copy.deepcopy(sk_sr)
            sr_copy["used_by"] = [u for u in (sr_copy.get("used_by") or []) if u in valid_sub_ids]
            norm_sensors.append(sr_copy)
    merged["sensors"] = norm_sensors if norm_sensors else copy.deepcopy(skeleton["sensors"])

    # Data files (drop hallucinated names not in ZIP, normalize to real ZIP basenames, merge skeleton CSVs).
    ai_df = cleaned.get("data_files")
    norm_df = []
    seen_df_lower: set[str] = set()
    if isinstance(ai_df, list):
        for df in ai_df:
            if not isinstance(df, dict) or not str(df.get("file") or "").strip():
                continue
            resolved = _resolve_valid_file(df.get("file"), valid_by_base)
            if not resolved:
                continue
            low_file = resolved.lower()
            if low_file in seen_df_lower:
                continue
            seen_df_lower.add(low_file)
            fmt = df.get("format") if df.get("format") in _VALID_FORMATS else "csv"
            sub = df.get("subsystem") if df.get("subsystem") in valid_sub_ids else None
            cols = [c for c in (df.get("columns") or []) if isinstance(c, dict) and str(c.get("name") or "").strip()]
            norm_df.append({
                "file": resolved,
                "description": df.get("description") if isinstance(df.get("description"), str) else None,
                "subsystem": sub,
                "format": fmt,
                "sample_rate_hz": _deep_merge_prefer_ai(df.get("sample_rate_hz"), _tracked_null()),
                "columns": cols,
            })
    for sk_df in (skeleton.get("data_files") or []):
        if len(norm_df) >= 10:
            break
        sk_name = str(sk_df.get("file") or "").strip()
        if sk_name and sk_name.lower() not in seen_df_lower:
            sk_copy = copy.deepcopy(sk_df)
            sk_copy["subsystem"] = sk_copy.get("subsystem") if sk_copy.get("subsystem") in valid_sub_ids else None
            norm_df.append(sk_copy)
            seen_df_lower.add(sk_name.lower())
    merged["data_files"] = norm_df[:10] if norm_df else [
        {**copy.deepcopy(df), "subsystem": df.get("subsystem") if df.get("subsystem") in valid_sub_ids else None}
        for df in (skeleton.get("data_files") or [])
    ]

    # Results, metrics, limitations, future_work & media (grounded against ZIP and free of placeholders).
    merged["results"] = _deep_merge_prefer_ai(cleaned.get("results"), skeleton["results"])
    clean_metrics = [
        mt for mt in (merged["results"].get("metrics") or [])
        if isinstance(mt, dict) and not _is_placeholder_text(mt.get("metric"))
    ]
    merged["results"]["metrics"] = clean_metrics if clean_metrics else copy.deepcopy(skeleton["results"].get("metrics") or [])

    clean_lims = [x for x in (merged["results"].get("limitations") or []) if isinstance(x, str) and not _is_placeholder_text(x)]
    merged["results"]["limitations"] = clean_lims if clean_lims else copy.deepcopy(skeleton["results"].get("limitations") or [])

    clean_fw = [x for x in (merged["results"].get("future_work") or []) if isinstance(x, str) and not _is_placeholder_text(x)]
    merged["results"]["future_work"] = clean_fw if clean_fw else copy.deepcopy(skeleton["results"].get("future_work") or [])

    clean_media = []
    seen_media_lower: set[str] = set()
    for m in (merged["results"].get("media") or []):
        if not isinstance(m, dict) or not str(m.get("file") or "").strip():
            continue
        resolved = _resolve_valid_file(m.get("file"), valid_by_base)
        if not resolved or resolved.lower() in seen_media_lower:
            continue
        seen_media_lower.add(resolved.lower())
        part = m.get("part") if m.get("part") in valid_sub_ids else None
        raw_cap = m.get("caption")
        caption = raw_cap if isinstance(raw_cap, str) and not _is_placeholder_text(raw_cap) else None
        clean_media.append({"file": resolved, "caption": caption, "part": part})
    merged["results"]["media"] = clean_media if clean_media else [
        {**copy.deepcopy(m), "part": m.get("part") if m.get("part") in valid_sub_ids else None}
        for m in (skeleton.get("results", {}).get("media") or [])
    ]
    return merged


def extract_project(client: LLMClient, zip_bytes: bytes, files: list[dict], report: str | None, schema: dict, on_progress=None) -> tuple[dict, dict]:
    if on_progress:
        on_progress("scanning", "Scanning archive, CSVs & code (AST)…", 25, f"{len(files)} files in archive", 0)
    if client.provider == "local_rules":
        return local_rules_extract(zip_bytes, files, report)
    skeleton, _ = local_rules_extract(zip_bytes, files, report)
    parts = build_parts(zip_bytes, files, report)
    if client.provider == "ollama" and not _is_thinking_model(client.model):
        system = SYSTEM_PROMPT + "\n\n" + _OLLAMA_COMPACT_TEMPLATE
    else:
        system = (
            SYSTEM_PROMPT
            + "\n\nAnswer with one JSON object and nothing else (no prose, no code fence). It must match this JSON Schema "
            + "exactly; every property shown is required, use null where the report gives nothing:\n"
            + json.dumps(output_schema(schema), separators=(",", ":"))
        )
    data, meta = client.ask_json(system, parts, max_tokens=65536, on_progress=on_progress)
    return enforce_rules(merge_with_skeleton(data, skeleton, files=files)), meta


CHECKLIST_PROMPT = """You read an instructor's assignment brief for a university engineering project and list \
what a submitted project must contain or achieve, as a checklist an examiner could tick.

Rules:
- Use only the brief. Never add requirements it does not state, never soften or strengthen them.
- If the brief describes several problems or tasks for different teams, keep the requirements that apply \
to every team plus those of the one problem this project addresses.
- Leave out submission logistics (deadlines, how or where to submit, file naming, page layout, fonts).
- Each item: text (one short plain line), quote (at most 200 chars), source (file name and section/page), weight (number or null).
- 5 to 25 items.
Answer with JSON only: {"items": [{"text": "...", "quote": "...", "source": "...", "weight": null}]}"""

JUDGE_PROMPT = """You check an engineering project against a fixed checklist taken from the instructor's \
brief. For every checklist item decide: "met", "partial", or "not_met".
Answer with JSON only: {"items": [{"id": 0, "verdict": "met|partial|not_met", "evidence": "...", "where": "..."}]}"""

BRIEF_NAME = re.compile(r"activity|requirement|brief|assignment|rubric|instruction|task|spec", re.I)
NOT_BRIEF = re.compile(r"report|team\s*\d|group|submission|lab_guide|guide|manual|template|readme", re.I)


def find_briefs(files: list[dict], report: str | None) -> list[str]:
    scored = []
    for f in files:
        p = f["path"]
        if f.get("blocked") or p == report or not p.lower().endswith((".pdf", ".docx")):
            continue
        name = PurePosixPath(p).name
        if not BRIEF_NAME.search(name):
            continue
        s = 40 if re.search(r"activity|assignment|brief|rubric", name, re.I) else 20
        s -= 50 if NOT_BRIEF.search(name) and not re.search(r"requirement", name, re.I) else 0
        s += 2 if p.lower().endswith(".docx") else 0
        if s > 0:
            scored.append((s, p))
    out, stems = [], set()
    for _, p in sorted(scored, key=lambda x: -x[0]):
        stem = re.sub(r"\s*\(\d+\)$", "", PurePosixPath(p).stem.lower())
        if stem in stems:
            continue
        stems.add(stem)
        out.append(p)
    return out[:2]


def doc_part(z: zipfile.ZipFile, path: str) -> dict:
    data = z.read(path)
    if path.lower().endswith(".pdf"):
        if len(data) > MAX_PDF_BYTES:
            raise ValueError(f"{path} is too large to read")
        return {"type": "pdf", "bytes": data, "title": path}
    return {"type": "text", "text": f'<document name="{path}">\n{docx_text(data)}\n</document>'}


def brief_score(client: LLMClient, zip_bytes: bytes, files: list[dict], report: str | None, title: str) -> dict | None:
    if client.provider == "local_rules":
        return None
    if client.provider == "ollama" and os.environ.get("UNFOLD_OLLAMA_BRIEF", "0") != "1":
        return None
    z = zipfile.ZipFile(io.BytesIO(zip_bytes))
    briefs = find_briefs(files, report)
    if not briefs or not report:
        return None

    def _ask_brief(prompt: str, q_parts: list[dict]) -> tuple[dict, dict]:
        try:
            return client.ask_json(prompt, q_parts, max_tokens=16384, timeout=90)
        except (RuntimeError, requests.RequestException) as exc:
            if client.provider == "gemini" and client.model != DEFAULT_GEMINI_MODEL:
                log.warning("brief check fell back to %s after %s error (%s)", DEFAULT_GEMINI_MODEL, client.model, exc)
                fallback = LLMClient(provider="gemini", model=DEFAULT_GEMINI_MODEL, api_key=client.api_key)
                return fallback.ask_json(prompt, q_parts, max_tokens=16384, timeout=90)
            raise

    parts = [doc_part(z, b) for b in briefs]
    parts.append({"type": "text", "text": f'The project being checked: report file "{PurePosixPath(report).name}", title "{title}". List the checklist from the brief above.'})
    checklist, meta = _ask_brief(CHECKLIST_PROMPT, parts)
    items = [i for i in checklist.get("items", []) if isinstance(i, dict) and str(i.get("text", "")).strip()][:25]
    if not items:
        return None
    numbered = "\n".join(f'{k}. {i["text"]}  [brief: "{i.get("quote") or ""}"]' for k, i in enumerate(items))
    v_parts = [doc_part(z, report), {"type": "text", "text": f"Other files in the project's ZIP:\n\n{build_context(z, files, report)}\n\nChecklist:\n{numbered}"}]
    verdicts, meta = _ask_brief(JUDGE_PROMPT, v_parts)
    by_id = {int(v.get("id", -1)): v for v in verdicts.get("items", []) if isinstance(v, dict)}
    out = []
    for k, i in enumerate(items):
        v = by_id.get(k, {})
        verdict = v.get("verdict") if v.get("verdict") in ("met", "partial", "not_met") else "not_met"
        w = i.get("weight")
        out.append({"text": i["text"], "source": i.get("source") or "", "weight": float(w) if isinstance(w, (int, float)) and w > 0 else None,
                    "verdict": verdict, "evidence": v.get("evidence") or "", "where": v.get("where") or ""})
    given = [o["weight"] for o in out if o["weight"]]
    for o in out:
        o["weight"] = o["weight"] or (sum(given) / len(given) if given else 1.0)
    return {"items": out, "briefs": [PurePosixPath(b).name for b in briefs], "model": meta["model"], "version": BRIEF_VERSION}


class Site:
    def __init__(self, base: str, token: str):
        self.base = base.rstrip("/")
        self.s = requests.Session()
        self.s.headers["Authorization"] = f"Bearer {token}"
        self.s.headers["User-Agent"] = "unfold-worker/0.3"

    def route(self, path: str) -> str:
        return f"{self.base}/?rest_route=/unfold/v1{path}"

    def next_job(self) -> dict | None:
        r = self.s.post(self.route("/worker/next"), timeout=30)
        if r.status_code == 204:
            return None
        r.raise_for_status()
        return r.json()

    def zip(self, job: dict) -> bytes:
        r = self.s.get(self.route(f"/worker/jobs/{job['project_id']}/zip"), params={"job": job["job_id"]}, timeout=600)
        r.raise_for_status()
        return r.content

    def progress(self, job: dict, stage: str, label: str, pct: int, detail: str = "", chars: int = 0) -> None:
        try:
            self.s.post(
                self.route(f"/worker/jobs/{job['project_id']}/progress"),
                json={"job_id": job["job_id"], "stage": stage, "label": label, "pct": int(pct), "detail": detail, "chars": int(chars)},
                timeout=10,
            )
        except requests.RequestException:
            pass

    def result(self, job: dict, payload: dict) -> dict:
        r = self.s.post(self.route(f"/worker/jobs/{job['project_id']}/result"), json={"job_id": job["job_id"], **payload}, timeout=120)
        r.raise_for_status()
        return r.json()


COVER_PROMPT_TEMPLATE = (
    "2D vector illustration of {description}, clean thin grey outlines, soft gradient shading, "
    "subtle white highlights, semi-flat volume effect. Vibrant colors, professional cartoonish clip-art style, "
    "isolated on a pure white background."
)


def build_cover_prompt(data: dict) -> str:
    """Format the 2D vector cover illustration prompt from extracted project title/description."""
    meta = data.get("meta") or {}
    title = str(meta.get("title") or "").strip()
    desc = str(meta.get("short_description") or "").strip()
    if title and desc and desc.lower() != title.lower():
        raw = f"{title} - {desc}"
    else:
        raw = title or desc or "engineering autonomous robotics project"
    raw = re.sub(r"\s+", " ", raw).strip()[:240].rstrip(" ,;:-")
    return COVER_PROMPT_TEMPLATE.format(description=raw)


GEMINI_IMAGE_MODELS = (
    "gemini-2.5-flash-image",
    "gemini-2.5-flash-image-preview",
    "gemini-2.0-flash-exp-image-generation",
)


def generate_cover_image(client: LLMClient, data: dict) -> tuple[bytes, str, str] | None:
    """Generate a 2D vector illustration for the project card using configured AI or opt-in fallback."""
    prompt = build_cover_prompt(data)

    if client.provider == "gemini":
        key = client.api_key or os.environ.get("GEMINI_API_KEY") or read_secret_file("gemini-key.txt")
        if key:
            candidate_models: list[str] = []
            if client.model:
                candidate_models.append(client.model)
            for m in GEMINI_IMAGE_MODELS:
                if m not in candidate_models:
                    candidate_models.append(m)
            for img_model in candidate_models:
                try:
                    url = f"https://generativelanguage.googleapis.com/v1beta/models/{img_model}:generateContent"
                    for img_try in range(2):
                        r = requests.post(
                            url,
                            headers={"x-goog-api-key": key},
                            json={
                                "contents": [{"role": "user", "parts": [{"text": prompt}]}],
                                "generationConfig": {"responseModalities": ["IMAGE", "TEXT"]},
                            },
                            timeout=60,
                        )
                        if r.ok:
                            for pt in (((r.json().get("candidates") or [{}])[0].get("content") or {}).get("parts") or []):
                                inl = pt.get("inlineData") or {}
                                if inl.get("data"):
                                    return base64.standard_b64decode(inl["data"]), inl.get("mimeType") or "image/png", prompt
                            break
                        if r.status_code in (429, 503) and img_try == 0:
                            log.warning("gemini image model %s returned HTTP %s, retrying shortly...", img_model, r.status_code)
                            time.sleep(5)
                            continue
                        err_hint = re.sub(r"\s+", " ", (r.text or "")).strip()[:160]
                        log.warning("gemini image model %s returned HTTP %s (%s)", img_model, r.status_code, err_hint)
                        break
                except Exception:  # noqa: BLE001
                    log.debug("gemini image generation unavailable for %s", img_model, exc_info=True)

    if client.provider == "openai_compat" and client.base_url and "api.openai.com" in client.base_url.lower():
        key = client.api_key or os.environ.get("UNFOLD_AI_API_KEY") or read_secret_file("ai-key.txt")
        if key:
            try:
                r = requests.post(
                    f"{client.base_url.rstrip('/')}/images/generations",
                    headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
                    json={"model": "gpt-image-1", "prompt": prompt, "size": "1024x1024", "response_format": "b64_json"},
                    timeout=60,
                )
                if r.ok:
                    b64 = ((r.json().get("data") or [{}])[0]).get("b64_json")
                    if b64:
                        return base64.standard_b64decode(b64), "image/png", prompt
            except Exception:  # noqa: BLE001
                log.debug("openai image generation unavailable, trying fallback", exc_info=True)

    if os.environ.get("UNFOLD_IMAGE_FALLBACK", "0") == "1":
        try:
            url = f"https://image.pollinations.ai/prompt/{quote(prompt)}?width=1024&height=640&nologo=true"
            r = requests.get(url, timeout=45)
            ct = (r.headers.get("Content-Type") or "").split(";")[0].strip().lower()
            if r.ok and ct.startswith("image/") and len(r.content) > 1024:
                return r.content, ct, prompt
        except Exception:  # noqa: BLE001
            log.debug("pollinations cover fallback failed", exc_info=True)

    return None


def handle(site: Site, client: LLMClient, job: dict) -> None:
    active_client = client
    job_ai = job.get("ai")
    if isinstance(job_ai, dict) and job_ai.get("provider"):
        active_client = LLMClient(
            provider=job_ai.get("provider"),
            model=job_ai.get("model") or None,
            api_key=job_ai.get("api_key") or None,
            base_url=job_ai.get("base_url") or None,
        )
    log.info("job %s for project %s (provider=%s, model=%s)", job["job_id"], job["project_id"], active_client.provider, active_client.model)
    def report_progress(stage: str, label: str, pct: int, detail: str = "", chars: int = 0) -> None:
        site.progress(job, stage, label, pct, detail, chars)

    try:
        report_progress("downloading", "Downloading project ZIP…", 10, f"{len(job.get('files') or [])} files")
        zip_bytes = site.zip(job)
        data, meta = extract_project(active_client, zip_bytes, job["files"], job.get("report"), job["schema"], on_progress=report_progress)
    except (ValueError, RuntimeError, json.JSONDecodeError, KeyError, zipfile.BadZipFile, requests.RequestException) as e:
        log.exception("extraction failed")
        site.result(job, {"error": str(e)[:400]})
        return
    try:
        report_progress("ai", "Evaluating against assignment brief (0–100)…", 82)
        score = brief_score(active_client, zip_bytes, job["files"], job.get("report"), (data.get("meta") or {}).get("title") or "")
        if score:
            r = site.s.post(site.route(f"/worker/projects/{job['project_id']}/brief-score"), json=score, timeout=60)
            r.raise_for_status()
    except Exception:  # noqa: BLE001
        log.exception("brief check failed (the project is not affected)")
    try:
        report_progress("ai", "Generating cover illustration…", 88)
        cover = generate_cover_image(active_client, data)
        if cover:
            img_bytes, mime_type, cover_prompt = cover
            r = site.s.post(
                site.route(f"/worker/projects/{job['project_id']}/cover-image"),
                json={
                    "image_base64": base64.standard_b64encode(img_bytes).decode("ascii"),
                    "mime_type": mime_type,
                    "prompt": cover_prompt,
                },
                timeout=60,
            )
            r.raise_for_status()
            log.info("uploaded AI cover illustration for project %s", job["project_id"])
    except Exception:  # noqa: BLE001
        log.exception("cover illustration failed (the project is not affected)")
    report_progress("saving", "Validating schema & attaching files…", 95, f"{meta['usage']['output_tokens']} output tokens")
    res = site.result(job, {"data": data, "meta": meta})
    log.info("done: %s output tokens, %d schema problems, %d files attached",
             meta["usage"]["output_tokens"], len(res.get("schema_errors", [])), res.get("attached", 0))


def default_schema_path() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    local = os.path.join(here, "project.schema.json")
    if os.path.isfile(local):
        return local
    return os.path.abspath(os.path.join(here, "..", "..", "wp-content", "plugins", "unfold", "schema", "project.schema.json"))


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--once", action="store_true", help="handle at most one job, then exit")
    ap.add_argument("--until-empty", action="store_true", help="handle every waiting job, then exit")
    ap.add_argument("--provider", help="gemini | ollama | openai_compat | local_rules | anthropic")
    ap.add_argument("--dry-run", metavar="ZIP", help="build the request for a local ZIP without calling any API")
    ap.add_argument("--report", help="report path inside the ZIP (dry run)")
    ap.add_argument("--schema", default=default_schema_path(), help="path to project.schema.json")
    args = ap.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    client = LLMClient(args.provider)
    if args.dry_run:
        raw = open(args.dry_run, "rb").read()
        z = zipfile.ZipFile(io.BytesIO(raw))
        files = []
        for info in z.infolist():
            if info.is_dir():
                continue
            ext = PurePosixPath(info.filename).suffix.lower().lstrip(".")
            kind = "report" if ext in ("pdf", "docx", "doc") else "data" if ext in ("csv", "json", "txt", "npy", "mat") else "image" if ext in ("png", "jpg", "jpeg") else "code" if ext in ("py", "m", "md") else "other"
            files.append({"path": info.filename, "size": info.file_size, "kind": kind, "blocked": False})
        report = args.report or next((f["path"] for f in files if f["kind"] == "report"), None)
        if client.provider == "local_rules":
            data, _ = local_rules_extract(raw, files, report)
            print(json.dumps(data, indent=2, ensure_ascii=False)[:2000])
        else:
            parts = build_parts(raw, files, report)
            print(f"provider={client.provider} model={client.model} parts={len(parts)}")
        return

    site_url = os.environ.get("UNFOLD_SITE", "http://localhost:8881")
    token = os.environ.get("UNFOLD_WORKER_TOKEN") or read_token_file()
    if not token:
        sys.exit("Set UNFOLD_WORKER_TOKEN (wp-admin -> Projects -> Settings), or save it in worker-token.txt.")
    site = Site(site_url, token)
    log.info("polling %s every %ss (provider=%s, model=%s)", site_url, POLL_SECONDS, client.provider, client.model)
    while True:
        try:
            job = site.next_job()
        except requests.RequestException as e:
            log.warning("site unreachable: %s", e)
            job = None
        if job:
            handle(site, client, job)
        if args.once or (args.until_empty and not job):
            return
        if not job:
            time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
