"""Phase 16: what a review of the site found, mended. Each class is one of the defects, told by
what it did wrong and kept from coming back."""

import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account
from uoj import db, db_value


def members(client, slug, form, **fields):
    """the form of the members of a domain: '' or why it was refused"""
    return client.form("/d/%s/members" % slug, form, **fields)


def managers(problem_id):
    return [row[0] for row in db("select username from problems_permissions where problem_id = %d order by username" % problem_id)]


def title_of(problem_id):
    return db_value("select title from problems where id = %d" % problem_id)


def try_login(username, password):
    """what the site answers a login with this password"""
    client = uoj.Client()
    r = client.http.post(uoj.BASE_URL + "/login", data={
        "_token": client.token, "login": "", "username": username, "password": client.password_hash(password),
    })  # fmt: skip
    return r.text


class DomainProblemManagerTest(unittest.TestCase):
    """A problem of a domain could be given to anybody to manage, and stayed theirs when they
    left the domain or were taken out of it: from its address on the site they changed it still."""

    def test_who_is_taken_out_of_a_domain_manages_its_problems_no_more(self):
        admin = uoj.admin()
        teacher = account("p16_mgr_teacher")
        self.assertEqual(admin.change_user("p16_mgr_teacher", "grant:teacher"), "")
        teacher.new_domain("p16-managers")
        helper = account("p16_mgr_helper")
        outsider = account("p16_mgr_outsider")
        self.assertEqual(members(teacher, "p16-managers", "add", username="p16_mgr_helper", role="member"), "")
        problem_id = teacher.new_problem("p16-managers")
        manage = "/problem/%d/manage" % problem_id

        # it is given to members of the domain only
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "+p16_mgr_outsider"})
        self.assertEqual(managers(problem_id), [])
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "+p16_mgr_helper"})
        self.assertEqual(managers(problem_id), ["p16_mgr_helper"])
        self.assertEqual(helper.get(manage + "/statement").status_code, 200)
        self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(problem_id), "")
        submission_id = teacher.submit(problem_id, AB, path="/d/p16-managers/problem/%d" % uoj.pid(problem_id))
        link = 'href="/submission/%d"' % submission_id
        self.assertIn(link, helper.get("/submissions?problem_id=%d" % problem_id).text)

        # taken out of the domain, the helper manages it no more, from no address
        self.assertEqual(members(teacher, "p16-managers", "remove", username="p16_mgr_helper"), "")
        self.assertEqual(managers(problem_id), [])
        for page in ("statement", "data", "managers"):
            self.assertIn(helper.get("%s/%s" % (manage, page)).status_code, (403, 404), page)
        before = title_of(problem_id)
        helper.post(manage + "/statement", {"problem_title": "p16 changed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""})
        self.assertEqual(title_of(problem_id), before)
        self.assertNotIn(link, helper.get("/submissions?problem_id=%d" % problem_id).text)

        # nor does anybody who was given it before this was so, and is not in the domain
        db("insert into problems_permissions (problem_id, username) values (%d, 'p16_mgr_outsider')" % problem_id)
        self.assertIn(outsider.get(manage + "/statement").status_code, (403, 404))
        outsider.post(manage + "/statement", {"problem_title": "p16 changed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""})
        self.assertEqual(title_of(problem_id), before)
        # and who manages it can still take that away
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "-p16_mgr_outsider"})
        self.assertEqual(managers(problem_id), [])


class RestrictedContestSubmissionTest(unittest.TestCase):
    """A contest with a password, or for the people on a list, stays with the people who took part
    in it after it is over, and its page says so. What was submitted in it was on the lists of
    submissions of the site for everybody, with its code where the problem shows everybody the code."""

    def test_what_was_submitted_in_it_stays_with_who_took_part(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files(), extra_config={"view_content_type": "ALL", "view_all_details_type": "ALL"})
        contest_id = admin.new_contest("p16 locked", rule="ICPC", problems=str(problem_id), join_mode="password", join_password="p16 sesame")
        pupil = account("p16_locked_pupil")
        mate = account("p16_locked_mate")
        stranger = account("p16_locked_stranger")
        for client in (pupil, mate):
            self.assertEqual(client.submit_form("/contest/%d/register" % contest_id, "register", {"join_password": "p16 sesame"}), "")
        uoj.move_contest(contest_id, -60, 60)
        inside = pupil.submit_in_contest(contest_id, problem_id, AB + "// p16-inside\n")
        uoj.move_contest(contest_id, -7200, 60)
        outside = stranger.submit(problem_id, AB + "// p16-outside\n")

        # what was submitted inside: for who took part, the staff, and nobody else, on no list
        for client in (pupil, mate, admin):
            self.assertIn("p16-inside", client.get("/submission/%d" % inside).text)
            self.assertIn('href="/submission/%d"' % inside, client.get("/submissions?problem_id=%d" % problem_id).text)
        for client in (stranger, uoj.Client()):
            self.assertEqual(client.get("/submission/%d" % inside).status_code, 403)
            for page in ("/submissions?problem_id=%d" % problem_id, "/submissions", "/problem/%d/statistics" % problem_id):
                self.assertNotIn('href="/submission/%d"' % inside, client.get(page).text, page)
        # what was submitted to the problem outside of the contest is everybody's to read
        for client in (pupil, stranger, uoj.Client()):
            self.assertIn("p16-outside", client.get("/submission/%d" % outside).text)


class ArchivedDomainTest(unittest.TestCase):
    """An archived domain is read only until it is brought back. The teacher who made a contest
    there still changed it, as the owner of the contest, and the administrators of the site
    changed its problems and its contests."""

    def test_nothing_in_an_archived_domain_is_changed(self):
        admin = uoj.admin()
        teacher = account("p16_arch_teacher")
        self.assertEqual(admin.change_user("p16_arch_teacher", "grant:teacher"), "")
        teacher.new_domain("p16-archived")
        problem_id = teacher.new_problem("p16-archived")
        contest_id = teacher.new_contest("p16 archived contest", domain="p16-archived")
        contest_name = lambda: db_value("select name from contests where id = %d" % contest_id)
        statement = {"problem_title": "p16 renamed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""}
        before = title_of(problem_id)
        self.assertEqual(teacher.form("/d/p16-archived/settings", "archive"), "")

        for client in (teacher, admin):
            self.assertNotEqual(client.contest_settings(contest_id, name="p16 renamed"), "")
            self.assertEqual(contest_name(), "p16 archived contest")
            self.assertIn(client.get("/contest/%d/manage" % contest_id).status_code, (403, 404))
            client.post("/problem/%d/manage/statement" % problem_id, statement)
            self.assertEqual(title_of(problem_id), before)
        # it is read as before
        for client in (teacher, admin):
            self.assertEqual(client.get("/d/p16-archived/problem/%d" % uoj.pid(problem_id)).status_code, 200)
            self.assertIn("p16 archived contest", client.get("/d/p16-archived/contests").text)

        # brought back, it is changed again
        self.assertEqual(teacher.form("/d/p16-archived/settings", "archive"), "")
        self.assertEqual(teacher.contest_settings(contest_id, name="p16 renamed"), "")
        self.assertEqual(contest_name(), "p16 renamed")


class LoginNameLettersTest(unittest.TestCase):
    """The wrong passwords of a name were counted by the name as it was typed, while the user is
    found by the name in any letters: past the limit, the same name in capitals tried on."""

    def test_a_name_written_otherwise_is_the_same_name(self):
        uoj.Client().register("p16_letters", "p16-letters-password")
        for name in ("p16_letters", "P16_letters", "p16_LETTERS", "P16_LETTERS", "p16_Letters"):
            self.assertEqual(try_login(name, "wrong"), "failed")
        # the sixth is not looked at, however the name is written
        for name in ("p16_letters", "P16_LETTERS", "p16_lEtTeRs"):
            self.assertRegex(try_login(name, "p16-letters-password"), r"^throttled \d+$")
        db("delete from auth_failures where scope like 'login:%p16_letters%'")
        self.assertEqual(try_login("P16_LETTERS", "p16-letters-password"), "ok")


if __name__ == "__main__":
    unittest.main()
