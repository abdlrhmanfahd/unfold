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

    def test_local_rules_extract_produces_valid_structure(self):
        raw_zip, files = make_sample_zip()
        # Use a PDF name so docx_text is not called on dummy bytes
        files[0]["path"] = "report.pdf"
        buf = io.BytesIO(raw_zip)
        with zipfile.ZipFile(buf, "a") as z:
            z.writestr("report.pdf", b"%PDF-1.4 (Warehouse Fleet Control) %%EOF")
        data, meta = uw.local_rules_extract(buf.getvalue(), files, "report.pdf")
        self.assertEqual(meta["model"], "local_rules")
        self.assertEqual(data["schema_version"], "0.2")
        self.assertEqual(data["data_files"][0]["file"], "speed.csv")
        self.assertEqual(data["data_files"][0]["columns"][0]["name"], "t")
        self.assertEqual(data["results"]["media"][0]["file"], "plot1.png")
        self.assertEqual(data["subsystems"][0]["code_files"], ["src/speed_ctrl.py"])

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
            with mock.patch.object(uw.requests, "post", return_value=fake_resp):
                client = uw.LLMClient("ollama")
                res, meta = client.ask_json("sys", [{"type": "text", "text": "hello"}])
                self.assertTrue(res["local"])
                self.assertEqual(meta["usage"]["output_tokens"], 20)

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


if __name__ == "__main__":
    unittest.main()
