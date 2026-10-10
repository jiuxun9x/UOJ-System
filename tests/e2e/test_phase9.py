"""End-to-end tests of phase 9: what a contest needs while it runs and after it (registration,
the frozen board, virtual participation, balloons, deletion), and how problems get in (several
copies at once, templates and packages).

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import test_phase3 as p3
import test_phase6 as p6
import uoj
from fixtures import *
from uoj import db, db_value


def setUpModule():
    uoj.admin()


def data_flash(client, problem_id):
    """what the page of the data of a problem says about what was done last: (kind, words)"""
    page = client.get("/problem/%d/manage/data" % problem_id).text
    found = re.search(r'(?s)class="alert alert-(\w+) text-left" role="alert" id="data-flash">(.*?)</div>', page)
    return found.groups() if found else (None, "")


class InputValidationTest(unittest.TestCase):
    """the inputs of a problem are run past a validator only where its settings say so, and
    what the settings say is seen in them"""

    REJECT_EVERYTHING = "int main() { return 1; }\n"

    def settings(self, admin, problem_id, **changes):
        fields = dict(form="judge_settings", type="multi_pass", passes="2", time_limit="1", memory_limit="256", checker="wcmp", scoring="per_test")
        fields.update(changes)
        r = admin.post("/problem/%d/manage/data" % problem_id, fields)
        self.assertEqual(r.status_code, 302, r.text[-300:])

    def test_validation_that_came_with_a_problem_conf_is_seen_and_turned_off(self):
        admin = uoj.admin()
        problem_id = admin.new_problem(title="p9 通信题")
        manage = "/problem/%d/manage/data" % problem_id
        # ---- a problem.conf as it came from somewhere, with the line in it and no validator
        self.assertIn("上传成功", admin.upload_data(problem_id, multi_pass_problem_files(validate_input_before_test="on")).text)
        # The data is not published, and it is said why: it used to be published, and every
        # submission then failed at a validator that was not there.
        self.assertIn("validate_input_before_test is on, but the problem has no validator", admin.sync(problem_id))
        self.assertEqual(db_value("select count(*) from problem_data_versions where problem_id = %d and status = 'ready'" % problem_id), "0")
        # the settings show what the problem.conf says, and the check of the files says what is missing
        page = admin.get(manage).text
        self.assertRegex(page, r'id="input-problem-validate_input" name="validate_input" checked="checked"')
        self.assertIn("缺少文件", uoj.text_of(page))

        # ---- the settings are saved without the box: the line is gone, the data is published,
        # and a program is judged
        self.settings(admin, problem_id)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertNotIn("validate_input_before_test", published_conf(problem_id))
        self.assertRegex(admin.get(manage).text, r'id="input-problem-validate_input" name="validate_input" />')
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, messages_solution())).score, 100)

        # ---- with the box and no validator nothing new is published, and it is said why
        versions = db_value("select count(*) from problem_data_versions where problem_id = %d and status = 'ready'" % problem_id)
        self.settings(admin, problem_id, validate_input="on")
        kind, said = data_flash(admin, problem_id)
        self.assertIn("还没有数据校验器", said)
        self.assertEqual(db_value("select count(*) from problem_data_versions where problem_id = %d and status = 'ready'" % problem_id), versions)

        # ---- with the box and a validator that was chosen, the inputs are validated
        self.assertEqual(p6.upload_files(admin, problem_id, {"accepts.cpp": ACCEPT_ANYTHING, "rejects.cpp": self.REJECT_EVERYTHING}).status_code, 302)
        self.assertRegex(admin.get(manage).text, r'(?s)<select[^>]*name="val_file".*?<option value="accepts.cpp">')
        self.settings(admin, problem_id, validate_input="on", val_file="accepts.cpp")
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["validate_input_before_test"], conf["val_source"]), ("on", "accepts.cpp"))
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, messages_solution())).score, 100)
        self.settings(admin, problem_id, validate_input="on", val_file="rejects.cpp")
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        refused = uoj.wait_submission(admin.submit(problem_id, messages_solution()))
        self.assertEqual((refused.score, set(refused.infos)), (0, {"Invalid Input"}))

    def test_problem_of_the_usual_kind_validates_nothing_unless_told(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        self.assertNotIn("validate_input_before_test", published_conf(problem_id))
        page = admin.get("/problem/%d/manage/data" % problem_id).text
        self.assertRegex(page, r'id="input-problem-validate_input" name="validate_input" />')
        # told to, with the validator that is called what validators are called
        self.settings(admin, problem_id, type="traditional", checker="ncmp", validate_input="on")
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["validate_input_before_test"], conf["val_source"]), ("on", "val.cpp"))
        solver = p3.account("p9_val_solver")
        self.assertEqual(uoj.wait_submission(solver.submit(problem_id, AB)).score, 100)


def icpc_board(client, contest_id, query=""):
    """the board of an ICPC contest as somebody sees it: (username => (rank, solved, what each
    cell says), the row above the board that is the viewer's own or None, the page)"""
    page = client.get("/contest/%d/standings%s" % (contest_id, query))
    assert page.status_code == 200, page.status_code

    def cells_of(row):
        said = {}
        for kind, letter, inside in re.findall(r'(?s)<td class="uoj-icpc-(\w+)" data-problem="(\w)">(.*?)</td>', row):
            words = [w for w in re.sub(r"<[^>]+>", " ", inside).split() if w]
            said[letter] = (kind, " ".join(words))
        return said

    rows = {}
    for name, rank, solved, cells in re.findall(
        r'(?s)<tr(?: class="uoj-scoreboard-me")? data-username="([^"]+)" data-rank="(\d+)" data-solved="(\d+)" data-penalty="\d+">(.*?)</tr>', page.text
    ):
        rows[name] = (int(rank), int(solved), cells_of(cells))
    mine = re.search(r'(?s)<div class="table-responsive" id="standings-mine">.*?<tbody>\s*<tr class="uoj-scoreboard-me" data-username="([^"]+)" data-solved="(\d+)"[^>]*>\s*<td[^>]*>([^<]*)</td>(.*?)</tr>', page.text)
    return rows, (mine.group(1), int(mine.group(2)), mine.group(3), cells_of(mine.group(4))) if mine else None, page.text


class FrozenBoardTest(unittest.TestCase):
    """the frozen board of an ICPC contest counts the way DOMjudge counts"""

    def test_board_freezes_the_way_domjudge_freezes_it(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p9 封榜", minutes=300, rule="ICPC", freeze_minutes="60", problems="%d, %d" % (first, second))
        dan, eve, fay = (p3.account("p9_frz_" + name) for name in ("dan", "eve", "fay"))
        for client in (dan, eve, fay):
            client.register_for_contest(contest_id)
        # two hundred and fifty minutes into its three hundred: the last sixty are frozen
        uoj.move_contest(contest_id, -250 * 60, 300)

        def at(minutes, submission_id):
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute)"
               " where id = %d" % (contest_id, minutes, submission_id))  # fmt: skip
            return submission_id

        # dan solved A before the board froze, after one attempt in vain, and goes on submitting
        # to it afterwards: a wrong program, and a right one
        at(10, dan.submit_in_contest(contest_id, first, AB_WRONG))
        at(20, dan.submit_in_contest(contest_id, first, AB))
        dan.submit_in_contest(contest_id, first, AB_WRONG)
        dan.submit_in_contest(contest_id, first, AB)
        # eve failed A before the board froze, solved it afterwards, and fails it once more
        at(30, eve.submit_in_contest(contest_id, first, AB_WRONG))
        uoj.wait_idle()
        eve_right = eve.submit_in_contest(contest_id, first, AB)
        uoj.wait_idle()
        eve.submit_in_contest(contest_id, first, AB_WRONG)
        uoj.wait_idle()

        # ---- what everybody is shown who does not run the contest
        for client in (dan, eve, fay):
            rows, mine, page = icpc_board(client, contest_id)
            self.assertIn('data-frozen="1"', page)
            # what was solved before the board froze stays solved, whatever came after it
            self.assertEqual(rows["p9_frz_dan"], (1, 1, {"A": ("first", "+1 0:20")}), client.username)
            # one attempt that failed, and one whose outcome is not told: what was submitted
            # after the one that solved the problem is not counted, as nothing after it counts
            self.assertEqual(rows["p9_frz_eve"], (2, 0, {"A": ("pending", "? 1 + 1")}), client.username)
            self.assertNotIn("/submission/%d" % eve_right, page if client is not eve else "")
        # ---- and what each of them is shown about themselves, above the board: the truth, and no rank
        rows, mine, page = icpc_board(eve, contest_id)
        self.assertEqual(mine[:3], ("p9_frz_eve", 1, "?"))
        self.assertEqual(mine[3]["A"][0], "solved")
        self.assertRegex(mine[3]["A"][1], r"^\+1 4:1\d$")
        rows, mine, page = icpc_board(dan, contest_id)
        self.assertEqual((mine[:3], mine[3]), (("p9_frz_dan", 1, "?"), {"A": ("solved", "+1 0:20")}))
        self.assertNotIn("p9_frz_eve", page[page.index('id="standings-mine"'):page.index('id="table-icpc-standings"')])
        rows, mine, page = icpc_board(fay, contest_id)
        self.assertEqual((mine[:3], mine[3]), (("p9_frz_fay", 0, "?"), {}))
        # ---- the staff sees how it is, and has no row of its own
        rows, mine, page = icpc_board(admin, contest_id)
        self.assertIsNone(mine)
        self.assertEqual((rows["p9_frz_dan"][:2], rows["p9_frz_eve"][:2], rows["p9_frz_eve"][2]["A"][0]), ((1, 1), (2, 1), "solved"))
        rows, mine, page = icpc_board(admin, contest_id, "?frozen=1")
        self.assertEqual(rows["p9_frz_eve"], (2, 0, {"A": ("pending", "? 1 + 1")}))

        # ---- what waits to be judged is an attempt whose outcome nobody knows, on every board
        with uoj.judgers_paused():
            fay.submit_in_contest(contest_id, second, AB)
            for client in (fay, admin):
                rows, mine, page = icpc_board(client, contest_id)
                self.assertEqual(rows["p9_frz_fay"][1:], (0, {"B": ("pending", "? 0 + 1")}), client.username)
        uoj.wait_idle()
        rows, mine, page = icpc_board(admin, contest_id)
        self.assertEqual(rows["p9_frz_fay"][2]["B"][0], "first")
