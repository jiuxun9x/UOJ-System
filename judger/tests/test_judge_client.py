"""Unit tests for judge_client.

Run with: python3 -m unittest discover -s judger/tests -v
"""

import hashlib
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


class FakeResponse:
    def __init__(self, status_code=200, body=b"", headers=None):
        self.status_code = status_code
        self.body = body
        self.headers = headers or {}
        self.closed = False

    @property
    def text(self):
        return self.body.decode()

    def iter_content(self, chunk_size=65536):
        for i in range(0, len(self.body), chunk_size):
            yield self.body[i : i + chunk_size]

    def close(self):
        self.closed = True


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
        # finding out the versions of the compilers takes a while
        self.jc.judger_identity = {"judger_version": "0123456789abcdef", "toolchain": "{}"}

        self.old_cwd = os.getcwd()
        self.root = tempfile.mkdtemp()
        os.makedirs(os.path.join(self.root, "uoj_judger", "data"))
        os.makedirs(os.path.join(self.root, "uoj_judger", "work"))
        os.makedirs(os.path.join(self.root, "uoj_judger", "result"))
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


class JudgerResultTest(JudgeClientTestCase):
    def read_result(self, content):
        with open(os.path.join(self.root, "uoj_judger", "result", "result.txt"), "wb") as f:
            f.write(content)
        return self.jc.get_judger_result()

    def test_result_is_parsed(self):
        res = self.read_result(b"score 100\ntime 12\nmemory 3456\ndetails\n<tests>\n</tests>\n")
        self.assertEqual(
            res,
            {"score": 100.0, "time": 12, "memory": 3456, "details": "<tests>\n</tests>\n", "status": "Judged"},
        )

    def test_error_is_parsed(self):
        res = self.read_result(b"error Compile Error\ndetails\n<error>oops</error>\n")
        self.assertEqual(res["error"], "Compile Error")
        self.assertEqual(res["details"], "<error>oops</error>\n")

    def test_details_with_binary_data_do_not_fail_the_judgement(self):
        details = b"<tests><test><in>" + BINARY_DATA.replace(b"\x00", b"") + b"</in></test></tests>\n"
        res = self.read_result(b"score 0\ntime 1\nmemory 1\ndetails\n" + details)
        self.assertEqual(res["score"], 0)
        self.assertIn("line1", res["details"])
        # the result has to survive the encoding of the request that reports it
        json.dumps(res, ensure_ascii=False).encode("utf-8")

    def test_details_with_a_character_cut_in_two_do_not_fail_the_judgement(self):
        cut = "I don\u2019t know".encode("utf-8")[:7]
        res = self.read_result(b"score 0\ntime 1\nmemory 1\ndetails\n<out>" + cut + b"</out>\n")
        self.assertEqual(res["details"], "<out>I don\ufffd</out>\n")


class DownloadTest(JudgeClientTestCase):
    def download(self, response):
        target = os.path.join(self.root, "download.bin")
        with mock.patch.object(self.jc.requests, "post", return_value=response) as post:
            result = self.jc.uoj_download("/problem/1", target)
        self.assertEqual(post.call_args[0][0], "http://uoj-web/judge/download/problem/1")
        self.assertEqual(
            post.call_args[1]["data"], {"judger_name": "test_judger", "password": "secret"}
        )
        return target, result

    def test_download_returns_size_and_sha256(self):
        response = FakeResponse(body=BINARY_DATA, headers={"Content-Length": str(len(BINARY_DATA))})
        target, (size, sha256) = self.download(response)
        with open(target, "rb") as f:
            self.assertEqual(f.read(), BINARY_DATA)
        self.assertEqual(size, len(BINARY_DATA))
        self.assertEqual(sha256, hashlib.sha256(BINARY_DATA).hexdigest())
        self.assertTrue(response.closed)

    def test_download_rejects_an_error_page(self):
        response = FakeResponse(status_code=404, body=b"<html>404</html>")
        with self.assertRaisesRegex(Exception, "HTTP 404"):
            self.download(response)
        self.assertTrue(response.closed)

    def test_download_reports_a_rejected_judger(self):
        with self.assertRaises(self.jc.UOJAuthError):
            self.download(FakeResponse(status_code=403, body=b"judger authentication failed"))

    def test_download_rejects_a_truncated_file(self):
        response = FakeResponse(body=BINARY_DATA, headers={"Content-Length": "100000"})
        with self.assertRaisesRegex(Exception, "truncated"):
            self.download(response)

    def test_download_rejects_a_corrupted_file(self):
        response = FakeResponse(body=BINARY_DATA, headers={"X-UOJ-SHA256": "0" * 64})
        with self.assertRaisesRegex(Exception, "corrupted"):
            self.download(response)

    def test_download_accepts_a_matching_sha256(self):
        expected = hashlib.sha256(BINARY_DATA).hexdigest().upper()
        self.download(FakeResponse(body=BINARY_DATA, headers={"X-UOJ-SHA256": expected}))


class InteractTest(JudgeClientTestCase):
    def test_interact_reports_a_rejected_judger(self):
        response = FakeResponse(status_code=403, body=b"judger authentication failed")
        with mock.patch.object(self.jc.requests, "post", return_value=response):
            with self.assertRaises(self.jc.UOJAuthError):
                self.jc.uoj_interact({})

    def test_interact_rejects_an_error_page(self):
        response = FakeResponse(status_code=500, body=b"<html>oops</html>")
        with mock.patch.object(self.jc.requests, "post", return_value=response):
            with self.assertRaisesRegex(Exception, "HTTP 500"):
                self.jc.uoj_interact({})

    def test_nothing_to_judge(self):
        with mock.patch.object(self.jc, "uoj_interact", return_value="Nothing to judge"):
            self.assertFalse(self.jc.send_and_fetch())
        self.assertIsNone(self.jc.submission)

    def test_judger_can_decline_new_work(self):
        self.jc.submission = {"id": 5}
        with mock.patch.object(self.jc, "uoj_interact", return_value="Nothing to judge") as interact:
            self.jc.send_and_fetch(result={"score": 100, "time": 1, "memory": 1, "details": ""}, fetch_new=False)
        # what the web server receives must be false for PHP, which the string "False" is not
        sent = requests.Request("POST", "http://uoj-web/", data=interact.call_args[0][0]).prepare().body
        self.assertIn("fetch_new=0", sent)

    def test_judger_asks_for_new_work_by_default(self):
        with mock.patch.object(self.jc, "uoj_interact", return_value="Nothing to judge") as interact:
            self.jc.send_and_fetch()
        self.assertNotIn("fetch_new", interact.call_args[0][0])

    def test_request_for_work_tells_what_the_judger_is(self):
        with mock.patch.object(self.jc, "uoj_interact", return_value="Nothing to judge") as interact:
            self.jc.send_and_fetch()
        data = interact.call_args[0][0]
        self.assertEqual(data["protocol"], 2)
        self.assertEqual(data["judger_version"], "0123456789abcdef")
        self.assertEqual(data["toolchain"], "{}")

    def test_result_tells_which_data_was_used(self):
        self.jc.submission = {"id": 5, "problem_data_version": 3, "problem_data_sha256": "ab" * 32}
        with mock.patch.object(self.jc, "uoj_interact", return_value="Nothing to judge") as interact:
            self.jc.send_and_fetch(result={"score": 100, "time": 1, "memory": 1, "details": ""})
        data = interact.call_args[0][0]
        self.assertEqual(data["problem_data_version"], 3)
        self.assertEqual(data["problem_data_sha256"], "ab" * 32)

    def test_task_that_could_not_be_done_is_given_back(self):
        self.jc.submission = {"id": 5}
        self.assertEqual(self.jc.give_back_report(), {"requeue": "1", "id": 5})
        self.jc.submission = {"id": 5, "is_custom_test": ""}
        self.assertEqual(self.jc.give_back_report(), {"requeue": "1", "id": 5, "is_custom_test": True})
        self.jc.submission = {"id": 5, "is_hack": "", "hack": {"id": 9}}
        self.assertEqual(self.jc.give_back_report(), {"requeue": "1", "id": 9, "is_hack": True})

        with mock.patch.object(self.jc, "uoj_interact", return_value="Nothing to judge") as interact:
            self.jc.send_and_fetch(fetch_new=False, report=self.jc.give_back_report())
        data = interact.call_args[0][0]
        self.assertEqual(data["requeue"], "1")
        self.assertEqual(data["fetch_new"], "0")
        self.assertNotIn("submit", data)

    def test_web_server_hears_from_the_judger_during_a_task(self):
        import threading

        self.jc.submission = {"prepare": {"id": 1}}
        self.jc.SIGN_OF_LIFE_INTERVAL = 0.05
        with mock.patch.object(self.jc, "uoj_interact", return_value="") as interact:
            result = self.jc.run_task(lambda: threading.Event().wait(0.4) or "done")
            calls = interact.call_count
            self.assertEqual(result, "done")
            self.assertGreaterEqual(calls, 2)
            self.assertEqual(interact.call_args[0][0], {"heartbeat": "1"})
            # and not any more once the task is done
            threading.Event().wait(0.2)
            self.assertEqual(interact.call_count, calls)

    def test_task_that_fails_stops_reporting(self):
        self.jc.submission = {"id": 5}

        def task():
            raise Exception("boom")

        with mock.patch.object(self.jc, "uoj_interact", return_value=""):
            with self.assertRaisesRegex(Exception, "boom"):
                self.jc.run_task(task)
        self.assertTrue(self.jc.task_finished)

    def test_new_submission(self):
        with mock.patch.object(self.jc, "uoj_interact", return_value='{"id": 5, "problem_id": 1}'):
            self.assertTrue(self.jc.send_and_fetch())
        self.assertEqual(self.jc.submission, {"id": 5, "problem_id": 1})

    def test_result_is_kept_until_the_judger_is_accepted(self):
        self.jc.submission = {"id": 5}
        responses = [self.jc.UOJAuthError("rejected"), "Nothing to judge"]
        with mock.patch.object(self.jc, "uoj_interact", side_effect=responses) as interact:
            self.jc.send_and_fetch(result={"score": 100, "time": 1, "memory": 1, "details": ""})
        self.assertEqual(interact.call_count, 2)
        for call in interact.call_args_list:
            self.assertEqual(json.loads(call[0][0]["result"])["score"], 100)
        self.sleep.assert_any_call(30)


class ProblemDataTestCase(JudgeClientTestCase):
    PROBLEM_ID = 42

    def make_zip(self, files=None, with_conf=True):
        """build the archive of a problem the same way the web server does"""
        src_root = tempfile.mkdtemp(dir=self.root)
        src = os.path.join(src_root, str(self.PROBLEM_ID))
        os.mkdir(src)
        files = dict(files or {"input1.txt": b"1 2\n", "output1.txt": b"3\n"})
        if with_conf:
            files.setdefault("problem.conf", b"use_builtin_judger on\nn_tests 1\n")
        for name, content in files.items():
            os.makedirs(os.path.dirname(os.path.join(src, name)), exist_ok=True)
            with open(os.path.join(src, name), "wb") as f:
                f.write(content)
        subprocess.check_call(
            ["zip", "-r", "-q", str(self.PROBLEM_ID) + ".zip", str(self.PROBLEM_ID)], cwd=src_root
        )
        with open(os.path.join(src_root, str(self.PROBLEM_ID) + ".zip"), "rb") as f:
            return f.read()

    def serve(self, *bodies):
        """make uoj_download return the given archives (or raise the given exceptions) in turn"""
        bodies = list(bodies)
        self.requested = []

        def fake_download(uri, filename):
            self.requested.append(uri)
            body = bodies.pop(0) if len(bodies) > 1 else bodies[0]
            if isinstance(body, Exception):
                raise body
            with open(filename, "wb") as f:
                f.write(body)
            return len(body), hashlib.sha256(body).hexdigest()

        patcher = mock.patch.object(self.jc, "uoj_download", side_effect=fake_download)
        download = patcher.start()
        self.addCleanup(patcher.stop)
        return download

    def update(self, version, archive, steps=None):
        """ask for the version of the data that the given archive is"""
        sha256 = hashlib.sha256(archive).hexdigest()
        self.jc.update_problem_data(self.PROBLEM_ID, version, sha256, steps)
        return sha256

    def read_copy(self, name):
        with open(self.data_path(str(self.PROBLEM_ID), name), "rb") as f:
            return f.read()

    def cached_version(self):
        return self.jc.cached_data_version(self.PROBLEM_ID)

    def assert_no_leftovers(self):
        leftovers = [name for name in os.listdir(self.data_path()) if name.startswith(".")]
        self.assertEqual(leftovers, [])


class UpdateProblemDataTest(ProblemDataTestCase):
    def test_first_download(self):
        archive = self.make_zip()
        download = self.serve(archive)
        sha256 = self.update(1, archive)

        self.assertEqual(download.call_count, 1)
        self.assertEqual(self.requested, ["/problem/42/1"])
        self.assertEqual(self.read_copy("input1.txt"), b"1 2\n")
        self.assertEqual(self.cached_version(), {"version": 1, "sha256": sha256})
        self.assertFalse(os.access(self.data_path(str(self.PROBLEM_ID)), os.W_OK))
        self.assert_no_leftovers()

    def test_cached_version_is_not_downloaded_again(self):
        archive = self.make_zip()
        download = self.serve(archive)
        self.update(1, archive)
        self.update(1, archive)
        self.assertEqual(download.call_count, 1)

    def test_other_version_replaces_the_copy(self):
        old = self.make_zip({"input1.txt": b"old\n", "stale.txt": b"stale\n"})
        new = self.make_zip({"input1.txt": b"new\n"})
        download = self.serve(old, new)
        self.update(1, old)
        sha256 = self.update(2, new)

        self.assertEqual(download.call_count, 2)
        self.assertEqual(self.requested, ["/problem/42/1", "/problem/42/2"])
        self.assertEqual(self.read_copy("input1.txt"), b"new\n")
        self.assertFalse(os.path.exists(self.data_path(str(self.PROBLEM_ID), "stale.txt")))
        self.assertEqual(self.cached_version(), {"version": 2, "sha256": sha256})
        self.assert_no_leftovers()

    def test_same_number_with_other_content_replaces_the_copy(self):
        # the data of a problem was cleared and its versions start again
        old = self.make_zip({"input1.txt": b"old\n"})
        new = self.make_zip({"input1.txt": b"new\n"})
        download = self.serve(old, new)
        self.update(1, old)
        self.update(1, new)
        self.assertEqual(download.call_count, 2)
        self.assertEqual(self.read_copy("input1.txt"), b"new\n")

    def test_copy_of_an_unknown_version_is_replaced(self):
        # a copy made before versions were recorded
        os.makedirs(self.data_path(str(self.PROBLEM_ID)))
        with open(self.data_path(str(self.PROBLEM_ID), "input1.txt"), "wb") as f:
            f.write(b"unknown\n")
        archive = self.make_zip()
        download = self.serve(archive)
        self.update(1, archive)
        self.assertEqual(download.call_count, 1)
        self.assertEqual(self.read_copy("input1.txt"), b"1 2\n")

    def test_failed_download_keeps_the_old_copy_and_fails(self):
        old = self.make_zip({"input1.txt": b"old\n"})
        download = self.serve(old, Exception("HTTP 404"))
        sha256 = self.update(1, old)
        with self.assertRaisesRegex(Exception, "HTTP 404"):
            self.jc.update_problem_data(self.PROBLEM_ID, 2, "0" * 64, None)

        self.assertEqual(download.call_count, 1 + self.jc.PROBLEM_DATA_DOWNLOAD_TRIES)
        self.assertEqual(self.read_copy("input1.txt"), b"old\n")
        # the old copy is still known as what it is, so the next submission tries again
        self.assertEqual(self.cached_version(), {"version": 1, "sha256": sha256})
        self.assert_no_leftovers()

    def test_broken_archive_is_never_used(self):
        archive = self.make_zip()[:100]
        self.serve(archive)
        with self.assertRaises(Exception):
            self.update(1, archive)

        self.assertFalse(os.path.exists(self.data_path(str(self.PROBLEM_ID))))
        self.assertIsNone(self.cached_version())
        self.assert_no_leftovers()

    def test_archive_without_problem_conf_is_never_used(self):
        archive = self.make_zip(with_conf=False)
        self.serve(archive)
        with self.assertRaisesRegex(Exception, "problem.conf is missing"):
            self.update(1, archive)
        self.assertFalse(os.path.exists(self.data_path(str(self.PROBLEM_ID))))

    def test_archive_of_another_version_is_never_used(self):
        self.serve(self.make_zip({"input1.txt": b"other\n"}))
        with self.assertRaisesRegex(Exception, "is not version 2"):
            self.update(2, self.make_zip({"input1.txt": b"wanted\n"}))
        self.assertFalse(os.path.exists(self.data_path(str(self.PROBLEM_ID))))

    def test_download_is_retried_while_the_web_server_is_publishing(self):
        old = self.make_zip({"input1.txt": b"old\n"})
        new = self.make_zip({"input1.txt": b"new\n"})
        download = self.serve(old, new)
        self.update(2, new)
        self.assertEqual(download.call_count, 2)
        self.assertEqual(self.read_copy("input1.txt"), b"new\n")

    def test_missing_data_on_the_web_server_fails_without_a_download(self):
        download = self.serve(self.make_zip())
        with self.assertRaisesRegex(Exception, "has no data"):
            self.jc.update_problem_data(self.PROBLEM_ID, None, None, None)
        self.assertEqual(download.call_count, 0)

    def test_leftovers_of_an_interrupted_update_are_removed(self):
        os.makedirs(self.data_path(".tmp_42_abc", "extract", "42"))
        archive = self.make_zip()
        self.serve(archive)
        self.update(1, archive)
        self.assert_no_leftovers()

    def test_data_of_the_problems_used_last_is_kept(self):
        for problem_id in range(1, 121):
            os.makedirs(self.data_path(str(problem_id)))
            with open(self.data_path("%d.version" % problem_id), "w") as f:
                f.write("{}")
            os.utime(self.data_path(str(problem_id)), (1000000 + problem_id, 1000000))
        self.jc.evict_problem_data("1")

        kept = sorted(int(name) for name in os.listdir(self.data_path()) if name.isdigit())
        self.assertEqual(kept, [1] + list(range(22, 121)))
        versions = sorted(int(name[:-8]) for name in os.listdir(self.data_path()) if name.endswith(".version"))
        self.assertEqual(versions, kept)


class BuildProblemProgramsTest(ProblemDataTestCase):
    """the programs that come with the data of a problem are built by the judger"""

    STEPS = [
        {"type": "compile", "name": "chk", "include": True},
        {"type": "compile", "name": "std", "impl": "implementer", "path": "require"},
        {"type": "make"},
    ]

    def test_steps_run_in_the_sandbox_of_the_compiler(self):
        archive = self.make_zip({"chk.cpp": b"int main() {}\n", "require/implementer.cpp": b"\n"})
        self.serve(archive)
        with mock.patch.object(self.jc, "run_compiler") as run_compiler:
            self.update(1, archive, self.STEPS)

        main_path = os.path.abspath("uoj_judger")
        calls = [call[0] for call in run_compiler.call_args_list]
        self.assertEqual(len(calls), 3)
        for scratch_dir, work_path, _, _, _ in calls:
            # everything is built before the data is moved to its place
            self.assertIn(".tmp_42_", scratch_dir)
            self.assertIn(".tmp_42_", work_path)
        self.assertEqual(calls[0][2:], (15, [main_path + "/run/compile", "--cinclude=" + main_path + "/include", "chk"], "chk"))
        self.assertTrue(calls[0][1].endswith("/extract/42"))
        self.assertEqual(calls[1][2:], (15, [main_path + "/run/compile", "--impl=implementer", "../std"], "std"))
        self.assertTrue(calls[1][1].endswith("/extract/42/require"))
        self.assertEqual(calls[2][2:], (60, ["/usr/bin/make", "INCLUDE_PATH=" + main_path + "/include"], "Makefile"))
        self.assertEqual(self.read_copy("chk.cpp"), b"int main() {}\n")

    def test_data_whose_programs_do_not_build_is_never_used(self):
        old = self.make_zip({"input1.txt": b"old\n"})
        new = self.make_zip({"chk.cpp": b"broken\n"})
        download = self.serve(old, new)
        sha256 = self.update(1, old)
        error = self.jc.PrepareError("chk: compile error\nexpected ';'")
        with mock.patch.object(self.jc, "run_compiler", side_effect=error):
            with self.assertRaises(self.jc.PrepareError):
                self.update(2, new, self.STEPS)

        # building again would fail again, so the data is not downloaded again either
        self.assertEqual(download.call_count, 2)
        self.assertEqual(self.read_copy("input1.txt"), b"old\n")
        self.assertEqual(self.cached_version(), {"version": 1, "sha256": sha256})
        self.assert_no_leftovers()

    def test_only_known_steps_are_run(self):
        for step in (
            {"type": "shell", "command": "rm -rf /"},
            {"type": "compile", "name": "../../evil"},
            {"type": "compile", "name": "chk", "path": "/etc"},
            {"type": "compile", "name": "chk", "impl": "; rm -rf /"},
        ):
            with mock.patch.object(self.jc, "run_compiler") as run_compiler:
                with self.assertRaises(self.jc.PrepareError):
                    self.jc.build_problem_programs(self.root, [step], self.root)
            self.assertEqual(run_compiler.call_count, 0)

    def run_compiler_with(self, result, message=b"", overloaded=False):
        """run run_compiler with a sandbox that writes the given result"""

        def fake_call(command, **kwargs):
            options = dict(arg[2:].split("=", 1) for arg in command if arg.startswith("--") and "=" in arg)
            if result is not None:
                with open(options["res"], "w") as f:
                    f.write(result)
            with open(options["err"], "wb") as f:
                f.write(message)
            if overloaded:
                open(os.path.join(os.path.dirname(options["res"]), "uoj_overloaded"), "w").close()
            self.command = command
            return 0

        with mock.patch.object(self.jc.subprocess, "call", side_effect=fake_call):
            self.jc.run_compiler(self.root, self.root, 15, ["compile", "chk"], "chk")

    def test_compiler_runs_in_the_sandbox(self):
        self.run_compiler_with("0 100 2000 0\n\n")
        main_path = os.path.abspath("uoj_judger")
        self.assertEqual(self.command[0], main_path + "/run/run_program")
        for option in ("--type=compiler", "--tl=15", "--in=/dev/null", "--work-path=" + self.root):
            self.assertIn(option, self.command)
        self.assertEqual(self.command[-2:], ["compile", "chk"])

    def test_compile_error_is_reported_with_the_message_of_the_compiler(self):
        with self.assertRaisesRegex(self.jc.PrepareError, "chk: compile error\nexpected ';' before"):
            self.run_compiler_with("0 100 2000 1\n\n", b"expected ';' before '}' token \xff")

    def test_compiler_that_exceeds_its_limits_is_reported(self):
        with self.assertRaisesRegex(self.jc.PrepareError, "Compiler Time Limit Exceeded"):
            self.run_compiler_with("4 -1 -1 -1\n\n")

    def test_compiler_that_could_not_run_is_not_a_compile_error(self):
        for kwargs in ({"result": None}, {"result": "7 -1 -1 -1\n", "overloaded": True}):
            with self.assertRaises(Exception) as caught:
                self.run_compiler_with(**kwargs)
            self.assertNotIsInstance(caught.exception, self.jc.PrepareError)

    def test_build_task_reports_whether_the_data_can_be_used(self):
        archive = self.make_zip()
        self.serve(archive)
        self.jc.submission = {
            "prepare": {"id": 7},
            "problem_id": self.PROBLEM_ID,
            "problem_data_version": 3,
            "problem_data_sha256": hashlib.sha256(archive).hexdigest(),
            "problem_data_prepare": self.STEPS,
        }
        with mock.patch.object(self.jc, "run_compiler"):
            self.assertEqual(self.jc.prepare(), {"prepare_result": "1", "id": 7, "ok": "1", "message": ""})

        self.jc.submission["problem_data_version"] = 4
        error = self.jc.PrepareError("chk: compile error\nexpected ';'")
        with mock.patch.object(self.jc, "run_compiler", side_effect=error):
            with mock.patch.object(self.jc, "cached_data_version", return_value=None):
                report = self.jc.prepare()
        self.assertEqual(report["ok"], "0")
        self.assertEqual(report["message"], "chk: compile error\nexpected ';'")


if __name__ == "__main__":
    unittest.main()
