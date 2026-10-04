"""End-to-end tests for phase 5: what keeps the site running.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import mock_smtp
import test_phase3 as p3
import uoj
from fixtures import *
from uoj import db, db_value

account = p3.account
SETTINGS = "/super-manage/settings"


def setUpModule():
    uoj.wait_idle()


def site_settings(client, **settings):
    """post the settings of the site: mail_host="x" for the setting mail.host"""
    return client.form(SETTINGS, "site_settings", **{"setting[%s]" % name.replace("_", ".", 1): value for name, value in settings.items()})


def stored(name):
    return db_value("select concat('[', value, ']') from site_settings where name = '%s'" % name)


class MailTest(unittest.TestCase):
    """the mailbox the site sends from is set on the site"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.smtp = mock_smtp.MockSMTP()
        cls.smtp.start()

    @classmethod
    def tearDownClass(cls):
        cls.smtp.stop()
        # the site goes back to the mailbox of its configuration, which is none
        db("delete from site_settings where name like 'mail.%'")

    def test_mailbox_is_set_on_the_site_and_tried_out(self):
        oj_admin = account("p5_mail_ojadmin")
        self.assertEqual(self.admin.change_user("p5_mail_ojadmin", "grant:oj_admin"), "")
        mailbox = dict(mail_host=mock_smtp.HOST, mail_port=str(mock_smtp.PORT), mail_secure="none",
                       mail_username="oj@example.edu.cn", mail_password="p5-mail-secret", mail_from_name="校园 OJ")  # fmt: skip

        # the settings are of the system administrators alone
        self.assertNotEqual(site_settings(oj_admin, **mailbox), "")
        self.assertNotEqual(oj_admin.form(SETTINGS, "test_mail", to="p5@example.edu.cn"), "")
        self.assertEqual(db_value("select count(*) from site_settings where name like 'mail.%'"), "0")
        self.assertIn("还没有设置发信邮箱", self.admin.get(SETTINGS).text)

        # what makes no sense is refused, and nothing of the form is kept
        for wrong in (dict(mail_port="0"), dict(mail_port="70000"), dict(mail_port="25x"), dict(mail_secure="rot13"),
                      dict(mail_host="smtp.example\nBcc: x"), dict(mail_username="x" * 201)):  # fmt: skip
            self.assertNotEqual(site_settings(self.admin, **dict(mailbox, **wrong)), "", wrong)
        self.assertEqual(db_value("select count(*) from site_settings where name like 'mail.%'"), "0")

        self.assertEqual(site_settings(self.admin, **mailbox), "")
        self.assertEqual(stored("mail.host"), "[%s]" % mock_smtp.HOST)
        page = self.admin.get(SETTINGS).text
        self.assertIn("现在用的是这里设置的邮箱", page)
        self.assertIn('value="oj@example.edu.cn"', page)
        # a password is never shown again, and never written down where changes are
        self.assertNotIn("p5-mail-secret", page)
        self.assertIn("已设置，留空表示不修改", page)
        self.assertEqual(db_value("select count(*) from audit_logs where after_json like '%p5-mail-secret%' or before_json like '%p5-mail-secret%'"), "0")
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'site.edit_setting' and resource_id = 'mail.password'"), "1")
        # left empty, it stays what it is
        self.assertEqual(site_settings(self.admin, **dict(mailbox, mail_password="", mail_from_name="学校 OJ")), "")
        self.assertEqual(stored("mail.password"), "[p5-mail-secret]")
        self.assertEqual(stored("mail.from_name"), "[学校 OJ]")

        # a mail to try it out
        for wrong in ("not an address", "a@b.c\nBcc: x@y.z", ""):
            self.assertNotEqual(self.admin.form(SETTINGS, "test_mail", to=wrong), "", wrong)
        self.assertEqual(self.smtp.messages, [])
        self.assertEqual(self.admin.form(SETTINGS, "test_mail", to="p5@example.edu.cn"), "")
        self.assertEqual(len(self.smtp.messages), 1)
        mail = self.smtp.messages[0]
        self.assertEqual((mail.user, mail.password), ("oj@example.edu.cn", "p5-mail-secret"))
        self.assertEqual((mail.sender, mail.recipients), ("oj@example.edu.cn", ["p5@example.edu.cn"]))
        self.assertIn("测试邮件", mail.subject)
        self.assertIn("发信邮箱设置正确", mail.text)
        self.assertIn("学校 OJ", str(email_header(mail.parsed["From"])))

        # a mailbox that turns the site away says why
        self.smtp.password = "another password"
        refusal = self.admin.form(SETTINGS, "test_mail", to="p5@example.edu.cn")
        self.assertIn("发送失败", refusal)
        self.assertEqual(len(self.smtp.messages), 1)
        self.smtp.password = None

        # the mail that lets a user set a new password comes from the same mailbox
        account("p5_mail_user")
        uoj.Client().submit_form("/forgot-password", "forgot", {"username": "p5_mail_user"})
        self.assertEqual(len(self.smtp.messages), 2)
        mail = self.smtp.messages[1]
        self.assertEqual((mail.sender, mail.recipients), ("oj@example.edu.cn", ["p5_mail_user@example.com"]))
        self.assertIn("/reset-password", mail.text)


def email_header(value):
    import email.header

    return email.header.make_header(email.header.decode_header(value))
