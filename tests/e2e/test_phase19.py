"""Phase 19: the boards of a contest as QOJ, DOMjudge and XCPCIO have them."""

import json
import re
import unittest

import uoj
from fixtures import AB, AB_WRONG, ab_problem_files
from test_phase3 import account
from test_phase17 import at
from uoj import db_value


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


class MedalsTest(unittest.TestCase):
    """The outer board gave a medal to the best three of the contestants, a gold, a silver and a
    bronze. A contest has medals of its own kinds now, as many kinds as it likes, each for so
    many people or for so many percent of the contestants, or of those who solved something,
    rounded up; one after the other down the ranks, as DOMjudge gives its medals."""

    def test_the_medals_are_the_contests_own(self):
        admin = uoj.admin()
        problem = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p19 奖牌", minutes=300, rule="ICPC", problems=str(problem))
        people = [account("p19_medal_%d" % index) for index in range(5)]
        for client in people:
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -100 * 60, 300)
        # four solve A, ten minutes after each other; the fifth does not
        made = [(10 * (index + 1), client.submit_in_contest(contest_id, problem, AB if index < 4 else AB_WRONG)) for index, client in enumerate(people)]
        uoj.wait_idle()
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        board = "/contest/%d/board" % contest_id
        state = lambda: uoj.Client().get(board + "?json=1").json()
        medals = lambda told: [(row["username"], row["medal"]) for row in told["rows"]]

        # ---- what a contest that says nothing gives: a gold, a silver and a bronze
        told = state()
        self.assertEqual(medals(told), [("p19_medal_0", 1), ("p19_medal_1", 2), ("p19_medal_2", 3), ("p19_medal_3", 0), ("p19_medal_4", 0)])
        self.assertEqual([(kind["name"], kind["count"], kind["given"]) for kind in told["medals"]], [("金牌", 1, 1), ("银牌", 1, 1), ("铜牌", 1, 1)])
        manage = admin.get("/contest/%d/manage" % contest_id).text
        self.assertIn('id="table-contest-medals"', manage)
        self.assertIn('name="medals" id="input-contest-medals" value="[{&quot;name&quot;:&quot;金牌&quot;', manage)

        # ---- kinds of its own: a gold for 25% of all five (1.25, so two), a silver for one, a
        # bronze for 50% of the four who solved something (two): the fifth solved nothing and
        # gets nothing all the same
        tiers = [
            {"name": "金奖", "color": "#ffd700", "amount": "25%", "of": "all"},
            {"name": "银奖", "color": "#c0c0c0", "amount": "1"},
            {"name": "铜奖", "color": "#cd7f32", "amount": "50%", "of": "effective"},
        ]
        self.assertEqual(admin.contest_settings(contest_id, medals=json.dumps(tiers)), "")
        self.assertEqual(json.loads(db_value("select extra_config from contests where id = %d" % contest_id))["medals"], tiers)
        told = state()
        self.assertEqual(medals(told), [("p19_medal_0", 1), ("p19_medal_1", 1), ("p19_medal_2", 2), ("p19_medal_3", 3), ("p19_medal_4", 0)])
        self.assertEqual([(kind["name"], kind["color"], kind["of"], kind["count"], kind["given"]) for kind in told["medals"]],
                         [("金奖", "#ffd700", "all", 2, 2), ("银奖", "#c0c0c0", None, 1, 1), ("铜奖", "#cd7f32", "effective", 2, 1)])  # fmt: skip
        self.assertEqual(told["counted"], {"all": 5, "effective": 4})
        self.assertIn('id="outer-board-medals"', uoj.Client().get(board).text)

        # ---- what is no medal is refused, and nothing changes
        err = admin.contest_settings(contest_id, medals=json.dumps([{"name": "金奖", "color": "gold", "amount": "1"}]))
        self.assertIn("第 1 档奖牌的颜色应写成 #rrggbb", err)
        err = admin.contest_settings(contest_id, medals=json.dumps([{"name": "金奖", "color": "#ffd700", "amount": "120%", "of": "all"}]))
        self.assertIn("第 1 档奖牌的数量应为人数（如 3）或比例（如 10%），比例不超过 100%", err)
        self.assertEqual(json.loads(db_value("select extra_config from contests where id = %d" % contest_id))["medals"], tiers)
        # a form without the medals keeps them
        self.assertEqual(admin.contest_settings(contest_id), "")
        self.assertEqual(json.loads(db_value("select extra_config from contests where id = %d" % contest_id))["medals"], tiers)

        # ---- no medals at all
        self.assertEqual(admin.contest_settings(contest_id, medals="[]"), "")
        told = state()
        self.assertEqual(([row["medal"] for row in told["rows"]], told["medals"]), ([0] * 5, []))
        # and the ones of a contest that says nothing, which is then what it says
        default = [{"name": "金牌", "color": "#f6c945", "amount": "1"}, {"name": "银牌", "color": "#cfd6de", "amount": "1"}, {"name": "铜牌", "color": "#e0a36a", "amount": "1"}]
        self.assertEqual(admin.contest_settings(contest_id, medals=json.dumps(default, ensure_ascii=False)), "")
        self.assertNotIn("medals", json.loads(db_value("select extra_config from contests where id = %d" % contest_id)))
        self.assertEqual([row["medal"] for row in state()["rows"]], [1, 2, 3, 0, 0])


if __name__ == "__main__":
    unittest.main()
