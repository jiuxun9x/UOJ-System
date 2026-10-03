"""Unit tests for judge_client.

Run with: python3 -m unittest discover -s judger/tests -v
"""

import importlib.machinery
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

import requests

sys.dont_write_bytecode = True

JUDGE_CLIENT_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "judge_client")

# bytes that a text mode reader would reject or rewrite
BINARY_DATA = b"\x00\xff\xfe\x80line1\r\nline2\rline3\n\xc3\x28 trailing  \r\n\x00"


def load_judge_client():
    loader = importlib.machinery.SourceFileLoader("judge_client", JUDGE_CLIENT_PATH)
    spec = importlib.util.spec_from_loader("judge_client", loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class JudgeClientTestCase(unittest.TestCase):
    def setUp(self):
        self.jc = load_judge_client()
        self.jc.jconf = {
            "uoj_protocol": "http",
            "uoj_host": "uoj-web",
            "judger_name": "test_judger",
            "judger_password": "secret",
            "socket_port": 2333,
            "socket_password": "socket_secret",
        }

        self.old_cwd = os.getcwd()
        self.root = tempfile.mkdtemp()
        os.makedirs(os.path.join(self.root, "uoj_judger", "data"))
        os.makedirs(os.path.join(self.root, "uoj_judger", "work"))
        os.chdir(self.root)

        patcher = mock.patch.object(self.jc.time, "sleep")
        self.sleep = patcher.start()
        self.addCleanup(patcher.stop)

    def tearDown(self):
        os.chdir(self.old_cwd)
        subprocess.call(["chmod", "-R", "700", self.root])
        shutil.rmtree(self.root)

    def data_path(self, *names):
        return os.path.join(self.root, "uoj_judger", "data", *names)

    def work_path(self, *names):
        return os.path.join(self.root, "uoj_judger", "work", *names)


class HackFilesTest(JudgeClientTestCase):
    def write_hack_files(self):
        with open(self.work_path("hack_input.txt"), "wb") as f:
            f.write(BINARY_DATA)
        with open(self.work_path("std_output.txt"), "wb") as f:
            f.write(BINARY_DATA[::-1])

    def test_hack_files_are_read_as_binary(self):
        self.write_hack_files()
        files = self.jc.read_hack_files()
        self.assertEqual(files["hack_input"], ("hack_input.txt", BINARY_DATA))
        self.assertEqual(files["std_output"], ("std_output.txt", BINARY_DATA[::-1]))

    def test_hack_files_survive_the_multipart_encoding_twice(self):
        self.write_hack_files()
        files = self.jc.read_hack_files()
        for _ in range(2):
            body = requests.Request(
                "POST", "http://uoj-web/judge/submit", data={"id": 1}, files=files
            ).prepare().body
            self.assertIn(BINARY_DATA, body)
            self.assertIn(BINARY_DATA[::-1], body)

    def test_hack_files_are_sent_again_when_the_request_is_retried(self):
        self.write_hack_files()
        self.jc.submission = {"is_hack": "", "hack": {"id": 7}}
        sent = []

        def fake_interact(data, files={}):
            sent.append((dict(data), files))
            if len(sent) == 1:
                raise requests.ConnectionError("connection reset")
            return "Nothing to judge"

        with mock.patch.object(self.jc, "uoj_interact", side_effect=fake_interact):
            self.jc.send_and_fetch(result={"score": 1, "time": 0, "memory": 0, "details": ""})

        self.assertEqual(len(sent), 2)
        for data, files in sent:
            self.assertEqual(data["id"], 7)
            self.assertTrue(data["is_hack"])
            self.assertEqual(files["hack_input"][1], BINARY_DATA)
            self.assertEqual(files["std_output"][1], BINARY_DATA[::-1])

    def test_failed_hack_sends_no_files(self):
        self.jc.submission = {"is_hack": "", "hack": {"id": 7}}
        with mock.patch.object(
            self.jc, "uoj_interact", return_value="Nothing to judge"
        ) as interact:
            self.jc.send_and_fetch(result={"score": 0, "time": 0, "memory": 0, "details": ""})
        self.assertEqual(interact.call_args[0][1], {})

    def test_unreadable_hack_files_report_a_failed_judgement(self):
        self.jc.submission = {"is_hack": "", "hack": {"id": 7}}
        with mock.patch.object(
            self.jc, "uoj_interact", return_value="Nothing to judge"
        ) as interact:
            self.jc.send_and_fetch(result={"score": 1, "time": 0, "memory": 0, "details": ""})
        data, files = interact.call_args[0]
        self.assertEqual(files, {})
        self.assertEqual(json.loads(data["result"])["error"], "Judgement Failed")


if __name__ == "__main__":
    unittest.main()
