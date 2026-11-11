"""Phase 28: the outer board and the resolver in the font of DOMjudge, and the time bar of the
outer board."""

import re
import unittest

import uoj
from fixtures import AB, AB_WRONG, ab_problem_files
from test_phase3 import account
from test_phase17 import at


class BoardFontTest(unittest.TestCase):
    """The outer board was set in whatever font the browser had, and the resolver in the font of
    a terminal, which Chinese did not go with. Both are set in Roboto, as the scoreboard of
    DOMjudge is, and the site comes with it: a computer without it, or without the internet,
    shows it all the same."""

    def test_the_boards_are_set_in_roboto_which_comes_with_the_site(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p28 字体", rule="ICPC", problems=str(problem_id))
        uoj.move_contest(contest_id, -120 * 60, 60)
        for page, client in (("/contest/%d/board" % contest_id, uoj.Client()), ("/contest/%d/resolver" % contest_id, admin)):
            self.assertRegex(client.get(page).text, r'href="[^"]*/fonts/roboto/roboto\.css', page)
        anybody = uoj.Client()
        css = anybody.get("/fonts/roboto/roboto.css")
        self.assertEqual(css.status_code, 200)
        self.assertIn('font-family: "Roboto"', css.text)
        font = anybody.get("/fonts/roboto/" + re.search(r'url\("([^"]+\.woff2)"\)', css.text).group(1))
        self.assertEqual((font.status_code, font.content[:4]), (200, b"wOF2"))
        self.assertIn("woff2", font.headers.get("Content-Type", ""))
        self.assertIn("SIL Open Font License", anybody.get("/fonts/roboto/LICENSE").text)
        for sheet in ("/css/uoj-board.css", "/css/uoj-resolver.css"):
            self.assertIn("font-family: Roboto, ", anybody.get(sheet).text, sheet)
        self.assertNotIn("monospace", anybody.get("/css/uoj-resolver.css").text)


class TimeBarTest(unittest.TestCase):
    """The outer board told the contest as it is, and nothing of how it came to be. Its time bar
    takes it to a moment that passed: the board as it stood then for whoever looked - frozen from
    the freeze on while the results are not published, as it was - and once they are, as it
    turned out. No moment beyond the one the board is at is told."""

    def test_the_board_of_a_moment_that_passed(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p28 时间条", minutes=300, rule="ICPC", freeze_minutes="60", problems=str(problem_id))
        ann, bob = account("p28_tb_ann"), account("p28_tb_bob")
        for client in (ann, bob):
            client.register_for_contest(contest_id)
        # 270 minutes into it, frozen since 240: ann solved A at 20; bob failed it at 30 and
        # solved it at 250, which the board does not tell
        uoj.move_contest(contest_id, -270 * 60, 300)
        made = [(20, ann.submit_in_contest(contest_id, problem_id, AB)), (30, bob.submit_in_contest(contest_id, problem_id, AB_WRONG)),
                (250, bob.submit_in_contest(contest_id, problem_id, AB))]  # fmt: skip
        uoj.wait_idle()
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        board = "/contest/%d/board" % contest_id
        state = lambda query="": uoj.Client().get(board + "?json=1" + query).json()
        cells = lambda told: {row["username"]: row["cells"][0][0] for row in told["rows"]}

        now = state()
        self.assertEqual((now["phase"], now["frozen"], now["moment"], now["latest_ticking"]), ("running", True, None, True))
        self.assertEqual(cells(now), {ann.username: "first", bob.username: "pending"})
        # ---- 25 minutes in: ann has solved A, bob has not tried it; the clock stands still
        then = state("&at=1500")
        self.assertEqual((then["moment"], then["elapsed"], then["ticking"], then["frozen"]), (1500, 1500, False, False))
        self.assertTrue(270 * 60 - 5 <= then["latest"] <= 270 * 60 + 900, then["latest"])
        self.assertEqual(cells(then), {ann.username: "first", bob.username: "none"})
        self.assertEqual([(run[1], run[3]) for run in state("&at=1500&runs=1")["runs"]], [(ann.username, "yes")])
        # ---- 35 minutes in, bob has failed it
        self.assertEqual(cells(state("&at=2100"))[bob.username], "failed")
        # ---- 255 minutes in, the board was frozen: what bob did then is not told
        frozen = state("&at=15300")
        self.assertEqual((frozen["moment"], frozen["frozen"]), (15300, True))
        self.assertEqual(cells(frozen)[bob.username], "pending")
        self.assertEqual([run[3] for run in state("&at=15300&runs=1")["runs"] if run[1] == bob.username], ["no", "wait"])
        # ---- a moment to come is not told: it is the board as it is
        for query in ("&at=%d" % (290 * 60), "&at=x", "&at=-1"):
            self.assertEqual(state(query)["moment"], None, query)
        # the page has the bar, and asks again for the board as it is
        page = uoj.Client().get(board + "?at=1500").text
        self.assertIn('id="outer-board-timebar"', page)
        self.assertIn('data-url="%s?json=1"' % board, page)

        # ---- once the results are published, a moment is as it turned out
        uoj.move_contest(contest_id, -400 * 60, 300)
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        published = state("&at=15300")
        self.assertEqual((published["phase"], published["moment"], published["frozen"], published["latest"]), ("final", 15300, False, 300 * 60))
        self.assertEqual(cells(published), {ann.username: "first", bob.username: "solved"})
        self.assertEqual(cells(state("&at=2100")), {ann.username: "first", bob.username: "failed"})


if __name__ == "__main__":
    unittest.main()
