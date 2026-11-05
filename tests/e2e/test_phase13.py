"""Phase 13: passwords kept as Argon2id, links to reset a password that are used once and expire,
logins that end when the password changes, logins that are slowed down after failures, and the
judgers that say which claim on a task they report about."""

import hashlib
import re
import unittest

import requests

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
