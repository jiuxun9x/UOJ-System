"""End-to-end tests for phase 4: domains, the spaces of classes, courses and teams.

See test_phase1.py for how to start the containers.
"""

import csv
import io
import json
import re
import threading
import unittest

import test_phase3 as p3
import uoj
from fixtures import *
from uoj import db, db_value

account = p3.account


def setUpModule():
    uoj.admin()
    # the school of the tests of the single sign-on, for the students of the rosters
    p3.IDP.start()


def tearDownModule():
    p3.IDP.stop()


def domain_id(slug):
    return int(db_value("select id from domains where slug = '%s'" % slug))


def role_in(slug, username):
    """what a user is in a domain: 'owner', a role of a member, or None"""
    if db_value("select owner_username from domains where slug = '%s'" % slug) == username:
        return "owner"
    return db_value(
        "select role from domain_members where domain_id = %d and username = '%s'" % (domain_id(slug), username)
    )


def member_form(client, slug, form, **fields):
    return client.form("/d/%s/members" % slug, form, **fields)


def new_invite(client, slug, **fields):
    """create an invitation, return the token the page shows once"""
    fields.setdefault("hours", "168")
    assert member_form(client, slug, "invite", **fields) == ""
    page = client.get("/d/%s/members" % slug).text
    return re.search(r'id="new-invite-token">([A-Za-z0-9_-]+)<', page).group(1)


def redeem(client, token):
    return client.form("/domains/join", "redeem", token=token)


def refusal(client, path, form, **fields):
    """the words a page refuses a form with"""
    fields["form"] = form
    page = client.post(path, fields)
    assert page.status_code == 200, page.status_code
    return re.search(r'class="alert alert-danger"[^>]*>([^<]*)<', page.text).group(1)


class DomainTest(unittest.TestCase):
    """domains, who is in them, and who may do what there"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = account("p4_teacher")
        cls.student = account("p4_student")
        assert cls.admin.change_user("p4_teacher", "grant:teacher") == ""

    def test_who_may_create_a_domain(self):
        self.assertEqual(self.student.get("/domain/new").status_code, 403)
        self.student.form("/domain/new", "create", name="x", slug="p4-not-allowed", description="", type="course")
        self.assertEqual(db_value("select count(*) from domains where slug = 'p4-not-allowed'"), "0")

        # a teacher may, and so may whoever was given just that
        self.teacher.new_domain("p4-of-a-teacher")
        captain = account("p4_captain")
        self.assertEqual(self.admin.change_user("p4_captain", "grant:domain_creator"), "")
        captain.new_domain("p4-of-a-captain", type="team")
        self.assertEqual(captain.get("/problems").status_code, 200)
        problems = db_value("select count(*) from problems")
        captain.submit_form("/problems", "new_problem")
        self.assertEqual(db_value("select count(*) from problems"), problems)

        # the user who creates a domain owns it, and is not listed among its members
        self.assertEqual(db("select owner_username, created_by from domains where slug = 'p4-of-a-teacher'"),
                         [["p4_teacher", "p4_teacher"]])  # fmt: skip
        self.assertEqual(db_value("select count(*) from domain_members where domain_id = %d" % domain_id("p4-of-a-teacher")), "0")
        self.assertEqual(role_in("p4-of-a-teacher", "p4_teacher"), "owner")
        page = self.teacher.get("/d/p4-of-a-teacher").text
        self.assertIn("域 p4-of-a-teacher", page)
        self.assertIn("所有者", page)

    def test_system_administrator_lets_everybody_create_domains(self):
        page, switch = "/super-manage/settings", {"setting[domain.open_creation]": "on"}
        opener, oj_admin = account("p4_open_student"), account("p4_open_ojadmin")
        self.assertEqual(self.admin.change_user("p4_open_ojadmin", "grant:oj_admin"), "")
        self.teacher.new_domain("p4-open-other")

        # the switch is of the system administrators alone
        self.assertEqual(self.admin.get(page).status_code, 200)
        is_on = lambda: re.search(r'id="input-setting-domain-open_creation"[^>]*checked', self.admin.get(page).text) is not None
        self.assertFalse(is_on())
        for client in (oj_admin, self.teacher, opener):
            self.assertIn(client.get(page).status_code, (403, 404))
            self.assertNotEqual(client.form(page, "site_settings", **switch), "")
        self.assertEqual(db_value("select count(*) from site_settings"), "0")
        self.assertEqual(opener.get("/domain/new").status_code, 403)
        self.assertNotIn('id="button-new-domain"', opener.get("/domains").text)

        try:
            self.assertEqual(self.admin.form(page, "site_settings", **switch), "")
            self.assertEqual(db("select value, updated_by from site_settings where name = 'domain.open_creation'"),
                             [["1", uoj.ADMIN[0]]])  # fmt: skip
            self.assertTrue(is_on())
            # now whoever is logged in creates a domain, and owns it
            self.assertIn('id="button-new-domain"', opener.get("/domains").text)
            self.assertEqual(opener.get("/domain/new").status_code, 200)
            opener.new_domain("p4-of-a-student")
            self.assertEqual(role_in("p4-of-a-student", "p4_open_student"), "owner")
            self.assertEqual(uoj.Client().get("/domain/new").status_code, 302)
            # the switch opens nothing else: not the problems of the site, not the domains of others
            problems = db_value("select count(*) from problems where owner_domain_id is null")
            opener.submit_form("/problems", "new_problem")
            self.assertEqual(db_value("select count(*) from problems where owner_domain_id is null"), problems)
            self.assertEqual(opener.get("/d/p4-open-other").status_code, 404)
            self.assertNotIn('id="table-all-domains"', opener.get("/domains").text)
        finally:
            # a box that is not ticked switches it off
            self.assertEqual(self.admin.form(page, "site_settings", **{"present[domain.open_creation]": "1"}), "")
        self.assertEqual(db_value("select value from site_settings where name = 'domain.open_creation'"), "0")
        self.assertEqual(opener.get("/domain/new").status_code, 403)
        self.assertNotEqual(opener.form("/domain/new", "create", name="x", slug="p4-too-late", description="", type="course"), "")
        self.assertEqual(db_value("select count(*) from domains where slug = 'p4-too-late'"), "0")
        # what was created stays with whoever created it, and the roles work as before
        self.assertEqual(opener.get("/d/p4-of-a-student/settings").status_code, 200)
        self.assertEqual(self.teacher.get("/domain/new").status_code, 200)
        log = db("select action, actor, after_json from audit_logs where resource_type = 'site_setting' and resource_id = 'domain.open_creation' order by id")
        self.assertEqual([row[:2] for row in log], [["site.edit_setting", uoj.ADMIN[0]]] * 2)
        self.assertEqual([json.loads(row[2])["on"] for row in log], [True, False])

    def test_settings_are_checked(self):
        self.teacher.new_domain("p4-settings")
        base = dict(name="x", description="", type="course")
        for wrong in (dict(slug="P4-UPPER"), dict(slug="p4_under"), dict(slug="p4-"), dict(slug="p4-settings"),
                      dict(slug="p4-ok", name=""), dict(slug="p4-ok", type="x")):  # fmt: skip
            self.assertNotEqual(self.teacher.form("/domain/new", "create", **dict(base, **wrong)), "", wrong)
        self.assertEqual(db_value("select count(*) from domains where slug in ('p4-ok', 'p4-', 'p4_under')"), "0")

        settings = "/d/p4-settings/settings"
        self.assertNotEqual(self.teacher.form(settings, "settings", **dict(base, type="guild")), "")
        self.assertEqual(self.teacher.form(settings, "settings", **dict(base, name="新名字", type="team", slug="p4-renamed")), "")
        self.assertEqual(db("select name, type, slug from domains where id = %d" % domain_id("p4-settings")),
                         [["新名字", "team", "p4-settings"]])  # fmt: skip
        # there is no setting that shows a domain to the people outside
        self.assertEqual(db("show columns from domains where Field in ('visibility', 'join_method')"), [])

    def test_domain_is_seen_by_the_people_in_it_and_the_administrators_of_the_site(self):
        self.teacher.new_domain("p4-seen", name="只有里面的人看得到")
        co_admin, pupil = account("p4_seen_admin"), account("p4_seen_pupil")
        other_teacher, oj_admin, visitor = account("p4_seen_teacher"), account("p4_seen_ojadmin"), uoj.Client()
        self.assertEqual(self.admin.change_user("p4_seen_teacher", "grant:teacher"), "")
        self.assertEqual(self.admin.change_user("p4_seen_ojadmin", "grant:oj_admin"), "")
        self.assertEqual(member_form(self.teacher, "p4-seen", "add", username="p4_seen_admin", role="admin"), "")
        self.assertEqual(member_form(self.teacher, "p4-seen", "add", username="p4_seen_pupil", role="member"), "")
        pages = ("", "/members", "/settings", "/problems", "/contests", "/homeworks", "/announcements", "/homework/new")

        # Whoever is not in it learns nothing, whatever they are elsewhere: the answers are
        # the ones of a domain that does not exist.
        for path in pages:
            for stranger in (self.student, other_teacher):
                self.assertEqual(stranger.get("/d/p4-seen" + path).status_code, 404, path)
                self.assertEqual(stranger.get("/d/p4-no-such-domain" + path).status_code, 404, path)
            self.assertEqual(visitor.get("/d/p4-seen" + path).status_code, 302, path)
            self.assertEqual(visitor.get("/d/p4-no-such-domain" + path).status_code, 302, path)
        for stranger in (self.student, other_teacher, visitor):
            listed = stranger.get("/domains").text
            self.assertNotIn("p4-seen", listed)
            self.assertNotIn("只有里面的人看得到", listed)
            self.assertNotIn('id="table-all-domains"', listed)
            self.assertNotIn("只有里面的人看得到", stranger.get("/domains?q=p4-seen").text)
        # and there is no door to knock on
        self.assertIn("404", self.student.form("/d/p4-seen", "join"))
        self.assertIsNone(role_in("p4-seen", "p4_student"))

        # the owner and the administrator the owner appointed see it and manage it
        for manager in (self.teacher, co_admin):
            self.assertIn("只有里面的人看得到", manager.get("/domains").text)
            for path in ("", "/members", "/settings"):
                self.assertEqual(manager.get("/d/p4-seen" + path).status_code, 200, path)
        self.assertEqual(member_form(co_admin, "p4-seen", "add", username="p4_student", role="ta"), "")
        self.assertEqual(member_form(co_admin, "p4-seen", "remove", username="p4_student"), "")
        # a member sees it, and manages nothing
        self.assertIn("只有里面的人看得到", pupil.get("/domains").text)
        self.assertEqual(pupil.get("/d/p4-seen").status_code, 200)
        self.assertEqual(pupil.get("/d/p4-seen/settings").status_code, 403)
        self.assertNotEqual(member_form(pupil, "p4-seen", "add", username="p4_student", role="member"), "")
        self.assertIsNone(role_in("p4-seen", "p4_student"))

        # the administrators of the site see every domain and manage it, without being in it
        for overseer in (self.admin, oj_admin):
            listed = overseer.get("/domains").text
            self.assertIn('id="table-all-domains"', listed)
            self.assertIn("/d/p4-seen", listed)
            self.assertIn("只有里面的人看得到", overseer.get("/domains?q=p4-seen").text)
            self.assertNotIn("只有里面的人看得到", overseer.get("/domains?q=p4-nothing-like-it").text)
            for path in ("", "/members", "/settings"):
                self.assertEqual(overseer.get("/d/p4-seen" + path).status_code, 200, path)
        self.assertEqual(self.teacher.form("/d/p4-seen/settings", "archive"), "")
        self.assertIn("/d/p4-seen", self.admin.get("/domains").text)
        self.assertEqual(self.student.get("/d/p4-seen").status_code, 404)

    def test_who_came_with_an_invitation_may_leave_and_who_was_put_on_the_list_may_not(self):
        self.teacher.new_domain("p4-leave")
        guest, listed = account("p4_leave_guest"), account("p4_leave_listed")
        self.assertEqual(redeem(guest, new_invite(self.teacher, "p4-leave")), "")
        self.assertEqual(member_form(self.teacher, "p4-leave", "add", username="p4_leave_listed", role="member"), "")
        self.assertIn('id="button-leave-domain"', guest.get("/d/p4-leave/members").text)
        self.assertNotIn('id="button-leave-domain"', listed.get("/d/p4-leave/members").text)
        self.assertNotEqual(listed.form("/d/p4-leave/members", "leave"), "")
        self.assertEqual(role_in("p4-leave", "p4_leave_listed"), "member")
        self.assertEqual(guest.form("/d/p4-leave/members", "leave"), "")
        self.assertIsNone(role_in("p4-leave", "p4_leave_guest"))
        self.assertEqual(guest.get("/d/p4-leave").status_code, 404)

    def test_members_are_managed_by_the_owner_and_the_administrators(self):
        self.teacher.new_domain("p4-staff")
        co, lecturer, tutor, pupil = (account(name) for name in ("p4_co", "p4_lecturer", "p4_tutor", "p4_pupil"))
        for name, role in (("p4_co", "admin"), ("p4_lecturer", "teacher"), ("p4_tutor", "ta"), ("p4_pupil", "member")):
            self.assertEqual(member_form(self.teacher, "p4-staff", "add", username=name, role=role), "")
            self.assertEqual(role_in("p4-staff", name), role)
        self.assertNotEqual(member_form(self.teacher, "p4-staff", "add", username="p4_pupil", role="ta"), "")
        self.assertNotEqual(member_form(self.teacher, "p4-staff", "add", username="p4_no_such_user", role="member"), "")
        for client in (co, lecturer, tutor, pupil):
            self.assertEqual(client.get("/d/p4-staff/members").status_code, 200)

        # teachers, assistants and students do not manage members
        account("p4_extra")
        for client in (lecturer, tutor, pupil, self.student):
            member_form(client, "p4-staff", "add", username="p4_extra", role="member")
            member_form(client, "p4-staff", "remove", username="p4_pupil")
            member_form(client, "p4-staff", "role", username="p4_pupil", role="teacher")
        self.assertIsNone(role_in("p4-staff", "p4_extra"))
        self.assertEqual(role_in("p4-staff", "p4_pupil"), "member")

        # an administrator does, but neither makes nor unmakes administrators, and the owner is out of reach
        self.assertEqual(member_form(co, "p4-staff", "add", username="p4_extra", role="ta"), "")
        self.assertEqual(member_form(co, "p4-staff", "role", username="p4_extra", role="teacher"), "")
        self.assertEqual(role_in("p4-staff", "p4_extra"), "teacher")
        self.assertIn("所有者", member_form(co, "p4-staff", "role", username="p4_extra", role="admin"))
        account("p4_second_co")
        self.assertEqual(member_form(self.teacher, "p4-staff", "add", username="p4_second_co", role="admin"), "")
        self.assertNotEqual(member_form(co, "p4-staff", "remove", username="p4_second_co"), "")
        self.assertNotEqual(member_form(co, "p4-staff", "role", username="p4_second_co", role="member"), "")
        self.assertNotEqual(member_form(co, "p4-staff", "remove", username="p4_teacher"), "")
        self.assertNotEqual(member_form(co, "p4-staff", "role", username="p4_teacher", role="member"), "")
        self.assertEqual(role_in("p4-staff", "p4_second_co"), "admin")
        self.assertEqual(role_in("p4-staff", "p4_teacher"), "owner")
        self.assertEqual(member_form(co, "p4-staff", "remove", username="p4_extra"), "")
        self.assertIsNone(role_in("p4-staff", "p4_extra"))
        # the owner can not be turned into a member either
        self.assertNotEqual(member_form(self.teacher, "p4-staff", "role", username="p4_teacher", role="member"), "")
        self.assertNotEqual(member_form(self.teacher, "p4-staff", "remove", username="p4_teacher"), "")
        self.assertEqual(role_in("p4-staff", "p4_teacher"), "owner")

        # only the settings of administrators
        self.assertEqual(co.get("/d/p4-staff/settings").status_code, 200)
        for client in (lecturer, tutor, pupil):
            self.assertEqual(client.get("/d/p4-staff/settings").status_code, 403)

    def test_domain_is_handed_over_to_exactly_one_owner(self):
        self.teacher.new_domain("p4-handover")
        heir, co = account("p4_heir"), account("p4_handover_co")
        settings = "/d/p4-handover/settings"
        owners = lambda: db("select owner_username from domains where slug = 'p4-handover'")
        # only to somebody who is in the domain, and only by the owner
        self.assertIn("成员", self.teacher.form(settings, "transfer", username="p4_heir"))
        self.assertEqual(member_form(self.teacher, "p4-handover", "add", username="p4_heir", role="member"), "")
        self.assertEqual(member_form(self.teacher, "p4-handover", "add", username="p4_handover_co", role="admin"), "")
        self.assertNotEqual(co.form(settings, "transfer", username="p4_handover_co"), "")
        self.assertNotEqual(heir.form(settings, "transfer", username="p4_heir"), "")
        self.assertEqual(owners(), [["p4_teacher"]])

        self.assertEqual(self.teacher.form(settings, "transfer", username="p4_heir"), "")
        self.assertEqual(owners(), [["p4_heir"]])
        # the owner before is an administrator now, the new owner is no longer listed as a member
        self.assertEqual(role_in("p4-handover", "p4_teacher"), "admin")
        self.assertEqual(role_in("p4-handover", "p4_heir"), "owner")
        self.assertEqual(
            db("select username, role from domain_members where domain_id = %d order by username" % domain_id("p4-handover")),
            [["p4_handover_co", "admin"], ["p4_teacher", "admin"]],
        )
        # and has no say about the ownership any more
        self.assertNotEqual(self.teacher.form(settings, "transfer", username="p4_teacher"), "")
        self.assertNotEqual(self.teacher.form(settings, "archive"), "")
        self.assertEqual(owners(), [["p4_heir"]])
        self.assertEqual(db_value("select archived_at is null from domains where slug = 'p4-handover'"), "1")

    def test_archived_domain_is_read_only(self):
        self.teacher.new_domain("p4-archived")
        pupil = account("p4_archived_pupil")
        self.assertEqual(member_form(self.teacher, "p4-archived", "add", username="p4_archived_pupil", role="member"), "")
        self.assertEqual(self.teacher.form("/d/p4-archived/settings", "archive"), "")
        self.assertEqual(db_value("select archived_at is not null from domains where slug = 'p4-archived'"), "1")

        self.assertEqual(pupil.get("/d/p4-archived").status_code, 200)
        self.assertIn("已归档", pupil.get("/d/p4-archived").text)
        account("p4_archived_late")
        self.assertNotEqual(member_form(self.teacher, "p4-archived", "add", username="p4_archived_late", role="member"), "")
        base = dict(name="x", description="", type="course")
        self.assertNotEqual(self.teacher.form("/d/p4-archived/settings", "settings", **base), "")
        self.assertEqual(db_value("select name from domains where slug = 'p4-archived'"), "域 p4-archived")

        # its owner brings it back
        self.assertEqual(self.teacher.form("/d/p4-archived/settings", "archive"), "")
        self.assertEqual(member_form(self.teacher, "p4-archived", "add", username="p4_archived_late", role="member"), "")

    def test_administrator_of_the_site_manages_every_domain(self):
        self.teacher.new_domain("p4-overseen")
        self.assertEqual(self.admin.get("/d/p4-overseen/members").status_code, 200)
        self.assertEqual(member_form(self.admin, "p4-overseen", "add", username="p4_student", role="admin"), "")
        self.assertEqual(role_in("p4-overseen", "p4_student"), "admin")
        self.assertEqual(member_form(self.admin, "p4-overseen", "remove", username="p4_student"), "")
        # without becoming a member
        self.assertIsNone(role_in("p4-overseen", uoj.ADMIN[0]))
        self.assertIn("全站管理员", self.admin.get("/d/p4-overseen").text)
        log = db("select action, actor from audit_logs where resource_type = 'domain' and resource_id = '%d' order by id"
                 % domain_id("p4-overseen"))  # fmt: skip
        self.assertEqual(log, [["domain.create", "p4_teacher"], ["domain.add_member", uoj.ADMIN[0]],
                               ["domain.remove_member", uoj.ADMIN[0]]])  # fmt: skip


    def test_announcements_are_written_by_the_teachers_of_the_domain(self):
        self.teacher.new_domain("p4-news")
        pupil, tutor = account("p4_news_pupil"), account("p4_news_tutor")
        self.assertEqual(member_form(self.teacher, "p4-news", "add", username="p4_news_pupil", role="member"), "")
        self.assertEqual(member_form(self.teacher, "p4-news", "add", username="p4_news_tutor", role="ta"), "")
        news = "/d/p4-news/announcements"
        did = domain_id("p4-news")

        text = "考试在 **周五**。<script>alert(1)</script>"
        self.assertEqual(self.teacher.form(news, "save", title="期中考试 <安排>", content_md=text), "")
        self.assertEqual(self.teacher.form(news, "save", title="第 1 次作业讲评", content_md="见课件。", pinned="on"), "")
        for client in (tutor, pupil):
            self.assertNotEqual(client.form(news, "save", title="不该出现", content_md="x"), "")
        self.assertEqual(db_value("select count(*) from domain_announcements where domain_id = %d" % did), "2")
        self.assertNotEqual(self.teacher.form(news, "save", title="", content_md="x"), "")
        self.assertNotEqual(self.teacher.form(news, "save", title="x", content_md="  "), "")

        page = pupil.get(news).text
        self.assertIn("<strong>周五</strong>", page)
        self.assertNotIn("alert(1)", page)
        self.assertIn("期中考试 &lt;安排&gt;", page)
        # the pinned one comes first, and the front page of the domain lists them
        self.assertLess(page.index("第 1 次作业讲评"), page.index("期中考试"))
        self.assertIn("第 1 次作业讲评", pupil.get("/d/p4-news").text)
        self.assertNotIn('id="form-announcement"', page)
        self.assertEqual(account("p4_student").get(news).status_code, 404)

        first = db_value("select id from domain_announcements where domain_id = %d order by id limit 1" % did)
        self.assertEqual(self.teacher.form(news + "?edit=" + first, "save", title="期中考试改期", content_md="改到 *周六*。"), "")
        self.assertEqual(
            db("select title, pinned from domain_announcements where id = %s" % first), [["期中考试改期", "0"]]
        )
        self.assertIn("<em>周六</em>", pupil.get(news).text)
        pupil.form(news, "delete", id=first)
        self.assertEqual(db_value("select count(*) from domain_announcements where domain_id = %d" % did), "2")
        self.assertEqual(self.teacher.form(news, "delete", id=first), "")
        self.assertEqual(db_value("select count(*) from domain_announcements where domain_id = %d" % did), "1")


class DomainJoinTest(unittest.TestCase):
    """invitations and rosters"""

    @classmethod
    def setUpClass(cls):
        cls.teacher = account("p4_join_teacher")
        assert uoj.admin().change_user("p4_join_teacher", "grant:teacher") == ""

    def test_invitation_is_a_secret_that_is_kept_only_as_a_hash(self):
        self.teacher.new_domain("p4-invite")
        token = new_invite(self.teacher, "p4-invite", label="周二班")
        self.assertRegex(token, r"^[A-Za-z0-9_-]{24}$")
        # the database does not know the token, and the page shows it no second time
        self.assertEqual(uoj.columns_holding(token), [])
        self.assertEqual(
            db("select token_hash, label, uses from domain_invites where domain_id = %d" % domain_id("p4-invite")),
            [[uoj.sha256(token.encode()), "周二班", "0"]],
        )
        self.assertNotIn(token, self.teacher.get("/d/p4-invite/members").text)
        self.assertNotIn(token, db_value("select group_concat(ifnull(after_json, '')) from audit_logs where action = 'domain.create_invite'"))

        guest = account("p4_invited")
        self.assertEqual(redeem(guest, token), "")
        self.assertEqual(role_in("p4-invite", "p4_invited"), "member")
        self.assertEqual(db_value("select uses from domain_invites where domain_id = %d" % domain_id("p4-invite")), "1")
        self.assertEqual(guest.get("/d/p4-invite").status_code, 200)
        # the hash is not a token
        self.assertNotEqual(redeem(account("p4_invited_2"), uoj.sha256(token.encode())), "")
        self.assertIsNone(role_in("p4-invite", "p4_invited_2"))

    def test_invitation_expires_runs_out_and_is_revoked(self):
        self.teacher.new_domain("p4-invite-ends")
        did = domain_id("p4-invite-ends")
        clients = [account("p4_ends_%d" % n) for n in range(6)]

        expired = new_invite(self.teacher, "p4-invite-ends", label="expired")
        db("update domain_invites set expires_at = now() - interval 1 minute where domain_id = %d and label = 'expired'" % did)
        self.assertIn("无效或已过期", redeem(clients[0], expired))

        twice = new_invite(self.teacher, "p4-invite-ends", label="twice", max_uses="2")
        self.assertEqual(redeem(clients[1], twice), "")
        self.assertEqual(redeem(clients[2], twice), "")
        self.assertIn("无效或已过期", redeem(clients[3], twice))

        revoked = new_invite(self.teacher, "p4-invite-ends", label="revoked")
        invite_id = db_value("select id from domain_invites where domain_id = %d and label = 'revoked'" % did)
        self.assertEqual(member_form(self.teacher, "p4-invite-ends", "revoke", id=invite_id), "")
        self.assertIn("无效或已过期", redeem(clients[4], revoked))

        # an invitation is worth nothing while its domain is archived
        still_good = new_invite(self.teacher, "p4-invite-ends", label="closed")
        self.assertEqual(self.teacher.form("/d/p4-invite-ends/settings", "archive"), "")
        self.assertIn("无效或已过期", redeem(clients[5], still_good))
        self.assertEqual(self.teacher.form("/d/p4-invite-ends/settings", "archive"), "")
        self.assertEqual(redeem(clients[5], still_good), "")

        self.assertEqual(
            sorted(row[0] for row in db("select username from domain_members where domain_id = %d" % did)),
            ["p4_ends_1", "p4_ends_2", "p4_ends_5"],
        )
        self.assertEqual(
            db("select label, uses from domain_invites where domain_id = %d order by id" % did),
            [["expired", "0"], ["twice", "2"], ["revoked", "0"], ["closed", "1"]],
        )

    def test_only_one_of_many_gets_the_last_use(self):
        self.teacher.new_domain("p4-invite-race")
        did = domain_id("p4-invite-race")
        token = new_invite(self.teacher, "p4-invite-race", max_uses="1")
        clients = [account("p4_race_%d" % n) for n in range(10)]
        answers = [None] * len(clients)

        def run(n):
            answers[n] = redeem(clients[n], token)

        threads = [threading.Thread(target=run, args=(n,)) for n in range(len(clients))]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(answers.count(""), 1, answers)
        self.assertEqual(db_value("select count(*) from domain_members where domain_id = %d" % did), "1")
        self.assertEqual(db_value("select uses from domain_invites where domain_id = %d" % did), "1")

    def test_member_does_not_use_up_an_invitation(self):
        self.teacher.new_domain("p4-invite-member")
        did = domain_id("p4-invite-member")
        token = new_invite(self.teacher, "p4-invite-member", max_uses="1")
        member = account("p4_already_in")
        self.assertEqual(member_form(self.teacher, "p4-invite-member", "add", username="p4_already_in", role="ta"), "")
        redeem(member, token)
        redeem(self.teacher, token)
        self.assertEqual(db_value("select uses from domain_invites where domain_id = %d" % did), "0")
        self.assertEqual(role_in("p4-invite-member", "p4_already_in"), "ta")
        # so that it is still there for whom it was made
        self.assertEqual(redeem(account("p4_not_yet_in"), token), "")
        self.assertEqual(role_in("p4-invite-member", "p4_not_yet_in"), "member")

    def test_join_page_tells_nothing_about_domains(self):
        self.teacher.new_domain("p4-invite-quiet")
        stranger = account("p4_quiet")
        expired = new_invite(self.teacher, "p4-invite-quiet")
        db("update domain_invites set expires_at = now() - interval 1 minute where domain_id = %d" % domain_id("p4-invite-quiet"))

        # an invitation that ended, one that never was, and nonsense are refused in the same words
        answers = {
            refusal(stranger, "/domains/join", "redeem", token=token)
            for token in (expired, "A" * 24, "p4-invite-quiet", "", "x" * 64)
        }
        self.assertEqual(len(answers), 1, answers)
        self.assertNotIn("p4-invite-quiet", answers.pop())
        self.assertEqual(uoj.Client().get("/domains/join").status_code, 200)
        self.assertNotEqual(uoj.Client().form("/domains/join", "redeem", token="A" * 24), "")

    def test_roster_adds_users_and_keeps_the_rest_for_their_first_login(self):
        if "/login/sso/cas" not in uoj.Client().get("/login").text:
            self.skipTest("the single sign-on is not configured, see configure.py")
        self.teacher.new_domain("p4-roster")
        did = domain_id("p4-roster")
        account("p4_roster_local")
        # a student of the school whose username was corrected: the roster knows the student number
        known = uoj.Client()
        p3.cas_login(known, "p4_known", employeeNumber="20250001", cn="钱一")
        self.assertEqual(
            uoj.admin().submit_form("/super-manage/users", "rename",
                                    {"rename_username": "20250001", "rename_new_username": "20250091"}),  # fmt: skip
            "",
        )
        roster = "p4_roster_local\n20250001\n20250002\n\n  20250003  \nnot a student!\np4_roster_local\n"
        self.assertEqual(member_form(self.teacher, "p4-roster", "import", roster=roster, role="member"), "")
        report = uoj.text_of(self.teacher.get("/d/p4-roster/members").text)
        self.assertIn("已加入 2 人", report)
        self.assertIn("已挂起 2 人", report)
        self.assertIn("not a student!", report)
        self.assertEqual(role_in("p4-roster", "p4_roster_local"), "member")
        self.assertEqual(role_in("p4-roster", "20250091"), "member")
        self.assertEqual(
            db("select student_id, role from domain_pending_members where domain_id = %d order by student_id" % did),
            [["20250002", "member"], ["20250003", "member"]],
        )

        # the student arrives through the school, and is in the class
        newcomer = uoj.Client()
        p3.cas_login(newcomer, "p4_newcomer", employeeNumber="20250002", cn="孙二")
        self.assertEqual(p3.who(newcomer), "20250002")
        self.assertEqual(role_in("p4-roster", "20250002"), "member")
        self.assertEqual(newcomer.get("/d/p4-roster").status_code, 200)
        self.assertEqual(
            db("select student_id from domain_pending_members where domain_id = %d" % did), [["20250003"]]
        )
        # the teachers of the domain see who their students are
        members = self.teacher.get("/d/p4-roster/members").text
        self.assertIn("孙二", members)
        self.assertNotIn("孙二", newcomer.get("/d/p4-roster/members").text)

        # a line that was removed from the roster lets nobody in
        pending_id = db_value("select id from domain_pending_members where domain_id = %d" % did)
        self.assertEqual(member_form(self.teacher, "p4-roster", "unpend", id=pending_id), "")
        latecomer = uoj.Client()
        p3.cas_login(latecomer, "p4_latecomer", employeeNumber="20250003")
        self.assertIsNone(role_in("p4-roster", "20250003"))

    def test_student_number_with_letters_is_a_student_number(self):
        if "/login/sso/cas" not in uoj.Client().get("/login").text:
            self.skipTest("the single sign-on is not configured, see configure.py")
        self.teacher.new_domain("p4-letters")
        did = domain_id("p4-letters")
        # numbers with letters go on the roster like any other, and wait for their students
        roster = "CS21000009\npb21000007\nZhang3\n"
        self.assertEqual(member_form(self.teacher, "p4-letters", "import", roster=roster, role="member"), "")
        self.assertIn("已挂起 3 人", uoj.text_of(self.teacher.get("/d/p4-letters/members").text))

        # Nobody takes such a name before its student arrives: not one that looks like a
        # student number, and not one that waits on a roster whatever it looks like.
        for name in ("CS21000009", "PB21000007", "CS21000010", "Zhang3"):
            with self.assertRaises(Exception, msg=name):
                uoj.Client().register(name, "x")
            self.assertIn("统一身份认证", account("p4_letters_squat").update_profile(username=name), name)
            self.assertEqual(db_value("select count(*) from user_info where username = '%s'" % name), "0")

        # the students arrive through the school, are named by their numbers and are in the class
        first, second = uoj.Client(), uoj.Client()
        p3.cas_login(first, "p4_letters_first", employeeNumber="CS21000009", cn="周一")
        # the school writes the number in capitals, the roster of the teacher did not
        p3.cas_login(second, "p4_letters_second", employeeNumber="PB21000007", cn="吴二")
        self.assertEqual((p3.who(first), p3.who(second)), ("CS21000009", "PB21000007"))
        for name in ("CS21000009", "PB21000007"):
            self.assertEqual(role_in("p4-letters", name), "member")
        self.assertEqual(db("select student_id from domain_pending_members where domain_id = %d" % did), [["Zhang3"]])
        members = self.teacher.get("/d/p4-letters/members").text
        for name in ("CS21000009", "PB21000007"):
            self.assertRegex(members, r'<span class="uoj-username"[^>]*>%s</span>' % name)

        # A line of a roster is the student number the school vouches for before it is a
        # username. Somebody was called by a number before the school gave it to a student:
        account("p4_lt_impostor")
        self.assertEqual(
            uoj.admin().submit_form("/super-manage/users", "rename",
                                    {"rename_username": "p4_lt_impostor", "rename_new_username": "CS22000001"}),  # fmt: skip
            "",
        )
        # the student got the number when the school corrected the one they came with
        p3.cas_login(uoj.Client(), "p4_letters_real", employeeNumber="CS22000002", cn="郑三")
        real = uoj.Client()
        p3.cas_login(real, "p4_letters_real", employeeNumber="CS22000001", cn="郑三")
        self.assertEqual(p3.who(real), "CS22000002")
        self.teacher.new_domain("p4-letters-next")
        self.assertEqual(member_form(self.teacher, "p4-letters-next", "import", roster="CS22000001", role="member"), "")
        self.assertEqual(role_in("p4-letters-next", "CS22000002"), "member")
        self.assertIsNone(role_in("p4-letters-next", "CS22000001"))

    def test_numbers_that_are_equal_to_php_are_two_students(self):
        if "/login/sso/cas" not in uoj.Client().get("/login").text:
            self.skipTest("the single sign-on is not configured, see configure.py")
        # '0123456' == '123456' and '21E0004' == '210000' to PHP, which compares what looks like numbers as numbers
        clients = {}
        for number in ("0123456", "123456", "21E0004", "210000"):
            clients[number] = uoj.Client()
            p3.cas_login(clients[number], "p4_equal_" + number, employeeNumber=number)
            self.assertEqual(p3.who(clients[number]), number)
            clients[number].username = number
        only_mine = {"view_content_type": "SELF", "view_all_details_type": "SELF", "view_details_type": "SELF"}
        problem_id = uoj.admin().create_problem(ab_problem_files(), extra_config=only_mine)
        for author, other in (("0123456", "123456"), ("123456", "0123456"), ("21E0004", "210000"), ("210000", "21E0004")):
            submission_id = clients[author].submit(problem_id, AB + "// source-of-%s\n" % author)
            self.assertEqual(uoj.wait_submission(submission_id).score, 100)
            self.assertIn("source-of-" + author, clients[author].get("/submission/%d" % submission_id).text)
            self.assertNotIn("source-of-" + author, clients[other].get("/submission/%d" % submission_id).text, (author, other))
        # each has a profile that is theirs alone
        for viewer, shown in (("123456", "0123456"), ("210000", "21E0004")):
            self.assertIn('href="/user/msg?enter=%s"' % shown, clients[viewer].get("/user/profile/" + shown).text)

    def test_roster_can_not_hand_out_what_its_importer_can_not(self):
        self.teacher.new_domain("p4-roster-roles")
        co = account("p4_roster_co")
        account("p4_roster_target")
        self.assertEqual(member_form(self.teacher, "p4-roster-roles", "add", username="p4_roster_co", role="admin"), "")
        self.assertNotEqual(member_form(co, "p4-roster-roles", "import", roster="p4_roster_target", role="admin"), "")
        self.assertIsNone(role_in("p4-roster-roles", "p4_roster_target"))
        self.assertEqual(member_form(co, "p4-roster-roles", "import", roster="p4_roster_target", role="ta"), "")
        self.assertEqual(role_in("p4-roster-roles", "p4_roster_target"), "ta")


class DomainProblemTest(unittest.TestCase):
    """problems that belong to a domain, and copies of problems"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = account("p4_prob_teacher")
        assert cls.admin.change_user("p4_prob_teacher", "grant:teacher") == ""
        cls.teacher.new_domain("p4-problems")
        cls.did = domain_id("p4-problems")
        cls.pupil = account("p4_prob_pupil")
        cls.stranger = account("p4_prob_stranger")
        assert member_form(cls.teacher, "p4-problems", "add", username="p4_prob_pupil", role="member") == ""
        cls.public_id = cls.admin.create_problem(ab_problem_files())

    def copy(self, client, problem_id, slug="p4-problems"):
        return client.form("/d/%s/problems" % slug, "copy", problem_id=str(problem_id))

    def newest_problem(self, did=None):
        return int(db_value("select max(id) from problems where owner_domain_id = %d" % (did or self.did)))

    def data_version(self, problem_id):
        return db("select problems.data_version, sha256 from problems, problem_data_versions"
                  " where problems.id = %d and problem_id = problems.id and version = data_version" % problem_id)[0]  # fmt: skip

    def test_problem_of_a_domain_is_seen_in_the_domain_only(self):
        problems = "/d/p4-problems/problems"
        # students do not create problems
        count = lambda: db_value("select count(*) from problems where owner_domain_id = %d" % self.did)
        before = count()
        self.pupil.form(problems, "new")
        self.assertEqual(count(), before)
        self.assertEqual(self.teacher.form(problems, "new"), "")
        self.assertEqual(int(count()), int(before) + 1)
        problem_id = self.newest_problem()
        self.assertEqual(db_value("select is_hidden from problems where id = %d" % problem_id), "1")
        # it has a number in the domain, and an id that is none of the numbers of the site
        number = uoj.pid(problem_id)
        self.assertEqual(number, int(count()))
        self.assertGreater(problem_id, 1000000)
        newest_of_the_site = int(db_value("select max(id) from problems where owner_domain_id is null"))
        self.assertEqual(self.admin.new_problem(), newest_of_the_site + 1)
        self.assertIn('href="/d/p4-problems/problem/%d/manage/statement"' % number, self.teacher.get(problems).text)
        # whoever teaches in the domain manages its problems, without being listed for them
        self.assertEqual(db_value("select count(*) from problems_permissions where problem_id = %d" % problem_id), "0")
        self.assertIn("上传成功", self.teacher.upload_data(problem_id, ab_problem_files()).text)
        self.assertEqual(self.teacher.sync(problem_id), "")
        self.assertEqual(self.pupil.get("/problem/%d/manage/data" % problem_id).status_code, 403)

        here = "/d/p4-problems/problem/%d" % number
        # the pages that manage it are in the domain as well, under its number
        for page in ("statement", "managers", "data"):
            self.assertEqual(self.teacher.get("%s/manage/%s" % (here, page)).status_code, 200, page)
            self.assertEqual(self.pupil.get("%s/manage/%s" % (here, page)).status_code, 403, page)
        self.assertIn("#%d :" % number, self.teacher.get(here + "/manage/data").text)
        self.assertEqual(self.teacher.get(here).status_code, 200)
        self.assertEqual(self.pupil.get(here).status_code, 404)
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        page = self.pupil.get(here)
        self.assertEqual(page.status_code, 200)
        self.assertIn("域 p4-problems", page.text)
        # its address on the site leads the people of the domain to it, and nobody else anywhere
        r = self.pupil.get("/problem/%d" % problem_id)
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, here))
        for client in (self.stranger, uoj.Client()):
            self.assertEqual(client.get("/problem/%d" % problem_id).status_code, 404)
            self.assertIn(client.get(here).status_code, (302, 404))
            self.assertEqual(client.get("/problem/%d/statistics" % problem_id).status_code, 404)
        # another domain is no way in either
        self.teacher.new_domain("p4-problems-other")
        self.assertEqual(self.teacher.get("/d/p4-problems-other/problem/%d" % number).status_code, 404)
        # the numbers of a domain are its own: the first problem of the other domain is its number 1
        self.assertEqual(self.teacher.form("/d/p4-problems-other/problems", "new"), "")
        self.assertEqual(uoj.pid(self.newest_problem(domain_id("p4-problems-other"))), 1)
        # and the id of a problem is not a number of it anywhere in a domain
        self.assertEqual(self.teacher.get("/d/p4-problems/problem/%d" % problem_id).status_code, 404)

        # the list of the site does not have it, the list of the domain does
        for client in (self.admin, self.pupil, self.stranger):
            self.assertNotIn('href="/problem/%d"' % problem_id, client.get("/problems?search=%d" % problem_id).text)
            self.assertIn('href="/problem/%d"' % self.public_id, client.get("/problems?search=%d" % self.public_id).text)
        self.assertIn(here, self.pupil.get(problems).text)

        # what is submitted to it stays in the domain
        submission_id = self.pupil.submit(problem_id, AB + "// p4-domain-source\n", path=here)
        self.assertEqual(db_value("select domain_id from submissions where id = %d" % submission_id), str(self.did))
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        tutor = account("p4_prob_tutor")
        self.assertEqual(member_form(self.teacher, "p4-problems", "add", username="p4_prob_tutor", role="ta"), "")
        link = 'href="/submission/%d"' % submission_id
        for client in (self.pupil, self.teacher, tutor, self.admin):
            self.assertIn("p4-domain-source", client.get("/submission/%d" % submission_id).text)
            self.assertIn(link, client.get("/submissions?problem_id=%d" % problem_id).text)
        for client in (self.stranger, uoj.Client()):
            self.assertEqual(client.get("/submission/%d" % submission_id).status_code, 403)
            self.assertNotIn(link, client.get("/submissions?problem_id=%d" % problem_id).text)
            self.assertNotIn(link, client.get("/submissions").text)
        # what somebody solved in a domain is not told on their profile, nor counted for the site
        name = self.pupil.username
        self.assertEqual(db_value("select count(*) from best_ac_submissions where submitter = '%s' and problem_id = %d" % (name, problem_id)), "1")
        self.assertNotIn('href="/problem/%d"' % problem_id, self.stranger.get("/user/profile/" + name).text)
        self.assertEqual(
            db_value("select ac_num from user_info where username = '%s'" % name),
            db_value("select count(*) from best_ac_submissions b, problems p where p.id = b.problem_id"
                     " and p.owner_domain_id is null and b.submitter = '%s'" % name),  # fmt: skip
        )

    def test_copy_is_a_problem_of_its_own(self):
        source_version, source_sha = self.data_version(self.public_id)
        earlier = self.pupil.submit(self.public_id, AB)
        self.assertEqual(uoj.wait_submission(earlier).score, 100)

        self.assertEqual(self.copy(self.teacher, self.public_id), "")
        copy_id = self.newest_problem()
        self.assertNotEqual(copy_id, self.public_id)
        self.assertEqual(
            db("select owner_domain_id, source_problem_id, source_data_version, is_hidden, imported_by, title"
               " from problems where id = %d" % copy_id),
            [[str(self.did), str(self.public_id), source_version, "1", "p4_prob_teacher",
              db_value("select title from problems where id = %d" % self.public_id)]],
        )  # fmt: skip
        self.assertEqual(uoj.wait_data_version(copy_id), "")
        self.assertEqual(self.data_version(copy_id)[0], "1")
        # neither the submissions nor who manages the source come along
        self.assertEqual(db_value("select count(*) from submissions where problem_id = %d" % copy_id), "0")
        self.assertEqual(db_value("select ac_num from problems where id = %d" % copy_id), "0")

        # the copy is judged with its own data
        here = "/d/p4-problems/problem/%d" % uoj.pid(copy_id)
        self.assertEqual(uoj.wait_submission(self.teacher.submit(copy_id, AB, path=here)).score, 100)

        # changing the copy leaves the source alone
        stricter = ab_problem_files()
        stricter["output1.txt"] = "no program prints this\n"
        self.assertIn("上传成功", self.teacher.upload_data(copy_id, stricter).text)
        self.assertEqual(self.teacher.sync(copy_id), "")
        self.assertEqual(self.data_version(copy_id)[0], "2")
        self.assertEqual(self.data_version(self.public_id), [source_version, source_sha])
        self.assertLess(uoj.wait_submission(self.teacher.submit(copy_id, AB, path=here)).score, 100)
        self.assertEqual(uoj.wait_submission(self.pupil.submit(self.public_id, AB)).score, 100)

        # and changing the source leaves the copy alone
        copy_version = self.data_version(copy_id)
        self.assertIn("上传成功", self.admin.upload_data(self.public_id, ab_problem_files()).text)
        self.assertEqual(self.admin.sync(self.public_id), "")
        self.assertNotEqual(self.data_version(self.public_id)[0], source_version)
        self.assertEqual(self.data_version(copy_id), copy_version)
        self.assertEqual(db_value("select source_data_version from problems where id = %d" % copy_id), source_version)
        log = db("select action, actor from audit_logs where resource_type = 'problem' and resource_id = '%d' order by id limit 2" % copy_id)
        self.assertEqual(log, [["problem.copy", "p4_prob_teacher"], ["problem.sync_data", "p4_prob_teacher"]])

    def test_who_may_take_a_copy(self):
        hidden_id = self.admin.new_problem()
        count = lambda: db_value("select count(*) from problems where owner_domain_id = %d" % self.did)
        before = count()
        # a problem one may not see is refused in the same words as one that does not exist
        refusals = {
            refusal(self.teacher, "/d/p4-problems/problems", "copy", problem_id=str(problem_id))
            for problem_id in (hidden_id, 99999999)
        }
        self.assertEqual(len(refusals), 1, refusals)
        # students and strangers copy nothing
        self.assertNotEqual(self.copy(self.pupil, self.public_id), "")
        self.assertNotEqual(self.copy(self.stranger, self.public_id), "")
        self.assertEqual(count(), before)

        # a problem of another domain is copied by somebody who teaches there
        other = account("p4_prob_teacher2")
        self.assertEqual(self.admin.change_user("p4_prob_teacher2", "grant:teacher"), "")
        other.new_domain("p4-problems-theirs")
        self.assertEqual(other.form("/d/p4-problems-theirs/problems", "new"), "")
        theirs = self.newest_problem(domain_id("p4-problems-theirs"))
        self.assertIn("上传成功", other.upload_data(theirs, ab_problem_files()).text)
        self.assertEqual(other.sync(theirs), "")
        db("update problems set is_hidden = 0 where id = %d" % theirs)
        # it is named by its domain and its number there; its id names nothing
        named = "p4-problems-theirs#%d" % uoj.pid(theirs)
        self.assertNotEqual(self.copy(self.teacher, named), "")
        self.assertEqual(member_form(other, "p4-problems-theirs", "add", username="p4_prob_teacher", role="member"), "")
        self.assertNotEqual(self.copy(self.teacher, named), "")
        self.assertEqual(count(), before)
        self.assertEqual(member_form(other, "p4-problems-theirs", "role", username="p4_prob_teacher", role="teacher"), "")
        self.assertNotEqual(self.copy(self.teacher, theirs), "")
        self.assertEqual(count(), before)
        self.assertEqual(self.copy(self.teacher, named), "")
        self.assertEqual(db_value("select source_problem_id from problems where id = %d" % self.newest_problem()), str(theirs))

    def test_custom_judger_of_a_copy_is_approved_by_what_it_is(self):
        files = custom_judger_problem_files()
        files["Makefile"] += "\n# the judger of the copies\n"
        source_id = self.admin.create_problem(files)
        fingerprint = json.loads(db_value("select extra_config from problems where id = %d" % source_id))["custom_judger_fingerprint"]
        # what the system administrator synced is on record by its content
        self.assertEqual(
            db("select approved_by, problem_id from approved_judger_fingerprints where fingerprint = '%s'" % fingerprint),
            [[uoj.ADMIN[0], str(source_id)]],
        )

        # the copy does not carry the approval along, and is built because its files are the approved ones
        self.assertEqual(self.copy(self.teacher, source_id), "")
        copy_id = self.newest_problem()
        self.assertNotIn("custom_judger_fingerprint", db_value("select extra_config from problems where id = %d" % copy_id))
        self.assertEqual(uoj.wait_data_version(copy_id), "")
        here = "/d/p4-problems/problem/%d" % uoj.pid(copy_id)
        self.assertEqual(uoj.wait_submission(self.teacher.submit(copy_id, AB, path=here)).score, 100)
        # the teacher may sync it again as it is
        self.assertEqual(self.teacher.sync(copy_id), "")

        # with anything changed that the judger is built from, it is nobody's approved judger
        changed = dict(files)
        changed["Makefile"] += "# and one more line\n"
        self.assertIn("上传成功", self.teacher.upload_data(copy_id, {"Makefile": changed["Makefile"]}).text)
        self.assertIn("use_builtin_judger must be on", self.teacher.sync(copy_id))
        self.assertEqual(db_value("select data_version from problems where id = %d" % copy_id), "2")
        # until a system administrator syncs exactly that
        self.assertEqual(self.admin.sync(copy_id), "")
        self.assertEqual(db_value("select count(*) from approved_judger_fingerprints where problem_id = %d" % copy_id), "1")
        self.assertEqual(self.teacher.sync(copy_id), "")
        # and then it holds for every other problem with the same files
        self.assertEqual(self.teacher.form("/d/p4-problems/problems", "new"), "")
        twin_id = self.newest_problem()
        self.assertIn("上传成功", self.teacher.upload_data(twin_id, changed).text)
        self.assertEqual(self.teacher.sync(twin_id), "")
        uoj.wait_idle()


class DomainContestTest(unittest.TestCase):
    """contests that belong to a domain"""

    def test_contest_of_a_domain_is_for_its_members_and_run_by_its_teachers(self):
        admin = uoj.admin()
        teacher, lecturer, tutor, pupil, stranger = (
            account("p4_contest_" + name) for name in ("teacher", "lecturer", "tutor", "pupil", "stranger")
        )
        self.assertEqual(admin.change_user("p4_contest_teacher", "grant:teacher"), "")
        did = teacher.new_domain("p4-contests")
        for name, role in (("lecturer", "teacher"), ("tutor", "ta"), ("pupil", "member")):
            self.assertEqual(member_form(teacher, "p4-contests", "add", username="p4_contest_" + name, role=role), "")
        contests, new_contest = "/d/p4-contests/contests", "/d/p4-contests/contest/new"

        new = dict(name="p4 期中上机", start_time=uoj.web_time(3600), last_min="120", rule="OI", join_mode="open")
        for client in (tutor, pupil):
            self.assertEqual(client.get(new_contest).status_code, 403)
            client.form(new_contest, "create", **new)
        self.assertEqual(db_value("select count(*) from contests where domain_id = %d" % did), "0")
        self.assertNotEqual(teacher.form(new_contest, "create", **dict(new, start_time="not a time")), "")
        # a box that says the contest is rated is not in the form, and is not believed
        self.assertNotIn('name="rated"', lecturer.get(new_contest).text)
        self.assertIn('href="%s"' % new_contest, lecturer.get(contests).text)
        self.assertNotIn('href="%s"' % new_contest, pupil.get(contests).text)
        # lecturer is no teacher of the site, and creates the contest as a teacher of the domain
        self.assertEqual(lecturer.form(new_contest, "create", rated="on", **new), "")
        contest_id = int(db_value("select id from contests where domain_id = %d" % did))
        self.assertIn("unrated", db_value("select extra_config from contests where id = %d" % contest_id))
        here = "/contest/%d" % contest_id

        # the list of the site does not have it, the domain does
        for client in (admin, pupil, stranger, uoj.Client()):
            self.assertNotIn('href="%s"' % here, client.get("/contests").text)
        self.assertIn('href="%s"' % here, pupil.get(contests).text)
        # nobody outside of the domain gets to any of its pages
        for path in ("", "/registrants", "/register", "/standings", "/manage", "/export_standings"):
            self.assertEqual(stranger.get(here + path).status_code, 404, path)
            self.assertIn(uoj.Client().get(here + path).status_code, (302, 404), path)

        # everybody who teaches in the domain runs it, the assistants look behind the scenes
        for client in (teacher, lecturer, admin):
            self.assertEqual(client.get(here + "/manage").status_code, 200)
        for client in (tutor, pupil):
            self.assertEqual(client.get(here + "/manage").status_code, 403)
        self.assertEqual(tutor.get(here + "/backstage").status_code, 200)

        # its problems are the ones of the domain: a problem of the site is copied into it first
        public_id = admin.create_problem(ab_problem_files())
        self.assertEqual(teacher.form("/d/p4-contests/problems", "new"), "")
        own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
        self.assertIn("上传成功", teacher.upload_data(own_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(own_id), "")
        teacher.new_domain("p4-contests-other")
        self.assertEqual(teacher.form("/d/p4-contests-other/problems", "new"), "")
        foreign_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % domain_id("p4-contests-other")))
        copy_id = teacher.copy_problem("p4-contests", public_id)
        self.assertEqual(uoj.wait_data_version(copy_id), "")
        # what is typed is the number a problem has in the domain: not a number of the site, not an id
        for refused in (public_id, own_id, foreign_id):
            self.assertNotEqual(teacher.contest_commands(contest_id, "problems", "+%d" % refused), "", refused)
        self.assertEqual(lecturer.contest_commands(contest_id, "problems", "+%d\n+%d" % (uoj.pid(own_id), uoj.pid(copy_id))), "")
        self.assertEqual(
            sorted(int(row[0]) for row in db("select problem_id from contests_problems where contest_id = %d" % contest_id)),
            sorted([copy_id, own_id]),
        )
        # and a contest of the site does not take the problems of a domain
        site_contest = admin.new_contest("p4 全站比赛")
        self.assertNotEqual(admin.contest_commands(site_contest, "problems", "+%d" % own_id), "")
        self.assertEqual(db_value("select count(*) from contests_problems where contest_id = %d" % site_contest), "0")
        # teaching in a domain does not make a teacher of the site
        self.assertEqual(lecturer.get("/contest/new").status_code, 403)

        # the members register and take part
        pupil.register_for_contest(contest_id)
        stranger.submit_form(here + "/register", "register")
        self.assertEqual(db("select username from contests_registrants where contest_id = %d" % contest_id), [["p4_contest_pupil"]])
        uoj.move_contest(contest_id, -60, 600)
        for problem_id in (copy_id, own_id):
            submission_id = pupil.submit_in_contest(contest_id, problem_id, AB)
            self.assertEqual(
                db("select contest_id, domain_id from submissions where id = %d" % submission_id),
                [[str(contest_id), str(did)]],
            )
            for client in (pupil, tutor, lecturer):
                self.assertEqual(client.get("/submission/%d" % submission_id).status_code, 200)
            self.assertEqual(stranger.get("/submission/%d" % submission_id).status_code, 403)
        self.assertEqual(pupil.get(here + "/standings").status_code, 200)
        self.assertIn("域 p4-contests", pupil.get(here).text)
        # the addresses of its problems say the numbers they have in the domain
        self.assertIn('href="%s/problem/%d"' % (here, uoj.pid(own_id)), pupil.get(here).text)
        self.assertNotIn("/problem/%d" % own_id, pupil.get(here).text)
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200, 60)


# ---------------------------------------------------------------------- homework


def homework_settings(**changes):
    """what the form of a homework posts: by default one that began ten days ago, turned late
    three days ago and ends tomorrow, with 80% for the first day late and 50% after it"""
    fields = {
        "title": "p4 作业", "description_md": "", "claim_end_at": "", "allow_withdraw": "on",
        "begin_at": uoj.web_time(-10 * 86400), "end_at": uoj.web_time(86400),
        "allow_late": "on", "penalty_since": uoj.web_time(-3 * 86400),
        "penalty_after[]": ["0", "1"], "penalty_unit[]": ["hour", "day"], "penalty_percent[]": ["80", "50"],
    }  # fmt: skip
    fields.update(changes)
    return fields


def new_homework(client, slug, **changes):
    err = client.form("/d/%s/homework/new" % slug, "save", **homework_settings(**changes))
    if err:
        raise Exception("failed to create a homework: " + err[-600:])
    return int(db_value("select max(id) from homeworks where domain_id = %d" % domain_id(slug)))


def homework_form(client, slug, homework_id, form, **fields):
    return client.form("/d/%s/homework/%d/manage" % (slug, homework_id), form, **fields)


def homework_row(homework_id, columns):
    return db("select %s from homeworks where id = %d" % (columns, homework_id))[0]


def tick():
    """what the web server does every minute"""
    return uoj.docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php homework:tick")


def scores_of(client, slug, homework_id, query=""):
    """the scores as the people who look after a homework export them: username => row"""
    r = client.get("/d/%s/homework/%d/scoreboard?%sexport=1" % (slug, homework_id, query))
    assert r.status_code == 200, r.status_code
    rows = list(csv.reader(io.StringIO(r.content.decode("utf-8-sig"))))
    return {row[1]: dict(zip(rows[0], row)) for row in rows[1:]}


def snapshot_scores(snapshot_id):
    return {
        (row[0], int(row[1])): (float(row[2]), row[3])
        for row in db(
            "select username, problem_id, score, ifnull(submission_id, 'NULL') from homework_snapshot_scores"
            " where snapshot_id = %s" % snapshot_id
        )
    }


class HomeworkTest(unittest.TestCase):
    """homework of a domain: from a draft to official scores, and what happens to them afterwards"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = account("p4_hw_teacher")
        assert cls.admin.change_user("p4_hw_teacher", "grant:teacher") == ""
        cls.slug = "p4-homework"
        cls.did = cls.teacher.new_domain(cls.slug)
        cls.alice, cls.bob, cls.carol, cls.tutor = (account("p4_hw_" + name) for name in ("alice", "bob", "carol", "tutor"))
        for name, role in (("alice", "member"), ("bob", "member"), ("carol", "member"), ("tutor", "ta")):
            assert member_form(cls.teacher, cls.slug, "add", username="p4_hw_" + name, role=role) == ""
        cls.stranger = account("p4_hw_stranger")
        # a problem of the domain, and a public problem of the site with the copy the domain took of it
        cls.public_id = cls.admin.create_problem(ab_problem_files())
        assert cls.teacher.form("/d/%s/problems" % cls.slug, "new") == ""
        cls.own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % cls.did))
        assert "上传成功" in cls.teacher.upload_data(cls.own_id, ab_problem_files()).text
        assert cls.teacher.sync(cls.own_id) == ""
        cls.copy_id = cls.teacher.copy_problem(cls.slug, cls.public_id)
        assert uoj.wait_data_version(cls.copy_id) == ""

    def url(self, homework_id, path=""):
        return "/d/%s/homework/%d%s" % (self.slug, homework_id, path)

    def problem_url(self, homework_id, problem_id):
        """where a problem of a homework is solved: under the number it has in the domain"""
        return self.url(homework_id, "/problem/%d" % uoj.pid(problem_id))

    def wait_published(self, homework_id):
        uoj.wait_until("homework #%d is published" % homework_id,
                       lambda: tick() and homework_row(homework_id, "status")[0] != "publishing", timeout=300)  # fmt: skip
        self.assertEqual(homework_row(homework_id, "status, ifnull(publish_error, '')"), ["published", ""])

    def publish(self, homework_id, problems):
        for problem_id, score in problems:
            self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_problem", problem_id=str(uoj.pid(problem_id)), score=str(score)), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "publish"), "")
        self.wait_published(homework_id)

    def problems_of(self, homework_id):
        return db("select problem_id, ifnull(source_problem_id, 'NULL'), score, required from homework_problems"
                  " where homework_id = %d order by position" % homework_id)  # fmt: skip

    def test_homework_from_a_draft_to_official_scores(self):
        slug, teacher, alice, bob, carol, tutor = self.slug, self.teacher, self.alice, self.bob, self.carol, self.tutor
        solver = account("p4_hw_solver")
        public_submission = solver.submit(self.public_id, AB + "// p4-public-solution\n")
        self.assertEqual(uoj.wait_submission(public_submission).score, 100)

        # ---- only the people who teach in the domain set homework
        for client in (alice, tutor):
            self.assertEqual(client.get("/d/%s/homework/new" % slug).status_code, 403)
            client.form("/d/%s/homework/new" % slug, "save", **homework_settings())
        self.assertEqual(db_value("select count(*) from homeworks where domain_id = %d" % self.did), "0")
        new = "/d/%s/homework/new" % slug
        for wrong in (dict(end_at=uoj.web_time(-20 * 86400)), dict(title=""), dict(penalty_since=uoj.web_time(2 * 86400)),
                      {"penalty_percent[]": ["120", "50"]}, {"penalty_after[]": ["1", "24"], "penalty_unit[]": ["day", "hour"]}):  # fmt: skip
            self.assertNotEqual(teacher.form(new, "save", **homework_settings(**wrong)), "", wrong)
        self.assertEqual(db_value("select count(*) from homeworks where domain_id = %d" % self.did), "0")

        # ---- whoever sets the homework decides what a late submission is worth
        homework_id = new_homework(teacher, slug, title="p4 第 1 次作业", begin_at=uoj.web_time(3600), penalty_since=uoj.web_time(86400), end_at=uoj.web_time(2 * 86400))
        self.assertEqual(json.loads(homework_row(homework_id, "penalty_rules")[0]),
                         [{"after_hours": 0, "multiplier": 0.8}, {"after_hours": 24, "multiplier": 0.5}])  # fmt: skip
        self.assertEqual(homework_row(homework_id, "status, settle_state"), ["draft", "open"])
        rules = uoj.text_of(teacher.get(self.url(homework_id)).text)
        self.assertIn("迟交不超过 1 天：按 80% 计分", rules)
        self.assertIn("迟交超过 1 天：按 50% 计分", rules)
        # a homework that can not be handed in late has no steps
        strict = new_homework(teacher, slug, title="p4 不许迟交", allow_late="")
        self.assertEqual(homework_row(strict, "ifnull(penalty_since, 'NULL'), penalty_rules"), ["NULL", "[]"])

        # ---- a draft is seen by nobody who takes part
        for client in (alice, self.stranger):
            self.assertEqual(client.get(self.url(homework_id)).status_code, 404)
        self.assertNotIn("p4 第 1 次作业", alice.get("/d/%s/homeworks" % slug).text)
        self.assertEqual(tutor.get(self.url(homework_id)).status_code, 200)

        # ---- its problems: the problems of the domain, named by the numbers they have there.
        # A problem of the site is not one of them until the domain has taken a copy of it.
        copy_id = self.copy_id
        own_number, copy_number = uoj.pid(self.own_id), uoj.pid(copy_id)
        self.assertEqual(db_value("select count(*) from problems where owner_domain_id = %d and domain_pid = %d" % (self.did, self.public_id)), "0")
        for refused in (self.public_id, self.own_id, copy_id):
            self.assertNotEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(refused), score="100"), "", refused)
        self.assertNotEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(copy_number), score="0"), "")
        self.assertEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(own_number), score="50", optional="on"), "")
        self.assertEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(copy_number), score="100"), "")
        self.assertEqual(homework_form(teacher, slug, homework_id, "move_problem", problem_id=str(copy_id)), "")
        promised = [[str(copy_id), "NULL", "100", "1"], [str(self.own_id), "NULL", "50", "0"]]
        self.assertEqual(self.problems_of(homework_id), promised)
        page = teacher.get(self.url(homework_id, "/manage")).text
        self.assertIn("#%d. " % copy_number, page)
        self.assertIn("复制自 主站 #%d" % self.public_id, page)
        for client in (alice, tutor):
            self.assertEqual(client.get(self.url(homework_id, "/manage")).status_code, 403)
            homework_form(client, slug, homework_id, "publish")
        self.assertEqual(homework_row(homework_id, "status")[0], "draft")

        # ---- publishing tells the students, and makes no problem
        messages = int(db_value("select count(*) from user_system_msg where receiver = 'p4_hw_alice'"))
        copies = db_value("select count(*) from problems where owner_domain_id = %d" % self.did)
        self.assertEqual(homework_form(teacher, slug, homework_id, "publish"), "")
        self.wait_published(homework_id)
        self.assertEqual(self.problems_of(homework_id), promised)
        self.assertEqual(db_value("select is_hidden from problems where id = %d" % copy_id), "1")
        self.assertEqual(int(db_value("select count(*) from user_system_msg where receiver = 'p4_hw_alice'")), messages + 1)
        # once it is published its problems are what was promised
        self.assertNotEqual(homework_form(teacher, slug, homework_id, "remove_problem", problem_id=str(copy_id)), "")
        self.assertNotEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(own_number), score="10"), "")
        # another homework of the domain uses the same problem
        self.publish(strict, [(copy_id, 100)])
        self.assertEqual(self.problems_of(strict), [[str(copy_id), "NULL", "100", "1"]])
        self.assertEqual(db_value("select count(*) from problems where owner_domain_id = %d" % self.did), copies)
        # that one is over long before the rest of this test, so that it closes nothing
        db("update homeworks set begin_at = '%s', end_at = '%s' where id = %d" % (uoj.web_time(-20 * 86400), uoj.web_time(-19 * 86400), strict))

        # ---- students claim the homework to take part, and see no problem before it begins
        self.assertIn("p4 第 1 次作业", alice.get("/d/%s/homeworks" % slug).text)
        self.assertEqual(self.stranger.get(self.url(homework_id)).status_code, 404)
        page = alice.get(self.url(homework_id)).text
        self.assertIn('id="button-claim-homework"', page)
        self.assertNotIn(self.problem_url(homework_id, copy_id), page)
        self.assertEqual(alice.form(self.url(homework_id), "claim"), "")
        self.assertNotEqual(tutor.form(self.url(homework_id), "claim"), "")
        self.assertNotEqual(self.stranger.form(self.url(homework_id), "claim"), "")
        participants = lambda: db("select username, status from homework_participants where homework_id = %d order by username" % homework_id)
        self.assertEqual(participants(), [["p4_hw_alice", "active"]])
        page = alice.get(self.url(homework_id)).text
        self.assertIn('id="homework-problems-closed"', page)
        self.assertNotIn(self.problem_url(homework_id, copy_id), page)
        self.assertEqual(alice.get(self.problem_url(homework_id, copy_id)).status_code, 404)
        self.assertEqual(alice.get("/d/%s/problem/%d" % (slug, copy_number)).status_code, 404)
        # before it begins, whoever claimed it may step back
        self.assertEqual(alice.form(self.url(homework_id), "withdraw"), "")
        self.assertEqual(participants(), [["p4_hw_alice", "withdrawn"]])
        self.assertEqual(alice.form(self.url(homework_id), "claim"), "")

        # ---- the homework runs: it began ten days ago, turned late three days ago, and ends tomorrow
        db("update homeworks set begin_at = '%s', penalty_since = '%s', end_at = '%s' where id = %d"
           % (uoj.web_time(-10 * 86400), uoj.web_time(-3 * 86400), uoj.web_time(86400), homework_id))  # fmt: skip
        self.assertNotEqual(alice.form(self.url(homework_id), "withdraw"), "")
        here = self.problem_url(homework_id, copy_id)
        self.assertEqual(alice.get(here).status_code, 200)
        # not without claiming it
        self.assertEqual(bob.get(here).status_code, 404)
        for client in (bob, carol):
            self.assertEqual(client.form(self.url(homework_id), "claim"), "")
        self.assertEqual(bob.get(here).status_code, 200)

        # alice was on time, bob half a day late, carol a day and a half; alice got nothing for the other problem
        submissions = {}
        for name, client, late in (("alice", alice, -2 * 86400), ("bob", bob, 43200), ("carol", carol, 36 * 3600)):
            submissions[name] = client.submit(copy_id, AB + "// p4-homework-%s\n" % name, path=here)
            db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-3 * 86400 + late), submissions[name]))
        wrong = alice.submit(self.own_id, AB_WRONG, path=self.problem_url(homework_id, self.own_id))
        db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-5 * 86400), wrong))
        self.assertEqual(
            db("select homework_id, domain_id, is_hidden from submissions where id = %d" % submissions["alice"]),
            [[str(homework_id), str(self.did), "0"]],
        )
        for submission_id in list(submissions.values()) + [wrong]:
            uoj.wait_submission(submission_id)
        # what somebody who does not take part submits through the homework is not submitted to it
        practice = tutor.submit(copy_id, AB, path=here)
        self.assertEqual(db_value("select ifnull(homework_id, 'NULL') from submissions where id = %d" % practice), "NULL")

        # ---- while it runs, what was submitted to it is nobody else's business
        link = 'href="/submission/%d"' % submissions["alice"]
        for client in (alice, teacher, tutor, self.admin):
            self.assertIn("p4-homework-alice", client.get("/submission/%d" % submissions["alice"]).text)
            self.assertIn(link, client.get("/submissions?problem_id=%d" % copy_id).text)
        for client in (bob, self.stranger, uoj.Client()):
            self.assertEqual(client.get("/submission/%d" % submissions["alice"]).status_code, 403)
            self.assertNotIn(link, client.get("/submissions?problem_id=%d" % copy_id).text)
        # and the solutions of the public problem it was copied from are closed to whoever takes part
        self.assertNotIn("p4-public-solution", alice.get("/submission/%d" % public_submission).text)
        self.assertIn("p4-public-solution", self.stranger.get("/submission/%d" % public_submission).text)
        self.assertIn("p4-public-solution", tutor.get("/submission/%d" % public_submission).text)
        self.assertIn(here, alice.get("/problem/%d" % self.public_id).text)

        # ---- the scores so far: of every problem the submission that is worth the most
        self.assertEqual(alice.get(self.url(homework_id, "/scoreboard")).status_code, 403)
        self.assertEqual(self.stranger.get(self.url(homework_id, "/scoreboard")).status_code, 404)
        for client in (teacher, tutor):
            scores = scores_of(client, slug, homework_id)
            self.assertEqual({name: row["Total"] for name, row in scores.items()},
                             {"p4_hw_alice": "100", "p4_hw_bob": "80", "p4_hw_carol": "50"})  # fmt: skip
        self.assertRegex(alice.get(self.url(homework_id)).text, r'id="my-official-score">100 <small[^>]*>/ 150')
        # a later submission that is worth less takes nothing away
        worse = bob.submit(copy_id, AB_WRONG, path=here)
        uoj.wait_submission(worse)
        self.assertEqual(scores_of(teacher, slug, homework_id)["p4_hw_bob"]["Total"], "80")
        self.assertEqual(homework_row(homework_id, "settle_state, ifnull(current_official_snapshot_id, 'NULL')"), ["open", "NULL"])

        # ---- the end: the scores are settled once, whoever looks
        db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-60), homework_id))
        threads = [threading.Thread(target=lambda c=client: c.get(self.url(homework_id))) for client in (alice, bob, carol, tutor, teacher) * 2]
        threads.append(threading.Thread(target=tick))
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        tick()
        self.assertEqual(db("select version, status, created_by from homework_snapshots where homework_id = %d" % homework_id),
                         [["1", "official", ""]])  # fmt: skip
        first = db_value("select id from homework_snapshots where homework_id = %d" % homework_id)
        self.assertEqual(homework_row(homework_id, "settle_state, current_official_snapshot_id"), ["settled", first])
        official = {
            ("p4_hw_alice", copy_id): (100.0, str(submissions["alice"])), ("p4_hw_alice", self.own_id): (0.0, str(wrong)),
            ("p4_hw_bob", copy_id): (80.0, str(submissions["bob"])), ("p4_hw_bob", self.own_id): (0.0, "NULL"),
            ("p4_hw_carol", copy_id): (50.0, str(submissions["carol"])), ("p4_hw_carol", self.own_id): (0.0, "NULL"),
        }  # fmt: skip
        self.assertEqual(snapshot_scores(first), official)
        # the snapshot knows the rules it was made with
        rules = json.loads(db_value("select rules_json from homework_snapshots where id = %s" % first))
        self.assertEqual(rules["penalty_rules"], [{"after_hours": 0, "multiplier": 0.8}, {"after_hours": 24, "multiplier": 0.5}])
        self.assertEqual(rules["end_at"], homework_row(homework_id, "end_at")[0])
        self.assertEqual([(p["problem_id"], p["score"], p["data_version"]) for p in rules["problems"]], [(copy_id, 100, 1), (self.own_id, 50, 1)])
        self.assertEqual(rules["problems"][0]["data_sha256"], db_value("select sha256 from problem_data_versions where problem_id = %d and version = 1" % copy_id))
        self.assertEqual(rules["unjudged_submissions"], [])

        # ---- afterwards: what is submitted is correction, and the official scores stand
        fixed = alice.submit(self.own_id, AB, path=self.problem_url(homework_id, self.own_id))
        self.assertEqual(uoj.wait_submission(fixed).score, 100)
        page = alice.get(self.url(homework_id)).text
        self.assertRegex(page, r'id="my-official-score">100 <small')
        self.assertRegex(page, r'id="my-correction-score">150 <small')
        self.assertEqual(snapshot_scores(first), official)
        self.assertEqual(scores_of(teacher, slug, homework_id)["p4_hw_alice"]["Total"], "100")
        self.assertEqual(scores_of(teacher, slug, homework_id, "view=correction&")["p4_hw_alice"]["Total"], "150")
        # and the homework is no secret among the members of the domain any more
        self.assertIn("p4-homework-alice", bob.get("/submission/%d" % submissions["alice"]).text)
        self.assertEqual(self.stranger.get("/submission/%d" % submissions["alice"]).status_code, 403)
        self.assertIn("p4-public-solution", alice.get("/submission/%d" % public_submission).text)

        # ---- the data of a problem is corrected: nothing changes until somebody says so
        manage = self.url(homework_id, "/manage?tab=scores")
        self.assertNotIn('id="alert-drift"', teacher.get(manage).text)
        stricter = ab_problem_files()
        stricter["output3.txt"] = "no program prints this\n"
        self.assertIn("上传成功", teacher.upload_data(copy_id, stricter).text)
        self.assertEqual(teacher.sync(copy_id), "")
        page = teacher.get(manage).text
        self.assertIn('id="alert-drift"', page)
        self.assertIn("有 3 份计分的提交是按 v1 评测的", uoj.text_of(page))
        self.assertEqual(snapshot_scores(first), official)

        # a new settlement needs a reason, judges only what was submitted to this homework again,
        # and counts for nothing before it is confirmed
        self.assertNotEqual(homework_form(teacher, slug, homework_id, "resettle", scope=str(copy_id), reason=" "), "")
        self.assertNotEqual(homework_form(alice, slug, homework_id, "resettle", scope=str(copy_id), reason="x"), "")
        self.assertNotEqual(homework_form(tutor, slug, homework_id, "resettle", scope=str(copy_id), reason="x"), "")
        self.assertEqual(db_value("select count(*) from homework_snapshots where homework_id = %d" % homework_id), "1")
        self.assertEqual(homework_form(teacher, slug, homework_id, "resettle", scope=str(copy_id), reason="第 3 个测试点的答案有误"), "")
        started = db("select version, status, active_slot, reason, created_by from homework_snapshots where homework_id = %d order by version" % homework_id)[1]
        self.assertEqual(started[:1] + started[2:], ["2", "1", "第 3 个测试点的答案有误", "p4_hw_teacher"])
        self.assertIn(started[1], ("rejudging", "candidate"))
        self.assertEqual(homework_row(homework_id, "current_official_snapshot_id")[0], first)
        self.assertIn("已经有一次", homework_form(teacher, slug, homework_id, "resettle", scope="all", reason="again"))
        # the practice of the assistant was not submitted to the homework, and is left alone
        self.assertEqual(db_value("select status from submissions where id = %d" % practice), "Judged")
        uoj.wait_until("the submissions of the homework are judged again",
                       lambda: db_value("select count(*) from submissions where homework_id = %d and status != 'Judged'" % homework_id) == "0")  # fmt: skip
        tick()
        second = db_value("select id from homework_snapshots where homework_id = %d and version = 2" % homework_id)
        self.assertEqual(db_value("select status from homework_snapshots where id = %s" % second), "candidate")
        page = teacher.get(manage).text
        self.assertIn('id="table-snapshot-changes"', page)
        self.assertIn('id="button-confirm-snapshot"', page)
        # the scores that count are still the ones of the first snapshot
        self.assertRegex(alice.get(self.url(homework_id)).text, r'id="my-official-score">100 <small')
        self.assertEqual(homework_row(homework_id, "current_official_snapshot_id")[0], first)
        self.assertNotEqual(homework_form(alice, slug, homework_id, "confirm", snapshot_id=second), "")

        messages = int(db_value("select count(*) from user_system_msg where receiver = 'p4_hw_alice'"))
        self.assertEqual(homework_form(teacher, slug, homework_id, "confirm", snapshot_id=second), "")
        self.assertEqual(homework_row(homework_id, "current_official_snapshot_id")[0], second)
        self.assertEqual(db("select version, status, ifnull(active_slot, 'NULL') from homework_snapshots where homework_id = %d order by version" % homework_id),
                         [["1", "official", "NULL"], ["2", "official", "NULL"]])  # fmt: skip
        self.assertEqual(int(db_value("select count(*) from user_system_msg where receiver = 'p4_hw_alice'")), messages + 1)
        # the first snapshot is still there with what it was, the second has the new scores and says what data made them
        self.assertEqual(snapshot_scores(first), official)
        new_scores = snapshot_scores(second)
        self.assertLess(new_scores[("p4_hw_alice", copy_id)][0], 100.0)
        self.assertLess(new_scores[("p4_hw_bob", copy_id)][0], 80.0)
        self.assertEqual(json.loads(db_value("select rules_json from homework_snapshots where id = %s" % second))["problems"][0]["data_version"], 2)
        self.assertEqual(json.loads(db_value("select rules_json from homework_snapshots where id = %s" % first))["problems"][0]["data_version"], 1)
        self.assertEqual(scores_of(teacher, slug, homework_id, "snapshot=%s&" % first)["p4_hw_alice"]["Total"], "100")
        self.assertNotEqual(scores_of(teacher, slug, homework_id)["p4_hw_alice"]["Total"], "100")
        self.assertNotIn('id="alert-drift"', teacher.get(manage).text)

        # ---- a settlement that is thrown away changes nothing
        self.assertEqual(homework_form(teacher, slug, homework_id, "resettle", scope="none", reason="只是看看"), "")
        third = db_value("select id from homework_snapshots where homework_id = %d and version = 3" % homework_id)
        tick()
        self.assertEqual(db_value("select status from homework_snapshots where id = %s" % third), "candidate")
        self.assertIn('id="snapshot-no-changes"', teacher.get(manage).text)
        self.assertEqual(homework_form(teacher, slug, homework_id, "discard", snapshot_id=third), "")
        self.assertEqual(db("select status, ifnull(active_slot, 'NULL') from homework_snapshots where id = %s" % third), [["discarded", "NULL"]])
        self.assertNotEqual(homework_form(teacher, slug, homework_id, "confirm", snapshot_id=third), "")
        self.assertEqual(homework_row(homework_id, "current_official_snapshot_id")[0], second)

        # ---- changing the homework afterwards changes no snapshot
        self.assertEqual(homework_form(teacher, slug, homework_id, "save", **homework_settings(
            title="p4 第 1 次作业", begin_at=uoj.web_time(-10 * 86400), penalty_since=uoj.web_time(-3 * 86400), end_at=uoj.web_time(-60),
            **{"penalty_after[]": ["0"], "penalty_unit[]": ["hour"], "penalty_percent[]": ["10"]})), "")  # fmt: skip
        self.assertEqual(snapshot_scores(second), new_scores)
        self.assertEqual(homework_row(homework_id, "settle_state, current_official_snapshot_id"), ["settled", second])
        self.assertIn('id="alert-stale"', teacher.get(manage).text)

        log = [row[0] for row in db("select action from audit_logs where resource_type = 'homework' and resource_id = '%d' order by id" % homework_id)]
        for action in ("homework.create", "homework.add_problem", "homework.publish", "homework.published", "homework.settle",
                       "homework.resettle", "homework.confirm_snapshot", "homework.discard_snapshot", "homework.edit", "homework.export_scores"):  # fmt: skip
            self.assertIn(action, log)
        self.assertEqual(db("select actor_type from audit_logs where resource_type = 'homework' and resource_id = '%d' and action = 'homework.settle'" % homework_id), [["system"]])
        uoj.wait_idle()


class HomeworkStateTest(unittest.TestCase):
    """publishing and settling a homework happen once, whatever gets in the way"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = account("p4_st_teacher")
        assert cls.admin.change_user("p4_st_teacher", "grant:teacher") == ""
        cls.slug = "p4-hw-states"
        cls.did = cls.teacher.new_domain(cls.slug)
        cls.pupils = [account("p4_st_pupil%d" % n) for n in range(3)]
        cls.lecturer = account("p4_st_lecturer")
        for n in range(3):
            assert member_form(cls.teacher, cls.slug, "add", username="p4_st_pupil%d" % n, role="member") == ""
        assert member_form(cls.teacher, cls.slug, "add", username="p4_st_lecturer", role="teacher") == ""
        assert cls.teacher.form("/d/%s/problems" % cls.slug, "new") == ""
        cls.own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % cls.did))
        assert "上传成功" in cls.teacher.upload_data(cls.own_id, ab_problem_files()).text
        assert cls.teacher.sync(cls.own_id) == ""

    def url(self, homework_id, path=""):
        return "/d/%s/homework/%d%s" % (self.slug, homework_id, path)

    def problem_url(self, homework_id, problem_id):
        return self.url(homework_id, "/problem/%d" % uoj.pid(problem_id))

    def published(self, title, problems=None, **settings):
        """a homework that runs, of the problem of the domain unless told otherwise"""
        homework_id = new_homework(self.teacher, self.slug, title=title, **settings)
        for problem_id in problems or [self.own_id]:
            self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_problem", problem_id=str(uoj.pid(problem_id)), score="100"), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "publish"), "")
        uoj.wait_until("homework #%d is published" % homework_id,
                       lambda: tick() and homework_row(homework_id, "status")[0] != "publishing", timeout=300)  # fmt: skip
        self.assertEqual(homework_row(homework_id, "status")[0], "published")
        return homework_id

    def test_settlement_waits_for_judgements_and_goes_on_without_the_ones_that_never_come(self):
        pupil = self.pupils[0]
        homework_id = self.published("p4 结算等待", allow_late="")
        self.assertEqual(pupil.form(self.url(homework_id), "claim"), "")
        here = self.problem_url(homework_id, self.own_id)
        first = pupil.submit(self.own_id, AB_WRONG, path=here)
        self.assertEqual(uoj.wait_submission(first).score, 0)
        manage = self.url(homework_id, "/manage?tab=scores")

        with uoj.judgers_paused():
            # submitted before the end, and not judged when the end comes
            stuck = pupil.submit(self.own_id, AB, path=here)
            db("update submissions set submit_time = '%s' where id in (%d, %d)" % (uoj.web_time(-3600), first, stuck))
            db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-60), homework_id))
            pupil.get(self.url(homework_id))
            tick()
            self.assertEqual(homework_row(homework_id, "settle_state, ifnull(current_official_snapshot_id, 'NULL')"), ["waiting_judgements", "NULL"])
            self.assertEqual(db_value("select count(*) from homework_snapshots where homework_id = %d" % homework_id), "0")
            self.assertIn('id="settlement-waiting"', self.teacher.get(manage).text)
            # nothing happens however often anybody looks
            for _ in range(3):
                pupil.get(self.url(homework_id))
                tick()
            self.assertEqual(homework_row(homework_id, "settle_state")[0], "waiting_judgements")

            # no judger comes: after the grace time the homework is settled with what there is
            db("update homeworks set settle_waiting_since = '%s' where id = %d" % (uoj.web_time(-7200), homework_id))
            tick()
            snapshot = db_value("select id from homework_snapshots where homework_id = %d" % homework_id)
            self.assertEqual(homework_row(homework_id, "settle_state, current_official_snapshot_id"), ["settled", snapshot])
            self.assertEqual(snapshot_scores(snapshot), {("p4_st_pupil0", self.own_id): (0.0, str(first))})
            # and the snapshot says what it went on without
            self.assertEqual(json.loads(db_value("select rules_json from homework_snapshots where id = %s" % snapshot))["unjudged_submissions"], [stuck])

        # the judgers are back: the official score stands, and the page says that it is out of date
        self.assertEqual(uoj.wait_submission(stuck).score, 100)
        tick()
        self.assertEqual(snapshot_scores(snapshot), {("p4_st_pupil0", self.own_id): (0.0, str(first))})
        self.assertEqual(db_value("select count(*) from homework_snapshots where homework_id = %d" % homework_id), "1")
        self.assertIn('id="alert-stale"', self.teacher.get(manage).text)
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "resettle", scope="none", reason="结算时有提交没评完"), "")
        tick()
        second = db_value("select id from homework_snapshots where homework_id = %d and version = 2" % homework_id)
        self.assertEqual(db_value("select status from homework_snapshots where id = %s" % second), "candidate")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "confirm", snapshot_id=second), "")
        self.assertEqual(snapshot_scores(second), {("p4_st_pupil0", self.own_id): (100.0, str(stuck))})
        self.assertEqual(homework_row(homework_id, "current_official_snapshot_id")[0], second)

    def test_publishing_waits_for_the_data_of_its_problems(self):
        public_id = self.admin.create_problem(checker_problem_files())
        homework_id = new_homework(self.teacher, self.slug, title="p4 发布等待")
        copies = lambda: db("select id from problems where owner_domain_id = %d and source_problem_id = %d" % (self.did, public_id))

        with uoj.judgers_paused():
            # the copy the domain takes has a checker, which a judger builds, and there is none
            copy_id = self.teacher.copy_problem(self.slug, public_id)
            self.assertEqual(db_value("select status from problem_data_versions where problem_id = %d" % copy_id), "pending")
            self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_problem", problem_id=str(uoj.pid(copy_id)), score="100"), "")
            # everybody who may publishes at the same moment, and the tick of the server joins in
            clients = [self.teacher, self.lecturer, self.admin]
            threads = [threading.Thread(target=lambda c=c: homework_form(c, self.slug, homework_id, "publish")) for c in clients]
            threads += [threading.Thread(target=tick) for _ in range(3)]
            for thread in threads:
                thread.start()
            for thread in threads:
                thread.join()
            tick()
            # the homework waits, and publishing it has made no problem
            self.assertEqual(homework_row(homework_id, "status")[0], "publishing")
            self.assertEqual(len(copies()), 1)
            self.assertIn('id="homework-publishing"', self.teacher.get(self.url(homework_id, "/manage")).text)
            self.assertEqual(self.pupils[0].get(self.url(homework_id)).status_code, 404)
        uoj.wait_until("the homework is published", lambda: tick() and homework_row(homework_id, "status")[0] == "published", timeout=300)
        self.assertEqual(len(copies()), 1)
        self.assertEqual(
            db("select problem_id, ifnull(source_problem_id, 'NULL') from homework_problems where homework_id = %d" % homework_id),
            [[str(copy_id), "NULL"]],
        )
        self.assertEqual(db_value("select count(*) from audit_logs where resource_type = 'homework' and resource_id = '%d' and action = 'homework.published'" % homework_id), "1")
        # what the public problem becomes afterwards is nothing to the homework
        pupil = self.pupils[1]
        self.assertEqual(pupil.form(self.url(homework_id), "claim"), "")
        broken = checker_problem_files()
        broken["output1.txt"] = "999\n"
        self.assertIn("上传成功", self.admin.upload_data(public_id, broken).text)
        self.assertEqual(self.admin.sync(public_id), "")
        submission_id = pupil.submit(copy_id, AB, path=self.problem_url(homework_id, copy_id))
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        self.assertLess(uoj.wait_submission(self.admin.submit(public_id, AB)).score, 100)
        self.assertEqual(db_value("select data_version from problems where id = %d" % copy_id), "1")

    def grades(self, client):
        """the grades of the domain as they are exported: the head, and username => row"""
        r = client.get("/d/%s/grades?export=1" % self.slug)
        self.assertEqual(r.status_code, 200)
        rows = list(csv.reader(io.StringIO(r.content.decode("utf-8-sig"))))
        return rows[0], {row[0]: row for row in rows[1:]}

    def test_grades_of_the_domain_are_what_counts_of_every_homework(self):
        settled, running = self.published("p4 成绩甲", allow_late=""), self.published("p4 成绩乙", allow_late="")
        first, second, third = self.pupils
        for homework_id in (settled, running):
            self.assertEqual(first.form(self.url(homework_id), "claim"), "")
        self.assertEqual(second.form(self.url(settled), "claim"), "")
        submit = lambda client, homework_id, code: uoj.wait_submission(
            client.submit(self.own_id, code, path=self.problem_url(homework_id, self.own_id))
        ).score
        self.assertEqual(submit(first, settled, AB), 100)
        self.assertEqual(submit(second, settled, AB_WRONG), 0)
        self.assertEqual(submit(first, running, AB), 100)
        db("update submissions set submit_time = '%s' where homework_id = %d" % (uoj.web_time(-3600), settled))
        db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-60), settled))
        uoj.wait_until("the homework is settled", lambda: tick() and homework_row(settled, "settle_state")[0] == "settled")

        page = "/d/%s/grades" % self.slug
        head, grades = self.grades(self.teacher)
        self.assertEqual((head[:3], head[-1]), (["username", "student_id", "real_name"], "total"))
        official, so_far = head.index("p4 成绩甲"), head.index("p4 成绩乙 (未结算)")
        self.assertEqual([grades["p4_st_pupil0"][n] for n in (official, so_far)], ["100", "100"])
        # who did not claim a homework has no score in it, which is not the same as none
        self.assertEqual([grades["p4_st_pupil1"][n] for n in (official, so_far)], ["0", ""])
        self.assertEqual([grades["p4_st_pupil2"][n] for n in (official, so_far)], ["", ""])
        # the total is of the homework that is settled
        for row in grades.values():
            counted = sum(float(row[n] or 0) for n in range(3, len(head) - 1) if not head[n].endswith("(未结算)"))
            self.assertEqual(float(row[-1]), counted, row)
        self.assertNotIn("p4_st_teacher", grades)
        # a username that is a number is a user like any other on the scoreboard
        digits = account("40418")
        self.assertEqual(member_form(self.teacher, self.slug, "add", username="40418", role="member"), "")
        self.assertEqual(digits.form(self.url(running), "claim"), "")
        self.assertEqual(submit(digits, running, AB), 100)
        board = self.teacher.get(self.url(running, "/scoreboard")).text
        for name in ("40418", "p4_st_pupil0"):
            self.assertRegex(board, r'<span class="uoj-username"[^>]*>%s</span>' % name)
        self.assertEqual(scores_of(self.teacher, self.slug, running)["40418"]["Total"], "100")
        self.assertEqual(member_form(self.teacher, self.slug, "remove", username="40418"), "")
        self.assertIn('id="table-grades"', self.teacher.get(page).text)

        # the grades are for the people who look after the domain
        tutor = account("p4_st_tutor")
        self.assertEqual(member_form(self.teacher, self.slug, "add", username="p4_st_tutor", role="ta"), "")
        link = 'href="%s"' % page
        for client in (self.teacher, self.lecturer, tutor, self.admin):
            self.assertEqual(client.get(page).status_code, 200)
            self.assertIn(link, client.get("/d/%s" % self.slug).text)
        self.assertEqual(first.get(page).status_code, 403)
        self.assertEqual(first.get(page + "?export=1").status_code, 403)
        self.assertNotIn(link, first.get("/d/%s" % self.slug).text)
        self.assertEqual(account("p4_st_stranger").get(page).status_code, 404)

        # and the front page tells the people who teach what waits for them
        overview = self.teacher.get("/d/%s" % self.slug).text
        self.assertIn('id="domain-overview-pending"', overview)
        self.assertIn("名学生还没有认领", overview)
        for client in (first, tutor):
            self.assertNotIn('id="domain-overview-pending"', client.get("/d/%s" % self.slug).text)

    def test_homework_that_can_not_be_published_goes_back_to_a_draft(self):
        # a problem of the domain that has no data yet
        self.assertEqual(self.teacher.form("/d/%s/problems" % self.slug, "new"), "")
        empty_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % self.did))
        homework_id = new_homework(self.teacher, self.slug, title="p4 发布失败")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_problem", problem_id=str(uoj.pid(empty_id)), score="100"), "")
        homework_form(self.teacher, self.slug, homework_id, "publish")
        uoj.wait_until("the homework is not being published any more", lambda: tick() and homework_row(homework_id, "status")[0] != "publishing")
        status, error = homework_row(homework_id, "status, ifnull(publish_error, '')")
        self.assertEqual(status, "draft")
        self.assertIn("#%d" % uoj.pid(empty_id), error)
        self.assertNotIn(str(empty_id), error)
        self.assertIn('id="homework-publish-error"', self.teacher.get(self.url(homework_id, "/manage")).text)
        # a homework without problems is not published either
        blank = new_homework(self.teacher, self.slug, title="p4 没有题目")
        self.assertNotEqual(homework_form(self.teacher, self.slug, blank, "publish"), "")
        self.assertEqual(homework_row(blank, "status")[0], "draft")
        # a draft can be thrown away
        self.assertEqual(homework_form(self.teacher, self.slug, blank, "delete"), "")
        self.assertEqual(db_value("select count(*) from homeworks where id = %d" % blank), "0")

    def test_homework_can_be_taken_back_before_it_begins(self):
        homework_id = self.published("p4 撤回", begin_at=uoj.web_time(3600), penalty_since=uoj.web_time(7200), end_at=uoj.web_time(86400))
        self.assertNotEqual(homework_form(self.pupils[0], self.slug, homework_id, "unpublish"), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "unpublish"), "")
        self.assertEqual(homework_row(homework_id, "status")[0], "draft")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "remove_problem", problem_id=str(self.own_id)), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_problem", problem_id=str(uoj.pid(self.own_id)), score="30"), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "publish"), "")
        uoj.wait_until("the homework is published again", lambda: tick() and homework_row(homework_id, "status")[0] == "published")
        # once it has begun it stays
        db("update homeworks set begin_at = '%s' where id = %d" % (uoj.web_time(-60), homework_id))
        self.assertNotEqual(homework_form(self.teacher, self.slug, homework_id, "unpublish"), "")
        self.assertEqual(homework_row(homework_id, "status")[0], "published")

    def test_students_who_did_not_claim_are_listed_added_and_exported(self):
        homework_id = self.published("p4 未认领", allow_late="")
        claimed, forgot, also_forgot = self.pupils
        self.assertEqual(claimed.form(self.url(homework_id), "claim"), "")
        submission_id = claimed.submit(self.own_id, AB, path=self.problem_url(homework_id, self.own_id))
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

        page = self.teacher.get(self.url(homework_id, "/manage?tab=participants")).text
        unclaimed = re.search(r'(?s)id="list-unclaimed">(.*?)</p>', page).group(1)
        self.assertIn("p4_st_pupil1", unclaimed)
        self.assertIn("p4_st_pupil2", unclaimed)
        self.assertNotIn("p4_st_pupil0", unclaimed)
        self.assertNotIn("p4_st_lecturer", unclaimed)
        # the scores have who takes part; the export can list the others as such
        self.assertEqual(set(scores_of(self.teacher, self.slug, homework_id)), {"p4_st_pupil0"})
        everybody = scores_of(self.teacher, self.slug, homework_id, "unclaimed=1&")
        self.assertEqual({name: (row["Claimed"], row["Total"]) for name, row in everybody.items()},
                         {"p4_st_pupil0": ("yes", "100"), "p4_st_pupil1": ("no", ""), "p4_st_pupil2": ("no", "")})  # fmt: skip

        # one of them is put in by name, the rest all at once; whoever handed nothing in has zero
        self.assertNotEqual(homework_form(forgot, self.slug, homework_id, "add_all"), "")
        self.assertNotEqual(homework_form(self.teacher, self.slug, homework_id, "add_participant", username="p4_hw_stranger"), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_participant", username="p4_st_pupil1"), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_all"), "")
        self.assertEqual(
            db("select username, status, ifnull(added_by, 'NULL') from homework_participants where homework_id = %d order by username" % homework_id),
            [["p4_st_pupil0", "active", "NULL"], ["p4_st_pupil1", "active", "p4_st_teacher"], ["p4_st_pupil2", "active", "p4_st_teacher"]],
        )
        self.assertEqual({name: row["Total"] for name, row in scores_of(self.teacher, self.slug, homework_id).items()},
                         {"p4_st_pupil0": "100", "p4_st_pupil1": "0", "p4_st_pupil2": "0"})  # fmt: skip
        # and whoever is taken out is out of the scores, with what they submitted kept
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "remove_participant", username="p4_st_pupil0"), "")
        self.assertEqual(set(scores_of(self.teacher, self.slug, homework_id)), {"p4_st_pupil1", "p4_st_pupil2"})
        self.assertEqual(db_value("select homework_id from submissions where id = %d" % submission_id), str(homework_id))

    def test_maintainer_looks_after_one_homework(self):
        homework_id = self.published("p4 维护者")
        other = self.published("p4 别人的作业")
        keeper = self.pupils[2]
        manage = self.url(homework_id, "/manage")
        self.assertEqual(keeper.get(manage).status_code, 403)
        self.assertNotEqual(homework_form(self.teacher, self.slug, homework_id, "add_maintainer", username="p4_hw_stranger"), "")
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "add_maintainer", username="p4_st_pupil2"), "")
        for path in ("/manage", "/manage?tab=scores", "/scoreboard"):
            self.assertEqual(keeper.get(self.url(homework_id, path)).status_code, 200, path)
            self.assertEqual(keeper.get(self.url(other, path)).status_code, 403, path)
        self.assertEqual(homework_form(keeper, self.slug, homework_id, "add_participant", username="p4_st_pupil0"), "")
        self.assertEqual(db_value("select count(*) from homework_participants where homework_id = %d" % homework_id), "1")
        # but neither chooses who else looks after it, nor sets homework
        homework_form(keeper, self.slug, homework_id, "add_maintainer", username="p4_st_pupil1")
        homework_form(keeper, self.slug, homework_id, "clone")
        self.assertEqual(db("select username from homework_maintainers where homework_id = %d" % homework_id), [["p4_st_pupil2"]])
        self.assertEqual(db_value("select count(*) from homeworks where title like 'p4 维护者%%'"), "1")
        self.assertEqual(keeper.get("/d/%s/homework/new" % self.slug).status_code, 403)
        self.assertEqual(homework_form(self.teacher, self.slug, homework_id, "remove_maintainer", username="p4_st_pupil2"), "")
        self.assertEqual(keeper.get(manage).status_code, 403)

    def test_copy_of_a_homework_starts_as_a_draft_without_anybody_in_it(self):
        homework_id = self.published("p4 原作业")
        self.assertEqual(self.pupils[0].form(self.url(homework_id), "claim"), "")
        self.assertEqual(homework_form(self.lecturer, self.slug, homework_id, "clone"), "")
        clone_id = int(db_value("select max(id) from homeworks where domain_id = %d" % self.did))
        self.assertNotEqual(clone_id, homework_id)
        columns = "penalty_rules, allow_withdraw, description_md"
        self.assertEqual(homework_row(clone_id, columns), homework_row(homework_id, columns))
        self.assertEqual(homework_row(clone_id, "title, status, created_by, settle_state"), ["p4 原作业（副本）", "draft", "p4_st_lecturer", "open"])
        problems = lambda h: db("select problem_id, score, required from homework_problems where homework_id = %d order by position" % h)
        self.assertEqual(problems(clone_id), problems(homework_id))
        self.assertEqual(db_value("select count(*) from homework_participants where homework_id = %d" % clone_id), "0")


if __name__ == "__main__":
    unittest.main()


class TrainingTest(unittest.TestCase):
    """trainings: lists of problems, and what the members have done of them"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = account("p4_tr_teacher")
        assert cls.admin.change_user("p4_tr_teacher", "grant:teacher") == ""
        cls.slug = "p4-trainings"
        cls.did = cls.teacher.new_domain(cls.slug)
        cls.pupil, cls.other, cls.tutor, cls.stranger = (account("p4_tr_" + name) for name in ("pupil", "other", "tutor", "stranger"))
        for name, role in (("pupil", "member"), ("other", "member"), ("tutor", "ta")):
            assert member_form(cls.teacher, cls.slug, "add", username="p4_tr_" + name, role=role) == ""
        # a student whose username is a number, as the ones who come through the single sign-on are
        cls.digits = account("40417")
        assert member_form(cls.teacher, cls.slug, "add", username="40417", role="member") == ""
        assert cls.teacher.form("/d/%s/problems" % cls.slug, "new") == ""
        cls.own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % cls.did))
        assert "上传成功" in cls.teacher.upload_data(cls.own_id, ab_problem_files()).text
        assert cls.teacher.sync(cls.own_id) == ""
        db("update problems set is_hidden = 0 where id = %d" % cls.own_id)
        # a public problem of the site, and the copy the domain took of it
        cls.public_id = cls.admin.create_problem(ab_problem_files())
        cls.copy_id = cls.teacher.copy_problem(cls.slug, cls.public_id)
        assert uoj.wait_data_version(cls.copy_id) == ""
        db("update problems set is_hidden = 0 where id = %d" % cls.copy_id)

    def progress(self, client, training_id):
        """what the people who look after the domain export: username => row"""
        r = client.get("/d/%s/training/%d?view=progress&export=1" % (self.slug, training_id))
        self.assertEqual(r.status_code, 200)
        if "text/csv" not in r.headers.get("Content-Type", ""):
            return None
        rows = list(csv.reader(io.StringIO(r.content.decode("utf-8-sig"))))
        return {row[0]: dict(zip(rows[0], row)) for row in rows[1:]}

    def test_training_from_a_draft_to_what_everybody_has_done(self):
        new, count = "/d/%s/training/new" % self.slug, "select count(*) from trainings where domain_id = %d" % self.did
        # students do not write trainings
        self.assertEqual(self.pupil.get(new).status_code, 403)
        self.pupil.form(new, "save", title="x", description_md="", status="published")
        self.tutor.form(new, "save", title="x", description_md="", status="published")
        self.assertEqual(db_value(count), "0")
        self.assertNotEqual(self.teacher.form(new, "save", title=" ", description_md="", status="draft"), "")
        self.assertEqual(self.teacher.form(new, "save", title="p4 第一章 线性表", description_md="先做**必做题**。", status="draft"), "")
        training_id = int(db_value("select max(id) from trainings where domain_id = %d" % self.did))
        here = "/d/%s/training/%d" % (self.slug, training_id)
        manage, trainings = here + "/manage", "/d/%s/trainings" % self.slug

        # its problems are problems of the domain, named by the numbers they have there
        self.teacher.new_domain("p4-trainings-other")
        self.assertEqual(self.teacher.form("/d/p4-trainings-other/problems", "new"), "")
        foreign_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % domain_id("p4-trainings-other")))
        own, copy = uoj.pid(self.own_id), uoj.pid(self.copy_id)
        problems = db_value("select count(*) from problems")
        self.assertEqual(self.teacher.form(manage, "add_problem", problem_id=str(own)), "")
        self.assertEqual(self.teacher.form(manage, "add_problem", problem_id=str(copy), optional="on"), "")
        # not twice, and neither a problem of the site nor an id nor a problem of another domain
        for refused in (own, self.public_id, self.own_id, foreign_id, 99999999):
            self.assertNotEqual(self.teacher.form(manage, "add_problem", problem_id=str(refused)), "", refused)
        self.assertNotEqual(self.pupil.form(manage, "add_problem", problem_id=str(copy)), "")
        order = lambda: db("select problem_id, required from training_problems where training_id = %d order by position" % training_id)
        self.assertEqual(order(), [[str(self.own_id), "1"], [str(self.copy_id), "0"]])
        self.assertEqual(db_value("select count(*) from problems"), problems)
        self.assertEqual(self.teacher.form(manage, "move_problem", problem_id=str(self.copy_id)), "")
        self.assertEqual(order(), [[str(self.copy_id), "0"], [str(self.own_id), "1"]])
        self.assertEqual(self.teacher.form(manage, "update_problem", problem_id=str(self.copy_id)), "")
        self.assertEqual(self.teacher.form(manage, "update_problem", problem_id=str(self.copy_id), optional="on"), "")
        self.assertEqual(self.teacher.form(manage, "remove_problem", problem_id=str(self.own_id)), "")
        self.assertEqual(self.teacher.form(manage, "add_problem", problem_id=str(own)), "")
        self.assertEqual(order(), [[str(self.copy_id), "0"], [str(self.own_id), "1"]])

        # a draft is for the people who teach
        self.assertEqual(self.teacher.get(here).status_code, 200)
        for client in (self.pupil, self.tutor, self.stranger):
            self.assertEqual(client.get(here).status_code, 404)
        self.assertNotIn("p4 第一章", self.pupil.get(trainings).text)
        self.assertIn("p4 第一章", self.teacher.get(trainings).text)
        self.assertEqual(self.teacher.form(manage, "save", title="p4 第一章 线性表", description_md="先做**必做题**。", status="published"), "")
        page = self.pupil.get(here)
        self.assertEqual(page.status_code, 200)
        self.assertIn("<strong>必做题</strong>", page.text)
        for number in (own, copy):
            self.assertIn('href="/d/%s/problem/%d"' % (self.slug, number), page.text)
        self.assertNotIn('href="/problem/', page.text)
        self.assertIn("p4 第一章", self.pupil.get(trainings).text)
        self.assertEqual(self.stranger.get(here).status_code, 404)
        self.assertEqual(self.stranger.get(trainings).status_code, 404)
        self.assertEqual(self.pupil.get(manage).status_code, 403)

        # what counts is the best score on a problem, wherever it was submitted
        own_page, copy_page = ("/d/%s/problem/%d" % (self.slug, number) for number in (own, copy))
        self.assertEqual(uoj.wait_submission(self.pupil.submit(self.copy_id, AB, path=copy_page)).score, 100)
        self.assertEqual(uoj.wait_submission(self.pupil.submit(self.own_id, AB_WRONG, path=own_page)).score, 0)
        self.assertIn("已通过 <strong>1</strong> / 2 题", self.pupil.get(here).text)
        self.assertIn("已通过 <strong>0</strong> / 2 题", self.other.get(here).text)
        self.assertNotIn("已完成", self.pupil.get(here).text)

        # the people who look after the domain see what every student has done
        self.assertIn('id="table-training-progress"', self.tutor.get(here + "?view=progress").text)
        self.assertNotIn('id="table-training-progress"', self.pupil.get(here + "?view=progress").text)
        self.assertIsNone(self.progress(self.pupil, training_id))
        public, mine = "#%d" % copy, "#%d" % own
        rows = self.progress(self.tutor, training_id)
        self.assertEqual(sorted(rows), ["40417", "p4_tr_other", "p4_tr_pupil"])
        # a username that is a number is a user like any other in the table
        matrix = self.tutor.get(here + "?view=progress").text
        for name in ("40417", "p4_tr_pupil"):
            self.assertRegex(matrix, r'<span class="uoj-username"[^>]*>%s</span>' % name)
        self.assertEqual([rows["p4_tr_pupil"][key] for key in (public, mine, "solved", "done")], ["100", "0", "1", "no"])
        self.assertEqual([rows["p4_tr_other"][key] for key in (public, mine, "solved", "done")], ["", "", "0", "no"])

        # the problem that has to be solved is solved: the training is done
        self.assertEqual(uoj.wait_submission(self.pupil.submit(self.own_id, AB, path=own_page)).score, 100)
        self.assertIn("已完成", self.pupil.get(here).text)
        self.assertIn("已完成", self.pupil.get(trainings).text)
        self.assertEqual(self.progress(self.teacher, training_id)["p4_tr_pupil"]["done"], "yes")

        # a training that is thrown away takes nothing with it
        self.assertNotEqual(self.pupil.form(manage, "delete"), "")
        self.assertEqual(self.teacher.form(manage, "delete"), "")
        self.assertEqual(db_value("select count(*) from trainings where id = %d" % training_id), "0")
        self.assertEqual(db_value("select count(*) from training_problems where training_id = %d" % training_id), "0")
        self.assertEqual(self.teacher.get(here).status_code, 404)
        self.assertEqual(db_value("select count(*) from submissions where submitter = 'p4_tr_pupil'"), "3")
