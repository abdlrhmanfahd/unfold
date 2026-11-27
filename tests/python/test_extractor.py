import io
import json
import os
import sys
import unittest
import zipfile
from unittest import mock

sys.path.insert(0, os.path.abspath("workers/extractor"))
import unfold_worker as uw  # noqa: E402


def make_sample_zip() -> tuple[bytes, list[dict]]:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("report.docx", b"not-used-directly")
        z.writestr("src/speed_ctrl.py", "def step(e):\n    return 0.5 * e\n")
        z.writestr("logs/speed.csv", "t,v_meas,v_ref\n0.0,0.1,0.6\n0.1,0.5,0.6\n")
        z.writestr("figures/plot1.png", b"PNGDATA")
    files = [
        {"path": "report.docx", "size": 100, "kind": "report", "blocked": False},
        {"path": "src/speed_ctrl.py", "size": 40, "kind": "code", "blocked": False},
        {"path": "logs/speed.csv", "size": 50, "kind": "data", "blocked": False},
        {"path": "figures/plot1.png", "size": 7, "kind": "image", "blocked": False},
    ]
    return buf.getvalue(), files


class ExtractorWorkerTests(unittest.TestCase):
    def test_parse_json_tolerates_markdown_fences(self):
        raw = 'Here is the result:\n```json\n{"schema_version": "0.2", "ok": true}\n```'
        parsed = uw.parse_json(raw)
        self.assertEqual(parsed["schema_version"], "0.2")
        self.assertTrue(parsed["ok"])

    def test_enforce_rules_forces_extracted_and_clears_invalid_sim(self):
        data = {
            "meta": {"platform": {"vehicle_count": {"value": 2, "source_ref": "p.1", "status": "confirmed"}}},
            "subsystems": [
                {"id": "a", "sim": {"type": "conceptual", "template": None, "student_approved": True}},
                {"id": "b", "sim": {"type": "conceptual", "template": "speed_loop", "student_approved": False}},
            ],
        }
        out = uw.enforce_rules(data)
        self.assertEqual(out["meta"]["platform"]["vehicle_count"]["status"], "extracted")
        self.assertIsNone(out["subsystems"][0]["sim"])
        self.assertTrue(out["subsystems"][1]["sim"]["student_approved"])

    def test_output_schema_strips_review_and_unsupported_keywords(self):
        schema_path = os.path.abspath("wp-content/plugins/unfold/schema/project.schema.json")
        with open(schema_path, encoding="utf-8") as fh:
            schema = json.load(fh)
        reduced = uw.output_schema(schema)
        self.assertNotIn("review", reduced["properties"])
        self.assertNotIn("minLength", json.dumps(reduced))

    def test_pdf_text_rejects_binary_garbage(self):
        noisy_pdf = b"%PDF-1.4\n1 0 obj\n<< /Length 40 >>\nstream\n(\xde\xc7#H \xd8F\xe8'O\x1b`\xc5\xfbOo \xb8\xb8Ze)\nendstream\nendobj\n%%EOF"
        txt = uw.pdf_text(noisy_pdf)
        self.assertEqual(txt, "")

    def test_local_rules_extract_produces_valid_structure(self):
        raw_zip, files = make_sample_zip()
        files[0]["path"] = "report.pdf"
        buf = io.BytesIO(raw_zip)
        with zipfile.ZipFile(buf, "a") as z:
            z.writestr("report.pdf", b"%PDF-1.4\nstream\nBT\n(Warehouse Fleet Control)\nET\nendstream\n%%EOF")
        data, meta = uw.local_rules_extract(buf.getvalue(), files, "report.pdf")
        self.assertEqual(meta["model"], "local_rules")
        self.assertEqual(data["schema_version"], "0.2")
        self.assertEqual(data["data_files"][0]["file"], "speed.csv")
        self.assertEqual(data["data_files"][0]["format"], "csv")
        self.assertEqual(data["data_files"][0]["columns"][0]["name"], "t")
        self.assertEqual(data["results"]["media"][0]["file"], "plot1.png")
        self.assertNotIn("type", data["results"]["media"][0])
        self.assertEqual(data["subsystems"][0]["code_files"], ["src/speed_ctrl.py"])

    def test_local_rules_extract_ast_subsystems_connections_and_docx_sections(self):
        buf = io.BytesIO()
        doc_xml = (
            '<?xml version="1.0" encoding="UTF-8"?>'
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            '<w:p><w:r><w:t>Autonomous QCar Obstacle Avoidance System</w:t></w:r></w:p>'
            '<w:p><w:r><w:t>Abstract</w:t></w:r></w:p>'
            '<w:p><w:r><w:t>This project implements real-time LiDAR obstacle avoidance on QLabs and physical QCar 2 platforms.</w:t></w:r></w:p>'
            '<w:p><w:r><w:t>Objectives</w:t></w:r></w:p>'
            '<w:p><w:r><w:t>1. Detect static obstacles within 1.5 m using LiDAR scans at 10 Hz.</w:t></w:r></w:p>'
            '<w:p><w:r><w:t>Results</w:t></w:r></w:p>'
            '<w:p><w:r><w:t>Success rate: 96%</w:t></w:r></w:p>'
            '</w:body></w:document>'
        )
        docx_buf = io.BytesIO()
        with zipfile.ZipFile(docx_buf, "w") as dz:
            dz.writestr("word/document.xml", doc_xml)

        with zipfile.ZipFile(buf, "w") as z:
            z.writestr("report.docx", docx_buf.getvalue())
            z.writestr("a8_lidar.py", '"""Processes 2D LiDAR scans."""\nLIDAR_RANGE_M = 2.5\n')
            z.writestr("a8_run.py", '"""Main vehicle speed controller."""\nimport a8_lidar\nMAX_SPEED_MPS = 0.6\n')
            z.writestr("make_slides.py", '"""Helper script to generate presentation slides."""\n')
            z.writestr("runs.csv", "t_s,v_mps\n0.0,0.4\n")
            z.writestr("stop_calibration.png", b"PNG")

        files = [
            {"path": "report.docx", "size": 500, "kind": "report", "blocked": False},
            {"path": "a8_lidar.py", "size": 60, "kind": "code", "blocked": False},
            {"path": "a8_run.py", "size": 80, "kind": "code", "blocked": False},
            {"path": "make_slides.py", "size": 50, "kind": "code", "blocked": False},
            {"path": "runs.csv", "size": 30, "kind": "data", "blocked": False},
            {"path": "stop_calibration.png", "size": 10, "kind": "image", "blocked": False},
        ]
        data, _ = uw.local_rules_extract(buf.getvalue(), files, "report.docx")
        sub_ids = [s["id"] for s in data["subsystems"]]
        self.assertIn("perception", sub_ids)
        self.assertIn("control", sub_ids)
        self.assertNotIn("make_slides.py", [f for s in data["subsystems"] for f in s["code_files"]])
        self.assertEqual(data["connections"], [{"from": "perception", "to": "control", "signal": "a8_lidar module data"}])
        self.assertEqual(data["meta"]["platform"]["mode"], "both")
        self.assertIn("python", data["meta"]["platform"]["software_stack"])
        self.assertEqual(data["goal"]["objectives"], ["Detect static obstacles within 1.5 m using LiDAR scans at 10 Hz."])
        self.assertEqual(data["results"]["metrics"][0]["value"]["value"], 96.0)
        self.assertEqual(data["data_files"][0]["format"], "csv")
        self.assertNotIn("type", data["results"]["media"][0])
        for sub in data["subsystems"]:
            self.assertLessEqual(len(sub["name"].split()), 3)

    def test_gemini_client_parses_response(self):
        fake_resp = mock.Mock()
        fake_resp.status_code = 200
        fake_resp.json.return_value = {
            "candidates": [{"content": {"parts": [{"text": '{"schema_version": "0.2", "ok": true}'}]}}],
            "usageMetadata": {"promptTokenCount": 120, "candidatesTokenCount": 30},
        }
        with mock.patch.dict(os.environ, {"GEMINI_API_KEY": "test-key", "UNFOLD_AI_PROVIDER": "gemini"}):
            with mock.patch.object(uw.requests, "post", return_value=fake_resp) as post:
                client = uw.LLMClient("gemini")
                res, meta = client.ask_json("sys", [{"type": "text", "text": "hello"}])
                self.assertTrue(res["ok"])
                self.assertEqual(meta["usage"]["input_tokens"], 120)
                self.assertTrue(post.called)

    def test_ollama_client_parses_response(self):
        fake_resp = mock.Mock()
        fake_resp.status_code = 200
        fake_resp.json.return_value = {
            "message": {"content": '{"schema_version": "0.2", "local": true}'},
            "prompt_eval_count": 80,
            "eval_count": 20,
        }
        with mock.patch.dict(os.environ, {"UNFOLD_AI_PROVIDER": "ollama"}):
            with mock.patch.object(uw.requests, "post", return_value=fake_resp) as post:
                client = uw.LLMClient("ollama")
                res, meta = client.ask_json("sys", [{"type": "text", "text": "hello"}])
                self.assertTrue(res["local"])
                self.assertEqual(meta["usage"]["output_tokens"], 20)
                opts = post.call_args.kwargs["json"]["options"]
                self.assertGreaterEqual(opts["num_ctx"], 8192)
                self.assertEqual(opts["temperature"], 0.1)

    def test_merge_with_skeleton_strips_unknown_root_and_fills_required_sections(self):
        raw_zip, files = make_sample_zip()
        files[0]["path"] = "report.pdf"
        buf = io.BytesIO(raw_zip)
        with zipfile.ZipFile(buf, "a") as z:
            z.writestr("report.pdf", b"%PDF-1.4\nstream\nBT\n(Autonomous Rover)\nET\nendstream\n%%EOF")
        skel, _ = uw.local_rules_extract(buf.getvalue(), files, "report.pdf")

        partial_ai = {
            "project_description": {"project_name": "Autonomous Rover", "project_description": "Clean AI summary."},
            "goal": {"objectives": ["Avoid obstacles cleanly"]},
            "environment": {"map_source": "qlabs_builtin|custom|other|null", "obstacles": "static|dynamic|both|none|null"},
        }
        merged = uw.merge_with_skeleton(partial_ai, skel)
        self.assertNotIn("project_description", merged)
        self.assertEqual(merged["meta"]["title"], "Autonomous Rover")
        self.assertEqual(merged["meta"]["short_description"], "Clean AI summary.")
        self.assertEqual(merged["goal"]["objectives"], ["Avoid obstacles cleanly"])
        self.assertEqual(merged["subsystems"], skel["subsystems"])
        self.assertEqual(merged["data_files"][0]["format"], "csv")
        self.assertNotIn("type", merged["results"]["media"][0])
        self.assertIn(merged["environment"]["map_source"], {"qlabs_builtin", "custom", "physical_track", "other", None})
        self.assertIn(merged["environment"]["obstacles"], {"static", "dynamic", "both", "none", None})

    def test_per_job_credentials_override_environment(self):
        fake_resp = mock.Mock()
        fake_resp.status_code = 200
        fake_resp.json.return_value = {
            "choices": [{"message": {"content": '{"schema_version": "0.2", "byok": true}'}}],
            "usage": {"prompt_tokens": 50, "completion_tokens": 12},
        }
        with mock.patch.dict(os.environ, {"UNFOLD_AI_API_KEY": "env-key", "UNFOLD_AI_BASE_URL": "https://env.example/v1"}):
            with mock.patch.object(uw.requests, "post", return_value=fake_resp) as post:
                client = uw.LLMClient(
                    provider="openai_compat",
                    model="gpt-4o-mini",
                    api_key="job-secret-key",
                    base_url="https://api.openai.com/v1/",
                )
                res, meta = client.ask_json("sys", [{"type": "text", "text": "hello"}])
                self.assertTrue(res["byok"])
                self.assertEqual(meta["model"], "gpt-4o-mini")
                self.assertEqual(post.call_args.args[0], "https://api.openai.com/v1/chat/completions")
                self.assertEqual(post.call_args.kwargs["headers"]["Authorization"], "Bearer job-secret-key")

    def test_handle_switches_to_local_rules_for_quick_extract_job(self):
        raw_zip, files = make_sample_zip()
        files[0]["path"] = "report.pdf"
        buf = io.BytesIO(raw_zip)
        with zipfile.ZipFile(buf, "a") as z:
            z.writestr("report.pdf", b"%PDF-1.4 (Quick Test) %%EOF")

        fake_site = mock.Mock()
        fake_site.zip.return_value = buf.getvalue()
        fake_site.result.return_value = {"schema_errors": [], "attached": 1}

        default_client = uw.LLMClient(provider="gemini", api_key="never-used")
        job = {
            "job_id": "job-1",
            "project_id": 99,
            "report": "report.pdf",
            "files": files,
            "schema": {},
            "ai": {"provider": "local_rules", "model": "local_rules", "base_url": "", "api_key": ""},
        }
        uw.handle(fake_site, default_client, job)
        self.assertTrue(fake_site.result.called)
        payload = fake_site.result.call_args.args[1]
        self.assertEqual(payload["meta"]["model"], "local_rules")
        self.assertEqual(payload["data"]["schema_version"], "0.2")

    def test_build_cover_prompt_uses_required_vector_style(self):
        prompt = uw.build_cover_prompt({
            "meta": {"title": "Autonomous QCar", "short_description": "LiDAR obstacle avoidance"},
        })
        self.assertTrue(prompt.startswith("2D vector illustration of Autonomous QCar - LiDAR obstacle avoidance,"))
        self.assertIn("clean thin grey outlines, soft gradient shading", prompt)
        self.assertIn("isolated on a pure white background.", prompt)

    def test_generate_cover_image_uses_free_fallback_when_no_cloud_image_key(self):
        fake_png = b"\x89PNG\r\n\x1a\n" + b"0" * 2048
        fake_resp = mock.Mock()
        fake_resp.ok = True
        fake_resp.headers = {"Content-Type": "image/png"}
        fake_resp.content = fake_png
        with mock.patch.object(uw.requests, "get", return_value=fake_resp) as get_mock:
            res = uw.generate_cover_image(uw.LLMClient("local_rules"), {"meta": {"title": "Fleet Robot", "short_description": "Multi-robot path planning"}})
            self.assertIsNotNone(res)
            img_bytes, mime, prompt = res
            self.assertEqual(img_bytes, fake_png)
            self.assertEqual(mime, "image/png")
            self.assertIn("Fleet Robot", prompt)
            self.assertTrue(get_mock.called)

    def test_build_context_includes_extra_docs_md_csv_stats_and_deep_code(self):
        docx_buf = io.BytesIO()
        doc_xml = (
            '<?xml version="1.0" encoding="UTF-8"?>'
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            '<w:p><w:r><w:t>Extra appendix DOCX content with sensor specs.</w:t></w:r></w:p>'
            '</w:body></w:document>'
        )
        with zipfile.ZipFile(docx_buf, "w") as dz:
            dz.writestr("word/document.xml", doc_xml)

        py_lines = [f"# line {i}" for i in range(1, 50)]
        py_lines[44] = "DEEP_PARAM_GAIN = 2.75"
        py_src = "\n".join(py_lines)

        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as z:
            z.writestr("main_report.pdf", b"%PDF-1.4\nstream\nBT\n(Main Report Body)\nET\nendstream\n%%EOF")
            z.writestr("appendix_specs.docx", docx_buf.getvalue())
            z.writestr("slides_summary.pdf", b"%PDF-1.4\nstream\nBT\n(Extra PDF Slides Text)\nET\nendstream\n%%EOF")
            z.writestr("README.md", "# Project Notes\nLiDAR runs at 10 Hz.")
            z.writestr("runs.csv", "t,speed_mps\n0.0,0.2\n0.1,0.4\n0.2,0.6\n")
            z.writestr("controller.py", py_src)

        files = [
            {"path": "main_report.pdf", "size": 100, "kind": "report", "blocked": False},
            {"path": "appendix_specs.docx", "size": 200, "kind": "report", "blocked": False},
            {"path": "slides_summary.pdf", "size": 100, "kind": "report", "blocked": False},
            {"path": "README.md", "size": 50, "kind": "code", "blocked": False},
            {"path": "runs.csv", "size": 60, "kind": "data", "blocked": False},
            {"path": "controller.py", "size": 400, "kind": "code", "blocked": False},
        ]
        ctx = uw.build_context(zipfile.ZipFile(io.BytesIO(buf.getvalue())), files, "main_report.pdf")
        self.assertIn("Extra appendix DOCX content with sensor specs.", ctx)
        self.assertIn("Extra PDF Slides Text", ctx)
        self.assertIn("LiDAR runs at 10 Hz.", ctx)
        self.assertIn("estimated_sample_rate_hz: 10.0", ctx)
        self.assertIn("speed_mps: min=0.2, mean=0.4, max=0.6, last=0.6", ctx)
        self.assertIn("DEEP_PARAM_GAIN = 2.75", ctx)

    def test_merge_with_skeleton_rejects_hallucinated_files_and_preserves_ast_subsystems(self):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as z:
            z.writestr("report.pdf", b"%PDF-1.4\nstream\nBT\n(Obstacle Detection Study)\nET\nendstream\n%%EOF")
            z.writestr("src/a8_lidar.py", '"""Processes 2D LiDAR scans."""\nRANGE_M = 2.0\n')
            z.writestr("src/a8_run.py", '"""Vehicle speed and stop-release control."""\nimport a8_lidar\nMAX_V = 0.5\n')
            z.writestr("logs/speed.csv", "t,v_meas,v_ref\n0.0,0.1,0.5\n0.1,0.5,0.5\n")
            z.writestr("figures/plot1.png", b"PNGDATA")
        files = [
            {"path": "report.pdf", "size": 100, "kind": "report", "blocked": False},
            {"path": "src/a8_lidar.py", "size": 50, "kind": "code", "blocked": False},
            {"path": "src/a8_run.py", "size": 60, "kind": "code", "blocked": False},
            {"path": "logs/speed.csv", "size": 50, "kind": "data", "blocked": False},
            {"path": "figures/plot1.png", "size": 7, "kind": "image", "blocked": False},
        ]
        skel, _ = uw.local_rules_extract(buf.getvalue(), files, "report.pdf")
        stub_ai = {
            "meta": {
                "title": "Object Detection and Safe Stopping",
                "question": "Can software fix bent wheels?",
                "short_description": "Continuous clear observation before releasing a stop.",
            },
            "subsystems": [
                {
                    "id": "perception",
                    "name": "Perception",
                    "plain": "Detects obstacles.",
                    "on": ["lidar"],
                    "category": "perception",
                    "method": "Algorithm or null",
                    "code_files": ["file.py"],
                }
            ],
            "connections": [],
            "data_files": [{"file": "runs.csv", "format": "csv", "columns": [{"name": "time", "unit": "s"}]}],
            "results": {
                "metrics": [{"metric": "Metric 1", "value": {"value": 1, "source_ref": "p. 1", "status": "extracted"}, "unit": "%"}],
                "media": [{"file": "plot.png", "caption": "Caption", "part": None}],
                "limitations": ["Limitation 1"],
                "future_work": ["Future improvement 1"],
            },
        }
        merged = uw.merge_with_skeleton(stub_ai, skel, files=files)
        self.assertIsNone(merged["meta"]["question"])
        sub_ids = {s["id"] for s in merged["subsystems"]}
        self.assertIn("perception", sub_ids)
        self.assertIn("control", sub_ids)
        perc = next(s for s in merged["subsystems"] if s["id"] == "perception")
        self.assertIsNone(perc["method"])
        self.assertNotIn("file.py", perc["code_files"])
        self.assertEqual(perc["code_files"], ["src/a8_lidar.py"])
        self.assertTrue(len(merged["connections"]) > 0)
        self.assertEqual([df["file"] for df in merged["data_files"]], ["speed.csv"])
        self.assertEqual([m["file"] for m in merged["results"]["media"]], ["plot1.png"])
        self.assertEqual(merged["results"]["limitations"], [])
        self.assertEqual(merged["results"]["future_work"], [])
        self.assertEqual(merged["results"]["metrics"], [])

    def test_ollama_json_preserves_context_alongside_long_report(self):
        long_report = "<report name=\"report.pdf\">\n" + ("R" * 12_500) + "\n</report>"
        context_part = "Inventory and stats:\n" + ("C" * 500) + "\nEND_CONTEXT_MARKER"
        fake_resp = mock.Mock()
        fake_resp.status_code = 200
        fake_resp.json.return_value = {
            "message": {"content": '{"schema_version": "0.2"}'},
            "prompt_eval_count": 100,
            "eval_count": 10,
        }
        with mock.patch.object(uw.requests, "post", return_value=fake_resp) as post:
            client = uw.LLMClient("ollama")
            client.ask_json("sys", [{"type": "text", "text": long_report}, {"type": "text", "text": context_part}])
            sent_user = post.call_args.kwargs["json"]["messages"][1]["content"]
            self.assertIn("END_CONTEXT_MARKER", sent_user)


if __name__ == "__main__":
    unittest.main()
