"""End-to-end tests of phase 6: run-twice problems, numbers of problems inside a domain, the
switch for blogs, what a contest shows while it runs, the ICPC rule, and the forms that make a
problem or a contest in one go.

See test_phase1.py for how to start the containers.
"""

import json
import unittest

import uoj
from fixtures import *
from uoj import db, db_value, docker_exec


def setUpModule():
    uoj.admin()


class RunTwiceTest(unittest.TestCase):
    """a program is run twice, and its second run knows of the first what the relay tells it"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(run_twice_problem_files())

    def judge(self, code, language="C++17"):
        return uoj.wait_submission(self.admin.submit(self.problem_id, code, language))

    def test_relay_is_built_by_a_judger(self):
        prepare, status, judger = db(
            "select prepare, status, judger_name from problem_data_versions where problem_id = %d" % self.problem_id
        )[0]
        self.assertEqual(
            sorted(step["name"] for step in json.loads(prepare)), ["chk", "relay"]
        )
        self.assertEqual(status, "ready")
        container = uoj.JUDGERS[uoj.JUDGER_NAMES.index(judger)]
        built = docker_exec(container, "ls /opt/uoj_judger/uoj_judger/data/%d | grep relay" % self.problem_id).split()
        self.assertEqual(sorted(built), ["relay", "relay.cpp"])

    def test_second_run_is_given_what_the_relay_made_of_the_first(self):
        # the relay hands the messages over in another order, which only its notes tell the checker
        j = self.judge(messages_solution())
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 2, j)
        self.assertIn("3 numbers", j.details)

    def test_relay_can_refuse_what_the_first_run_wrote(self):
        j = self.judge(messages_solution(width=41))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)
        self.assertIn("message 1 is longer than 40 characters", j.details)

    def test_second_run_that_dies(self):
        j = self.judge(messages_solution(second="return 3;"))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Runtime Error"] * 2, j)
        self.assertIn("in the second run", j.details)

    def test_first_run_that_dies(self):
        j = self.judge(messages_solution(burn='if (strcmp(run, "first") == 0) return 3;'))
        self.assertEqual(j.infos, ["Runtime Error"] * 2, j)
        self.assertIn("in the first run", j.details)

    def test_each_run_has_the_time_limit_to_itself(self):
        # six tenths of a second in each run, with a limit of one second
        burn = "volatile unsigned long long spin = 0; while (clock() < CLOCKS_PER_SEC * 6 / 10) spin = spin + 1;"
        j = self.judge(messages_solution(burn=burn))
        self.assertEqual(j.score, 100, j)
        # and the time of a test is that of its longer run, not of both
        self.assertGreaterEqual(j.used_time, 2 * 550, j)
        self.assertLess(j.used_time, 2 * 1000, j)

    def test_first_run_can_not_leave_a_file(self):
        j = self.judge(MESSAGES_STASH)
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Dangerous Syscalls"] * 2, j)

    def test_second_run_can_not_look_at_what_the_first_wrote(self):
        j = self.judge(MESSAGES_STAT_RESULT_FOLDER)
        self.assertEqual(j.score, 0, j)
        self.assertIn(j.infos[1], ("Wrong Answer", "Dangerous Syscalls"), j)
        self.assertNotIn("found 7", j.details)

    def test_work_folder_tells_the_second_run_nothing(self):
        # A program may list its work folder and see how large everything in it is. This one
        # does, in its second run, and looks for a file as large as its first run made it.
        j = self.judge(MESSAGES_LIST_WORK_FOLDER, "Python3")
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos[1], "Wrong Answer", j)
        self.assertIn("expected 7, found -1", j.details)

    def test_builtin_checker_reads_the_answer_file_when_the_relay_wrote_no_notes(self):
        problem_id = self.admin.create_problem(run_twice_plain_problem_files())
        j = uoj.wait_submission(self.admin.submit(problem_id, ECHO_THEN_DOUBLE))
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 2 + ["Extra Test Passed"], j)
        j = uoj.wait_submission(self.admin.submit(problem_id, ECHO_THEN_DOUBLE.replace("2 * n", "3 * n")))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)

    def test_run_twice_problem_can_not_be_hacked(self):
        problem_id = self.admin.create_problem(run_twice_plain_problem_files())
        r = self.admin.upload_data(problem_id, {"std.cpp": ECHO_THEN_DOUBLE, "val.cpp": ACCEPT_ANYTHING})
        self.assertIn("上传成功", r.text)
        err = self.admin.toggle_hackable(problem_id)
        self.assertIn("hackable", err)
        self.assertEqual(db_value("select hackable from problems where id = %d" % problem_id), "0")

    def test_run_twice_problem_without_a_relay_is_not_synced(self):
        files = run_twice_plain_problem_files()
        del files["relay.cpp"]
        problem_id = self.admin.new_problem()
        self.admin.upload_data(problem_id, files)
        self.assertIn("relay", self.admin.sync(problem_id))


if __name__ == "__main__":
    unittest.main()
