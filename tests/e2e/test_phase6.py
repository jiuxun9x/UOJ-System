"""End-to-end tests of phase 6: run-twice problems, numbers of problems inside a domain, the
switch for blogs, what a contest shows while it runs, the ICPC rule, and the forms that make a
problem or a contest in one go.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import test_phase3 as p3
import test_phase4 as p4
import uoj
from fixtures import *
from uoj import db, db_value, docker_exec


def setUpModule():
    uoj.admin()


class RunTwiceTest(unittest.TestCase):
    """a program is run twice, and its second run knows of the first what the relay tells it"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(run_twice_problem_files())

    def judge(self, code, language="C++17"):
        return uoj.wait_submission(self.admin.submit(self.problem_id, code, language))

    def test_relay_is_built_by_a_judger(self):
        prepare, status, judger = db(
            "select prepare, status, judger_name from problem_data_versions where problem_id = %d" % self.problem_id
        )[0]
        self.assertEqual(
            sorted(step["name"] for step in json.loads(prepare)), ["chk", "relay"]
        )
        self.assertEqual(status, "ready")
        container = uoj.JUDGERS[uoj.JUDGER_NAMES.index(judger)]
        built = docker_exec(container, "ls /opt/uoj_judger/uoj_judger/data/%d | grep relay" % self.problem_id).split()
        self.assertEqual(sorted(built), ["relay", "relay.cpp"])

    def test_second_run_is_given_what_the_relay_made_of_the_first(self):
        # the relay hands the messages over in another order, which only its notes tell the checker
        j = self.judge(messages_solution())
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 2, j)
        self.assertIn("3 numbers", j.details)

    def test_relay_can_refuse_what_the_first_run_wrote(self):
        j = self.judge(messages_solution(width=41))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)
        self.assertIn("message 1 is longer than 40 characters", j.details)

    def test_second_run_that_dies(self):
        j = self.judge(messages_solution(second="return 3;"))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Runtime Error"] * 2, j)
        self.assertIn("in the second run", j.details)

    def test_first_run_that_dies(self):
        j = self.judge(messages_solution(burn='if (strcmp(run, "first") == 0) return 3;'))
        self.assertEqual(j.infos, ["Runtime Error"] * 2, j)
        self.assertIn("in the first run", j.details)

    def test_each_run_has_the_time_limit_to_itself(self):
        # Six tenths of a second in each run, with a limit of one second. The clock is asked
        # now and then only: asking is a system call, and the time that counts is the time
        # the program computes.
        burn = ("volatile unsigned long long spin = 0; "
                "while (clock() < CLOCKS_PER_SEC * 6 / 10) for (int i = 0; i < 1000000; i++) spin = spin + 1;")  # fmt: skip
        j = self.judge(messages_solution(burn=burn))
        self.assertEqual(j.score, 100, j)
        # and the time of a test is that of its longer run, not of both: two tests of it
        self.assertGreaterEqual(j.used_time, 2 * 450, j)
        self.assertLess(j.used_time, 2 * 1000, j)

    def test_first_run_can_not_leave_a_file(self):
        j = self.judge(MESSAGES_STASH)
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Dangerous Syscalls"] * 2, j)

    def test_second_run_can_not_look_at_what_the_first_wrote(self):
        j = self.judge(MESSAGES_STAT_RESULT_FOLDER)
        self.assertEqual(j.score, 0, j)
        self.assertIn(j.infos[1], ("Wrong Answer", "Dangerous Syscalls"), j)
        self.assertNotIn("found 7", j.details)

    def test_work_folder_tells_the_second_run_nothing(self):
        # A program may list its work folder and see how large everything in it is. This one
        # does, in its second run, and looks for a file as large as its first run made it.
        j = self.judge(MESSAGES_LIST_WORK_FOLDER, "Python3")
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos[1], "Wrong Answer", j)
        self.assertIn("expected 7, found -1", j.details)

    def test_builtin_checker_reads_the_answer_file_when_the_relay_wrote_no_notes(self):
        problem_id = self.admin.create_problem(run_twice_plain_problem_files())
        j = uoj.wait_submission(self.admin.submit(problem_id, ECHO_THEN_DOUBLE))
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 2 + ["Extra Test Passed"], j)
        j = uoj.wait_submission(self.admin.submit(problem_id, ECHO_THEN_DOUBLE.replace("2 * n", "3 * n")))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)

    def test_run_twice_problem_can_not_be_hacked(self):
        problem_id = self.admin.create_problem(run_twice_plain_problem_files())
        r = self.admin.upload_data(problem_id, {"std.cpp": ECHO_THEN_DOUBLE, "val.cpp": ACCEPT_ANYTHING})
        self.assertIn("上传成功", r.text)
        err = self.admin.toggle_hackable(problem_id)
        self.assertIn("hackable", err)
        self.assertEqual(db_value("select hackable from problems where id = %d" % problem_id), "0")

    def test_run_twice_problem_without_a_relay_is_not_synced(self):
        files = run_twice_plain_problem_files()
        del files["relay.cpp"]
        problem_id = self.admin.new_problem()
        self.admin.upload_data(problem_id, files)
        self.assertIn("relay", self.admin.sync(problem_id))


class DomainContestRatingTest(unittest.TestCase):
    """what happens in a domain stays out of the ratings of the site"""

    def test_contest_of_a_domain_counts_for_no_rating(self):
        admin = uoj.admin()
        teacher = p3.account("p6_rate_teacher")
        self.assertEqual(admin.change_user("p6_rate_teacher", "grant:teacher"), "")
        did = teacher.new_domain("p6-rating")
        pupils = [p3.account("p6_rate_pupil%d" % n) for n in range(2)]
        for n in range(2):
            self.assertEqual(p4.member_form(teacher, "p6-rating", "add", username="p6_rate_pupil%d" % n, role="member"), "")
        self.assertEqual(teacher.form("/d/p6-rating/problems", "new"), "")
        own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
        self.assertIn("上传成功", teacher.upload_data(own_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(own_id), "")
        contest_id = teacher.new_contest("p6 域内赛", domain="p6-rating", problems=str(uoj.pid(own_id)))
        self.assertEqual(db_value("select domain_id from contests where id = %d" % contest_id), str(did))
        self.assertEqual(db("select problem_id from contests_problems where contest_id = %d" % contest_id), [[str(own_id)]])

        # the administrator of the site is not offered the box, ticks it in vain, and a setting
        # that says the contest is rated is not believed, whoever wrote it
        page = admin.get("/contest/%d/manage" % contest_id).text
        self.assertNotIn('name="rated"', page)
        self.assertIn("域内的比赛不计入 Rating", page)
        self.assertEqual(admin.contest_settings(contest_id, rated="on"), "")
        self.assertIn("unrated", db_value("select extra_config from contests where id = %d" % contest_id))
        db("update contests set extra_config = '{}' where id = %d" % contest_id)
        for pupil in pupils:
            pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        uoj.wait_submission(pupils[0].submit_in_contest(contest_id, own_id, AB))
        uoj.wait_submission(pupils[1].submit_in_contest(contest_id, own_id, AB_WRONG))
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "start_test"), "")
        uoj.wait_idle()
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "finished")

        for n in range(2):
            name = "p6_rate_pupil%d" % n
            self.assertEqual(db_value("select rating from user_info where username = '%s'" % name), "1500")
            self.assertEqual(db_value("select count(*) from user_system_msg where receiver = '%s' and title like 'Rating%%'" % name), "0")
            # and the history of the rating on the profile does not tell of the contest
            profile = admin.get("/user/profile/" + name).text
            self.assertNotIn("p6 域内赛", profile)
        self.assertIn('"rated":false', db_value(
            "select after_json from audit_logs where action = 'contest.publish_results' and resource_id = '%d'" % contest_id
        ))  # fmt: skip
        # the standings of the contest are there all the same
        self.assertEqual(
            db("select username, `rank` from contests_registrants where contest_id = %d order by `rank`" % contest_id),
            [["p6_rate_pupil0", "1"], ["p6_rate_pupil1", "2"]],
        )


def submit_and_follow(client, path, code):
    """submit a program to the problem at an address: where the site sends the user afterwards"""
    r = client.post(path, {
        "submit-answer": "answer",
        "answer_answer_upload_type": "editor",
        "answer_answer_editor": code,
        "answer_answer_language": "C++17",
    })  # fmt: skip
    assert r.status_code in (301, 302), r.status_code
    return r.headers["Location"]


def last_submission(username):
    return int(db_value("select max(id) from submissions where submitter = '%s'" % username))


def board(client, contest_id, query=""):
    """the board of an ICPC contest as somebody sees it: username => rank, solved, penalty in
    seconds, and the class of the cell of every problem that has one"""
    page = client.get("/contest/%d/standings%s" % (contest_id, query))
    assert page.status_code == 200, page.status_code
    rows = {}
    for name, rank, solved, penalty, cells in re.findall(
        r'(?s)<tr data-username="([^"]+)" data-rank="(\d+)" data-solved="(\d+)" data-penalty="(\d+)">(.*?)</tr>', page.text
    ):
        rows[name] = (int(rank), int(solved), int(penalty), dict(
            (letter, kind) for kind, letter in re.findall(r'<td class="uoj-icpc-(\w+)" data-problem="(\w)"', cells)
        ))  # fmt: skip
    return rows, page.text


class AfterSubmittingTest(unittest.TestCase):
    """where somebody is taken after submitting: to what they submitted, where they submitted it"""

    def test_submitting_to_a_homework_leads_to_what_one_submitted_to_it(self):
        admin = uoj.admin()
        site_problem = admin.create_problem(ab_problem_files())
        teacher, pupil, other = (p3.account("p6_hw_" + name) for name in ("teacher", "pupil", "other"))
        self.assertEqual(admin.change_user("p6_hw_teacher", "grant:teacher"), "")
        slug = "p6-homework"
        did = teacher.new_domain(slug)
        for name in ("pupil", "other"):
            self.assertEqual(p4.member_form(teacher, slug, "add", username="p6_hw_" + name, role="member"), "")
        self.assertEqual(teacher.form("/d/%s/problems" % slug, "new"), "")
        own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
        self.assertIn("上传成功", teacher.upload_data(own_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(own_id), "")
        db("update problems set is_hidden = 0 where id = %d" % own_id)
        homework_id = p4.new_homework(teacher, slug, title="p6 作业")
        self.assertEqual(p4.homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(uoj.pid(own_id)), score="100"), "")
        self.assertEqual(p4.homework_form(teacher, slug, homework_id, "publish"), "")
        uoj.wait_until("published", lambda: p4.tick() and p4.homework_row(homework_id, "status")[0] == "published")
        for client in (pupil, other):
            self.assertEqual(client.form("/d/%s/homework/%d" % (slug, homework_id), "claim"), "")

        # in a homework: the list of what one submitted to the homework
        in_homework = "/d/%s/homework/%d/problem/%d" % (slug, homework_id, uoj.pid(own_id))
        mine = "/submissions?homework_id=%d&submitter=p6_hw_pupil" % homework_id
        self.assertEqual(submit_and_follow(pupil, in_homework, AB_WRONG), mine)
        submission_id = last_submission("p6_hw_pupil")
        uoj.wait_submission(submission_id)
        listing = pupil.get(mine)
        self.assertEqual(listing.status_code, 200)
        self.assertIn('href="/submission/%d"' % submission_id, listing.text)
        self.assertIn('id="submissions-of-homework"', listing.text)
        self.assertIn("p6 作业", listing.text)
        # and how it did on every test, as when practising
        page = pupil.get("/submission/%d" % submission_id).text
        self.assertIn("Test #", page)
        self.assertIn("Wrong Answer", page)
        # what the others submitted to it is not there while it runs
        self.assertNotIn('href="/submission/%d"' % submission_id, other.get("/submissions?homework_id=%d" % homework_id).text)
        self.assertIn('href="/submission/%d"' % submission_id, teacher.get("/submissions?homework_id=%d" % homework_id).text)
        # a homework nobody may see filters nothing, and names nothing
        stranger = p3.account("p6_hw_stranger")
        listing = stranger.get("/submissions?homework_id=%d" % homework_id).text
        self.assertNotIn("p6 作业", listing)
        self.assertNotIn('id="submissions-of-homework"', listing)

        # outside of the homework, in the domain: what one submitted to the problem
        in_domain = "/d/%s/problem/%d" % (slug, uoj.pid(own_id))
        self.assertEqual(submit_and_follow(pupil, in_domain, AB), "/submissions?problem_id=%d&submitter=p6_hw_pupil" % own_id)
        practice = last_submission("p6_hw_pupil")
        self.assertIn('href="/submission/%d"' % practice, pupil.get("/submissions?problem_id=%d&submitter=p6_hw_pupil" % own_id).text)
        # and on the site as ever
        self.assertEqual(submit_and_follow(pupil, "/problem/%d" % site_problem, AB), "/submissions")
        uoj.wait_idle()


class ContestFeedbackTest(unittest.TestCase):
    """what somebody is told about what they submitted: in a contest, in a homework, in a domain"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(ab_problem_files())

    def contest(self, name, rule="OI"):
        return self.admin.new_contest(name, rule=rule, problems=str(self.problem_id))

    def test_nobody_is_told_about_single_tests_while_a_contest_runs(self):
        for rule in ("OI", "IOI"):
            contest_id = self.contest("p6 反馈 " + rule, rule)
            pupil = p3.account("p6_fb_" + rule.lower())
            pupil.register_for_contest(contest_id)
            uoj.move_contest(contest_id, -60, 600)
            here = "/contest/%d" % contest_id
            # after submitting, the list of what one submitted in the contest
            self.assertEqual(submit_and_follow(pupil, "%s/problem/%d" % (here, self.problem_id), AB_WRONG), here + "/submissions")
            wrong = last_submission(pupil.username)
            broken = pupil.submit_in_contest(contest_id, self.problem_id, AB_COMPILE_ERROR)
            for submission_id in (wrong, broken):
                uoj.wait_submission(submission_id)
            uoj.wait_idle()

            listing = pupil.get(here + "/submissions").text
            self.assertIn('href="/submission/%d"' % wrong, listing)
            page = pupil.get("/submission/%d" % wrong).text
            # the program is there, how it did on the tests is not
            self.assertIn("a + b + 1", page)
            self.assertIn('id="details-after-contest"', page)
            for told in ("Wrong Answer", "details_details_accordion"):
                self.assertNotIn(told, page, (rule, told))
            # what the compiler said is
            page = pupil.get("/submission/%d" % broken).text
            self.assertIn('id="compile-error"', page)
            self.assertIn("undeclared", page)
            # the staff sees everything
            self.assertIn("details_details_accordion", self.admin.get("/submission/%d" % wrong).text)
            self.assertIn("此次比赛为 %s 赛制" % rule, uoj.text_of(pupil.get(here).text))

            # when the contest is over, so does the owner
            uoj.move_contest(contest_id, -7200, 60)
            page = pupil.get("/submission/%d" % wrong).text
            self.assertIn("details_details_accordion", page)
            self.assertIn("Wrong Answer", page)
            self.assertNotIn('id="details-after-contest"', page)

    def test_final_test_is_for_contests_that_judged_with_the_samples(self):
        # an OI contest judges with the samples while it runs, and with everything afterwards
        oi, ioi = self.contest("p6 终测 OI"), self.contest("p6 终测 IOI", "IOI")
        pupil = p3.account("p6_fb_final")
        for contest_id in (oi, ioi):
            pupil.register_for_contest(contest_id)
            uoj.move_contest(contest_id, -60, 600)
        in_oi = pupil.submit_in_contest(oi, self.problem_id, AB)
        in_ioi = pupil.submit_in_contest(ioi, self.problem_id, AB)
        self.assertIn("final_test_config", db_value("select content from submissions where id = %d" % in_oi))
        self.assertNotIn("final_test_config", db_value("select content from submissions where id = %d" % in_ioi))
        uoj.wait_idle()
        self.assertEqual(len(uoj.get_submission(in_ioi).infos), 4)
        for contest_id in (oi, ioi):
            uoj.move_contest(contest_id, -7200, 60)
        # the results of the one are published after its final test
        page = self.admin.get("/contest/%d" % oi).text
        self.assertIn('id="form-start_test"', page)
        self.assertNotIn('id="form-publish_result"', page)
        self.assertEqual(self.admin.submit_form("/contest/%d" % oi, "start_test"), "")
        uoj.wait_idle()
        self.assertEqual(self.admin.submit_form("/contest/%d" % oi, "publish_result"), "")
        # the results of the other as they are
        page = self.admin.get("/contest/%d" % ioi).text
        self.assertNotIn('id="form-start_test"', page)
        self.assertIn('id="form-publish_result"', page)
        judged = db_value("select judge_time from submissions where id = %d" % in_ioi)
        self.assertEqual(self.admin.submit_form("/contest/%d" % ioi, "publish_result"), "")
        self.assertEqual(db_value("select judge_time from submissions where id = %d" % in_ioi), judged)
        for contest_id in (oi, ioi):
            self.assertEqual(db("select status from contests where id = %d" % contest_id), [["finished"]])
            self.assertEqual(db("select score from contests_submissions where contest_id = %d" % contest_id), [["100"]])


class IcpcTest(unittest.TestCase):
    """the ICPC rule: solved problems and penalty, and a board that freezes"""

    def test_icpc_contest_from_its_board_to_its_results(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p6 ICPC 校赛", minutes=300, rule="ICPC", freeze_minutes="60", problems="%d, %d" % (first, second))
        self.assertEqual(db("select freeze_minutes, join_mode from contests where id = %d" % contest_id), [["60", "open"]])
        ann, bob, cat, outsider = (p3.account("p6_icpc_" + name) for name in ("ann", "bob", "cat", "outsider"))
        for client in (ann, bob, cat):
            client.register_for_contest(contest_id)
        here = "/contest/%d" % contest_id
        # two hundred and fifty minutes into its three hundred: the last sixty are frozen
        uoj.move_contest(contest_id, -250 * 60, 300)
        self.assertIn("最后 60 分钟封榜", uoj.text_of(ann.get(here).text))

        def at(minutes, submission_id):
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute)"
               " where id = %d" % (contest_id, minutes, submission_id))  # fmt: skip
            return submission_id

        # before the board froze: ann fails A and solves it, bob solves A at once and fails B
        ann_wrong = at(10, ann.submit_in_contest(contest_id, first, AB_WRONG))
        ann_right = at(20, ann.submit_in_contest(contest_id, first, AB))
        bob_first = at(30, bob.submit_in_contest(contest_id, first, AB))
        at(50, bob.submit_in_contest(contest_id, second, AB_WRONG))
        # after it froze: bob solves B, cat solves A
        bob_second = bob.submit_in_contest(contest_id, second, AB)
        cat_first = cat.submit_in_contest(contest_id, first, AB)
        uoj.wait_idle()
        # every submission was judged with all the data
        self.assertNotIn("final_test_config", db_value("select content from submissions where id = %d" % ann_right))
        self.assertEqual(uoj.get_submission(ann_right).infos, ["Accepted"] * 3 + ["Extra Test Passed"])

        # ---- what a contestant is told: passed or not, and nothing about the tests
        listing = ann.get(here + "/submissions").text
        self.assertRegex(listing, r'href="/submission/%d" class="uoj-verdict text-success"><strong>Accepted' % ann_right)
        self.assertRegex(listing, r'href="/submission/%d" class="uoj-verdict text-danger"><strong>Wrong Answer' % ann_wrong)
        page = ann.get("/submission/%d" % ann_wrong).text
        self.assertIn('id="details-after-contest"', page)
        self.assertNotIn("Test #", page)
        # also after the board froze, about what is one's own
        self.assertRegex(cat.get(here + "/submissions").text, r'href="/submission/%d" class="uoj-verdict text-success"' % cat_first)

        # ---- the board: frozen for the contestants, whole for the staff
        for client in (ann, cat):
            rows, page = board(client, contest_id)
            self.assertIn('id="standings-frozen"', page)
            self.assertIn('data-frozen="1"', page)
            # bob is ahead with less penalty, though ann solved A before him
            self.assertEqual(rows["p6_icpc_bob"], (1, 1, 30 * 60, {"A": "solved", "B": "pending"}), client.username)
            self.assertEqual(rows["p6_icpc_ann"], (2, 1, 20 * 60 + 1200, {"A": "first"}))
            self.assertEqual(rows["p6_icpc_cat"], (3, 0, 0, {"A": "pending"}))
            self.assertIn("1 + 1", page)
            self.assertNotIn("/submission/%d" % bob_second, page)
        rows, page = board(admin, contest_id)
        self.assertNotIn('data-frozen="1"', page)
        self.assertEqual(rows["p6_icpc_bob"][:2], (1, 2))
        self.assertEqual(rows["p6_icpc_bob"][3], {"A": "solved", "B": "first"})
        self.assertEqual(rows["p6_icpc_ann"], (2, 1, 20 * 60 + 1200, {"A": "first"}))
        self.assertEqual(rows["p6_icpc_cat"][:2], (3, 1))
        # the staff can look at what the contestants see
        rows, page = board(admin, contest_id, "?frozen=1")
        self.assertEqual(rows["p6_icpc_cat"], (3, 0, 0, {"A": "pending"}))
        # nobody sees what the others submitted
        for client in (ann, outsider):
            self.assertEqual(client.get("/submission/%d" % cat_first).status_code, 403)
            self.assertNotIn('href="/submission/%d"' % cat_first, client.get("/submissions?problem_id=%d" % first).text)

        # ---- the contest is over, and the board stays frozen until the results are published
        uoj.move_contest(contest_id, -400 * 60, 300)
        for minutes, submission_id in ((10, ann_wrong), (20, ann_right), (30, bob_first), (251, bob_second), (252, cat_first)):
            at(minutes, submission_id)
        db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval 50 minute)"
           " where contest_id = %d and submitter = 'p6_icpc_bob' and problem_id = %d and score < 100" % (contest_id, contest_id, second))  # fmt: skip
        # the contest is open to everybody now, and the board is what it was for all of them
        for client in (ann, outsider):
            rows, page = board(client, contest_id)
            self.assertEqual(rows["p6_icpc_cat"], (3, 0, 0, {"A": "pending"}), client.username)
            self.assertEqual(rows["p6_icpc_bob"], (1, 1, 30 * 60, {"A": "solved", "B": "pending"}))
            self.assertEqual(client.get("/submission/%d" % cat_first).status_code, 403)
        self.assertIn("比赛尚未结束", ann.get("%s/problem/%d/statistics" % (here, first)).text)
        # her own submissions are hers to look into now
        self.assertIn("Test #", ann.get("/submission/%d" % ann_wrong).text)

        # ---- publishing: there is no final test, and nothing is published before everything is judged
        page = admin.get(here).text
        self.assertNotIn('id="form-start_test"', page)
        self.assertIn('id="form-publish_result"', page)
        with uoj.judgers_paused():
            db("update submissions set status = 'Waiting' where id = %d" % cat_first)
            self.assertIn("还有 1 个提交没有评测完", admin.submit_form(here, "publish_result"))
            self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "unfinished")
            db("update submissions set status = 'Judged' where id = %d" % cat_first)
        self.assertEqual(admin.submit_form(here, "publish_result"), "")
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "finished")

        # ---- the results: what counted is kept, with the attempts
        kept = db("select submitter, problem_id, score, penalty, attempts, submission_id from contests_submissions"
                  " where contest_id = %d order by submitter, problem_id" % contest_id)  # fmt: skip
        self.assertEqual(kept, [
            ["p6_icpc_ann", str(first), "100", str(20 * 60 + 1200), "1", str(ann_right)],
            ["p6_icpc_bob", str(first), "100", str(30 * 60), "0", str(bob_first)],
            ["p6_icpc_bob", str(second), "100", str(251 * 60 + 1200), "1", str(bob_second)],
            ["p6_icpc_cat", str(first), "100", str(252 * 60), "0", str(cat_first)],
        ])  # fmt: skip
        self.assertEqual(
            db("select username, `rank` from contests_registrants where contest_id = %d order by `rank`" % contest_id),
            [["p6_icpc_bob", "1"], ["p6_icpc_ann", "2"], ["p6_icpc_cat", "3"]],
        )
        for client in (ann, outsider, admin):
            rows, page = board(client, contest_id)
            self.assertNotIn('id="standings-frozen"', page)
            self.assertEqual(rows["p6_icpc_bob"], (1, 2, 30 * 60 + 251 * 60 + 1200, {"A": "solved", "B": "first"}))
            self.assertEqual(rows["p6_icpc_ann"], (2, 1, 20 * 60 + 1200, {"A": "first"}))
            self.assertEqual(rows["p6_icpc_cat"], (3, 1, 252 * 60, {"A": "solved"}))
        self.assertEqual(ann.get("/submission/%d" % cat_first).status_code, 200)
        self.assertNotIn("比赛尚未结束", ann.get("%s/problem/%d/statistics" % (here, first)).text)
        export = admin.get(here + "/export_standings").text
        self.assertIn("A_failed_attempts", export)

        # ---- somebody who sits it again is counted by the same rule
        sitter = p3.account("p6_icpc_sitter")
        self.assertEqual(sitter.form(here + "/virtual", "start"), "")
        again = "%s/problem/%d" % (here, first)
        wrong = sitter.submit(first, AB_WRONG, path=again)
        right = sitter.submit(first, AB, path=again)
        for submission_id in (wrong, right):
            uoj.wait_submission(submission_id)
        # twenty five minutes into it, with the failed attempt five minutes before
        db("update contest_virtuals set start_time = '%s' where contest_id = %d" % (uoj.web_time(-25 * 60), contest_id))
        db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-5 * 60), wrong))
        db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-60), right))
        page = sitter.get(here + "/virtual?tab=standings").text
        self.assertIn("通过 / 罚时", page)
        mine = re.search(r'(?s)<tr class="table-info" id="virtual-my-row"[^>]*data-rank="(\d+)">(.*?)</tr>', page)
        self.assertEqual(mine.group(1), "2")
        self.assertIn('class="uoj-icpc-solved"', mine.group(2))
        self.assertIn(">+1<", mine.group(2))
        # ann solved it after twenty minutes, bob will after thirty: he has nothing yet
        self.assertRegex(page, r'data-username="p6_icpc_ann" data-rank="1"')
        self.assertRegex(page, r'data-username="p6_icpc_bob" data-rank="3"')
        uoj.wait_idle()


class ContestFormTest(unittest.TestCase):
    """a contest is made with one form and changed with one form"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = p3.account("p6_form_teacher")
        assert cls.admin.change_user("p6_form_teacher", "grant:teacher") == ""
        cls.mine = [cls.teacher.new_problem() for _ in range(3)]
        cls.foreign = cls.admin.new_problem()

    def config(self, contest_id):
        return json.loads(db_value("select extra_config from contests where id = %d" % contest_id))

    def problems(self, contest_id):
        return [int(row[0]) for row in db("select problem_id from contests_problems where contest_id = %d order by position, problem_id" % contest_id)]

    def test_contest_is_made_with_everything_it_needs(self):
        teacher, (first, second, third) = self.teacher, self.mine
        contests = lambda: int(db_value("select count(*) from contests"))
        before = contests()
        form = dict(name="p6 一次建好", start_time=uoj.web_time(86400)[:16].replace(" ", "T"), last_min="150", rule="ICPC",
                    freeze_minutes="30", join_mode="password", join_password="open sesame")  # fmt: skip
        page = teacher.get("/contest/new").text
        for field in ('name="name"', 'type="datetime-local"', 'name="last_min"', 'value="OI"', 'value="IOI"', 'value="ICPC"',
                      'name="freeze_minutes"', 'name="join_mode"', 'name="problems"'):  # fmt: skip
            self.assertIn(field, page, field)
        # what is wrong is said, and nothing is made of the rest
        for wrong, said in ((dict(name=" "), "比赛名称"), (dict(start_time="next week"), "开始时间"), (dict(last_min="0"), "时长"),
                            (dict(rule="ACM"), "赛制"), (dict(freeze_minutes="151"), "封榜"), (dict(join_password=""), "参赛密码"),
                            (dict(problems="%d %d" % (first, self.foreign)), "#%d 的管理者" % self.foreign),
                            (dict(problems="%d, %d" % (first, first)), "写了两次"), (dict(problems="999999"), "没有题号为 999999")):  # fmt: skip
            self.assertEqual(p4.refusal(teacher, "/contest/new", "create", **dict(form, **wrong)).count(said), 1, wrong)
        self.assertEqual(contests(), before)
        # and what was typed is still in the form
        page = teacher.post("/contest/new", dict(form, form="create", name="p6 写了一半", problems="999999")).text
        self.assertIn('value="p6 写了一半"', page)
        self.assertRegex(page, r'value="ICPC" checked')

        # the problems are lettered in the order they are written in
        self.assertEqual(teacher.form("/contest/new", "create", problems="%d，%d %d" % (third, first, second), **form), "")
        contest_id = int(db_value("select max(id) from contests"))
        self.assertEqual(contests(), before + 1)
        self.assertEqual(
            db("select name, last_min, freeze_minutes, join_mode, status, ifnull(domain_id, 'NULL') from contests where id = %d" % contest_id),
            [["p6 一次建好", "150", "30", "password", "unfinished", "NULL"]],
        )
        self.assertEqual(db_value("select start_time from contests where id = %d" % contest_id), form["start_time"].replace("T", " ") + ":00")
        self.assertTrue(db_value("select join_password from contests where id = %d" % contest_id).startswith("$2y$"))
        self.assertEqual(self.config(contest_id)["contest_type"], "ICPC")
        self.assertIn("unrated", self.config(contest_id))
        self.assertEqual(self.problems(contest_id), [third, first, second])
        self.assertEqual(db("select username, role from contests_permissions where contest_id = %d" % contest_id), [["p6_form_teacher", "owner"]])
        self.assertEqual(db_value("select count(*) from audit_logs where after_json like '%open sesame%'"), "0")

        # ---- the page that manages it: one form for what it is
        manage = "/contest/%d/manage" % contest_id
        page = teacher.get(manage).text
        self.assertEqual(page.count('id="button-save-contest-settings"'), 1)
        self.assertRegex(page, r'value="ICPC" checked')
        self.assertIn('value="30"', page)
        self.assertNotIn("open sesame", page)
        self.assertEqual(teacher.contest_settings(contest_id, name="p6 改了名字", rule="OI", standings_version="1", last_min="200"), "")
        self.assertEqual(db("select name, last_min, freeze_minutes, join_mode from contests where id = %d" % contest_id),
                         [["p6 改了名字", "200", "0", "password"]])  # fmt: skip
        self.assertEqual((self.config(contest_id)["contest_type"], self.config(contest_id)["standings_version"]), ("OI", 1))
        self.assertNotEqual(teacher.contest_settings(contest_id, last_min="none"), "")
        self.assertEqual(db_value("select last_min from contests where id = %d" % contest_id), "200")

        # ---- its problems: added, moved and taken away one at a time
        letters = lambda: re.findall(r'<tr data-problem="(\d+)">\s*<td><strong>(\w)</strong>', teacher.get(manage).text)
        self.assertEqual(letters(), [(str(third), "A"), (str(first), "B"), (str(second), "C")])
        self.assertEqual(teacher.form(manage, "move_problem", problem_id=str(second), tab="problems"), "")
        self.assertEqual(self.problems(contest_id), [third, second, first])
        self.assertEqual(teacher.form(manage, "remove_problem", problem_id=str(third), tab="problems"), "")
        self.assertEqual(letters(), [(str(second), "A"), (str(first), "B")])
        self.assertNotEqual(teacher.form(manage, "add_problem", number=str(first), tab="problems"), "")
        self.assertNotEqual(teacher.form(manage, "add_problem", number=str(self.foreign), tab="problems"), "")
        self.assertEqual(teacher.form(manage, "add_problem", number=str(third), tab="problems"), "")
        self.assertEqual(self.problems(contest_id), [second, first, third])
        # under the OI rule a problem can be judged with everything while the contest runs
        self.assertEqual(teacher.form(manage, "judge_problem", problem_id=str(first), judged_with="everything", tab="problems"), "")
        self.assertEqual(self.config(contest_id)["problem_%d" % first], "full")
        self.assertEqual(teacher.form(manage, "judge_problem", problem_id=str(first), judged_with="samples", tab="problems"), "")
        self.assertNotIn("problem_%d" % first, self.config(contest_id))
        self.assertNotEqual(teacher.form(manage, "judge_problem", problem_id=str(self.foreign), judged_with="everything", tab="problems"), "")
        # the order the contest was given is the order on its pages
        pupil = p3.account("p6_form_pupil")
        self.assertEqual(pupil.submit_form("/contest/%d/register" % contest_id, "register", {"join_password": "open sesame"}), "")
        uoj.move_contest(contest_id, -60, 600)
        dashboard = pupil.get("/contest/%d" % contest_id).text
        order = [int(problem_id) for problem_id in re.findall(r'href="/contest/%d/problem/(\d+)"' % contest_id, dashboard)]
        self.assertEqual(order, [second, first, third])
        self.assertRegex(pupil.get("/contest/%d/problem/%d" % (contest_id, first)).text, r">\s*B\. ")

        # ---- the people who run it
        helper = p3.account("p6_form_helper")
        self.assertNotEqual(teacher.form(manage, "add_manager", username="p6_no_such_user", role="assistant", tab="managers"), "")
        self.assertEqual(teacher.form(manage, "add_manager", username="p6_form_helper", role="assistant", tab="managers"), "")
        self.assertEqual(helper.get("/contest/%d/backstage" % contest_id).status_code, 200)
        self.assertEqual(helper.get(manage).status_code, 403)
        self.assertEqual(teacher.form(manage, "add_manager", username="p6_form_helper", role="owner", tab="managers"), "")
        self.assertEqual(helper.get(manage).status_code, 200)
        self.assertEqual(teacher.form(manage, "remove_manager", username="p6_form_teacher", tab="managers"), "")
        self.assertEqual(teacher.get(manage).status_code, 403)
        # a contest is not left without anybody
        self.assertIn("最后一位负责人", p4.refusal(helper, manage, "remove_manager", username="p6_form_helper", tab="managers"))
        self.assertEqual(db("select username, role from contests_permissions where contest_id = %d" % contest_id), [["p6_form_helper", "owner"]])

    def test_only_the_people_who_may_make_contests_see_the_form(self):
        student = p3.account("p6_form_student")
        self.assertEqual(student.get("/contest/new").status_code, 403)
        before = db_value("select count(*) from contests")
        student.form("/contest/new", "create", name="x", start_time=uoj.web_time(3600), last_min="60", rule="OI", join_mode="open")
        self.assertEqual(db_value("select count(*) from contests"), before)
        # an administrator decides about ratings where the contest is made
        page = self.admin.get("/contest/new").text
        self.assertRegex(page, r'name="rated" checked')
        rated = self.admin.new_contest("p6 计分")
        unrated = self.admin.new_contest("p6 不计分", rated=None, rating_k="250")
        self.assertNotIn("unrated", self.config(rated))
        self.assertIn("unrated", self.config(unrated))
        self.assertEqual(self.config(unrated)["rating_k"], 250)


class BlogSwitchTest(unittest.TestCase):
    """blogs are closed unless the system administrator opens them; announcements stay"""

    def post(self, poster, title, announcement=False):
        db("insert into blogs (title, content, content_md, post_time, poster, zan, is_hidden, type, is_draft)"
           " values ('%s', '<p>text of %s</p>', 'text of %s', now(), '%s', 0, 0, 'B', 0)" % (title, title, title, poster))  # fmt: skip
        blog_id = int(db_value("select max(id) from blogs where poster = '%s'" % poster))
        if announcement:
            db("insert into important_blogs (blog_id, level) values (%d, 0)" % blog_id)
        return blog_id

    def comment(self, client, blog_id, poster):
        return client.submit_form("/blog/%s/post/%d" % (poster, blog_id), "comment", {"comment": "p6 comment by " + client.username})

    def test_blogs_are_closed_until_the_system_administrator_opens_them(self):
        import test_phase5 as p5

        admin, visitor = uoj.admin(), uoj.Client()
        writer, reader = p3.account("p6_blog_writer"), p3.account("p6_blog_reader")
        oj_admin = p3.account("p6_blog_ojadmin")
        self.assertEqual(admin.change_user("p6_blog_ojadmin", "grant:oj_admin"), "")
        self.assertIsNone(db_value("select value from site_settings where name = 'blog.enabled'"))
        news = self.post(uoj.ADMIN[0], "p6 期末安排", announcement=True)
        diary = self.post("p6_blog_writer", "p6 日记")
        comments = lambda: int(db_value("select count(*) from blogs_comments where blog_id in (%d, %d)" % (news, diary)))

        # ---- closed: no blogs in the navigation, nobody but the administrators has one
        for client in (reader, visitor):
            self.assertNotIn('href="/blogs"', client.get("/").text)
        r = reader.get("/blogs")
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/announcements"))
        for client in (writer, reader, visitor):
            for path in ("/", "/archive", "/post/%d" % diary, "/post/new/write"):
                self.assertEqual(client.get("/blog/p6_blog_writer" + path).status_code, 404, path)
        self.assertEqual(reader.get("/blogs/%d" % diary).headers.get("Location"), "/blog/p6_blog_writer/post/%d" % diary)
        self.assertNotIn("/blog/p6_blog_writer", reader.get("/user/profile/p6_blog_writer").text)
        # the announcements are there for everybody, and are not discussed
        for client in (reader, visitor):
            self.assertIn("p6 期末安排", client.get("/").text)
            page = client.get("/blog/%s/post/%d" % (uoj.ADMIN[0], news))
            self.assertEqual(page.status_code, 200)
            self.assertIn("text of p6 期末安排", page.text)
            self.assertIn('id="comments-closed"', page.text)
            self.assertNotIn('id="form-comment"', page.text)
        self.comment(reader, news, uoj.ADMIN[0])
        self.assertEqual(comments(), 0)
        # the administrators go on writing them
        self.assertEqual(admin.get("/blog/%s/post/new/write" % uoj.ADMIN[0]).status_code, 200)
        self.assertEqual(oj_admin.get("/blog/p6_blog_ojadmin/post/new/write").status_code, 200)

        # ---- the switch is the system administrator's
        settings = "/super-manage/settings"
        self.assertEqual(oj_admin.get(settings).status_code, 403)
        p5.site_settings(oj_admin, blog_enabled=True)
        self.assertIsNone(db_value("select value from site_settings where name = 'blog.enabled'"))
        self.assertIn("开放用户博客", admin.get(settings).text)
        self.assertEqual(p5.site_settings(admin, blog_enabled=True), "")
        try:
            # ---- open: everybody has a blog, and posts are discussed
            self.assertIn('href="/blogs"', reader.get("/").text)
            self.assertEqual(reader.get("/blogs").status_code, 200)
            self.assertIn("p6 日记", reader.get("/blogs").text)
            self.assertEqual(reader.get("/blog/p6_blog_writer/post/%d" % diary).status_code, 200)
            self.assertEqual(writer.get("/blog/p6_blog_writer/post/new/write").status_code, 200)
            self.assertIn("/blog/p6_blog_writer", reader.get("/user/profile/p6_blog_writer").text)
            page = reader.get("/blog/%s/post/%d" % (uoj.ADMIN[0], news)).text
            self.assertIn('id="form-comment"', page)
            self.assertNotIn('id="comments-closed"', page)
            self.assertEqual(self.comment(reader, diary, "p6_blog_writer"), "")
            self.assertEqual(comments(), 1)
        finally:
            self.assertEqual(p5.site_settings(admin, blog_enabled=False), "")
        # ---- closed again: what was written is kept, and is out of sight
        self.assertEqual(reader.get("/blog/p6_blog_writer/post/%d" % diary).status_code, 404)
        self.assertEqual(db_value("select count(*) from blogs where id = %d" % diary), "1")
        self.assertEqual(comments(), 1)
        self.assertEqual(
            db("select action, actor from audit_logs where resource_type = 'site_setting' and resource_id = 'blog.enabled' order by id"),
            [["site.edit_setting", uoj.ADMIN[0]]] * 2,
        )


if __name__ == "__main__":
    unittest.main()
