"""Phase 20: hidden contests, and what a review of judging found (J01-J07, D01, D02, A01, A02)."""

import json
import unittest

import uoj
from fixtures import AB, AB_HACKABLE, ab_problem_files
from uoj import conf, db, db_value, docker_exec

# a checker of a+b that went wrong where an answer differs: it says FAIL, as testlib says when a
# check of its own fails; put right, it says the answer is wrong
CHECKER = r"""
#include "testlib.h"
int main(int argc, char *argv[]) {
    registerTestlibCmd(argc, argv);
    long long expected = ans.readLong(), found = ouf.readLong();
    if (expected != found) {
        quitf(%s, "expected %%lld, found %%lld", expected, found);
    }
    quitf(_ok, "%%lld", found);
}
"""


def with_checker(verdict):
    files = ab_problem_files()
    files["problem.conf"] = "".join(line + "\n" for line in files["problem.conf"].splitlines() if not line.startswith("use_builtin_checker"))
    files["chk.cpp"] = CHECKER % verdict
    return files


class HackJudgementFailedTest(unittest.TestCase):
    """A hack whose checker failed was a hack that succeeded: what the checker said FAIL of, or
    crashed on, was taken for a wrong answer, and the input was added to the data. The judgement
    fails now, the hack neither succeeds nor fails, nothing is added, and the input is kept to
    judge it again once the checker is put right. (J03)"""

    def test_a_checker_that_fails_makes_no_successful_hack(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(with_checker("_fail"), hackable=True)
        submission_id = admin.submit(problem_id, AB_HACKABLE % 4242)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        version = db_value("select data_version from problems where id = %d" % problem_id)

        hack_id = admin.hack(submission_id, b"4242 1\n")
        self.assertFalse(uoj.wait_hack(hack_id))
        success, error, details, path = db("select success, ifnull(result_error, 'NULL'), details, input from hacks where id = %d" % hack_id)[0]
        self.assertEqual((success, error), ("0", "Judgment Failed"), details)
        self.assertIn("Checker Judgment Failed", details)
        self.assertIn("expected 4243, found 0", details)
        # nothing was added to the data, and the input is kept
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), version)
        docker_exec(uoj.WEB, "test -f /opt/uoj/web/app/storage%s" % path)
        page = admin.get("/hack/%d" % hack_id).text
        self.assertIn("Judgement Failed", page)
        self.assertIn('id="hack-judgement-failed"', page)
        # only who manages the problem judges it again
        self.assertNotIn('id="hack-judgement-failed"', uoj.Client().get("/hack/%d" % hack_id).text)

        # ---- the checker put right, the hack is judged again: it succeeds, and its input is a test
        r = admin.upload_data(problem_id, with_checker("_wa"))
        self.assertIn("上传成功", r.text)
        self.assertEqual(admin.sync(problem_id), "")
        version = int(db_value("select data_version from problems where id = %d" % problem_id))
        self.assertEqual(admin.submit_form("/hack/%d" % hack_id, "rejudge"), "")
        self.assertTrue(uoj.wait_hack(hack_id))
        self.assertEqual(db_value("select ifnull(result_error, 'NULL') from hacks where id = %d" % hack_id), "NULL")
        # its input is an extra test of a new version of the data
        uoj.wait_until("the input of the hack is a test", lambda: int(db_value("select data_version from problems where id = %d" % problem_id)) > version)


class RejudgeBetweenTheTwoTestsTest(unittest.TestCase):
    """A submission of an OI contest is judged with the samples while the contest runs, then with
    all the data when the judgers have nothing else to do. Judged again while it waited for that
    second test, it was left Judged without a score: the test of the samples was skipped, and the
    test with all the data was taken for the second of two, which keeps the score of the first.
    It begins with the samples again now. (J05)"""

    def test_a_submission_judged_again_between_its_two_tests_is_judged_from_the_samples(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p20 两段评测中重测", rule="OI", problems=str(problem_id))
        pupil = uoj.client(("p20_rejudge", "p20-password"))
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        with uoj.judgers_paused():
            submission_id = pupil.submit_in_contest(contest_id, problem_id, AB)
            # tested with the sample, the way judge_client does it: it waits for all the data
            task = uoj.fake_fetch()
            self.assertEqual(task["id"], submission_id)
            sample = {"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}
            uoj.judge_api("/judge/submit", {"fetch_new": "0", "id": submission_id, "worker": uoj.FAKE_WORKER, "claim": task["claim"], "submit": "1", "result": json.dumps(sample)})
            self.assertEqual(db_value("select status from submissions where id = %d" % submission_id), "Judged, Waiting")
            # who manages the problem judges everything of it again
            self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "rejudge"), "")
            status, content = db("select status, content from submissions where id = %d" % submission_id)[0]
            self.assertEqual(status, "Waiting Rejudge")
            content = json.loads(content)
            self.assertNotIn("first_test_config", content)
            self.assertIn("final_test_config", content)
        uoj.wait_idle()
        # both tests done again, one after the other: the score of the samples, the result of all the data
        status, score, result = db("select status, ifnull(score, 'NULL'), result from submissions where id = %d" % submission_id)[0]
        self.assertEqual((status, score), ("Judged", "100"), result)
        self.assertEqual(json.loads(result)["final_result"]["score"], 100)
        self.assertEqual(db_value("select count(*) from best_ac_submissions where submission_id = %d" % submission_id), "1")


def answers_problem(n_tests, submit_answer=True):
    """a problem of answers with so many tests (or the same as a problem of programs)"""
    settings = dict(use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=n_tests, n_ex_tests=0, n_sample_tests=0,
                    input_pre="input", input_suf="txt", output_pre="output", output_suf="txt", time_limit=1, memory_limit=256)  # fmt: skip
    if submit_answer:
        settings["submit_answer"] = "on"
    files = {"problem.conf": conf(**settings)}
    for num in range(1, n_tests + 1):
        files["input%d.txt" % num] = "%d\n" % num
        files["output%d.txt" % num] = "%d\n" % num
    if not submit_answer:
        files["std.cpp"] = "#include <cstdio>\nint main() { int x; scanf(\"%d\", &x); printf(\"%d\\n\", x); }\n"
    return files


class SubmissionFilesFollowTheDataTest(unittest.TestCase):
    """The files a submission is made of were put down the first time the data of a problem was
    published, and never again: a problem of answers that got a second test asked for one answer
    still, and one that became a problem of programs for answers. They follow the data now,
    unless somebody wrote them by hand. (D02)"""

    def test_the_files_of_a_submission_follow_the_data(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(answers_problem(1))
        requirement = lambda: json.loads(db_value("select submission_requirement from problems where id = %d" % problem_id))
        publish = lambda files: (self.assertIn("上传成功", admin.upload_data(problem_id, files).text), self.assertEqual(admin.sync(problem_id), ""))
        self.assertEqual([item["file_name"] for item in requirement()], ["output1.txt"])
        # a test more is an answer more
        publish(answers_problem(2))
        self.assertEqual([item["file_name"] for item in requirement()], ["output1.txt", "output2.txt"])
        page = admin.get("/problem/%d" % problem_id).text
        self.assertIn("output2.txt", page)
        # a problem of programs takes a program, and of answers again its answers
        publish(answers_problem(2, submit_answer=False))
        self.assertEqual(requirement(), [{"name": "answer", "type": "source code", "file_name": "answer.code"}])
        publish(answers_problem(3))
        self.assertEqual(len(requirement()), 3)

        # ---- written by hand, they stay as they are, and the page says what the data asks for
        extra = db_value("select json_extract(extra_config, '$') from problems where id = %d" % problem_id)
        mine = [{"name": "output1", "type": "text", "file_name": "output1.txt"}, {"name": "notes", "type": "text", "file_name": "notes.txt"}]
        self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "info", {"submission_requirement": json.dumps(mine), "extra_config": extra}), "")
        publish(answers_problem(4))
        self.assertEqual(requirement(), mine)
        self.assertIn('id="requirement-custom"', admin.get("/problem/%d/manage/data" % problem_id).text)
        # written back to nothing, they follow the data again
        extra = db_value("select json_extract(extra_config, '$') from problems where id = %d" % problem_id)
        self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "info", {"submission_requirement": "[]", "extra_config": extra}), "")
        publish(answers_problem(4))
        self.assertEqual(len(requirement()), 4)


if __name__ == "__main__":
    unittest.main()
