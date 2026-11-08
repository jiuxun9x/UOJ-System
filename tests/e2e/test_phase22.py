"""Phase 22: what a review found of hidden contests, of the people who look after a homework, and
of the testers of a contest."""

import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account
from test_phase4 import homework_form, homework_row, member_form, new_homework, tick
from uoj import db, db_value


class HiddenContestOverTest(unittest.TestCase):
    """A hidden contest was a contest for everybody as soon as it was over, if anybody might take
    part in it: what was submitted to it was shown to everybody, in the lists and by its address.
    It stays with who may go inside it, as its pages do."""

    def test_what_was_submitted_to_a_hidden_contest_stays_with_it(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p22 隐藏比赛赛后", rule="ICPC", problems=str(problem_id), hidden="on")
        pupil, stranger = account("p22_hc_pupil"), account("p22_hc_stranger")
        self.assertEqual(admin.form("/contest/%d/manage" % contest_id, "allow", names="p22_hc_pupil", tab="access"), "")
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -30 * 60, 60)
        submission_id = pupil.submit_in_contest(contest_id, problem_id, AB)
        uoj.wait_submission(submission_id)
        uoj.move_contest(contest_id, -120 * 60, 60)
        page, listed = "/submission/%d" % submission_id, 'href="/submission/%d"' % submission_id
        for client in (uoj.Client(), stranger):
            self.assertNotEqual(client.get(page).status_code, 200)
            self.assertNotIn(listed, client.get("/submissions?problem_id=%d" % problem_id).text)
        # who took part, and the administrators, see it as before
        for client in (pupil, admin):
            self.assertEqual(client.get(page).status_code, 200)
            self.assertIn(listed, client.get("/submissions?problem_id=%d" % problem_id).text)
        # shown, it is a contest like any other
        self.assertEqual(admin.contest_settings(contest_id), "")
        self.assertEqual(stranger.get(page).status_code, 200)


class HomeworkMaintainerLeftTest(unittest.TestCase):
    """Who was asked to look after a homework and was taken out of its domain was shown what was
    submitted to it in the lists of submissions still, and looked after it again when they came
    back. They look after it while they are members of the domain, and taken out of it they look
    after it no more."""

    def test_who_is_taken_out_of_a_domain_looks_after_its_homework_no_more(self):
        admin = uoj.admin()
        teacher, keeper, pupil, outsider = account("p22_hw_teacher"), account("p22_hw_keeper"), account("p22_hw_pupil"), account("p22_hw_out")
        self.assertEqual(admin.change_user("p22_hw_teacher", "grant:teacher"), "")
        slug = "p22-hw"
        teacher.new_domain(slug)
        for client in (keeper, pupil):
            self.assertEqual(member_form(teacher, slug, "add", username=client.username, role="member"), "")
        self.assertEqual(teacher.new_problem_form(slug), "")
        problem_id = int(db_value("select max(id) from problems where owner_domain_id = (select id from domains where slug = '%s')" % slug))
        self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(problem_id), "")
        homework_id = new_homework(teacher, slug, title="p22 维护者离开")
        self.assertEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(uoj.pid(problem_id)), score="100"), "")
        self.assertEqual(homework_form(teacher, slug, homework_id, "publish"), "")
        uoj.wait_until("the homework is published", lambda: tick() and homework_row(homework_id, "status")[0] == "published", timeout=300)
        self.assertEqual(homework_form(teacher, slug, homework_id, "add_maintainer", username="p22_hw_keeper"), "")
        self.assertEqual(pupil.form("/d/%s/homework/%d" % (slug, homework_id), "claim"), "")
        submission_id = pupil.submit(problem_id, AB, path="/d/%s/homework/%d/problem/%d" % (slug, homework_id, uoj.pid(problem_id)))
        uoj.wait_submission(submission_id)
        listed = 'href="/submission/%d"' % submission_id
        lists = lambda client: client.get("/submissions?problem_id=%d" % problem_id).text
        self.assertIn(listed, lists(keeper))

        # taken out of the domain, they look after it no more: not in the lists either
        self.assertEqual(member_form(teacher, slug, "remove", username="p22_hw_keeper"), "")
        self.assertEqual(db("select username from homework_maintainers where homework_id = %d" % homework_id), [])
        self.assertNotIn(listed, lists(keeper))
        # and back in the domain, they are a member like any other
        self.assertEqual(member_form(teacher, slug, "add", username="p22_hw_keeper", role="member"), "")
        self.assertNotIn(listed, lists(keeper))
        self.assertEqual(keeper.get("/d/%s/homework/%d/manage" % (slug, homework_id)).status_code, 403)
        # nor does anybody asked before this was so, who is not in the domain
        db("insert into homework_maintainers (homework_id, username) values (%d, 'p22_hw_out')" % homework_id)
        self.assertNotIn(listed, lists(outsider))
        self.assertIn(listed, lists(teacher))


if __name__ == "__main__":
    unittest.main()
