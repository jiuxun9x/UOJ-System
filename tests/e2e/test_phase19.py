"""Phase 19: the boards of a contest as QOJ, DOMjudge and XCPCIO have them."""

import re
import unittest

import uoj
from fixtures import AB, AB_WRONG, ab_problem_files
from test_phase3 import account


class InnerBoardLikeQOJTest(unittest.TestCase):
    """The board of an ICPC contest had colours of its own: a green and a red that were not QOJ's,
    the figures of the font made to line up, what stands under a cell small and grey, and the row
    of whoever looks at it in a blue frame. It is in the fonts and colours of QOJ now: how many
    somebody solved is coloured by how much of the contest that is, a problem nobody solved is
    headed in red, and one's own row is yellow."""

    def test_the_board_is_in_the_colours_of_qoj(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p19 QOJ 内榜", minutes=300, rule="ICPC", problems="%d, %d" % (first, second))
        ann, bob = account("p19_qoj_ann"), account("p19_qoj_bob")
        for client in (ann, bob):
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -100 * 60, 300)
        # ann solves A; bob tries B and fails, and nobody solves it
        ann.submit_in_contest(contest_id, first, AB)
        bob.submit_in_contest(contest_id, second, AB_WRONG)
        uoj.wait_idle()

        page = ann.get("/contest/%d/standings" % contest_id).text
        board = page[page.index('id="table-icpc-standings"'):]
        # the widths of QOJ
        self.assertIn('<th style="width:3em">#</th>', board)
        self.assertIn('<th style="width:4em">通过<div class="uoj-icpc-under">罚时</div></th>', board)
        # a problem somebody solved is headed in green, one nobody did in red
        self.assertEqual(re.findall(r'<th style="width:5em[^"]*" class="([\w-]+)"', board), ["uoj-icpc-solved-by", "uoj-icpc-solved-by-none"])
        # half the contest is yellow, none of it red
        totals = dict(re.findall(r'(?s)data-username="(p19_qoj_\w+)".*?<span style="color:(rgb\(\d+,\d+,\d+\))" class="uoj-icpc-total', board))
        self.assertEqual(totals, {"p19_qoj_ann": "rgb(230,230,0)", "p19_qoj_bob": "rgb(204,0,0)"})
        # the cells say what they said
        self.assertRegex(board, r'(?s)data-username="p19_qoj_ann".*?<td class="uoj-icpc-first" data-problem="A"><a href="/submission/\d+">\+</a>')
        self.assertRegex(board, r'(?s)data-username="p19_qoj_bob".*?<td class="uoj-icpc-failed" data-problem="B"><a href="/submission/\d+">-1</a></td>')
        # ann's own row, to ann alone
        self.assertIn('<tr class="uoj-scoreboard-me" data-username="p19_qoj_ann"', board)
        self.assertNotIn('class="uoj-scoreboard-me" data-username="p19_qoj_bob"', board)

        # and the colours are QOJ's: the green and red of a cell, the yellow of one's own row
        style = uoj.Client().get("/css/uoj-domain.css").text
        for colour in ("rgb(0, 204, 0)", "rgb(204, 0, 0)", "#ffeeba", "#f5c6cb"):
            self.assertIn(colour, style)
        self.assertNotIn("inset 0 2px 0 #007bff", style)


if __name__ == "__main__":
    unittest.main()
