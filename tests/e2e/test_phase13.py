"""Phase 13: passwords kept as Argon2id, links to reset a password that are used once and expire,
logins that end when the password changes, logins that are slowed down after failures, and the
judgers that say which claim on a task they report about."""

import base64
import hashlib
import re
import unittest

import requests

import mock_smtp
import uoj
from uoj import db, db_value, docker_exec


def who(client):
    """the user a client is logged in as, None for a visitor"""
    found = re.search(r'data-link="0"[^>]*>([^<]*)</span>', client.get("/").text)
    return found.group(1) if found else None


def browser_with(cookies):
    """another browser that presents these cookies"""
    other = uoj.Client.__new__(uoj.Client)
    other.http = requests.Session()
    other.http.cookies.update(cookies)
    return other


def stored_password(username):
    return db("select password, password_legacy, ifnull(password_salt, '') from user_info where username = '%s'" % username)[0]


# ---------------------------------------------------------------------- passwords


class PasswordStorageTest(unittest.TestCase):
    """a password is kept as Argon2id; one kept as md5 before lets its user in and is kept anew"""

    def test_password_is_kept_as_argon2id(self):
        client = uoj.Client()
        client.register("p13_argon", "p13-password")
        password, legacy, salt = stored_password("p13_argon")
        self.assertTrue(password.startswith("$argon2id$v=19$m=19456,t=2,p=1$"), password)
        self.assertEqual((legacy, salt), ("0", ""))
        # nothing of what was typed or sent is in it
        self.assertNotIn(client.password_hash("p13-password"), password)
        client.login("p13_argon", "p13-password")
        with self.assertRaises(Exception):
            uoj.Client().login("p13_argon", "p13-passwordx")

    def test_md5_of_before_is_wrapped_by_the_upgrade_and_kept_anew_at_the_first_login(self):
        client = uoj.Client()
        client.register("p13_md5", "p13-old-password")
        sent = client.password_hash("p13-old-password")
        # the password as the site kept it before phase 13: md5 of the username and what was sent
        db("update user_info set password = md5(concat('p13_md5', '%s')), password_legacy = 0 where username = 'p13_md5'" % sent)
        self.assertRegex(stored_password("p13_md5")[0], r"^[0-9a-f]{32}$")
        # the upgrade wraps every md5 there is into Argon2id as it is
        out = docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php upgrade:refresh 1029_password_hashes")
        self.assertIn("password(s) wrapped into Argon2id", out)
        password, legacy, _ = stored_password("p13_md5")
        self.assertTrue(password.startswith("$argon2id$"), password)
        self.assertEqual(legacy, "1")
        # its user logs in with the password of before, which is kept the new way from then on
        with self.assertRaises(Exception):
            uoj.Client().login("p13_md5", "p13-wrong-password")
        self.assertEqual(stored_password("p13_md5")[1], "1")
        uoj.Client().login("p13_md5", "p13-old-password")
        password, legacy, _ = stored_password("p13_md5")
        self.assertTrue(password.startswith("$argon2id$"), password)
        self.assertEqual(legacy, "0")
        uoj.Client().login("p13_md5", "p13-old-password")

    def test_password_of_before_keeps_the_name_it_was_hashed_with_through_a_rename(self):
        client = uoj.Client()
        client.register("p13_was", "p13-renamed-password")
        sent = client.password_hash("p13-renamed-password")
        db("update user_info set password = md5(concat('p13_was', '%s')), password_legacy = 0 where username = 'p13_was'" % sent)
        # a system administrator renames the user, who has not logged in since (their own form
        # would check the password, and keep it the new way first)
        err = uoj.admin().submit_form("/super-manage/users", "rename", {"rename_username": "p13_was", "rename_new_username": "p13_now"})
        self.assertEqual(err, "")
        self.assertEqual(stored_password("p13_now")[2], "p13_was")
        uoj.Client().login("p13_now", "p13-renamed-password")
        self.assertEqual(stored_password("p13_now")[1:], ["0", ""])


class RememberedLoginTest(unittest.TestCase):
    """a device that remembers a login has a token of its own, of which the site keeps the hash"""

    def test_each_device_has_a_token_and_logging_out_forgets_that_one(self):
        first, second = uoj.Client(), uoj.Client()
        first.register("p13_devices", "p13-devices-password")
        first.login("p13_devices", "p13-devices-password")
        second.login("p13_devices", "p13-devices-password")
        tokens = [c.http.cookies.get("uoj_remember_token") for c in (first, second)]
        self.assertNotEqual(tokens[0], tokens[1])
        kept = sorted(row[0] for row in db("select token_hash from user_remember_tokens where username = 'p13_devices'"))
        self.assertEqual(kept, sorted(hashlib.sha256(t.encode()).hexdigest() for t in tokens))
        self.assertEqual(db_value("select count(*) from user_remember_tokens where token_hash in ('%s', '%s')" % tuple(tokens)), "0")
        # a browser that has only the remembered login of the second device is logged in
        remembered = {k: v for k, v in second.http.cookies.get_dict().items() if k != "UOJSESSID"}
        self.assertEqual(who(browser_with(remembered)), "p13_devices")
        # the first device logs out: the second stays logged in, and so does what it remembers
        first.get("/logout?_token=" + first.token)
        self.assertIsNone(who(first))
        self.assertEqual(who(second), "p13_devices")
        self.assertEqual(who(browser_with(remembered)), "p13_devices")
        self.assertEqual(db_value("select count(*) from user_remember_tokens where username = 'p13_devices'"), "1")


# ---------------------------------------------------------------------- links to reset a password


SENT = 'id="forgot-password-sent"'
INVALID = 'id="reset-link-invalid"'


def answer(page):
    """what the page that asks for a link answers, without the rest of the page"""
    return re.search(r'id="forgot-password-sent">(.*?)</div>', page, re.S).group(1)


class PasswordResetTest(unittest.TestCase):
    """a link to reset a password works for half an hour, once, while it is the newest one"""

    @classmethod
    def setUpClass(cls):
        uoj.admin()
        cls.smtp = mock_smtp.MockSMTP()
        cls.smtp.start()
        # the site sends from the mock mailbox
        for name, value in (("mail.host", mock_smtp.HOST), ("mail.port", str(mock_smtp.PORT)), ("mail.secure", "none"),
                            ("mail.username", "oj@example.edu.cn"), ("mail.password", "p13-mail"), ("mail.from_name", "OJ")):  # fmt: skip
            db("replace into site_settings (name, value, updated_by, updated_at) values ('%s', '%s', 'e2e_admin', now())" % (name, value))

    @classmethod
    def tearDownClass(cls):
        cls.smtp.stop()
        db("delete from site_settings where name like 'mail.%'")

    def ask(self, username):
        """ask for a link, return the page and the token of the mail that came, or None"""
        before = len(self.smtp.messages)
        r = uoj.Client().post("/forgot-password", {"submit-forgot": "forgot", "username": username})
        page = r.text if r.status_code == 200 else uoj.Client().get(r.headers.get("Location", "/")).text
        if len(self.smtp.messages) == before:
            return page, None
        self.assertEqual(len(self.smtp.messages), before + 1)
        found = re.search(r"/reset-password\?p=([A-Za-z0-9_-]{43})", self.smtp.messages[-1].text)
        self.assertIsNotNone(found, self.smtp.messages[-1].text)
        return page, found.group(1)

    def reset(self, token, password):
        client = uoj.Client()
        r = client.post("/reset-password?p=" + token, {"reset": "", "newPW": client.password_hash(password)})
        return r.text

    def test_whoever_asks_is_told_the_same(self):
        uoj.client(("p13_asker", "p13-asker-password"))
        known, token = self.ask("p13_asker")
        self.assertIsNotNone(token)
        unknown, none = self.ask("p13_nobody_is_called_so")
        self.assertIsNone(none)
        self.assertEqual(answer(known), answer(unknown))
        # who asks again within a minute is told the same, and is sent nothing
        again, nothing = self.ask("p13_asker")
        self.assertIsNone(nothing)
        self.assertEqual(answer(again), answer(known))

    def test_link_works_once(self):
        logged_in = uoj.Client()
        logged_in.register("p13_resetter", "p13-before-password")
        logged_in.login("p13_resetter", "p13-before-password")
        _, token = self.ask("p13_resetter")
        # only the hash of the token is kept
        self.assertEqual(db_value("select count(*) from password_resets where token_hash = '%s'" % hashlib.sha256(token.encode()).hexdigest()), "1")
        self.assertEqual(db_value("select count(*) from password_resets where token_hash = '%s'" % token), "0")
        self.assertNotIn(INVALID, uoj.Client().get("/reset-password?p=" + token).text)
        self.assertEqual(self.reset(token, "p13-after-password"), "ok")
        # whoever was logged in with the password of before is not any more
        self.assertIsNone(who(logged_in))
        self.assertEqual(db_value("select count(*) from user_remember_tokens where username = 'p13_resetter'"), "0")
        uoj.Client().login("p13_resetter", "p13-after-password")
        with self.assertRaises(Exception):
            uoj.Client().login("p13_resetter", "p13-before-password")
        # once
        self.assertIn(INVALID, uoj.Client().get("/reset-password?p=" + token).text)
        self.assertIn("链接无效或已过期", self.reset(token, "p13-third-password"))
        uoj.Client().login("p13_resetter", "p13-after-password")
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'user.password_reset' and resource_id = 'p13_resetter'"), "1")

    def test_newer_link_ends_the_one_before_and_a_link_ends_in_half_an_hour(self):
        uoj.client(("p13_twice", "p13-twice-password"))
        _, first = self.ask("p13_twice")
        # a minute later (the link of a minute ago is moved back in time)
        db("update password_resets set created_at = created_at - interval 2 minute where username = 'p13_twice'")
        _, second = self.ask("p13_twice")
        self.assertIsNotNone(second)
        self.assertIn(INVALID, uoj.Client().get("/reset-password?p=" + first).text)
        self.assertIn("链接无效或已过期", self.reset(first, "p13-first-password"))
        self.assertNotIn(INVALID, uoj.Client().get("/reset-password?p=" + second).text)
        # half an hour passes
        self.assertEqual(db_value("select timestampdiff(minute, created_at, expires_at) from password_resets where token_hash = '%s'" % hashlib.sha256(second.encode()).hexdigest()), "30")
        db("update password_resets set expires_at = now() - interval 1 second where username = 'p13_twice'")
        self.assertIn(INVALID, uoj.Client().get("/reset-password?p=" + second).text)
        self.assertIn("链接无效或已过期", self.reset(second, "p13-second-password"))
        uoj.Client().login("p13_twice", "p13-twice-password")

    def test_link_of_the_old_kind_does_not_work(self):
        uoj.client(("p13_oldlink", "p13-oldlink-password"))
        stored = db_value("select password from user_info where username = 'p13_oldlink'")
        old = base64.urlsafe_b64encode(("p13_oldlink." + hashlib.md5(("p13_oldlink+" + stored).encode()).hexdigest()).encode()).decode().rstrip("=")
        self.assertIn(INVALID, uoj.Client().get("/reset-password?p=" + old).text)
        self.assertIn("链接无效或已过期", self.reset(old, "p13-stolen-password"))
        uoj.Client().login("p13_oldlink", "p13-oldlink-password")


# ---------------------------------------------------------------------- logins that end


class LoginsEndTest(unittest.TestCase):
    """a new password ends the logins of the old one, but the one that set it"""

    def test_changing_the_password_ends_the_logins_of_the_other_devices(self):
        here, there = uoj.Client(), uoj.Client()
        here.register("p13_changer", "p13-changer-password")
        here.login("p13_changer", "p13-changer-password")
        there.login("p13_changer", "p13-changer-password")
        remembered = {k: v for k, v in there.http.cookies.get_dict().items() if k != "UOJSESSID"}
        self.assertEqual(who(browser_with(remembered)), "p13_changer")
        version = int(db_value("select auth_version from user_info where username = 'p13_changer'"))
        self.assertEqual(here.update_profile(ptag="1", password=here.password_hash("p13-changed-password")), "ok")
        self.assertEqual(int(db_value("select auth_version from user_info where username = 'p13_changer'")), version + 1)
        # here stays logged in, and remembers the login anew
        self.assertEqual(who(here), "p13_changer")
        self.assertEqual(db_value("select count(*) from user_remember_tokens where username = 'p13_changer'"), "1")
        here_remembered = {k: v for k, v in here.http.cookies.get_dict().items() if k != "UOJSESSID"}
        self.assertEqual(who(browser_with(here_remembered)), "p13_changer")
        # there, and what there remembers, are logged out
        self.assertIsNone(who(there))
        self.assertIsNone(who(browser_with(remembered)))
        uoj.Client().login("p13_changer", "p13-changed-password")

    def test_a_banned_user_stays_logged_out_after_the_ban_is_lifted(self):
        victim = uoj.Client()
        victim.register("p13_banned", "p13-banned-password")
        victim.login("p13_banned", "p13-banned-password")
        admin = uoj.admin()
        self.assertEqual(admin.change_user("p13_banned", "banneduser"), "")
        self.assertEqual(admin.change_user("p13_banned", "normaluser"), "")
        self.assertIsNone(who(victim))
        uoj.Client().login("p13_banned", "p13-banned-password")


# ---------------------------------------------------------------------- passwords that are guessed


def try_login(username, password):
    """what the site answers a login with this password"""
    client = uoj.Client()
    r = client.http.post(uoj.BASE_URL + "/login", data={
        "_token": client.token, "login": "", "username": username, "password": client.password_hash(password),
    })  # fmt: skip
    return r.text


class GuessingTest(unittest.TestCase):
    """past so many wrong passwords in a quarter of an hour, no password is looked at for the rest of it"""

    def test_login_waits_after_five_wrong_passwords_of_a_name_from_an_address(self):
        uoj.Client().register("p13_guessed", "p13-guessed-password")
        # a right password in between starts the count of this name from this address afresh
        for _ in range(3):
            self.assertEqual(try_login("p13_guessed", "wrong"), "failed")
        self.assertEqual(try_login("p13_guessed", "p13-guessed-password"), "ok")
        for _ in range(5):
            self.assertEqual(try_login("p13_guessed", "wrong"), "failed")
        # the sixth is not even looked at, right or wrong
        self.assertRegex(try_login("p13_guessed", "p13-guessed-password"), r"^throttled 1[45]$")
        self.assertRegex(try_login("p13_guessed", "wrong"), r"^throttled \d+$")
        # another name from the same address is not held up
        uoj.Client().register("p13_bystander", "p13-bystander-password")
        self.assertEqual(try_login("p13_bystander", "p13-bystander-password"), "ok")
        # the quarter of an hour passes
        db("update auth_failures set window_start = window_start - interval 16 minute where scope like 'login:%%p13_guessed%%'")
        self.assertEqual(try_login("p13_guessed", "p13-guessed-password"), "ok")

    def test_old_password_on_the_profile_counts_as_well(self):
        client = uoj.client(("p13_profile", "p13-profile-password"))
        for _ in range(5):
            self.assertIn("密码错误", client.update_profile(old_password=client.password_hash("wrong")))
        self.assertIn("尝试次数过多", client.update_profile())
        self.assertRegex(try_login("p13_profile", "p13-profile-password"), r"^throttled \d+$")
        db("delete from auth_failures where scope like 'login:%%p13_profile%%'")
        self.assertEqual(client.update_profile(), "ok")

    def test_password_of_a_contest_is_guessed_twenty_times_whatever_the_session(self):
        admin = uoj.admin()
        contest_id = admin.new_contest("p13 猜密码")
        self.assertEqual(admin.contest_settings(contest_id, join_mode="password", join_password="open sesame"), "")
        register = "/contest/%d/register" % contest_id
        guesser = uoj.Client()
        guesser.register("p13_contest_guesser", "p13-guesser-password")
        guesser.login("p13_contest_guesser", "p13-guesser-password")
        for n in range(20):
            self.assertIn("参赛密码不正确", guesser.submit_form(register, "register", {"join_password": "guess %d" % n}))
        # a new login is a new session, and does not start the count afresh
        again = uoj.Client()
        again.login("p13_contest_guesser", "p13-guesser-password")
        self.assertIn("尝试次数过多", again.submit_form(register, "register", {"join_password": "open sesame"}))
        self.assertEqual(db_value("select count(*) from contests_registrants where contest_id = %d" % contest_id), "0")
