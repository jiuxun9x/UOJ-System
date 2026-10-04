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


def site_tick():
    """what the web server does every minute"""
    return uoj.docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php site:tick")


def open_alerts():
    return sorted(row[0] for row in db("select kind from site_alerts where active_slot = 1"))


class MonitorTest(unittest.TestCase):
    """the administrators are told when the judgers are gone, and when they are back"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.smtp = mock_smtp.MockSMTP()
        cls.smtp.start()
        err = site_settings(
            cls.admin,
            mail_host=mock_smtp.HOST, mail_port=str(mock_smtp.PORT), mail_secure="none",
            mail_username="oj@example.edu.cn", mail_password="p5-monitor-secret",
            alert_email="on", alert_recipients="ops@example.edu.cn, not an address",
            alert_judger_silent_seconds="30", alert_queue_wait_seconds="60",
        )  # fmt: skip
        assert err == "", err

    @classmethod
    def tearDownClass(cls):
        cls.smtp.stop()
        db("delete from site_settings where name like 'mail.%' or name like 'alert.%'")

    def test_judgers_that_are_gone_are_reported_and_so_is_their_return(self):
        monitor = "/super-manage/monitor"
        admin_name = uoj.ADMIN[0]
        told = lambda: int(db_value("select count(*) from user_system_msg where receiver = '%s' and (title like '告警：%%' or title like '已恢复：%%')" % admin_name))
        uoj.wait_idle()
        uoj.wait_until("nothing is wrong", lambda: site_tick() and open_alerts() == [], timeout=180)
        page = self.admin.get(monitor).text
        self.assertIn('id="monitor-ok"', page)
        self.assertNotIn('id="site-alerts-banner"', page)
        for name in uoj.JUDGER_NAMES:
            self.assertRegex(page, r'(?s)data-judger="%s">.*?badge-success">在线' % name)
        told_before, mails_before, alerts_before = told(), len(self.smtp.messages), int(db_value("select count(*) from site_alerts"))

        problem_id = self.admin.create_problem(ab_problem_files())
        with uoj.judgers_paused():
            # the judgers have not been heard of for ten minutes, and a submission waits for an hour
            db("update judger_info set last_heartbeat_at = now() - interval 10 minute")
            waiting = self.admin.submit(problem_id, AB)
            db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-3600), waiting))
            site_tick()
            self.assertEqual(open_alerts(), ["no_judger", "queue_stuck"])
            # looking again tells nothing twice
            for _ in range(3):
                site_tick()
            self.assertEqual(open_alerts(), ["no_judger", "queue_stuck"])
            self.assertEqual(int(db_value("select count(*) from site_alerts")), alerts_before + 2)

            # the administrators see it on every page, the others do not
            self.assertIn('id="site-alerts-banner"', self.admin.get("/").text)
            self.assertIn('id="site-alerts-banner"', self.admin.get("/problems").text)
            self.assertNotIn('id="site-alerts-banner"', account("p5_mon_user").get("/").text)
            self.assertNotIn('id="site-alerts-banner"', uoj.Client().get("/").text)
            page = self.admin.get(monitor).text
            self.assertIn('id="monitor-alerts"', page)
            self.assertIn("没有在线的评测机", page)
            self.assertIn('badge-danger">离线', page)
            # they get a message each time something goes wrong, and a mail where they asked for it
            self.assertEqual(told(), told_before + 2)
            mails = self.smtp.messages[mails_before:]
            self.assertEqual(len(mails), 2)
            self.assertEqual([mail.recipients for mail in mails], [["ops@example.edu.cn"]] * 2)
            self.assertTrue(all("告警" in mail.subject for mail in mails), [mail.subject for mail in mails])
            self.assertIn("没有在线的评测机", "".join(mail.text for mail in mails))

        # the judgers are back: the submission is judged, and the alerts close by themselves
        self.assertEqual(uoj.wait_submission(waiting).score, 100)
        uoj.wait_until("the alerts are over", lambda: site_tick() and open_alerts() == [], timeout=180)
        self.assertEqual(told(), told_before + 4)
        mails = self.smtp.messages[mails_before:]
        self.assertEqual(len(mails), 4)
        self.assertTrue(all("已恢复" in mail.subject for mail in mails[2:]), [mail.subject for mail in mails])
        page = self.admin.get(monitor).text
        self.assertIn('id="monitor-ok"', page)
        self.assertNotIn('id="site-alerts-banner"', page)
        closed = "select count(*) from site_alerts where resolved_at is not null and mailed_at is not null and started_at > now() - interval 10 minute"
        self.assertGreaterEqual(int(db_value(closed)), 2)

        # the state of the site is for its administrators
        oj_admin = account("p5_mon_ojadmin")
        self.assertEqual(self.admin.change_user("p5_mon_ojadmin", "grant:oj_admin"), "")
        self.assertEqual(oj_admin.get(monitor).status_code, 200)
        self.assertEqual(account("p5_mon_user").get(monitor).status_code, 403)

    def test_mail_is_not_sent_unless_asked_for(self):
        self.assertEqual(site_settings(self.admin, alert_recipients="ops@example.edu.cn"), "")
        try:
            mails_before = len(self.smtp.messages)
            uoj.wait_until("nothing is wrong", lambda: site_tick() and open_alerts() == [], timeout=180)
            with uoj.judgers_paused():
                db("update judger_info set last_heartbeat_at = now() - interval 10 minute")
                site_tick()
                self.assertEqual(open_alerts(), ["no_judger"])
            uoj.wait_until("the alert is over", lambda: site_tick() and open_alerts() == [], timeout=180)
            self.assertEqual(len(self.smtp.messages), mails_before)
        finally:
            self.assertEqual(site_settings(self.admin, alert_email="on", alert_recipients="ops@example.edu.cn, not an address"), "")


def email_header(value):
    import email.header

    return email.header.make_header(email.header.decode_header(value))
