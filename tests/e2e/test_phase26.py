"""Phase 26: what is submitted in a contest is listed with it, and on the list of the site, for
everybody who may see the contest, while it runs as well: how it did is told when the contest tells
it. What is submitted through a contest that is over - to practise, or while sitting it virtually -
is listed with the contest too. Whoever submits in a contest is taken to their own submissions in it."""

import re
import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account
from uoj import db, db_value

# whose results are not told: the row says so, and nothing of how it did
KEPT = "uoj-result-kept"


def submit_through(client, contest_id, problem_id, code):
    """submit to a problem on the page of a contest: (where the page sends one, the id of the submission)"""
    r = client.post("/contest/%d/problem/%d" % (contest_id, uoj.pid(problem_id)), {
        "submit-answer": "answer",
        "answer_answer_upload_type": "editor",
        "answer_answer_editor": code,
        "answer_answer_language": "C++17",
    })  # fmt: skip
    assert r.status_code in (301, 302), uoj.text_of(r.text)[-800:]
    submission_id = int(db_value("select max(id) from submissions where submitter = '%s' and problem_id = %d" % (client.username, problem_id)))
    return r.headers["Location"], submission_id


def row_of(page, submission_id):
    """the row of a list of submissions that is the submission's, as its html; None if it is not listed"""
    found = re.search(r'<tr[^>]*>\s*<td><a href="/submission/%d">.*?</tr>' % submission_id, page, re.S)
    return found.group(0) if found else None


def everybody(client, contest_id):
    """the submissions of a contest, everybody's, as the box above the list asks for them"""
    client.http.cookies.set("show_all_submissions", "")
    try:
        return client.get("/contest/%d/submissions" % contest_id).text
    finally:
        client.http.cookies.set("show_all_submissions", "0")


def readable_problem():
    """a problem that shows everybody the code and the details of what is submitted to it"""
    return uoj.admin().create_problem(ab_problem_files(), extra_config={"view_content_type": "ALL", "view_all_details_type": "ALL"})


class RunningContestTest(unittest.TestCase):
    """What was submitted in a contest that ran was its submitter's alone, on the list of the contest
    and on the list of the site: the others saw nothing when they asked for everybody's. They see
    that it was submitted, by whom, when and to what; how it did they are told when the contest
    tells it."""

    def test_the_others_see_it_and_are_not_told_how_it_did(self):
        admin, problem_id = uoj.admin(), readable_problem()
        contest_id = admin.new_contest("p26 进行中", rule="ICPC", problems=str(problem_id))
        ann, bob = account("p26_run_ann"), account("p26_run_bob")
        for client in (ann, bob):
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 60)
        where, submission_id = submit_through(ann, contest_id, problem_id, AB + "// p26-ann-source\n")
        # ---- who submits is taken to their own submissions in the contest
        self.assertEqual(where, "/contest/%d/submissions?mine=1" % contest_id)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        mine = ann.get(where).text
        self.assertIsNotNone(row_of(mine, submission_id))
        self.assertNotIn(KEPT, row_of(mine, submission_id))
        # (even who asked for everybody's before)
        ann.http.cookies.set("show_all_submissions", "")
        self.assertIn('id="input-show_all_submissions" />', ann.get(where).text)
        ann.http.cookies.set("show_all_submissions", "0")

        # ---- the others: on the list of the contest and of the site, and its page, told nothing
        for page in (everybody(bob, contest_id), bob.get("/submissions?problem_id=%d" % problem_id).text, uoj.Client().get("/submissions?problem_id=%d" % problem_id).text):
            row = row_of(page, submission_id)
            self.assertIsNotNone(row)
            self.assertIn(KEPT, row)
            self.assertNotIn("uoj-verdict", row)
            self.assertNotIn("ms</td>", row)
        page = bob.get("/submission/%d" % submission_id)
        self.assertEqual(page.status_code, 200)
        self.assertIn(KEPT, page.text)
        self.assertNotIn("p26-ann-source", page.text)
        # nor are they told by looking for what scored full marks, or at what passed fastest
        self.assertIsNone(row_of(bob.get("/submissions?problem_id=%d&min_score=100" % problem_id).text, submission_id))
        self.assertIsNone(row_of(bob.get("/problem/%d/statistics" % problem_id).text, submission_id))
        self.assertIsNotNone(row_of(ann.get("/submissions?problem_id=%d&min_score=100" % problem_id).text, submission_id))
        # the staff are told everything, as before
        self.assertNotIn(KEPT, row_of(everybody(admin, contest_id), submission_id))
        self.assertIn("p26-ann-source", admin.get("/submission/%d" % submission_id).text)

        # ---- the contest is over: everybody is told
        uoj.move_contest(contest_id, -7200, 60)
        row = row_of(bob.get("/submissions?problem_id=%d" % problem_id).text, submission_id)
        self.assertNotIn(KEPT, row)
        self.assertIn('class="uoj-verdict text-success"', row)
        self.assertIn("p26-ann-source", bob.get("/submission/%d" % submission_id).text)
        self.assertIsNotNone(row_of(bob.get("/problem/%d/statistics" % problem_id).text, submission_id))

    def test_a_board_that_froze_tells_nothing_until_its_results_are_published(self):
        admin, problem_id = uoj.admin(), readable_problem()
        contest_id = admin.new_contest("p26 封榜", minutes=300, rule="ICPC", freeze_minutes="60", problems=str(problem_id))
        ann, bob = account("p26_frz_ann"), account("p26_frz_bob")
        ann.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -250 * 60, 300)
        submission_id = ann.submit_in_contest(contest_id, problem_id, AB)
        uoj.wait_submission(submission_id)
        # over, and not published: listed, and not told
        uoj.move_contest(contest_id, -301 * 60, 300)
        self.assertIn(KEPT, row_of(bob.get("/submissions?problem_id=%d" % problem_id).text, submission_id))
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertNotIn(KEPT, row_of(bob.get("/submissions?problem_id=%d" % problem_id).text, submission_id))

    def test_a_contest_that_is_not_for_everybody_keeps_it_with_who_took_part(self):
        admin, problem_id = uoj.admin(), readable_problem()
        contest_id = admin.new_contest("p26 密码赛", rule="ICPC", problems=str(problem_id), join_mode="password", join_password="p26 sesame")
        ann, mate, stranger = account("p26_locked_ann"), account("p26_locked_mate"), account("p26_locked_stranger")
        for client in (ann, mate):
            self.assertEqual(client.submit_form("/contest/%d/register" % contest_id, "register", {"join_password": "p26 sesame"}), "")
        uoj.move_contest(contest_id, -60, 60)
        submission_id = ann.submit_in_contest(contest_id, problem_id, AB)
        self.assertIn(KEPT, row_of(mate.get("/submissions?problem_id=%d" % problem_id).text, submission_id))
        for client in (stranger, uoj.Client()):
            self.assertIsNone(row_of(client.get("/submissions?problem_id=%d" % problem_id).text, submission_id))
            self.assertNotEqual(client.get("/submission/%d" % submission_id).status_code, 200)


class AfterTheContestTest(unittest.TestCase):
    """What was submitted on the page of a contest that is over was a submission to the problem and
    to nothing else: not on the list of the contest, and the page went on to the list of the site.
    It is listed with the contest and on the list of the site, for everybody who may see the
    contest, and counts on no board."""

    def test_what_is_submitted_to_practise_is_listed_with_the_contest(self):
        admin, problem_id = uoj.admin(), readable_problem()
        contest_id = admin.new_contest("p26 赛后", rule="ICPC", problems=str(problem_id))
        ann, bob = account("p26_after_ann"), account("p26_after_bob")
        ann.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 60)
        uoj.wait_submission(ann.submit_in_contest(contest_id, problem_id, AB))
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        standings = admin.get("/contest/%d/standings" % contest_id).text

        # bob took no part: he practises on its problems now
        where, submission_id = submit_through(bob, contest_id, problem_id, AB + "// p26-practice\n")
        self.assertEqual(where, "/contest/%d/submissions?mine=1" % contest_id)
        self.assertEqual(db("select ifnull(contest_id, 'none'), practice_contest_id, is_virtual, is_hidden from submissions where id = %d" % submission_id), [["none", str(contest_id), "0", "0"]])
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        self.assertIsNotNone(row_of(bob.get(where).text, submission_id))
        # for everybody who may see the contest, with the contest and on the list of the site, marked
        for page in (everybody(ann, contest_id), ann.get("/submissions?problem_id=%d" % problem_id).text, uoj.Client().get("/submissions?problem_id=%d" % problem_id).text):
            row = row_of(page, submission_id)
            self.assertIsNotNone(row)
            self.assertIn("uoj-after-contest", row)
            self.assertNotIn(KEPT, row)
        self.assertIn("p26-practice", ann.get("/submission/%d" % submission_id).text)
        # and on no board
        self.assertNotIn("p26_after_bob", admin.get("/contest/%d/standings" % contest_id).text)
        self.assertEqual(admin.get("/contest/%d/standings" % contest_id).text.count("p26_after_ann"), standings.count("p26_after_ann"))


class VirtualParticipationTest(unittest.TestCase):
    """What somebody submitted while sitting a contest virtually was on the list of the contest for
    them alone. It is listed with the contest for everybody, under a star; while they sit it they
    are told nothing of how anybody else did on its problems, and nobody else of how they did."""

    def test_what_is_submitted_virtually_is_listed_under_a_star(self):
        admin, problem_id = uoj.admin(), readable_problem()
        contest_id = admin.new_contest("p26 虚拟参赛", rule="ICPC", problems=str(problem_id))
        here = "/contest/%d" % contest_id
        ann, cat, bob = account("p26_vp_ann"), account("p26_vp_cat"), account("p26_vp_bob")
        ann.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 60)
        ann_id = ann.submit_in_contest(contest_id, problem_id, AB + "// p26-vp-ann\n")
        uoj.wait_submission(ann_id)
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form(here, "publish_result"), "")

        r = cat.post(here + "/virtual", {"form": "start"})
        self.assertEqual((r.status_code, r.headers["Location"]), (302, here))
        where, cat_id = submit_through(cat, contest_id, problem_id, AB + "// p26-vp-cat\n")
        self.assertEqual(where, here + "/submissions?mine=1")
        self.assertEqual(db("select practice_contest_id, is_virtual, is_hidden from submissions where id = %d" % cat_id), [[str(contest_id), "1", "0"]])
        uoj.wait_submission(cat_id)

        # ---- while cat sits it: cat is told nothing of how ann did, nor bob of how cat does
        self.assertIn(KEPT, row_of(cat.get("/submissions?problem_id=%d" % problem_id).text, ann_id))
        self.assertNotIn("p26-vp-ann", cat.get("/submission/%d" % ann_id).text)
        self.assertIn("虚拟参赛结束后才能看这道题的统计", cat.get("/problem/%d/statistics" % problem_id).text)
        # everybody's, to cat, is what had been submitted by the same moment of the contest
        listed = everybody(cat, contest_id)
        self.assertIsNotNone(row_of(listed, cat_id))
        self.assertIsNone(row_of(listed, ann_id))
        row = row_of(everybody(bob, contest_id), cat_id)
        self.assertIsNotNone(row)
        self.assertRegex(row, r'<span class="uoj-unofficial-star"[^>]*>\*</span><span class="uoj-username"[^>]*>p26_vp_cat<')
        self.assertIn(KEPT, row)

        # ---- cat is through: everybody is told everything
        db("update contest_virtuals set start_time = '%s' where contest_id = %d and username = 'p26_vp_cat'" % (uoj.web_time(-7200), contest_id))
        self.assertNotIn(KEPT, row_of(bob.get("/submissions?problem_id=%d" % problem_id).text, cat_id))
        self.assertNotIn(KEPT, row_of(cat.get("/submissions?problem_id=%d" % problem_id).text, ann_id))
        self.assertIn("p26-vp-ann", cat.get("/submission/%d" % ann_id).text)
        self.assertNotIn("虚拟参赛结束后", cat.get("/problem/%d/statistics" % problem_id).text)


if __name__ == "__main__":
    unittest.main()
