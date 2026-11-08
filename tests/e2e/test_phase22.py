"""Phase 22: what a review found of hidden contests, of the people who look after a homework, and
of the testers of a contest."""

import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account
from uoj import db_value


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


if __name__ == "__main__":
    unittest.main()
