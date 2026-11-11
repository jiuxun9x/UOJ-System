"""Phase 28: the outer board and the resolver in the font of DOMjudge."""

import re
import unittest

import uoj
from fixtures import ab_problem_files


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
            self.assertIn('href="/fonts/roboto/roboto.css', client.get(page).text, page)
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


if __name__ == "__main__":
    unittest.main()
