"""Phase 17: the outer board for everybody, with who sat the contest virtually on it under a star,
and the board as one of them sees it, the way Codeforces shows the standings of a participant."""

import json
import unittest

import uoj
from fixtures import AB, AB_WRONG, ab_problem_files
from test_phase3 import account
from uoj import db, db_value


def at(contest_id, minutes, submission_id):
    """move a submission to so many minutes into its contest"""
    db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute)"
       " where id = %d" % (contest_id, minutes, submission_id))  # fmt: skip
    return submission_id


class PublicBoardTest(unittest.TestCase):
    """The outer board of a contest with a password, or for the people on a list, was for the
    people who took part in it alone: who was not logged in was sent to log in. It is for whoever
    watches now; a contest of a domain keeps it with the people of the domain, unless they make it
    public."""

    def test_the_outer_board_is_for_everybody(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        stranger = account("p17_pub_stranger")
        for mode, extra in (("password", {"join_password": "p17 sesame"}), ("list", {})):
            contest_id = admin.new_contest("p17 公开外榜 " + mode, rule="ICPC", problems=str(problem_id), join_mode=mode, **extra)
            pupil = account("p17_pub_" + mode)
            db("insert into contests_registrants (username, user_rating, contest_id, has_participated, `rank`) values ('%s', 1500, %d, 1, 0)" % (pupil.username, contest_id))
            uoj.move_contest(contest_id, -600, 60)
            board = "/contest/%d/board" % contest_id
            for client in (uoj.Client(), stranger):
                r = client.get(board)
                self.assertEqual(r.status_code, 200, mode)
                self.assertIn('id="outer-board"', r.text)
                self.assertEqual([row["username"] for row in client.get(board + "?json=1").json()["rows"]], [pupil.username], mode)
                # the contest itself is still theirs alone: its problems, what was submitted
                self.assertNotEqual(client.get("/contest/%d/problem/1" % contest_id).status_code, 200, mode)

        # ---- a contest of a domain keeps it with the people of the domain
        teacher, member = account("p17_pub_teacher"), account("p17_pub_member")
        self.assertEqual(admin.change_user("p17_pub_teacher", "grant:teacher"), "")
        teacher.new_domain("p17-board")
        self.assertEqual(teacher.form("/d/p17-board/members", "add", username="p17_pub_member", role="member"), "")
        contest_id = teacher.new_contest("p17 域内外榜", domain="p17-board", rule="ICPC")
        board = "/contest/%d/board" % contest_id
        self.assertEqual(uoj.Client().get(board).status_code, 302)
        self.assertEqual(stranger.get(board).status_code, 404)
        self.assertEqual(member.get(board).status_code, 200)
        # the box that makes it public is on the settings of a contest of a domain, and only there
        manage = "/contest/%d/manage" % contest_id
        self.assertIn('id="input-contest-public_board"', teacher.get(manage).text)
        site_contest = admin.new_contest("p17 站内比赛")
        self.assertNotIn('id="input-contest-public_board"', admin.get("/contest/%d/manage" % site_contest).text)
        # made public, it is for everybody
        self.assertEqual(teacher.contest_settings(contest_id, public_board="on"), "")
        self.assertEqual(json.loads(db_value("select extra_config from contests where id = %d" % contest_id))["public_board"], True)
        self.assertIn('id="input-contest-public_board" name="public_board" checked="checked"', teacher.get(manage).text)
        for client in (uoj.Client(), stranger, member):
            r = client.get(board)
            self.assertEqual(r.status_code, 200)
            self.assertIn('id="outer-board"', r.text)
        # its page is not: it is the domain's
        self.assertEqual(stranger.get("/contest/%d" % contest_id).status_code, 404)
        # and private again
        self.assertEqual(teacher.contest_settings(contest_id), "")
        self.assertNotIn("public_board", json.loads(db_value("select extra_config from contests where id = %d" % contest_id)))
        self.assertEqual(uoj.Client().get(board).status_code, 302)


class VirtualOnTheBoardTest(unittest.TestCase):
    """Who sat a contest virtually was on the board of the contest for whoever asked, and never on
    its outer board. They are there now when it is over, under a star, ranked among the contestants
    and taking none of their medals. And anybody may see the board as one of them sees it: the
    contest at the moment of their participation they are at, the clock going on while it runs."""

    def test_who_sat_it_virtually_is_on_the_board_and_is_seen_as(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p17 VP 外榜", minutes=300, rule="ICPC", problems="%d, %d" % (first, second))
        ann, bob = account("p17_vp_ann"), account("p17_vp_bob")
        for client in (ann, bob):
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -100 * 60, 300)
        made = [(20, ann.submit_in_contest(contest_id, first, AB)), (60, bob.submit_in_contest(contest_id, first, AB)), (70, bob.submit_in_contest(contest_id, second, AB_WRONG))]
        uoj.wait_idle()
        uoj.move_contest(contest_id, -400 * 60, 300)
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")

        # one sits it now, thirty minutes in, and solved A ten minutes into it; one sat it to its end
        sitter, done = account("p17_vp_sitter"), account("p17_vp_done")
        for client in (sitter, done):
            self.assertEqual(client.form("/contest/%d/virtual" % contest_id, "start"), "")
        solved = uoj.wait_submission(sitter.submit(first, AB, path="/contest/%d/problem/%d" % (contest_id, first)))
        self.assertEqual(solved.score, 100)
        sitter_submission = int(db_value("select max(id) from submissions where submitter = 'p17_vp_sitter'"))
        db("update contest_virtuals set start_time = '%s' where contest_id = %d and username = 'p17_vp_sitter'" % (uoj.web_time(-30 * 60), contest_id))
        db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-20 * 60), sitter_submission))
        db("update contest_virtuals set start_time = '%s' where contest_id = %d and username = 'p17_vp_done'" % (uoj.web_time(-6 * 3600), contest_id))

        board = "/contest/%d/board" % contest_id
        state = lambda client, query="": client.get(board + "?json=1" + query).json()
        rows = lambda told: [(row["username"], row["rank"], row["solved"], row["virtual"]) for row in told["rows"]]

        # ---- the board of the contest, to anybody: who sat it virtually is on it, ranked among
        # the contestants. They take no medal, and were not there to be first.
        final = state(uoj.Client())
        self.assertEqual((final["phase"], final["virtual"], final["viewing"]), ("final", True, None))
        self.assertEqual(rows(final), [("p17_vp_sitter", 1, 1, True), ("p17_vp_ann", 2, 1, False), ("p17_vp_bob", 3, 1, False), ("p17_vp_done", 4, 0, True)])
        self.assertEqual([row["medal"] for row in final["rows"]], [0, 1, 2, 0])
        self.assertEqual([row["cells"][0][0] for row in final["rows"][:3]], ["solved", "first", "solved"])
        # whose view may be seen: who sits it now first
        self.assertEqual([(person["username"], person["running"]) for person in final["virtuals"]], [("p17_vp_sitter", True), ("p17_vp_done", False)])
        # and the board without them
        self.assertEqual(rows(state(uoj.Client(), "&virtual=0")), [("p17_vp_ann", 1, 1, False), ("p17_vp_bob", 2, 1, False)])

        # ---- the board as the sitter sees it, to anybody: the contest thirty minutes in, with
        # who sat it virtually at the same moment of theirs; the clock goes on
        view = state(uoj.Client(), "&vp=p17_vp_sitter")
        self.assertEqual((view["phase"], view["ticking"], view["frozen"]), ("replay", True, False))
        self.assertTrue(1795 <= view["elapsed"] <= 2100, view["elapsed"])
        viewing = view["viewing"]
        self.assertEqual((viewing["key"], viewing["username"], viewing["running"], viewing["mine"]), ("*p17_vp_sitter", "p17_vp_sitter", True, False))
        # thirty minutes in, ann has solved A and bob has not; who sat it to its end had nothing yet
        self.assertEqual(rows(view), [("p17_vp_sitter", 1, 1, True), ("p17_vp_ann", 2, 1, False), ("p17_vp_done", 3, 0, True), ("p17_vp_bob", 3, 0, False)])
        # it is the sitter's own board as well, which they can not leave while they sit it
        own = state(sitter)
        self.assertEqual((own["phase"], own["viewing"]["mine"], rows(own), own["virtuals"]), ("replay", True, rows(view), []))
        self.assertEqual(state(sitter, "&vp=p17_vp_done")["viewing"]["username"], "p17_vp_sitter")
        self.assertEqual(state(sitter, "&virtual=0")["viewing"]["username"], "p17_vp_sitter")
        # the view of who sat it to its end: the board at its end, not frozen, and no clock
        ended = state(uoj.Client(), "&vp=p17_vp_done")
        self.assertEqual((ended["phase"], ended["ticking"], ended["elapsed"], ended["viewing"]["running"]), ("replay", False, 18000, False))
        self.assertEqual(rows(ended), rows(final))
        # nobody's view who did not sit it: the board of the contest
        for name in ("p17_vp_ann", "nobody_at_all"):
            self.assertEqual(state(uoj.Client(), "&vp=" + name)["viewing"], None, name)
        # the page asks again for what it was asked for
        page = uoj.Client().get(board + "?vp=p17_vp_sitter").text
        self.assertIn('data-url="%s?json=1&amp;vp=p17_vp_sitter"' % board, page)
        self.assertIn('id="outer-board-vp"', page)


if __name__ == "__main__":
    unittest.main()
