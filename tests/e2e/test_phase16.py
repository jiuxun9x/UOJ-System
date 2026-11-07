"""Phase 16: what a review of the site found, mended. Each class is one of the defects, told by
what it did wrong and kept from coming back."""

import json
import re
import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account
from uoj import db, db_value, docker_exec


def members(client, slug, form, **fields):
    """the form of the members of a domain: '' or why it was refused"""
    return client.form("/d/%s/members" % slug, form, **fields)


def managers(problem_id):
    return [row[0] for row in db("select username from problems_permissions where problem_id = %d order by username" % problem_id)]


def title_of(problem_id):
    return db_value("select title from problems where id = %d" % problem_id)


def try_login(username, password):
    """what the site answers a login with this password"""
    client = uoj.Client()
    r = client.http.post(uoj.BASE_URL + "/login", data={
        "_token": client.token, "login": "", "username": username, "password": client.password_hash(password),
    })  # fmt: skip
    return r.text


class DomainProblemManagerTest(unittest.TestCase):
    """A problem of a domain could be given to anybody to manage, and stayed theirs when they
    left the domain or were taken out of it: from its address on the site they changed it still."""

    def test_who_is_taken_out_of_a_domain_manages_its_problems_no_more(self):
        admin = uoj.admin()
        teacher = account("p16_mgr_teacher")
        self.assertEqual(admin.change_user("p16_mgr_teacher", "grant:teacher"), "")
        teacher.new_domain("p16-managers")
        helper = account("p16_mgr_helper")
        outsider = account("p16_mgr_outsider")
        self.assertEqual(members(teacher, "p16-managers", "add", username="p16_mgr_helper", role="member"), "")
        problem_id = teacher.new_problem("p16-managers")
        manage = "/problem/%d/manage" % problem_id

        # it is given to members of the domain only
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "+p16_mgr_outsider"})
        self.assertEqual(managers(problem_id), [])
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "+p16_mgr_helper"})
        self.assertEqual(managers(problem_id), ["p16_mgr_helper"])
        self.assertEqual(helper.get(manage + "/statement").status_code, 200)
        self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(problem_id), "")
        submission_id = teacher.submit(problem_id, AB, path="/d/p16-managers/problem/%d" % uoj.pid(problem_id))
        link = 'href="/submission/%d"' % submission_id
        self.assertIn(link, helper.get("/submissions?problem_id=%d" % problem_id).text)

        # taken out of the domain, the helper manages it no more, from no address
        self.assertEqual(members(teacher, "p16-managers", "remove", username="p16_mgr_helper"), "")
        self.assertEqual(managers(problem_id), [])
        for page in ("statement", "data", "managers"):
            self.assertIn(helper.get("%s/%s" % (manage, page)).status_code, (403, 404), page)
        before = title_of(problem_id)
        helper.post(manage + "/statement", {"problem_title": "p16 changed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""})
        self.assertEqual(title_of(problem_id), before)
        self.assertNotIn(link, helper.get("/submissions?problem_id=%d" % problem_id).text)

        # nor does anybody who was given it before this was so, and is not in the domain
        db("insert into problems_permissions (problem_id, username) values (%d, 'p16_mgr_outsider')" % problem_id)
        self.assertIn(outsider.get(manage + "/statement").status_code, (403, 404))
        outsider.post(manage + "/statement", {"problem_title": "p16 changed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""})
        self.assertEqual(title_of(problem_id), before)
        # and who manages it can still take that away
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "-p16_mgr_outsider"})
        self.assertEqual(managers(problem_id), [])


class RestrictedContestSubmissionTest(unittest.TestCase):
    """A contest with a password, or for the people on a list, stays with the people who took part
    in it after it is over, and its page says so. What was submitted in it was on the lists of
    submissions of the site for everybody, with its code where the problem shows everybody the code."""

    def test_what_was_submitted_in_it_stays_with_who_took_part(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files(), extra_config={"view_content_type": "ALL", "view_all_details_type": "ALL"})
        contest_id = admin.new_contest("p16 locked", rule="ICPC", problems=str(problem_id), join_mode="password", join_password="p16 sesame")
        pupil = account("p16_locked_pupil")
        mate = account("p16_locked_mate")
        stranger = account("p16_locked_stranger")
        for client in (pupil, mate):
            self.assertEqual(client.submit_form("/contest/%d/register" % contest_id, "register", {"join_password": "p16 sesame"}), "")
        uoj.move_contest(contest_id, -60, 60)
        inside = pupil.submit_in_contest(contest_id, problem_id, AB + "// p16-inside\n")
        uoj.move_contest(contest_id, -7200, 60)
        outside = stranger.submit(problem_id, AB + "// p16-outside\n")

        # what was submitted inside: for who took part, the staff, and nobody else, on no list
        for client in (pupil, mate, admin):
            self.assertIn("p16-inside", client.get("/submission/%d" % inside).text)
            self.assertIn('href="/submission/%d"' % inside, client.get("/submissions?problem_id=%d" % problem_id).text)
        for client in (stranger, uoj.Client()):
            self.assertEqual(client.get("/submission/%d" % inside).status_code, 403)
            for page in ("/submissions?problem_id=%d" % problem_id, "/submissions", "/problem/%d/statistics" % problem_id):
                self.assertNotIn('href="/submission/%d"' % inside, client.get(page).text, page)
        # what was submitted to the problem outside of the contest is everybody's to read
        for client in (pupil, stranger, uoj.Client()):
            self.assertIn("p16-outside", client.get("/submission/%d" % outside).text)


class ArchivedDomainTest(unittest.TestCase):
    """An archived domain is read only until it is brought back. The teacher who made a contest
    there still changed it, as the owner of the contest, and the administrators of the site
    changed its problems and its contests."""

    def test_nothing_in_an_archived_domain_is_changed(self):
        admin = uoj.admin()
        teacher = account("p16_arch_teacher")
        self.assertEqual(admin.change_user("p16_arch_teacher", "grant:teacher"), "")
        teacher.new_domain("p16-archived")
        problem_id = teacher.new_problem("p16-archived")
        contest_id = teacher.new_contest("p16 archived contest", domain="p16-archived")
        contest_name = lambda: db_value("select name from contests where id = %d" % contest_id)
        statement = {"problem_title": "p16 renamed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""}
        before = title_of(problem_id)
        self.assertEqual(teacher.form("/d/p16-archived/settings", "archive"), "")

        for client in (teacher, admin):
            self.assertNotEqual(client.contest_settings(contest_id, name="p16 renamed"), "")
            self.assertEqual(contest_name(), "p16 archived contest")
            self.assertIn(client.get("/contest/%d/manage" % contest_id).status_code, (403, 404))
            client.post("/problem/%d/manage/statement" % problem_id, statement)
            self.assertEqual(title_of(problem_id), before)
        # it is read as before
        for client in (teacher, admin):
            self.assertEqual(client.get("/d/p16-archived/problem/%d" % uoj.pid(problem_id)).status_code, 200)
            self.assertIn("p16 archived contest", client.get("/d/p16-archived/contests").text)

        # brought back, it is changed again
        self.assertEqual(teacher.form("/d/p16-archived/settings", "archive"), "")
        self.assertEqual(teacher.contest_settings(contest_id, name="p16 renamed"), "")
        self.assertEqual(contest_name(), "p16 renamed")


class LoginNameLettersTest(unittest.TestCase):
    """The wrong passwords of a name were counted by the name as it was typed, while the user is
    found by the name in any letters: past the limit, the same name in capitals tried on."""

    def test_a_name_written_otherwise_is_the_same_name(self):
        uoj.Client().register("p16_letters", "p16-letters-password")
        for name in ("p16_letters", "P16_letters", "p16_LETTERS", "P16_LETTERS", "p16_Letters"):
            self.assertEqual(try_login(name, "wrong"), "failed")
        # the sixth is not looked at, however the name is written
        for name in ("p16_letters", "P16_LETTERS", "p16_lEtTeRs"):
            self.assertRegex(try_login(name, "p16-letters-password"), r"^throttled \d+$")
        db("delete from auth_failures where scope like 'login:%p16_letters%'")
        self.assertEqual(try_login("P16_LETTERS", "p16-letters-password"), "ok")


class DecimalScoreTest(unittest.TestCase):
    """A problem may count in decimals (score_type real-1). The site keeps a score as a whole
    number, and 99.6 was kept as 100: a pass, counted among the solved problems. And then, in six
    decimals (real-6), 99.999999 was kept as 100 as well."""

    def test_a_score_short_of_full_is_no_pass(self):
        admin = uoj.admin()
        pupil = account("p16_decimal")
        # what misses the third test (999999999 1) misses what that test is worth
        almost = AB.replace("a + b", "a < 1000000 ? a + b : 0")
        for score_type, worth, short in (("real-1", ("49.8", "49.8", "0.4"), 99.6), ("real-6", ("50", "49.999999", "0.000001"), 99.999999)):
            problem_id = admin.create_problem(ab_problem_files(score_type=score_type, point_score_1=worth[0], point_score_2=worth[1], point_score_3=worth[2]))
            solved = lambda: db_value("select count(*) from best_ac_submissions where submitter = 'p16_decimal' and problem_id = %d" % problem_id)
            submission_id = pupil.submit(problem_id, almost)
            judgement = uoj.wait_submission(submission_id)
            self.assertEqual(judgement.score, 99, (score_type, judgement))
            self.assertAlmostEqual(judgement.result["score"], short, places=9, msg=score_type)
            self.assertEqual(db_value("select score from submission_judgements where kind = 'submission' and target_id = %d and outcome = 'judged'" % submission_id), "99")
            self.assertEqual(solved(), "0", score_type)
            # all of it is 100, and a pass
            self.assertEqual(uoj.wait_submission(pupil.submit(problem_id, AB)).score, 100, score_type)
            self.assertEqual(solved(), "1", score_type)


class UploadLimitTest(unittest.TestCase):
    """The data of a problem is held to 5000 files and 1024 MB, and an archive was checked against
    them by itself: archives one after the other held as many files as they liked, and the files
    an archive wrote over were counted twice (the unit tests of uploadEntriesError have the bytes)."""

    def test_an_archive_counts_with_the_files_there_are(self):
        admin = uoj.admin()
        problem_id = admin.new_problem()
        folder = "/var/uoj_data/upload/%d" % problem_id
        there = lambda: int(docker_exec(uoj.WEB, "find %s -type f | wc -l" % folder).strip())
        files = lambda names: {name: "1\n" for name in names}
        first = ["p16_%04d.txt" % i for i in range(2600)]
        self.assertIn("上传成功", admin.upload_data(problem_id, files(first)).text)
        # the first again, with as many new files as there is room for: the first are written over
        more = ["p16_more_%04d.txt" % i for i in range(5000 - there())]
        self.assertIn("上传成功", admin.upload_data(problem_id, files(first + more)).text)
        # and one more is one too many
        page = admin.upload_data(problem_id, files(["p16_last.txt"])).text
        self.assertIn('id="upload-refused"', page)
        self.assertIn("个文件，超过了上限 5000 个", page)
        self.assertEqual(docker_exec(uoj.WEB, "test -e %s/p16_last.txt && echo there || echo missing" % folder).strip(), "missing")


def board_submissions(client, contest_id):
    """the submissions the cells of the board of a contest (not under the ICPC rule) link to: user => ids"""
    page = client.get("/contest/%d/standings" % contest_id).text
    score = json.loads(re.search(r"(?m)^score=(.*);$", page).group(1))
    cells = lambda row: row.values() if isinstance(row, dict) else row
    return {name: sorted(cell[2] for cell in cells(row)) for name, row in (score.items() if score else [])}


class DeletedSubmissionBoardTest(unittest.TestCase):
    """The board of a contest whose results are published is made of its submissions. When one of
    them was deleted, the board went on linking to it, a page that was not there any more, and
    counting it, until somebody updated the board by hand."""

    def test_the_board_is_made_again_of_what_is_left(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        cheat, honest = account("p16_del_cheat"), account("p16_del_honest")
        contest_id = admin.new_contest("p16 删除提交", rule="IOI", problems=str(problem_id))
        for pupil in (cheat, honest):
            pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        copied = cheat.submit_in_contest(contest_id, problem_id, AB)
        # (two of the three tests: fewer points, and no tie whose order the seconds decide)
        own = honest.submit_in_contest(contest_id, problem_id, AB.replace("a + b", "a < 1000000 ? a + b : 0"))
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        ranks = lambda: db("select username, `rank` from contests_registrants where contest_id = %d order by username" % contest_id)
        self.assertEqual(ranks(), [["p16_del_cheat", "1"], ["p16_del_honest", "2"]])
        self.assertEqual(board_submissions(admin, contest_id), {"p16_del_cheat": [copied], "p16_del_honest": [own]})

        # the copied program is deleted: the board is made again of what is left
        self.assertEqual(admin.submit_form("/submission/%d" % copied, "delete"), "")
        self.assertEqual(db_value("select count(*) from submissions where id = %d" % copied), "0")
        self.assertEqual(db_value("select count(*) from contests_submissions where submission_id = %d" % copied), "0")
        self.assertEqual(board_submissions(admin, contest_id).get("p16_del_cheat", []), [])
        self.assertEqual(board_submissions(admin, contest_id)["p16_del_honest"], [own])
        self.assertEqual(ranks(), [["p16_del_cheat", "2"], ["p16_del_honest", "1"]])

    def test_a_board_waits_for_what_is_judged_again(self):
        """Deleting a submission while another one of the contest was judged again made the board
        anew at once, of the submissions that had a score: the one judged again had none yet, and
        the published result of its contestant was gone from the board, also after its judgement."""
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        for rule, ranks_after in (("IOI", ["2", "1"]), ("ICPC", ["1", "1"])):
            cheat, honest = account("p16_rj_cheat_" + rule.lower()), account("p16_rj_honest_" + rule.lower())
            contest_id = admin.new_contest("p16 重测时删除 " + rule, rule=rule, problems=str(problem_id))
            for pupil in (cheat, honest):
                pupil.register_for_contest(contest_id)
            uoj.move_contest(contest_id, -60, 600)
            copied = cheat.submit_in_contest(contest_id, problem_id, AB)
            own = honest.submit_in_contest(contest_id, problem_id, AB.replace("a + b", "a < 1000000 ? a + b : 0"))
            uoj.wait_idle()
            uoj.move_contest(contest_id, -7200, 60)
            self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "", rule)
            cells = lambda: db("select submitter, submission_id, score, penalty, attempts from contests_submissions where contest_id = %d order by submitter" % contest_id)
            honest_cells = [cell for cell in cells() if cell[0] == honest.username]
            self.assertEqual([cell[1] for cell in honest_cells], [str(own)], rule)
            due = lambda: db_value("select standings_update_due from contests where id = %d" % contest_id)

            with uoj.judgers_paused():
                self.assertEqual(admin.submit_form("/submission/%d" % own, "rejudge"), "", rule)
                self.assertEqual(db_value("select status from submissions where id = %d" % own), "Waiting Rejudge", rule)
                self.assertEqual(admin.submit_form("/submission/%d" % copied, "delete"), "", rule)
                # what was deleted is off the board at once; what is judged again keeps its result
                self.assertEqual(cells(), honest_cells, rule)
                self.assertEqual(due(), "1", rule)
                # site:tick leaves it for the judgement as well
                docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php site:tick")
                self.assertEqual((cells(), due()), (honest_cells, "1"), rule)
            # once it is judged, the board is made again of what is left
            uoj.wait_until("the board of the %s contest is made again" % rule, lambda: due() == "0", timeout=180)
            self.assertEqual(cells(), honest_cells, rule)
            ranks = db("select username, `rank` from contests_registrants where contest_id = %d order by username" % contest_id)
            self.assertEqual(ranks, [[cheat.username, ranks_after[0]], [honest.username, ranks_after[1]]], rule)


def site_tick():
    """what the web server does every minute"""
    return docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php site:tick")


def told(title, about):
    """how many messages with this title about this the administrators were sent"""
    return int(db_value("select count(*) from user_system_msg where title = '%s' and content like '%%%s%%'" % (title, about)))


class AlertEscalationTest(unittest.TestCase):
    """When one judger was gone and then all of them were, the alert of all of them was opened,
    and the alert of the one closed, with a message that it was back: it was not."""

    def test_a_judger_gone_before_the_others_is_not_told_back(self):
        started = db_value("select now()")
        names = ", ".join("'%s'" % name for name in uoj.JUDGER_NAMES)
        alert = lambda: db("select ifnull(active_slot, 'closed'), clear_since is null from site_alerts where kind = 'judger_silent' and subject = 'p16_flaky' order by id desc limit 1")
        db("insert into judger_info (judger_name, password, ip, enabled, last_heartbeat_at) values ('p16_flaky', 'p16-not-a-password', '', 1, now() - interval 10 minute)")
        try:
            with uoj.judgers_paused():
                # the others answer, p16_flaky does not
                db("update judger_info set last_heartbeat_at = now() where judger_name in (%s)" % names)
                site_tick()
                self.assertEqual(alert(), [["1", "1"]])
                self.assertEqual(told("告警：评测机离线", "p16_flaky"), 1)
                # and then none of them does
                db("update judger_info set last_heartbeat_at = now() - interval 10 minute where judger_name in (%s)" % names)
                site_tick()
                self.assertEqual(db_value("select count(*) from site_alerts where kind = 'no_judger' and active_slot = 1 and clear_since is null"), "1")
                # p16_flaky is not back: its alert is open, and nobody is told it is over
                self.assertEqual(alert(), [["1", "1"]])
                self.assertEqual(told("已恢复：评测机离线", "p16_flaky"), 0)
                site_tick()
                self.assertEqual((alert(), told("告警：评测机离线", "p16_flaky")), ([["1", "1"]], 1))
        finally:
            db("delete from judger_info where judger_name = 'p16_flaky'")
            db("update judger_info set last_heartbeat_at = now() where judger_name in (%s)" % names)
            # what this made go wrong is over
            db("update site_alerts set resolved_at = now(), active_slot = null where active_slot = 1 and kind in ('no_judger', 'judger_silent') and started_at >= '%s'" % started)


if __name__ == "__main__":
    unittest.main()
