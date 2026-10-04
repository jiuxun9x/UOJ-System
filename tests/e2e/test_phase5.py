"""End-to-end tests for phase 5: what keeps the site running.

See test_phase1.py for how to start the containers.
"""

import base64
import json
import re
import unittest
from urllib.parse import urlparse

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
    """post settings of the site: mail_host="x" for the setting mail.host, True and False for a switch"""
    fields = {}
    for name, value in settings.items():
        name = name.replace("_", ".", 1)
        if isinstance(value, bool):
            fields["present[%s]" % name] = "1"
            if value:
                fields["setting[%s]" % name] = "on"
        else:
            fields["setting[%s]" % name] = value
    return client.form(SETTINGS, "site_settings", **fields)


def stored(name):
    return db_value("select concat('[', value, ']') from site_settings where name = '%s'" % name)


def cli(command, check=True):
    """a command of cli.php in the container of the web server, with everything it prints"""
    return uoj.docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php %s 2>&1" % command, check=check)


def web_sh(script, check=True):
    return uoj.docker_exec(uoj.WEB, script, check=check).strip()


def new_backup():
    made = re.search(r"backup (uoj-\d{8}-\d{6}) is complete", cli("backup:run"))
    assert made, "no backup was made"
    return made.group(1)


BACKUPS = "/var/uoj_backup"


class BackupTest(unittest.TestCase):
    """the site makes backups of itself, and they can be put back"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        uoj.wait_idle()

    def test_backup_is_made_tried_out_and_put_back(self):
        problem_id = self.admin.create_problem(ab_problem_files())
        user = account("p5_backup_user")
        kept = user.submit(problem_id, AB + "// p5-backup-kept\n")
        self.assertEqual(uoj.wait_submission(kept).score, 100)
        uoj.wait_idle()

        name = new_backup()
        root = "%s/%s" % (BACKUPS, name)
        # it holds the database, the data of the problems and what was submitted, and says so
        self.assertEqual(web_sh("ls -A %s | sort | tr '\\n' ' '" % root), ".complete data db.sql.gz manifest.json storage")
        manifest = json.loads(web_sh("cat %s/manifest.json" % root))
        self.assertTrue(manifest["tables_exact"])
        self.assertEqual(manifest["tables"]["problems"], int(db_value("select count(*) from problems")))
        self.assertEqual(manifest["tables"]["user_info"], int(db_value("select count(*) from user_info")))
        self.assertGreater(manifest["sources"]["data"]["files"], 0)
        self.assertGreater(manifest["sources"]["storage"]["files"], 0)
        self.assertEqual(web_sh("test -d %s/data/%d && echo there" % (root, problem_id)), "there")
        self.assertEqual(web_sh("test -e %s/storage/tmp && echo there || echo left out" % root), "left out")
        self.assertEqual(web_sh("gunzip -c %s/db.sql.gz | grep -c 'p5_backup_user'" % root) != "0", True)
        self.assertEqual(db("select status, reason, files_count from backup_runs where name = '%s'" % name),
                         [["ok", "manual", str(manifest["files_count"])]])  # fmt: skip
        self.assertIn(name, cli("backup:list"))

        # the rehearsal of a restore: the backup is loaded into a database of its own and counted
        self.assertIn("can be restored", cli("backup:verify"))
        self.assertEqual(db_value("select verified_at is not null from backup_runs where name = '%s'" % name), "1")
        self.assertEqual(db_value("select count(*) from information_schema.schemata where schema_name = 'app_uoj233_verify'"), "0")
        self.assertIn("can not be restored", cli("backup:verify uoj-20200101-000000", check=False))
        self.assertIn("can not be restored", cli("backup:verify ../../etc", check=False))

        # things happen after the backup, and then the worst
        lost = user.submit(problem_id, AB + "// p5-backup-lost\n")
        self.assertEqual(uoj.wait_submission(lost).score, 100)
        account("p5_backup_late")
        with uoj.judgers_paused():
            web_sh("rm -rf /var/uoj_data/%d" % problem_id)
            db("delete from submissions where id = %d" % kept)
            db("update problems set title = 'p5 ruined' where id = %d" % problem_id)
            # it is not put back by accident
            self.assertIn("--yes", cli("backup:restore %s" % name, check=False))
            self.assertIn("没有这个备份", cli("backup:restore uoj-20200101-000000 --yes", check=False))
            self.assertEqual(db_value("select title from problems where id = %d" % problem_id), "p5 ruined")

            self.assertIn("is restored", cli("backup:restore %s --yes" % name))
            cli("upgrade:latest")
        # what was there when the backup was made is back, and what came later is gone
        self.assertNotEqual(db_value("select title from problems where id = %d" % problem_id), "p5 ruined")
        self.assertEqual(db_value("select count(*) from submissions where id = %d" % kept), "1")
        self.assertEqual(db_value("select count(*) from submissions where id = %d" % lost), "0")
        self.assertEqual(db_value("select count(*) from user_info where username = 'p5_backup_late'"), "0")
        self.assertEqual(web_sh("test -d /var/uoj_data/%d && echo there" % problem_id), "there")
        self.assertIn("p5-backup-kept", user.get("/submission/%d" % kept).text)
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'backup.restore' and resource_id = '%s'" % name), "1")
        # the site works on: the problem has its data and the judgers judge
        self.assertEqual(uoj.wait_submission(user.submit(problem_id, AB)).score, 100)

    def test_old_backups_go_and_unchanged_files_take_no_space_twice(self):
        # a backup from long ago, and what is left of one that was interrupted
        web_sh("mkdir -p %s/uoj-20200101-030000 %s/uoj-20200102-030000 && touch %s/uoj-20200101-030000/.complete" % (BACKUPS, BACKUPS, BACKUPS))
        first, second = new_backup(), new_backup()
        left = web_sh("ls %s" % BACKUPS).split()
        self.assertNotIn("uoj-20200101-030000", left)
        self.assertNotIn("uoj-20200102-030000", left)
        self.assertIn(first, left)
        self.assertIn(second, left)
        # a file that did not change between two backups is one file with two names
        links = web_sh("find %s/%s/data -type f | head -n 1 | xargs stat -c %%h" % (BACKUPS, second))
        self.assertGreaterEqual(int(links), 2)
        # two backups do not run at once
        self.assertEqual(db_value("select count(*) from backup_runs where status = 'running'"), "0")

    def test_failed_backup_is_an_alert_until_one_succeeds(self):
        uoj.wait_until("nothing is wrong", lambda: site_tick() and "backup_failed" not in open_alerts(), timeout=120)
        new_backup()
        db("insert into backup_runs (name, reason, status, started_at, finished_at, message)"
           " values ('uoj-20260101-030000', 'scheduled', 'failed', now(), now(), '磁盘已满')")  # fmt: skip
        site_tick()
        self.assertIn("backup_failed", open_alerts())
        page = self.admin.get("/super-manage/monitor").text
        self.assertIn("磁盘已满", page)
        self.assertIn('id="site-alerts-banner"', page)
        self.assertRegex(page, r'(?s)id="table-monitor-backups".*?data-status="failed"')
        new_backup()
        site_tick()
        self.assertNotIn("backup_failed", open_alerts())

    def test_backup_starts_by_itself_and_when_an_administrator_asks(self):
        monitor = "/super-manage/monitor"
        runs = lambda reason: int(db_value("select count(*) from backup_runs where reason = '%s' and status = 'ok'" % reason))
        idle = lambda: db_value("select count(*) from backup_runs where status in ('requested', 'running')") == "0"
        uoj.wait_until("no backup runs", idle, timeout=300)

        # on the page of the state of the site, for the system administrators
        oj_admin = account("p5_backup_ojadmin")
        self.assertEqual(self.admin.change_user("p5_backup_ojadmin", "grant:oj_admin"), "")
        self.assertNotIn('id="button-backup-now"', oj_admin.get(monitor).text)
        self.assertNotEqual(oj_admin.form(monitor, "backup"), "")
        self.assertTrue(idle())
        before = runs("manual")
        self.assertIn('id="button-backup-now"', self.admin.get(monitor).text)
        self.assertEqual(self.admin.form(monitor, "backup"), "")
        self.assertNotEqual(self.admin.form(monitor, "backup"), "")
        uoj.wait_until("the backup that was asked for is made", lambda: site_tick() and idle() and runs("manual") == before + 1, timeout=300)

        # every day at the hour that was set, once
        hour = int(uoj.web_time()[11:13])
        db("delete from backup_runs where reason = 'scheduled'")
        try:
            self.assertEqual(site_settings(self.admin, backup_enabled=False, backup_hour=str(hour)), "")
            site_tick()
            self.assertEqual(runs("scheduled"), 0)
            self.assertIn("自动备份已关闭", self.admin.get(monitor).text)
            self.assertEqual(site_settings(self.admin, backup_enabled=True, backup_hour=str(hour), backup_keep_days="3"), "")
            uoj.wait_until("the backup of the day is made", lambda: site_tick() and idle() and runs("scheduled") == 1, timeout=300)
            for _ in range(2):
                site_tick()
            uoj.wait_until("no backup runs", idle, timeout=300)
            self.assertEqual(int(db_value("select count(*) from backup_runs where reason = 'scheduled'")), 1)
            self.assertIn("每天 %d 点自动备份，保留 3 天" % hour, self.admin.get(monitor).text)
        finally:
            db("delete from site_settings where name like 'backup.%'")


class LocalResourcesTest(unittest.TestCase):
    """a page of the site needs nothing from anywhere else"""

    def test_mathjax_comes_with_the_site(self):
        client = uoj.Client()
        needed = ("MathJax.js", "config/TeX-AMS_HTML.js", "extensions/tex2jax.js", "extensions/MathMenu.js",
                  "jax/input/TeX/jax.js", "jax/element/mml/jax.js", "jax/output/HTML-CSS/jax.js",
                  "jax/output/HTML-CSS/fonts/TeX/fontdata.js", "jax/output/PreviewHTML/jax.js",
                  "fonts/HTML-CSS/TeX/woff/MathJax_Main-Regular.woff", "fonts/HTML-CSS/TeX/otf/MathJax_Math-Italic.otf")  # fmt: skip
        for path in needed:
            r = client.get("/js/mathjax/" + path)
            self.assertEqual(r.status_code, 200, path)
            self.assertGreater(len(r.content), 1000, path)
        # what the site does not use did not come along
        for path in ("jax/output/SVG/jax.js", "jax/output/HTML-CSS/fonts/STIX-Web/fontdata.js", "unpacked/MathJax.js"):
            self.assertEqual(client.get("/js/mathjax/" + path).status_code, 404, path)
        problem_id = uoj.admin().create_problem(ab_problem_files())
        page = client.get("/problem/%d" % problem_id).text
        self.assertIn("/js/mathjax/MathJax.js?config=TeX-AMS_HTML", page)
        self.assertNotIn("jsdelivr", page)

    def test_pages_load_nothing_from_other_sites(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        host = urlparse(uoj.BASE_URL).netloc
        pages = ["/", "/problems", "/problem/%d" % problem_id, "/contests", "/submissions", "/hacks", "/blogs", "/ranklist",
                 "/faq", "/domains", "/user/profile/" + uoj.ADMIN[0], "/user/modify-profile", "/user/msg", "/user/system-msg",
                 "/super-manage/users", "/super-manage/monitor", "/super-manage/settings",
                 "/problem/%d/manage/statement" % problem_id, "/problem/%d/manage/data" % problem_id]  # fmt: skip
        loaded = re.compile(r"""<(?:script|img|iframe|link|source|embed|audio|video)\b[^>]*?\b(?:src|href)\s*=\s*["']([^"']+)""")
        for client, paths in ((admin, pages), (uoj.Client(), ["/", "/login", "/register", "/forgot-password", "/problems", "/faq"])):
            for path in paths:
                r = client.get(path)
                self.assertEqual(r.status_code, 200, path)
                for url in loaded.findall(r.text):
                    if url.startswith(("http://", "https://", "//")):
                        self.assertEqual(urlparse(url).netloc, host, "%s loads %s" % (path, url))
                self.assertNotIn("googleapis", r.text, path)
        # the slides do not wait for fonts from elsewhere either
        for theme in ("beige", "blood", "default", "league", "moon", "night", "serif", "simple", "sky", "solarized"):
            css = admin.get("/css/reveal/theme/%s.css" % theme)
            if css.status_code == 200:
                self.assertNotRegex(css.text, r"@import url\((https?:)?//")

    def test_picture_of_a_user_is_drawn_by_the_site(self):
        user, other = account("p5_avatar_user"), account("p5_avatar_other")
        self.assertEqual(user.update_profile(nickname="晓雨"), "ok")
        picture = lambda name: re.search(r'<img[^>]*src="(data:image/svg\+xml;base64,[^"]+)"', other.get("/user/profile/" + name).text).group(1)
        svg = lambda name: base64.b64decode(picture(name).split(",", 1)[1]).decode()
        self.assertIn(">晓</text>", svg("p5_avatar_user"))
        self.assertIn(">P</text>", svg("p5_avatar_other"))
        # each has a colour of their own, and it stays theirs
        self.assertNotEqual(re.search(r"hsl\([^)]*\)", svg("p5_avatar_user")).group(0), re.search(r"hsl\([^)]*\)", svg("p5_avatar_other")).group(0))
        self.assertEqual(picture("p5_avatar_other"), picture("p5_avatar_other"))
        self.assertNotIn("gravatar", other.get("/user/profile/p5_avatar_user").text)


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
            alert_email=True, alert_recipients="ops@example.edu.cn, not an address",
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
        self.assertEqual(site_settings(self.admin, alert_email=False), "")
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
            self.assertEqual(site_settings(self.admin, alert_email=True), "")


def email_header(value):
    import email.header

    return email.header.make_header(email.header.decode_header(value))
