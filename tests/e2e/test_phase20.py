"""Phase 20: hidden contests, and what a review of judging found (J01-J07, D01, D02, A01, A02)."""

import json
import unittest

import uoj
from fixtures import AB, AB_HACKABLE, ab_problem_files
from test_phase3 import account
from test_phase16 import members
from uoj import conf, db, db_value, docker_exec

# a checker of a+b that went wrong where an answer differs: it says FAIL, as testlib says when a
# check of its own fails; put right, it says the answer is wrong
CHECKER = r"""
#include "testlib.h"
int main(int argc, char *argv[]) {
    registerTestlibCmd(argc, argv);
    long long expected = ans.readLong(), found = ouf.readLong();
    if (expected != found) {
        quitf(%s, "expected %%lld, found %%lld", expected, found);
    }
    quitf(_ok, "%%lld", found);
}
"""


def with_checker(verdict):
    files = ab_problem_files()
    files["problem.conf"] = "".join(line + "\n" for line in files["problem.conf"].splitlines() if not line.startswith("use_builtin_checker"))
    files["chk.cpp"] = CHECKER % verdict
    return files


class HackJudgementFailedTest(unittest.TestCase):
    """A hack whose checker failed was a hack that succeeded: what the checker said FAIL of, or
    crashed on, was taken for a wrong answer, and the input was added to the data. The judgement
    fails now, the hack neither succeeds nor fails, nothing is added, and the input is kept to
    judge it again once the checker is put right. (J03)"""

    def test_a_checker_that_fails_makes_no_successful_hack(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(with_checker("_fail"), hackable=True)
        submission_id = admin.submit(problem_id, AB_HACKABLE % 4242)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        version = db_value("select data_version from problems where id = %d" % problem_id)

        hack_id = admin.hack(submission_id, b"4242 1\n")
        self.assertFalse(uoj.wait_hack(hack_id))
        success, error, details, path = db("select success, ifnull(result_error, 'NULL'), details, input from hacks where id = %d" % hack_id)[0]
        self.assertEqual((success, error), ("0", "Judgment Failed"), details)
        self.assertIn("Checker Judgment Failed", details)
        self.assertIn("expected 4243, found 0", details)
        # nothing was added to the data, and the input is kept
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), version)
        docker_exec(uoj.WEB, "test -f /opt/uoj/web/app/storage%s" % path)
        page = admin.get("/hack/%d" % hack_id).text
        self.assertIn("Judgement Failed", page)
        self.assertIn('id="hack-judgement-failed"', page)
        # only who manages the problem judges it again
        self.assertNotIn('id="hack-judgement-failed"', uoj.Client().get("/hack/%d" % hack_id).text)

        # ---- the checker put right, the hack is judged again: it succeeds, and its input is a test
        r = admin.upload_data(problem_id, with_checker("_wa"))
        self.assertIn("上传成功", r.text)
        self.assertEqual(admin.sync(problem_id), "")
        version = int(db_value("select data_version from problems where id = %d" % problem_id))
        self.assertEqual(admin.submit_form("/hack/%d" % hack_id, "rejudge"), "")
        self.assertTrue(uoj.wait_hack(hack_id))
        self.assertEqual(db_value("select ifnull(result_error, 'NULL') from hacks where id = %d" % hack_id), "NULL")
        # its input is an extra test of a new version of the data
        uoj.wait_until("the input of the hack is a test", lambda: int(db_value("select data_version from problems where id = %d" % problem_id)) > version)


class RejudgeBetweenTheTwoTestsTest(unittest.TestCase):
    """A submission of an OI contest is judged with the samples while the contest runs, then with
    all the data when the judgers have nothing else to do. Judged again while it waited for that
    second test, it was left Judged without a score: the test of the samples was skipped, and the
    test with all the data was taken for the second of two, which keeps the score of the first.
    It begins with the samples again now. (J05)"""

    def test_a_submission_judged_again_between_its_two_tests_is_judged_from_the_samples(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p20 两段评测中重测", rule="OI", problems=str(problem_id))
        pupil = uoj.client(("p20_rejudge", "p20-password"))
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        with uoj.judgers_paused():
            submission_id = pupil.submit_in_contest(contest_id, problem_id, AB)
            # tested with the sample, the way judge_client does it: it waits for all the data
            task = uoj.fake_fetch()
            self.assertEqual(task["id"], submission_id)
            sample = {"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}
            uoj.judge_api("/judge/submit", {"fetch_new": "0", "id": submission_id, "worker": uoj.FAKE_WORKER, "claim": task["claim"], "submit": "1", "result": json.dumps(sample)})
            self.assertEqual(db_value("select status from submissions where id = %d" % submission_id), "Judged, Waiting")
            # who manages the problem judges everything of it again
            self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "rejudge"), "")
            status, content = db("select status, content from submissions where id = %d" % submission_id)[0]
            self.assertEqual(status, "Waiting Rejudge")
            content = json.loads(content)
            self.assertNotIn("first_test_config", content)
            self.assertIn("final_test_config", content)
        uoj.wait_idle()
        # both tests done again, one after the other: the score of the samples, the result of all the data
        status, score, final = db("select status, ifnull(score, 'NULL'), ifnull(json_extract(convert(result using utf8mb4), '$.final_result.score'), 'NULL') from submissions where id = %d" % submission_id)[0]
        self.assertEqual((status, score, final), ("Judged", "100", "100"))
        self.assertEqual(db_value("select count(*) from best_ac_submissions where submission_id = %d" % submission_id), "1")


def answers_problem(n_tests, submit_answer=True):
    """a problem of answers with so many tests (or the same as a problem of programs)"""
    settings = dict(use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=n_tests, n_ex_tests=0, n_sample_tests=0,
                    input_pre="input", input_suf="txt", output_pre="output", output_suf="txt", time_limit=1, memory_limit=256)  # fmt: skip
    if submit_answer:
        settings["submit_answer"] = "on"
    files = {"problem.conf": conf(**settings)}
    for num in range(1, n_tests + 1):
        files["input%d.txt" % num] = "%d\n" % num
        files["output%d.txt" % num] = "%d\n" % num
    if not submit_answer:
        files["std.cpp"] = "#include <cstdio>\nint main() { int x; scanf(\"%d\", &x); printf(\"%d\\n\", x); }\n"
    return files


class SubmissionFilesFollowTheDataTest(unittest.TestCase):
    """The files a submission is made of were put down the first time the data of a problem was
    published, and never again: a problem of answers that got a second test asked for one answer
    still, and one that became a problem of programs for answers. They follow the data now,
    unless somebody wrote them by hand. (D02)"""

    def test_the_files_of_a_submission_follow_the_data(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(answers_problem(1))
        requirement = lambda: json.loads(db_value("select submission_requirement from problems where id = %d" % problem_id))
        publish = lambda files: (self.assertIn("上传成功", admin.upload_data(problem_id, files).text), self.assertEqual(admin.sync(problem_id), ""))
        self.assertEqual([item["file_name"] for item in requirement()], ["output1.txt"])
        # a test more is an answer more
        publish(answers_problem(2))
        self.assertEqual([item["file_name"] for item in requirement()], ["output1.txt", "output2.txt"])
        page = admin.get("/problem/%d" % problem_id).text
        self.assertIn("output2.txt", page)
        # a problem of programs takes a program, and of answers again its answers
        publish(answers_problem(2, submit_answer=False))
        self.assertEqual(requirement(), [{"name": "answer", "type": "source code", "file_name": "answer.code"}])
        publish(answers_problem(3))
        self.assertEqual(len(requirement()), 3)

        # ---- written by hand, they stay as they are, and the page says what the data asks for
        extra = db_value("select json_extract(extra_config, '$') from problems where id = %d" % problem_id)
        mine = [{"name": "output1", "type": "text", "file_name": "output1.txt"}, {"name": "notes", "type": "text", "file_name": "notes.txt"}]
        self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "info", {"submission_requirement": json.dumps(mine), "extra_config": extra}), "")
        publish(answers_problem(4))
        self.assertEqual(requirement(), mine)
        self.assertIn('id="requirement-custom"', admin.get("/problem/%d/manage/data" % problem_id).text)
        # written back to nothing, they follow the data again
        extra = db_value("select json_extract(extra_config, '$') from problems where id = %d" % problem_id)
        self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "info", {"submission_requirement": "[]", "extra_config": extra}), "")
        publish(answers_problem(4))
        self.assertEqual(len(requirement()), 4)


class DomainContestStaffTest(unittest.TestCase):
    """The staff of a contest of a domain stayed its staff when they were taken out of the domain:
    its pages were closed to them, and the code of its contestants was not, by the addresses of
    their submissions. They are its staff while they are members of the domain now, and taken
    out of it they are taken off its staff. (A01)"""

    def test_who_is_taken_out_of_a_domain_is_staff_of_its_contests_no_more(self):
        admin = uoj.admin()
        teacher = account("p20_a01_teacher")
        self.assertEqual(admin.change_user("p20_a01_teacher", "grant:teacher"), "")
        teacher.new_domain("p20-staff")
        helper, pupil, classmate, outsider = account("p20_a01_helper"), account("p20_a01_pupil"), account("p20_a01_mate"), account("p20_a01_out")
        for client in (helper, pupil, classmate):
            self.assertEqual(members(teacher, "p20-staff", "add", username=client.username, role="member"), "")
        problem_id = teacher.new_problem("p20-staff")
        self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(problem_id), "")
        # what is submitted to it is seen by its submitter and the staff alone
        db("update problems set extra_config = '{\"view_content_type\": \"SELF\", \"view_all_details_type\": \"SELF\", \"view_details_type\": \"SELF\"}' where id = %d" % problem_id)
        contest_id = teacher.new_contest("p20 域内比赛工作人员", domain="p20-staff", rule="IOI", problems=str(uoj.pid(problem_id)))
        self.assertEqual(teacher.contest_commands(contest_id, "managers", "+p20_a01_helper"), "")
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -30 * 60, 60)
        submission_id = pupil.submit_in_contest(contest_id, problem_id, AB)
        uoj.wait_submission(submission_id)
        source = "/submission/%d" % submission_id
        listed = 'href="/submission/%d"' % submission_id
        self.assertEqual(helper.get(source).status_code, 200)
        self.assertIn("scanf", helper.get(source).text)
        self.assertIn(listed, helper.get("/submissions?problem_id=%d" % problem_id).text)
        self.assertNotEqual(classmate.get(source).status_code, 200)

        # taken out of the domain, the helper is off the staff of its contests, and reads nothing
        self.assertEqual(members(teacher, "p20-staff", "remove", username="p20_a01_helper"), "")
        self.assertEqual(db_value("select count(*) from contests_permissions where contest_id = %d and username = 'p20_a01_helper'" % contest_id), "0")
        for client in (helper, outsider):
            page = client.get(source)
            self.assertNotEqual(page.status_code, 200, client.username)
            self.assertNotIn("scanf", page.text)
            self.assertNotIn(listed, client.get("/submissions?problem_id=%d" % problem_id).text, client.username)
        # nor does anybody made staff of it before this was so, who is not in the domain
        db("insert into contests_permissions (username, contest_id, role) values ('p20_a01_out', %d, 'assistant')" % contest_id)
        self.assertNotEqual(outsider.get(source).status_code, 200)
        self.assertNotIn(listed, outsider.get("/submissions?problem_id=%d" % problem_id).text)
        self.assertNotIn("scanf", outsider.get(source).text)
        # the staff who are members, and the administrators of the site, read it as before
        self.assertIn("scanf", teacher.get(source).text)
        self.assertIn("scanf", admin.get(source).text)


class ArchivedDomainSubmissionTest(unittest.TestCase):
    """An archived domain is read only, and its students still submitted to its problems, which
    were judged. Nothing is submitted there any more: not code, not answers, not a custom test.
    (A02)"""

    def test_nothing_is_submitted_in_an_archived_domain(self):
        admin = uoj.admin()
        teacher = account("p20_a02_teacher")
        self.assertEqual(admin.change_user("p20_a02_teacher", "grant:teacher"), "")
        teacher.new_domain("p20-archived")
        pupil = account("p20_a02_pupil")
        self.assertEqual(members(teacher, "p20-archived", "add", username="p20_a02_pupil", role="member"), "")
        problem_id = teacher.new_problem("p20-archived")
        self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(problem_id), "")
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        path = "/d/p20-archived/problem/%d" % uoj.pid(problem_id)
        count = lambda table: db_value("select count(*) from %s where problem_id = %d" % (table, problem_id))
        pupil.submit(problem_id, AB, path=path)
        self.assertEqual(count("submissions"), "1")

        self.assertEqual(teacher.form("/d/p20-archived/settings", "archive"), "")
        # it is read as before, and says nothing is submitted to it
        page = pupil.get(path)
        self.assertEqual(page.status_code, 200)
        self.assertIn('id="problem-archived"', page.text)
        with self.assertRaises(Exception) as refused:
            pupil.submit(problem_id, AB, path=path)
        self.assertIn("这个域已经归档", str(refused.exception))
        err = pupil.submit_form(path, "custom_test", {
            "custom_test_answer_upload_type": "editor", "custom_test_answer_editor": AB, "custom_test_answer_language": "C++17",
            "custom_test_input_upload_type": "editor", "custom_test_input_editor": "1 2\n",
        })  # fmt: skip
        self.assertIn("这个域已经归档", err)
        self.assertEqual((count("submissions"), count("custom_test_submissions")), ("1", "0"))

        # brought back, it is submitted to again
        self.assertEqual(teacher.form("/d/p20-archived/settings", "archive"), "")
        pupil.submit(problem_id, AB, path=path)
        self.assertEqual(count("submissions"), "2")


class HiddenContestTest(unittest.TestCase):
    """A contest can be hidden while its problems are tested, as Hydro hides one: only the
    administrators, its staff, the testers it invited (as Codeforces invites them) and the people
    on its list see it. The testers read and submit to its problems before it begins, on no board,
    and see its boards; its outer board is for them and the staff alone. It is shown by hand."""

    def test_a_hidden_contest_is_seen_by_who_tests_it(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p20 隐藏的比赛", starts_in=7200, rule="ICPC", problems=str(problem_id), hidden="on")
        self.assertEqual(db_value("select is_hidden from contests where id = %d" % contest_id), "1")
        tester, listed, stranger = account("p20_hid_tester"), account("p20_hid_listed"), account("p20_hid_stranger")
        here, board, manage = "/contest/%d" % contest_id, "/contest/%d/board" % contest_id, "/contest/%d/manage" % contest_id
        self.assertIn('id="input-contest-hidden" name="hidden" checked="checked"', admin.get(manage).text)
        self.assertIn('id="contest-hidden-notice"', admin.get(here).text)

        # ---- nobody else sees it: not in the list, not by its address, not its outer board
        for client in (uoj.Client(), stranger):
            self.assertNotIn("p20 隐藏的比赛", client.get("/contests").text)
            self.assertIn(client.get(here).status_code, (302, 404))
            self.assertIn(client.get(board).status_code, (302, 404))
            self.assertIn(client.get(here + "/problem/A").status_code, (302, 404))

        # ---- a tester, invited as Codeforces invites one, and told of it
        self.assertEqual(admin.form(manage, "add_tester", username="p20_hid_tester", tab="managers"), "")
        self.assertEqual(db_value("select role from contests_permissions where contest_id = %d and username = 'p20_hid_tester'" % contest_id), "tester")
        self.assertEqual(db_value("select count(*) from user_system_msg where receiver = 'p20_hid_tester' and title = '验题邀请' and content like '%%/contest/%d%%'" % contest_id), "1")
        self.assertIn('id="table-contest-testers"', admin.get(manage).text)
        self.assertIn("p20 隐藏的比赛", tester.get("/contests").text)
        # before it begins, the contest is open to them: its page, its problems, a submission (to no board)
        page = tester.get(here)
        self.assertEqual(page.status_code, 200)
        self.assertIn('id="contest-hidden-notice"', page.text)
        self.assertEqual(tester.get(here + "/problem/A").status_code, 200)
        submission_id = tester.submit(problem_id, AB, path=here + "/problem/A")
        self.assertEqual(db_value("select ifnull(contest_id, 'NULL') from submissions where id = %d" % submission_id), "NULL")
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        self.assertEqual(tester.get(board).status_code, 200)
        self.assertIn('id="outer-board"', tester.get(board).text)
        # and they are none of its staff: no management, no registering
        self.assertEqual(tester.get(manage).status_code, 403)
        self.assertNotEqual(tester.get("/contest/%d/backstage" % contest_id).status_code, 200)
        # (the page of registering sends them away)
        tester.submit_form("/contest/%d/register" % contest_id, "register")
        self.assertEqual(db_value("select count(*) from contests_registrants where contest_id = %d and username = 'p20_hid_tester'" % contest_id), "0")

        # ---- the people on its list, as Hydro has it: they see it and take part
        self.assertEqual(admin.form(manage, "allow", names="p20_hid_listed", tab="access"), "")
        self.assertIn("p20 隐藏的比赛", listed.get("/contests").text)
        listed.register_for_contest(contest_id)
        # the outer board is for the staff and the testers alone
        self.assertNotIn('id="outer-board"', listed.get(board).text)
        self.assertNotIn('id="outer-board"', stranger.get(board).text)

        # ---- shown by hand, it is a contest like any other
        self.assertEqual(admin.contest_settings(contest_id), "")
        self.assertEqual(db_value("select is_hidden from contests where id = %d" % contest_id), "0")
        self.assertIn("p20 隐藏的比赛", stranger.get("/contests").text)
        self.assertIn('id="outer-board"', uoj.Client().get(board).text)
        self.assertNotIn('id="contest-hidden-notice"', admin.get(here).text)


if __name__ == "__main__":
    unittest.main()
