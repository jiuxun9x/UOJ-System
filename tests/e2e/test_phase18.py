"""Phase 18: tests that are an input alone, where the checker of a problem is its own."""

import unittest

import uoj
from fixtures import AB, AB_WRONG
from uoj import docker_exec

# a checker that needs no answer: the sum is what the input says it is
SUM_CHECKER = r"""
#include "testlib.h"
int main(int argc, char *argv[]) {
    registerTestlibCmd(argc, argv);
    long long a = inf.readLong(), b = inf.readLong();
    long long found = ouf.readLong();
    if (found != a + b) {
        quitf(_wa, "expected %lld, found %lld", a + b, found);
    }
    quitf(_ok, "the sum");
}
"""

# right where both numbers are below a million: the first tests, not the last
AB_SMALL = AB.replace("a + b", "a < 1000000 ? a + b : 0")


def data_settings(client, problem_id, **fields):
    """send the form of the judging settings of a problem: the answer"""
    form = dict(form="judge_settings", type="traditional", time_limit="1", memory_limit="256", checker="wcmp", scoring="per_test")
    form.update(fields)
    return client.post("/problem/%d/manage/data" % problem_id, form)


def uploaded(problem_id, name):
    return docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/%s" % (problem_id, name))


class InputOnlyTest(unittest.TestCase):
    """A problem whose own checker needs nothing but the input to judge what a program wrote had
    to have an answer file for every test all the same: without one, a test was not found, or the
    data did not pass the check."""

    def test_a_checker_of_its_own_needs_no_answers(self):
        admin = uoj.admin()
        problem_id = admin.new_problem()
        files = {"data1.in": "1 2\n", "data2.in": "1000 2000\n", "data3.in": "999999999 1\n", "chk.cpp": SUM_CHECKER}
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        data_settings(admin, problem_id, checker="custom", checker_file="chk.cpp")
        # the tests are found, and handed an empty answer
        self.assertIn("n_tests 3", uploaded(problem_id, "problem.conf"))
        for number in (1, 2, 3):
            self.assertEqual(docker_exec(uoj.WEB, "stat -c %%s /var/uoj_data/upload/%d/data%d.out" % (problem_id, number)).strip(), "0")
        self.assertEqual(admin.sync(problem_id), "")
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)
        judgement = uoj.wait_submission(admin.submit(problem_id, AB_SMALL))
        self.assertEqual((judgement.score, judgement.infos), (66, ["Accepted", "Accepted", "Wrong Answer"]), judgement)

    def test_a_problem_conf_written_by_hand(self):
        admin = uoj.admin()
        conf = uoj.conf(use_builtin_judger="on", n_tests=2, input_pre="data", input_suf="in", output_pre="data", output_suf="out", time_limit=1, memory_limit=256)
        # its own checker: the answers that are not there are empty
        own = admin.new_problem()
        self.assertIn("上传成功", admin.upload_data(own, {"problem.conf": conf, "data1.in": "1 2\n", "data2.in": "3 4\n", "chk.cpp": SUM_CHECKER}).text)
        self.assertEqual(admin.sync(own), "")
        self.assertEqual(uoj.wait_submission(admin.submit(own, AB)).score, 100)
        self.assertEqual(uoj.wait_submission(admin.submit(own, AB_WRONG)).score, 0)
        # a way of comparing of the site compares with the answer, and wants one: it says so
        builtin = admin.new_problem()
        self.assertIn("上传成功", admin.upload_data(builtin, {"problem.conf": conf + "use_builtin_checker wcmp\n", "data1.in": "1 2\n", "data2.in": "3 4\n"}).text)
        said = admin.sync(builtin)
        self.assertIn("data1.out", said)
        self.assertIn("内置的比较方式要拿答案比较", said)


if __name__ == "__main__":
    unittest.main()
