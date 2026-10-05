"""End-to-end tests of phase 6: multi-pass problems, numbers of problems inside a domain, the
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


class MultiPassTest(unittest.TestCase):
    """a program is run on a test more than once, and a later pass knows of an earlier one what
    the checker tells it"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(multi_pass_problem_files())

    def judge(self, code, language="C++17"):
        return uoj.wait_submission(self.admin.submit(self.problem_id, code, language))

    def test_checker_is_built_by_a_judger_and_nothing_else_is_needed(self):
        prepare, status = db("select prepare, status from problem_data_versions where problem_id = %d" % self.problem_id)[0]
        self.assertEqual([step["name"] for step in json.loads(prepare)], ["chk"])
        self.assertEqual(status, "ready")
        self.assertEqual(published_conf(self.problem_id)["multi_pass"], "2")

    def test_second_pass_is_given_what_the_checker_made_of_the_first(self):
        # the checker hands the messages over in another order, and remembers the numbers itself
        j = self.judge(messages_solution())
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 2, j)
        self.assertIn("3 numbers", j.details)

    def test_checker_can_refuse_what_the_first_pass_wrote(self):
        j = self.judge(messages_solution(width=41))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)
        self.assertIn("message 1 is longer than 40 characters", j.details)

    def test_pass_that_dies_is_said_to_be_the_one(self):
        j = self.judge(messages_solution(second="return 3;"))
        self.assertEqual((j.score, j.infos), (0, ["Runtime Error"] * 2), j)
        self.assertIn("in pass 2", j.details)
        j = self.judge(messages_solution(burn='if (strcmp(run, "first") == 0) return 3;'))
        self.assertEqual(j.infos, ["Runtime Error"] * 2, j)
        self.assertIn("in pass 1", j.details)

    def test_each_pass_has_the_time_limit_to_itself(self):
        # Six tenths of a second in each pass, with a limit of one second. The clock is asked
        # now and then only: asking is a system call, and the time that counts is the time
        # the program computes.
        burn = ("volatile unsigned long long spin = 0; "
                "while (clock() < CLOCKS_PER_SEC * 6 / 10) for (int i = 0; i < 1000000; i++) spin = spin + 1;")  # fmt: skip
        j = self.judge(messages_solution(burn=burn))
        self.assertEqual(j.score, 100, j)
        # and the time of a test is that of its longest pass, not of all of them: two tests of it
        self.assertGreaterEqual(j.used_time, 2 * 450, j)
        self.assertLess(j.used_time, 2 * 1000, j)

    # ---- nothing but what the checker hands on reaches a later pass

    def test_first_pass_can_not_leave_a_file(self):
        j = self.judge(MESSAGES_STASH)
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Dangerous Syscalls"] * 2, j)

    def test_later_pass_can_not_get_at_what_the_checker_keeps(self):
        # The checker remembers the very numbers in state.txt, and what the first pass wrote
        # has a size that says them. A second pass that looks for those files, where the
        # judger has them and where it has its own files, by reading and by asking for sizes.
        for place in ("/opt/uoj_judger/uoj_judger/result/passes", "../result/passes", ".", "passes"):
            for read in (True, False):
                j = self.judge(messages_cheat_reading_the_checker(place, read))
                self.assertEqual(j.score, 0, (place, read, j))
                self.assertIn(j.infos[1], ("Wrong Answer", "Dangerous Syscalls"), (place, read, j))
                self.assertNotIn("Accepted", j.infos, (place, read, j))
        # the state of the checker is there all the while: it is what tells the honest program right
        self.assertEqual(self.judge(messages_solution()).score, 100)

    def test_work_folder_tells_a_later_pass_nothing(self):
        # A program may list its work folder and see how large everything in it is. This one
        # does, in its second pass, and looks for a file as large as its first pass made it,
        # and for the files of the checker.
        j = self.judge(MESSAGES_LIST_WORK_FOLDER, "Python3")
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos[1], "Wrong Answer", j)
        self.assertIn("expected 7, found -1", j.details)

    # ---- as many passes as the problem allows

    def test_checker_decides_how_many_passes_there_are(self):
        problem_id = self.admin.create_problem(steps_problem_files(passes=3))
        # three passes, two passes and one: the checker counts them in its state, which is
        # empty again when the next test begins
        j = uoj.wait_submission(self.admin.submit(problem_id, STEPS))
        self.assertEqual((j.score, j.infos), (100, ["Accepted"] * 3), j)
        self.assertRegex(j.details, r"(?s)3 passes.*2 passes.*1 passes")
        # a pass that is wrong ends the test there
        j = uoj.wait_submission(self.admin.submit(problem_id, STEPS.replace("value + 1", "value + (step == 2 ? 2 : 1)")))
        self.assertEqual(j.infos, ["Wrong Answer", "Wrong Answer", "Accepted"], j)
        self.assertIn("step 2: expected 12, found 13", j.details)
        self.assertNotIn("step 3", j.details)

    def test_checker_that_asks_for_more_passes_than_allowed_is_wrong_itself(self):
        problem_id = self.admin.create_problem(steps_problem_files(passes=2))
        j = uoj.wait_submission(self.admin.submit(problem_id, STEPS))
        # the test that needs three passes fails for the checker, not for the program
        self.assertEqual(j.infos, ["Checker Judgment Failed", "Accepted", "Accepted"], j)
        self.assertIn("asked for another pass after pass 2", j.details)

    def test_multi_pass_problem_is_what_it_can_be(self):
        # it is judged by a checker of its own, is not hacked, and has an end of passes
        for change, said in ((dict(use_builtin_checker="ncmp"), "builtin checker"), (dict(multi_pass=99), "between 0 and 20"), (dict(interaction_mode="on"), "interaction_mode")):
            problem_id = self.admin.new_problem()
            files = multi_pass_problem_files(**change)
            files["interactor.cpp"] = DOUBLE_INTERACTOR
            self.assertIn("上传成功", self.admin.upload_data(problem_id, files).text)
            self.assertIn(said, self.admin.sync(problem_id), change)
        problem_id = self.admin.create_problem(multi_pass_problem_files())
        r = self.admin.upload_data(problem_id, {"std.cpp": messages_solution(), "val.cpp": ACCEPT_ANYTHING})
        self.assertIn("上传成功", r.text)
        self.assertIn("hackable", self.admin.toggle_hackable(problem_id))
        self.assertEqual(db_value("select hackable from problems where id = %d" % problem_id), "0")


def judger_leftovers():
    """what is left of judgements on the judgers: processes that run or have a folder of a
    judgement as their place, processes that are dead and not waited for, and files of passes"""
    script = """
for p in /proc/[0-9]*; do
    exe=$(readlink $p/exe 2>/dev/null)
    cwd=$(readlink $p/cwd 2>/dev/null)
    state=$(sed 's/.*) //' $p/stat 2>/dev/null | cut -d' ' -f1)
    case "$state:$exe:$cwd" in
        Z:*|*:/opt/uoj_judger/uoj_judger*|*:/var/uoj_data_copy*) echo "process ${p#/proc/} $state $exe $cwd";;
    esac
done
ls -d /opt/uoj_judger/uoj_judger/result/pass* 2>/dev/null
true
"""
    found = []
    for judger in uoj.JUDGERS:
        found += [judger + ": " + line for line in docker_exec(judger, script).splitlines() if line.strip()]
    return found


class JudgerIsLeftCleanTest(unittest.TestCase):
    """whatever a program, a checker or a judger does, a judger is afterwards as it was before:
    no process stays behind, nothing piles up, and the next submission is judged"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()

    def assert_nothing_is_left(self):
        uoj.wait_idle()
        # a process that was killed is gone a moment later
        try:
            uoj.wait_until("nothing is left of the judgements", lambda: not judger_leftovers(), timeout=30)
        except Exception:
            self.fail(judger_leftovers())

    def test_passes_that_misbehave_leave_no_process_behind(self):
        problem_id = self.admin.create_problem(multi_pass_problem_files())
        in_first = 'if (strcmp(run, "first") == 0) { %s }'
        sleeping = "struct epoll_event event; epoll_wait(epoll_create1(0), &event, 1, 60000);"
        spinning = "volatile unsigned long long spin = 0; for (;;) spin = spin + 1;"
        forking = "if (fork() == 0) { for (;;) pause(); }"
        programs = [
            ("waits in the first pass", "Time Limit Exceeded", messages_solution(burn=in_first % sleeping)),
            ("waits in the second pass", "Time Limit Exceeded", messages_solution(second=sleeping)),
            ("spins in the second pass", "Time Limit Exceeded", messages_solution(second=spinning)),
            ("leaves a process in the first pass", "Dangerous Syscalls", messages_solution(burn=in_first % forking)),
            ("leaves a process in the second pass", "Dangerous Syscalls", messages_solution(second=forking)),
        ]
        headers = "#include <sys/epoll.h>\n#include <unistd.h>\n"
        submissions = [(what, info, self.admin.submit(problem_id, headers + code)) for what, info, code in programs]
        for what, info, submission_id in submissions:
            j = uoj.wait_submission(submission_id)
            self.assertEqual((j.score, j.infos), (0, [info] * 2), (what, j))
        self.assert_nothing_is_left()
        # and the judgers go on judging
        self.assertEqual(uoj.wait_submission(self.admin.submit(problem_id, messages_solution())).score, 100)

    def test_checker_that_misbehaves_ends_its_test_and_nothing_else(self):
        problem_id = self.admin.create_problem(unruly_checker_problem_files())
        j = uoj.wait_submission(self.admin.submit(problem_id, UNRULY))
        expected = {
            "spin": "Checker Time Limit Exceeded",
            # asks for a fourth pass of three
            "more": "Checker Judgment Failed",
            # the input of a pass is no larger than a checker may write
            "flood": "Checker Output Limit Exceeded",
            # the input of a pass is a plain file
            "folder": "Checker Judgment Failed",
            # two files are all that a checker may write
            "stray": "Checker Dangerous Syscalls",
            "fine": "Accepted",
        }
        self.assertEqual(j.infos, [expected[kind] for kind in UNRULY_KINDS], j)
        self.assertIn("asked for another pass after pass 3", j.details)
        self.assertIn("nextpass.in of the checker is not a plain file", j.details)
        self.assertIn("3 passes", j.details)
        # the 64 megabytes that the checker wrote are gone with the test they were written in
        self.assert_nothing_is_left()
        for judger in uoj.JUDGERS:
            megabytes = int(docker_exec(judger, "du -sm /opt/uoj_judger/uoj_judger/result | cut -f1"))
            self.assertLess(megabytes, 8, judger)
        self.assertEqual(uoj.wait_submission(self.admin.submit(problem_id, UNRULY)).infos, j.infos)

    def test_judger_that_hangs_is_killed_with_all_that_it_started(self):
        # The judger of this problem never ends, and it has started a process that left its
        # process group. Its time is over after five seconds: the judgement fails, and both
        # processes are gone.
        problem_id = self.admin.create_problem(hanging_judger_problem_files())
        j = uoj.wait_submission(self.admin.submit(problem_id, AB))
        self.assertEqual(j.error, "Judgment Failed", j)
        self.assertIn("Time Limit Exceeded", j.details)
        self.assert_nothing_is_left()
        ordinary = self.admin.create_problem(ab_problem_files())
        for _ in uoj.JUDGERS * 2:
            self.assertEqual(uoj.wait_submission(self.admin.submit(ordinary, AB)).score, 100)
        self.assert_nothing_is_left()


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
        self.assertEqual(teacher.new_problem_form("p6-rating"), "")
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
        self.assertEqual(teacher.new_problem_form(slug), "")
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
            self.assertIn("赛制：%s" % rule, uoj.text_of(pupil.get(here).text))

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
        # the page of the contest says what its rule means, in a few lines
        told = uoj.text_of(ann.get(here).text)
        for fact in ("赛制：ICPC", "比赛中用全部数据评测", "罚时：每次未通过的提交 20 分钟", "封榜：最后 60 分钟（开始后 4:00:00 起）"):
            self.assertIn(fact, told)

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
            # under the letter of a problem: how many solved it, of how many who tried
            self.assertEqual(re.findall(r'data-solved-by="\d+">(\d+/\d+)<', page), ["2/3", "0/1"])
            # a solved problem says when it was solved, as hours and minutes
            self.assertRegex(page, r'(?s)data-username="p6_icpc_ann".*?>\+1</a>\s*<div class="uoj-icpc-under">0:20</div>')
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
            self.assertEqual(re.findall(r'data-solved-by="\d+">(\d+/\d+)<', page), ["3/3", "1/1"])
            # bob: two problems, five hours and one minute of penalty
            self.assertRegex(page, r'(?s)data-username="p6_icpc_bob".*?uoj-icpc-total">2</span><div class="uoj-icpc-under">5:01</div>')
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


def attach(client, path, files, **fields):
    """send files to the form that adds attachments on a page: '' or why it was refused.
    files is a list of (name, content)."""
    fields["form"] = "add_attachments"
    r = client.post(path, fields, [("attachments[]", (name, content, "application/octet-stream")) for name, content in files])
    return "" if r.status_code in (301, 302) else "HTTP %d: %s" % (r.status_code, uoj.text_of(r.text))


def attachments_of(owner_type, owner_id):
    """name => (id, size, sha256)"""
    return {
        row[1]: (int(row[0]), int(row[2]), row[3])
        for row in db("select id, name, size, sha256 from attachments where owner_type = '%s' and owner_id = %d" % (owner_type, owner_id))
    }


class AttachmentTest(unittest.TestCase):
    """the files that come with a problem or a contest"""

    TOOL = b"#!/usr/bin/env python3\nprint('a tool to try a solution with')\n"
    PAGE = b"<html><script>alert(document.cookie)</script></html>"
    PDF = b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.setter = p3.account("p6_att_setter")
        assert cls.admin.change_user("p6_att_setter", "grant:teacher") == ""
        cls.reader, cls.outsider = p3.account("p6_att_reader"), p3.account("p6_att_outsider")

    def test_files_of_a_problem_go_with_the_problem(self):
        setter, reader = self.setter, self.reader
        problem_id = setter.new_problem()
        manage = "/problem/%d/manage/attachments" % problem_id
        self.assertEqual(setter.get(manage).status_code, 200)
        self.assertIn('href="%s"' % manage, setter.get("/problem/%d/manage/statement" % problem_id).text)
        # only who manages the problem adds to it
        self.assertEqual(reader.get(manage).status_code, 403)
        self.assertNotEqual(attach(reader, manage, [("tool.py", self.TOOL)]), "")
        self.assertEqual(attachments_of("problem", problem_id), {})
        # what a file may not be called
        self.assertIn("文件名", attach(setter, manage, [(".htaccess", b"deny from all")]))
        self.assertIn("请选择", attach(setter, manage, []))
        self.assertEqual(attachments_of("problem", problem_id), {})

        self.assertEqual(attach(setter, manage, [("本地测试 工具.py", self.TOOL), ("page.html", self.PAGE), ("题面.pdf", self.PDF)]), "")
        files = attachments_of("problem", problem_id)
        self.assertEqual(sorted(files), sorted(["本地测试 工具.py", "page.html", "题面.pdf"]))
        tool_id, size, digest = files["本地测试 工具.py"]
        self.assertEqual((size, digest), (len(self.TOOL), uoj.sha256(self.TOOL)))
        self.assertEqual(uoj.file_sha256(uoj.WEB, "/var/uoj_data/attachments/%d" % tool_id), digest)

        # ---- they are shown under the statement, to whoever reads the problem
        page = setter.get("/problem/%d" % problem_id).text
        self.assertIn('href="/attachment/%d"' % tool_id, page)
        self.assertIn("本地测试 工具.py", page)
        r = setter.get("/attachment/%d" % tool_id)
        self.assertEqual((r.status_code, r.content), (200, self.TOOL))
        self.assertIn("attachment", r.headers["Content-Disposition"])
        self.assertIn("filename*=UTF-8''%E6%9C%AC%E5%9C%B0%E6%B5%8B%E8%AF%95%20%E5%B7%A5%E5%85%B7.py", r.headers["Content-Disposition"])
        # the problem is hidden: to anybody else its files are not there
        self.assertEqual(reader.get("/attachment/%d" % tool_id).status_code, 404)
        self.assertEqual(uoj.Client().get("/attachment/%d" % tool_id).status_code, 302)
        self.assertEqual(reader.get("/attachment/99999999").status_code, 404)
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        for client in (reader, uoj.Client()):
            self.assertEqual(client.get("/attachment/%d" % tool_id).content, self.TOOL)
            self.assertIn('href="/attachment/%d"' % tool_id, client.get("/problem/%d" % problem_id).text)

        # ---- a file is a download, never a page of the site; a PDF is read in the browser
        r = reader.get("/attachment/%d" % files["page.html"][0])
        self.assertEqual(r.content, self.PAGE)
        self.assertEqual(r.headers["Content-Type"].split(";")[0], "application/octet-stream")
        self.assertTrue(r.headers["Content-Disposition"].startswith("attachment"))
        self.assertEqual(r.headers["X-Content-Type-Options"], "nosniff")
        r = reader.get("/attachment/%d" % files["题面.pdf"][0])
        self.assertEqual(r.headers["Content-Type"].split(";")[0], "application/pdf")
        self.assertTrue(r.headers["Content-Disposition"].startswith("inline"))

        # ---- a file of the same name takes the place of the one there is
        newer = self.TOOL + b"# version 2\n"
        self.assertEqual(attach(setter, manage, [("本地测试 工具.py", newer)]), "")
        self.assertEqual(attachments_of("problem", problem_id)["本地测试 工具.py"], (tool_id, len(newer), uoj.sha256(newer)))
        self.assertEqual(reader.get("/attachment/%d" % tool_id).content, newer)

        # ---- a copy of the problem has copies of its files, which are its own
        teacher = p3.account("p6_att_teacher")
        self.assertEqual(self.admin.change_user("p6_att_teacher", "grant:teacher"), "")
        teacher.new_domain("p6-attachments")
        db("update problems set is_hidden = 1 where id = %d" % problem_id)
        public_id = self.admin.create_problem(ab_problem_files())
        self.assertEqual(attach(self.admin, "/problem/%d/manage/attachments" % public_id, [("checker-notes.txt", b"notes")]), "")
        copy_id = teacher.copy_problem("p6-attachments", public_id)
        copied = attachments_of("problem", copy_id)
        self.assertEqual(list(copied), ["checker-notes.txt"])
        self.assertNotEqual(copied["checker-notes.txt"][0], attachments_of("problem", public_id)["checker-notes.txt"][0])
        self.assertEqual(copied["checker-notes.txt"][2], uoj.sha256(b"notes"))
        uoj.wait_data_version(copy_id)
        number = uoj.pid(copy_id)
        self.assertEqual(teacher.get("/d/p6-attachments/problem/%d/manage/attachments" % number).status_code, 200)
        self.assertEqual(teacher.get("/attachment/%d" % copied["checker-notes.txt"][0]).content, b"notes")
        # a problem of a domain keeps its files in the domain
        self.assertEqual(reader.get("/attachment/%d" % copied["checker-notes.txt"][0]).status_code, 404)

        # ---- taking a file away
        self.assertNotEqual(reader.form(manage, "delete_attachment", attachment_id=str(tool_id)), "")
        # not through a problem it does not belong to
        self.assertNotEqual(self.admin.form("/problem/%d/manage/attachments" % public_id, "delete_attachment", attachment_id=str(tool_id)), "")
        self.assertEqual(setter.form(manage, "delete_attachment", attachment_id=str(tool_id)), "")
        self.assertNotIn("本地测试 工具.py", attachments_of("problem", problem_id))
        self.assertEqual(setter.get("/attachment/%d" % tool_id).status_code, 404)
        self.assertEqual(uoj.docker_exec(uoj.WEB, "ls /var/uoj_data/attachments/%d 2>/dev/null || echo gone" % tool_id).strip(), "gone")
        self.assertEqual(attachments_of("problem", copy_id), copied)
        log = [row[0] for row in db("select action from audit_logs where resource_type = 'problem' and resource_id = '%d' and action like 'attachment.%%' order by id" % problem_id)]
        self.assertEqual(log, ["attachment.add"] * 4 + ["attachment.delete"])

    def test_files_of_a_contest_are_for_the_people_inside_it(self):
        admin, reader, outsider = self.admin, self.reader, self.outsider
        hidden_id = admin.new_problem()
        self.assertEqual(attach(admin, "/problem/%d/manage/attachments" % hidden_id, [("tool.py", self.TOOL)]), "")
        tool_id = attachments_of("problem", hidden_id)["tool.py"][0]
        # a contest is made with its files
        fields = {"form": "create", "name": "p6 带附件的比赛", "start_time": uoj.web_time(3600), "last_min": "60", "rule": "ICPC",
                  "join_mode": "open", "problems": str(hidden_id)}  # fmt: skip
        r = admin.post("/contest/new", fields, [("attachments[]", ("statements.pdf", self.PDF, "application/pdf"))])
        self.assertEqual(r.status_code, 302, uoj.text_of(r.text)[-300:])
        contest_id = int(db_value("select max(id) from contests"))
        here, manage = "/contest/%d" % contest_id, "/contest/%d/manage" % contest_id
        pdf_id, size, digest = attachments_of("contest", contest_id)["statements.pdf"]
        self.assertEqual((size, digest), (len(self.PDF), uoj.sha256(self.PDF)))
        # and more are added on the page that manages it, by who runs it
        self.assertNotEqual(attach(reader, manage, [("x.txt", b"x")], tab="attachments"), "")
        self.assertEqual(attach(admin, manage, [("clarifications.txt", b"none yet")], tab="attachments"), "")
        notes_id = attachments_of("contest", contest_id)["clarifications.txt"][0]
        self.assertIn('href="/attachment/%d"' % notes_id, admin.get(manage).text)

        # ---- before it begins nobody but its staff reads them
        reader.register_for_contest(contest_id)
        self.assertEqual(admin.get("/attachment/%d" % pdf_id).content, self.PDF)
        for client in (reader, outsider):
            for attachment_id in (pdf_id, notes_id, tool_id):
                self.assertEqual(client.get("/attachment/%d" % attachment_id).status_code, 404)

        # ---- while it runs: the contestants, also the files of its problems that are hidden
        uoj.move_contest(contest_id, -60, 600)
        page = reader.get(here).text
        self.assertIn('href="/attachment/%d"' % pdf_id, page)
        self.assertIn("statements.pdf", page)
        for attachment_id, content in ((pdf_id, self.PDF), (notes_id, b"none yet"), (tool_id, self.TOOL)):
            self.assertEqual(reader.get("/attachment/%d" % attachment_id).content, content)
            self.assertEqual(outsider.get("/attachment/%d" % attachment_id).status_code, 404)
        self.assertIn('href="/attachment/%d"' % tool_id, reader.get("%s/problem/%d" % (here, hidden_id)).text)

        # ---- when it is over: whoever may get inside
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(outsider.get("/attachment/%d" % pdf_id).content, self.PDF)
        self.assertIn('href="/attachment/%d"' % pdf_id, outsider.get(here).text)
        self.assertEqual(admin.form(manage, "delete_attachment", attachment_id=str(notes_id), tab="attachments"), "")
        self.assertEqual(sorted(attachments_of("contest", contest_id)), ["statements.pdf"])
        self.assertEqual(outsider.get("/attachment/%d" % notes_id).status_code, 404)


def data_zip(files):
    return ("data", ("data.zip", uoj.make_zip(files), "application/zip"))


def uploaded_conf(problem_id):
    """the problem.conf of the data that was uploaded for a problem, as a dict"""
    text = docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/problem.conf" % problem_id)
    return dict(line.split(None, 1) for line in text.splitlines() if line.strip())


def uploaded_files(problem_id):
    return sorted(docker_exec(uoj.WEB, "ls /var/uoj_data/upload/%d" % problem_id).split())


class ProblemFormTest(unittest.TestCase):
    """a problem is made with one form, and how it is judged is said in a form, not in a file"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher = p3.account("p6_prob_teacher")
        assert cls.admin.change_user("p6_prob_teacher", "grant:teacher") == ""

    def settings(self, problem_id, **changes):
        fields = dict(form="judge_settings", type="traditional", time_limit="1", memory_limit="256", checker="wcmp", scoring="per_test")
        fields.update(changes)
        return self.admin.post("/problem/%d/manage/data" % problem_id, fields)

    def test_problem_is_made_with_everything_it_needs(self):
        teacher = self.teacher
        page = teacher.get("/problem/new").text
        for field in ('name="title"', 'name="statement_md"', 'name="tags"', 'name="public"', 'value="traditional"', 'value="interactive"',
                      'value="multi_pass"', 'value="submit_answer"', 'name="time_limit"', 'name="memory_limit"', 'name="checker"',
                      'name="scoring"', 'name="data"', 'name="attachments[]"'):  # fmt: skip
            self.assertIn(field, page, field)
        self.assertIn('href="/problem/new"', teacher.get("/problems").text)
        problems = lambda: db_value("select count(*) from problems")
        before = problems()
        # what is wrong is said, and no problem is made
        for wrong, said in ((dict(title=" "), "标题"), (dict(time_limit="fast"), "时间限制"), (dict(memory_limit="0"), "内存限制"),
                            (dict(type="quiz"), "题目类型"), (dict(checker=""), "比较方式"), (dict(scoring="subtasks", subtasks="5 100"), "子任务")):  # fmt: skip
            self.assertIn(said, teacher.new_problem_form(**wrong), wrong)
        self.assertEqual(problems(), before)
        # and what was typed is still in the form
        page = teacher.post("/problem/new", dict(form="create", title="p6 写了一半", type="multi_pass", passes="7", time_limit="x", memory_limit="64", checker="ncmp", scoring="all")).text
        self.assertIn('value="p6 写了一半"', page)
        self.assertRegex(page, r'value="multi_pass" checked')
        self.assertRegex(page, r'name="passes"[^>]*value="7"')
        self.assertRegex(page, r'value="all" checked')

        # ---- one form: title, statement, tags, limits, checker, data named the way people name it, a file to go with it
        data = {"1.in": "1 2\n", "1.out": "3\n", "2.in": "1000 2000\n", "2.ans": "3000\n", "10.in": "999999999 1\n", "10.out": "1000000000\n",
                "sample1.in": "5 7\n", "sample1.out": "12\n", "std.cpp": AB}  # fmt: skip
        problem_id = teacher.new_problem(
            title="p6 A + B", statement_md="### 题目描述\n\n求 $a + b$。", tags="入门， 模拟", public="on",
            time_limit="2", memory_limit="128", checker="ncmp",
            files=[data_zip(data), ("attachments[]", ("notes.txt", b"notes", "text/plain"))],
        )
        # the page it leads to says what was made of the data
        landed = teacher.get("/problem/%d/manage/data" % problem_id).text
        self.assertIn('id="data-flash"', landed)
        told = uoj.text_of(landed)
        for fact in ("题目 #%d 已创建" % problem_id, "识别到 3 个测试点、1 个额外测试点", "重命名了 8 个文件"):
            self.assertIn(fact, told)
        self.assertEqual(db("select title, is_hidden from problems where id = %d" % problem_id), [["p6 A + B", "0"]])
        statement, statement_md = db("select hex(statement), hex(statement_md) from problems_contents where id = %d" % problem_id)[0]
        self.assertIn("<h3>题目描述</h3>", bytes.fromhex(statement).decode())
        self.assertIn("求 $a + b$。", bytes.fromhex(statement_md).decode())
        self.assertEqual(sorted(row[0] for row in db("select tag from problems_tags where problem_id = %d" % problem_id)), sorted(["入门", "模拟"]))
        self.assertEqual(db("select username from problems_permissions where problem_id = %d" % problem_id), [["p6_prob_teacher"]])
        self.assertEqual(list(attachments_of("problem", problem_id)), ["notes.txt"])
        # the tests were found, put in their natural order and called what the judgers call them
        self.assertEqual(uploaded_files(problem_id), sorted(
            ["data1.in", "data1.out", "data2.in", "data2.out", "data3.in", "data3.out", "ex_data1.in", "ex_data1.out", "problem.conf", "std.cpp"]
        ))  # fmt: skip
        self.assertEqual(docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/data3.in" % problem_id).strip(), "999999999 1")
        self.assertEqual(docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/problem.conf" % problem_id), uoj.conf(
            use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=3, n_ex_tests=1, n_sample_tests=1,
            input_pre="data", input_suf="in", output_pre="data", output_suf="out", time_limit=2, memory_limit=128,
        ))  # fmt: skip
        # and the data was published: the problem is judged
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        j = uoj.wait_submission(teacher.submit(problem_id, AB))
        self.assertEqual((j.score, j.infos), (100, ["Accepted"] * 3 + ["Extra Test Passed"]), j)
        self.assertIn("p6 A + B", uoj.Client().get("/problem/%d" % problem_id).text)

    def test_multi_pass_problem_is_made_by_choosing_its_kind(self):
        # inputs alone: the answer of such a problem is not a file
        data = {"chk.cpp": MESSAGES_CHECKER, "a.in": "first\n3\n5\n123456789\n0\n", "b.in": "first\n1\n7\n"}
        page = self.admin.get("/problem/new").text
        self.assertIn('value="multi_pass"', page)
        self.assertIn('name="passes"', page)
        self.assertIn("轮数", self.admin.new_problem_form(title="x", type="multi_pass", passes="1"))
        problem_id = self.admin.new_problem(title="p6 通信题", type="multi_pass", passes="2", scoring="all", public="on", files=[data_zip(data)])
        conf = uploaded_conf(problem_id)
        self.assertEqual((conf["multi_pass"], conf["n_tests"], conf["n_subtasks"], "use_builtin_checker" in conf), ("2", "2", "1", False))
        self.assertEqual(uploaded_files(problem_id), sorted(["chk.cpp", "data1.in", "data1.out", "data2.in", "data2.out", "problem.conf"]))
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        j = uoj.wait_submission(self.admin.submit(problem_id, messages_solution()))
        self.assertEqual(j.score, 100, j)
        # all or nothing: the first test that fails is the last that is judged
        j = uoj.wait_submission(self.admin.submit(problem_id, messages_solution(width=41)))
        self.assertEqual((j.score, j.infos), (0, ["Wrong Answer"]), j)
        # the form on the page of the data shows the kind and the passes, and changes them
        page = self.admin.get("/problem/%d/manage/data" % problem_id).text
        self.assertRegex(page, r'value="multi_pass" checked')
        self.assertRegex(page, r'name="passes"[^>]*value="2"')
        self.assertEqual(self.settings(problem_id, type="multi_pass", passes="5", scoring="all").status_code, 302)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(published_conf(problem_id)["multi_pass"], "5")
        # and switches them off: the problem is an ordinary one again, with a checker of its own
        self.assertEqual(self.settings(problem_id, type="traditional", checker="custom", scoring="all").status_code, 302)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertNotIn("multi_pass", published_conf(problem_id))

    def test_problem_conf_that_comes_with_the_data_is_used_as_it_is(self):
        problem_id = self.admin.new_problem(title="p6 自带配置", time_limit="1", checker="wcmp", files=[data_zip(ab_problem_files(time_limit=3))])
        self.assertIn("数据包里带有 problem.conf", uoj.text_of(self.admin.get("/problem/%d/manage/data" % problem_id).text))
        conf = uploaded_conf(problem_id)
        self.assertEqual((conf["time_limit"], conf["use_builtin_checker"], conf["input_pre"]), ("3", "ncmp", "input"))
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(published_conf(problem_id)["time_limit"], "3")

    def test_settings_are_kept_until_the_data_comes_and_changed_in_a_form(self):
        admin = self.admin
        problem_id = admin.new_problem(title="p6 先建题", time_limit="3", checker="ncmp")
        manage = "/problem/%d/manage/data" % problem_id
        conf = uploaded_conf(problem_id)
        self.assertEqual((conf["n_tests"], conf["time_limit"], conf["use_builtin_checker"]), ("0", "3", "ncmp"))
        page = admin.get(manage).text
        self.assertIn("还没有测试数据", page)
        self.assertIn('id="form-judge-settings"', page)
        self.assertRegex(page, r'name="time_limit"[^>]*value="3"')
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "0")

        # the data arrives without a problem.conf: its tests are found, what was said before holds
        r = admin.upload_data(problem_id, {"input1.txt": "1 2\n", "output1.txt": "3\n", "input2.txt": "2 2\n", "output2.txt": "4\n"})
        self.assertIn("上传成功", r.text)
        self.assertIn("识别到 2 个测试点", r.text)
        conf = uploaded_conf(problem_id)
        self.assertEqual((conf["n_tests"], conf["input_pre"], conf["output_suf"], conf["time_limit"], conf["use_builtin_checker"]), ("2", "input", "txt", "3", "ncmp"))
        self.assertEqual(uploaded_files(problem_id), sorted(["input1.txt", "input2.txt", "output1.txt", "output2.txt", "problem.conf"]))
        self.assertEqual(admin.sync(problem_id), "")
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)

        # ---- the form on the page of the data says how the problem is judged, and saving it publishes
        versions = lambda: int(db_value("select count(*) from problem_data_versions where problem_id = %d" % problem_id))
        before = versions()
        r = self.settings(problem_id, time_limit="1.5", memory_limit="64", scoring="subtasks", subtasks="1 40\n2 60")
        self.assertEqual(r.status_code, 302, uoj.text_of(r.text)[-300:])
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(versions(), before + 1)
        conf = published_conf(problem_id)
        self.assertEqual((conf["time_limit"], conf["memory_limit"], conf["use_builtin_checker"], conf["n_subtasks"], conf["subtask_end_1"], conf["subtask_score_2"]),
                         ("1.5", "64", "wcmp", "2", "1", "60"))  # fmt: skip
        page = admin.get(manage).text
        self.assertIn("评测设置已保存", page)
        self.assertRegex(page, r'value="subtasks" checked')
        # a subtask is scored as a whole
        half = AB.replace('printf("%lld\\n", a + b);', 'printf("%lld\\n", a == 1 ? a + b : 0);')
        self.assertNotEqual(half, AB)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, half)).score, 40)

        # what is wrong is said and changes nothing
        for wrong, said in ((dict(time_limit="0"), "时间限制"), (dict(scoring="subtasks", subtasks="1 40\n5 60"), "2 个测试点")):
            r = self.settings(problem_id, **wrong)
            self.assertIn('id="judge-settings-error"', r.text, wrong)
            self.assertIn(said, uoj.text_of(r.text), wrong)
        self.assertEqual(uploaded_conf(problem_id)["time_limit"], "1.5")
        self.assertEqual(versions(), before + 1)
        # what the form does not decide is kept when it is saved
        docker_exec(uoj.WEB, "echo 'output_limit 32' >> /var/uoj_data/upload/%d/problem.conf" % problem_id)
        self.assertEqual(self.settings(problem_id, time_limit="2").status_code, 302)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["output_limit"], conf["time_limit"], "n_subtasks" in conf), ("32", "2", False))
        # a problem.conf somebody wrote is not written over because data was uploaded
        self.assertIn("评测设置没有变", admin.upload_data(problem_id, {"input3.txt": "3 3\n", "output3.txt": "6\n"}).text)
        self.assertEqual(uploaded_conf(problem_id)["n_tests"], "2")
        # saving the form finds the new test
        self.assertEqual(self.settings(problem_id, time_limit="2").status_code, 302)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(published_conf(problem_id)["n_tests"], "3")

    def test_problem_with_a_judger_of_its_own_has_no_form(self):
        problem_id = self.admin.create_problem(custom_judger_problem_files())
        page = self.admin.get("/problem/%d/manage/data" % problem_id).text
        self.assertNotIn('id="form-judge-settings"', page)
        self.assertIn("使用自己的评测程序", page)
        before = uoj.tree_sha256(uoj.WEB, "/var/uoj_data/upload/%d" % problem_id)
        self.settings(problem_id, time_limit="9")
        self.assertEqual(uoj.tree_sha256(uoj.WEB, "/var/uoj_data/upload/%d" % problem_id), before)

    def test_problems_are_made_by_the_people_who_may(self):
        student = p3.account("p6_prob_student")
        self.assertEqual(student.get("/problem/new").status_code, 403)
        self.assertNotIn('href="/problem/new"', student.get("/problems").text)
        before = db_value("select count(*) from problems")
        self.assertNotEqual(student.new_problem_form(title="x"), "")
        self.assertEqual(db_value("select count(*) from problems"), before)
        # in a domain: the people who teach there, and the problem is the domain's
        teacher = self.teacher
        did = teacher.new_domain("p6-problem-form")
        self.assertEqual(p4.member_form(teacher, "p6-problem-form", "add", username="p6_prob_student", role="member"), "")
        new = "/d/p6-problem-form/problem/new"
        self.assertEqual(student.get(new).status_code, 403)
        self.assertNotEqual(student.new_problem_form("p6-problem-form", title="x"), "")
        self.assertIn('href="%s"' % new, teacher.get("/d/p6-problem-form/problems").text)
        self.assertNotIn('href="%s"' % new, student.get("/d/p6-problem-form/problems").text)
        self.assertIn('name="title"', teacher.get(new).text)
        problem_id = teacher.new_problem("p6-problem-form", title="p6 域内题", public="on", files=[data_zip({"1.in": "1 2\n", "1.out": "3\n"})])
        self.assertEqual(db("select owner_domain_id, domain_pid, is_hidden from problems where id = %d" % problem_id), [[str(did), "1", "0"]])
        self.assertEqual(db_value("select count(*) from problems_permissions where problem_id = %d" % problem_id), "0")
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        here = "/d/p6-problem-form/problem/1"
        self.assertIn("p6 域内题", student.get(here).text)
        self.assertEqual(uoj.wait_submission(student.submit(problem_id, AB, path=here)).score, 100)
        # the page it led to, with the number the problem has in the domain
        self.assertIn("题目 #1 已创建", uoj.text_of(teacher.get(here + "/manage/data").text))


class BlogSwitchTest(unittest.TestCase):
    """blogs are closed unless the system administrator opens them; announcements stay"""

    def post(self, poster, title, announcement=False):
        db("insert into blogs (title, content, content_md, post_time, poster, zan, is_hidden, type, is_draft)"
           " values ('%s', '<p>text of %s</p>', 'text of %s', now(), '%s', 0, 0, 'B', 0)" % (title, title, title, poster))  # fmt: skip
        blog_id = int(db_value("select max(id) from blogs where poster = '%s'" % poster))
        if announcement:
            db("insert into important_blogs (blog_id, level) values (%d, 0)" % blog_id)
        return blog_id

    @staticmethod
    def blog(username, path=""):
        """the address of the blog of a user: the name is written with hyphens there, and an
        address with the name as it is leads to that one"""
        return "/blog/%s%s" % (username.replace("_", "-").lower(), path)

    def comment(self, client, blog_id, poster):
        return client.submit_form(self.blog(poster, "/post/%d" % blog_id), "comment", {"comment": "p6 comment by " + client.username})

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
            # the address of a blog has no slash at its end: the web server takes one away
            for path in ("", "/archive", "/post/%d" % diary, "/post/new/write"):
                self.assertEqual(client.get(self.blog("p6_blog_writer", path)).status_code, 404, path)
                self.assertEqual(client.get("/blog/p6_blog_writer" + path).status_code, 404, path)
        # the short address of a post leads to the blog it is in, which is not there
        self.assertTrue(reader.get("/blogs/%d" % diary).headers.get("Location").endswith(self.blog("p6_blog_writer", "/post/%d" % diary)))
        self.assertNotIn(self.blog("p6_blog_writer"), reader.get("/user/profile/p6_blog_writer").text)
        # the announcements are there for everybody, and are not discussed
        for client in (reader, visitor):
            self.assertIn("p6 期末安排", client.get("/").text)
            page = client.get(self.blog(uoj.ADMIN[0], "/post/%d" % news))
            self.assertEqual(page.status_code, 200)
            self.assertIn("text of p6 期末安排", page.text)
            self.assertIn('id="comments-closed"', page.text)
            self.assertNotIn('id="form-comment"', page.text)
        self.comment(reader, news, uoj.ADMIN[0])
        self.assertEqual(comments(), 0)
        # the administrators go on writing them
        self.assertEqual(admin.get(self.blog(uoj.ADMIN[0], "/post/new/write")).status_code, 200)
        self.assertEqual(oj_admin.get(self.blog("p6_blog_ojadmin", "/post/new/write")).status_code, 200)

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
            self.assertEqual(reader.get(self.blog("p6_blog_writer", "/post/%d" % diary)).status_code, 200)
            self.assertEqual(writer.get(self.blog("p6_blog_writer", "/post/new/write")).status_code, 200)
            self.assertIn(self.blog("p6_blog_writer"), reader.get("/user/profile/p6_blog_writer").text)
            page = reader.get(self.blog(uoj.ADMIN[0], "/post/%d" % news)).text
            self.assertIn('id="form-comment"', page)
            self.assertNotIn('id="comments-closed"', page)
            self.assertEqual(self.comment(reader, diary, "p6_blog_writer"), "")
            self.assertEqual(comments(), 1)
        finally:
            self.assertEqual(p5.site_settings(admin, blog_enabled=False), "")
        # ---- closed again: what was written is kept, and is out of sight
        self.assertEqual(reader.get(self.blog("p6_blog_writer", "/post/%d" % diary)).status_code, 404)
        self.assertEqual(db_value("select count(*) from blogs where id = %d" % diary), "1")
        self.assertEqual(comments(), 1)
        self.assertEqual(
            db("select action, actor from audit_logs where resource_type = 'site_setting' and resource_id = 'blog.enabled' order by id"),
            [["site.edit_setting", uoj.ADMIN[0]]] * 2,
        )


if __name__ == "__main__":
    unittest.main()
