"""Phase 14: judging faster. A precompiled <bits/stdc++.h> for every standard of C++, which
changes how long a program takes to compile and nothing else; several judgers on one machine,
each on a CPU of its own, all with one judging account; judgers with nothing to do that wait for
work instead of asking every two seconds; requests of the judgers answered without a session."""

import json
import os
import re
import subprocess
import tempfile
import threading
import time
import unittest
import uuid

import requests

import test_phase3 as p3
import test_phase9 as p9
import uoj
from fixtures import AB, AB_SLOW, ab_problem_files
from uoj import db, db_value

COMPILE = "/opt/uoj_judger/uoj_judger/run/compile"
PCH_DIR = "/usr/local/lib/uoj-pch"
STANDARDS = ["C++98", "C++03", "C++11", "C++14", "C++17", "C++20", "C++23", "C++26"]

AB_BITS = r"""#include <bits/stdc++.h>
using namespace std;
int main() {
    long long a, b;
    cin >> a >> b;
    cout << a + b << endl;
}
"""

# what can come before the #include, and changes what the header means (no assert() checks)
AB_BITS_DEFINE_FIRST = r"""#define NDEBUG
#include <bits/stdc++.h>
using namespace std;
int main() {
    long long a, b;
    cin >> a >> b;
    assert(a < 0);
    cout << a + b << endl;
}
"""

AB_BITS_PRAGMA_FIRST = r"""#pragma GCC optimize("O3,unroll-loops")
#include <bits/stdc++.h>
using namespace std;
int main() {
    long long a, b;
    cin >> a >> b;
    cout << a + b << endl;
}
"""

AB_BITS_DEBUG_MODE = r"""#define _GLIBCXX_DEBUG
#include <bits/stdc++.h>
using namespace std;
int main() {
    vector<long long> v(2);
    cin >> v[0] >> v[1];
    cout << v[0] + v[1] << endl;
}
"""

# right only where ONLINE_JUDGE is defined, as it is for every program
AB_BITS_ONLINE_JUDGE = r"""// A+B
#include <bits/stdc++.h>
int main() {
    long long a, b;
    std::cin >> a >> b;
#ifdef ONLINE_JUDGE
    std::cout << a + b << std::endl;
#else
    std::cout << "not on the judge" << std::endl;
#endif
}
"""

# compiles in C++20 and not in C++17: the header of each standard is that of its own
AB_BITS_CPP20 = r"""#include <bits/stdc++.h>
using namespace std;
int main() {
    vector<long long> v(2);
    cin >> v[0] >> v[1];
    ranges::sort(v);
    cout << reduce(v.begin(), v.end()) << endl;
}
"""

COMPILE_ERRORS = {
    "undeclared": "#include <bits/stdc++.h>\nint main() {\n    return undeclared_thing;\n}\n",
    "missing semicolon": "#include <bits/stdc++.h>\nint main() {\n    int x = 1\n    return x;\n}\n",
    "deep in the library": (
        "#include <bits/stdc++.h>\nstruct P { int x; };\nint main() {\n"
        "    std::vector<P> v(3);\n    std::sort(v.begin(), v.end());\n    std::set<P> s;\n    s.insert(P{1});\n}\n"
    ),
}


def judger():
    return uoj.JUDGERS[0]


def put(path, content):
    uoj.run("docker", "exec", "-i", judger(), "sh", "-c", "mkdir -p $(dirname %s) && cat > %s" % (path, path), stdin=content.encode())


def compile_in_judger(source, language, *options):
    """run run/compile of the judger on a program, outside the sandbox: its exit status, the
    options it ran g++ with, and what the compiler said"""
    folder = "/tmp/uoj_e2e_pch"
    uoj.docker_exec(judger(), "rm -rf %s; mkdir -p %s" % (folder, folder))
    put(folder + "/answer.code", source)
    p = uoj.run(
        "docker", "exec", "-w", folder, judger(), COMPILE, "--verbose", "--lang", language, *options, "answer",
        check=False,
    )  # fmt: skip
    lines = p.stderr.decode(errors="replace").splitlines()
    return p.returncode, lines[0] if lines else "", "\n".join(lines[1:])


def errors_of(message):
    """the errors a compiler reported, without what it says about where they were included from"""
    return [line for line in message.splitlines() if "error:" in line]


class PrecompiledHeaderTest(unittest.TestCase):
    """the judger image holds <bits/stdc++.h> compiled for every standard, and gcc takes it"""

    def test_every_standard_has_a_precompiled_header_that_gcc_takes(self):
        for language in STANDARDS:
            std = language.lower()
            code, options, message = compile_in_judger(AB_BITS, language)
            self.assertEqual(code, 0, (language, message))
            self.assertIn("-I%s/%s" % (PCH_DIR, std), options.split(), language)
            # the same options again, with gcc telling which headers it read: "!" before a
            # precompiled header it took, "x" before one it did not
            shown = uoj.run(
                "docker", "exec", "-w", "/tmp/uoj_e2e_pch", judger(),
                "sh", "-c", "/usr/bin/g++ %s -H -fsyntax-only -x c++ answer.code" % options,
                check=False,
            ).stderr.decode(errors="replace")  # fmt: skip
            self.assertIn("! %s/%s/bits/stdc++.h.gch" % (PCH_DIR, std), shown, language)

    def test_precompiled_header_is_used_only_where_it_means_the_same(self):
        for source in (AB_BITS_DEFINE_FIRST, AB_BITS_PRAGMA_FIRST, AB_BITS_DEBUG_MODE):
            code, options, message = compile_in_judger(source, "C++17")
            self.assertEqual(code, 0, message)
            self.assertNotIn(PCH_DIR, options, source)
        code, options, message = compile_in_judger(AB_BITS, "C++17", "--no-pch")
        self.assertEqual(code, 0, message)
        self.assertNotIn(PCH_DIR, options)
        # nothing of it for C
        code, options, message = compile_in_judger("#include <stdio.h>\nint main(void) { return 0; }\n", "C17")
        self.assertEqual(code, 0, message)
        self.assertNotIn(PCH_DIR, options)

    def test_compile_errors_are_those_without_the_precompiled_header(self):
        for name, source in COMPILE_ERRORS.items():
            for language in ("C++11", "C++17", "C++20"):
                code, options, message = compile_in_judger(source, language)
                plain_code, plain_options, plain_message = compile_in_judger(source, language, "--no-pch")
                self.assertIn(PCH_DIR, options)
                self.assertNotIn(PCH_DIR, plain_options)
                self.assertEqual((code != 0, plain_code != 0), (True, True), (name, language))
                self.assertTrue(errors_of(message), (name, language, message[-2000:]))
                self.assertEqual(errors_of(message), errors_of(plain_message), (name, language))


class PrecompiledHeaderVerdictTest(unittest.TestCase):
    """what is judged with the precompiled header gets the verdict it got without it"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(ab_problem_files())

    def judge(self, code, language="C++17"):
        return uoj.wait_submission(self.admin.submit(self.problem_id, code, language))

    def test_programs_with_the_header_are_accepted_in_every_standard(self):
        for language in STANDARDS:
            j = self.judge(AB_BITS, language)
            self.assertEqual((j.score, j.error), (100, None), (language, j))

    def test_programs_that_are_compiled_the_ordinary_way_are_accepted(self):
        for source in (AB_BITS_DEFINE_FIRST, AB_BITS_PRAGMA_FIRST, AB_BITS_DEBUG_MODE, AB_BITS_ONLINE_JUDGE):
            j = self.judge(source)
            self.assertEqual((j.score, j.error), (100, None), (source, j))

    def test_each_standard_has_a_library_of_its_own(self):
        j = self.judge(AB_BITS_CPP20, "C++20")
        self.assertEqual(j.score, 100, j)
        j = self.judge(AB_BITS_CPP20, "C++17")
        self.assertEqual(j.error, "Compile Error", j)

    def test_compile_error_is_reported_as_before(self):
        j = self.judge(COMPILE_ERRORS["undeclared"])
        self.assertEqual(j.error, "Compile Error", j)
        self.assertIn("undeclared_thing", j.details, j)
        self.assertIn("was not declared in this scope", j.details, j)

    def test_precompiled_header_makes_compiling_faster(self):
        # the same program, once with the header precompiled and once with a line before the
        # #include that keeps the precompiled header from being used
        fast = [self.admin.submit(self.problem_id, AB_BITS) for _ in range(2)]
        slow = [self.admin.submit(self.problem_id, "#define UOJ_E2E_NOTHING\n" + AB_BITS) for _ in range(2)]
        for submission_id in fast + slow:
            j = uoj.wait_submission(submission_id)
            self.assertEqual(j.score, 100, j)
        with_pch = min(uoj.task_timing("submission", i)["compile_ms"] for i in fast)
        without = min(uoj.task_timing("submission", i)["compile_ms"] for i in slow)
        self.assertLess(with_pch, without * 0.7, (with_pch, without))

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
IMAGE = "ghcr.io/universaloj/uoj-judger:latest"


class WorkersTest(unittest.TestCase):
    """judger/workers.sh writes the judgers of a machine of their own: they reach the site by its
    address, as from another machine, each on its CPU, and share one account without taking
    anything from each other"""

    ACCOUNT = "p14_workers"
    PROJECT = "p14workers"

    def setUp(self):
        self.admin = uoj.admin()
        db("delete from judger_info where judger_name = '%s'" % self.ACCOUNT)
        r = self.admin.post("/super-manage/judger", {"submit-judger_adder": "judger_adder", "judger_adder_name": self.ACCOUNT, "judger_adder_note": "workers.sh"})
        self.password = re.search(r'id="judger-password">([0-9a-zA-Z]{32})<', r.text).group(1)
        self.addCleanup(db, "delete from judger_info where judger_name = '%s'" % self.ACCOUNT)
        self.folder = tempfile.mkdtemp()
        self.addCleanup(subprocess.call, ["sudo", "rm", "-rf", self.folder])

    def start_workers(self, cpus):
        compose = os.path.join(self.folder, "docker-compose.judgers.yml")
        uoj.run(
            "bash", os.path.join(REPO, "judger", "workers.sh"),
            "--url", "http://host.docker.internal", "--name", self.ACCOUNT, "--password", self.password,
            # the CPUs of the runners of the tests are hyperthreads of two cores
            "--cpus", cpus, "--allow-smt", "--image", IMAGE, "--prefix", "p14-judger", "--memory", "3g",
            "--add-host", "host.docker.internal:host-gateway", "--env", "DATA_SYNC=false",
            "--output", compose,
        )  # fmt: skip
        uoj.run("docker", "compose", "-p", self.PROJECT, "-f", compose, "up", "-d")
        self.addCleanup(uoj.run, "docker", "compose", "-p", self.PROJECT, "-f", compose, "down", "-t", "5", check=False)
        return compose

    def test_judgers_of_one_machine_share_an_account(self):
        self.start_workers("0-1")
        containers = ["p14-judger-cpu0", "p14-judger-cpu1"]
        for container, cpu in zip(containers, "01"):
            self.assertEqual(uoj.run("docker", "inspect", "-f", "{{.HostConfig.CpusetCpus}}", container).stdout.decode().strip(), cpu)
            self.assertEqual(uoj.run("docker", "inspect", "-f", "{{.HostConfig.Memory}}", container).stdout.decode().strip(), str(3 << 30))

        # both have asked for work, each as a worker of its own named after its CPU
        def workers():
            names = [row[0] for row in db("select worker from judger_workers where judger_name = '%s'" % self.ACCOUNT)]
            return names if len(names) == 2 else None

        names = uoj.wait_until("both judgers have asked for work", workers, timeout=180)
        self.assertEqual(sorted(name.split("-")[0] for name in names), ["cpu0", "cpu1"])
        for name in names:
            self.assertRegex(name, r"^cpu[01]-[0-9a-f]{16}$")

        problem_id = self.admin.create_problem(ab_problem_files())
        big = self.admin.create_problem(ab_problem_files(memory_limit=2600))
        # the other judgers have nothing to do meanwhile, and no other worker is around
        uoj.wait_idle()
        names_in = ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES)
        db("update judger_info set enabled = 0 where judger_name in (%s)" % names_in)
        self.addCleanup(db, "update judger_info set enabled = 1 where judger_name in (%s)" % names_in)
        db("update judger_workers set last_seen_at = now() - interval 1 hour where judger_name <> '%s'" % self.ACCOUNT)

        submissions = [self.admin.submit(problem_id, AB_SLOW) for _ in range(6)]
        for submission_id in submissions:
            j = uoj.wait_submission(submission_id, timeout=600)
            self.assertEqual(j.score, 100, j)
        rows = db(
            "select judger_name, worker, outcome from submission_judgements where kind = 'submission' and target_id in (%s)"
            % ", ".join(map(str, submissions))
        )  # fmt: skip
        self.assertEqual({(row[0], row[2]) for row in rows}, {(self.ACCOUNT, "judged")}, rows)
        self.assertEqual(len(rows), len(submissions), rows)
        # both worked, and nothing was taken from one for the other
        self.assertEqual({row[1] for row in rows}, set(names), rows)

        # the pages say which judger it was
        page = self.admin.get("/submission/%d" % submissions[0]).text
        worker = db_value("select worker from submission_judgements where kind = 'submission' and target_id = %d" % submissions[0])
        self.assertIn('data-worker="%s">%s</small>' % (worker, worker.split("-")[0]), page)
        page = self.admin.get("/super-manage/judger").text
        # each with the most memory a problem may need for it to judge it: 3 GB but 512 MB
        self.assertIn("2 个进程在用这个账户（最近 10 分钟），各评各的：cpu0（≤2560 MB）、cpu1（≤2560 MB）", uoj.text_of(page))

        # A problem whose programs may use more memory than the judgers have is given back, not
        # judged wrongly; with no judger around that can judge it, it fails at once, saying why.
        submission_id = self.admin.submit(big, AB)
        j = uoj.wait_submission(submission_id, timeout=600)
        self.assertEqual(j.error, "Judgement Failed", j)
        self.assertIn("没有内存足够的评测机：这道题允许 2600 MB，在线的评测机最多能评 2560 MB 的题", j.details)
        self.assertEqual([outcome for _, outcome in uoj.judgements("submission", submission_id)], ["failed"])
        logs = "".join(uoj.judger_log(container) for container in containers)
        self.assertIn("problem #%d needs 2600 MB, and this judger judges problems of up to 2560 MB" % big, logs)


class MemoryGiveBackTest(unittest.TestCase):
    """A judger with too little memory for a problem gives it back, which is no failed attempt.
    The problem keeps what it needs for the version of its data, and is not given to judgers
    with less while one with enough is around; with none around, it fails at once."""

    SMALL = "e2e-memory-small-01"
    BIG = "e2e-memory-big-0001"
    RESULT = {"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(ab_problem_files(memory_limit=2000))

    def setUp(self):
        # only the workers of the test are around: the judgers of the tests are switched off, and
        # no other worker was heard of for an hour
        uoj.wait_idle()
        names_in = ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES)
        db("update judger_info set enabled = 0 where judger_name in (%s)" % names_in)
        self.addCleanup(db, "update judger_info set enabled = 1 where judger_name in (%s)" % names_in)
        db("update judger_workers set last_seen_at = now() - interval 1 hour where worker not in ('%s', '%s')" % (self.SMALL, self.BIG))

    def ask(self, worker, memory=None, wait=None):
        data = {"protocol": "3", "judger_version": "e2e-fake", "toolchain": "{}", "worker": worker, "fetch_id": uuid.uuid4().hex}
        if memory is not None:
            data["memory_mb"] = str(memory)
        if wait is not None:
            data["wait"] = str(wait)
        r = uoj.judge_api("/judge/submit", data)
        self.assertEqual(r.status_code, 200, r.text[:300])
        return r.text

    def task_for(self, worker, memory=None):
        answer = self.ask(worker, memory)
        self.assertNotIn(answer, ("Nothing to judge", "Ask again"), worker)
        return json.loads(answer)

    def give_back(self, task, worker, needed):
        r = uoj.judge_api("/judge/submit", {
            "fetch_new": "0", "worker": worker, "requeue": "1", "id": task["id"], "claim": task["claim"],
            "reason": "memory", "needed_mb": str(needed), "problem_data_version": str(task["problem_data_version"]),
        })  # fmt: skip
        self.assertEqual(r.status_code, 200)

    def finish(self, task, worker):
        r = uoj.judge_api("/judge/submit", {"fetch_new": "0", "worker": worker, "submit": "1", "id": task["id"], "claim": task["claim"], "result": json.dumps(self.RESULT)})
        self.assertEqual(r.status_code, 200)
        self.assertEqual(uoj.wait_submission(task["id"]).score, 100)

    def state(self, submission_id):
        return tuple(db("select status, judge_attempts from submissions where id = %d" % submission_id)[0])

    def remembered(self):
        return tuple(db("select ifnull(judge_memory_mb, 'NULL'), ifnull(judge_memory_version, 'NULL') from problems where id = %d" % self.problem_id)[0])

    def test_problem_is_left_to_a_judger_with_enough_memory(self):
        version = db_value("select data_version from problems where id = %d" % self.problem_id)
        # a judger with no limit is around
        self.assertEqual(self.ask(self.BIG), "Nothing to judge")

        # ---- (a) a judger that can judge problems of up to 1000 MB is given one of 2000, gives it
        # back, and that is no failed attempt; the problem keeps what it needs
        first = self.admin.submit(self.problem_id, AB)
        task = self.task_for(self.SMALL, memory=1000)
        self.assertEqual(task["id"], first)
        self.give_back(task, self.SMALL, 2000)
        self.assertEqual(self.state(first), ("Waiting", "0"))
        self.assertEqual(self.remembered(), ("2000", version))
        self.assertEqual(uoj.judgements("submission", first), [(uoj.FAKE_JUDGER["judger_name"], "memory")])
        self.assertIn("评测机内存不够，留给内存够的评测机", self.admin.get("/submission/%d" % first).text)

        # ---- (b) it is not given it again, nor woken for it while it waits; the judger with no
        # limit is given it
        self.assertEqual(self.ask(self.SMALL, memory=1000), "Nothing to judge")
        started = time.time()
        self.assertEqual(self.ask(self.SMALL, memory=1000, wait=2), "Nothing to judge")
        self.assertGreaterEqual(time.time() - started, 1.8)
        task = self.task_for(self.BIG)
        self.assertEqual(task["id"], first)
        self.finish(task, self.BIG)

        # ---- (e) while a judger that can judge it is around, giving it back fails nothing,
        # however often (the problem is forgotten each time, so that the small judger gets it)
        second = self.admin.submit(self.problem_id, AB)
        for _ in range(5):
            db("update problems set judge_memory_version = null where id = %d" % self.problem_id)
            task = self.task_for(self.SMALL, memory=1000)
            self.assertEqual(task["id"], second)
            self.give_back(task, self.SMALL, 2000)
            self.assertEqual(self.state(second), ("Waiting", "0"))
        self.finish(self.task_for(self.BIG), self.BIG)

        # ---- (c) with no judger around that can judge it, the small one is given it, and giving
        # it back fails it at once, saying why
        db("update judger_workers set last_seen_at = now() - interval 1 hour where worker = '%s'" % self.BIG)
        third = self.admin.submit(self.problem_id, AB)
        task = self.task_for(self.SMALL, memory=1000)
        self.assertEqual(task["id"], third)
        self.give_back(task, self.SMALL, 2000)
        j = uoj.wait_submission(third)
        self.assertEqual((j.score, j.error), (0, "Judgement Failed"), j)
        self.assertIn("没有内存足够的评测机：这道题允许 2000 MB，在线的评测机最多能评 1000 MB 的题", j.details)
        self.assertEqual(uoj.judgements("submission", third), [(uoj.FAKE_JUDGER["judger_name"], "failed")])

        # ---- (d) once new data is published, what the problem kept counts no more: the small
        # judger is given it again, even with the big one around, and finds out for itself
        files = ab_problem_files(memory_limit=2000)
        files["input1.txt"] = "40 2\n"
        files["output1.txt"] = "42\n"
        self.assertIn("上传成功", self.admin.upload_data(self.problem_id, files).text)
        self.assertEqual(self.admin.sync(self.problem_id, wait=False), "")
        # (the data is built by the fake judger, which is all there is)
        prepared = self.task_for(self.BIG)
        self.assertIn("prepare", prepared)
        r = uoj.judge_api("/judge/submit", {"fetch_new": "0", "worker": self.BIG, "prepare_result": "1", "id": prepared["prepare"]["id"], "claim": prepared["claim"], "ok": "1", "message": ""})
        self.assertEqual(r.status_code, 200)
        self.assertEqual(uoj.wait_data_version(self.problem_id), "")
        self.assertNotEqual(db_value("select data_version from problems where id = %d" % self.problem_id), version)
        fourth = self.admin.submit(self.problem_id, AB)
        task = self.task_for(self.SMALL, memory=1000)
        self.assertEqual(task["id"], fourth)
        self.finish(task, self.SMALL)


class LongPollTest(unittest.TestCase):
    """a judger with nothing to do may ask the site to wait: it is told as soon as there is work,
    and the request that waits never gives anything out itself"""

    WORKER = "e2e-long-poll-0001"

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(ab_problem_files())

    def setUp(self):
        # only the fake judger is given work meanwhile
        uoj.wait_idle()
        names_in = ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES)
        db("update judger_info set enabled = 0 where judger_name in (%s)" % names_in)
        self.addCleanup(db, "update judger_info set enabled = 1 where judger_name in (%s)" % names_in)

    def ask(self, wait=None, fetch_id=None, worker=None):
        """ask for work as the fake judger, return the answer and the seconds it took"""
        data = {"protocol": "3", "judger_version": "e2e-fake", "toolchain": "{}", "worker": worker or self.WORKER,
                "fetch_id": fetch_id or uuid.uuid4().hex}  # fmt: skip
        if wait is not None:
            data["wait"] = str(wait)
        started = time.time()
        r = uoj.judge_api("/judge/submit", data)
        self.assertEqual(r.status_code, 200, r.text[:300])
        return r.text, time.time() - started

    def ask_in_background(self, **fields):
        answer = {}
        thread = threading.Thread(target=lambda: answer.update(zip(("text", "seconds"), self.ask(**fields))))
        thread.start()
        return thread, answer

    def finish(self, task):
        """the fake judger reports the task done, so that nothing waits for it afterwards"""
        r = uoj.judge_api("/judge/submit", {
            "fetch_new": "0", "worker": self.WORKER, "submit": "1", "id": task["id"], "claim": task["claim"],
            "result": json.dumps({"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}),
        })  # fmt: skip
        self.assertEqual(r.status_code, 200)

    def test_judger_that_waits_is_told_as_soon_as_there_is_work(self):
        asked_at = time.time()
        thread, answer = self.ask_in_background(wait=20)
        time.sleep(2)
        submission_id = self.admin.submit(self.problem_id, AB)
        submitted_at = time.time()
        thread.join(30)
        self.assertEqual(answer["text"], "Ask again")
        # told within moments of the submission, not when the wait was over
        self.assertLess(asked_at + answer["seconds"] - submitted_at, 3, answer)
        # the waiting request gave nothing out: the judger asks for the work, and is given it
        status, claim = db("select status, ifnull(judge_claim, 'NULL') from submissions where id = %d" % submission_id)[0]
        self.assertEqual((status, claim), ("Waiting", "NULL"))
        text, seconds = self.ask()
        task = json.loads(text)
        self.assertEqual(task["id"], submission_id)
        self.assertLess(seconds, 2)
        self.finish(task)

    def test_judger_that_waits_for_nothing_is_answered_when_the_wait_is_over(self):
        text, seconds = self.ask(wait=3)
        self.assertEqual(text, "Nothing to judge")
        self.assertGreaterEqual(seconds, 2.8)
        self.assertLess(seconds, 8)
        # a wait that is no number of seconds is no wait, and so is none at all
        for wait in ("soon", "-5", None):
            text, seconds = self.ask(wait=wait)
            self.assertEqual(text, "Nothing to judge", wait)
            self.assertLess(seconds, 2, wait)

    def test_wait_ends_when_the_judger_asks_again(self):
        # the answer of the wait was lost: the judger asked again meanwhile, and that wait is over
        thread, answer = self.ask_in_background(wait=20)
        time.sleep(1)
        self.assertEqual(self.ask()[0], "Nothing to judge")
        thread.join(30)
        self.assertEqual(answer["text"], "Nothing to judge")
        self.assertLess(answer["seconds"], 12)

    def test_judger_that_is_switched_off_while_it_waits_is_given_nothing(self):
        thread, answer = self.ask_in_background(wait=20)
        time.sleep(1)
        db("update judger_info set enabled = 0 where judger_name = '%s'" % uoj.FAKE_JUDGER["judger_name"])
        try:
            thread.join(30)
            self.assertEqual(answer["text"], "Nothing to judge")
            self.assertLess(answer["seconds"], 12)
        finally:
            db("update judger_info set enabled = 1 where judger_name = '%s'" % uoj.FAKE_JUDGER["judger_name"])

    def test_no_more_judgers_wait_at_once_than_the_site_allows(self):
        # thirty workers wait already, as many as judger.long-poll-max-waiting allows by default
        fake = uoj.FAKE_JUDGER["judger_name"]
        rows = ", ".join("('%s', 'e2e-waiting-%04d', now(), now(), now() + interval 60 second)" % (fake, n) for n in range(30))
        db("insert into judger_workers (judger_name, worker, first_seen_at, last_seen_at, waiting_until) values %s" % rows)
        self.addCleanup(db, "delete from judger_workers where worker like 'e2e-waiting-%'")
        text, seconds = self.ask(wait=10)
        self.assertEqual(text, "Nothing to judge")
        self.assertLess(seconds, 2)
        self.assertEqual(db_value("select ifnull(waiting_until, 'NULL') from judger_workers where worker = '%s'" % self.WORKER), "NULL")
        # with room, it waits, and says so while it does, and not afterwards
        db("delete from judger_workers where worker like 'e2e-waiting-%'")
        thread, answer = self.ask_in_background(wait=3)
        time.sleep(1)
        self.assertEqual(db_value("select waiting_until > now() from judger_workers where worker = '%s'" % self.WORKER), "1")
        thread.join(30)
        self.assertEqual(answer["text"], "Nothing to judge")
        self.assertGreaterEqual(answer["seconds"], 2.8)
        self.assertEqual(db_value("select ifnull(waiting_until, 'NULL') from judger_workers where worker = '%s'" % self.WORKER), "NULL")

    def test_web_server_and_database_have_room_for_the_judgers_that_wait(self):
        # what the images really allow, against the thirty that may wait (docs/deployment.md)
        mpm = uoj.docker_exec(uoj.WEB, "apache2ctl -M 2>/dev/null | grep -o 'mpm_[a-z]*'").strip()
        workers = uoj.docker_exec(uoj.WEB, "grep -h '^[[:space:]]*MaxRequestWorkers' /etc/apache2/mods-enabled/%s.conf" % mpm).split()[1]
        connections = db_value("select @@max_connections")
        print("\nweb server: %s, MaxRequestWorkers %s; database: max_connections %s" % (mpm, workers, connections))
        self.assertEqual(mpm, "mpm_prefork")
        self.assertGreaterEqual(int(workers), 4 * 30)
        self.assertGreaterEqual(int(connections), 4 * 30)

    def test_judgers_that_wait_pick_up_work_at_once(self):
        # the judgers of the tests wait for work too: a submission is picked up in well under the
        # two seconds a judger that asks every two seconds takes on average
        db("update judger_info set enabled = 1 where judger_name in (%s)" % ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES))
        uoj.wait_idle()
        pickups = []
        for _ in range(4):
            time.sleep(3)
            submission_id = self.admin.submit(self.problem_id, AB)
            submitted_at = time.time()
            self.assertEqual(uoj.wait_submission(submission_id).score, 100)
            pickups.append(uoj.task_timing("submission", submission_id)["fetched_at"] - submitted_at)
        self.assertLess(sorted(pickups)[1], 1.0, pickups)


class JudgeRequestTest(unittest.TestCase):
    """a judger says which account it is with every request: it gets no session"""

    def test_requests_of_the_judgers_open_no_session(self):
        uoj.admin()
        r = uoj.judge_api("/judge/submit", {"heartbeat": "1", "worker": "e2e-no-session-0001"})
        self.assertEqual(r.status_code, 200, r.text[:300])
        self.assertNotIn("Set-Cookie", r.headers)
        r = uoj.judge_api("/judge/submit", {"fetch_new": "0"})
        self.assertEqual((r.status_code, r.text), (200, "Nothing to judge"))
        self.assertNotIn("Set-Cookie", r.headers)
        # a judger that is refused is still told so
        r = uoj.judge_api("/judge/submit", {"heartbeat": "1"}, auth={"judger_name": uoj.FAKE_JUDGER["judger_name"], "password": "wrong"})
        self.assertEqual(r.status_code, 403)
        # the pages keep their sessions
        self.assertIn("UOJSESSID=", requests.get(uoj.BASE_URL + "/login").headers.get("Set-Cookie", ""))


class FirstSolveTest(unittest.TestCase):
    """Who solved first is told only once nothing submitted before waits to be judged: several
    judgers judge at once, and a later submission may be judged before an earlier one (as
    DOMjudge has it). Of the same second, the smaller number is first, everywhere."""

    JUDGED = {"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}

    def judge(self, task, worker):
        r = uoj.judge_api("/judge/submit", {"fetch_new": "0", "worker": worker, "submit": "1", "id": task["id"], "claim": task["claim"], "result": json.dumps(self.JUDGED)})
        self.assertEqual(r.status_code, 200)
        self.assertEqual(uoj.wait_submission(task["id"]).score, 100)

    def everywhere(self, admin, contest_id):
        """who is first of what: on the balloon list (awards), on the board of the contest and on
        the outer board (the cells that say first), by username and letter"""
        rows, _ = p9.balloon_list(admin, contest_id)
        balloons = {(name, letter): awards for name, letter, _, _, awards, _ in rows}
        board, _, _ = p9.icpc_board(admin, contest_id)
        on_board = {(name, letter) for name, (_, _, cells) in board.items() for letter, (kind, _) in cells.items() if kind == "first"}
        told = admin.get("/contest/%d/board?json=1" % contest_id).json()
        letters = [problem["letter"] for problem in told["problems"]]
        outer = {(row["username"], letters[pos]) for row in told["rows"] for pos, cell in enumerate(row["cells"]) if cell[0] == "first"}
        return balloons, on_board, outer

    def test_first_is_told_once_nothing_before_it_waits(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p14 一血", minutes=300, rule="ICPC", problems="%d, %d" % (first, second))
        manage, balloons = "/contest/%d/manage" % contest_id, "/contest/%d/balloons" % contest_id
        ann, bob = p3.account("p14_fs_ann"), p3.account("p14_fs_bob")
        self.assertEqual(admin.form(manage, "add_contestants", tab="contestants", names="p14_fs_ann A-1\np14_fs_bob A-2"), "")
        self.assertEqual(admin.form(balloons, "settings", balloons="on"), "")
        uoj.move_contest(contest_id, -100 * 60, 300)

        def at(minutes, submission_id):
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute)"
               " where id = %d" % (contest_id, minutes, submission_id))  # fmt: skip
            return submission_id

        # nothing is judged meanwhile but what the fake judger is given
        uoj.wait_idle()
        names_in = ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES)
        db("update judger_info set enabled = 0 where judger_name in (%s)" % names_in)
        self.addCleanup(db, "update judger_info set enabled = 1 where judger_name in (%s)" % names_in)

        # ---- ann submitted first and bob after her; bob's submission is judged first
        early = at(10, ann.submit_in_contest(contest_id, first, AB))
        late = at(20, bob.submit_in_contest(contest_id, first, AB))
        task_early = uoj.fake_fetch(worker="e2e-first-solve-one")
        task_late = uoj.fake_fetch(worker="e2e-first-solve-two")
        self.assertEqual((task_early["id"], task_late["id"]), (early, late))
        self.judge(task_late, "e2e-first-solve-two")
        # bob passed, but ann's earlier submission may pass too: bob is nobody's first yet
        balloons_of, on_board, outer = self.everywhere(admin, contest_id)
        self.assertEqual(balloons_of, {("p14_fs_bob", "A"): ("contest_pending", "problem_pending")})
        self.assertEqual((on_board, outer), (set(), set()))
        page = admin.get(balloons).text
        self.assertIn("A 题一血待定", uoj.text_of(page))
        self.assertNotIn("uoj-balloon-starred", page)

        # ---- ann's passes: she is the first, and bob is not
        self.judge(task_early, "e2e-first-solve-one")
        balloons_of, on_board, outer = self.everywhere(admin, contest_id)
        self.assertEqual(balloons_of, {("p14_fs_ann", "A"): ("contest", "problem"), ("p14_fs_bob", "A"): ()})
        self.assertEqual(on_board, {("p14_fs_ann", "A")})
        self.assertEqual(outer, {("p14_fs_ann", "A")})

        # ---- B: bob and ann at the same second, bob with the smaller number. ann stands above
        # him on the board, and still bob is first, on the board as on the balloon list.
        bob_b = at(30, bob.submit_in_contest(contest_id, second, AB))
        ann_b = at(30, ann.submit_in_contest(contest_id, second, AB))
        self.assertLess(bob_b, ann_b)
        for worker in ("e2e-first-solve-one", "e2e-first-solve-two"):
            self.judge(uoj.fake_fetch(worker=worker), worker)
        board, _, _ = p9.icpc_board(admin, contest_id)
        self.assertLess(board["p14_fs_ann"][0], board["p14_fs_bob"][0])
        balloons_of, on_board, outer = self.everywhere(admin, contest_id)
        self.assertEqual(balloons_of[("p14_fs_bob", "B")], ("problem",))
        self.assertEqual(balloons_of[("p14_fs_ann", "B")], ())
        self.assertEqual(on_board, {("p14_fs_ann", "A"), ("p14_fs_bob", "B")})
        self.assertEqual(outer, {("p14_fs_ann", "A"), ("p14_fs_bob", "B")})


    def test_own_earlier_submission_leaves_the_first_sure(self):
        # cat submits, and then again; the second is judged first while the first still waits:
        # whether the first passes or not, the first to solve A is cat, with the same balloon
        admin = uoj.admin()
        problem = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p14 自己的一血", minutes=300, rule="ICPC", problems="%d" % problem)
        cat = p3.account("p14_fs_cat")
        self.assertEqual(admin.form("/contest/%d/manage" % contest_id, "add_contestants", tab="contestants", names="p14_fs_cat C-1"), "")
        self.assertEqual(admin.form("/contest/%d/balloons" % contest_id, "settings", balloons="on"), "")
        uoj.move_contest(contest_id, -100 * 60, 300)
        uoj.wait_idle()
        names_in = ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES)
        db("update judger_info set enabled = 0 where judger_name in (%s)" % names_in)
        self.addCleanup(db, "update judger_info set enabled = 1 where judger_name in (%s)" % names_in)

        earlier = cat.submit_in_contest(contest_id, problem, AB)
        later = cat.submit_in_contest(contest_id, problem, AB)
        task_earlier = uoj.fake_fetch(worker="e2e-first-solve-one")
        task_later = uoj.fake_fetch(worker="e2e-first-solve-two")
        self.assertEqual((task_earlier["id"], task_later["id"]), (earlier, later))
        self.judge(task_later, "e2e-first-solve-two")
        balloons_of, on_board, outer = self.everywhere(admin, contest_id)
        self.assertEqual(balloons_of, {("p14_fs_cat", "A"): ("contest", "problem")})
        self.assertEqual((on_board, outer), ({("p14_fs_cat", "A")}, {("p14_fs_cat", "A")}))
        page = uoj.text_of(admin.get("/contest/%d/balloons" % contest_id).text)
        self.assertIn("★ A 题一血", page)
        self.assertNotIn("待定", page)
        self.judge(task_earlier, "e2e-first-solve-one")

if __name__ == "__main__":
    unittest.main()
