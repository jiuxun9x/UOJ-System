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
import time
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
        self.jc.jconf["data_cache_problems"] = 100
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


    def test_how_much_data_is_kept_is_a_setting(self):
        self.assertEqual(self.jc.data_cache_limit(), self.jc.DATA_CACHE_PROBLEMS)
        for value, limit in ((500, 500), (2, 2), (1, 300), (0, 300), (-5, 300), ("many", 300), (True, 300), (2.5, 300), (None, 300)):
            self.jc.jconf["data_cache_problems"] = value
            self.assertEqual(self.jc.data_cache_limit(), limit, value)
        self.jc.jconf["data_cache_problems"] = 3
        for problem_id in range(1, 7):
            os.makedirs(self.data_path(str(problem_id)))
            os.utime(self.data_path(str(problem_id)), (1000000 + problem_id, 1000000))
        self.jc.evict_problem_data("1")
        self.assertEqual(sorted(name for name in os.listdir(self.data_path()) if name.isdigit()), ["1", "5", "6"])


class DataSyncTest(ProblemDataTestCase):
    """a judger with nothing to judge fetches the data of the problems before it is needed"""

    SHA = {n: ("%02d" % n) * 32 for n in range(1, 10)}

    def version(self, problem_id, version=1, steps=None, serial=None):
        # the later a version was published, the larger its number: here, the problem's
        return (problem_id, version, self.SHA[problem_id], steps, 10 * problem_id + version if serial is None else serial)

    def held(self, problem_id, version=1):
        return {"version": version, "sha256": self.SHA[problem_id]}

    def plan(self, versions, cached, limit=10, failed=None, now=10000, seen=None):
        plan, have = self.jc.plan_data_sync(versions, cached, limit, failed or {}, now, seen)
        return [row[0] for row in plan], have

    def test_what_is_not_held_is_fetched_the_newest_first(self):
        versions = [self.version(n) for n in (5, 4, 3, 2, 1)]
        self.assertEqual(self.plan(versions, {}), ([5, 4, 3, 2, 1], 0))
        # what is held and current is counted, and left alone
        self.assertEqual(self.plan(versions, {4: self.held(4), 2: self.held(2)}), ([5, 3, 1], 2))
        self.assertEqual(self.plan(versions, {n: self.held(n) for n in range(1, 6)}), ([], 5))
        self.assertEqual(self.plan([], {1: self.held(1)}), ([], 0))

    def test_what_is_held_is_kept_up_to_date(self):
        versions = [self.version(2, version=3), self.version(1)]
        # another version, the same number with other content, and a copy nobody knows the version of
        self.assertEqual(self.plan(versions, {2: self.held(2, version=2), 1: self.held(1)}), ([2], 1))
        self.assertEqual(self.plan(versions, {2: {"version": 3, "sha256": "f" * 64}, 1: self.held(1)}), ([2], 1))
        self.assertEqual(self.plan(versions, {2: None, 1: self.held(1)}), ([2], 1))

    def test_fetching_ahead_fills_the_room_there_is_and_no_more(self):
        versions = [self.version(n) for n in (5, 4, 3, 2, 1)]
        self.assertEqual(self.plan(versions, {}, limit=3), ([5, 4, 3], 0))
        # data that was judged with is not pushed out by data nobody asked for
        others = {n: self.held(1) for n in (101, 102)}
        self.assertEqual(self.plan(versions, others, limit=3), ([5], 0))
        self.assertEqual(self.plan(versions, {101: self.held(1), 102: self.held(1), 103: None}, limit=3), ([], 0))
        # what is held takes no new room: it is refreshed however full the judger is
        full = {101: self.held(1), 102: self.held(1), 4: self.held(4, version=9)}
        self.assertEqual(self.plan(versions, full, limit=3), ([4], 0))

    def test_full_judger_still_fetches_what_is_published_from_then_on(self):
        versions = [self.version(n) for n in (5, 4, 3, 2, 1)]
        full = {101: self.held(1), 102: self.held(1), 103: self.held(1)}
        # told for the first time: there is no room, and nothing is new to it
        self.assertEqual(self.plan(versions, full, limit=3, seen=None), ([], 0))
        # what was published since it last looked is fetched, whatever that pushes out;
        # it does not go back for the older data it had no room for
        self.assertEqual(self.plan(versions, full, limit=3, seen=31), ([5, 4], 0))
        self.assertEqual(self.plan(versions, full, limit=3, seen=51), ([], 0))
        # with room for one, the newest takes it, new or not; what is new comes besides
        self.assertEqual(self.plan(versions, {101: self.held(1), 102: self.held(1)}, limit=3, seen=31), ([5, 4], 0))
        self.assertEqual(self.plan(versions, {101: self.held(1), 102: self.held(1)}, limit=3, seen=51), ([5], 0))

    def test_what_could_not_be_fetched_is_left_for_a_while(self):
        versions = [self.version(2), self.version(1)]
        now = 10000
        just_now = {(2, 1): now - 5}
        self.assertEqual(self.plan(versions, {}, failed=just_now, now=now), ([1], 0))
        long_ago = {(2, 1): now - self.jc.DATA_SYNC_RETRY_AFTER - 1}
        self.assertEqual(self.plan(versions, {}, failed=long_ago, now=now), ([2, 1], 0))
        # another version of the problem is another thing to try
        self.assertEqual(self.plan([self.version(2, version=2)], {}, failed=just_now, now=now), ([2], 0))
        # and what failed does not take the room of what can be fetched
        self.assertEqual(self.plan(versions, {}, limit=1, failed=just_now, now=now), ([1], 0))

    def test_only_an_answer_that_can_be_relied_on_is_used(self):
        answer = {"versions": [[3, 2, "a" * 64, [{"type": "make"}], 9], [2, 1, "b" * 64, None, 8], ["4", 1, "c" * 64, None, 7], [5, True, "d" * 64, None, 6],
                               [6, 1, 7, None, 5], [7, 1, "e" * 64, "make", 4], [8, 1, "f" * 64, None, "3"]]}  # fmt: skip
        self.assertEqual(self.jc.read_data_versions(json.dumps(answer)), [(3, 2, "a" * 64, [{"type": "make"}], 9), (2, 1, "b" * 64, None, 8)])
        for nonsense in ("Nothing to judge", "{}", '{"versions": [[1, 2]]}', '{"versions": [[1, 2, "a", null]]}', ""):
            with self.assertRaises(Exception):
                self.jc.read_data_versions(nonsense)

    def sync(self, answers, fetched=None, fails=()):
        """run steps of fetching ahead against a web server that gives these answers"""
        asked = []

        def interact(data, files={}):
            asked.append(dict(data))
            answer = answers[min(len(asked), len(answers)) - 1]
            if isinstance(answer, Exception):
                raise answer
            return json.dumps({"versions": [list(row) for row in answer]})

        def update(problem_id, version, sha256, steps):
            if problem_id in fails:
                raise Exception("no data today")
            os.makedirs(self.data_path(str(problem_id)), exist_ok=True)
            with open(self.data_path("%d.version" % problem_id), "w") as f:
                json.dump({"version": version, "sha256": sha256}, f)
            if fetched is not None:
                fetched.append((problem_id, version, steps))

        return asked, mock.patch.object(self.jc, "uoj_interact", side_effect=interact), mock.patch.object(self.jc, "update_problem_data", side_effect=update)

    def test_idle_judger_asks_and_fetches_one_problem_at_a_time(self):
        fetched = []
        steps = [{"type": "compile", "name": "chk", "include": True}]
        asked, interact, update = self.sync([[self.version(3, steps=steps), self.version(2), self.version(1)]], fetched)
        with interact, update:
            # one problem a step, so that the judger looks for work in between
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertEqual(fetched, [(3, 1, steps)])
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertEqual([row[0] for row in fetched], [3, 2, 1])
            # nothing left: the judger rests, and does not ask again before it is time
            self.assertFalse(self.jc.sync_data_ahead())
            self.assertEqual(asked, [{"data_versions": "1", "fetch_new": "0"}])
            # when it asks again, it says how much it holds of what there was
            self.jc.data_sync_asked_at -= self.jc.DATA_SYNC_INTERVAL
            self.assertFalse(self.jc.sync_data_ahead())
            self.assertEqual(asked[1], {"data_versions": "1", "fetch_new": "0", "data_have": 3, "data_total": 3})
            self.assertEqual(len(fetched), 3)

    def test_new_data_is_fetched_when_the_judger_next_asks(self):
        fetched = []
        asked, interact, update = self.sync([[self.version(1)], [self.version(1, version=2), self.version(2)]], fetched)
        with interact, update:
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertFalse(self.jc.sync_data_ahead())
            self.jc.data_sync_asked_at -= self.jc.DATA_SYNC_INTERVAL
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertEqual([row[:2] for row in fetched], [(1, 1), (1, 2), (2, 1)])
            self.assertEqual(asked[1], {"data_versions": "1", "fetch_new": "0", "data_have": 1, "data_total": 1})

    def test_full_judger_is_told_of_new_data_once(self):
        self.jc.jconf["data_cache_problems"] = 2
        fetched = []
        first = [self.version(2), self.version(1)]
        later = [self.version(4), self.version(3)] + first
        asked, interact, update = self.sync([first, later, later], fetched)
        with interact, update:
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertEqual(self.jc.data_sync_seen, 21)
            # full now; two problems are published, and are fetched all the same
            self.jc.data_sync_asked_at -= self.jc.DATA_SYNC_INTERVAL
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertEqual([row[0] for row in fetched], [2, 1, 4, 3])
            # (the judger that is told of them pushes out what was used longest ago: here that
            # is left to the function that fetches, which this test replaces)
            self.assertEqual(self.jc.data_sync_seen, 41)

    def test_data_that_can_not_be_fetched_does_not_stop_the_rest(self):
        fetched = []
        asked, interact, update = self.sync([[self.version(3), self.version(2), self.version(1)]], fetched, fails=(3,))
        with interact, update:
            self.assertFalse(self.jc.sync_data_ahead())
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertTrue(self.jc.sync_data_ahead())
            self.assertEqual([row[0] for row in fetched], [2, 1])
            # it is not tried again at once, and is not counted as held
            self.jc.data_sync_asked_at -= self.jc.DATA_SYNC_INTERVAL
            self.assertFalse(self.jc.sync_data_ahead())
            self.assertEqual(asked[1], {"data_versions": "1", "fetch_new": "0", "data_have": 2, "data_total": 3})
            self.assertEqual(len(fetched), 2)

    def test_web_server_that_does_not_answer_is_asked_again_later(self):
        asked, interact, update = self.sync([Exception("HTTP 502"), [self.version(1)]], [])
        with interact, update, mock.patch("traceback.print_exc"):
            self.assertFalse(self.jc.sync_data_ahead())
            self.assertFalse(self.jc.sync_data_ahead())
            self.assertEqual(len(asked), 1)
            self.jc.data_sync_asked_at -= self.jc.DATA_SYNC_INTERVAL
            self.assertTrue(self.jc.sync_data_ahead())

    def test_fetching_ahead_can_be_switched_off(self):
        self.jc.jconf["data_sync"] = False
        asked, interact, update = self.sync([[self.version(1)]], [])
        with interact, update:
            self.assertFalse(self.jc.sync_data_ahead())
        self.assertEqual(asked, [])


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

    # ---- programs that were built before are not built again

    def build(self, files, steps=None, binaries=True):
        """unpack the files as the data of a problem and build its programs; the compiler is
        one that writes down what it was asked for. Returns the folder and the builds done."""
        data_dir = tempfile.mkdtemp(dir=self.root)
        for name, content in files.items():
            os.makedirs(os.path.dirname(os.path.join(data_dir, name)), exist_ok=True)
            with open(os.path.join(data_dir, name), "wb") as f:
                f.write(content)
        built = []

        def compiler(scratch_dir, work_path, time_limit, command, what):
            built.append(what)
            if binaries:
                with open(os.path.join(data_dir, what), "wb") as f:
                    f.write(b"binary of " + what.encode() + b" #%d" % self.builds)
                self.builds += 1

        with mock.patch.object(self.jc, "run_compiler", side_effect=compiler):
            self.jc.build_problem_programs(data_dir, steps or [{"type": "compile", "name": "chk", "include": True}], self.root)
        return data_dir, built

    def read(self, data_dir, name):
        with open(os.path.join(data_dir, name), "rb") as f:
            return f.read()

    CHECKER = b'#include "testlib.h"\n#include "tables.h"\nint main() {}\n'

    def cache_set_up(self):
        self.builds = 0
        os.makedirs(os.path.join(self.root, "uoj_judger", "include"))
        with open(os.path.join(self.root, "uoj_judger", "include", "testlib.h"), "wb") as f:
            f.write(b"// testlib\n")
        self.jc.include_digest = None

    def test_program_that_did_not_change_is_not_built_again(self):
        self.cache_set_up()
        files = {"chk.cpp": self.CHECKER, "tables.h": b"int t[] = {1};\n", "input1.txt": b"1\n"}
        first, built = self.build(files)
        self.assertEqual(built, ["chk"])
        # the next version of the data has other tests and the same checker
        second, built = self.build(dict(files, **{"input1.txt": b"2\n", "input2.txt": b"3\n"}))
        self.assertEqual(built, [])
        self.assertEqual(self.read(second, "chk"), self.read(first, "chk"))
        self.assertTrue(os.access(os.path.join(second, "chk"), os.X_OK))

    def test_program_is_built_again_when_anything_it_is_made_of_changes(self):
        self.cache_set_up()
        files = {"chk.cpp": self.CHECKER, "tables.h": b"int t[] = {1};\n", "sub/deep.h": b"// deep\n"}
        files["tables.h"] += b'#include "sub/deep.h"\n'
        self.assertEqual(self.build(files)[1], ["chk"])
        self.assertEqual(self.build(files)[1], [])
        changed = {
            "the source": {"chk.cpp": self.CHECKER + b"// changed\n"},
            "a header it includes": {"tables.h": files["tables.h"] + b"// changed\n"},
            "a header a header includes": {"sub/deep.h": b"// changed\n"},
        }
        for what, change in changed.items():
            self.assertEqual(self.build(dict(files, **change))[1], ["chk"], what)
        # the headers of the judger
        with open(os.path.join(self.root, "uoj_judger", "include", "testlib.h"), "ab") as f:
            f.write(b"// a newer testlib\n")
        self.jc.include_digest = None
        self.assertEqual(self.build(files)[1], ["chk"], "testlib.h")
        # the compilers
        self.jc.judger_identity = {"judger_version": "fedcba9876543210", "toolchain": '{"g++": "15"}'}
        self.assertEqual(self.build(files)[1], ["chk"], "the toolchain")
        # how it is built
        self.assertEqual(self.build(files, [{"type": "compile", "name": "chk"}])[1], ["chk"], "the flags")
        # and what it is called: the validator is not the checker, whatever is in it
        self.assertEqual(self.build({"val.cpp": self.CHECKER, "tables.h": files["tables.h"], "sub/deep.h": files["sub/deep.h"]},
                                    [{"type": "compile", "name": "val", "include": True}])[1], ["val"])  # fmt: skip
        # none of which forgot what was built for the checker as it is now
        self.assertEqual(self.build(files)[1], [])

    def test_solution_with_an_implementer_depends_on_both(self):
        self.cache_set_up()
        step = [{"type": "compile", "name": "std", "impl": "implementer", "path": "require"}]
        files = {"std.cpp": b"int solve();\n", "require/implementer.cpp": b"int main() {}\n"}
        self.assertEqual(self.build(files, step)[1], ["std"])
        self.assertEqual(self.build(files, step)[1], [])
        self.assertEqual(self.build(dict(files, **{"require/implementer.cpp": b"int main() { return 0; }\n"}), step)[1], ["std"])

    def test_program_is_built_every_time_when_what_it_reads_can_not_be_told(self):
        self.cache_set_up()
        unknowable = {
            "another language": {"chk.pas": b"begin end.\n"},
            "two sources": {"chk.cpp": b"int main() {}\n", "chk.c": b"int main() {}\n"},
            "an include that is a macro": {"chk.cpp": b"#define T \"tables.h\"\n#include T\nint main() {}\n"},
            "a file that is embedded": {"chk.cpp": b"const char d[] = {\n#embed \"input1.txt\"\n};\nint main() {}\n"},
            "a file the assembler includes": {"chk.cpp": b'asm(".incbin \\"input1.txt\\"");\nint main() {}\n'},
            "an include outside of the data": {"chk.cpp": b'#include "../../other/secret.h"\nint main() {}\n'},
            "an include from anywhere on the judger": {"chk.cpp": b"#include </etc/hostname>\nint main() {}\n"},
        }
        for what, files in unknowable.items():
            self.assertEqual(self.build(files)[1], ["chk"], what)
            self.assertEqual(self.build(files)[1], ["chk"], what)
        self.assertEqual(os.listdir(os.path.join(self.root, "uoj_judger", "cache")) if os.path.isdir(os.path.join(self.root, "uoj_judger", "cache")) else [], [])

    def test_compiler_that_leaves_nothing_behind_fills_no_cache(self):
        self.cache_set_up()
        files = {"chk.cpp": b"int main() {}\n"}
        self.assertEqual(self.build(files, binaries=False)[1], ["chk"])
        self.assertEqual(self.build(files, binaries=False)[1], ["chk"])

    def test_cache_keeps_the_programs_used_last(self):
        self.cache_set_up()
        self.jc.PROGRAM_CACHE_ENTRIES = 3
        for n in range(5):
            self.build({"chk.cpp": b"int main() { return %d; }\n" % n})
            os.utime(self.jc.program_cache_path(os.listdir(self.jc.program_cache_path())[0]))
        self.assertEqual(len(os.listdir(self.jc.program_cache_path())), 3)

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


class CleanUpFolderTest(JudgeClientTestCase):
    def test_folder_that_nobody_may_look_into_is_removed(self):
        os.makedirs(self.work_path("passes", "nextpass.in"))
        os.chmod(self.work_path("passes", "nextpass.in"), 0)
        os.makedirs(self.work_path("deep", "deeper"))
        with open(self.work_path("deep", "deeper", "file.txt"), "w") as f:
            f.write("x")
        os.chmod(self.work_path("deep", "deeper"), 0o500)
        with open(self.work_path("file.txt"), "w") as f:
            f.write("x")
        os.symlink(self.data_path(), self.work_path("link"))
        self.jc.clean_up_folder(self.work_path())
        self.assertEqual(os.listdir(self.work_path()), [])
        # what a link pointed to is not touched
        self.assertTrue(os.path.isdir(self.data_path()))


@unittest.skipUnless(sys.platform.startswith("linux"), "the sessions of processes are read from /proc")
class RunMainJudgerTest(JudgeClientTestCase):
    """a judgement comes to an end, and nothing of it is left when it has"""

    # A main_judger that starts a process which leaves its process group, the way run_program
    # puts every program it runs into a group of its own, and then does as it is told.
    MAIN_JUDGER = """#!%s
import os, time
if os.fork() == 0:
    os.setpgid(0, 0)
    with open("child.pid.tmp", "w") as f:
        f.write(str(os.getpid()))
    os.rename("child.pid.tmp", "child.pid")
    time.sleep(1000)
    os._exit(0)
while not os.path.exists("child.pid"):
    time.sleep(0.01)
%s
"""

    def write_main_judger(self, then):
        path = os.path.join(self.root, "uoj_judger", "main_judger")
        with open(path, "w") as f:
            f.write(self.MAIN_JUDGER % (sys.executable, then))
        os.chmod(path, 0o755)
        if os.path.exists(os.path.join(self.root, "uoj_judger", "child.pid")):
            os.unlink(os.path.join(self.root, "uoj_judger", "child.pid"))

    def child(self):
        with open(os.path.join(self.root, "uoj_judger", "child.pid")) as f:
            return int(f.read())

    def runs(self, pid):
        """whether a process is there and is more than an entry that waits to be read"""
        try:
            with open("/proc/%d/stat" % pid, "rb") as f:
                return f.read().rsplit(b")", 1)[1].split()[0] != b"Z"
        except OSError:
            return False

    def test_session_of_a_process_is_read(self):
        self.assertEqual(self.jc.session_of(os.getpid()), os.getsid(0))
        process = subprocess.Popen(["sleep", "1000"], start_new_session=True)
        try:
            self.assertEqual(self.jc.session_of(process.pid), process.pid)
        finally:
            process.kill()
            process.wait()
        self.assertIsNone(self.jc.session_of(process.pid))

    def test_main_judger_that_ends_leaves_nothing(self):
        self.write_main_judger("os._exit(0)")
        self.assertEqual(self.jc.run_main_judger(), "done")
        self.assertFalse(self.runs(self.child()))

        self.write_main_judger("os._exit(3)")
        self.assertEqual(self.jc.run_main_judger(), "failed")
        self.assertFalse(self.runs(self.child()))

    def test_main_judger_that_does_not_end_is_killed_with_all_it_started(self):
        self.jc.jconf["max_judging_seconds"] = 1
        self.write_main_judger("time.sleep(1000)")
        started = time.time()
        self.assertEqual(self.jc.run_main_judger(), "given up")
        self.assertLess(time.time() - started, 20)
        self.assertFalse(self.runs(self.child()))

    def test_only_the_processes_of_the_judgement_are_killed(self):
        bystander = subprocess.Popen(["sleep", "1000"], start_new_session=True)
        try:
            self.write_main_judger("os._exit(0)")
            self.assertEqual(self.jc.run_main_judger(), "done")
            self.assertTrue(self.runs(bystander.pid))
            self.assertTrue(self.runs(os.getpid()))
        finally:
            bystander.kill()
            bystander.wait()


class GivenUpJudgementTest(JudgeClientTestCase):
    def judge_with(self, outcome):
        self.jc.submission = {"id": 1, "problem_id": 1, "content": {"file_name": "/submission/1", "config": []}}
        with open(os.path.join(self.root, "uoj_judger", "result", "result.txt"), "w") as f:
            f.write("score 100\ntime 1\nmemory 1\ndetails\n<tests></tests>\n")
        with mock.patch.object(self.jc, "update_problem_data"), mock.patch.object(self.jc, "uoj_download"):
            with mock.patch.object(self.jc, "execute"), mock.patch.object(self.jc, "clean_up_folder"):
                with mock.patch.object(self.jc, "run_main_judger", return_value=outcome):
                    return self.jc.judge()

    def test_judgement_that_was_given_up_is_a_judgement_that_failed(self):
        self.assertEqual(self.judge_with("done")["score"], 100)
        self.jc.jconf["max_judging_seconds"] = 7
        res = self.judge_with("given up")
        self.assertEqual((res["score"], res["error"], res["status"]), (0, "Judgment Failed", "Judged"))
        self.assertIn("not done after 7 seconds", res["details"])
        # a judger that could not be run at all is not a verdict: another judger gets to try
        with self.assertRaises(Exception):
            self.judge_with("failed")

    def test_limit_that_is_no_number_of_seconds_is_not_used(self):
        self.jc.jconf.pop("max_judging_seconds", None)
        self.assertEqual(self.jc.max_judging_seconds(), 3600)
        for value, limit in ((90, 90), (0.5, 0.5), (0, 3600), (-1, 3600), ("90", 3600), (None, 3600), (True, 3600)):
            self.jc.jconf["max_judging_seconds"] = value
            self.assertEqual(self.jc.max_judging_seconds(), limit, value)


if __name__ == "__main__":
    unittest.main()
