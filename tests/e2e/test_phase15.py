"""Phase 15: what a check of the whole system found, mended. Each class is one of the defects,
told by what it does wrong and kept from coming back."""

import json
import unittest

import uoj
from fixtures import AB, ab_problem_files
from uoj import db, db_value, docker_exec

# A+B in the languages of the site but C++ (which every other test submits in)
AB_IN = {
    "C11": r"""#include <stdio.h>
int main(void) {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    printf("%lld\n", a + b);
    return 0;
}
""",
    "Pascal": """var a, b: int64;
begin
    readln(a, b);
    writeln(a + b);
end.
""",
    "Python3": """import sys
a, b = map(int, sys.stdin.read().split())
print(a + b)
""",
}

# what lists the processes of a judger that run judge_client, the oldest first, as root sees them
JUDGE_CLIENT_PIDS = r"""python3 -c '
import os
for p in sorted((p for p in os.listdir("/proc") if p.isdigit()), key=int):
    try:
        cmdline = open("/proc/%s/cmdline" % p, "rb").read()
    except OSError:
        continue
    if b"judge_client" in cmdline and not cmdline.startswith(b"python3\x00-c"):
        print(p)
'"""


def judge_client_pid(judger):
    pids = docker_exec(judger, JUDGE_CLIENT_PIDS).split()
    if not pids:
        raise AssertionError("no judge_client runs in %s" % judger)
    return int(pids[0])


def judger_password(judger):
    return json.loads(docker_exec(judger, "cat /opt/uoj_judger/.conf.json"))["judger_password"]


class CompilerReadsNoOtherProcessTest(unittest.TestCase):
    """A program that is compiled can #include any file it names, and the compiler shows what it
    read: it may read nothing of the other processes of the judger in /proc, such as the
    environment of judge_client, and judge_client keeps no password in its environment."""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())
        cls.student = uoj.client(("p15_compiler", "p15-password"))

    def test_judge_client_keeps_no_password_in_its_environment(self):
        for judger in uoj.JUDGERS:
            pid = judge_client_pid(judger)
            environment = docker_exec(judger, "tr '\\0' '\\n' < /proc/%d/environ" % pid)
            self.assertNotIn("JUDGER_PASSWORD", environment, judger)
            self.assertNotIn("SOCKET_PASSWORD", environment, judger)
            self.assertNotIn(judger_password(judger), environment, judger)

    def test_what_is_compiled_can_not_include_the_files_of_judge_client(self):
        passwords = {judger_password(judger) for judger in uoj.JUDGERS}
        pids = {judge_client_pid(judger) for judger in uoj.JUDGERS}
        # the judgers start the same way, so judge_client has the same pid in each of them, and
        # whichever judger compiles the program finds a process there that it may not look at
        refused = "Permission denied" if len(pids) == 1 else "(Permission denied|No such file)"
        for name in ("environ", "cmdline", "status"):
            code = '#include "/proc/%d/%s"\nint main() {}\n' % (min(pids), name)
            judgement = uoj.wait_submission(self.student.submit(self.problem_id, code))
            self.assertEqual(judgement.error, "Compile Error", judgement)
            self.assertRegex(judgement.details, refused, judgement)
            for password in passwords:
                self.assertNotIn(password, judgement.raw_result.decode(), name)

    def test_the_languages_compile_as_before(self):
        # the compilers read their own entry of /proc and the files of the system there
        for language, code in AB_IN.items():
            judgement = uoj.wait_submission(self.student.submit(self.problem_id, code, language=language))
            self.assertEqual(judgement.score, 100, (language, judgement))
        self.assertEqual(uoj.wait_submission(self.student.submit(self.problem_id, AB)).score, 100)


JAVA_AB = """import java.util.Scanner;

public class Main {
    public static void main(String[] args) {
        Scanner in = new Scanner(System.in);
        long a = in.nextLong(), b = in.nextLong();
        %s
        System.out.println(a + b);
    }
}
"""


class JavaRunsTest(unittest.TestCase):
    """A Java program ran with an address space of twice its memory limit, and the JVM asked for
    a heap of 2 GB: every Java program was a runtime error. It runs as the others do now, held to
    its memory limit by what it uses."""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())
        cls.student = uoj.client(("p15_java", "p15-password"))

    def judge(self, code, language="Java17"):
        return uoj.wait_submission(self.student.submit(self.problem_id, code, language=language))

    def test_a_plus_b_passes_in_every_java(self):
        for language in ("Java8", "Java11", "Java17", "Java21"):
            judgement = self.judge(JAVA_AB % "", language)
            self.assertEqual(judgement.score, 100, (language, judgement))

    def test_a_program_may_use_much_of_its_memory(self):
        # 96 MB of a limit of 256 MB, with what the JVM needs besides
        code = JAVA_AB % "long[] kept = new long[12000000]; for (int i = 0; i < kept.length; i += 512) kept[i] = i; if (kept[512] != 512) return;"
        judgement = self.judge(code)
        self.assertEqual(judgement.score, 100, judgement)
        self.assertGreater(judgement.used_memory, 96 * 1024, judgement)

    def test_more_than_its_memory_is_not_passed(self):
        # 480 MB of a limit of 256 MB: the heap is no larger than the limit
        code = JAVA_AB % "long[] kept = new long[60000000]; for (int i = 0; i < kept.length; i += 512) kept[i] = i; if (kept[512] != 512) return;"
        judgement = self.judge(code)
        self.assertEqual(judgement.score, 0, judgement)
        for info in judgement.infos:
            self.assertIn(info, ("Memory Limit Exceeded", "Runtime Error"), judgement)



# passes the sample (5 7) and nothing else
AB_SAMPLE_ONLY = AB.replace("a + b", "a == 5 ? a + b : a + b + 1")


class OiFinalTestTest(unittest.TestCase):
    """An OI contest judges with the samples while it runs, and with all the data after it, the
    final test. A submission whose test with all the data had not been done when the final test
    started (the judgers do it when they have nothing else to do) was left out of the final test,
    and the score of its samples was published."""

    def test_what_still_waits_for_its_test_with_all_the_data_is_in_the_final_test(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p15 终测", rule="OI", problems=str(problem_id))
        pupil = uoj.client(("p15_final", "p15-password"))
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        with uoj.judgers_paused():
            submission_id = pupil.submit_in_contest(contest_id, problem_id, AB_SAMPLE_ONLY)
            # a judger tests it with the sample, the way judge_client does: it passes, and waits
            # for its test with all the data
            task = uoj.fake_fetch()
            self.assertEqual(task["id"], submission_id)
            sample = {"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}
            data = {"fetch_new": "0", "id": submission_id, "worker": uoj.FAKE_WORKER, "claim": task["claim"], "submit": "1", "result": json.dumps(sample)}
            uoj.judge_api("/judge/submit", data)
            self.assertEqual(db_value("select status from submissions where id = %d" % submission_id), "Judged, Waiting")
            self.assertIn("first_test_config", db_value("select content from submissions where id = %d" % submission_id))
            # the contest ends before a judger does that test, and the final test starts
            uoj.move_contest(contest_id, -7200, 60)
            self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "start_test"), "")
            row = db("select status, ifnull(judge_claim, 'NULL'), content from submissions where id = %d" % submission_id)[0]
            self.assertEqual(row[:2], ["Waiting Rejudge", "NULL"])
            self.assertNotIn("first_test_config", row[2])
            self.assertNotIn("test_sample_only", row[2])
        uoj.wait_idle()
        self.assertEqual(uoj.get_submission(submission_id).score, 0)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(db("select score from contests_submissions where contest_id = %d" % contest_id), [["0"]])


class ContestNoticeTest(unittest.TestCase):
    """What the staff of a contest wrote in a notice was printed as HTML on the dashboard of every
    contestant, scripts and all."""

    def test_a_notice_keeps_its_links_and_runs_no_script(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p15 公告", problems=str(problem_id))
        pupil = uoj.client(("p15_notice", "p15-password"))
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        content = 'A 题改了样例。\n<a href="https://example.com/faq">说明</a><script>alert(1)</script><img src="x" onerror="alert(2)">'
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "post_notice", {"title": "改样例", "content": content}), "")
        page = pupil.get("/contest/%d" % contest_id).text
        self.assertIn("A 题改了样例。\n", page)
        self.assertIn('<a href="https://example.com/faq">说明</a>', page)
        self.assertNotIn("<script>alert(1)", page)
        self.assertNotIn("onerror", page)

if __name__ == "__main__":
    unittest.main()
