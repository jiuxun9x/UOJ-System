"""End-to-end tests of phase 7: the accounts judgers work with, and the data of the problems
that judgers fetch before a submission needs it.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import test_phase3 as p3
import uoj
from fixtures import *
from uoj import db, db_value, docker_exec, judge_api


def setUpModule():
    uoj.admin()


def account_rows(page):
    """the judging accounts the page lists: name => (state, enabled, data held, data there is)"""
    rows = {}
    for name, state, enabled, row in re.findall(r'(?s)<tr data-judger="([^"]+)" data-state="(\w+)" data-enabled="(\d)">(.*?)</tr>', page):
        have, total = re.search(r'data-have="(\d*)" data-total="(\d*)"', row).groups()
        rows[name] = (state, enabled == "1", have, total)
    return rows


def cached_version(judger, problem_id):
    """the version of the data of a problem that a judger holds, or None"""
    text = docker_exec(judger, "cat /opt/uoj_judger/uoj_judger/data/%d.version 2>/dev/null; true" % problem_id).strip()
    return json.loads(text)["version"] if text else None


class JudgingAccountTest(unittest.TestCase):
    """a judger works for the site with an account that the system administrator makes"""

    PAGE = "/super-manage/judger"
    IDLE = {"fetch_new": "0"}

    def setUp(self):
        self.admin = uoj.admin()
        db("delete from judger_info where judger_name like 'p7\\_%'")

    def tearDown(self):
        db("delete from judger_info where judger_name like 'p7\\_%'")

    def add(self, name, note=""):
        r = self.admin.post(self.PAGE, {"submit-judger_adder": "judger_adder", "judger_adder_name": name, "judger_adder_note": note})
        found = re.search(r'id="judger-password">([0-9a-zA-Z]{32})<', r.text)
        return (found.group(1) if found else None), r.text

    def act(self, form, name):
        r = self.admin.post(self.PAGE, {"form": form, "judger_name": name})
        return r

    def test_account_is_made_and_tells_how_a_judger_is_started_with_it(self):
        password, page = self.add("p7_remote", "实验楼 3 层的机器")
        self.assertIsNotNone(password)
        # the three things a judger needs: where the site is, the name, the password
        self.assertIn('id="judger-server-url">%s<' % uoj.BASE_URL.rstrip("/"), page)
        self.assertIn('id="judger-name">p7_remote<', page)
        command = re.search(r'(?s)id="judger-command">(.*?)</pre>', page).group(1)
        for part in ("UOJ_SERVER_URL=%s" % uoj.BASE_URL.rstrip("/"), "JUDGER_NAME=p7_remote", "JUDGER_PASSWORD=%s" % password):
            self.assertIn(part, command)
        # only the hash of the password is kept, with who made the account and what for
        row = db("select password, note, created_by, enabled, created_at is not null from judger_info where judger_name = 'p7_remote'")[0]
        self.assertEqual(row, ["sha256:" + uoj.sha256(password.encode()), "实验楼 3 层的机器", self.admin.username, "1", "1"])
        listing = self.admin.get(self.PAGE).text
        self.assertNotIn(password, listing)
        self.assertIn("实验楼 3 层的机器", listing)
        # The accounts have numbers of their own, in the order they were made, which have
        # nothing to do with the numbers of the users.
        number = int(db_value("select id from judger_info where judger_name = 'p7_remote'"))
        self.assertIn("编号 #%d" % number, uoj.text_of(page))
        self.assertRegex(listing, r'(?s)<tr data-judger="p7_remote"[^>]*>\s*<td data-id="%d">#%d</td>' % (number, number))
        self.assertIsNotNone(self.add("p7_second")[0])
        self.assertEqual(int(db_value("select id from judger_info where judger_name = 'p7_second'")), number + 1)
        numbers = [int(row[0]) for row in db("select id from judger_info")]
        self.assertEqual(len(set(numbers)), len(numbers))
        self.assertEqual(db_value("select count(*) from user_info where username in ('p7_remote', 'p7_second')"), "0")
        self.assertEqual(account_rows(listing)["p7_remote"], ("never", True, "", ""))
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'judger.add' and resource_id = 'p7_remote'"), "1")
        self.assertEqual(db_value("select count(*) from audit_logs where after_json like '%%%s%%'" % password), "0")

        # the judger that has them is let in, and is seen to be there
        auth = {"judger_name": "p7_remote", "password": password}
        self.assertEqual(judge_api("/judge/submit", self.IDLE, auth=auth).text, "Nothing to judge")
        self.assertEqual(account_rows(self.admin.get(self.PAGE).text)["p7_remote"][0], "online")
        self.assertEqual(judge_api("/judge/submit", self.IDLE, auth=dict(auth, password=password[::-1])).status_code, 403)

        # names that are no names, and a name that is taken
        for wrong in ("", "has space", "a" * 21, "名字", "p7_remote"):
            self.assertIsNone(self.add(wrong)[0], wrong)
        self.assertEqual(db_value("select count(*) from judger_info where judger_name like 'p7\\_%'"), "2")

    def test_account_is_switched_off_given_a_new_password_and_deleted(self):
        password, page = self.add("p7_managed")
        auth = {"judger_name": "p7_managed", "password": password}
        problem_id = self.admin.create_problem(ab_problem_files())

        # switched off: it is still let in, and told of nothing to do or to fetch
        self.assertEqual(self.act("judger_switch", "p7_managed").status_code, 302)
        self.assertEqual(account_rows(self.admin.get(self.PAGE).text)["p7_managed"][1], False)
        self.assertEqual(judge_api("/judge/submit", {"data_versions": "1"}, auth=auth).json(), {"versions": []})
        self.assertEqual(self.act("judger_switch", "p7_managed").status_code, 302)
        self.assertEqual(account_rows(self.admin.get(self.PAGE).text)["p7_managed"][1], True)
        self.assertIn(problem_id, [row[0] for row in judge_api("/judge/submit", {"data_versions": "1"}, auth=auth).json()["versions"]])

        # a new password: the old one is no good any more
        r = self.act("judger_reset", "p7_managed")
        renewed = re.search(r'id="judger-password">([0-9a-zA-Z]{32})<', r.text).group(1)
        self.assertNotEqual(renewed, password)
        self.assertEqual(judge_api("/judge/submit", self.IDLE, auth=auth).status_code, 403)
        self.assertEqual(judge_api("/judge/submit", self.IDLE, auth=dict(auth, password=renewed)).text, "Nothing to judge")

        # deleted: gone from the list, and no longer let in
        self.assertEqual(self.act("judger_delete", "p7_managed").status_code, 302)
        self.assertNotIn("p7_managed", account_rows(self.admin.get(self.PAGE).text))
        self.assertEqual(judge_api("/judge/submit", self.IDLE, auth=dict(auth, password=renewed)).status_code, 403)
        for action in ("judger.switch", "judger.reset_password", "judger.delete"):
            self.assertGreaterEqual(int(db_value("select count(*) from audit_logs where action = '%s' and resource_id = 'p7_managed'" % action)), 1, action)
        # an account that is not there is said not to be
        self.assertIn("没有这个评测账户", uoj.text_of(self.act("judger_delete", "p7_managed").text))

        # The accounts are made and looked after by the administrators of the site, of both
        # kinds, and by nobody else: who holds one is given every submission to judge.
        oj_admin, teacher = p3.account("p7_oj_admin"), p3.account("p7_teacher")
        self.assertEqual(self.admin.change_user("p7_oj_admin", "grant:oj_admin"), "")
        self.assertEqual(self.admin.change_user("p7_teacher", "grant:teacher"), "")
        self.add("p7_kept")
        for nobody in (teacher, uoj.Client()):
            self.assertIn(nobody.get(self.PAGE).status_code, (302, 403, 404))
            for form in ("judger_switch", "judger_reset", "judger_delete"):
                nobody.post(self.PAGE, {"form": form, "judger_name": "p7_kept"})
            nobody.post(self.PAGE, {"submit-judger_adder": "judger_adder", "judger_adder_name": "p7_sneaked"})
        self.assertEqual(db("select judger_name, enabled from judger_info where judger_name like 'p7\\_%' order by judger_name"), [["p7_kept", "1"]])
        r = oj_admin.post(self.PAGE, {"submit-judger_adder": "judger_adder", "judger_adder_name": "p7_by_oj_admin"})
        self.assertRegex(r.text, r'id="judger-password">[0-9a-zA-Z]{32}<')
        self.assertEqual(db_value("select created_by from judger_info where judger_name = 'p7_by_oj_admin'"), "p7_oj_admin")
        self.assertEqual(oj_admin.post(self.PAGE, {"form": "judger_switch", "judger_name": "p7_kept"}).status_code, 302)
        self.assertEqual(db_value("select enabled from judger_info where judger_name = 'p7_kept'"), "0")

    def test_judger_is_told_which_data_the_problems_have(self):
        password, page = self.add("p7_asking")
        auth = {"judger_name": "p7_asking", "password": password}
        older = self.admin.create_problem(ab_problem_files())
        newer = self.admin.create_problem(checker_problem_files())
        versions = judge_api("/judge/submit", {"data_versions": "1"}, auth=auth).json()["versions"]
        by_problem = {row[0]: row for row in versions}
        # for every problem the version that is judged with, what it hashes to, and what has
        # to be built; the data that was published last comes first
        for problem_id in (older, newer):
            version, sha256 = db("select version, sha256 from problem_data_versions where problem_id = %d and status = 'ready' order by version desc limit 1" % problem_id)[0]
            self.assertEqual(by_problem[problem_id][1:3], [int(version), sha256])
        self.assertEqual(by_problem[older][3], [])
        self.assertEqual([step["name"] for step in by_problem[newer][3]], ["chk"])
        self.assertLess([row[0] for row in versions].index(newer), [row[0] for row in versions].index(older))
        # each comes with a number that grows with every publication, by which a judger
        # that has no more room tells what is new to it
        self.assertEqual([row[4] for row in versions], sorted((row[4] for row in versions), reverse=True))
        self.assertGreater(by_problem[newer][4], by_problem[older][4])
        # and that is the data it is given when it fetches it
        r = judge_api("/judge/download/problem/%d/%d" % (newer, by_problem[newer][1]), auth=auth)
        self.assertEqual((r.status_code, uoj.sha256(r.content)), (200, by_problem[newer][2]))
        # a problem without data is not among them
        empty = self.admin.new_problem(title="p7 没有数据")
        self.assertNotIn(empty, [row[0] for row in judge_api("/judge/submit", {"data_versions": "1"}, auth=auth).json()["versions"]])

        # what the judger says it holds is what the list of the accounts shows
        self.assertEqual(account_rows(self.admin.get(self.PAGE).text)["p7_asking"][2:], ("", ""))
        judge_api("/judge/submit", {"data_versions": "1", "data_have": "3", "data_total": "8"}, auth=auth)
        self.assertEqual(account_rows(self.admin.get(self.PAGE).text)["p7_asking"][2:], ("3", "8"))
        self.assertIn("3 / 8 题", uoj.text_of(self.admin.get(self.PAGE).text))
        judge_api("/judge/submit", {"data_versions": "1", "data_have": "8", "data_total": "8"}, auth=auth)
        self.assertEqual(account_rows(self.admin.get(self.PAGE).text)["p7_asking"][2:], ("8", "8"))
        # numbers that are none are not written down
        judge_api("/judge/submit", {"data_versions": "1", "data_have": "8; drop table x", "data_total": "-1"}, auth=auth)
        self.assertEqual(account_rows(self.admin.get(self.PAGE).text)["p7_asking"][2:], ("8", "8"))
        # nobody but a judger asks
        self.assertEqual(judge_api("/judge/submit", {"data_versions": "1"}, auth=dict(auth, password="wrong")).status_code, 403)


class DataAheadTest(unittest.TestCase):
    """the judgers fetch the data of a problem when it is published, not when it is first judged"""

    def test_every_judger_has_the_data_before_anything_is_submitted(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(checker_problem_files())
        version = int(db_value("select data_version from problems where id = %d" % problem_id))
        self.assertEqual(db_value("select count(*) from submissions where problem_id = %d" % problem_id), "0")
        # nothing was submitted, and still every judger gets the data, with the programs built
        uoj.wait_until("every judger holds the data", lambda: all(cached_version(judger, problem_id) == version for judger in uoj.JUDGERS), timeout=240)
        for judger in uoj.JUDGERS:
            assert_judger_has_data_of_web(self, judger, problem_id)
            self.assertIn("chk", docker_exec(judger, "ls /opt/uoj_judger/uoj_judger/data/%d" % problem_id).split(), judger)
        self.assertEqual(db_value("select count(*) from submissions where problem_id = %d" % problem_id), "0")

        # new data is fetched the same way
        files = checker_problem_files()
        files["input1.txt"] = "40 2\n"
        files["output1.txt"] = "42\n"
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        self.assertEqual(admin.sync(problem_id), "")
        newer = int(db_value("select data_version from problems where id = %d" % problem_id))
        self.assertGreater(newer, version)
        uoj.wait_until("every judger holds the new data", lambda: all(cached_version(judger, problem_id) == newer for judger in uoj.JUDGERS), timeout=240)
        for judger in uoj.JUDGERS:
            self.assertEqual(docker_exec(judger, "cat /opt/uoj_judger/uoj_judger/data/%d/input1.txt" % problem_id), "40 2\n")

        # the judgers say how much they hold, and the page of the accounts shows it
        def all_report():
            rows = account_rows(admin.get("/super-manage/judger").text)
            return all(rows[name][2] != "" and rows[name][3] != "" and int(rows[name][3]) > 0 for name in uoj.JUDGER_NAMES)

        uoj.wait_until("every judger has said what it holds", all_report, timeout=120)
        # and the first submission is judged with what is there already
        j = uoj.wait_submission(admin.submit(problem_id, AB))
        self.assertEqual(j.score, 100, j)


# a picture of four dots
PNG = bytes.fromhex(
    "89504e470d0a1a0a0000000d4948445200000002000000020802000000fdd49a7300"
    "0000164944415478da63b8636363b3e00ec38713366e5ba2002c8206554424bcf700"
    "00000049454e44ae426082"
)
MP4 = b"\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" + bytes(range(256))


class AnnouncementTest(unittest.TestCase):
    """the announcements of the site are posted where they are read, by the administrators, with
    the pictures and films that go with them, and say who posted them"""

    def announcements(self):
        return int(db_value("select count(*) from important_blogs"))

    def test_announcement_from_its_posting_to_its_end(self):
        admin, visitor = uoj.admin(), uoj.Client()
        student, teacher, oj_admin = (p3.account("p7_ann_" + name) for name in ("student", "teacher", "oj_admin"))
        self.assertEqual(admin.change_user("p7_ann_teacher", "grant:teacher"), "")
        self.assertEqual(admin.change_user("p7_ann_oj_admin", "grant:oj_admin"), "")
        form = dict(form="save", title="p7 公告", content_md="正文", level="0")

        # ---- the way in is on the page of the announcements, for who may post
        for may in (admin, oj_admin):
            self.assertIn('id="button-new-announcement"', may.get("/announcements").text)
            self.assertIn('name="media[]"', may.get("/announcement/new").text)
        for may_not in (teacher, student, visitor):
            self.assertNotIn('id="button-new-announcement"', may_not.get("/announcements").text)
        self.assertEqual(student.get("/announcement/new").status_code, 403)
        self.assertEqual(visitor.get("/announcement/new").status_code, 302)
        before = self.announcements()
        self.assertEqual(teacher.post("/announcement/new", form).status_code, 403)
        # what is wrong with one is said, and nothing is posted
        for wrong, said in ((dict(title="  "), "标题"), (dict(level="9"), "置顶"), (dict(title="长" * 201), "标题")):
            r = admin.post("/announcement/new", dict(form, **wrong))
            self.assertEqual(r.status_code, 200, wrong)
            self.assertIn(said, uoj.text_of(r.text), wrong)
        self.assertIn("正文", admin.post("/announcement/new", dict(form, title=" ")).text)
        self.assertEqual(self.announcements(), before)

        # ---- posted, with a picture, a film and a file
        files = [("media[]", ("校园.png", PNG, "image/png")), ("media[]", ("开幕式.mp4", MP4, "video/mp4")),
                 ("media[]", ("报名表.zip", uoj.make_zip({"a.txt": "a"}), "application/zip"))]  # fmt: skip
        r = admin.post("/announcement/new", dict(form, title="p7 秋季赛 <通知>", content_md="**报名**开始了。\n\n<script>alert(1)</script>", level="2"), files)
        self.assertEqual(r.status_code, 302, r.text[-400:])
        announcement_id = int(db_value("select max(blog_id) from important_blogs"))
        here = "/announcement/%d" % announcement_id
        self.assertEqual(r.headers["Location"], here)
        self.assertEqual(self.announcements(), before + 1)
        stored = lambda: db("select poster, is_hidden, (select level from important_blogs where blog_id = blogs.id) from blogs where id = %d" % announcement_id)[0]
        self.assertEqual(stored(), [admin.username, "0", "2"])
        media = {row[1]: int(row[0]) for row in db("select id, name from attachments where owner_type = 'notice' and owner_id = %d" % announcement_id)}
        self.assertEqual(set(media), {"校园.png", "开幕式.mp4", "报名表.zip"})
        text_of_it = lambda: bytes.fromhex(db_value("select hex(content_md) from blogs where id = %d" % announcement_id)).decode()
        # each file was written into the text, as what it is
        self.assertIn("![校园.png](/attachment/%d)" % media["校园.png"], text_of_it())
        self.assertIn('<video controls src="/attachment/%d"></video>' % media["开幕式.mp4"], text_of_it())
        self.assertIn("[报名表.zip](/attachment/%d)" % media["报名表.zip"], text_of_it())

        # ---- everybody reads it, logged in or not: what it says, who posted it, what it shows
        for reader in (visitor, student):
            r = reader.get(here)
            self.assertEqual(r.status_code, 200)
            self.assertIn("p7 秋季赛 &lt;通知&gt;", r.text)
            self.assertRegex(r.text, r'(?s)id="announcement-meta">.*?发布者：.*?>%s<' % admin.username)
            self.assertIn("<strong>报名</strong>", r.text)
            self.assertNotIn("alert(1)", r.text)
            self.assertRegex(r.text, r'<img[^>]*src="/attachment/%d"' % media["校园.png"])
            self.assertRegex(r.text, r'<video[^>]*src="/attachment/%d"' % media["开幕式.mp4"])
            self.assertRegex(r.text, r"<video[^>]*controls")
            self.assertIn('<a href="/attachment/%d">报名表.zip</a>' % media["报名表.zip"], r.text)
            # a picture is a picture and a film a film to the browser; a file is a download
            for name, content, kind, how in (("校园.png", PNG, "image/png", "inline"), ("开幕式.mp4", MP4, "video/mp4", "inline"),
                                             ("报名表.zip", None, "application/octet-stream", "attachment")):  # fmt: skip
                r = reader.get("/attachment/%d" % media[name])
                self.assertEqual((r.status_code, r.headers["Content-Type"].split(";")[0]), (200, kind), name)
                self.assertTrue(r.headers["Content-Disposition"].startswith(how), name)
                if content is not None:
                    self.assertEqual(r.content, content, name)
        # a film is played from where one moves to: a part of it can be asked for
        r = visitor.get("/attachment/%d" % media["开幕式.mp4"], headers={"Range": "bytes=4-11"})
        self.assertEqual((r.status_code, r.content), (206, MP4[4:12]))
        # it stands on the front page and among the announcements, with who posted it
        for path in ("/", "/announcements"):
            page = visitor.get(path).text
            self.assertIn('href="%s"' % here, page, path)
            self.assertIn("p7 秋季赛 &lt;通知&gt;", page, path)
        self.assertRegex(visitor.get("/announcements").text, r'(?s)data-announcement="%d".*?置顶.*?uoj-username[^>]*>%s<' % (announcement_id, admin.username))

        # ---- changed by another administrator: who posted it stays who posted it
        edit = here + "/edit"
        page = oj_admin.get(edit).text
        self.assertIn('value="p7 秋季赛 &lt;通知&gt;"', page)
        self.assertRegex(page, r'<option value="2" selected="selected">')
        self.assertEqual(len(re.findall(r'<tr data-attachment="\d+">', page)), 3)
        r = oj_admin.post(edit, dict(form, title="p7 秋季赛（改期）", content_md=text_of_it().replace("开始了", "改期了"), level="0"))
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, here))
        self.assertEqual(stored(), [admin.username, "0", "0"])
        page = visitor.get(here).text
        self.assertIn("改期了", page)
        self.assertIn("p7 秋季赛（改期）", page)
        self.assertRegex(page, r'<img[^>]*src="/attachment/%d"' % media["校园.png"])
        # another file is added to it, and one is taken away
        r = oj_admin.post(edit, dict(form, title="p7 秋季赛（改期）", content_md=text_of_it()), [("media[]", ("路线.png", PNG, "image/png"))])
        self.assertEqual(r.status_code, 302)
        route = int(db_value("select id from attachments where owner_type = 'notice' and owner_id = %d and name = '路线.png'" % announcement_id))
        self.assertRegex(visitor.get(here).text, r'<img[^>]*src="/attachment/%d"' % route)
        self.assertEqual(oj_admin.post(edit, {"form": "delete_media", "attachment_id": str(media["开幕式.mp4"])}).status_code, 302)
        self.assertEqual(visitor.get("/attachment/%d" % media["开幕式.mp4"]).status_code, 404)
        self.assertEqual(visitor.get("/attachment/%d" % route).status_code, 200)
        # a file of something else is not taken away from here
        self.assertIn("没有这个文件", uoj.text_of(oj_admin.post(edit, {"form": "delete_media", "attachment_id": "99999999"}).text))

        # ---- nobody else changes it or takes it away
        for nobody in (teacher, student):
            self.assertEqual(nobody.get(edit).status_code, 403)
            self.assertEqual(nobody.post(edit, dict(form, title="p7 不该成功")).status_code, 403)
            nobody.post(edit, {"form": "delete_media", "attachment_id": str(route)})
            nobody.post(here, {"form": "delete"})
        self.assertEqual(self.announcements(), before + 1)
        self.assertIn("p7 秋季赛（改期）", visitor.get(here).text)
        self.assertEqual(visitor.get("/attachment/%d" % route).status_code, 200)

        # ---- taken away: gone from the lists, and its files with it
        r = admin.post(here, {"form": "delete"})
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/announcements"))
        self.assertEqual(self.announcements(), before)
        self.assertEqual(visitor.get(here).status_code, 404)
        self.assertNotIn("p7 秋季赛", visitor.get("/announcements").text)
        self.assertNotIn("p7 秋季赛", visitor.get("/").text)
        self.assertEqual(db_value("select count(*) from attachments where owner_type = 'notice' and owner_id = %d" % announcement_id), "0")
        for attachment_id in (media["校园.png"], media["报名表.zip"], route):
            self.assertEqual(visitor.get("/attachment/%d" % attachment_id).status_code, 404)
        for action in ("announcement.post", "announcement.edit", "announcement.delete"):
            self.assertGreaterEqual(int(db_value("select count(*) from audit_logs where action = '%s' and resource_id = '%d'" % (action, announcement_id))), 1, action)
