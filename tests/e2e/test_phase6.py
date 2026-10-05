"""End-to-end tests of phase 6: multi-pass problems, numbers of problems inside a domain, the
switch for blogs, what a contest shows while it runs, the ICPC rule, and the forms that make a
problem or a contest in one go.

See test_phase1.py for how to start the containers.
"""

import io
import json
import re
import unittest
import zipfile

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
        # what was there before these tests is not theirs
        uoj.wait_idle()
        cls.before = set(judger_leftovers())

    def left(self):
        return [line for line in judger_leftovers() if line not in self.before]

    def assert_nothing_is_left(self):
        uoj.wait_idle()
        # a process that was killed is gone a moment later
        try:
            uoj.wait_until("nothing is left of the judgements", lambda: not self.left(), timeout=30)
        except Exception:
            self.fail(self.left())

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
            # two files are all that a checker may make: no folder, and no file of its own
            "folder": "Checker Dangerous Syscalls",
            "stray": "Checker Dangerous Syscalls",
            "fine": "Accepted",
        }
        self.assertEqual(j.infos, [expected[kind] for kind in UNRULY_KINDS], j)
        self.assertIn("asked for another pass after pass 3", j.details)
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
        # The problem named there is the problem in the homework: on its own it is hidden, as
        # a problem that was just made is, and closed to the pupils.
        in_domain = "/d/%s/problem/%d" % (slug, uoj.pid(own_id))
        self.assertEqual(pupil.get(in_domain).status_code, 404)
        for page in (listing.text, pupil.get("/submission/%d" % submission_id).text, pupil.get("/d/%s/homework/%d" % (slug, homework_id)).text):
            self.assertIn('<a href="%s">' % in_homework, page)
            self.assertNotIn('href="%s"' % in_domain, page)
        self.assertEqual(pupil.get(in_homework).status_code, 200)
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
        db("update problems set is_hidden = 0 where id = %d" % own_id)
        self.assertEqual(submit_and_follow(pupil, in_domain, AB), "/submissions?problem_id=%d&submitter=p6_hw_pupil" % own_id)
        practice = last_submission("p6_hw_pupil")
        self.assertIn('href="/submission/%d"' % practice, pupil.get("/submissions?problem_id=%d&submitter=p6_hw_pupil" % own_id).text)

        # ---- the way to what was submitted: from the homework, for the people who look after
        # it to everything, for who does it to their own
        everything = 'href="/submissions?homework_id=%d" id="link-homework-submissions"' % homework_id
        for path in ("", "/manage", "/scoreboard"):
            self.assertIn(everything, teacher.get("/d/%s/homework/%d%s" % (slug, homework_id, path)).text, path)
        page = pupil.get("/d/%s/homework/%d" % (slug, homework_id)).text
        self.assertIn('href="/submissions?homework_id=%d&amp;submitter=p6_hw_pupil" id="link-homework-submissions"' % homework_id, page)
        self.assertNotIn(everything, page)

        # and from a training: what its problems were sent, wherever that was
        self.assertEqual(teacher.form("/d/%s/training/new" % slug, "save", title="p6 训练", description_md="", status="published"), "")
        training_id = int(db_value("select max(id) from trainings where domain_id = %d" % did))
        self.assertEqual(teacher.form("/d/%s/training/%d/manage" % (slug, training_id), "add_problem", problem_id=str(uoj.pid(own_id))), "")
        another = teacher.new_problem(slug, title="p6 不在训练里")
        db("insert into submissions (problem_id, domain_id, submit_time, submitter, content, language, tot_size, status, result, is_hidden)"
           " values (%d, %d, now(), 'p6_hw_pupil', '{}', 'C++', 10, 'Judged', '{}', 0)" % (another, did))  # fmt: skip
        elsewhere = last_submission("p6_hw_pupil")
        of_training = "/submissions?training_id=%d" % training_id
        listed = lambda client, query="": set(int(n) for n in re.findall(r'href="/submission/(\d+)"', client.get(of_training + query).text))
        self.assertIn('href="%s" id="link-training-submissions"' % of_training, teacher.get("/d/%s/training/%d" % (slug, training_id)).text)
        self.assertIn('href="%s&amp;submitter=p6_hw_pupil" id="link-training-submissions"' % of_training,
                      pupil.get("/d/%s/training/%d" % (slug, training_id)).text)  # fmt: skip
        page = teacher.get(of_training).text
        self.assertIn('id="submissions-of-training"', page)
        self.assertIn("p6 训练", page)
        # the teacher sees what everybody sent these problems, in the homework as well; a pupil
        # what is theirs, and of the others what a pupil may see; and nothing of other problems
        self.assertEqual(listed(teacher), {submission_id, practice})
        self.assertEqual(listed(pupil, "&submitter=p6_hw_pupil"), {submission_id, practice})
        self.assertEqual(listed(other), {practice})
        # a training nobody may see filters nothing, and names nothing
        page = stranger.get(of_training).text
        self.assertNotIn("p6 训练", page)
        self.assertNotIn('id="submissions-of-training"', page)
        self.assertNotIn('href="/submission/%d"' % practice, page)
        self.assertNotIn(elsewhere, listed(teacher))
        # and on the site as ever
        self.assertEqual(submit_and_follow(pupil, "/problem/%d" % site_problem, AB), "/submissions")
        uoj.wait_idle()


class SubmissionsSearchTest(unittest.TestCase):
    """the list of what was submitted is narrowed down by choosing, where there is a choice"""

    def test_language_is_chosen_from_the_languages_there_are(self):
        visitor = uoj.Client()
        page = visitor.get("/submissions").text
        self.assertRegex(page, r'<select[^>]*name="language"')
        self.assertNotRegex(page, r'<input[^>]*name="language"')
        for language in ("C++17", "C11", "Java17", "Pascal", "Python3"):
            self.assertIn('<option value="%s">%s</option>' % (language, language), page)
        # what is chosen stays chosen, and is what the list is narrowed down to
        page = visitor.get("/submissions", params={"language": "Pascal"}).text
        self.assertIn('<option value="Pascal" selected="selected">', page)
        self.assertNotIn(">C++17</a>", page)
        # a language that is asked for and not offered is still what was asked for
        self.assertIn('<option value="Fortran" selected="selected">', visitor.get("/submissions", params={"language": "Fortran"}).text)


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
        # the problem is called what it is called in the contest, here and on the board
        for told in (listing, page, ann.get(here + "/standings").text):
            self.assertIn('href="%s/problem/A"' % here, told)
            self.assertNotIn('href="%s/problem/%d"' % (here, first), told)
        self.assertRegex(listing, r'<a href="%s/problem/A">A\. ' % here)
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
        # down as well as up; a problem at the end stays where it is
        for way in ("up", "down"):
            self.assertIn('name="direction" value="%s"' % way, teacher.get(manage).text)
        self.assertEqual(teacher.form(manage, "move_problem", problem_id=str(third), direction="down", tab="problems"), "")
        self.assertEqual(self.problems(contest_id), [second, third, first])
        for _ in range(2):
            self.assertEqual(teacher.form(manage, "move_problem", problem_id=str(third), direction="down", tab="problems"), "")
            self.assertEqual(self.problems(contest_id), [second, first, third])
        for _ in range(2):
            self.assertEqual(teacher.form(manage, "move_problem", problem_id=str(third), direction="up", tab="problems"), "")
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
        self.assertEqual(re.findall(r'href="/contest/%d/problem/(\w+)"' % contest_id, dashboard), ["A", "B", "C"])
        self.assertRegex(pupil.get("/contest/%d/problem/%d" % (contest_id, first)).text, r">\s*B\. ")

        # ---- in a contest a problem is called by its letter: that is its address, and what
        # the list of what was submitted calls it, not the number it has outside
        heading = lambda path: re.search(r'(?s)<h1 class="col-md-7 text-center">(.*?)</h1>', pupil.get(path).text).group(1)
        for title, problem_id in zip(("p6 乙", "p6 甲", "p6 丙"), (second, first, third)):
            db("update problems set title = '%s' where id = %d" % (title, problem_id))
        for letter, title, problem_id in zip("ABC", ("p6 乙", "p6 甲", "p6 丙"), (second, first, third)):
            by_letter = "/contest/%d/problem/%s" % (contest_id, letter)
            self.assertEqual(heading(by_letter).strip(), "%s. %s" % (letter, title))
            self.assertEqual(heading("/contest/%d/problem/%d" % (contest_id, problem_id)), heading(by_letter))
            self.assertEqual(pupil.get(by_letter + "/statistics").status_code, 200)
        for nowhere in ("D", "Z", "a", "AB"):
            self.assertEqual(pupil.get("/contest/%d/problem/%s" % (contest_id, nowhere)).status_code, 404, nowhere)
        # (these problems have no data to submit to: what was submitted is written down as such)
        db("insert into submissions (problem_id, contest_id, submit_time, submitter, content, language, tot_size, status, result, is_hidden)"
           " values (%d, %d, now(), 'p6_form_pupil', '{}', 'C++', 10, 'Judged', '{}', 0)" % (first, contest_id))  # fmt: skip
        for page in (pupil.get("/contest/%d/submissions" % contest_id).text, pupil.get("/submissions?submitter=p6_form_pupil").text):
            self.assertIn('<a href="/contest/%d/problem/B">B. p6 甲</a>' % contest_id, page)
            self.assertNotIn("#%d. p6 甲" % first, page)

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
        # the navigation writes its addresses in full
        blogs_link = r'href="[^"]*/blogs"'
        for client in (reader, visitor):
            self.assertNotRegex(client.get("/").text, blogs_link)
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
        # the page of the administrators has no such tab for anybody else
        settings = "/super-manage/settings"
        self.assertEqual(oj_admin.get(settings).status_code, 404)
        p5.site_settings(oj_admin, blog_enabled=True)
        self.assertIsNone(db_value("select value from site_settings where name = 'blog.enabled'"))
        self.assertIn("开放用户博客", admin.get(settings).text)
        self.assertEqual(p5.site_settings(admin, blog_enabled=True), "")
        try:
            # ---- open: everybody has a blog, and posts are discussed
            self.assertRegex(reader.get("/").text, blogs_link)
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


def picked(client, scope, **query):
    """what the field that picks problems is told for what was typed: number => title"""
    query["scope"] = scope
    r = client.get("/problems/pick", params=query)
    assert r.status_code == 200, r.status_code
    return [(row["number"], row["title"]) for row in r.json()["problems"]]


class ProblemPickerTest(unittest.TestCase):
    """problems are put into a contest, a homework or a training by what one remembers of them:
    a number, or a piece of a title"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.teacher, cls.pupil, cls.outsider = (p3.account("p6_pick_" + name) for name in ("teacher", "pupil", "outsider"))
        assert cls.admin.change_user("p6_pick_teacher", "grant:teacher") == ""
        cls.slug = "p6-pick"
        cls.did = cls.teacher.new_domain(cls.slug)
        assert p4.member_form(cls.teacher, cls.slug, "add", username="p6_pick_pupil", role="member") == ""
        # three problems of the domain, the first of them for everybody there
        cls.numbers = {}
        for title in ("p6 两数之和", "p6 修复一个错误", "p6 蒙眼猜数字 <1>"):
            cls.numbers[title] = uoj.pid(cls.teacher.new_problem(cls.slug, title=title))
        db("update problems set is_hidden = 0 where owner_domain_id = %d and domain_pid = %d" % (cls.did, cls.numbers["p6 两数之和"]))
        # and on the site: one for everybody, one that is the teacher's, one that is nobody's business
        cls.public = cls.admin.new_problem(title="p6 公开的求和")
        db("update problems set is_hidden = 0 where id = %d" % cls.public)
        cls.own = cls.teacher.new_problem(title="p6 老师的求和")
        cls.secret = cls.admin.new_problem(title="p6 藏着的求和")

    def test_what_is_typed_finds_the_problem(self):
        teacher, numbers = self.teacher, self.numbers
        two, fix, guess = (numbers[title] for title in ("p6 两数之和", "p6 修复一个错误", "p6 蒙眼猜数字 <1>"))
        # a piece of the title, and letters of it in their order
        self.assertEqual(picked(teacher, self.slug, q="一个错误"), [(fix, "p6 修复一个错误")])
        self.assertEqual(picked(teacher, self.slug, q="修错"), [(fix, "p6 修复一个错误")])
        self.assertEqual(picked(teacher, self.slug, q="错修"), [])
        # a title is given as it reads, whatever it is kept as
        self.assertEqual(picked(teacher, self.slug, q="数字 <1"), [(guess, "p6 蒙眼猜数字 <1>")])
        # a number: the problem with that number comes first
        self.assertEqual(picked(teacher, self.slug, q=str(fix))[0], (fix, "p6 修复一个错误"))
        self.assertEqual(picked(teacher, self.slug, q="#%d" % two)[0], (two, "p6 两数之和"))
        # nothing typed: the newest first
        self.assertEqual([number for number, title in picked(teacher, self.slug, q="")], [guess, fix, two])
        # the problems with given numbers, in the order of the numbers; what is not there is left out
        self.assertEqual(
            picked(teacher, self.slug, numbers="%d, %d 99999" % (guess, two)),
            [(guess, "p6 蒙眼猜数字 <1>"), (two, "p6 两数之和")],
        )
        # what is hidden is said to be
        hidden = {row["number"]: row["hidden"] for row in teacher.get("/problems/pick?scope=%s" % self.slug).json()["problems"]}
        self.assertEqual(hidden, {two: False, fix: True, guess: True})

    def test_nobody_is_shown_what_is_not_theirs_to_see(self):
        numbers = self.numbers
        two = numbers["p6 两数之和"]
        # in a domain: its pupils the problems that are open, who is not in it nothing at all
        self.assertEqual(picked(self.pupil, self.slug, q="p6"), [(two, "p6 两数之和")])
        self.assertEqual(picked(self.pupil, self.slug, numbers=" ".join(str(n) for n in numbers.values())), [(two, "p6 两数之和")])
        for nobody in (self.outsider, uoj.Client()):
            self.assertEqual(picked(nobody, self.slug, q=""), [])
            self.assertEqual(picked(nobody, self.slug, numbers=str(two)), [])
            self.assertEqual(picked(nobody, "no-such-domain", q=""), [])
        # on the site: to read, what is open and what is one's own; to put into a contest, one's own
        found = lambda client, **query: sorted(number for number, title in picked(client, "site", q="p6 求和", **query))
        self.assertEqual(found(self.teacher), sorted([self.public, self.own]))
        self.assertEqual(found(self.teacher, purpose="manage"), [self.own])
        self.assertEqual(found(self.pupil), [self.public])
        self.assertEqual(found(self.pupil, purpose="manage"), [])
        self.assertEqual(found(self.admin, purpose="manage"), sorted([self.public, self.own, self.secret]))
        self.assertEqual(found(uoj.Client()), [])
        # the problems of a domain are not among the problems of the site
        self.assertEqual(picked(self.admin, "site", q="p6 两数之和"), [])

    def test_the_forms_take_what_was_picked(self):
        teacher, slug, numbers = self.teacher, self.slug, self.numbers
        two, fix, guess = (numbers[title] for title in ("p6 两数之和", "p6 修复一个错误", "p6 蒙眼猜数字 <1>"))
        picker = lambda field, scope: r'class="[^"]*uoj-problem-picker[^"]*"[^>]*name="%s"[^>]*data-scope="%s"' % (field, scope)
        # every form that takes problems has the field that picks them, looking in the right place
        self.assertRegex(teacher.get("/d/%s/contest/new" % slug).text, picker("problems", slug))
        self.assertRegex(self.admin.get("/contest/new").text, picker("problems", "site"))
        self.assertRegex(teacher.get("/d/%s/problems" % slug).text, picker("problem_id", "site"))

        # a homework takes several at once, each with the score that was given
        homework_id = p4.new_homework(teacher, slug, title="p6 选题作业")
        manage = "/d/%s/homework/%d/manage" % (slug, homework_id)
        self.assertRegex(teacher.get(manage + "?tab=problems").text, picker("problem_id", slug))
        self.assertEqual(teacher.form(manage, "add_problem", problem_id="%d %d" % (fix, two), score="40"), "")
        in_homework = db(
            "select problems.domain_pid, homework_problems.score from homework_problems, problems"
            " where homework_id = %d and problems.id = homework_problems.problem_id order by position" % homework_id
        )
        self.assertEqual(in_homework, [[str(fix), "40"], [str(two), "40"]])
        # one that is not there is named, and the ones before it stay
        self.assertIn("题目 #99999", teacher.form(manage, "add_problem", problem_id="%d 99999" % guess, score="20"))
        self.assertEqual(db_value("select count(*) from homework_problems where homework_id = %d" % homework_id), "3")
        self.assertIn("本域没有这个题号", teacher.form(manage, "add_problem", problem_id="", score="20"))

        # a training likewise
        self.assertEqual(teacher.form("/d/%s/training/new" % slug, "save", title="p6 选题训练", description_md="", status="draft"), "")
        training_id = int(db_value("select max(id) from trainings where domain_id = %d" % self.did))
        manage = "/d/%s/training/%d/manage" % (slug, training_id)
        self.assertRegex(teacher.get(manage).text, picker("problem_id", slug))
        self.assertEqual(teacher.form(manage, "add_problem", problem_id="%d,%d" % (guess, fix)), "")
        in_training = db(
            "select problems.domain_pid from training_problems, problems"
            " where training_id = %d and problems.id = training_problems.problem_id order by position" % training_id
        )
        self.assertEqual(in_training, [[str(guess)], [str(fix)]])

        # and a contest, as it did before
        contest_id = teacher.new_contest("p6 选题比赛", domain=slug, problems="%d %d" % (two, guess))
        self.assertRegex(teacher.get("/contest/%d/manage" % contest_id).text, picker("number", slug))
        self.assertEqual(teacher.form("/contest/%d/manage" % contest_id, "add_problem", number=str(fix), tab="problems"), "")
        in_contest = db(
            "select problems.domain_pid from contests_problems, problems"
            " where contest_id = %d and problems.id = contests_problems.problem_id order by position" % contest_id
        )
        self.assertEqual(in_contest, [[str(two)], [str(guess)], [str(fix)]])


def upload_files(client, problem_id, files):
    """send files as the data of a problem the way its page does, several at once"""
    return client.post(
        "/problem/%d/manage/data" % problem_id,
        {"form": "upload_files"},
        [("data_files[]", (name, content if isinstance(content, bytes) else content.encode(), "application/octet-stream"))
         for name, content in files.items()],
    )  # fmt: skip


def file_roles(page):
    """the files the page of the data of a problem lists, each with what it is to the problem"""
    return dict(re.findall(r'(?s)<tr data-name="([^"]+)">.*?</td>\s*<td>[^<]*</td>\s*<td><(?:span|small)[^>]*>([^<]*)<', page))


class ProblemDataPageTest(unittest.TestCase):
    """the data of a problem is put together on its page: files one by one, the programs of the
    problem chosen among them, and problem.conf written by choosing, or by hand"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()

    def settings(self, problem_id, **changes):
        fields = dict(form="judge_settings", type="traditional", time_limit="1", memory_limit="256", checker="wcmp", scoring="per_test")
        fields.update(changes)
        return self.admin.post("/problem/%d/manage/data" % problem_id, fields)

    def act(self, problem_id, form, **fields):
        """what the page says after something was done with one of the files: how it went, and the words"""
        manage = "/problem/%d/manage/data" % problem_id
        r = self.admin.post(manage, dict(fields, form=form))
        self.assertEqual(r.status_code, 302, r.text[-300:])
        kind, said = re.search(r'(?s)class="alert alert-(\w+) text-left" role="alert" id="data-flash">(.*?)</div>', self.admin.get(manage).text).groups()
        return kind, said

    def test_checker_is_a_file_that_was_chosen(self):
        admin = self.admin
        problem_id = admin.new_problem(title="p6 选校验器", public="on")
        manage = "/problem/%d/manage/data" % problem_id
        upload_dir = "/var/uoj_data/upload/%d" % problem_id
        self.assertIn('id="data-files-empty"', admin.get(manage).text)

        # ---- files are uploaded as they are, several at once
        files = {"1.in": "1 2\n", "1.out": "3\n", "2.in": "5 5\n", "2.out": "10\n", "sample1.in": "2 2\n", "sample1.out": "4\n",
                 "lenient.cpp": AB_LENIENT_CHECKER, "笔记.md": "notes\n", ".DS_Store": "junk"}  # fmt: skip
        self.assertEqual(upload_files(admin, problem_id, files).status_code, 302)
        page = admin.get(manage).text
        told = uoj.text_of(page)
        for fact in ("写入了 8 个文件", ".DS_Store", "识别到 2 个测试点、1 个额外测试点"):
            self.assertIn(fact, told)
        # the tests were found and called what the judgers call them; every file is listed
        # with what it is to the problem
        roles = file_roles(page)
        self.assertEqual(set(roles), {"data1.in", "data1.out", "data2.in", "data2.out", "ex_data1.in", "ex_data1.out", "lenient.cpp",
                                      "笔记.md", "problem.conf"})  # fmt: skip
        self.assertEqual((roles["data2.in"], roles["ex_data1.out"], roles["lenient.cpp"], roles["笔记.md"], roles["problem.conf"]),
                         ("测试点 2 输入", "样例 1 答案", "没有用到", "没有用到", "评测设置"))  # fmt: skip
        # the programs to choose among are the files that can be built
        self.assertRegex(page, r'(?s)<select[^>]*name="checker_file".*?<option value="lenient.cpp">')
        self.assertNotIn('<option value="笔记.md"', page)

        # ---- what saving would write is shown before anything is saved
        form = dict(type="traditional", time_limit="1", memory_limit="256", checker="custom", checker_file="lenient.cpp", scoring="per_test")
        before = uoj.tree_sha256(uoj.WEB, upload_dir)
        answer = admin.post(manage + "?preview_conf=1", form).json()
        self.assertTrue(answer["ok"], answer)
        for line in ("chk_source lenient.cpp\n", "n_tests 2\n", "n_ex_tests 1\n", "time_limit 1\n"):
            self.assertIn(line, answer["conf"])
        self.assertNotIn("use_builtin_checker", answer["conf"])
        self.assertIn("校验器是 lenient.cpp", answer["notes"])
        for wrong, said in ((dict(time_limit="0"), "时间限制"), (dict(checker_file="gone.cpp"), "gone.cpp"), (dict(checker_file="笔记.md"), "校验器文件")):
            answer = admin.post(manage + "?preview_conf=1", dict(form, **wrong)).json()
            self.assertEqual((answer["ok"], said in answer["error"]), (False, True), answer)
        self.assertEqual(uoj.tree_sha256(uoj.WEB, upload_dir), before)

        # ---- saved: the file that was chosen is the checker, whatever it is called
        self.assertEqual(self.settings(problem_id, **form).status_code, 302)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["chk_source"], "use_builtin_checker" in conf), ("lenient.cpp", False))
        # the judgers are given it under the name they look for
        published = docker_exec(uoj.WEB, "ls /var/uoj_data/%d" % problem_id).split()
        self.assertIn("chk.cpp", published)
        self.assertNotIn("lenient.cpp", published)
        # it lets through what it lets through: an answer that is one too large
        j = uoj.wait_submission(admin.submit(problem_id, AB_WRONG))
        self.assertEqual((j.score, j.infos), (100, ["Accepted", "Accepted", "Extra Test Passed"]), j)
        page = admin.get(manage).text
        self.assertIn('<option value="lenient.cpp" selected="selected">', page)
        self.assertEqual(file_roles(page)["lenient.cpp"], "校验器")

        # ---- a file is renamed: a checker stays the checker under its new name
        for nowhere in ("../lenient.cpp", "/tmp/lenient.cpp", "a/../../x.cpp", "data1.in", ""):
            kind, said = self.act(problem_id, "rename_file", name="lenient.cpp", new_name=nowhere)
            self.assertEqual(kind, "danger", (nowhere, said))
        self.assertEqual(self.act(problem_id, "rename_file", name="no-such-file", new_name="x.cpp")[0], "danger")
        self.assertEqual(self.act(problem_id, "rename_file", name="lenient.cpp", new_name="judge-v2.cpp")[0], "success")
        self.assertEqual(uploaded_conf(problem_id)["chk_source"], "judge-v2.cpp")
        # and moved into the folder of the files the contestants are given
        self.assertEqual(self.act(problem_id, "rename_file", name="笔记.md", new_name="download/说明.md")[0], "success")
        roles = file_roles(admin.get(manage).text)
        self.assertEqual((roles["judge-v2.cpp"], roles["download/说明.md"], "lenient.cpp" in roles, "笔记.md" in roles), ("校验器", "给选手下载", False, False))

        # ---- a file is fetched, and all of them
        r = admin.get(manage + "?download_file=data2.in")
        self.assertEqual((r.status_code, r.content), (200, b"5 5\n"))
        self.assertIn("attachment", r.headers["Content-Disposition"])
        self.assertEqual(admin.get(manage, params={"download_file": "download/说明.md"}).content, b"notes\n")
        for nowhere in ("../../../../etc/passwd", "/etc/passwd", "no-such-file", "download", "../%d/problem.conf" % problem_id):
            self.assertEqual(admin.get(manage, params={"download_file": nowhere}).status_code, 404, nowhere)
        with zipfile.ZipFile(io.BytesIO(admin.get(manage + "?download_all=1").content)) as everything:
            names = everything.namelist()
            self.assertEqual(len(names), 9, names)
            for name in ("data1.in", "data1.out", "data2.in", "data2.out", "ex_data1.in", "ex_data1.out", "judge-v2.cpp", "problem.conf"):
                self.assertIn(name, names)
            self.assertEqual(everything.read("data1.out"), b"3\n")

        # ---- a file is deleted, and a folder with its last file
        for nowhere in ("../problem.conf", "no-such-file", "download", ""):
            self.assertEqual(self.act(problem_id, "delete_file", name=nowhere)[0], "danger", nowhere)
        self.assertEqual(self.act(problem_id, "delete_file", name="download/说明.md")[0], "success")
        self.assertEqual(uploaded_files(problem_id), sorted(["data1.in", "data1.out", "data2.in", "data2.out", "ex_data1.in", "ex_data1.out",
                                                             "judge-v2.cpp", "problem.conf"]))  # fmt: skip
        # what the problem misses afterwards is said, before anybody syncs it
        self.assertEqual(self.act(problem_id, "delete_file", name="data2.out")[0], "success")
        page = admin.get(manage).text
        self.assertIn("有问题，同步会失败", page)
        self.assertIn("缺少文件：data2.out", uoj.text_of(page))
        # a file that is uploaded again takes the place of the one of that name
        self.assertEqual(upload_files(admin, problem_id, {"data2.out": "11\n", "data1.out": "4\n"}).status_code, 302)
        self.assertIn("评测设置没有变", uoj.text_of(admin.get(manage).text))
        self.assertEqual(docker_exec(uoj.WEB, "cat %s/data1.out" % upload_dir), "4\n")
        self.assertIn("文件齐全", admin.get(manage).text)
        # an archive among the files is unpacked
        self.assertEqual(upload_files(admin, problem_id, {"more.zip": uoj.make_zip({"data3.in": "7 8\n", "data3.out": "15\n"})}).status_code, 302)
        self.assertIn("data3.out", uploaded_files(problem_id))
        self.assertNotIn("more.zip", uploaded_files(problem_id))

        # ---- nobody else does any of this
        stranger = p3.account("p6_files_stranger")
        before = uoj.tree_sha256(uoj.WEB, upload_dir)
        self.assertEqual(upload_files(stranger, problem_id, {"x.in": "1\n"}).status_code, 403)
        for attempt in (dict(form="delete_file", name="data1.in"), dict(form="rename_file", name="data1.in", new_name="x.in"),
                        dict(form="conf_text", conf_text="n_tests 1\n")):  # fmt: skip
            self.assertEqual(stranger.post(manage, attempt).status_code, 403, attempt)
        for path in ("?download_file=data1.in", "?download_all=1", "?preview_conf=1"):
            self.assertEqual(stranger.get(manage + path).status_code, 403, path)
        self.assertEqual(uoj.tree_sha256(uoj.WEB, upload_dir), before)

    def test_problem_conf_is_written_by_choosing_and_by_hand(self):
        admin = self.admin
        # an interactive problem whose interactor is not called what the judgers call one
        problem_id = admin.new_problem(title="p6 选交互器", type="interactive", public="on",
                                       files=[data_zip({"judge.cpp": DOUBLE_INTERACTOR, "1.in": "21\n", "2.in": "1000\n"})])  # fmt: skip
        manage = "/problem/%d/manage/data" % problem_id
        upload_dir = "/var/uoj_data/upload/%d" % problem_id
        conf = uploaded_conf(problem_id)
        self.assertEqual((conf["interaction_mode"], conf["n_tests"], "interactor_source" in conf), ("on", "2", False))
        # nothing was guessed, and the page says what is missing
        page = admin.get(manage).text
        self.assertIn("还没有交互器", uoj.text_of(page))
        self.assertRegex(page, r'(?s)<select[^>]*name="interactor_file"[^>]*><option value="">请选择文件</option><option value="judge.cpp">')
        self.assertIn("有问题，同步会失败", page)

        # ---- chosen in the form
        self.assertEqual(self.settings(problem_id, type="interactive", interactor_file="judge.cpp").status_code, 302)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(published_conf(problem_id)["interactor_source"], "judge.cpp")
        self.assertIn("interactor.cpp", docker_exec(uoj.WEB, "ls /var/uoj_data/%d" % problem_id).split())
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, DOUBLE)).score, 100)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, DOUBLE_WRONG)).score, 0)

        # ---- problem.conf beside the form: as it is, and not to be typed into until one says so
        page = admin.get(manage).text
        shown = re.search(r'(?s)<textarea[^>]*id="conf-text"[^>]*readonly="readonly"[^>]*>(.*?)</textarea>', page).group(1)
        written = docker_exec(uoj.WEB, "cat %s/problem.conf" % upload_dir)
        self.assertEqual(shown.strip(), written.strip())
        self.assertIn("interactor_source judge.cpp", shown)
        self.assertIn('id="switch-edit-conf"', page)

        # ---- written by hand: what the form has no field for
        r = admin.post(manage, {"form": "conf_text", "conf_text": written + "time_limit_2 3\n\n"})
        self.assertEqual(r.status_code, 302, r.text[-300:])
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(published_conf(problem_id)["time_limit_2"], "3")
        self.assertIn("problem.conf 已保存", uoj.text_of(admin.get(manage).text))
        self.assertEqual(db_value(
            "select count(*) from audit_logs where action = 'problem.edit_conf' and resource_id = '%d' and after_json like '%%written_as_text%%'" % problem_id
        ), "1")  # fmt: skip
        # and the form keeps it when it is saved after that
        self.assertEqual(self.settings(problem_id, type="interactive", interactor_file="judge.cpp", time_limit="2").status_code, 302)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["time_limit_2"], conf["time_limit"], conf["interactor_source"]), ("3", "2", "judge.cpp"))

        # ---- what is no problem.conf is said, written nowhere, and still there to be put right
        before = uoj.tree_sha256(uoj.WEB, upload_dir)
        for wrong, said in (("n_tests 2\ntime limit 3\n", "第 2 行"), ("n_tests 2\nn_tests 3\n", "写了两次"), ("  \n", "空的")):
            r = admin.post(manage, {"form": "conf_text", "conf_text": wrong})
            self.assertEqual(r.status_code, 200)
            self.assertIn('id="conf-text-error"', r.text)
            self.assertIn(said, uoj.text_of(r.text), wrong)
        self.assertRegex(r.text, r'(?s)<textarea[^>]*id="conf-text"[^>]*>\s*</textarea>')
        r = admin.post(manage, {"form": "conf_text", "conf_text": "n_tests 2\ntime limit 3\n"})
        self.assertIn("time limit 3", re.search(r'(?s)<textarea[^>]*id="conf-text"[^>]*>(.*?)</textarea>', r.text).group(1))
        self.assertNotIn('readonly="readonly"', re.search(r'<textarea[^>]*id="conf-text"[^>]*>', r.text).group(0))
        self.assertEqual(uoj.tree_sha256(uoj.WEB, upload_dir), before)
        # a problem.conf that can be read and not judged with is kept, and the sync says why not
        r = admin.post(manage, {"form": "conf_text", "conf_text": "use_builtin_judger on\nn_tests 5\n"})
        self.assertEqual(r.status_code, 302)
        page = admin.get(manage).text
        self.assertIn("数据没有通过检查", uoj.text_of(page))
        self.assertEqual(uploaded_conf(problem_id), {"use_builtin_judger": "on", "n_tests": "5"})
