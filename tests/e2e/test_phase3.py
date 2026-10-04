"""End-to-end tests for phase 3: roles, who may see which submission, accounts and single
sign-on.

See test_phase1.py for how to start the containers.
"""

import unittest

import uoj
from fixtures import *
from uoj import db, db_value


def setUpModule():
    uoj.admin()


def account(name):
    return uoj.client((name, name + "-password"))


def roles_of(username):
    return sorted(row[0] for row in db("select role from user_roles where username = '%s'" % username))


def usergroup_of(username):
    return db_value("select usergroup from user_info where username = '%s'" % username)


class RolesTest(unittest.TestCase):
    """the roles of the whole site, and the people who run one contest"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = account("p3_teacher")
        cls.colleague = account("p3_colleague")
        cls.assistant = account("p3_assistant")
        cls.student = account("p3_student")
        cls.oj_admin = account("p3_ojadmin")
        for username, role in (("p3_teacher", "teacher"), ("p3_colleague", "teacher"), ("p3_ojadmin", "oj_admin")):
            assert cls.admin.change_user(username, "grant:" + role) == ""

    def test_roles_are_granted(self):
        self.assertEqual(roles_of("p3_teacher"), ["teacher"])
        self.assertEqual(roles_of("p3_ojadmin"), ["oj_admin"])
        self.assertEqual(roles_of("p3_student"), [])
        self.assertEqual(usergroup_of("p3_ojadmin"), "U")

    def test_student_is_kept_out_of_management(self):
        problem_id = self.admin.new_problem()
        contest_id = self.admin.new_contest("p3 closed doors")
        for path in (
            "/super-manage",
            "/super-manage/judger",
            "/contest/new",
            "/contest/%d/manage" % contest_id,
            "/contest/%d/backstage" % contest_id,
            "/contest/%d/export_standings" % contest_id,
            "/problem/%d/manage/statement" % problem_id,
            "/problem/%d/manage/data" % problem_id,
            "/problem/%d/manage/managers" % problem_id,
        ):
            # a contest that has not started sends its visitors to the registration
            self.assertIn(self.student.get(path).status_code, (302, 403, 404), path)

        problems = db_value("select count(*) from problems")
        contests = db_value("select count(*) from contests")
        self.student.submit_form("/problems", "new_problem")
        self.student.submit_form("/contest/new", "time", {"name": "x", "start_time": uoj.web_time(), "last_min": "60"})
        self.assertEqual(db_value("select count(*) from problems"), problems)
        self.assertEqual(db_value("select count(*) from contests"), contests)
        self.assertNotEqual(self.student.change_user("p3_student", "grant:teacher"), "")
        self.assertEqual(roles_of("p3_student"), [])

    def test_teacher_manages_the_problem_they_create(self):
        problem_id = self.teacher.new_problem()
        self.assertEqual(
            db("select username from problems_permissions where problem_id = %d" % problem_id), [["p3_teacher"]]
        )
        self.assertEqual(self.teacher.get("/problem/%d/manage/data" % problem_id).status_code, 200)
        self.assertEqual(self.teacher.get("/problem/%d" % problem_id).status_code, 200)
        # a hidden problem of somebody else stays hidden from another teacher
        self.assertEqual(self.colleague.get("/problem/%d" % problem_id).status_code, 404)
        self.assertEqual(self.colleague.get("/problem/%d/manage/data" % problem_id).status_code, 403)
        # an administrator of the OJ manages every problem
        self.assertEqual(self.oj_admin.get("/problem/%d/manage/data" % problem_id).status_code, 200)

    def test_teacher_runs_a_contest_without_being_an_administrator(self):
        contest_id = self.teacher.new_contest("p3 contest of a teacher")
        self.assertEqual(
            db("select username, role from contests_permissions where contest_id = %d" % contest_id),
            [["p3_teacher", "owner"]],
        )
        # only an administrator decides that a contest counts for the ratings of the site
        self.assertIn("unrated", db_value("select extra_config from contests where id = %d" % contest_id))
        self.assertEqual(self.teacher.get("/contest/%d/manage" % contest_id).status_code, 200)
        self.assertEqual(self.colleague.get("/contest/%d/manage" % contest_id).status_code, 403)
        self.assertEqual(self.oj_admin.get("/contest/%d/manage" % contest_id).status_code, 200)

        # the owner sets the problems, but only problems they manage
        problem_id = self.teacher.new_problem()
        foreign_problem_id = self.admin.new_problem()
        self.assertEqual(self.teacher.contest_commands(contest_id, "problems", "+%d" % problem_id), "")
        self.assertNotEqual(self.teacher.contest_commands(contest_id, "problems", "+%d" % foreign_problem_id), "")
        self.assertEqual(
            db("select problem_id from contests_problems where contest_id = %d" % contest_id), [[str(problem_id)]]
        )

        # the owner chooses the staff
        self.assertEqual(self.teacher.contest_commands(contest_id, "managers", "+p3_assistant"), "")
        self.assertEqual(
            db_value(
                "select role from contests_permissions where contest_id = %d and username = 'p3_assistant'"
                % contest_id
            ),
            "assistant",
        )
        # an assistant looks behind the scenes, but does not run the contest
        self.assertEqual(self.assistant.get("/contest/%d/backstage" % contest_id).status_code, 200)
        self.assertEqual(self.assistant.get("/contest/%d/manage" % contest_id).status_code, 403)
        self.assistant.contest_commands(contest_id, "managers", "+p3_student [owner]")
        self.assertEqual(
            db_value("select count(*) from contests_permissions where contest_id = %d" % contest_id), "2"
        )

        # once the contest is over, only the owner starts the final test and publishes the results
        uoj.move_contest(contest_id, -7200)
        for stranger in (self.assistant, self.colleague, self.student):
            stranger.submit_form("/contest/%d" % contest_id, "start_test")
            self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "unfinished")
        self.assertEqual(self.teacher.submit_form("/contest/%d" % contest_id, "start_test"), "")
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "testing")
        self.assertEqual(self.teacher.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "finished")

        # the owner can hand the contest to somebody else
        self.assertEqual(self.teacher.contest_commands(contest_id, "managers", "+p3_colleague [owner]"), "")
        self.assertEqual(self.colleague.get("/contest/%d/manage" % contest_id).status_code, 200)

    def test_only_a_system_administrator_changes_roles(self):
        victim = account("p3_victim")
        # an administrator of the OJ sees the administration, but not the judgers
        self.assertEqual(self.oj_admin.get("/super-manage/users").status_code, 200)
        self.assertEqual(self.oj_admin.get("/super-manage/judger").status_code, 404)
        self.assertEqual(self.admin.get("/super-manage/judger").status_code, 200)
        self.assertNotIn("_judger_password_", self.oj_admin.get("/super-manage/users").text)

        for operation in ("grant:teacher", "grant:oj_admin", "superuser", "revoke:teacher"):
            self.assertNotEqual(self.oj_admin.change_user("p3_victim", operation), "", operation)
        self.assertEqual(roles_of("p3_victim"), [])
        self.assertEqual(usergroup_of("p3_victim"), "U")
        self.assertEqual(roles_of("p3_teacher"), ["teacher"])

        # they may ban a user, but not an administrator
        self.assertEqual(self.oj_admin.change_user("p3_victim", "banneduser"), "")
        self.assertEqual(usergroup_of("p3_victim"), "B")
        self.assertEqual(victim.get("/user/modify-profile").status_code, 302)
        self.assertEqual(self.oj_admin.change_user("p3_victim", "normaluser"), "")
        self.assertEqual(usergroup_of("p3_victim"), "U")
        self.assertNotEqual(self.oj_admin.change_user(uoj.ADMIN[0], "banneduser"), "")
        self.assertNotEqual(self.oj_admin.change_user(uoj.ADMIN[0], "normaluser"), "")
        self.assertEqual(usergroup_of(uoj.ADMIN[0]), "S")

        # a banned user loses the roles they had
        self.assertEqual(self.admin.change_user("p3_victim", "grant:teacher"), "")
        self.assertEqual(self.admin.change_user("p3_victim", "banneduser"), "")
        self.assertEqual(roles_of("p3_victim"), [])
        self.assertEqual(self.admin.change_user("p3_victim", "normaluser"), "")

    def test_site_keeps_a_system_administrator(self):
        self.assertEqual(db_value("select count(*) from user_info where usergroup = 'S'"), "1")
        self.assertIn("最后一位", self.admin.change_user(uoj.ADMIN[0], "normaluser"))
        self.assertNotEqual(self.admin.change_user(uoj.ADMIN[0], "banneduser"), "")
        self.assertEqual(usergroup_of(uoj.ADMIN[0]), "S")
        # with a second one, either can be made a normal user again
        account("p3_second_admin")
        self.assertEqual(self.admin.change_user("p3_second_admin", "superuser"), "")
        self.assertEqual(usergroup_of("p3_second_admin"), "S")
        self.assertEqual(self.admin.change_user("p3_second_admin", "normaluser"), "")
        self.assertEqual(usergroup_of("p3_second_admin"), "U")


class ClosedContestTest(unittest.TestCase):
    """while a contest runs, the submissions to its problems are closed"""

    def source_is_shown(self, client, submission_id, marker):
        r = client.get("/submission/%d" % submission_id)
        self.assertEqual(r.status_code, 200)
        return marker in r.text

    def test_submissions_are_closed_while_the_contest_runs(self):
        admin = uoj.admin()
        alice, bob, carol = account("p3_alice"), account("p3_bob"), account("p3_carol")
        assistant = account("p3_contest_assistant")
        visitor = uoj.Client()

        problem_id = admin.create_problem(ab_problem_files())
        # carol solved the problem long before the contest
        earlier = carol.submit(problem_id, AB + "// p3-earlier-source\n")
        self.assertTrue(self.source_is_shown(bob, earlier, "p3-earlier-source"))

        contest_id = admin.new_contest("p3 closed contest")
        self.assertEqual(admin.contest_commands(contest_id, "problems", "+%d" % problem_id), "")
        self.assertEqual(admin.contest_commands(contest_id, "managers", "+p3_contest_assistant"), "")
        alice.register_for_contest(contest_id)
        bob.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        in_contest = alice.submit_in_contest(contest_id, problem_id, AB + "// p3-contest-source\n")
        self.assertEqual(db_value("select contest_id from submissions where id = %d" % in_contest), str(contest_id))

        # ---- what was submitted in the contest is shown to its owner and the staff only
        for client in (alice, admin, assistant):
            self.assertTrue(self.source_is_shown(client, in_contest, "p3-contest-source"), client.username)
        for client in (bob, carol, visitor):
            self.assertEqual(client.get("/submission/%d" % in_contest).status_code, 403, client.username)

        link = 'href="/submission/%d"' % in_contest
        for client in (alice, admin, assistant):
            self.assertIn(link, client.get("/submissions?problem_id=%d" % problem_id).text, client.username)
        for client in (bob, carol, visitor):
            listing = client.get("/submissions?problem_id=%d" % problem_id).text
            self.assertNotIn(link, listing, client.username)
            self.assertIn('href="/submission/%d"' % earlier, listing, client.username)
        # another participant who asks for all the submissions of the contest gets their own
        listing = bob.get("/contest/%d/submissions" % contest_id, cookies={"show_all_submissions": ""})
        self.assertEqual(listing.status_code, 200)
        self.assertNotIn(link, listing.text)

        # ---- what was submitted to the problem before is still listed, but its source is closed
        for client in (bob, alice, visitor):
            self.assertFalse(self.source_is_shown(client, earlier, "p3-earlier-source"), client.username)
        for client in (carol, admin):
            self.assertTrue(self.source_is_shown(client, earlier, "p3-earlier-source"), client.username)

        # ---- once the contest is over, everybody reads everything again
        uoj.move_contest(contest_id, -7200, 60)
        for client in (bob, carol, visitor):
            self.assertTrue(self.source_is_shown(client, in_contest, "p3-contest-source"), client.username)
            self.assertTrue(self.source_is_shown(client, earlier, "p3-earlier-source"), client.username)
            self.assertIn(link, client.get("/submissions?problem_id=%d" % problem_id).text, client.username)
        uoj.wait_idle()

    def test_settings_of_a_problem_never_hide_a_submission_from_its_staff(self):
        admin = uoj.admin()
        dave, erin = account("p3_dave"), account("p3_erin")
        problem_id = admin.create_problem(ab_problem_files(), extra_config={"view_content_type": "SELF"})
        submission_id = dave.submit(problem_id, AB + "// p3-private-source\n")
        self.assertTrue(self.source_is_shown(dave, submission_id, "p3-private-source"))
        self.assertTrue(self.source_is_shown(admin, submission_id, "p3-private-source"))
        self.assertFalse(self.source_is_shown(erin, submission_id, "p3-private-source"))
        self.assertFalse(self.source_is_shown(uoj.Client(), submission_id, "p3-private-source"))
        uoj.wait_idle()


if __name__ == "__main__":
    unittest.main()
