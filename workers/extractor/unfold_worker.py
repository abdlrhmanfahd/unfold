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
from pathlib import PurePosixPath

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


def pdf_text(data: bytes, max_chars: int = 120_000) -> str:
    """Extract text locally from a PDF for text-only providers (Ollama / OpenAI-compat / fallback)."""
    try:
        from pypdf import PdfReader  # type: ignore
        reader = PdfReader(io.BytesIO(data))
        chunks = []
        total = 0
        for idx, page in enumerate(reader.pages, start=1):
            t = (page.extract_text() or "").strip()
            if t:
                chunk = f"[Page {idx}]\n{t}"
                chunks.append(chunk)
                total += len(chunk)
                if total >= max_chars:
                    break
        if chunks:
            return "\n\n".join(chunks)[:max_chars]
    except Exception:  # noqa: BLE001
        pass
    # Minimal fallback: extract printable ASCII strings inside PDF text operators.
    strings = re.findall(rb"\(([^()]{3,200})\)", data)
    text = " ".join(s.decode("latin1", "ignore") for s in strings)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:max_chars]


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
            t = open(p, encoding="utf-8").read().strip()
            if t:
                return t
    return None


def read_token_file() -> str | None:
    return read_secret_file("worker-token.txt")


def _tracked_null() -> dict:
    return {"value": None, "source_ref": None, "status": "extracted"}


def local_rules_extract(zip_bytes: bytes, files: list[dict], report: str | None) -> tuple[dict, dict]:
    """Deterministic zero-AI extraction fallback producing a valid project skeleton for manual editing."""
    z = zipfile.ZipFile(io.BytesIO(zip_bytes))
    title = PurePosixPath(report).stem.replace("_", " ").replace("-", " ").strip() if report else "Engineering Project"
    if len(title) < 2:
        title = "Engineering Project"

    summary_text = ""
    if report and report.lower().endswith(".docx"):
        lines = [ln.strip() for ln in docx_text(z.read(report)).splitlines() if ln.strip()]
        if lines and 3 <= len(lines[0]) <= 120:
            title = lines[0]
        summary_text = " ".join(lines[:4])[:500]
    elif report and report.lower().endswith(".pdf"):
        txt = pdf_text(z.read(report), max_chars=2000)
        summary_text = txt[:500]

    code_files = [f["path"] for f in files if not f.get("blocked") and f.get("kind") == "code"][:20]
    data_files = []
    for f in files:
        if f.get("blocked") or f.get("kind") != "data" or not f["path"].lower().endswith(".csv"):
            continue
        cols = []
        try:
            with z.open(f["path"]) as fh:
                first = fh.read(2048).decode("utf-8", "replace").splitlines()[0]
                for c in [x.strip().strip('"') for x in first.split(",") if x.strip()][:4]:
                    cols.append({"name": c, "unit": None})
        except Exception:  # noqa: BLE001
            pass
        data_files.append({
            "file": PurePosixPath(f["path"]).name,
            "description": None,
            "subsystem": "main_system",
            "columns": cols,
        })
        if len(data_files) >= 10:
            break

    media = []
    for f in files:
        if f.get("blocked"):
            continue
        p = f["path"].lower()
        if f.get("kind") == "image" or p.endswith((".png", ".jpg", ".jpeg", ".webp", ".gif")):
            media.append({"file": PurePosixPath(f["path"]).name, "caption": None, "type": "image", "part": None})
        elif f.get("kind") == "video" or p.endswith((".mp4", ".webm", ".mov")):
            media.append({"file": PurePosixPath(f["path"]).name, "caption": None, "type": "video", "part": None})
        if len(media) >= 15:
            break

    data = {
        "schema_version": "0.2",
        "meta": {
            "title": title,
            "question": None,
            "headline": None,
            "short_description": summary_text or "Extracted locally without an LLM. Review and complete the subsystems and results below.",
            "platform": {
                "mode": None,
                "vehicle_count": _tracked_null(),
                "software_stack": [],
            },
            "keywords": [],
        },
        "goal": {
            "problem_statement": summary_text or None,
            "objectives": [],
            "success_criteria": [],
        },
        "environment": {
            "scenario": None,
            "map_source": None,
            "map_file": None,
            "obstacles": None,
            "other_agents": None,
        },
        "sensors": [],
        "subsystems": [
            {
                "id": "main_system",
                "name": "System software",
                "plain": "Main project pipeline.",
                "on": [],
                "category": "other",
                "description": "Placeholder subsystem created by local fallback extractor; break down or rename in the editor.",
                "inputs": [],
                "outputs": [],
                "method": None,
                "equations": [],
                "parameters": [],
                "rate_hz": _tracked_null(),
                "code_files": code_files,
                "sim": None,
            }
        ],
        "connections": [],
        "data_files": data_files,
        "results": {
            "metrics": [],
            "media": media,
            "limitations": [],
            "future_work": [],
        },
    }
    meta = {"model": "local_rules", "usage": {"input_tokens": 0, "output_tokens": 0, "cache_read_input_tokens": 0}}
    return enforce_rules(data), meta


class LLMClient:
    """Unified client supporting free Gemini, local Ollama, OpenAI-compatible endpoints, local rules, or optional Anthropic."""

    def __init__(self, provider: str | None = None):
        self.provider = (provider or os.environ.get("UNFOLD_AI_PROVIDER") or self.detect_provider()).strip().lower()
        self.model = self._resolve_model()

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

    def _resolve_model(self) -> str:
        override = os.environ.get("UNFOLD_AI_MODEL")
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
        key = os.environ.get("GEMINI_API_KEY") or read_secret_file("gemini-key.txt")
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
        base = os.environ.get("UNFOLD_OLLAMA_URL", DEFAULT_OLLAMA_URL).rstrip("/")
        text_chunks = []
        for p in parts:
            if p["type"] == "pdf":
                text_chunks.append(f'<report name="{p["title"]}">\n{pdf_text(p["bytes"])}\n</report>')
            else:
                text_chunks.append(p["text"])
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": "\n\n".join(text_chunks)},
        ]
        for attempt in (1, 2):
            r = requests.post(
                f"{base}/api/chat",
                json={"model": self.model, "messages": messages, "format": "json", "stream": False},
                timeout=300,
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
        base = (os.environ.get("UNFOLD_AI_BASE_URL") or "https://api.groq.com/openai/v1").rstrip("/")
        key = os.environ.get("UNFOLD_AI_API_KEY") or read_secret_file("ai-key.txt") or ""
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
        key = os.environ.get("ANTHROPIC_API_KEY") or read_secret_file("anthropic-key.txt")
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


def extract_project(client: LLMClient, zip_bytes: bytes, files: list[dict], report: str | None, schema: dict) -> tuple[dict, dict]:
    if client.provider == "local_rules":
        return local_rules_extract(zip_bytes, files, report)
    parts = build_parts(zip_bytes, files, report)
    system = (
        SYSTEM_PROMPT
        + "\n\nAnswer with one JSON object and nothing else (no prose, no code fence). It must match this JSON Schema "
        + "exactly; every property shown is required, use null where the report gives nothing:\n"
        + json.dumps(output_schema(schema), separators=(",", ":"))
    )
    data, meta = client.ask_json(system, parts, max_tokens=65536)
    return enforce_rules(data), meta


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


def handle(site: Site, client: LLMClient, job: dict) -> None:
    log.info("job %s for project %s (provider=%s, model=%s)", job["job_id"], job["project_id"], client.provider, client.model)
    try:
        zip_bytes = site.zip(job)
        data, meta = extract_project(client, zip_bytes, job["files"], job.get("report"), job["schema"])
    except (ValueError, RuntimeError, json.JSONDecodeError, KeyError, zipfile.BadZipFile, requests.RequestException) as e:
        log.exception("extraction failed")
        site.result(job, {"error": str(e)[:400]})
        return
    res = site.result(job, {"data": data, "meta": meta})
    log.info("done: %s output tokens, %d schema problems, %d files attached",
             meta["usage"]["output_tokens"], len(res.get("schema_errors", [])), res.get("attached", 0))
    try:
        score = brief_score(client, zip_bytes, job["files"], job.get("report"), (data.get("meta") or {}).get("title") or "")
        if score:
            r = site.s.post(site.route(f"/worker/projects/{job['project_id']}/brief-score"), json=score, timeout=60)
            r.raise_for_status()
    except Exception:  # noqa: BLE001
        log.exception("brief check failed (the project is not affected)")


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
