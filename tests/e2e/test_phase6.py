"""End-to-end tests of phase 6: run-twice problems, numbers of problems inside a domain, the
switch for blogs, what a contest shows while it runs, the ICPC rule, and the forms that make a
problem or a contest in one go.

See test_phase1.py for how to start the containers.
"""

import json
import unittest

import test_phase3 as p3
import test_phase4 as p4
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


class DomainContestRatingTest(unittest.TestCase):
    """what happens in a domain stays out of the ratings of the site"""

    def test_contest_of_a_domain_counts_for_no_rating(self):
        admin = uoj.admin()
        teacher = p3.account("p6_rate_teacher")
        self.assertEqual(admin.change_user("p6_rate_teacher", "grant:teacher"), "")
        did = teacher.new_domain("p6-rating")
        pupils = [p3.account("p6_rate_pupil%d" % n) for n in range(2)]
        for n in range(2):
            self.assertEqual(p4.member_form(teacher, "p6-rating", "add", username="p6_rate_pupil%d" % n, role="member"), "")
        self.assertEqual(teacher.form("/d/p6-rating/problems", "new"), "")
        own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
        self.assertIn("上传成功", teacher.upload_data(own_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(own_id), "")
        self.assertEqual(teacher.form("/d/p6-rating/contests", "new", name="p6 域内赛", start_time=uoj.web_time(3600), last_min="60"), "")
        contest_id = int(db_value("select id from contests where domain_id = %d" % did))
        self.assertEqual(teacher.contest_commands(contest_id, "problems", "+%d" % uoj.pid(own_id)), "")

        # a setting that says the contest is rated is not believed, whoever wrote it
        db("update contests set extra_config = '{}' where id = %d" % contest_id)
        self.assertIn("不计入", uoj.text_of(admin.get("/contest/%d/manage" % contest_id).text))
        for pupil in pupils:
            pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        uoj.wait_submission(pupils[0].submit_in_contest(contest_id, own_id, AB))
        uoj.wait_submission(pupils[1].submit_in_contest(contest_id, own_id, AB_WRONG))
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "start_test"), "")
        uoj.wait_idle()
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "finished")

        for n in range(2):
            name = "p6_rate_pupil%d" % n
            self.assertEqual(db_value("select rating from user_info where username = '%s'" % name), "1500")
            self.assertEqual(db_value("select count(*) from user_system_msg where receiver = '%s' and title like 'Rating%%'" % name), "0")
            # and the history of the rating on the profile does not tell of the contest
            profile = admin.get("/user/profile/" + name).text
            self.assertNotIn("p6 域内赛", profile)
        self.assertIn('"rated":false', db_value(
            "select after_json from audit_logs where action = 'contest.publish_results' and resource_id = '%d'" % contest_id
        ))  # fmt: skip
        # the standings of the contest are there all the same
        self.assertEqual(
            db("select username, `rank` from contests_registrants where contest_id = %d order by `rank`" % contest_id),
            [["p6_rate_pupil0", "1"], ["p6_rate_pupil1", "2"]],
        )


if __name__ == "__main__":
    unittest.main()
