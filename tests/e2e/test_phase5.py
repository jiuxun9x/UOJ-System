"""End-to-end tests for phase 5: what keeps the site running.

See test_phase1.py for how to start the containers.
"""

import base64
import io
import json
import re
import unittest
import zipfile
from datetime import datetime, timedelta
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
    # the school of the tests of the single sign-on
    p3.IDP.start()


def tearDownModule():
    p3.IDP.stop()


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
        pages = ["/", "/problems", "/problem/%d" % problem_id, "/contests", "/submissions", "/hacks", "/announcements", "/ranklist",
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


def upload_zip(client, problem_id, data):
    """upload an archive as it is, return the page that answers"""
    return client.post(
        "/problem/%d/manage/data" % problem_id,
        {"problem_data_file_submit": "submit"},
        {"problem_data_file": ("data.zip", data, "application/zip")},
    )


def zip_of(entries):
    """an archive of (name, content or ZipInfo with content) pairs, in the order given"""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        for name, content in entries:
            z.writestr(name, content)
    return buf.getvalue()


class UploadTest(unittest.TestCase):
    """what is uploaded as the data of a problem is looked at before it is unpacked"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()

    def uploaded(self, problem_id):
        return web_sh("cd /var/uoj_data/upload/%d && find . -mindepth 1 | sort | tr '\\n' ' '" % problem_id).split()

    def state(self, problem_id):
        page = self.admin.get("/problem/%d/manage/data" % problem_id).text
        return re.search(r'(?s)id="data-preflight".*?id="preflight-state">([^<]*)<', page).group(1), page

    def test_archive_of_a_folder_is_unpacked_without_the_folder_and_the_junk(self):
        problem_id = self.admin.new_problem()
        entries = [("我的 题目/" + name, content) for name, content in ab_problem_files().items()]
        entries += [("__MACOSX/我的 题目/._input1.txt", "junk"), ("我的 题目/.DS_Store", "junk"), ("我的 题目/require/helper.h", "// helper\n")]
        self.assertIn("上传成功", upload_zip(self.admin, problem_id, zip_of(entries)).text)
        files = self.uploaded(problem_id)
        self.assertEqual(sorted(files), sorted(["./" + name for name in ab_problem_files()] + ["./require", "./require/helper.h"]))
        self.assertEqual(self.state(problem_id)[0], "文件齐全，可以同步")
        self.assertEqual(self.admin.sync(problem_id), "")
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        self.assertEqual(uoj.wait_submission(self.admin.submit(problem_id, AB)).score, 100)

    def test_archive_that_would_harm_the_site_is_refused_whole(self):
        problem_id = self.admin.new_problem()
        link = zipfile.ZipInfo("input1.txt")
        link.external_attr = 0o120777 << 16
        link.create_system = 3
        refused = {
            "a name that climbs out": [("problem.conf", "x"), ("../../p5_climbed_out.txt", "x")],
            "an absolute name": [("/var/uoj_data/p5_absolute.txt", "x")],
            "a symbolic link": [("problem.conf", "x"), (link, "/etc/passwd")],
            "too many files": [("input%d.txt" % n, "") for n in range(5001)],
            "nothing but junk": [("__MACOSX/._x", "x"), (".DS_Store", "x")],
            "two names that become one": [("Input1.txt", "1"), ("input1.txt", "2")],
        }
        for what, entries in refused.items():
            r = upload_zip(self.admin, problem_id, zip_of(entries))
            self.assertIn('id="upload-refused"', r.text, what)
            self.assertNotIn("上传成功", r.text, what)
            # nothing of it was written: there is what the problem was made with, and no more
            self.assertEqual(self.uploaded(problem_id), ["./problem.conf"], what)
        self.assertEqual(web_sh("ls /var/uoj_data/ /var/uoj_data/upload | grep -c p5_ || true"), "0")
        self.assertIn('id="upload-refused"', upload_zip(self.admin, problem_id, b"this is no archive").text)
        # the refusals are written down
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'problem.upload_refused' and resource_id = '%d'" % problem_id), str(len(refused) + 1))

        # a file that is small in the archive and huge once unpacked
        bomb = io.BytesIO()
        with zipfile.ZipFile(bomb, "w", zipfile.ZIP_DEFLATED) as z:
            with z.open("input1.txt", "w", force_zip64=True) as f:
                for _ in range(600):
                    f.write(bytes(1048576))
        self.assertLess(len(bomb.getvalue()), 2 * 1048576)
        r = upload_zip(self.admin, problem_id, bomb.getvalue())
        self.assertIn('id="upload-refused"', r.text)
        self.assertIn("600", uoj.text_of(r.text))
        self.assertEqual(self.uploaded(problem_id), ["./problem.conf"])

    def test_data_page_says_what_is_wrong_before_a_sync(self):
        problem_id = self.admin.new_problem()
        state, page = self.state(problem_id)
        self.assertEqual(state, "有问题，同步会失败")
        self.assertIn("还没有测试数据", page)

        files = ab_problem_files()
        del files["output2.txt"], files["ex_input1.txt"]
        files["input9.txt"] = "left over\n"
        self.assertIn("上传成功", self.admin.upload_data(problem_id, files).text)
        state, page = self.state(problem_id)
        self.assertEqual(state, "有问题，同步会失败")
        self.assertIn("缺少文件：output2.txt、ex_input1.txt", page)
        self.assertIn("input9.txt", page)
        # which is what a sync says, one file at a time
        self.assertNotEqual(self.admin.sync(problem_id, wait=False), "")

        # the files that were missing arrive on their own: an upload adds to what is there
        self.assertIn("上传成功", self.admin.upload_data(problem_id, {"output2.txt": "3000\n", "ex_input1.txt": "5 7\n"}).text)
        state, page = self.state(problem_id)
        self.assertEqual(state, "可以同步，但有几处值得看一眼")
        self.assertIn("3 个测试点", page)
        self.assertNotIn("缺少文件", page)
        self.assertEqual(self.admin.sync(problem_id), "")


class ContestAccessTest(unittest.TestCase):
    """who may take part in a contest: everybody, the people on a list, or whoever knows a password"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(ab_problem_files())

    def contest(self, name):
        contest_id = self.admin.new_contest(name)
        self.assertEqual(self.admin.contest_commands(contest_id, "problems", "+%d" % self.problem_id), "")
        return contest_id, "/contest/%d" % contest_id

    def registered(self, contest_id):
        return sorted(row[0] for row in db("select username from contests_registrants where contest_id = %d" % contest_id))

    def test_contest_with_a_password(self):
        contest_id, here = self.contest("p5 密码比赛")
        manage, register = here + "/manage", here + "/register"
        knows, guesses = account("p5_acl_knows"), account("p5_acl_guesses")

        # only who runs the contest says who may take part, and a password has to be one
        self.assertNotEqual(guesses.contest_settings(contest_id, join_mode="password", join_password="let me in"), "")
        for wrong in (dict(join_mode="password"), dict(join_mode="password", join_password="abc"), dict(join_mode="secret")):
            self.assertNotEqual(self.admin.contest_settings(contest_id, **wrong), "", wrong)
        self.assertEqual(db_value("select join_mode from contests where id = %d" % contest_id), "open")
        self.assertEqual(self.admin.contest_settings(contest_id, join_mode="password", join_password="open sesame"), "")
        # the password is kept as a hash, and is not written down where changes are
        self.assertTrue(db_value("select join_password from contests where id = %d" % contest_id).startswith("$2y$"))
        self.assertEqual(db_value("select count(*) from audit_logs where after_json like '%open sesame%'"), "0")
        self.assertNotIn("open sesame", self.admin.get(manage).text)
        # saving again without a password keeps the one there is
        self.assertEqual(self.admin.contest_settings(contest_id, join_mode="password"), "")

        # everybody sees the contest and that it asks for a password
        for client in (guesses, uoj.Client()):
            listed = client.get("/contests").text
            self.assertIn("p5 密码比赛", listed)
            self.assertIn("需要密码", listed)
        self.assertIn('id="contest-needs-password"', guesses.get(register).text)
        self.assertNotEqual(guesses.submit_form(register, "register"), "")
        self.assertNotEqual(guesses.submit_form(register, "register", {"join_password": "open says me"}), "")
        self.assertEqual(self.registered(contest_id), [])
        self.assertEqual(knows.submit_form(register, "register", {"join_password": "open sesame"}), "")
        self.assertEqual(self.registered(contest_id), ["p5_acl_knows"])

        # while it runs, whoever registered is inside and nobody else
        uoj.move_contest(contest_id, -60)
        problem = here + "/problem/%d" % self.problem_id
        self.assertEqual(knows.get(here).status_code, 200)
        self.assertEqual(knows.get(problem).status_code, 200)
        # somebody who did not register is sent to where a contest that runs is joined, and
        # is not inside before they did; somebody who is not logged in is told to come back
        for path in (here, problem):
            r = guesses.get(path)
            self.assertEqual((r.status_code, r.headers.get("Location")), (302, register), path)
        self.assertNotEqual(guesses.submit_form(register, "register", {"join_password": "open says me"}), "")
        self.assertEqual(self.registered(contest_id), ["p5_acl_knows"])
        self.assertIn("尚未报名", uoj.Client().get(here).text)
        # and it stays theirs when it is over, unlike a contest for everybody
        uoj.move_contest(contest_id, -7200)
        self.assertIn('id="contest-closed"', guesses.get(here).text)
        self.assertIn('id="contest-closed"', uoj.Client().get(here + "/standings").text)
        self.assertEqual(guesses.get(problem).status_code, 404)
        self.assertEqual(knows.get(here + "/standings").status_code, 200)
        self.assertEqual(knows.get(problem).status_code, 200)
        self.assertNotIn('id="contest-closed"', self.admin.get(here).text)
        # until who runs it opens it to everybody
        self.assertEqual(self.admin.contest_settings(contest_id, join_mode="open"), "")
        self.assertNotIn('id="contest-closed"', guesses.get(here).text)
        self.assertEqual(guesses.get(here + "/standings").status_code, 200)

    def test_guessing_a_password_stops_after_a_while(self):
        contest_id, here = self.contest("p5 猜密码")
        self.assertEqual(self.admin.contest_settings(contest_id, join_mode="password", join_password="open sesame"), "")
        guesser = account("p5_acl_guesser")
        for n in range(20):
            self.assertIn("参赛密码不正确", guesser.submit_form(here + "/register", "register", {"join_password": "guess %d" % n}))
        self.assertIn("尝试次数过多", guesser.submit_form(here + "/register", "register", {"join_password": "open sesame"}))
        self.assertEqual(self.registered(contest_id), [])

    def test_contest_for_the_people_on_a_list(self):
        contest_id, here = self.contest("p5 名单比赛")
        manage, register = here + "/manage", here + "/register"
        listed, removed, other, helper = (account("p5_acl_" + name) for name in ("listed", "removed", "other", "helper"))
        sso = "/login/sso/cas" in uoj.Client().get("/login").text

        # the list takes usernames and student numbers, of students who were never here as well
        names = "p5_acl_listed\nP5_ACL_REMOVED\nCS27000001\nnot a name!\np5_acl_listed\n"
        self.assertNotEqual(other.form(manage, "allow", names="p5_acl_other"), "")
        self.assertEqual(self.admin.form(manage, "allow", names=names), "")
        self.assertEqual(sorted(row[0] for row in db("select username from contest_allowed_users where contest_id = %d" % contest_id)),
                         ["CS27000001", "p5_acl_listed", "p5_acl_removed"])  # fmt: skip
        page = self.admin.get(manage).text
        self.assertIn("无法识别：not a name!", uoj.text_of(page))
        self.assertIn("还没有登录过", page)
        # a list does nothing until the contest is one for a list
        self.assertIn("p5 名单比赛", other.get("/contests").text)
        self.assertEqual(self.admin.contest_settings(contest_id, join_mode="list"), "")

        # to everybody who is not on it, the contest is not there
        for stranger in (other, uoj.Client()):
            self.assertNotIn("p5 名单比赛", stranger.get("/contests").text)
        for path in ("", "/register", "/standings", "/registrants", "/problem/%d" % self.problem_id):
            self.assertEqual(other.get(here + path).status_code, 404, path)
        self.assertNotEqual(other.submit_form(register, "register"), "")
        # the people on it see it and register, and so do the people who run it
        self.assertEqual(self.admin.contest_commands(contest_id, "managers", "+p5_acl_helper"), "")
        for client in (listed, removed, helper, self.admin):
            page = client.get("/contests").text
            self.assertIn("p5 名单比赛", page)
            self.assertIn("仅名单", page)
        self.assertIn('id="contest-on-list"', listed.get(register).text)
        self.assertEqual(listed.submit_form(register, "register"), "")
        self.assertEqual(self.registered(contest_id), ["p5_acl_listed"])

        # a student number lets its student in, whatever they are called and whenever they first come
        if sso:
            student = uoj.Client()
            p3.cas_login(student, "p5_acl_student", employeeNumber="CS27000001", cn="冯九")
            self.assertEqual(p3.who(student), "CS27000001")
            self.assertIn("p5 名单比赛", student.get("/contests").text)
            self.assertEqual(student.submit_form(register, "register"), "")
            self.assertRegex(self.admin.get(manage).text, r'(?s)id="list-allowed-users".*?class="uoj-username"[^>]*>CS27000001<')

        # taken off the list before registering: the contest is gone again
        self.assertEqual(self.admin.form(manage, "disallow", username="p5_acl_removed"), "")
        self.assertNotIn("p5 名单比赛", removed.get("/contests").text)
        self.assertEqual(removed.get(register).status_code, 404)
        # taken off it after registering: whoever registered takes part
        self.assertEqual(self.admin.form(manage, "disallow", username="p5_acl_listed"), "")
        self.assertIn("p5 名单比赛", listed.get("/contests").text)

        uoj.move_contest(contest_id, -60)
        self.assertEqual(listed.get(here).status_code, 200)
        uoj.wait_submission(listed.submit_in_contest(contest_id, self.problem_id, AB))
        self.assertEqual(other.get(here).status_code, 404)
        uoj.move_contest(contest_id, -7200)
        self.assertEqual(listed.get(here + "/standings").status_code, 200)
        self.assertEqual(other.get(here + "/standings").status_code, 404)
        self.assertEqual(helper.get(here + "/standings").status_code, 200)
        # what was submitted in it is not in the lists of who may not see the contest
        self.assertEqual(other.get(here + "/submissions").status_code, 404)


class VirtualTest(unittest.TestCase):
    """sitting a contest that is over, alone and against the clock"""

    def standings(self, client, contest_id):
        """the replayed standings as a page shows them: rows of username, rank, score, whether it
        is the virtual row. While a participation runs they are the board of the contest itself;
        of one that is over, a tab of the page it was started on."""
        page = client.get("/contest/%d/standings" % contest_id).text
        if 'id="table-virtual-standings"' not in page:
            page = client.get("/contest/%d/virtual?tab=standings" % contest_id).text
        rows = re.findall(r'(?s)<tr([^>]*) data-username="([^"]+)" data-rank="(\d+)">(.*?)</tr>', page)
        return [(name, int(rank), int(re.search(r"<strong>(-?\d+)</strong>", body).group(1)), "virtual-my-row" in attrs) for attrs, name, rank, body in rows]

    def test_contest_is_sat_again_with_its_standings_replayed(self):
        admin = uoj.admin()
        first_id = admin.create_problem(ab_problem_files())
        second_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p5 回放比赛")
        self.assertEqual(admin.contest_commands(contest_id, "problems", "+%d\n+%d" % (first_id, second_id)), "")
        here, virtual = "/contest/%d" % contest_id, "/contest/%d/virtual" % contest_id
        early, late, sitter, other = (account("p5_vp_" + name) for name in ("early", "late", "sitter", "other"))
        early.register_for_contest(contest_id)
        late.register_for_contest(contest_id)

        # the real contest: early solves the first problem 5 minutes in, late 30 minutes in, and
        # late fails the second one
        uoj.move_contest(contest_id, -600)
        solved_early = early.submit_in_contest(contest_id, first_id, AB)
        solved_late = late.submit_in_contest(contest_id, first_id, AB)
        failed_late = late.submit_in_contest(contest_id, second_id, AB_WRONG)
        uoj.wait_idle()
        # nobody sits a contest virtually before its results are final
        self.assertNotEqual(sitter.form(virtual, "start"), "")
        uoj.move_contest(contest_id, -7200)
        self.assertIn('id="virtual-not-yet"', sitter.get(virtual).text)
        self.assertNotEqual(sitter.form(virtual, "start"), "")
        for submission_id, seconds in ((solved_early, 300), (solved_late, 1800), (failed_late, 3000)):
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d second) where id = %d"
               % (contest_id, seconds, submission_id))  # fmt: skip
        self.assertEqual(admin.submit_form(here, "start_test"), "")
        uoj.wait_idle()
        self.assertEqual(admin.submit_form(here, "publish_result"), "")
        self.assertEqual(
            db("select submitter, problem_id, score, penalty from contests_submissions where contest_id = %d order by submitter, problem_id" % contest_id),
            [["p5_vp_early", str(first_id), "100", "300"], ["p5_vp_late", str(first_id), "100", "1800"], ["p5_vp_late", str(second_id), "0", "0"]],
        )
        self.assertEqual(db_value("select count(*) from contest_virtuals where contest_id = %d" % contest_id), "0")

        # now it can be sat again, by whoever is logged in
        self.assertIn('id="link-virtual"', sitter.get(here).text)
        self.assertEqual(uoj.Client().get(virtual).status_code, 302)
        self.assertIn('id="button-virtual-start"', sitter.get(virtual).text)

        # The field for the time of a reservation is filled in already, with the next minute
        # that ends in 0 or 5 and its date: a time is changed, not typed from nothing.
        def offered_at(now):
            opened = datetime.strptime(now, "%Y-%m-%d %H:%M:%S").replace(second=0)
            return (opened + timedelta(minutes=5 - opened.minute % 5)).strftime("%Y-%m-%dT%H:%M")

        before = uoj.web_time()
        page = sitter.get(virtual).text
        offered = re.search(r'id="input-start_time" name="start_time" value="([^"]*)"', page).group(1)
        self.assertIn(offered, (offered_at(before), offered_at(uoj.web_time())))
        self.assertRegex(page, r'id="input-start_time" name="start_time" value="[^"]+" min="%s[^"]+" max="\d{4}-\d\d-\d\dT\d\d:\d\d" required' % before[:10])
        # it can be reserved as it stands
        self.assertEqual(sitter.form(virtual, "reserve", start_time=offered), "")
        self.assertEqual(db_value("select start_time from contest_virtuals where contest_id = %d and username = 'p5_vp_sitter'" % contest_id), offered.replace("T", " ") + ":00")
        self.assertEqual(sitter.form(virtual, "cancel"), "")

        # at a time that was reserved, which can be given up
        for wrong in (uoj.web_time(-3600), uoj.web_time(40 * 86400), "next week", ""):
            self.assertNotEqual(sitter.form(virtual, "reserve", start_time=wrong), "", wrong)
        self.assertEqual(sitter.form(virtual, "reserve", start_time=uoj.web_time(3600)[:16].replace(" ", "T")), "")
        page = sitter.get(virtual).text
        self.assertIn('id="virtual-upcoming"', page)
        self.assertNotIn('id="table-virtual-problems"', page)
        self.assertEqual(self.standings(sitter, contest_id), [])
        self.assertEqual(sitter.form(virtual, "cancel"), "")
        self.assertNotEqual(sitter.form(virtual, "cancel"), "")
        self.assertEqual(db_value("select count(*) from contest_virtuals where contest_id = %d" % contest_id), "0")

        # or now
        self.assertEqual(sitter.form(virtual, "start"), "")
        self.assertNotEqual(sitter.form(virtual, "start"), "")
        self.assertEqual(db_value("select last_min from contest_virtuals where contest_id = %d and username = 'p5_vp_sitter'" % contest_id), "60")
        # it is sat in the pages of the contest itself, and the page it was started on leads there
        self.assertEqual((sitter.get(virtual).status_code, sitter.get(virtual).headers["Location"]), (302, here))
        self.assertIn('id="virtual-running"', sitter.get(here).text)

        # the test moves the start of the participation back to let its time go by, and what
        # was submitted in it with it
        mine = {}

        def at(elapsed):
            db("update contest_virtuals set start_time = '%s' where contest_id = %d and username = 'p5_vp_sitter'" % (uoj.web_time(-elapsed), contest_id))
            for submission_id, seconds in mine.items():
                db("update submissions set submit_time = date_add((select start_time from contest_virtuals where contest_id = %d and username = 'p5_vp_sitter'), interval %d second) where id = %d"
                   % (contest_id, seconds, submission_id))  # fmt: skip
            return self.standings(sitter, contest_id)

        def submit(problem_id, code, seconds):
            submission_id = sitter.submit(problem_id, code, path="%s/problem/%d" % (here, problem_id))
            uoj.wait_submission(submission_id)
            mine[submission_id] = seconds
            return submission_id

        me = lambda rows: [row for row in rows if row[3]][0]
        # at the start nobody has anything
        self.assertEqual(sorted(row[:3] for row in at(10)), [("p5_vp_early", 1, 0), ("p5_vp_late", 1, 0), ("p5_vp_sitter", 1, 0)])
        # ten minutes in, the contestant who was early has the first problem
        rows = at(600)
        self.assertEqual([row[:3] for row in rows], [("p5_vp_early", 1, 100), ("p5_vp_sitter", 2, 0), ("p5_vp_late", 2, 0)])
        self.assertEqual(me(rows)[:3], ("p5_vp_sitter", 2, 0))

        # what is submitted to a problem of the contest counts, and is a submission like any other
        own = submit(first_id, AB, 900)
        self.assertEqual(db("select ifnull(contest_id, 'NULL'), score from submissions where id = %d" % own), [["NULL", "100"]])
        self.assertIn('id="virtual-banner"', sitter.get("%s/problem/%d" % (here, first_id)).text)
        self.assertNotIn('id="virtual-banner"', other.get("%s/problem/%d" % (here, first_id)).text)
        rows = at(1000)
        self.assertEqual([row[:3] for row in rows], [("p5_vp_early", 1, 100), ("p5_vp_sitter", 2, 100), ("p5_vp_late", 3, 0)])
        # the others get what they got when they got it
        self.assertEqual([row[:3] for row in at(2000)], [("p5_vp_early", 1, 100), ("p5_vp_sitter", 2, 100), ("p5_vp_late", 3, 100)])

        # A problem that is still hidden is a problem of the contest to who sits it, and to
        # nobody else who did not take part.
        db("update problems set is_hidden = 1 where id = %d" % second_id)
        self.assertIn("请耐心等待", other.submit_form("%s/problem/%d" % (here, second_id), "answer", {
            "answer_answer_upload_type": "editor", "answer_answer_editor": AB, "answer_answer_language": "C++17",
        }))  # fmt: skip
        submit(second_id, AB, 2400)
        rows = at(2500)
        self.assertEqual([row[:3] for row in rows], [("p5_vp_sitter", 1, 200), ("p5_vp_early", 2, 100), ("p5_vp_late", 3, 100)])
        page = sitter.get(here).text
        self.assertRegex(page, r'id="virtual-score">200 ')
        self.assertRegex(uoj.text_of(page), r"此刻排在第\s*1\s*名")

        # when the time is up it is over: what comes later does not count
        rows = at(4000)
        page = sitter.get(virtual).text
        self.assertIn('id="virtual-ended"', page)
        self.assertRegex(uoj.text_of(page), r"相当于第\s*1\s*名")
        late_one = sitter.submit(first_id, AB_WRONG, path="%s/problem/%d" % (here, first_id))
        uoj.wait_submission(late_one)
        self.assertEqual([row[:3] for row in self.standings(sitter, contest_id)], [("p5_vp_sitter", 1, 200), ("p5_vp_early", 2, 100), ("p5_vp_late", 3, 100)])
        # and the contest itself is what it was
        self.assertEqual(db_value("select count(*) from contests_registrants where contest_id = %d" % contest_id), "2")
        self.assertEqual(db_value("select count(*) from contests_submissions where contest_id = %d" % contest_id), "3")
        self.assertNotIn("p5_vp_sitter", admin.get(here + "/standings").text)

        # it can be sat once more, from nothing
        self.assertEqual(sitter.form(virtual, "start"), "")
        mine.clear()
        self.assertEqual(me(self.standings(sitter, contest_id))[2], 0)
        self.assertEqual(db_value("select count(*) from contest_virtuals where contest_id = %d" % contest_id), "1")

        # a contest that is not for everybody is sat virtually by the people who took part
        self.assertEqual(admin.contest_settings(contest_id, join_mode="password", join_password="open sesame"), "")
        self.assertEqual(other.get(virtual).status_code, 404)
        self.assertNotEqual(other.form(virtual, "start"), "")
        self.assertEqual(early.get(virtual).status_code, 200)
        self.assertEqual(early.form(virtual, "start"), "")
        # who took part is in the standings twice: as they were, and as they are now
        rows = self.standings(early, contest_id)
        self.assertEqual(sorted((row[0], row[3]) for row in rows), [("p5_vp_early", False), ("p5_vp_early", True), ("p5_vp_late", False)])


class ProgramCacheTest(unittest.TestCase):
    """a judger does not build again what it has built"""

    def test_checker_that_did_not_change_is_not_built_again(self):
        if uoj.N_JUDGERS != 1:
            self.skipTest("with several judgers, which of them builds a version of the data is not told")
        admin = uoj.admin()
        hits = lambda: int(uoj.docker_exec(uoj.JUDGERS[0], "grep -c 'program_cache' /opt/uoj_judger/log/judge.log || true").strip() or 0)
        # a checker no other test has
        files = checker_problem_files()
        files["chk.cpp"] += "// p5 program cache %s\n" % uoj.web_time()
        before = hits()
        problem_id = admin.create_problem(files)
        self.assertEqual(hits(), before)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)

        # other tests, the same checker: it is not built again
        files["input3.txt"], files["output3.txt"] = "20 22\n", "42\n"
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        self.assertEqual(admin.sync(problem_id), "")
        self.assertEqual(hits(), before + 1)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)
        # and it is the checker of the problem that judges
        self.assertLess(uoj.wait_submission(admin.submit(problem_id, AB.replace("a + b", "a + b + 2"))).score, 100)

        # a checker that changed is built
        files["chk.cpp"] += "// changed\n"
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        self.assertEqual(admin.sync(problem_id), "")
        self.assertEqual(hits(), before + 1)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)


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


def judging_alerts():
    """the open alerts about the judgers and the queue: a backup has its own, at its own time"""
    return [kind for kind in open_alerts() if kind in ("no_judger", "judger_silent", "queue_stuck")]


def wait_for_calm(what="nothing is wrong with the judgers"):
    try:
        uoj.wait_until(what, lambda: site_tick() and judging_alerts() == [], timeout=180)
    except Exception as e:
        raise Exception("%s; still open: %s" % (e, db("select kind, subject, message from site_alerts where active_slot = 1")))


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
        # Judgers that earlier tests registered, let connect once and left behind would be
        # reported as gone for ever, and rightly so. They are switched off, which is what an
        # administrator does with a judger that is not coming back.
        names = ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES)
        cls.left_behind = [row[0] for row in db("select judger_name from judger_info where enabled = 1 and judger_name not in (%s)" % names)]
        db("update judger_info set enabled = 0 where judger_name not in (%s)" % names)

    @classmethod
    def tearDownClass(cls):
        cls.smtp.stop()
        db("delete from site_settings where name like 'mail.%' or name like 'alert.%'")
        for name in cls.left_behind:
            db("update judger_info set enabled = 1 where judger_name = '%s'" % name)

    def test_judgers_that_are_gone_are_reported_and_so_is_their_return(self):
        monitor = "/super-manage/monitor"
        admin_name = uoj.ADMIN[0]
        # The two things this test makes go wrong: no judger at all, and a queue that is stuck.
        # With several judgers, one of them may come back a moment before the others, and
        # that the others are still silent is then reported as well: that is not counted.
        told = lambda: int(db_value("select count(*) from user_system_msg where receiver = '%s' and (title like '%%评测机全部离线%%' or title like '%%评测积压%%') and (title like '告警：%%' or title like '已恢复：%%')" % admin_name))
        mails = lambda: [mail for mail in self.smtp.messages if "评测机全部离线" in mail.subject or "评测积压" in mail.subject]
        uoj.wait_idle()
        wait_for_calm()
        page = self.admin.get(monitor).text
        self.assertNotIn("没有在线的评测机，提交无法评测 <small>（从", page)
        for name in uoj.JUDGER_NAMES:
            self.assertRegex(page, r'(?s)data-judger="%s">.*?badge-success">在线' % name)
        count = "select count(*) from site_alerts where kind in ('no_judger', 'judger_silent', 'queue_stuck')"
        told_before, mails_before, alerts_before = told(), len(mails()), int(db_value(count))

        problem_id = self.admin.create_problem(ab_problem_files())
        with uoj.judgers_paused():
            # the judgers have not been heard of for ten minutes, and a submission waits for an hour
            db("update judger_info set last_heartbeat_at = now() - interval 10 minute")
            waiting = self.admin.submit(problem_id, AB)
            db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-3600), waiting))
            site_tick()
            self.assertEqual(judging_alerts(), ["no_judger", "queue_stuck"])
            # looking again tells nothing twice
            for _ in range(3):
                site_tick()
            self.assertEqual(judging_alerts(), ["no_judger", "queue_stuck"])
            self.assertEqual(int(db_value(count)), alerts_before + 2)

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
            sent = mails()[mails_before:]
            self.assertEqual(len(sent), 2)
            self.assertEqual([mail.recipients for mail in sent], [["ops@example.edu.cn"]] * 2)
            self.assertTrue(all("告警" in mail.subject for mail in sent), [mail.subject for mail in sent])
            self.assertIn("没有在线的评测机", "".join(mail.text for mail in sent))

        # the judgers are back: the submission is judged, and the alerts close by themselves
        self.assertEqual(uoj.wait_submission(waiting).score, 100)
        wait_for_calm("the alerts are over")
        self.assertEqual(told(), told_before + 4)
        sent = mails()[mails_before:]
        self.assertEqual(len(sent), 4)
        self.assertTrue(all("已恢复" in mail.subject for mail in sent[2:]), [mail.subject for mail in sent])
        page = self.admin.get(monitor).text
        for name in uoj.JUDGER_NAMES:
            self.assertRegex(page, r'(?s)data-judger="%s">.*?badge-success">在线' % name)
        self.assertNotIn("没有在线的评测机，提交无法评测 <small>（从", page)
        self.assertGreaterEqual(int(db_value(count + " and resolved_at is not null and mailed_at is not null and started_at > now() - interval 10 minute")), 2)

        # the state of the site is for its administrators
        oj_admin = account("p5_mon_ojadmin")
        self.assertEqual(self.admin.change_user("p5_mon_ojadmin", "grant:oj_admin"), "")
        self.assertEqual(oj_admin.get(monitor).status_code, 200)
        self.assertEqual(account("p5_mon_user").get(monitor).status_code, 403)

    def test_mail_is_not_sent_unless_asked_for(self):
        self.assertEqual(site_settings(self.admin, alert_email=False), "")
        try:
            mails_before = len([mail for mail in self.smtp.messages if "评测" in mail.subject])
            wait_for_calm()
            with uoj.judgers_paused():
                db("update judger_info set last_heartbeat_at = now() - interval 10 minute")
                site_tick()
                self.assertEqual(judging_alerts(), ["no_judger"])
            wait_for_calm("the alert is over")
            self.assertEqual(len([mail for mail in self.smtp.messages if "评测" in mail.subject]), mails_before)
        finally:
            self.assertEqual(site_settings(self.admin, alert_email=True), "")


def email_header(value):
    import email.header

    return email.header.make_header(email.header.decode_header(value))
