"""Phase 17: the outer board for everybody."""

import json
import unittest

import uoj
from fixtures import ab_problem_files
from test_phase3 import account
from uoj import db, db_value


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


if __name__ == "__main__":
    unittest.main()
