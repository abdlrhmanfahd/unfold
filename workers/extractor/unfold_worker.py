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
import html
import io
import json
import logging
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
DEFAULT_OLLAMA_MODEL = "qwen2.5:14b"
DEFAULT_OPENAI_MODEL = "llama-3.3-70b-versatile"
DEFAULT_ANTHROPIC_MODEL = "claude-opus-4-1"

POLL_SECONDS = 10
MAX_PDF_BYTES = 30 * 1024 * 1024
MAX_CONTEXT_CHARS = 90_000
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
- meta.question: the project's question in one short plain line ("Can software fix bent wheels?"). \
meta.short_description: always write it - 2-3 plain sentences shown right under the title on the \
project page: what the project builds or tests, how, and on what (a non-specialist must follow it), from \
the report only. meta.headline: the one result that answers it, as before/after numbers from the report with a short \
label ("Tracking loss, without -> with the fix"); null if the report has no such pair.
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


def build_context(z: zipfile.ZipFile, files: list[dict], report: str | None) -> str:
    """Inventory plus the most informative small files, within a fixed character budget."""
    parts: list[str] = []
    budget = MAX_CONTEXT_CHARS

    def add(title: str, body: str) -> bool:
        nonlocal budget
        chunk = f'<file name="{title}">\n{body.strip()}\n</file>\n'
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
    inventory = "\n".join(inv)
    parts.append(f"<inventory count=\"{len(files)}\">\n{inventory[:30000]}\n</inventory>\n")
    budget -= min(len(inventory), 30000)

    readable = {f["path"]: f for f in files if not f.get("blocked")}

    for path, f in readable.items():
        name = PurePosixPath(path).name.lower()
        if path == report:
            continue
        if (name.startswith("readme") or name.endswith(".md")) and f["size"] < 40_000 or (
            name.endswith(".json") and f["size"] < 12_000 and ("config" in name or "calibration" in name or "result" in name or "analysis" in name)
        ):
            add(path, z.read(path).decode("utf-8", "replace"))

    seen_headers = set()
    for path, _f in readable.items():
        if not path.lower().endswith((".csv", ".tsv")):
            continue
        with z.open(path) as fh:
            head = fh.read(4000).decode("utf-8", "replace").splitlines()[:3]
        key = head[0] if head else ""
        if key in seen_headers:
            continue
        seen_headers.add(key)
        if not add(path, "first lines:\n" + "\n".join(line[:400] for line in head)):
            break

    for path, f in readable.items():
        if f["kind"] == "code" and path.lower().endswith((".py", ".m")) and f["size"] < 200_000:
            text = z.read(path).decode("utf-8", "replace").splitlines()[:30]
            if not add(path, "first 30 lines:\n" + "\n".join(text)):
                break

    return "".join(parts)


def parse_json(text: str) -> dict:
    """The JSON object in a reply, tolerating a code fence or stray words around it."""
    t = text.strip()
    if t.startswith("```"):
        t = re.sub(r"^```[a-zA-Z]*\s*|\s*```$", "", t)
    start, end = t.find("{"), t.rfind("}")
    if start == -1 or end <= start:
        raise json.JSONDecodeError("no JSON object found", t, 0)
    data = json.loads(t[start:end + 1])
    if not isinstance(data, dict):
        raise json.JSONDecodeError("the reply is not a JSON object", t, 0)
    return data


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
    for s in data.get("subsystems", []):
        sim = s.get("sim")
        if not isinstance(sim, dict) or not sim.get("template"):
            s["sim"] = None
        else:
            sim["student_approved"] = True
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

    def _gemini_json(self, system: str, parts: list[dict], max_tokens: int) -> tuple[dict, dict]:
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
            payload = {
                "system_instruction": {"parts": [{"text": system}]},
                "contents": messages,
                "generationConfig": {
                    "responseMimeType": "application/json",
                    "maxOutputTokens": max_tokens,
                    "temperature": 0.1,
                },
            }
            r = requests.post(url, params={"key": key}, json=payload, timeout=180)
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

    def _ollama_json(self, system: str, parts: list[dict]) -> tuple[dict, dict]:
        base = (self.base_url or os.environ.get("UNFOLD_OLLAMA_URL", DEFAULT_OLLAMA_URL)).rstrip("/")
        text_chunks = []
        for p in parts:
            if p["type"] == "pdf":
                text_chunks.append(f'<report name="{p["title"]}">\n{pdf_text(p["bytes"], max_chars=9_000)}\n</report>')
            else:
                text_chunks.append(p["text"])
        user_text = "\n\n".join(text_chunks)[:11_000]
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": user_text},
        ]
        num_ctx = int(os.environ.get("UNFOLD_OLLAMA_NUM_CTX", "8192"))
        for attempt in (1, 2):
            r = requests.post(
                f"{base}/api/chat",
                json={
                    "model": self.model,
                    "messages": messages,
                    "format": "json",
                    "stream": False,
                    "options": {"num_ctx": num_ctx, "temperature": 0.1},
                },
                timeout=600,
            )
            r.raise_for_status()
            body = r.json()
            text = (body.get("message") or {}).get("content") or ""
            try:
                usage = {
                    "input_tokens": int(body.get("prompt_eval_count") or 0),
                    "output_tokens": int(body.get("eval_count") or 0),
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

    def ask_json(self, system: str, parts: list[dict], max_tokens: int = 32768) -> tuple[dict, dict]:
        if self.provider == "gemini":
            return self._gemini_json(system, parts, max_tokens)
        if self.provider == "ollama":
            return self._ollama_json(system, parts)
        if self.provider == "openai_compat":
            return self._openai_compat_json(system, parts, max_tokens)
        if self.provider == "anthropic":
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
    "title": "Short project title",
    "question": "Short question answered by this project or null",
    "headline": null,
    "short_description": "1-2 sentence summary from report",
    "platform": {"mode": "qlabs_sim", "vehicle_count": {"value": null, "source_ref": null, "status": "extracted"}, "software_stack": ["python"]},
    "keywords": ["LiDAR", "Obstacle Avoidance"]
  },
  "goal": {
    "problem_statement": "Problem description from report or null",
    "objectives": ["Objective 1", "Objective 2"],
    "success_criteria": [{"metric": "Stopping distance", "target": {"value": 1.0, "source_ref": "p. 2", "status": "extracted"}, "unit": "m"}]
  },
  "environment": {"scenario": "Scenario description or null", "map_source": "qlabs_builtin", "map_file": null, "obstacles": "static", "other_agents": null},
  "sensors": [{"type": "lidar", "purpose": "...", "rate_hz": {"value": null, "source_ref": null, "status": "extracted"}, "used_by": ["perception"]}],
  "subsystems": [{
    "id": "perception", "name": "Perception", "plain": "Detects obstacles in under 9 words.",
    "on": ["lidar"], "category": "perception", "description": "2-3 sentence explanation.",
    "inputs": [], "outputs": [], "method": "Algorithm or null", "equations": [], "parameters": [],
    "rate_hz": {"value": null, "source_ref": null, "status": "extracted"}, "code_files": ["file.py"], "sim": null
  }],
  "connections": [{"from": "perception", "to": "control", "signal": "obstacle state"}],
  "data_files": [{"file": "runs.csv", "description": null, "subsystem": "control", "format": "csv", "sample_rate_hz": {"value": null, "source_ref": null, "status": "extracted"}, "columns": [{"name": "col", "unit": null}]}],
  "results": {
    "metrics": [{"metric": "Success rate", "value": {"value": 95, "source_ref": "p. 5", "status": "extracted"}, "unit": "%", "conditions": null}],
    "media": [{"file": "plot.png", "caption": "Caption", "part": null}],
    "limitations": ["Limitation 1"],
    "future_work": ["Future improvement 1"]
  }
}
Important rules:
- Use null (or valid enum values) instead of copying placeholder pipes:
  - meta.platform.mode: "qlabs_sim", "physical_qcar2", "both", or null
  - environment.map_source: "qlabs_builtin", "custom", "physical_track", "other", or null
  - environment.obstacles: "static", "dynamic", "both", "none", or null
  - sensors[].type: "lidar", "rgb_camera", "depth_camera", "imu", "wheel_encoder", or "other"
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


def merge_with_skeleton(ai_data: dict, skeleton: dict) -> dict:
    """Normalize AI output against schema-valid skeleton so partial/small models never produce missing keys."""
    if not isinstance(ai_data, dict):
        return copy.deepcopy(skeleton)
    cleaned = {k: v for k, v in ai_data.items() if k in _VALID_ROOT_KEYS}

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
    plat = merged["meta"].get("platform") or {}
    if plat.get("mode") not in _VALID_MODES:
        plat["mode"] = skeleton["meta"]["platform"]["mode"]
    stack = [x for x in (plat.get("software_stack") or []) if x in _VALID_STACKS]
    plat["software_stack"] = stack if stack else copy.deepcopy(skeleton["meta"]["platform"]["software_stack"])
    if merged["environment"].get("map_source") not in _VALID_MAP_SOURCES:
        merged["environment"]["map_source"] = skeleton["environment"]["map_source"]
    if merged["environment"].get("obstacles") not in _VALID_OBSTACLES:
        merged["environment"]["obstacles"] = skeleton["environment"]["obstacles"]

    # Normalize subsystems.
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
            norm_subs.append({
                "id": sid,
                "name": name,
                "plain": plain,
                "on": on_hw,
                "category": cat,
                "description": raw_s.get("description") or plain,
                "inputs": raw_s.get("inputs") if isinstance(raw_s.get("inputs"), list) else [],
                "outputs": raw_s.get("outputs") if isinstance(raw_s.get("outputs"), list) else [],
                "method": raw_s.get("method") if isinstance(raw_s.get("method"), str) else None,
                "equations": [e for e in (raw_s.get("equations") or []) if isinstance(e, str) and e.strip()],
                "parameters": raw_s.get("parameters") if isinstance(raw_s.get("parameters"), list) else [],
                "rate_hz": _deep_merge_prefer_ai(raw_s.get("rate_hz"), _tracked_null()),
                "code_files": [c for c in (raw_s.get("code_files") or []) if isinstance(c, str) and c.strip()],
                "sim": raw_s.get("sim") if isinstance(raw_s.get("sim"), dict) else None,
            })
    merged["subsystems"] = norm_subs if norm_subs else copy.deepcopy(skeleton["subsystems"])
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

    # Sensors.
    ai_sensors = cleaned.get("sensors")
    norm_sensors = []
    if isinstance(ai_sensors, list):
        for sr in ai_sensors:
            if not isinstance(sr, dict) or sr.get("type") not in _VALID_SENSORS:
                continue
            used_by = [u for u in (sr.get("used_by") or []) if u in valid_sub_ids]
            norm_sensors.append({
                "type": sr["type"],
                "purpose": sr.get("purpose") if isinstance(sr.get("purpose"), str) else None,
                "rate_hz": _deep_merge_prefer_ai(sr.get("rate_hz"), _tracked_null()),
                "used_by": used_by,
            })
    merged["sensors"] = norm_sensors if norm_sensors else copy.deepcopy(skeleton["sensors"])

    # Data files (guarantee required "format").
    ai_df = cleaned.get("data_files")
    norm_df = []
    if isinstance(ai_df, list):
        for df in ai_df:
            if not isinstance(df, dict) or not str(df.get("file") or "").strip():
                continue
            fmt = df.get("format") if df.get("format") in _VALID_FORMATS else "csv"
            sub = df.get("subsystem") if df.get("subsystem") in valid_sub_ids else None
            cols = [c for c in (df.get("columns") or []) if isinstance(c, dict) and str(c.get("name") or "").strip()]
            norm_df.append({
                "file": str(df["file"]).strip(),
                "description": df.get("description") if isinstance(df.get("description"), str) else None,
                "subsystem": sub,
                "format": fmt,
                "sample_rate_hz": _deep_merge_prefer_ai(df.get("sample_rate_hz"), _tracked_null()),
                "columns": cols,
            })
    merged["data_files"] = norm_df if norm_df else copy.deepcopy(skeleton["data_files"])

    # Results & media (ensure no unknown keys like "type").
    merged["results"] = _deep_merge_prefer_ai(cleaned.get("results"), skeleton["results"])
    clean_media = []
    for m in (merged["results"].get("media") or []):
        if isinstance(m, dict) and str(m.get("file") or "").strip():
            part = m.get("part") if m.get("part") in valid_sub_ids else None
            clean_media.append({"file": str(m["file"]).strip(), "caption": m.get("caption") if isinstance(m.get("caption"), str) else None, "part": part})
    merged["results"]["media"] = clean_media if clean_media else copy.deepcopy(skeleton["results"]["media"])
    return merged


def extract_project(client: LLMClient, zip_bytes: bytes, files: list[dict], report: str | None, schema: dict) -> tuple[dict, dict]:
    if client.provider == "local_rules":
        return local_rules_extract(zip_bytes, files, report)
    skeleton, _ = local_rules_extract(zip_bytes, files, report)
    parts = build_parts(zip_bytes, files, report)
    if client.provider == "ollama":
        system = SYSTEM_PROMPT + "\n\n" + _OLLAMA_COMPACT_TEMPLATE
    else:
        system = (
            SYSTEM_PROMPT
            + "\n\nAnswer with one JSON object and nothing else (no prose, no code fence). It must match this JSON Schema "
            + "exactly; every property shown is required, use null where the report gives nothing:\n"
            + json.dumps(output_schema(schema), separators=(",", ":"))
        )
    data, meta = client.ask_json(system, parts, max_tokens=65536)
    return enforce_rules(merge_with_skeleton(data, skeleton)), meta


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
    parts = [doc_part(z, b) for b in briefs]
    parts.append({"type": "text", "text": f'The project being checked: report file "{PurePosixPath(report).name}", title "{title}". List the checklist from the brief above.'})
    checklist, meta = client.ask_json(CHECKLIST_PROMPT, parts, max_tokens=16384)
    items = [i for i in checklist.get("items", []) if isinstance(i, dict) and str(i.get("text", "")).strip()][:25]
    if not items:
        return None
    numbered = "\n".join(f'{k}. {i["text"]}  [brief: "{i.get("quote") or ""}"]' for k, i in enumerate(items))
    v_parts = [doc_part(z, report), {"type": "text", "text": f"Other files in the project's ZIP:\n\n{build_context(z, files, report)}\n\nChecklist:\n{numbered}"}]
    verdicts, meta = client.ask_json(JUDGE_PROMPT, v_parts, max_tokens=16384)
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


def generate_cover_image(client: LLMClient, data: dict) -> tuple[bytes, str, str] | None:
    """Generate a 2D vector illustration for the project card using configured AI or free fallback."""
    prompt = build_cover_prompt(data)

    if client.provider == "gemini":
        key = client.api_key or os.environ.get("GEMINI_API_KEY") or read_secret_file("gemini-key.txt")
        if key:
            try:
                url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-preview-image-generation:generateContent"
                r = requests.post(
                    url,
                    params={"key": key},
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
            except Exception:  # noqa: BLE001
                log.debug("gemini image generation unavailable, trying fallback", exc_info=True)

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

    if os.environ.get("UNFOLD_IMAGE_FALLBACK", "1") != "0":
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
    try:
        zip_bytes = site.zip(job)
        data, meta = extract_project(active_client, zip_bytes, job["files"], job.get("report"), job["schema"])
    except (ValueError, RuntimeError, json.JSONDecodeError, KeyError, zipfile.BadZipFile, requests.RequestException) as e:
        log.exception("extraction failed")
        site.result(job, {"error": str(e)[:400]})
        return
    res = site.result(job, {"data": data, "meta": meta})
    log.info("done: %s output tokens, %d schema problems, %d files attached",
             meta["usage"]["output_tokens"], len(res.get("schema_errors", [])), res.get("attached", 0))
    try:
        score = brief_score(active_client, zip_bytes, job["files"], job.get("report"), (data.get("meta") or {}).get("title") or "")
        if score:
            r = site.s.post(site.route(f"/worker/projects/{job['project_id']}/brief-score"), json=score, timeout=60)
            r.raise_for_status()
    except Exception:  # noqa: BLE001
        log.exception("brief check failed (the project is not affected)")
    try:
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
