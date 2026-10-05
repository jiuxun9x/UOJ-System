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
