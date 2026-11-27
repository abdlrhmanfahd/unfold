import os
import shutil
import sys
import tempfile
import unittest
import zipfile
from unittest import mock

sys.path.insert(0, os.path.abspath("workers/world"))
import world_worker as ww  # noqa: E402


class WorldWorkerTests(unittest.TestCase):
    def test_site_builds_rest_route_url(self):
        s = ww.Site("http://localhost:8881/", "tok")
        self.assertEqual(s.base, "http://localhost:8881")
        self.assertEqual(s.token, "tok")

    def test_extract_session_prevents_traversal_and_requires_bag(self):
        tmp = tempfile.mkdtemp(prefix="ww-test-")
        try:
            zpath = os.path.join(tmp, "drive.zip")
            with zipfile.ZipFile(zpath, "w") as z:
                z.writestr("session1/bag/bag_0.db3", b"ROS2BAG")
                z.writestr("session1/../evil.txt", b"BAD")
                z.writestr("session1/notes.txt", b"ok")

            dest = os.path.join(tmp, "out")
            os.makedirs(dest, exist_ok=True)
            ww.extract_session(zpath, "session1", dest)
            self.assertTrue(os.path.isfile(os.path.join(dest, "bag", "bag_0.db3")))
            self.assertTrue(os.path.isfile(os.path.join(dest, "notes.txt")))
            self.assertFalse(os.path.exists(os.path.join(tmp, "evil.txt")))

            bad_zip = os.path.join(tmp, "nobag.zip")
            with zipfile.ZipFile(bad_zip, "w") as z:
                z.writestr("notes.txt", b"only notes")
            dest2 = os.path.join(tmp, "out2")
            os.makedirs(dest2, exist_ok=True)
            with self.assertRaises(RuntimeError):
                ww.extract_session(bad_zip, "", dest2)
        finally:
            shutil.rmtree(tmp, ignore_errors=True)

    def test_read_token_file_finds_script_adjacent_token(self):
        tok_path = os.path.join(os.path.abspath("workers/world"), "worker-token.txt")
        existed = os.path.exists(tok_path)
        tmp = tempfile.mkdtemp(prefix="ww-env-")
        try:
            if not existed:
                with open(tok_path, "w", encoding="utf-8") as fh:
                    fh.write("ufw_testtoken123\n")
                with mock.patch.dict(os.environ, {"LOCALAPPDATA": tmp}):
                    self.assertEqual(ww.read_token_file(), "ufw_testtoken123")
        finally:
            shutil.rmtree(tmp, ignore_errors=True)
            if not existed and os.path.exists(tok_path):
                os.unlink(tok_path)


if __name__ == "__main__":
    unittest.main()
