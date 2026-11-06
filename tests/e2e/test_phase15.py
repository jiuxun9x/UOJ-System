"""Phase 15: what a check of the whole system found, mended. Each class is one of the defects,
told by what it does wrong and kept from coming back."""

import csv
import io
import json
import re
import unittest

import uoj
from fixtures import AB, AB_COMPILE_ERROR, AB_WRONG, DOUBLE, DOUBLE_INTERACTOR, ab_problem_files, checker_problem_files, interactive_problem_files
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


def rating_of(username):
    return int(db_value("select rating from user_info where username = '%s'" % username))


def rating_messages(username):
    return int(db_value("select count(*) from user_system_msg where receiver = '%s' and title like 'Rating%%'" % username))


class PublishingTest(unittest.TestCase):
    """Publishing the results of a contest changes the ratings of its contestants, once, from the
    ratings they have then. It could be done again after the results were published: the ratings
    were made anew from what each had when they registered, every message was sent again, and the
    board stayed as it was. And a contest published after another one took no notice of what the
    other had changed."""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(ab_problem_files())

    def held(self, name, pupils, codes):
        """a rated contest under the IOI rule that the pupils sat, with these programs, and is over"""
        contest_id = self.admin.new_contest(name, rule="IOI", problems=str(self.problem_id))
        for pupil in pupils:
            pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        ids = [pupil.submit_in_contest(contest_id, self.problem_id, code) for pupil, code in zip(pupils, codes)]
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200, 60)
        return contest_id, ids

    def ranks(self, contest_id):
        return db("select username, `rank` from contests_registrants where contest_id = %d order by username" % contest_id)

    def test_after_publishing_the_board_is_updated_and_the_ratings_stay(self):
        pupils = [uoj.client(("p15_pub_a", "p15-password")), uoj.client(("p15_pub_b", "p15-password"))]
        contest_id, (right, wrong) = self.held("p15 更新榜单", pupils, [AB, AB_WRONG])
        self.assertEqual(self.admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(self.ranks(contest_id), [["p15_pub_a", "1"], ["p15_pub_b", "2"]])
        ratings = [rating_of(p.username) for p in pupils]
        messages = [rating_messages(p.username) for p in pupils]
        self.assertGreater(ratings[0], ratings[1])
        # the results are published: the button that publishes them is gone, and the board is
        # updated after a rejudge that turned the outcomes round
        page = self.admin.get("/contest/%d" % contest_id).text
        self.assertNotIn('id="form-publish_result"', page)
        self.assertIn('id="form-update_standings"', page)
        db("update submissions set score = 0 where id = %d" % right)
        db("update submissions set score = 100 where id = %d" % wrong)
        self.assertEqual(self.admin.submit_form("/contest/%d" % contest_id, "update_standings"), "")
        self.assertEqual(self.ranks(contest_id), [["p15_pub_a", "2"], ["p15_pub_b", "1"]])
        scores = db("select submitter, score from contests_submissions where contest_id = %d order by submitter" % contest_id)
        self.assertEqual(scores, [["p15_pub_a", "0"], ["p15_pub_b", "100"]])
        self.assertEqual([rating_of(p.username) for p in pupils], ratings)
        self.assertEqual([rating_messages(p.username) for p in pupils], messages)
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'contest.update_standings' and resource_id = '%d'" % contest_id), "1")
        # nobody else may
        self.assertNotEqual(pupils[0].submit_form("/contest/%d" % contest_id, "update_standings"), "")

    def test_a_contest_published_later_counts_from_the_ratings_of_then(self):
        winner, loser = uoj.client(("p15_rate_w", "p15-password")), uoj.client(("p15_rate_l", "p15-password"))
        # both contests were sat, and registered for, before either was published
        first, _ = self.held("p15 先公布", [winner, loser], [AB, AB_WRONG])
        second, _ = self.held("p15 后公布", [winner, loser], [AB, AB_WRONG])
        self.assertEqual(self.admin.submit_form("/contest/%d" % first, "publish_result"), "")
        after_first = rating_of(winner.username)
        self.assertGreater(after_first, 1500)
        self.assertEqual(self.admin.submit_form("/contest/%d" % second, "publish_result"), "")
        # the second contest counted from what the first had made of the ratings, and its
        # history says so
        self.assertGreater(rating_of(winner.username), after_first)
        self.assertEqual(db_value("select user_rating from contests_registrants where contest_id = %d and username = '%s'" % (second, winner.username)), str(after_first))


# what site:tick does with the steps that failed, called the way cli.php calls it
TICK_ALERTS = r"""cat > /tmp/p15_tick.php <<'PHP'
<?php
$_SERVER['DOCUMENT_ROOT'] = '/opt/uoj/web';
require '/opt/uoj/web/app/libs/uoj-lib.php';
echo json_encode(siteTickAlerts(%s, array()));
PHP
php /tmp/p15_tick.php"""


class SiteTickTest(unittest.TestCase):
    """What the site does by itself every minute (cli.php site:tick, in a loop of the image) had all
    it said thrown away, and a step that failed kept the ones after it, the backups and the
    alerts among them, from being done, without a word."""

    def test_a_tick_tells_what_it_did(self):
        said = docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php site:tick")
        self.assertRegex(said, r"^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d advanced \d+ homeworks, showed \d+ problems, opened \d+ alerts")

    def test_the_loop_keeps_a_log(self):
        uoj.wait_until("site:tick writes its log", lambda: "advanced" in docker_exec(uoj.WEB, "cat /var/log/uoj-site-tick.log 2>/dev/null || true"), timeout=180)

    def test_a_step_that_fails_is_an_alert_until_it_works_again(self):
        before = int(db_value("select count(*) from user_system_msg where title = '告警：定时任务出错'"))
        opened = json.loads(docker_exec(uoj.WEB, TICK_ALERTS % "array('p15_step' => 'the disk is full')"))
        self.assertEqual(opened, [1, 0])
        message = db_value("select message from site_alerts where kind = 'tick_failed' and subject = 'p15_step' order by id desc limit 1")
        self.assertIn("the disk is full", message)
        self.assertGreater(int(db_value("select count(*) from user_system_msg where title = '告警：定时任务出错'")), before)
        docker_exec(uoj.WEB, TICK_ALERTS % "array()")
        self.assertEqual(db_value("select count(*) from site_alerts where kind = 'tick_failed' and subject = 'p15_step' and active_slot = 1"), "0")
        self.assertNotEqual(db_value("select ifnull(resolved_at, 'NULL') from site_alerts where kind = 'tick_failed' and subject = 'p15_step' order by id desc limit 1"), "NULL")


# a checker that can not judge a sum below zero, and says so the way testlib has for it
FAILING_CHECKER = r"""
#include "testlib.h"
int main(int argc, char **argv) {
    registerTestlibCmd(argc, argv);
    long long expected = ans.readLong();
    long long found = ouf.readLong();
    if (found < 0) {
        quitf(_fail, "this checker can not judge a negative sum");
    }
    if (found != expected) {
        quitf(_wa, "expected %lld, found %lld", expected, found);
    }
    quitf(_ok, "fine");
}
"""


class CheckerFailTest(unittest.TestCase):
    """A testlib checker that says FAIL (quitf(_fail, ...)) went wrong itself: the program it
    judged was given a wrong answer for it."""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(checker_problem_files(FAILING_CHECKER))
        cls.student = uoj.client(("p15_checker", "p15-password"))

    def test_a_checker_that_fails_is_a_judgment_failure(self):
        judgement = uoj.wait_submission(self.student.submit(self.problem_id, AB.replace('"%lld\\n", a + b', '"-1\\n"')))
        self.assertEqual(judgement.score, 0, judgement)
        self.assertTrue(judgement.infos, judgement)
        self.assertEqual(set(judgement.infos), {"Checker Judgment Failed"}, judgement)
        self.assertIn("this checker can not judge a negative sum", judgement.details)

    def test_a_wrong_answer_is_one_still(self):
        judgement = uoj.wait_submission(self.student.submit(self.problem_id, AB_WRONG))
        self.assertEqual(set(judgement.infos), {"Wrong Answer"}, judgement)
        self.assertEqual(uoj.wait_submission(self.student.submit(self.problem_id, AB)).score, 100)


# the interactor of DOUBLE, slow: it spends seconds of its own CPU time before it gives the number
SLOW_INTERACTOR = DOUBLE_INTERACTOR.replace(
    "std::cout << n << std::endl;",
    "volatile unsigned long long spent = 0; for (unsigned long long i = 0; i < 6000000000ULL; i++) spent = spent + 1;\n    std::cout << n << std::endl;",
)
# waits for a second number that never comes, while the interactor waits for its answer
DOUBLE_THAT_WAITS = DOUBLE.replace('scanf("%d", &n);', 'int m; scanf("%d%d", &n, &m);')


class InteractorTimeTest(unittest.TestCase):
    """An interactor that ran out of its own CPU time had the program it talked to given a time
    limit exceeded. It is the interactor that failed; a program the interactor waited for until
    the time was up still exceeded it."""

    def test_an_interactor_too_slow_is_a_judgment_failure(self):
        files = interactive_problem_files()
        files["interactor.cpp"] = SLOW_INTERACTOR
        problem_id = uoj.admin().create_problem(files)
        judgement = uoj.wait_submission(uoj.admin().submit(problem_id, DOUBLE))
        self.assertEqual(set(judgement.infos), {"Interactor Judgment Failed"}, judgement)
        self.assertIn("Interactor Time Limit Exceeded", judgement.details)

    def test_a_program_the_interactor_waits_for_exceeds_the_time(self):
        problem_id = uoj.admin().create_problem(interactive_problem_files())
        judgement = uoj.wait_submission(uoj.admin().submit(problem_id, DOUBLE_THAT_WAITS))
        self.assertEqual(set(judgement.infos), {"Time Limit Exceeded"}, judgement)
        self.assertEqual(uoj.wait_submission(uoj.admin().submit(problem_id, DOUBLE)).score, 100)


# A+B after some seconds of system calls, which take next to no time of its own
AB_IN_THE_KERNEL = r"""#include <cstdio>
#include <unistd.h>
#include <sys/syscall.h>
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    for (long i = 0; i < 60000000; i++) {
        syscall(SYS_getpid);
    }
    printf("%lld\n", a + b);
}
"""


class KernelTimeTest(unittest.TestCase):
    """The time limit counted the CPU time a program used itself, and not what it used in the
    kernel for it: a program that spends its time in system calls ran on until the time that went
    by stopped it, and was told it had used less than its limit."""

    def test_time_in_the_kernel_counts(self):
        # (the time that may go by is long, so that only the CPU time can stop it)
        problem_id = uoj.admin().create_problem(ab_problem_files(real_time_limit=30))
        judgement = uoj.wait_submission(uoj.admin().submit(problem_id, AB_IN_THE_KERNEL), timeout=600)
        self.assertEqual(set(judgement.infos), {"Time Limit Exceeded"}, judgement)
        self.assertEqual(uoj.wait_submission(uoj.admin().submit(problem_id, AB)).score, 100)


class VirtualHiddenProblemTest(unittest.TestCase):
    """Somebody who sat a contest virtually, whose problems are still hidden, could not see what
    they submitted in it: it was kept as a submission to a hidden problem, for nobody but the
    staff."""

    def test_what_is_submitted_virtually_to_a_hidden_problem_is_its_submitters_to_see(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        db("update problems set is_hidden = 1 where id = %d" % problem_id)
        contest_id = admin.new_contest("p15 虚拟参赛隐藏题", rule="IOI", problems=str(problem_id))
        here = "/contest/%d" % contest_id
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form(here, "publish_result"), "")
        sitter, other = uoj.client(("p15_sitter", "p15-password")), uoj.client(("p15_other", "p15-password"))
        r = sitter.post(here + "/virtual", {"form": "start"})
        self.assertEqual((r.status_code, r.headers["Location"]), (302, here))
        own = sitter.submit(problem_id, AB, path=here + "/problem/A")
        uoj.wait_submission(own)
        self.assertEqual(db_value("select is_hidden from submissions where id = %d" % own), "1")
        self.assertIn('href="/submission/%d"' % own, sitter.get(here + "/submissions").text)
        self.assertIn('href="/submission/%d"' % own, sitter.get("/submissions", params={"submitter": sitter.username}).text)
        self.assertEqual(sitter.get("/submission/%d" % own).status_code, 200)
        # and nobody else's, as any submission to a hidden problem
        self.assertEqual(other.get("/submission/%d" % own).status_code, 403)
        self.assertNotIn('href="/submission/%d"' % own, other.get("/submissions", params={"submitter": sitter.username}).text)


def solved_on_profile(viewer, username, problem_id):
    return ('<a href="/problem/%d" style="display:inline-block; width:4em;">' % problem_id) in viewer.get("/user/profile/" + username).text


class ProfileSolvedTest(unittest.TestCase):
    """The problems somebody solved were listed on their profile whatever they were: problems that
    are hidden, and what they solved in a contest that runs, which told the problems of the
    contest and who solved them before the board did."""

    def test_the_profile_tells_only_what_everybody_may_know(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p15 主页", rule="ICPC", problems=str(problem_id))
        pupil, other = uoj.client(("p15_profile", "p15-password")), uoj.client(("p15_looker", "p15-password"))
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        uoj.wait_submission(pupil.submit_in_contest(contest_id, problem_id, AB))
        # while the contest runs: to the pupil and the staff, not to the others
        self.assertTrue(solved_on_profile(pupil, pupil.username, problem_id))
        self.assertTrue(solved_on_profile(admin, pupil.username, problem_id))
        self.assertFalse(solved_on_profile(other, pupil.username, problem_id))
        self.assertFalse(solved_on_profile(uoj.Client(), pupil.username, problem_id))
        # once it is over, to everybody
        uoj.move_contest(contest_id, -7200, 60)
        self.assertTrue(solved_on_profile(other, pupil.username, problem_id))
        # a problem that is hidden is not told of
        db("update problems set is_hidden = 1 where id = %d" % problem_id)
        try:
            self.assertFalse(solved_on_profile(other, pupil.username, problem_id))
            self.assertTrue(solved_on_profile(pupil, pupil.username, problem_id))
        finally:
            db("update problems set is_hidden = 0 where id = %d" % problem_id)


def full_scores_in_statistics(viewer, problem_id):
    """how many submissions of score 100 the statistics of a problem count, as the page says"""
    page = viewer.get("/problem/%d/statistics" % problem_id).text
    data = json.loads(re.search(r"new Morris\.Bar\(\{\s*element: 'score-distribution-chart',\s*data: (\[.*?\]),", page, re.S).group(1))
    return sum(int(row["count"]) for row in data if int(row["score"]) == 100)


class StatisticsInContestTest(unittest.TestCase):
    """The statistics of a problem counted every submission to it: of a problem in a contest that
    runs, or whose board is frozen, they told how many had solved it there."""

    def test_the_statistics_wait_for_the_contest(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p15 统计", rule="ICPC", problems=str(problem_id))
        pupil, other = uoj.client(("p15_stats", "p15-password")), uoj.client(("p15_stats_look", "p15-password"))
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        uoj.wait_submission(pupil.submit_in_contest(contest_id, problem_id, AB))
        self.assertEqual(full_scores_in_statistics(other, problem_id), 0)
        self.assertEqual(full_scores_in_statistics(admin, problem_id), 1)
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(full_scores_in_statistics(other, problem_id), 1)


class ExportRanksTest(unittest.TestCase):
    """The ranks of the export of a board were the places in it: who was level with somebody was a
    place after them in the file and their equal on the board."""

    def test_the_export_has_the_ranks_of_the_board(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p15 导出排名", rule="IOI", problems=str(problem_id))
        pupils = [uoj.client(("p15_export_%s" % n, "p15-password")) for n in ("a", "b", "c")]
        for pupil in pupils:
            pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        for pupil, code in zip(pupils, (AB, AB_WRONG, AB_WRONG)):
            pupil.submit_in_contest(contest_id, problem_id, code)
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        rows = list(csv.reader(io.StringIO(admin.get("/contest/%d/export_standings" % contest_id).text)))
        ranks = {row[1]: row[0] for row in rows if len(row) > 1 and row[1].startswith("p15_export_")}
        self.assertEqual(ranks, {"p15_export_a": "1", "p15_export_b": "2", "p15_export_c": "2"})


class FrozenBoardLastTryTest(unittest.TestCase):
    """The outer board tells, under a problem that was tried in vain, when it was tried last. A
    submission that did not compile is no attempt on the board, but was taken for the last try,
    and told on the frozen board when it was made after the board froze."""

    def test_a_frozen_board_tells_no_time_of_after_the_freeze(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p15 封榜时间", rule="ICPC", problems=str(problem_id), freeze_minutes="30")
        pupil, other = uoj.client(("p15_frozen", "p15-password")), uoj.client(("p15_frozen_look", "p15-password"))
        pupil.register_for_contest(contest_id)
        # forty minutes into the contest, frozen since thirty
        uoj.move_contest(contest_id, -40 * 60, 60)
        made = [(10, pupil.submit_in_contest(contest_id, problem_id, AB_WRONG)), (35, pupil.submit_in_contest(contest_id, problem_id, AB_COMPILE_ERROR))]
        uoj.wait_idle()
        for minutes, submission_id in made:
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute) where id = %d" % (contest_id, minutes, submission_id))
        told = other.get("/contest/%d/board?json=1" % contest_id).json()
        self.assertTrue(told["frozen"])
        row = next(row for row in told["rows"] if row["username"] == pupil.username)
        self.assertEqual(row["cells"][0][0], "failed", row)
        self.assertEqual(row["cells"][0][2], "0:10", row)


class RemovedThingsTest(unittest.TestCase):
    """What was submitted to a problem that was taken out of a contest stood on its board under no
    problem at all, with a warning every time the board was made; the page of a hack whose
    submission was deleted failed."""

    def test_a_problem_taken_out_of_a_contest_leaves_its_board(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p15 移除题目", rule="IOI", problems="%d, %d" % (first, second))
        pupil = uoj.client(("p15_removed", "p15-password"))
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        for problem_id in (first, second):
            pupil.submit_in_contest(contest_id, problem_id, AB)
        uoj.wait_idle()
        db("delete from contests_problems where contest_id = %d and problem_id = %d" % (contest_id, second))
        page = pupil.get("/contest/%d/standings" % contest_id).text
        cells = json.loads(re.search(r"(?m)^score=(.*);$", page).group(1))[pupil.username]
        positions = [str(n) for n in range(len(cells))] if isinstance(cells, list) else list(cells)
        self.assertEqual(positions, ["0"], cells)

    def test_the_hack_of_a_deleted_submission_is_gone(self):
        problem_id = uoj.admin().create_problem(ab_problem_files())
        db("insert into hacks (problem_id, contest_id, submission_id, hacker, owner, input, input_type, submit_time, judge_time, success, details, is_hidden)"
           " values (%d, null, 3999999999, 'p15_hacker', 'p15_owner', 'none', 'USE_FORMATTER', now(), now(), 0, '', 0)" % problem_id)  # fmt: skip
        hack_id = int(db_value("select max(id) from hacks where submission_id = 3999999999"))
        try:
            self.assertEqual(uoj.admin().get("/hack/%d" % hack_id).status_code, 404)
        finally:
            db("delete from hacks where id = %d" % hack_id)


class ForgedRequestTest(unittest.TestCase):
    """A private message and a vote were taken from any page that made the browser of a user post
    them: neither asked for the token every form of the site sends. And a vote on what the user may
    not see told how many had liked it."""

    def test_a_message_needs_the_token(self):
        sender, receiver = uoj.client(("p15_sender", "p15-password")), uoj.client(("p15_receiver", "p15-password"))
        count = lambda: int(db_value("select count(*) from user_msg where sender = 'p15_sender' and receiver = 'p15_receiver'"))
        before = count()
        forged = sender.post("/user/msg", {"user_msg": "1", "receiver": receiver.username, "message": "forged"}, token=False)
        self.assertEqual(forged.text, "expired")
        self.assertEqual(count(), before)
        sent = sender.post("/user/msg", {"user_msg": "1", "receiver": receiver.username, "message": "hello"})
        self.assertEqual(sent.text, "ok")
        self.assertEqual(count(), before + 1)
        # the page hands its script the token
        self.assertIn("uojToken = '%s'" % sender.token, sender.get("/user/msg").text)

    def test_a_vote_needs_the_token_and_a_problem_one_may_see(self):
        admin = uoj.admin()
        public = admin.create_problem(ab_problem_files())
        hidden = admin.create_problem(ab_problem_files())
        db("update problems set is_hidden = 1 where id = %d" % hidden)
        voter = uoj.client(("p15_voter", "p15-password"))
        zan = lambda problem_id: int(db_value("select zan from problems where id = %d" % problem_id))
        before = zan(public)
        forged = voter.post("/click-zan", {"id": str(public), "delta": "1", "type": "P"}, token=False)
        self.assertIn("failed", forged.text)
        self.assertEqual(zan(public), before)
        self.assertNotIn("failed", voter.post("/click-zan", {"id": str(public), "delta": "1", "type": "P"}).text)
        self.assertEqual(zan(public), before + 1)
        hidden_before = zan(hidden)
        self.assertIn("failed", voter.post("/click-zan", {"id": str(hidden), "delta": "1", "type": "P"}).text)
        self.assertEqual(zan(hidden), hidden_before)


class RequestAddressTest(unittest.TestCase):
    """The forms of the site wrote the address the browser asked for into the page as it was: into
    their action, and into a string of their script, which a quote in the address ended."""

    def test_the_address_is_escaped_where_it_is_written(self):
        problem_id = uoj.admin().create_problem(ab_problem_files())
        student = uoj.client(("p15_address", "p15-password"))
        # (a quote is sent as it is; the browsers that encode it are many, not all)
        page = student.get("/problem/%d?x=';alert(1);//" % problem_id).text
        self.assertIn('id="form-custom_test"', page)
        self.assertNotIn("x=';alert(1)", page)


class JudgeDownloadTest(unittest.TestCase):
    """The query of a request changed what its route said: /judge/download/tmp/<name>?type=... was
    any download, and ?type=submission&id=... made a path out of whatever id said."""

    NAME = "p15tmpfile0000000001"

    def setUp(self):
        docker_exec(uoj.WEB, "printf 'the input of a hack' > /opt/uoj/web/app/storage/tmp/%s" % self.NAME)

    def tearDown(self):
        docker_exec(uoj.WEB, "rm -f /opt/uoj/web/app/storage/tmp/%s" % self.NAME)

    def test_the_route_says_what_is_downloaded(self):
        expected = uoj.sha256(b"the input of a hack")
        r = uoj.judge_api("/judge/download/tmp/%s" % self.NAME)
        self.assertEqual((r.status_code, r.headers.get("X-UOJ-SHA256")), (200, expected))
        r = uoj.judge_api("/judge/download/tmp/%s?type=judger" % self.NAME)
        self.assertEqual((r.status_code, r.headers.get("X-UOJ-SHA256")), (200, expected))
        r = uoj.judge_api("/judge/download/tmp/%s?type=submission&id=../.." % self.NAME)
        self.assertEqual((r.status_code, r.headers.get("X-UOJ-SHA256")), (200, expected))

if __name__ == "__main__":
    unittest.main()
