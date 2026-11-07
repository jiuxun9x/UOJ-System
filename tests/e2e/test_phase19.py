"""Phase 19: the boards of a contest as QOJ, DOMjudge and XCPCIO have them."""

import io
import json
import re
import unittest
import zipfile

import uoj
from fixtures import AB, AB_COMPILE_ERROR, AB_WRONG, ab_problem_files
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
        # what the contest keeps of them, as MySQL reads it out of its settings (the client of
        # the command line writes a backslash of what it prints twice: \u91d1 is no 金 then)
        stored = lambda: json.loads(db_value("select ifnull(json_extract(extra_config, '$.medals'), 'null') from contests where id = %d" % contest_id))

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
        self.assertEqual(stored(), tiers)
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
        self.assertEqual(stored(), tiers)
        # a form without the medals keeps them
        self.assertEqual(admin.contest_settings(contest_id), "")
        self.assertEqual(stored(), tiers)

        # ---- no medals at all
        self.assertEqual(admin.contest_settings(contest_id, medals="[]"), "")
        told = state()
        self.assertEqual(([row["medal"] for row in told["rows"]], told["medals"]), ([0] * 5, []))
        # and the ones of a contest that says nothing, which is then what it says
        default = [{"name": "金牌", "color": "#f6c945", "amount": "1"}, {"name": "银牌", "color": "#cfd6de", "amount": "1"}, {"name": "铜牌", "color": "#e0a36a", "amount": "1"}]
        self.assertEqual(admin.contest_settings(contest_id, medals=json.dumps(default, ensure_ascii=False)), "")
        self.assertIsNone(stored())
        self.assertEqual([row["medal"] for row in state()["rows"]], [1, 2, 3, 0, 0])


class StatisticsTest(unittest.TestCase):
    """Under the outer board, as under the boards of XCPCIO: of every problem how many
    submissions there were, how many tried it and solved it, how much failed, and when it was
    first and last solved."""

    def test_the_problems_are_told_of_under_the_board(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p19 每题统计", minutes=300, rule="ICPC", problems="%d, %d" % (first, second))
        ann, bob, cat = account("p19_stat_ann"), account("p19_stat_bob"), account("p19_stat_cat")
        for client in (ann, bob, cat):
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -100 * 60, 300)
        # ann fails A and solves it at 0:15; bob solves it at 0:30; cat fails it; nobody tries B
        made = [(5, ann, AB_WRONG), (15, ann, AB), (30, bob, AB), (20, cat, AB_WRONG)]
        made = [(minutes, client.submit_in_contest(contest_id, first, code)) for minutes, client, code in made]
        uoj.wait_idle()
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)

        told = uoj.Client().get("/contest/%d/board?json=1" % contest_id).json()
        self.assertEqual([(problem["solved"], problem["tries"]) for problem in told["problems"]], [(2, 4), (0, 0)])
        self.assertEqual(told["stats"], [
            {"people": 3, "rejected": 2, "pending": 0, "first": 900, "last": 1800},
            {"people": 0, "rejected": 0, "pending": 0, "first": None, "last": None},
        ])  # fmt: skip
        self.assertIn("<tfoot></tfoot>", uoj.Client().get("/contest/%d/board" % contest_id).text)


class SubmissionsTabTest(unittest.TestCase):
    """The outer board has tabs, as XCPCIO has them: the board, what was submitted, and the
    statistics. What was submitted is told as the board counts it, and no more: nothing that did
    not compile, nothing after a problem was solved, and nothing of what was submitted since the
    board froze but that it was."""

    def test_what_was_submitted_is_told_as_the_board_counts_it(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p19 提交页签", minutes=300, rule="ICPC", freeze_minutes="60", problems="%d, %d" % (first, second))
        ann, bob = account("p19_runs_ann"), account("p19_runs_bob")
        for client in (ann, bob):
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -270 * 60, 300)
        made = [(10, ann, first, AB_WRONG), (12, ann, first, AB_COMPILE_ERROR), (20, ann, first, AB), (25, ann, first, AB),
                (30, bob, first, AB_WRONG), (250, bob, second, AB)]  # fmt: skip
        made = [(minutes, client.submit_in_contest(contest_id, problem, code)) for minutes, client, problem, code in made]
        uoj.wait_idle()
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        board = "/contest/%d/board" % contest_id

        # the board alone does not carry them: they are asked for when somebody looks at them
        self.assertNotIn("runs", uoj.Client().get(board + "?json=1").json())
        told = uoj.Client().get(board + "?json=1&runs=1").json()
        self.assertTrue(told["frozen"])
        # ann's attempt in vain, and what passed; not what did not compile, nor what she sent
        # after; bob's attempt, and what he sent after the board froze, which is not told
        self.assertEqual(told["runs"], [[600, "p19_runs_ann", 0, "no"], [1200, "p19_runs_ann", 0, "yes"], [1800, "p19_runs_bob", 0, "no"], [15000, "p19_runs_bob", 1, "wait"]])
        page = uoj.Client().get(board).text
        for part in ('id="outer-board-tabs"', 'id="table-outer-board-runs"', 'id="outer-board-chart-time"'):
            self.assertIn(part, page)

        # the results published, everything is told
        uoj.move_contest(contest_id, -400 * 60, 300)
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        told = uoj.Client().get(board + "?json=1&runs=1").json()
        self.assertEqual(told["runs"][-1], [15000, "p19_runs_bob", 1, "yes"])


def resolver_data(page):
    """what the page of the resolver holds"""
    found = re.search(r"window\.uojResolver = (\{.*?\});\n</script>", page, re.S)
    assert found, page[-2000:]
    return json.loads(found.group(1))


class ResolverTest(unittest.TestCase):
    """An ICPC contest that is over can be resolved in front of the room, by its staff: the board
    as it froze, and what became of everything it did not tell, revealed one by one."""

    def test_the_staff_resolve_the_board(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p19 滚榜", minutes=300, rule="ICPC", freeze_minutes="60", problems="%d, %d" % (first, second))
        ann, bob, cat = account("p19_rs_ann"), account("p19_rs_bob"), account("p19_rs_cat")
        for client in (ann, bob, cat):
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -270 * 60, 300)
        # ann solves A before the board froze; bob fails it before and solves it after; cat
        # fails B after
        made = [(20, ann, first, AB), (30, bob, first, AB_WRONG), (250, bob, first, AB), (260, cat, second, AB_WRONG)]
        made = [(minutes, client.submit_in_contest(contest_id, problem, code)) for minutes, client, problem, code in made]
        uoj.wait_idle()
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        resolver = "/contest/%d/resolver" % contest_id

        # ---- not while it runs, and never for who is not of its staff
        self.assertIn("比赛结束之后才能滚榜", admin.get(resolver).text)
        self.assertEqual(uoj.Client().get(resolver).status_code, 302)
        self.assertEqual(ann.get(resolver).status_code, 404)

        # ---- over: the board as it froze, and what really became of it
        uoj.move_contest(contest_id, -400 * 60, 300)
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        page = admin.get(resolver)
        self.assertEqual(page.status_code, 200)
        data = resolver_data(page.text)
        self.assertEqual([problem["letter"] for problem in data["problems"]], ["A", "B"])
        self.assertEqual(data["freeze"], 240 * 60)
        cells = {person["username"]: person["cells"] for person in data["people"]}
        self.assertEqual(sorted(cells), ["p19_rs_ann", "p19_rs_bob", "p19_rs_cat"])
        # ann's A was solved before it froze, at 0:20: as it was, and as it is
        self.assertEqual(cells["p19_rs_ann"][0], [["first", "+", "0:20", 1200], ["first", "+", "0:20", 1200]])
        # bob's second attempt is not told on the frozen board; it passed, at 4:10 and a failed attempt
        self.assertEqual(cells["p19_rs_bob"][0], [["pending", "?", "1 + 1", None], ["solved", "+1", "4:10", 250 * 60 + 1200]])
        self.assertEqual(cells["p19_rs_cat"][1][0][0], "pending")
        self.assertEqual(cells["p19_rs_cat"][1][1][:2], ["failed", "-1"])
        # in the end ann is first and bob second: a gold and a silver
        self.assertEqual(data["medal"], {"p19_rs_ann": 1, "p19_rs_bob": 2, "p19_rs_cat": 0})
        self.assertIn('id="table-resolver"', page.text)
        self.assertIn("/js/uoj-resolver.js", page.text)
        # the staff find it among the tabs of the contest; the contestants do not
        self.assertIn('href="/contest/%d/resolver"' % contest_id, admin.get("/contest/%d" % contest_id).text)
        self.assertNotIn("/resolver", ann.get("/contest/%d" % contest_id).text)
        # published, it is resolved the same: from what was submitted
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(resolver_data(admin.get(resolver).text)["people"], data["people"])

        # ---- a contest of another rule is not resolved
        oi = admin.new_contest("p19 OI 不滚榜", minutes=60, problems=str(first))
        uoj.move_contest(oi, -120 * 60, 60)
        self.assertIn("只有 ICPC 赛制的比赛可以滚榜", admin.get("/contest/%d/resolver" % oi).text)


class ExportTest(unittest.TestCase):
    """The board can be resolved with the tools of others as well: the staff download the contest
    as the event feed of the CCS specifications, which the resolver of the ICPC Tools reads, or
    as the files of XCPCIO."""

    def test_the_contest_is_written_for_other_resolvers(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p19 导出", minutes=300, rule="ICPC", freeze_minutes="60", problems="%d, %d" % (first, second))
        ann, bob = account("p19_ex_ann"), account("p19_ex_bob")
        for client in (ann, bob):
            client.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -270 * 60, 300)
        made = [(20, ann, first, AB), (30, bob, first, AB_WRONG), (250, bob, first, AB), (255, bob, second, AB_COMPILE_ERROR)]
        made = [(minutes, client.submit_in_contest(contest_id, problem, code)) for minutes, client, problem, code in made]
        uoj.wait_idle()
        uoj.move_contest(contest_id, -400 * 60, 300)
        for minutes, submission_id in made:
            at(contest_id, minutes, submission_id)
        resolver = "/contest/%d/resolver" % contest_id
        self.assertIn('id="resolver-export-clics"', admin.get(resolver).text)
        # nobody else gets them: they hold what was submitted since the board froze
        self.assertEqual(ann.get(resolver + "?export=clics").status_code, 404)
        self.assertEqual(uoj.Client().get(resolver + "?export=xcpcio").status_code, 302)

        # ---- the event feed
        r = admin.get(resolver + "?export=clics")
        self.assertEqual(r.status_code, 200)
        self.assertTrue(r.headers["Content-Type"].startswith("application/x-ndjson"), r.headers["Content-Type"])
        self.assertIn("attachment", r.headers["Content-Disposition"])
        events = [json.loads(line) for line in r.text.splitlines()]
        of = lambda kind: [event["data"] for event in events if event["type"] == kind]
        contest = of("contest")[0]
        self.assertEqual((contest["id"], contest["duration"], contest["scoreboard_freeze_duration"], contest["penalty_time"]), ("uoj-%d" % contest_id, "5:00:00.000", "1:00:00.000", 20))
        self.assertEqual([problem["label"] for problem in of("problems")], ["A", "B"])
        self.assertEqual(sorted(team["id"] for team in of("teams")), ["p19_ex_ann", "p19_ex_bob"])
        self.assertEqual([(s["team_id"], s["problem_id"], s["contest_time"]) for s in of("submissions")],
                         [("p19_ex_ann", "A", "0:20:00.000"), ("p19_ex_bob", "A", "0:30:00.000"), ("p19_ex_bob", "A", "4:10:00.000"), ("p19_ex_bob", "B", "4:15:00.000")])  # fmt: skip
        self.assertEqual([judgement["judgement_type_id"] for judgement in of("judgements")], ["AC", "WA", "AC", "CE"])
        awards = {award["id"]: award["team_ids"] for award in of("awards")}
        self.assertEqual((awards["gold-medal"], awards["silver-medal"], awards["winner"], awards["first-to-solve-A"]), (["p19_ex_ann"], ["p19_ex_bob"], ["p19_ex_ann"], ["p19_ex_ann"]))
        state = events[-1]
        self.assertEqual(state["type"], "state")
        self.assertTrue(state["data"]["frozen"] and state["data"]["ended"] and state["data"]["finalized"], state)

        # ---- the files of XCPCIO
        r = admin.get(resolver + "?export=xcpcio")
        self.assertEqual(r.headers["Content-Type"], "application/zip")
        files = zipfile.ZipFile(io.BytesIO(r.content))
        self.assertEqual(sorted(files.namelist()), ["config.json", "run.json", "team.json"])
        config = json.loads(files.read("config.json"))
        self.assertEqual((config["frozen_time"], config["penalty"], config["problem_id"], config["medal"]), (3600, 1200, ["A", "B"], {"official": {"gold": 1, "silver": 1, "bronze": 1}}))
        self.assertEqual(sorted(json.loads(files.read("team.json"))), ["p19_ex_ann", "p19_ex_bob"])
        self.assertEqual([(run["team_id"], run["problem_id"], run["timestamp"], run["status"]) for run in json.loads(files.read("run.json"))],
                         [("p19_ex_ann", 0, 1200, "ACCEPTED"), ("p19_ex_bob", 0, 1800, "WRONG_ANSWER"), ("p19_ex_bob", 0, 15000, "ACCEPTED"), ("p19_ex_bob", 1, 15300, "COMPILATION_ERROR")])  # fmt: skip


if __name__ == "__main__":
    unittest.main()
