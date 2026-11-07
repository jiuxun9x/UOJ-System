"""Phase 20: hidden contests, and what a review of judging found (J01-J07, D01, D02, A01, A02)."""

import json
import unittest

import uoj
from fixtures import AB, AB_HACKABLE, ab_problem_files
from uoj import db, db_value, docker_exec

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


if __name__ == "__main__":
    unittest.main()
