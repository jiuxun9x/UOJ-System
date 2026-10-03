"""Conformance tests of the judger: what a program does, and the verdict it gets.

The other modules cover the rest of the list: custom tests and extra tests in MemoryLimitTest,
hacks and binary data in BinaryHackTest, many tests in LargeResultTest, checkers, validators and
interactors in ProblemProgramsTest, several judgers in SeveralJudgersTest, a busy machine in
test_load.py.

See test_phase1.py for how to start the containers.
"""

import unittest

import uoj
from fixtures import *
from uoj import db, db_value


def setUpModule():
    uoj.admin()


class VerdictTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())

    def judge(self, code):
        return uoj.wait_submission(uoj.admin().submit(self.problem_id, code))

    def assert_all_tests(self, code, verdict):
        j = self.judge(code)
        self.assertEqual(j.infos, [verdict] * 3, j)
        self.assertEqual(j.score, 0, j)

    def test_accepted(self):
        j = self.judge(AB)
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 3 + ["Extra Test Passed"], j)

    def test_wrong_answer(self):
        self.assert_all_tests(AB_WRONG, "Wrong Answer")

    def test_segmentation_fault(self):
        self.assert_all_tests(AB_NULL_POINTER, "Runtime Error")

    def test_floating_point_exception(self):
        self.assert_all_tests(AB_DIVISION_BY_ZERO, "Runtime Error")

    def test_abort(self):
        self.assert_all_tests(AB_ABORT, "Runtime Error")

    def test_cpu_time_limit(self):
        self.assert_all_tests(AB_TIME_LIMIT, "Time Limit Exceeded")

    def test_real_time_limit(self):
        # a program that waits instead of computing is stopped as well
        self.assert_all_tests(AB_SLEEPING, "Time Limit Exceeded")

    def test_memory_limit(self):
        j = self.judge(ECHO_ALLOCATING % {"trigger": 1, "megabytes": 400})
        self.assertEqual(j.infos[0], "Memory Limit Exceeded", j)

    def test_output_limit(self):
        self.assert_all_tests(AB_OUTPUT_LIMIT, "Output Limit Exceeded")

    def test_compile_error(self):
        j = self.judge(AB_COMPILE_ERROR)
        self.assertEqual(j.error, "Compile Error", j)
        self.assertIsNone(j.score)

    def test_stack_is_as_large_as_the_memory_limit_by_default(self):
        j = self.judge(AB_DEEP_RECURSION)
        self.assertEqual(j.score, 100, j)
        j = self.judge(AB_LARGE_LOCAL_ARRAY)
        self.assertEqual(j.score, 100, j)
        self.assertGreater(j.used_memory, 100 << 10)

    # what the sandbox does not let a program do

    def test_new_process(self):
        self.assert_all_tests(AB_FORK, "Dangerous Syscalls")

    def test_new_thread(self):
        self.assert_all_tests(AB_THREAD, "Dangerous Syscalls")

    def test_writing_a_file(self):
        self.assert_all_tests(AB_WRITE_FILE, "Dangerous Syscalls")
        for judger in uoj.JUDGERS:
            self.assertEqual(uoj.docker_exec(judger, "ls /tmp/uoj_e2e_escape.txt 2>/dev/null || true").strip(), "")

    def test_reading_the_secrets_of_the_judger(self):
        self.assert_all_tests(AB_READ_SECRET, "Dangerous Syscalls")

    def test_rejudge(self):
        submission_id = uoj.admin().submit(self.problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

        self.assertEqual(uoj.admin().submit_form("/submission/%d" % submission_id, "rejudge"), "")
        uoj.wait_until(
            "the submission is judged again",
            lambda: len(uoj.judgements("submission", submission_id)) == 2 and uoj.get_submission(submission_id),
        )
        self.assertEqual(uoj.get_submission(submission_id).score, 100)
        self.assertEqual([outcome for _, outcome in uoj.judgements("submission", submission_id)], ["judged"] * 2)


if __name__ == "__main__":
    unittest.main()
