"""End-to-end tests for phase 4: domains, the spaces of classes, courses and teams.

See test_phase1.py for how to start the containers.
"""

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
        self.student.form("/domain/new", "create", name="x", slug="p4-not-allowed", description="", type="course",
                          visibility="private", join_method="none")  # fmt: skip
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

    def test_settings_are_checked(self):
        self.teacher.new_domain("p4-settings")
        base = dict(name="x", description="", type="course", visibility="private", join_method="none")
        for wrong in (dict(slug="P4-UPPER"), dict(slug="p4_under"), dict(slug="p4-"), dict(slug="p4-settings"),
                      dict(slug="p4-ok", name=""), dict(slug="p4-ok", join_method="all"), dict(slug="p4-ok", type="x")):  # fmt: skip
            self.assertNotEqual(self.teacher.form("/domain/new", "create", **dict(base, **wrong)), "", wrong)
        self.assertEqual(db_value("select count(*) from domains where slug in ('p4-ok', 'p4-', 'p4_under')"), "0")

        settings = "/d/p4-settings/settings"
        self.assertIn("私有", self.teacher.form(settings, "settings", **dict(base, join_method="all")))
        self.assertEqual(self.teacher.form(settings, "settings", **dict(base, name="新名字", visibility="public", join_method="all")), "")
        self.assertEqual(db("select name, visibility, join_method, slug from domains where slug = 'p4-settings'"),
                         [["新名字", "public", "all", "p4-settings"]])  # fmt: skip

    def test_strangers_learn_nothing_about_a_private_domain(self):
        self.teacher.new_domain("p4-private")
        visitor = uoj.Client()
        for path in ("", "/members", "/settings"):
            # the same answers as for a domain that does not exist
            self.assertEqual(self.student.get("/d/p4-private" + path).status_code, 404, path)
            self.assertEqual(self.student.get("/d/p4-no-such-domain" + path).status_code, 404, path)
            self.assertEqual(visitor.get("/d/p4-private" + path).status_code, 302, path)
            self.assertEqual(visitor.get("/d/p4-no-such-domain" + path).status_code, 302, path)
        self.assertNotIn("p4-private", self.student.get("/domains").text)
        self.assertNotIn("p4-private", visitor.get("/domains").text)
        self.assertIn("p4-private", self.teacher.get("/domains").text)
        self.student.form("/d/p4-private", "join")
        self.assertIsNone(role_in("p4-private", "p4_student"))

    def test_everybody_may_look_at_a_public_domain_and_join_if_it_says_so(self):
        self.teacher.new_domain("p4-public", visibility="public", join_method="all", description="欢迎来到公开的域")
        self.teacher.new_domain("p4-unlisted", visibility="unlisted", join_method="none")
        visitor, joiner = uoj.Client(), account("p4_joiner")
        self.assertIn("p4-public", visitor.get("/domains").text)
        self.assertNotIn("p4-unlisted", visitor.get("/domains").text)
        for client in (visitor, joiner):
            page = client.get("/d/p4-public")
            self.assertEqual(page.status_code, 200)
            self.assertIn("欢迎来到公开的域", page.text)
            self.assertEqual(client.get("/d/p4-unlisted").status_code, 200)
        # what is inside is for members
        self.assertEqual(joiner.get("/d/p4-public/members").status_code, 404)
        self.assertEqual(visitor.get("/d/p4-public/members").status_code, 302)

        self.assertIn('id="button-join-domain"', joiner.get("/d/p4-public").text)
        self.assertEqual(joiner.form("/d/p4-public", "join"), "")
        self.assertEqual(role_in("p4-public", "p4_joiner"), "member")
        self.assertEqual(joiner.get("/d/p4-public/members").status_code, 200)
        self.assertIn("p4-public", joiner.get("/domains").text)
        # nobody joins a domain that does not say so
        self.assertNotIn('id="button-join-domain"', joiner.get("/d/p4-unlisted").text)
        joiner.form("/d/p4-unlisted", "join")
        self.assertIsNone(role_in("p4-unlisted", "p4_joiner"))
        # whoever came by themselves may leave by themselves
        self.assertEqual(joiner.form("/d/p4-public/members", "leave"), "")
        self.assertIsNone(role_in("p4-public", "p4_joiner"))

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
        self.teacher.new_domain("p4-archived", visibility="public", join_method="all")
        pupil = account("p4_archived_pupil")
        self.assertEqual(member_form(self.teacher, "p4-archived", "add", username="p4_archived_pupil", role="member"), "")
        self.assertEqual(self.teacher.form("/d/p4-archived/settings", "archive"), "")
        self.assertEqual(db_value("select archived_at is not null from domains where slug = 'p4-archived'"), "1")

        self.assertNotIn("p4-archived", uoj.Client().get("/domains").text)
        self.assertEqual(pupil.get("/d/p4-archived").status_code, 200)
        self.assertIn("已归档", pupil.get("/d/p4-archived").text)
        account("p4_archived_late")
        self.assertNotEqual(member_form(self.teacher, "p4-archived", "add", username="p4_archived_late", role="member"), "")
        account("p4_archived_late").form("/d/p4-archived", "join")
        self.assertIsNone(role_in("p4-archived", "p4_archived_late"))
        base = dict(name="x", description="", type="course", visibility="public", join_method="all")
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


class DomainJoinTest(unittest.TestCase):
    """invitations and rosters"""

    @classmethod
    def setUpClass(cls):
        cls.teacher = account("p4_join_teacher")
        assert uoj.admin().change_user("p4_join_teacher", "grant:teacher") == ""

    def test_invitation_is_a_secret_that_is_kept_only_as_a_hash(self):
        self.teacher.new_domain("p4-invite", join_method="code")
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
        self.teacher.new_domain("p4-invite-ends", join_method="code")
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

        # an invitation is worth nothing in a domain that lets nobody in
        still_good = new_invite(self.teacher, "p4-invite-ends", label="closed")
        db("update domains set join_method = 'none' where id = %d" % did)
        self.assertIn("无效或已过期", redeem(clients[5], still_good))
        db("update domains set join_method = 'code' where id = %d" % did)
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
        self.teacher.new_domain("p4-invite-race", join_method="code")
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
        self.teacher.new_domain("p4-invite-member", join_method="code")
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
        self.teacher.new_domain("p4-invite-quiet", join_method="code")
        stranger = account("p4_quiet")
        expired = new_invite(self.teacher, "p4-invite-quiet")
        db("update domain_invites set expires_at = now() - interval 1 minute where domain_id = %d" % domain_id("p4-invite-quiet"))

        def refusal(token):
            page = stranger.post("/domains/join", {"form": "redeem", "token": token})
            self.assertEqual(page.status_code, 200)
            return re.search(r'class="alert alert-danger"[^>]*>([^<]*)<', page.text).group(1)

        # an invitation that ended, one that never was, and nonsense are refused in the same words
        answers = {refusal(token) for token in (expired, "A" * 24, "p4-invite-quiet", "", "x" * 64)}
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

    def test_roster_can_not_hand_out_what_its_importer_can_not(self):
        self.teacher.new_domain("p4-roster-roles")
        co = account("p4_roster_co")
        account("p4_roster_target")
        self.assertEqual(member_form(self.teacher, "p4-roster-roles", "add", username="p4_roster_co", role="admin"), "")
        self.assertNotEqual(member_form(co, "p4-roster-roles", "import", roster="p4_roster_target", role="admin"), "")
        self.assertIsNone(role_in("p4-roster-roles", "p4_roster_target"))
        self.assertEqual(member_form(co, "p4-roster-roles", "import", roster="p4_roster_target", role="ta"), "")
        self.assertEqual(role_in("p4-roster-roles", "p4_roster_target"), "ta")


if __name__ == "__main__":
    unittest.main()
