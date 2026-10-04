"""End-to-end tests for phase 3: roles, who may see which submission, accounts and single
sign-on.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest
from urllib.parse import parse_qs, urlencode, urlparse

import requests

import mock_idp
import uoj
from fixtures import *
from uoj import db, db_value, docker_exec

IDP = mock_idp.MockIdP()


def setUpModule():
    uoj.admin()
    IDP.start()


def tearDownModule():
    IDP.stop()


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


class IdentityTest(unittest.TestCase):
    """a user is a number, a username and a nickname"""

    def test_every_user_has_a_number(self):
        ids = [int(row[0]) for row in db("select id from user_info order by register_time, id")]
        self.assertEqual(ids, sorted(set(ids)))
        self.assertEqual(db_value("select id from user_info where username = '%s'" % uoj.ADMIN[0]), "1")
        newcomer = account("p3_numbered")
        self.assertEqual(int(db_value("select id from user_info where username = 'p3_numbered'")), max(ids) + 1)
        self.assertIn('id="user-id">%d<' % (max(ids) + 1), newcomer.get("/user/profile/p3_numbered").text)

    def test_nickname_is_shown_with_the_username(self):
        user = account("p3_nick")
        self.assertEqual(user.update_profile(nickname="小明"), "ok")
        self.assertEqual(db_value("select nickname from user_info where username = 'p3_nick'"), "小明")
        self.assertIn("小明（<span", user.get("/user/profile/p3_nick").text)
        # wherever the user is linked, the page carries the nickname next to the username
        contest_id = uoj.admin().new_contest("p3 contest of nicknames")
        user.register_for_contest(contest_id)
        registrants = uoj.Client().get("/contest/%d/registrants" % contest_id).text
        self.assertIn('data-alias="小明">p3_nick</span>', registrants)
        for refused in ("<b>x</b>", "x" * 21, "小明（root）", "@root", 'a"b'):
            self.assertTrue(user.update_profile(nickname=refused).startswith("失败"), refused)
        self.assertEqual(db_value("select nickname from user_info where username = 'p3_nick'"), "小明")
        self.assertEqual(user.update_profile(nickname=""), "ok")
        self.assertNotIn("data-alias", uoj.Client().get("/user/profile/p3_nick").text)

    def test_profile_is_not_changed_without_the_token_or_the_password(self):
        user = account("p3_careful")
        self.assertNotEqual(user.update_profile(token=False, nickname="x"), "ok")
        self.assertNotEqual(user.update_profile(old_password="0" * 32, nickname="x"), "ok")
        self.assertEqual(db_value("select nickname from user_info where username = 'p3_careful'"), "")

    def test_username_columns_are_all_known(self):
        """a table that is added later and names users has to be renamed with them"""
        known = json.loads(docker_exec(
            uoj.WEB,
            "php -r 'require \"/opt/uoj/web/app/libs/uoj-user-lib.php\"; echo json_encode(usernameColumns());'",
        ))  # fmt: skip
        known = {(table, column) for table, columns in known.items() for column in columns}
        # the user table itself, and the journals that keep the names as they were
        known |= {("user_info", "username"), ("user_renames", "old_username"), ("user_renames", "new_username")}
        known |= {("user_renames", "renamed_by")}
        names = "'username', 'submitter', 'poster', 'hacker', 'owner', 'sender', 'receiver', 'creator'"
        names += ", 'created_by', 'granted_by', 'renamed_by', 'old_username', 'new_username'"
        found = {
            (table, column)
            for table, column in db(
                "select table_name, column_name from information_schema.columns"
                " where table_schema = 'app_uoj233' and column_name in (%s)" % names
            )
        }
        self.assertEqual(found - known, set())
        self.assertEqual(known - found, set())

    def test_user_changes_their_username(self):
        admin = uoj.admin()
        user = account("p3_before")
        user_id = db_value("select id from user_info where username = 'p3_before'")
        # leave traces of the user all over the database
        problem_id = admin.create_problem(ab_problem_files())
        submission_id = user.submit(problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        self.assertEqual(admin.change_user("p3_before", "grant:teacher"), "")
        own_problem_id = user.new_problem()
        contest_id = user.new_contest("p3 contest of a user who changes their name")
        account("p3_bystander").register_for_contest(contest_id)
        db("insert into contests_registrants (username, user_rating, contest_id, has_participated)"
           " values ('p3_before', 1500, %d, 0)" % contest_id)  # fmt: skip
        db("insert into blogs (title, poster, post_time) values ('p3 blog', 'p3_before', now())")
        db("insert into blogs_comments (blog_id, poster, post_time) values (1, 'p3_before', now())")
        db("insert into user_msg (sender, receiver, message, send_time) values ('p3_before', 'p3_bystander', 'hi', now())")
        db("insert into user_msg (sender, receiver, message, send_time) values ('p3_bystander', 'p3_before', 'hi', now())")
        db("insert into user_system_msg (receiver, title, content, send_time) values ('p3_before', 't', 'c', now())")
        db("insert into click_zans (type, username, target_id, val) values ('P', 'p3_before', %d, 1)" % problem_id)
        db("insert into contests_asks (contest_id, username, question, post_time) values (%d, 'p3_before', 'q', now())" % contest_id)
        db("insert into pastes (`index`, creator, created_at, content) values ('p3pastep3pastep3past', 'p3_before', now(), 'x')")
        # a hack that was judged already, so that no judger takes it
        db("insert into hacks (problem_id, submission_id, hacker, owner, input, input_type, submit_time, judge_time, details, is_hidden, success)"
           " values (%d, %d, 'p3_before', 'p3_before', '', 'USE_FORMATTER', now(), now(), '', 1, 0)" % (problem_id, submission_id))  # fmt: skip
        before = uoj.columns_holding("p3_before")
        self.assertGreaterEqual(len(before), 17, before)

        # a name that is taken, or is no name at all, is refused
        for refused in ("p3_bystander", uoj.ADMIN[0], "no spaces", "x" * 21, "名字"):
            self.assertTrue(user.update_profile(username=refused).startswith("失败"), refused)
        self.assertEqual(db_value("select username from user_info where id = %s" % user_id), "p3_before")

        self.assertEqual(user.update_profile(username="p3_after"), "ok")
        self.assertEqual(db_value("select username from user_info where id = %s" % user_id), "p3_after")
        # nothing is left behind under the old name but the journal of the change
        self.assertEqual(
            uoj.columns_holding("p3_before"), ["user_renames.old_username", "user_renames.renamed_by"]
        )
        self.assertEqual(len(uoj.columns_holding("p3_after")), len(before) + 1)
        self.assertEqual(db_value("select submitter from submissions where id = %d" % submission_id), "p3_after")

        # the user is still logged in, keeps what they had, and logs in with the password they had
        self.assertEqual(user.get("/user/modify-profile").status_code, 200)
        self.assertEqual(user.get("/problem/%d/manage/data" % own_problem_id).status_code, 200)
        self.assertEqual(user.get("/contest/%d/manage" % contest_id).status_code, 200)
        self.assertEqual(roles_of("p3_after"), ["teacher"])
        again = uoj.Client()
        again.login("p3_after", "p3_before-password")
        with self.assertRaises(Exception):
            uoj.Client().login("p3_before", "p3_before-password")
        # and so they do after they change the password
        new_hash = again.password_hash("p3-new-password")
        self.assertEqual(again.update_profile(ptag="1", password=new_hash), "ok")
        uoj.Client().login("p3_after", "p3-new-password")
        user.password = "p3-new-password"

        # the name they gave up is kept for them
        with self.assertRaises(Exception):
            uoj.Client().register("p3_before", "x")
        self.assertTrue(account("p3_bystander").update_profile(username="p3_before").startswith("失败"))
        self.assertEqual(db_value("select count(*) from user_info where username = 'p3_before'"), "0")

        # they can not change it again right away, but a system administrator can
        self.assertIn("天后", user.update_profile(username="p3_before"))
        self.assertNotEqual(
            account("p3_bystander").submit_form(
                "/super-manage/users", "rename", {"rename_username": "p3_after", "rename_new_username": "p3_stolen"}
            ),
            "",
        )
        self.assertEqual(db_value("select username from user_info where id = %s" % user_id), "p3_after")
        self.assertEqual(
            admin.submit_form(
                "/super-manage/users", "rename", {"rename_username": "p3_after", "rename_new_username": "p3_before"}
            ),
            "",
        )
        self.assertEqual(db_value("select username from user_info where id = %s" % user_id), "p3_before")
        self.assertEqual(uoj.columns_holding("p3_after"), ["user_renames.new_username", "user_renames.old_username"])
        uoj.Client().login("p3_before", "p3-new-password")


class SessionTest(unittest.TestCase):
    """the session of a user is theirs alone"""

    def session_with(self, cookies):
        """another browser that presents these cookies"""
        other = uoj.Client.__new__(uoj.Client)
        other.http = requests.Session()
        other.http.cookies.update(cookies)
        return other

    def test_session_cookie_is_kept_from_scripts_and_other_sites(self):
        cookie = requests.get(uoj.BASE_URL + "/login").headers["Set-Cookie"].lower()
        self.assertIn("uojsessid=", cookie)
        self.assertIn("httponly", cookie)
        self.assertIn("samesite=lax", cookie)

    def test_session_moves_to_a_new_id_at_login(self):
        client = uoj.Client()
        before = client.http.cookies.get("UOJSESSID")
        client.register("p3_session", "p3_session-password")
        client.login("p3_session", "p3_session-password")
        after = client.http.cookies.get("UOJSESSID")
        self.assertEqual(who(client), "p3_session")
        self.assertNotEqual(before, after)
        # whoever knew the id from before the login is nobody
        self.assertIsNone(who(self.session_with({"UOJSESSID": before})))
        self.assertEqual(who(self.session_with({"UOJSESSID": after})), "p3_session")

    def test_session_id_chosen_by_somebody_else_is_not_adopted(self):
        # somebody plants an id in the browser of a user before the user logs in
        planted = "p3plantedsessionid0000000000"
        client = self.session_with({})
        r = client.http.get(uoj.BASE_URL + "/login", headers={"Cookie": "UOJSESSID=" + planted})
        client.token = re.search(r'_token : "([0-9a-zA-Z]+)"', r.text).group(1)
        client.salt = re.search(r"\.val\(\), \"([^\"]*)\"\)", r.text).group(1)
        self.assertNotIn(client.http.cookies.get("UOJSESSID"), (None, planted))
        account("p3_session_victim")
        client.login("p3_session_victim", "p3_session_victim-password")
        self.assertEqual(who(client), "p3_session_victim")
        self.assertIsNone(who(self.session_with({"UOJSESSID": planted})))

    def test_logout_ends_the_session_and_the_remembered_login(self):
        client = uoj.Client()
        client.register("p3_leaver", "p3_leaver-password")
        client.login("p3_leaver", "p3_leaver-password")
        cookies = client.http.cookies.get_dict()
        self.assertIn("uoj_remember_token", cookies)
        self.assertEqual(who(self.session_with(cookies)), "p3_leaver")
        logout(client)
        self.assertIsNone(who(client))
        # neither the id of the session nor the remembered login still work
        self.assertIsNone(who(self.session_with(cookies)))
        self.assertEqual(db_value("select remember_token from user_info where username = 'p3_leaver'"), "")
        # and no token at all is not a token that matches
        forged = dict(cookies, uoj_remember_token="")
        self.assertIsNone(who(self.session_with(forged)))

    def test_forwarded_host_of_a_stranger_is_not_believed(self):
        r = requests.get(uoj.BASE_URL + "/login", headers={"X-Forwarded-Host": "evil.example"})
        self.assertEqual(r.status_code, 200)
        self.assertNotIn("evil.example", r.text)
        self.assertIn(uoj.BASE_URL + "/", r.text)


class AuditLogTest(unittest.TestCase):
    """who changed what"""

    def log_of(self, resource_type, resource_id):
        return db(
            "select action, actor, actor_type, ifnull(before_json, ''), ifnull(after_json, '') from audit_logs"
            " where resource_type = '%s' and resource_id = '%s' order by id" % (resource_type, resource_id)
        )

    def test_changes_are_recorded_with_who_made_them(self):
        admin = uoj.admin()
        teacher, student = account("p3_audit_teacher"), account("p3_audit_student")
        self.assertEqual(admin.change_user("p3_audit_teacher", "grant:teacher"), "")
        (action, actor, actor_type, before, after), = self.log_of("user", "p3_audit_teacher")
        self.assertEqual((action, actor, actor_type), ("user.grant_role", uoj.ADMIN[0], "user"))
        self.assertEqual(json.loads(after), {"role": "teacher"})
        actor_id, ip = db("select actor_id, ip from audit_logs where resource_type = 'user' and resource_id = 'p3_audit_teacher'")[0]
        self.assertEqual(actor_id, db_value("select id from user_info where username = '%s'" % uoj.ADMIN[0]))
        self.assertNotEqual(ip, "")

        # a problem: created, its data uploaded and synced
        problem_id = teacher.create_problem(ab_problem_files())
        log = self.log_of("problem", problem_id)
        self.assertEqual([row[0] for row in log], ["problem.create", "problem.upload_data", "problem.sync_data"])
        self.assertEqual({row[1] for row in log}, {"p3_audit_teacher"})
        self.assertEqual(json.loads(log[2][4])["version"], 1)
        self.assertEqual(
            json.loads(log[2][4])["sha256"],
            db_value("select sha256 from problem_data_versions where problem_id = %d and version = 1" % problem_id),
        )

        # a submission that is judged again
        submission_id = student.submit(problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        self.assertEqual(teacher.submit_form("/submission/%d" % submission_id, "rejudge"), "")
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        (action, actor, actor_type, before, after), = self.log_of("submission", submission_id)
        self.assertEqual((action, actor, json.loads(before)), ("submission.rejudge", "p3_audit_teacher", {"score": "100"}))

        # a contest, from its creation to the export of its standings
        contest_id = teacher.new_contest("p3 audited contest")
        self.assertEqual(teacher.contest_commands(contest_id, "problems", "+%d" % problem_id), "")
        self.assertEqual(teacher.contest_commands(contest_id, "managers", "+p3_audit_student"), "")
        self.assertEqual(teacher.contest_commands(contest_id, "managers", "-p3_audit_student"), "")
        start = uoj.web_time(-7200)
        self.assertEqual(
            teacher.submit_form(
                "/contest/%d/manage" % contest_id, "time", {"name": "p3 audited contest", "start_time": start, "last_min": "90"}
            ),
            "",
        )
        self.assertEqual(teacher.submit_form("/contest/%d" % contest_id, "start_test"), "")
        self.assertEqual(teacher.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(admin.get("/contest/%d/export_standings" % contest_id).status_code, 200)
        log = self.log_of("contest", contest_id)
        self.assertEqual(
            [(row[0], row[1]) for row in log],
            [
                ("contest.create", "p3_audit_teacher"),
                ("contest.add_problem", "p3_audit_teacher"),
                ("contest.add_staff", "p3_audit_teacher"),
                ("contest.remove_staff", "p3_audit_teacher"),
                ("contest.edit", "p3_audit_teacher"),
                ("contest.start_final_test", "p3_audit_teacher"),
                ("contest.publish_results", "p3_audit_teacher"),
                ("contest.export_standings", uoj.ADMIN[0]),
            ],
        )
        edit = log[4]
        self.assertEqual(json.loads(edit[3])["last_min"], 60)
        self.assertEqual((json.loads(edit[4])["last_min"], json.loads(edit[4])["start_time"]), (90, start))
        self.assertEqual(json.loads(log[2][4]), {"username": "p3_audit_student", "role": "assistant"})
        uoj.wait_idle()

    def test_log_is_read_by_system_administrators_only(self):
        account("p3_audit_reader")
        self.assertEqual(uoj.admin().change_user("p3_audit_reader", "grant:oj_admin"), "")
        page = uoj.admin().get("/super-manage/audit?resource_type=user&resource_id=p3_audit_reader")
        self.assertEqual(page.status_code, 200)
        self.assertIn("user.grant_role", page.text)
        self.assertEqual(account("p3_audit_reader").get("/super-manage/audit").status_code, 404)
        self.assertEqual(account("p3_audit_bystander").get("/super-manage/audit").status_code, 403)


# ---------------------------------------------------------------------- single sign-on


def sso_start(client, provider):
    """start a login the way a browser does, return where UOJ sends the browser and with what"""
    r = client.get("/login/sso/" + provider)
    assert r.status_code == 302, "HTTP %d: %s" % (r.status_code, uoj.text_of(r.text)[:300])
    url = urlparse(r.headers["Location"])
    return url, {key: values[0] for key, values in parse_qs(url.query).items()}


def local_path(url):
    assert url.startswith(uoj.BASE_URL + "/"), url
    return url[len(uoj.BASE_URL) :]


def cas_login(client, user, **attributes):
    """log in at the school as a user, and carry the ticket back to UOJ"""
    url, query = sso_start(client, "cas")
    assert url.path == "/cas/login", url
    ticket = IDP.cas_ticket(query["service"], user, **attributes)
    return client.get(local_path(query["service"]) + "&ticket=" + ticket)


def oauth_login(client, provider, profile):
    url, query = sso_start(client, provider)
    assert url.path == "/oauth/authorize", url
    code = IDP.oauth_code(query, profile)
    return client.get(local_path(query["redirect_uri"]) + "?" + urlencode({"code": code, "state": query["state"]}))


def who(client):
    """the user a client is logged in as, None for a visitor"""
    found = re.search(r'data-link="0"[^>]*>([^<]*)</span>', client.get("/").text)
    return found.group(1) if found else None


def logout(client):
    client.get("/logout?_token=" + client.token)


def identity_of(provider, external_id):
    rows = db(
        "select username, student_id, real_name, email from external_identities"
        " where provider = '%s' and external_id = '%s'" % (provider, external_id)
    )
    return rows[0] if rows else None


class SingleSignOnTest(unittest.TestCase):
    """logging in through the school, played by mock_idp.py"""

    @classmethod
    def setUpClass(cls):
        if "/login/sso/cas" not in uoj.Client().get("/login").text:
            raise unittest.SkipTest("the single sign-on is not configured, see configure.py")

    def assert_refused(self, response, message):
        self.assertEqual(response.status_code, 200, response.headers.get("Location"))
        self.assertIn(message, uoj.text_of(response.text))

    def test_first_login_through_cas_creates_a_user_named_by_the_student_number(self):
        users = int(db_value("select count(*) from user_info"))
        client = uoj.Client()
        r = cas_login(client, "zhangsan", employeeNumber="20240001", cn="张三", mail="zhangsan@example.edu.cn")
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/"))
        self.assertEqual(who(client), "20240001")

        # the user is named by the student number and has a number of their own
        self.assertEqual(int(db_value("select count(*) from user_info")), users + 1)
        user_id, usergroup, email, password = db(
            "select id, usergroup, email, password from user_info where username = '20240001'"
        )[0]
        self.assertEqual((usergroup, email), ("U", "zhangsan@example.edu.cn"))
        self.assertNotIn(user_id, ("20240001", "0"))
        # what the school calls them is kept apart from both
        self.assertEqual(
            identity_of("cas", "zhangsan"), ["20240001", "20240001", "张三", "zhangsan@example.edu.cn"]
        )
        # there is no password to log in with, and none to guess
        self.assertTrue(password.startswith("!") and len(password) == 32, password)
        for guess in ("", "20240001", password):
            with self.assertRaises(Exception):
                uoj.Client().login("20240001", guess)

        # the next login finds the same user, and learns what changed at the school
        again = uoj.Client()
        r = cas_login(again, "zhangsan", employeeNumber="20240001", cn="张三丰", mail="zhangsan@example.edu.cn")
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/"))
        self.assertEqual(who(again), "20240001")
        self.assertEqual(int(db_value("select count(*) from user_info")), users + 1)
        self.assertEqual(identity_of("cas", "zhangsan")[2], "张三丰")
        self.assertEqual(db_value("select count(*) from external_identities where username = '20240001'"), "1")

    def test_user_of_the_school_keeps_the_username_and_chooses_a_nickname(self):
        client = uoj.Client()
        cas_login(client, "lisi", employeeNumber="20240002", cn="李四")
        client.username, client.password = "20240002", ""
        page = client.get("/user/modify-profile").text
        self.assertRegex(page, r'id="input-username"[^>]*disabled')
        self.assertNotIn('id="input-old_password"', page)

        self.assertIn("不能修改", client.update_profile(username="lisi_the_great"))
        client.username = "20240002"
        self.assertEqual(db_value("select count(*) from user_info where username = 'lisi_the_great'"), "0")
        # the nickname is theirs to choose, and it is shown with the student number
        self.assertNotEqual(client.update_profile(token=False, nickname="小李"), "ok")
        self.assertEqual(client.update_profile(nickname="小李"), "ok")
        self.assertIn('data-alias="小李">20240002</span>', client.get("/").text)
        self.assertIn("小李（<span", client.get("/user/profile/20240002").text)

        # a system administrator can still correct the student number
        self.assertEqual(
            uoj.admin().submit_form(
                "/super-manage/users", "rename", {"rename_username": "20240002", "rename_new_username": "20240092"}
            ),
            "",
        )
        self.assertEqual(identity_of("cas", "lisi")[0], "20240092")
        relogin = uoj.Client()
        cas_login(relogin, "lisi", employeeNumber="20240002", cn="李四")
        self.assertEqual(who(relogin), "20240092")

    def test_real_name_and_student_number_are_shown_to_staff_only(self):
        student = uoj.Client()
        cas_login(student, "wangwu", employeeNumber="20240003", cn="王五")
        teacher = account("p3_sso_teacher")
        self.assertEqual(uoj.admin().change_user("p3_sso_teacher", "grant:teacher"), "")
        for client in (student, uoj.admin(), teacher):
            page = client.get("/user/profile/20240003").text
            self.assertIn('class="user-real-name">王五<', page)
            self.assertIn('class="user-student-id">20240003<', page)
        for client in (account("p3_sso_classmate"), uoj.Client()):
            page = client.get("/user/profile/20240003").text
            self.assertNotIn("王五", page)
            self.assertNotIn("user-student-id", page)

    def test_login_through_oauth(self):
        client = uoj.Client()
        url, query = sso_start(client, "oauth")
        # the browser is given a state and a PKCE challenge, and never the secret of the client
        self.assertEqual(query["redirect_uri"], uoj.BASE_URL + "/login/sso/oauth/callback")
        self.assertEqual(query["code_challenge_method"], "S256")
        self.assertNotIn(mock_idp.CLIENT_SECRET, url.geturl())
        profile = {"code": 0, "data": {"uid": 880004, "number": "20240004", "name": "赵六", "email": "zhaoliu@example.edu.cn"}}
        code = IDP.oauth_code(query, profile)
        r = client.get(local_path(query["redirect_uri"]) + "?" + urlencode({"code": code, "state": query["state"]}))
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/"))
        self.assertEqual(who(client), "20240004")
        self.assertEqual(identity_of("oauth", "880004"), ["20240004", "20240004", "赵六", "zhaoliu@example.edu.cn"])
        # UOJ exchanged the code itself, with the secret and the PKCE verifier
        exchange = [params for method, path, params in IDP.requests if path == "/oauth/token" and params.get("code") == code]
        self.assertEqual(len(exchange), 1)
        self.assertEqual(exchange[0]["client_secret"], mock_idp.CLIENT_SECRET)
        self.assertIn("code_verifier", exchange[0])

    def test_login_through_openid_connect_discovery(self):
        client = uoj.Client()
        profile = {"sub": "oidc-880005", "preferred_username": "20240005", "name": "孙七", "email": "sunqi@example.edu.cn"}
        r = oauth_login(client, "oidc", profile)
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/"))
        self.assertEqual(who(client), "20240005")
        self.assertEqual(identity_of("oidc", "oidc-880005"), ["20240005", "20240005", "孙七", "sunqi@example.edu.cn"])

    def test_ticket_is_only_good_for_the_browser_that_asked_for_it(self):
        # a ticket the school never issued
        client = uoj.Client()
        url, query = sso_start(client, "cas")
        self.assert_refused(client.get(local_path(query["service"]) + "&ticket=ST-forged"), "INVALID_TICKET")
        self.assertIsNone(who(client))

        # a ticket of somebody else, carried into another browser
        attacker, victim = uoj.Client(), uoj.Client()
        url, query = sso_start(attacker, "cas")
        ticket = IDP.cas_ticket(query["service"], "attacker", employeeNumber="20240006")
        callback = local_path(query["service"]) + "&ticket=" + ticket
        self.assert_refused(victim.get(callback), "过期")
        sso_start(victim, "cas")
        self.assert_refused(victim.get(callback), "不符")
        self.assertIsNone(who(victim))
        self.assertEqual(db_value("select count(*) from user_info where username = '20240006'"), "0")
        # the school was not even asked
        self.assertNotIn(ticket, [params.get("ticket") for method, path, params in IDP.requests])

        # in the browser that asked it works, once
        r = attacker.get(callback)
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/"))
        self.assertEqual(who(attacker), "20240006")
        logout(attacker)
        self.assertIsNone(who(attacker))
        self.assert_refused(attacker.get(callback), "过期")
        self.assertIsNone(who(attacker))

    def test_code_is_only_good_for_the_browser_that_asked_for_it(self):
        profile = {"data": {"uid": 880007, "number": "20240007", "name": "周八"}}
        client, victim = uoj.Client(), uoj.Client()
        url, query = sso_start(client, "oauth")
        code = IDP.oauth_code(query, profile)
        callback = local_path(query["redirect_uri"])

        # without the state of the browser, the code is not even exchanged
        self.assert_refused(victim.get(callback + "?" + urlencode({"code": code, "state": query["state"]})), "过期")
        sso_start(victim, "oauth")
        self.assert_refused(victim.get(callback + "?" + urlencode({"code": code, "state": query["state"]})), "不符")
        self.assertEqual([p for m, path, p in IDP.requests if p.get("code") == code], [])

        # a wrong state uses up the login that was started
        self.assert_refused(client.get(callback + "?" + urlencode({"code": code, "state": "x" * 32})), "不符")
        self.assert_refused(client.get(callback + "?" + urlencode({"code": code, "state": query["state"]})), "过期")
        self.assertIsNone(who(client))
        self.assertEqual(db_value("select count(*) from user_info where username = '20240007'"), "0")

        # a login the school refused
        url, query = sso_start(client, "oauth")
        self.assert_refused(
            client.get(callback + "?" + urlencode({"error": "access_denied", "state": query["state"]})), "access_denied"
        )

        # a code is exchanged once
        r = oauth_login(client, "oauth", profile)
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/"))
        used = [p["code"] for m, path, p in IDP.requests if path == "/oauth/token"][-1]
        logout(client)
        url, query = sso_start(client, "oauth")
        self.assert_refused(client.get(callback + "?" + urlencode({"code": used, "state": query["state"]})), "invalid_grant")
        self.assertIsNone(who(client))

    def test_student_number_can_not_be_registered_or_taken(self):
        with self.assertRaises(Exception):
            uoj.Client().register("20249999", "x")
        self.assertEqual(db_value("select count(*) from user_info where username = '20249999'"), "0")
        self.assertIn("统一身份认证", account("p3_sso_squatter").update_profile(username="20249998"))
        self.assertEqual(db_value("select count(*) from user_info where username = '20249998'"), "0")

    def test_existing_user_with_the_student_number_is_bound_by_their_password(self):
        # somebody registered with their student number before the single sign-on existed
        account("p3_sso_legacy")
        user_id = db_value("select id from user_info where username = 'p3_sso_legacy'")
        self.assertEqual(
            uoj.admin().submit_form(
                "/super-manage/users", "rename", {"rename_username": "p3_sso_legacy", "rename_new_username": "20240008"}
            ),
            "",
        )

        client = uoj.Client()
        r = cas_login(client, "wujiu", employeeNumber="20240008", cn="吴九")
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/login/sso/cas/bind"))
        self.assertIsNone(who(client))
        self.assertIsNone(identity_of("cas", "wujiu"))
        self.assertIn("20240008", uoj.text_of(client.get("/login/sso/cas/bind").text))

        # only the password of that user proves that it is the same person
        bind = lambda password, token=True: client.post(
            "/login/sso/cas/bind", {"bind": "", "password": client.password_hash(password)}, token=token
        ).text
        self.assertEqual(bind("not the password"), "failed")
        self.assertEqual(bind("p3_sso_legacy-password", token=False), "expired")
        self.assertIsNone(who(client))
        self.assertEqual(bind("p3_sso_legacy-password"), "ok")
        self.assertEqual(who(client), "20240008")
        self.assertEqual(identity_of("cas", "wujiu"), ["20240008", "20240008", "吴九", ""])
        self.assertEqual(db_value("select id from user_info where username = '20240008'"), user_id)

        # from now on the school is enough, and the username stays
        direct = uoj.Client()
        r = cas_login(direct, "wujiu", employeeNumber="20240008", cn="吴九")
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/"))
        self.assertEqual(who(direct), "20240008")
        direct.username, direct.password = "20240008", "p3_sso_legacy-password"
        self.assertIn("不能修改", direct.update_profile(username="p3_sso_again"))
        self.assertEqual(db_value("select username from user_info where id = %s" % user_id), "20240008")

        # nobody else at the school gets the user, whatever student number they come with
        imposter = uoj.Client()
        self.assert_refused(cas_login(imposter, "imposter", employeeNumber="20240008", cn="吴九"), "已绑定")
        self.assertIsNone(who(imposter))
        self.assertIsNone(identity_of("cas", "imposter"))

    def test_binding_gives_up_after_a_few_wrong_passwords(self):
        account("p3_sso_stubborn")
        self.assertEqual(
            uoj.admin().submit_form(
                "/super-manage/users", "rename", {"rename_username": "p3_sso_stubborn", "rename_new_username": "20240009"}
            ),
            "",
        )
        client = uoj.Client()
        cas_login(client, "zhengshi", employeeNumber="20240009")
        answers = [
            client.post("/login/sso/cas/bind", {"bind": "", "password": client.password_hash("guess %d" % n)}).text
            for n in range(6)
        ]
        self.assertEqual(answers, ["failed"] * 5 + ["too many"])
        # the right password comes too late, the login at the school has to be done again
        r = client.post("/login/sso/cas/bind", {"bind": "", "password": client.password_hash("p3_sso_stubborn-password")})
        self.assertEqual(r.status_code, 302)
        self.assertIsNone(who(client))
        self.assertIsNone(identity_of("cas", "zhengshi"))

    def test_banned_user_does_not_get_in_through_the_school(self):
        client = uoj.Client()
        cas_login(client, "banned_student", employeeNumber="20240010")
        self.assertEqual(who(client), "20240010")
        self.assertEqual(uoj.admin().change_user("20240010", "banneduser"), "")
        self.assertIsNone(who(client))
        again = uoj.Client()
        self.assert_refused(cas_login(again, "banned_student", employeeNumber="20240010"), "封停")
        self.assertIsNone(who(again))
        self.assertEqual(uoj.admin().change_user("20240010", "normaluser"), "")

    def test_student_number_that_can_not_be_a_username_is_refused(self):
        client = uoj.Client()
        self.assert_refused(cas_login(client, "odd_student", employeeNumber="2024-0011"), "不能作为用户名")
        self.assertIsNone(who(client))
        self.assertIsNone(identity_of("cas", "odd_student"))
        self.assertEqual(db_value("select count(*) from user_info where username like '2024-%'"), "0")

    def test_unknown_provider_is_not_found(self):
        self.assertEqual(uoj.Client().get("/login/sso/nowhere").status_code, 404)
        self.assertEqual(uoj.Client().get("/login/sso/nowhere/callback?ticket=ST-1").status_code, 404)


if __name__ == "__main__":
    unittest.main()
