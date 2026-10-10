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
        # Somebody who registered and has not opened a problem has not taken part: they are not
        # on the board, and have no row. From the moment they open one they have, with nothing.
        rows, mine, page = icpc_board(fay, contest_id)
        self.assertIsNone(mine)
        self.assertNotIn("p9_frz_fay", rows)
        self.assertEqual(fay.get("/contest/%d/problem/B" % contest_id).status_code, 200)
        rows, mine, page = icpc_board(fay, contest_id)
        self.assertEqual((mine[:3], mine[3]), (("p9_frz_fay", 0, "?"), {}))
        self.assertEqual(rows["p9_frz_fay"], (2, 0, {}))
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


def final_board(client, contest_id, rule, query=""):
    """the board of a contest that is over, as somebody is shown it: rows of (username, rank,
    whether the row is a virtual participation) in the order of the board, and the page"""
    page = client.get("/contest/%d/standings%s" % (contest_id, query)).text
    if rule == "ICPC":
        found = re.findall(r'<tr(?: class="uoj-scoreboard-me")? data-username="([^"]+)"( data-virtual="1")? data-rank="(\d+)"', page)
        return [(name, int(rank), virtual != "") for name, virtual, rank in found], page
    # the other boards are drawn in the browser, from what the page hands its script
    standings = json.loads(re.search(r"(?m)^standings=(.*);$", page).group(1))
    return [(row[2][0], int(row[3]), len(row[2]) > 3 and row[2][3] == "v") for row in standings], page


class VirtualInContestTest(unittest.TestCase):
    """a contest that is over is sat again in its own pages, as Codeforces does it, and what
    came of that is shown on its board afterwards, marked, for whoever wants to see it"""

    def sat(self, contest_id, client, elapsed, submitted):
        """moves a virtual participation in time: it began so many seconds ago, and what was
        submitted in it (id => seconds into it) with it"""
        where = "contest_id = %d and username = '%s'" % (contest_id, client.username)
        db("update contest_virtuals set start_time = '%s' where %s" % (uoj.web_time(-elapsed), where))
        for submission_id, seconds in submitted.items():
            db("update submissions set submit_time = date_add((select start_time from contest_virtuals where %s), interval %d second) where id = %d"
               % (where, seconds, submission_id))  # fmt: skip

    def replayed(self, client, contest_id):
        """the board of the contest as somebody who sits it is shown it: (username, rank, whether the row is theirs)"""
        page = client.get("/contest/%d/standings" % contest_id).text
        self.assertIn('id="table-virtual-standings"', page)
        return [(name, int(rank), "virtual-my-row" in attrs) for attrs, name, rank in re.findall(r'<tr([^>]*) data-username="([^"]+)" data-rank="(\d+)">', page)]

    def check(self, rule, prefix):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p9 虚拟参赛 " + rule, rule=rule, problems="%d, %d" % (first, second))
        here, virtual = "/contest/%d" % contest_id, "/contest/%d/virtual" % contest_id
        early, late, sitter, quick, watcher = (p3.account(prefix + name) for name in ("early", "late", "sitter", "quick", "watcher"))
        name = lambda client: client.username
        for client in (early, late):
            client.register_for_contest(contest_id)

        # ---- the contest: early solves A five minutes in, late thirty minutes in
        uoj.move_contest(contest_id, -600)
        solved = {5: early.submit_in_contest(contest_id, first, AB), 30: late.submit_in_contest(contest_id, first, AB)}
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200)
        for minutes, submission_id in solved.items():
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute) where id = %d"
               % (contest_id, minutes, submission_id))  # fmt: skip
        if rule == "OI":
            self.assertEqual(admin.submit_form(here, "start_test"), "")
            uoj.wait_idle()
        self.assertEqual(admin.submit_form(here, "publish_result"), "")
        real = [(name(early), 1, False), (name(late), 2, False)]
        rows, page = final_board(watcher, contest_id, rule)
        self.assertEqual(rows, real)
        # nobody sat it again yet: the board has nothing to offer
        self.assertNotIn('id="standings-virtual-switch"', page)

        # ---- starting takes one into the contest itself
        r = sitter.post(virtual, {"form": "start"})
        self.assertEqual((r.status_code, r.headers["Location"]), (302, here))
        r = sitter.get(virtual)
        self.assertEqual((r.status_code, r.headers["Location"]), (302, here))
        # whose pages are now the pages of the participation: the problems, and the clock
        page = sitter.get(here).text
        for mark in ('id="virtual-running"', 'id="virtual-clock"', 'id="table-virtual-problems"', 'href="/contest/%d/problem/A"' % contest_id):
            self.assertIn(mark, page)
        self.assertNotIn('id="link-virtual"', page)
        # to everybody else they are what they were
        page = watcher.get(here).text
        self.assertNotIn('id="virtual-running"', page)
        self.assertIn('id="link-virtual"', page)

        # ---- a problem is submitted to as in the contest, and one is taken where the contest takes one
        r = sitter.post(here + "/problem/A", {
            "submit-answer": "answer", "answer_answer_upload_type": "editor", "answer_answer_editor": AB, "answer_answer_language": "C++17",
        })  # fmt: skip
        self.assertEqual((r.status_code, r.headers["Location"]), (302, here + "/submissions"))
        own = int(db_value("select max(id) from submissions where submitter = '%s' and problem_id = %d" % (name(sitter), first)))
        uoj.wait_submission(own)
        mine = {own: 600}
        # twenty minutes in: the list of the contest is the list of what one submitted in it
        self.sat(contest_id, sitter, 1200, mine)
        page = sitter.get(here + "/submissions").text
        self.assertIn('id="table-virtual-submissions"', page)
        self.assertIn('href="/submission/%d"' % own, page)
        # and its board is the board as it was twenty minutes into the contest, with oneself on it
        self.assertEqual(self.replayed(sitter, contest_id), [(name(early), 1, False), (name(sitter), 2, True), (name(late), 3, False)])
        # the board of everybody else is the board of the contest: a participation that is not
        # over is on no board
        for query in ("", "?virtual=1"):
            rows, page = final_board(watcher, contest_id, rule, query)
            self.assertEqual(rows, real, query)
            self.assertNotIn('id="standings-virtual-switch"', page)

        # ---- when it is over, the pages of the contest are the pages of the contest again
        self.sat(contest_id, sitter, 4000, mine)
        page = sitter.get(here).text
        self.assertNotIn('id="virtual-running"', page)
        self.assertIn('id="link-virtual"', page)
        page = sitter.get(virtual).text
        self.assertIn('id="virtual-ended"', page)
        self.assertIn('id="link-virtual-on-board" href="/contest/%d/standings?virtual=1"' % contest_id, page)
        # Whoever sat it sees themselves on its board without asking: where they would have
        # stood, with the rank they would have had. Solved ten minutes in, that is behind
        # early and before late, whose rank is what it was.
        with_sitter = [(name(early), 1, False), (name(sitter), 2, True), (name(late), 2, False)]
        rows, page = final_board(sitter, contest_id, rule)
        self.assertEqual(rows, with_sitter)
        self.assertRegex(uoj.text_of(page), r"有 1 人赛后虚拟参赛")
        self.assertIn('href="/contest/%d/standings?virtual=0"' % contest_id, page)
        rows, page = final_board(sitter, contest_id, rule, "?virtual=0")
        self.assertEqual(rows, real)
        self.assertIn('href="/contest/%d/standings?virtual=1"' % contest_id, page)
        # everybody else sees the contestants, and the others when they ask
        rows, page = final_board(watcher, contest_id, rule)
        self.assertEqual(rows, real)
        self.assertIn('id="standings-virtual-switch"', page)
        self.assertNotIn(name(sitter), page)
        rows, page = final_board(watcher, contest_id, rule, "?virtual=1")
        self.assertEqual(rows, with_sitter)
        # and nothing of the contest has changed
        self.assertEqual(db("select username, `rank` from contests_registrants where contest_id = %d order by `rank`" % contest_id), [[name(early), "1"], [name(late), "2"]])

        # ---- somebody who solves both problems in two minutes stands before everybody, and is first at nothing
        self.assertEqual(quick.form(virtual, "start"), "")
        theirs = {quick.submit(first, AB, path=here + "/problem/A"): 60, quick.submit(second, AB, path=here + "/problem/B"): 120}
        for submission_id in theirs:
            uoj.wait_submission(submission_id)
        self.sat(contest_id, quick, 4000, theirs)
        # somebody who took part and sits it again is on the board twice
        self.assertEqual(early.form(virtual, "start"), "")
        self.sat(contest_id, early, 4000, {})
        rows, page = final_board(watcher, contest_id, rule, "?virtual=1")
        self.assertEqual(rows, [(name(quick), 1, True), (name(early), 1, False), (name(sitter), 2, True), (name(late), 2, False), (name(early), 3, True)])
        self.assertRegex(uoj.text_of(page), r"有 3 人赛后虚拟参赛")
        if rule == "ICPC":
            board = page[page.index('id="table-icpc-standings"'):]
            # who solved a problem first, and how many solved it, is said of the contestants
            self.assertEqual(re.findall(r'data-solved-by="(\d+)"', board), ["2", "0"])
            cells = re.findall(r'<tr[^>]* data-username="([^"]+)"( data-virtual="1")? [^>]*>.*?<td class="uoj-icpc-(\w*)" data-problem="A">', board, re.S)
            self.assertEqual([(who, kind) for who, virtual, kind in cells if who in (name(quick), name(early)) and kind],
                             [(name(quick), "solved"), (name(early), "first")])  # fmt: skip
            # a rank that nobody holds is written as one: (1)
            self.assertRegex(board, r'data-username="%s" data-virtual="1" data-rank="1"[^>]*>\s*<td><span class="text-muted" title="[^"]*">\(1\)</span></td>' % name(quick))
            self.assertRegex(board, r'data-username="%s" data-rank="1"[^>]*>\s*<td>1</td>' % name(early))
            # and the contestants are as many as they were
            self.assertIn("共 2 名参赛者", uoj.text_of(page))
        else:
            # the cells of a virtual row are kept beside the ones its user has as a contestant
            score = json.loads(re.search(r"(?m)^score=(.*);$", page).group(1))
            self.assertEqual(sorted(key for key in score if key.startswith("v/")), sorted("v/" + name(client) for client in (quick, sitter, early)))
            self.assertEqual([cell[0] for cell in score["v/" + name(quick)]], [100, 100])
            self.assertEqual(len(score[name(early)]), 1)

        # ---- sitting it once more takes the last time off the board: one participation each
        self.assertEqual(sitter.form(virtual, "start"), "")
        rows, page = final_board(watcher, contest_id, rule, "?virtual=1")
        self.assertNotIn((name(sitter), 2, True), rows)
        self.assertRegex(uoj.text_of(page), r"有 2 人赛后虚拟参赛")
        self.assertIn('id="virtual-running"', sitter.get(here).text)

    def test_contest_is_sat_in_its_own_pages_and_shown_on_its_board(self):
        self.check("OI", "p9_vo_")

    def test_icpc_contest_is_sat_in_its_own_pages_and_shown_on_its_board(self):
        self.check("ICPC", "p9_vi_")


def balloon_list(client, contest_id, query=""):
    """the list of the balloons of a contest as somebody is shown it: rows of (who, the letter of
    the problem, where they sit, whether it was brought, what is special about it, the problems
    they have a balloon for with it) in the order of the list, and the page"""
    r = client.get("/contest/%d/balloons%s" % (contest_id, query))
    assert r.status_code == 200, r.status_code
    rows = []
    for attrs, body in re.findall(r'(?s)<tr class="uoj-balloon-(?:pending|done)"([^>]*)>(.*?)</tr>', r.text):
        said = lambda name: re.search(r'data-%s="([^"]*)"' % name, attrs).group(1)
        seat = uoj.text_of(re.search(r'(?s)<td class="uoj-balloon-seat">(.*?)</td>', body).group(1))
        rows.append((said("username"), said("problem"), seat, said("done") == "1", tuple(re.findall(r'data-award="(\w+)"', body)), re.search(r'data-has="(\w*)"', body).group(1)))
    return rows, r.text


class BalloonTest(unittest.TestCase):
    """whoever solves a problem of a contest that is held in a room is brought a balloon, the way
    DOMjudge has it: a list of the balloons to bring, with where to, that is ticked off"""

    def test_balloons_from_the_list_to_the_desk(self):
        admin = uoj.admin()
        first, second = admin.create_problem(ab_problem_files()), admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p9 气球赛", minutes=300, rule="ICPC", freeze_minutes="60", problems="%d, %d" % (first, second))
        here, manage, balloons = "/contest/%d" % contest_id, "/contest/%d/manage" % contest_id, "/contest/%d/balloons" % contest_id
        ann, bob, cat, runner, outsider = (p3.account("p9_bal_" + name) for name in ("ann", "bob", "cat", "runner", "outsider"))
        self.assertEqual(admin.form(manage, "add_manager", tab="managers", username="p9_bal_runner", role="assistant"), "")
        self.assertEqual(admin.form(manage, "add_contestants", tab="contestants", names="p9_bal_ann A-12\np9_bal_bob B-3\np9_bal_cat"), "")
        seat_of = lambda name: db_value("select seat from contests_registrants where contest_id = %d and username = '%s'" % (contest_id, name))
        marks = lambda: db("select username, problem_id, done_by from contest_balloons where contest_id = %d order by username, problem_id" % contest_id)
        legend = lambda page: re.findall(r'data-problem="(\w)" data-color="(#\w+)"', page[page.index('id="balloon-legend"'):page.index('id="form-balloon-filter"')])
        # a hundred minutes into its three hundred; the last sixty are frozen
        uoj.move_contest(contest_id, -100 * 60, 300)

        def at(minutes, submission_id):
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute)"
               " where id = %d" % (contest_id, minutes, submission_id))  # fmt: skip
            return submission_id

        # ---- the list is for the people who run the contest
        self.assertEqual(uoj.Client().get(balloons).status_code, 302)
        for client in (ann, outsider):
            self.assertEqual(client.get(balloons).status_code, 403)
        # A contest gives no balloons until somebody who decides about it says so: the page
        # says what balloons are, and an assistant is told who to ask.
        page = runner.get(balloons).text
        self.assertIn('id="balloons-off"', page)
        self.assertNotIn('id="button-enable-balloons"', page)
        self.assertEqual(runner.post(balloons, {"form": "settings", "balloons": "on"}).status_code, 403)
        self.assertEqual(db_value("select balloons from contests where id = %d" % contest_id), "0")
        self.assertNotIn('id="link-balloons"', admin.get(here).text)
        self.assertNotIn('id="form-my-seat"', ann.get(here).text)
        self.assertIn('id="link-manage-balloons"', admin.get(manage).text)
        self.assertIn('id="button-enable-balloons"', admin.get(balloons).text)
        self.assertEqual(admin.form(balloons, "settings", balloons="on"), "")
        self.assertEqual(db("select balloons, balloons_after_freeze from contests where id = %d" % contest_id), [["1", "0"]])
        rows, page = balloon_list(runner, contest_id)
        self.assertEqual(rows, [])
        self.assertIn('id="balloons-none"', page)
        self.assertNotIn('id="balloon-settings"', page)
        # the problems have colours without anybody choosing them
        self.assertEqual(legend(page), [("A", "#e53935"), ("B", "#fb8c00")])
        self.assertIn("有 1 位选手没填座位", uoj.text_of(page))

        # ---- one balloon for a problem of a contestant, when they solve it for the first time
        at(10, ann.submit_in_contest(contest_id, first, AB_WRONG))
        at(20, ann.submit_in_contest(contest_id, first, AB))
        at(30, bob.submit_in_contest(contest_id, first, AB))
        at(40, bob.submit_in_contest(contest_id, second, AB))
        at(50, ann.submit_in_contest(contest_id, first, AB))
        uoj.wait_idle()
        rows, page = balloon_list(runner, contest_id)
        self.assertEqual(rows, [
            ("p9_bal_ann", "A", "A-12", False, ("contest", "problem"), "A"),
            ("p9_bal_bob", "A", "B-3", False, (), "A"),
            ("p9_bal_bob", "B", "B-3", False, ("problem",), "AB"),
        ])  # fmt: skip
        self.assertRegex(page, r'id="balloon-board" data-pending="3" data-done="0"')
        # the people who run the contest see on its page how many wait
        self.assertRegex(admin.get(here).text, r'id="link-balloons">气球 <span class="badge badge-light" id="balloons-waiting">3</span>')
        self.assertIn('id="link-balloons"', runner.get(here).text)
        self.assertNotIn('id="link-balloons"', ann.get(here).text)

        # ---- a balloon that was brought is ticked off, by whoever brought it
        self.assertEqual(runner.form(balloons, "done", username="p9_bal_bob", problem_id=str(first)), "")
        self.assertEqual(marks(), [["p9_bal_bob", str(first), "p9_bal_runner"]])
        # the ones that wait come first
        rows, page = balloon_list(admin, contest_id)
        self.assertEqual([(row[0], row[1], row[3]) for row in rows], [("p9_bal_ann", "A", False), ("p9_bal_bob", "B", False), ("p9_bal_bob", "A", True)])
        self.assertRegex(page, r'id="balloon-board" data-pending="2" data-done="1"')
        self.assertRegex(uoj.text_of(page), r"已送 p9_bal_runner \d\d:\d\d")
        # said twice it was brought once, by who said so first
        self.assertEqual(admin.form(balloons, "done", username="p9_bal_bob", problem_id=str(first)), "")
        self.assertEqual(marks(), [["p9_bal_bob", str(first), "p9_bal_runner"]])
        # a balloon that nobody earned is not brought
        for name, problem_id in (("p9_bal_cat", first), ("p9_bal_ann", second), ("p9_bal_nobody", first), ("p9_bal_ann", 0)):
            self.assertIn("没有这个气球", runner.form(balloons, "done", username=name, problem_id=str(problem_id)))
        self.assertEqual(len(marks()), 1)
        # the list is asked for what waits, for what was brought, and for a part of the room
        for query, expected in (("?show=pending", [("p9_bal_ann", "A"), ("p9_bal_bob", "B")]), ("?show=done", [("p9_bal_bob", "A")]),
                                ("?seat=b-", [("p9_bal_bob", "B"), ("p9_bal_bob", "A")]), ("?show=pending&seat=A-1", [("p9_bal_ann", "A")]),
                                ("?seat=Z", [])):  # fmt: skip
            self.assertEqual([row[:2] for row in balloon_list(runner, contest_id, query)[0]], expected, query)
        # and stays what it was asked for when something on it is ticked: here, taken back
        r = runner.post(balloons + "?show=done&seat=B", {"form": "undo", "username": "p9_bal_bob", "problem_id": str(first)})
        self.assertEqual((r.status_code, r.headers["Location"]), (302, balloons + "?show=done&seat=B"))
        self.assertEqual(marks(), [])

        # ---- the colours are chosen by who decides about the contest
        chosen = {"color_%d" % first: "#e53935", "name_%d" % first: "红色", "color_%d" % second: "#00FF00", "name_%d" % second: "荧光绿"}
        self.assertEqual(runner.post(balloons, dict(chosen, form="colors")).status_code, 403)
        self.assertIn("B 题：颜色要写成", admin.form(balloons, "colors", **dict(chosen, **{"color_%d" % second: "green"})))
        self.assertEqual(db_value("select count(*) from contest_balloon_colors where contest_id = %d" % contest_id), "0")
        self.assertEqual(admin.form(balloons, "colors", **chosen), "")
        # a problem that keeps the colour it had has no colour of its own
        self.assertEqual(db("select problem_id, color, name from contest_balloon_colors where contest_id = %d" % contest_id), [[str(second), "#00ff00", "荧光绿"]])
        rows, page = balloon_list(runner, contest_id)
        self.assertEqual(legend(page), [("A", "#e53935"), ("B", "#00ff00")])
        self.assertRegex(page, r'(?s)data-balloon="p9_bal_bob/B".*?background-color:#00ff00;color:#000000.*?荧光绿')
        self.assertIn('id="balloon-settings"', admin.get(balloons).text)
        # the contestants see what a problem is worth, on the page of the contest and on its board
        self.assertRegex(ann.get(here).text, r'class="uoj-balloon-dot" style="background-color:#00ff00" title="气球：荧光绿"')
        self.assertIn('data-balloon="#00ff00"', ann.get(here + "/standings").text)

        # ---- a contestant says where they sit, and nobody else does it for them
        self.assertIn('id="form-my-seat"', cat.get(here).text)
        self.assertNotIn('id="form-my-seat"', admin.get(here).text)
        self.assertEqual(cat.form(here, "my_seat", seat="C 区 7"), "")
        self.assertEqual(seat_of("p9_bal_cat"), "C 区 7")
        self.assertIn("座位最多 20 个字符", cat.form(here, "my_seat", seat="<b>x</b>"))
        self.assertEqual((seat_of("p9_bal_cat"), seat_of("p9_bal_ann")), ("C 区 7", "A-12"))
        self.assertRegex(cat.get(here).text, r'id="input-my-seat" name="seat" value="C 区 7"')

        # ---- the board freezes: what is solved from then on is held back, as the board holds it back
        uoj.move_contest(contest_id, -250 * 60, 300)
        uoj.wait_submission(cat.submit_in_contest(contest_id, first, AB))
        rows, page = balloon_list(runner, contest_id)
        self.assertEqual([row[:2] for row in rows], [("p9_bal_ann", "A"), ("p9_bal_bob", "A"), ("p9_bal_bob", "B")])
        self.assertIn('id="balloons-held-back"', page)
        self.assertIn("封榜后通过的 1 个气球先不发", uoj.text_of(page))
        self.assertIn("没有这个气球", runner.form(balloons, "done", username="p9_bal_cat", problem_id=str(first)))
        # unless the contest says that it goes on
        self.assertEqual(admin.form(balloons, "settings", balloons="on", after_freeze="on"), "")
        rows, page = balloon_list(runner, contest_id)
        self.assertEqual(rows[-1], ("p9_bal_cat", "A", "C 区 7", False, (), "A"))
        self.assertIn('id="balloons-after-freeze"', page)
        self.assertNotIn('id="balloons-held-back"', page)
        self.assertEqual(admin.form(balloons, "settings", balloons="on"), "")
        self.assertEqual(len(balloon_list(runner, contest_id)[0]), 3)
        # and when the results are published everything is on the list
        uoj.move_contest(contest_id, -400 * 60, 300)
        self.assertEqual(admin.submit_form(here, "publish_result"), "")
        rows, page = balloon_list(runner, contest_id)
        self.assertEqual(rows[-1][:3], ("p9_bal_cat", "A", "C 区 7"))
        self.assertNotIn('id="balloons-held-back"', page)
        # a contest that is over is not told where anybody sits any more
        self.assertNotIn('id="form-my-seat"', cat.get(here).text)

        # ---- somebody who is taken out of the contest is brought nothing
        self.assertEqual(runner.form(balloons, "done", username="p9_bal_ann", problem_id=str(first)), "")
        self.assertEqual(admin.form(manage, "remove_contestant", tab="contestants", username="p9_bal_bob"), "")
        rows, page = balloon_list(runner, contest_id)
        self.assertEqual([(row[0], row[1], row[3]) for row in rows], [("p9_bal_cat", "A", False), ("p9_bal_ann", "A", True)])
        # ---- a contest that stops giving balloons keeps what was written down
        self.assertEqual(admin.form(balloons, "settings"), "")
        self.assertIn('id="balloons-off"', runner.get(balloons).text)
        self.assertNotIn('id="link-balloons"', admin.get(here).text)
        self.assertIn("没有启用气球", runner.form(balloons, "done", username="p9_bal_cat", problem_id=str(first)))
        self.assertEqual(admin.form(balloons, "settings", balloons="on"), "")
        self.assertEqual([(row[0], row[3]) for row in balloon_list(runner, contest_id)[0]], [("p9_bal_cat", False), ("p9_bal_ann", True)])

        # ---- under the OI rule nobody knows during the contest what was solved: no balloons
        oi = admin.new_contest("p9 气球 OI", problems=str(first))
        self.assertIn('id="balloons-not-for-rule"', admin.get("/contest/%d/balloons" % oi).text)
        self.assertIn("没有气球可发", admin.form("/contest/%d/balloons" % oi, "settings", balloons="on"))
        self.assertEqual(db_value("select balloons from contests where id = %d" % oi), "0")

        # ---- a contest that is deleted takes its balloons with it
        self.assertEqual(admin.form(manage, "delete_contest", tab="delete", confirm="p9 气球赛"), "")
        self.assertEqual(db_value("select (select count(*) from contest_balloons where contest_id = %d) + (select count(*) from contest_balloon_colors where contest_id = %d)"
                                  % (contest_id, contest_id)), "0")  # fmt: skip


def registered(contest_id):
    return [row[0] for row in db("select username from contests_registrants where contest_id = %d order by username" % contest_id)]


class RegistrationTest(unittest.TestCase):
    """a contest is joined before it begins and while it runs, and the people who run it put
    contestants in and take them out at any time"""

    def test_contest_is_joined_while_it_runs_and_not_after_it(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        early, late, never = (p3.account("p9_reg_" + name) for name in ("early", "late", "never"))
        contest_id = admin.new_contest("p9 迟到也能报名", minutes=120, rule="ICPC", problems=str(problem_id))
        here = "/contest/%d" % contest_id
        early.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -1800, 120)

        # ---- half an hour into it: the list of the contests offers it, and its pages lead to where it is joined
        self.assertRegex(late.get("/contests").text, r'href="%s/register">' % here)
        self.assertNotRegex(early.get("/contests").text, r'href="%s/register">' % here)
        for path in (here, here + "/problem/A", here + "/standings"):
            r = late.get(path)
            self.assertEqual((r.status_code, r.headers.get("Location")), (302, here + "/register"), path)
        page = late.get(here + "/register").text
        self.assertIn('id="register-while-running"', page)
        late.register_for_contest(contest_id)
        self.assertEqual(registered(contest_id), ["p9_reg_early", "p9_reg_late"])
        self.assertEqual(late.get(here).status_code, 200)
        # who came late is in the contest from its start: the time of what they solve counts from there
        submission_id = late.submit_in_contest(contest_id, problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        rows, mine, page = icpc_board(late, contest_id)
        self.assertEqual(rows["p9_reg_late"][:2], (1, 1))
        self.assertRegex(rows["p9_reg_late"][2]["A"][1], r"^\+ 0:3\d$")
        # nobody registers twice
        r = late.get(here + "/register")
        self.assertEqual(r.status_code, 302)

        # ---- when it is over it is not joined any more
        uoj.move_contest(contest_id, -3 * 3600, 120)
        r = never.get(here + "/register")
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, here))
        never.post(here + "/register", {"submit-register": "register"})
        self.assertEqual(registered(contest_id), ["p9_reg_early", "p9_reg_late"])
        self.assertNotRegex(never.get("/contests").text, r'href="%s/register">' % here)

    def test_password_and_list_still_decide_who_joins_a_running_contest(self):
        admin = uoj.admin()
        knows, listed, other = (p3.account("p9_join_" + name) for name in ("knows", "listed", "other"))
        with_password = admin.new_contest("p9 进行中的密码赛", minutes=120, join_mode="password", join_password="open sesame")
        with_list = admin.new_contest("p9 进行中的名单赛", minutes=120, join_mode="list")
        self.assertEqual(admin.form("/contest/%d/manage" % with_list, "allow", tab="access", names="p9_join_listed"), "")
        for contest_id in (with_password, with_list):
            uoj.move_contest(contest_id, -600, 120)
        register = "/contest/%d/register" % with_password
        self.assertNotEqual(other.submit_form(register, "register", {"join_password": "wrong"}), "")
        self.assertEqual(knows.submit_form(register, "register", {"join_password": "open sesame"}), "")
        self.assertEqual(registered(with_password), ["p9_join_knows"])
        # a contest with a list is not there for who is not on it
        self.assertEqual(other.get("/contest/%d/register" % with_list).status_code, 404)
        other.post("/contest/%d/register" % with_list, {"submit-register": "register"})
        self.assertEqual(listed.submit_form("/contest/%d/register" % with_list, "register"), "")
        self.assertEqual(registered(with_list), ["p9_join_listed"])

    def test_staff_puts_contestants_in_and_takes_them_out_at_any_time(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        ann, bob, cat, helper = (p3.account("p9_staff_" + name) for name in ("ann", "bob", "cat", "helper"))
        contest_id = admin.new_contest("p9 现场赛", minutes=120, rule="ICPC", problems=str(problem_id))
        here, manage = "/contest/%d" % contest_id, "/contest/%d/manage" % contest_id
        self.assertEqual(admin.form(manage, "add_manager", tab="managers", username="p9_staff_helper", role="assistant"), "")
        seats = lambda: dict(db("select username, seat from contests_registrants where contest_id = %d" % contest_id))
        flash = lambda: uoj.text_of(re.search(r'(?s)id="contest-manage-flash">(.*?)</div>', admin.get(manage).text).group(1))

        # ---- before it begins: several at once, with where they sit
        self.assertEqual(admin.form(manage, "add_contestants", tab="contestants",
                                    names="p9_staff_ann A-12\np9_staff_bob，3 排 7 座\np9_staff_nobody B-1\np9_staff_helper\nbad name!"), "")  # fmt: skip
        self.assertEqual(seats(), {"p9_staff_ann": "A-12", "p9_staff_bob": "3 排 7 座"})
        said = flash()
        for told in ("加入了 2 位选手", "p9_staff_nobody：没有这个用户", "p9_staff_helper：是这场比赛的工作人员"):
            self.assertIn(told, said)
        self.assertEqual(db_value("select player_num from contests where id = %d" % contest_id), "2")
        page = admin.get(manage).text
        self.assertRegex(page, r'(?s)<tr data-username="p9_staff_bob">.*?name="seat" value="3 排 7 座"')
        # a seat is changed by itself, or with the list again; what a seat can not be is refused
        self.assertEqual(admin.form(manage, "set_seat", tab="contestants", username="p9_staff_ann", seat="A-13"), "")
        self.assertEqual(admin.form(manage, "add_contestants", tab="contestants", names="p9_staff_bob B-2\np9_staff_ann"), "")
        self.assertEqual(seats(), {"p9_staff_ann": "A-13", "p9_staff_bob": "B-2"})
        self.assertIn("更新了 1 个座位", flash())
        self.assertNotEqual(admin.form(manage, "set_seat", tab="contestants", username="p9_staff_ann", seat="<b>x</b>"), "")
        self.assertEqual(seats()["p9_staff_ann"], "A-13")

        # ---- while it runs: somebody who came late is put in, and is inside at once
        uoj.move_contest(contest_id, -1800, 120)
        self.assertEqual(admin.form(manage, "add_contestants", tab="contestants", names="p9_staff_cat C-1"), "")
        self.assertEqual(cat.get(here).status_code, 200)
        self.assertEqual(uoj.wait_submission(cat.submit_in_contest(contest_id, problem_id, AB)).score, 100)
        # the board is of who has opened a problem of the contest: cat, and ann once she has
        self.assertEqual(sorted(icpc_board(admin, contest_id)[0]), ["p9_staff_cat"])
        self.assertEqual(ann.get(here + "/problem/A").status_code, 200)
        self.assertEqual(sorted(icpc_board(admin, contest_id)[0]), ["p9_staff_ann", "p9_staff_cat"])
        # ---- somebody is taken out: off the board, with what they submitted kept, and back with it
        self.assertEqual(admin.form(manage, "remove_contestant", tab="contestants", username="p9_staff_cat"), "")
        self.assertEqual(sorted(seats()), ["p9_staff_ann", "p9_staff_bob"])
        self.assertNotIn("p9_staff_cat", icpc_board(admin, contest_id)[0])
        self.assertEqual(db_value("select count(*) from submissions where contest_id = %d and submitter = 'p9_staff_cat'" % contest_id), "1")
        self.assertNotEqual(admin.form(manage, "remove_contestant", tab="contestants", username="p9_staff_cat"), "")
        self.assertEqual(admin.form(manage, "add_contestants", tab="contestants", names="p9_staff_cat"), "")
        self.assertEqual(icpc_board(admin, contest_id)[0]["p9_staff_cat"][:2], (1, 1))

        # ---- only who runs the contest does this
        for nobody in (helper, ann):
            for form, fields in (("add_contestants", dict(names="p9_staff_helper")), ("remove_contestant", dict(username="p9_staff_bob")),
                                 ("set_seat", dict(username="p9_staff_bob", seat="Z-9"))):  # fmt: skip
                self.assertEqual(nobody.post(manage, dict(fields, form=form, tab="contestants")).status_code, 403, form)
        self.assertEqual(seats()["p9_staff_bob"], "B-2")
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'contest.add_contestants' and resource_id = '%d'" % contest_id), "4")


def exists(path):
    return uoj.docker_exec(uoj.WEB, "test -e %s && echo there; true" % path).strip() == "there"


class DeletionTest(unittest.TestCase):
    """a problem is deleted with what is its own, when nothing needs it any more; a contest is
    deleted and leaves its problems and what was submitted to them"""

    STORAGE = "/var/www/uoj/app/storage"

    def word(self, client, page_path, field):
        """the name a page asks for before it deletes"""
        return re.search(r'(?s)<label for="%s">.*?<strong>(.*?)</strong>' % field, client.get(page_path).text).group(1)

    def test_problem_is_deleted_with_everything_that_is_its_own(self):
        import html

        admin = uoj.admin()
        solver, stranger = p3.account("p9_del_solver"), p3.account("p9_del_stranger")
        problem_id = admin.create_problem(ab_problem_files())
        db("update problems set title = 'p9 要删的题 &amp; 它的数据' where id = %d" % problem_id)
        right, wrong = solver.submit(problem_id, AB), solver.submit(problem_id, AB_WRONG)
        self.assertEqual(uoj.wait_submission(right).score, 100)
        uoj.wait_submission(wrong)
        solved_before = int(db_value("select ac_num from user_info where username = 'p9_del_solver'"))
        files = [json.loads(db_value("select content from submissions where id = %d" % s))["file_name"] for s in (right, wrong)]
        for name in files:
            self.assertTrue(exists(self.STORAGE + name), name)
        contest_id = admin.new_contest("p9 用着这道题的比赛", problems=str(problem_id))
        page_path = "/problem/%d/manage/delete" % problem_id

        # ---- the way there is on the pages that manage the problem, for who manages it
        self.assertIn('href="%s"' % page_path, admin.get("/problem/%d/manage/statement" % problem_id).text)
        for nobody in (solver, stranger):
            self.assertEqual(nobody.get(page_path).status_code, 403)
            self.assertEqual(nobody.post(page_path, {"form": "delete_problem", "confirm": "p9 要删的题 & 它的数据"}).status_code, 403)

        # ---- a problem that a contest has is not deleted: the page says where it is, and has no form
        page = admin.get(page_path).text
        self.assertIn('id="problem-in-use"', page)
        self.assertIn("p9 用着这道题的比赛", page)
        self.assertNotIn('id="form-delete-problem"', page)
        self.assertIn("先把它从那里移出", admin.form(page_path, "delete_problem", confirm="p9 要删的题 & 它的数据"))
        self.assertEqual(db_value("select count(*) from problems where id = %d" % problem_id), "1")
        self.assertEqual(admin.form("/contest/%d/manage" % contest_id, "remove_problem", tab="problems", problem_id=str(problem_id)), "")

        # ---- it says what goes with the problem, and asks for its name
        page = admin.get(page_path).text
        told = uoj.text_of(re.search(r'(?s)<ul id="problem-deletion-facts">(.*?)</ul>', page).group(1))
        self.assertRegex(told, r"2\s*份提交（来自 1 个人）")
        name = html.unescape(self.word(admin, page_path, "input-confirm-delete"))
        self.assertEqual(name, "p9 要删的题 & 它的数据")
        for not_it in ("", "p9 要删的题", "#%d" % problem_id):
            self.assertIn("题目没有删除", admin.form(page_path, "delete_problem", confirm=not_it))
        self.assertEqual(db_value("select count(*) from problems where id = %d" % problem_id), "1")

        # ---- with its name it is gone, with what was its own
        r = admin.post(page_path, {"form": "delete_problem", "confirm": " " + name + " "})
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/problems"))
        page = admin.get("/problems").text
        self.assertIn('id="problems-flash"', page)
        self.assertIn("已删除", page)
        for table, column in (("problems", "id"), ("problems_contents", "id"), ("submissions", "problem_id"), ("best_ac_submissions", "problem_id"),
                              ("problem_data_versions", "problem_id"), ("problems_tags", "problem_id"), ("problems_permissions", "problem_id"),
                              ("submission_judgements", "problem_id")):  # fmt: skip
            self.assertEqual(db_value("select count(*) from %s where %s = %d" % (table, column, problem_id)), "0", table)
        for path in ["/var/uoj_data/%d" % problem_id, "/var/uoj_data/upload/%d" % problem_id, "/var/uoj_data/%d.zip" % problem_id] + [self.STORAGE + name for name in files]:
            self.assertFalse(exists(path), path)
        self.assertEqual(int(db_value("select ac_num from user_info where username = 'p9_del_solver'")), solved_before - 1)
        for path in ("/problem/%d" % problem_id, page_path, "/submission/%d" % right):
            self.assertEqual(admin.get(path).status_code, 404, path)
        self.assertEqual(solver.get("/submissions?submitter=p9_del_solver").status_code, 200)
        logged = json.loads(db_value("select before_json from audit_logs where action = 'problem.delete' and resource_id = '%d'" % problem_id))
        self.assertEqual((logged["submissions"], logged["submitters"]), (2, 1))
        # its number is not given to the next problem
        self.assertGreater(admin.new_problem(title="p9 之后的题"), problem_id)

    def test_problem_of_a_domain_is_deleted_by_who_teaches_there(self):
        admin = uoj.admin()
        teacher, member = p3.account("p9_del_teacher"), p3.account("p9_del_member")
        self.assertEqual(admin.change_user("p9_del_teacher", "grant:teacher"), "")
        slug = "p9-delete"
        if db_value("select count(*) from domains where slug = '%s'" % slug) == "0":
            teacher.new_domain(slug)
        self.assertEqual(teacher.form("/d/%s/members" % slug, "add", username="p9_del_member", role="member"), "")
        kept = teacher.new_problem(slug, title="p9 留下的题", public="on")
        gone = teacher.new_problem(slug, title="p9 域里要删的题", public="on")
        number = uoj.pid(gone)
        page_path = "/d/%s/problem/%d/manage/delete" % (slug, number)
        self.assertEqual(member.get(page_path).status_code, 403)
        # in a training: not deleted
        self.assertEqual(teacher.form("/d/%s/training/new" % slug, "save", title="p9 训练", description_md="", status="draft"), "")
        training_id = int(db_value("select max(id) from trainings"))
        self.assertEqual(teacher.form("/d/%s/training/%d/manage" % (slug, training_id), "add_problem", problem_id=str(number)), "")
        self.assertIn("训练“p9 训练”", teacher.get(page_path).text)
        self.assertNotEqual(teacher.form(page_path, "delete_problem", confirm="p9 域里要删的题"), "")
        self.assertEqual(teacher.form("/d/%s/training/%d/manage" % (slug, training_id), "remove_problem", problem_id=str(gone)), "")
        # deleted: back in the list of the problems of the domain, which says so
        r = teacher.post(page_path, {"form": "delete_problem", "confirm": "p9 域里要删的题"})
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/d/%s/problems" % slug))
        page = teacher.get("/d/%s/problems" % slug).text
        self.assertIn("已删除", page)
        self.assertIn("p9 留下的题", page)
        self.assertNotIn("p9 域里要删的题</a>", page)
        self.assertEqual(db_value("select count(*) from problems where id = %d" % gone), "0")
        # the number it had in the domain is not the number of the next problem
        self.assertEqual(uoj.pid(teacher.new_problem(slug, title="p9 域里之后的题")), number + 1)
        self.assertEqual(uoj.pid(kept), number - 1)

    def test_contest_is_deleted_and_its_problems_and_submissions_stay(self):
        admin = uoj.admin()
        ann, helper = p3.account("p9_delc_ann"), p3.account("p9_delc_helper")
        problem_id = admin.create_problem(ab_problem_files())
        db("update problems set is_hidden = 1 where id = %d" % problem_id)
        contest_id = admin.new_contest("p9 要删的比赛", minutes=120, rule="IOI", problems=str(problem_id))
        here, manage = "/contest/%d" % contest_id, "/contest/%d/manage" % contest_id
        self.assertEqual(admin.form(manage, "add_manager", tab="managers", username="p9_delc_helper", role="assistant"), "")
        ann.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -600, 120)
        submission_id = ann.submit_in_contest(contest_id, problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        self.assertEqual(db_value("select contest_id from submissions where id = %d" % submission_id), str(contest_id))

        # ---- the page says what goes and what stays, also that the contest runs
        page = admin.get(manage).text
        told = uoj.text_of(re.search(r'(?s)<ul id="contest-deletion-facts">(.*?)</ul>', page).group(1))
        for fact in ("1 位选手的报名", "1 道题目", "1 份提交", "正在进行"):
            self.assertIn(fact, told)
        # ---- who helps with a contest does not delete it, and its name has to be right
        self.assertEqual(helper.post(manage, {"form": "delete_contest", "tab": "delete", "confirm": "p9 要删的比赛"}).status_code, 403)
        self.assertIn("比赛没有删除", admin.form(manage, "delete_contest", tab="delete", confirm="p9"))
        self.assertEqual(db_value("select count(*) from contests where id = %d" % contest_id), "1")

        # ---- deleted: the contest is gone with who was in it, and nothing of the problem is
        r = admin.post(manage, {"form": "delete_contest", "tab": "delete", "confirm": "p9 要删的比赛"})
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/contests"))
        page = admin.get("/contests").text
        self.assertIn('id="contests-flash"', page)
        self.assertNotIn(">p9 要删的比赛</a>", page)
        for table in ("contests_registrants", "contests_permissions", "contests_problems", "contests_submissions", "contest_allowed_users"):
            self.assertEqual(db_value("select count(*) from %s where contest_id = %d" % (table, contest_id)), "0", table)
        self.assertEqual(db_value("select count(*) from contests where id = %d" % contest_id), "0")
        for path in (here, manage, here + "/standings"):
            self.assertEqual(admin.get(path).status_code, 404, path)
        # what was submitted to it is a submission to its problem, which is as hidden as it was
        self.assertEqual(db("select ifnull(contest_id, 'none'), problem_id, score from submissions where id = %d" % submission_id), [["none", str(problem_id), "100"]])
        self.assertEqual(db_value("select is_hidden from problems where id = %d" % problem_id), "1")
        self.assertEqual(admin.get("/submission/%d" % submission_id).status_code, 200)
        self.assertEqual(admin.get("/problem/%d" % problem_id).status_code, 200)
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'contest.delete' and resource_id = '%d'" % contest_id), "1")


class CopySeveralTest(unittest.TestCase):
    """problems are copied into a domain several at once"""

    def test_several_problems_are_copied_at_once(self):
        admin = uoj.admin()
        teacher = p3.account("p9_copy_teacher")
        self.assertEqual(admin.change_user("p9_copy_teacher", "grant:teacher"), "")
        first, second, third = (admin.create_problem(ab_problem_files()) for _ in range(3))
        for problem_id, title in ((first, "p9 复制一"), (second, "p9 复制二"), (third, "p9 复制三")):
            db("update problems set title = '%s' where id = %d" % (title, problem_id))
        hidden = admin.new_problem(title="p9 看不到的题")
        slug, other = "p9-copy", "p9-copy-from"
        for name in (slug, other):
            if db_value("select count(*) from domains where slug = '%s'" % name) == "0":
                teacher.new_domain(name)
        here = "/d/%s/problems" % slug
        copies = lambda: db("select source_problem_id, domain_pid, title, is_hidden from problems where owner_domain_id = (select id from domains where slug = '%s') order by domain_pid" % slug)
        flash = lambda: uoj.text_of(re.search(r'(?s)<div class="alert alert-\w+[^"]*"[^>]*>(.*?)</div>', teacher.get(here).text).group(1))

        # ---- the form: one row, and a field that takes several problems
        page = teacher.get(here).text
        self.assertRegex(page, r'(?s)<form method="post" class="uoj-copy-row" id="form-copy-problem">.*?id="button-new-domain-problem".*?id="input-copy-problem-id"[^>]*data-multiple=""[^>]*/>\s*<button type="submit"')

        # ---- several at once, as the field sends them or as somebody types them; what can not
        # be copied is said, and does not keep the others from being copied
        self.assertEqual(teacher.form(here, "copy", problem_id="%d %d, %d 99999999 %d" % (first, second, hidden, first)), "")
        self.assertEqual(copies(), [[str(first), "1", "p9 复制一", "1"], [str(second), "2", "p9 复制二", "1"]])
        said = flash()
        for told in ("已复制 2 道题", "主站 #%d → 本域 #1" % first, "主站 #%d → 本域 #2" % second, "%d：题目不存在，或者你没有权限复制它" % hidden, "99999999：题目不存在"):
            self.assertIn(told, said)
        for copy_id in db("select id from problems where owner_domain_id = (select id from domains where slug = '%s')" % slug):
            self.assertEqual(uoj.wait_data_version(int(copy_id[0])), "")
        # one, as before
        self.assertEqual(teacher.form(here, "copy", problem_id=str(third)), "")
        self.assertEqual(len(copies()), 3)
        # ---- nothing that can be copied: nothing is copied, and the form says why
        for nothing in ("", "  ", "99999999", "%d 99999999" % hidden, "no-such-domain#1"):
            self.assertNotEqual(teacher.form(here, "copy", problem_id=nothing), "", nothing)
        self.assertEqual(len(copies()), 3)

        # ---- from another domain one teaches in, several as well
        there = [uoj.pid(teacher.new_problem(other, title="p9 那边的题 %d" % n)) for n in (1, 2)]
        for number in there:
            problem_id = int(db_value("select id from problems where owner_domain_id = (select id from domains where slug = '%s') and domain_pid = %d" % (other, number)))
            self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
            self.assertEqual(teacher.sync(problem_id), "")
        self.assertEqual(teacher.form(here, "copy", problem_id="%s#%d %s#%d" % (other, there[0], other, there[1])), "")
        self.assertEqual([row[2] for row in copies()][3:], ["p9 那边的题 1", "p9 那边的题 2"])


def import_files(client, path, files, **fields):
    """send files to the page that imports problems: (the ids of the problems it made, the page)"""
    fields["form"] = "import"
    r = client.post(path, fields, [("packages[]", (name, content if isinstance(content, bytes) else content.encode(), "application/octet-stream"))
                                   for name, content in files])  # fmt: skip
    assert r.status_code == 200, "HTTP %d: %s" % (r.status_code, uoj.text_of(r.text)[:300])
    return [int(n) for n in re.findall(r'data-imported="(\d+)"', r.text)], r.text


class ImportTest(unittest.TestCase):
    """problems come in whole: as a template that was filled in, or as a package laid out the
    way Hydro lays its problems out"""

    TEMPLATE = (
        "---\n# 说明可以留着\ntitle: p9 模板导入的题\ntime_limit: 2.5   # 秒\nmemory_limit: 128\ntags: [p9模板, 入门]\npublic: %s\n---\n\n"
        "## 题目描述\n\n求 $a_1 + a_2$，价格 \\$5。\n\n## 样例\n\n```input1\n1 2\n```\n\n```output1\n3\n```\n"
    )

    def test_template_that_was_filled_in_is_a_problem(self):
        admin, student = uoj.admin(), p3.account("p9_imp_student")
        # ---- the template is handed out where problems are imported, to who makes problems
        self.assertIn('id="button-import-problems" href="/problems/import"', admin.get("/problems").text)
        self.assertEqual(student.get("/problems/import").status_code, 403)
        r = admin.get("/problems/import?template=1")
        self.assertEqual(r.status_code, 200)
        self.assertIn("text/markdown", r.headers["Content-Type"])
        self.assertIn("attachment", r.headers["Content-Disposition"])
        template = r.content.decode()
        for part in ("---\n", "title:", "time_limit:", "memory_limit:", "## 题目描述", "```input1"):
            self.assertIn(part, template)

        # ---- the template as it is handed out, and one that was filled in and says it is public
        made, page = import_files(admin, "/problems/import", [("题目模板.md", template), ("我的题.md", self.TEMPLATE % "true"), ("没有开头.md", "## 题目描述\n\n只有题面。\n")])
        self.assertEqual(len(made), 3)
        rows = {int(row[0]): row[1:] for row in db("select id, title, is_hidden from problems where id in (%s)" % ", ".join(map(str, made)))}
        self.assertEqual([rows[i] for i in made], [["这里写题目名称", "1"], ["p9 模板导入的题", "0"], ["没有开头", "1"]])
        filled = made[1]
        shown, kept = (bytes.fromhex(c).decode() for c in db("select hex(statement), hex(statement_md) from problems_contents where id = %d" % filled)[0])
        self.assertTrue(kept.startswith("## 题目描述\n\n求 $a_1 + a_2$"), kept[:60])
        self.assertNotIn("title:", kept)
        self.assertIn("<p>求 $a_1 + a_2$，价格 \\$5。</p>", shown)
        self.assertIn('<pre><code class="language-input1">1 2</code></pre>', shown)
        self.assertEqual(sorted(row[0] for row in db("select tag from problems_tags where problem_id = %d" % filled)), ["p9模板", "入门"])
        conf = dict(line.split(None, 1) for line in uoj.docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/problem.conf" % filled).splitlines() if line.strip())
        self.assertEqual((conf["time_limit"], conf["memory_limit"], conf["n_tests"]), ("2.5", "128", "0"))
        # it says what it made, and what is still to do
        told = uoj.text_of(page)
        for said in ("p9 模板导入的题", "没有测试数据", "文件开头没有两条 --- 之间的那几行"):
            self.assertIn(said, told)
        # the box of the form decides for the ones that do not say
        made, page = import_files(admin, "/problems/import", [("a.md", "## 题目描述\n\n公开的。\n"), ("b.md", self.TEMPLATE % "false")], public="on")
        self.assertEqual([db_value("select is_hidden from problems where id = %d" % i) for i in made], ["0", "1"])
        # ---- what is no template and no package is refused, and says so
        before = db_value("select count(*) from problems")
        made, page = import_files(admin, "/problems/import", [("a.exe", b"MZ"), ("data.zip", uoj.make_zip({"1.in": "1 2\n", "1.out": "3\n"})), ("broken.zip", b"not a zip")])
        self.assertEqual(made, [])
        told = uoj.text_of(page)
        for said in ("只能导入填好的模板", "压缩包里没有找到题目", "不是一个完好的 zip 文件"):
            self.assertIn(said, told)
        self.assertEqual(db_value("select count(*) from problems"), before)
        self.assertEqual(student.post("/problems/import", {"form": "import"}).status_code, 403)

    def hydro_package(self):
        config = (
            "type: default\ntime: 2s\nmemory: 128m\nsubtasks:\n"
            "  - score: 40\n    cases:\n      - input: a1.in\n        output: a1.out\n      - input: a2.in\n        output: a2.out\n"
            "  - score: 60\n    type: min\n    cases:\n      - input: b1.in\n        output: b1.out\n"
        )
        picture = picture_bytes()
        return uoj.make_zip({
            "A/problem.yaml": "pid: P1001\nowner: 2\ntitle: p9 Hydro 来的题\ntag:\n  - p9包\n  - 图论\nnSubmit: 12\nnAccept: 3\n",
            "A/problem_zh.md": "## 题目描述\n\n看图：![图](file://pic.png)，求 $a+b$。\n\n下载 [工具](file://tool.py)。\n",
            "A/problem_en.md": "## Statement\n\nEnglish.\n",
            "A/testdata/config.yaml": config,
            "A/testdata/a1.in": "1 2\n", "A/testdata/a1.out": "3\n", "A/testdata/a2.in": "10 20\n", "A/testdata/a2.out": "30\n",
            "A/testdata/b1.in": "7 8\n", "A/testdata/b1.out": "15\n", "A/testdata/unused9.in": "0 0\n",
            "A/additional_file/pic.png": picture, "A/additional_file/tool.py": "print(1)\n",
            "B/problem.yaml": "title: p9 没有 config 的题\n",
            "B/problem.md": "---\ntime_limit: 3\n---\n\n## 题目描述\n\n按文件名找测试点。\n",
            "B/testdata/1.in": "1 1\n", "B/testdata/1.out": "2\n", "B/testdata/2.in": "2 2\n", "B/testdata/2.out": "4\n",
            "D/problem.yaml": "title: p9 带校验器的题\n",
            "D/problem_zh.md": "## 题目描述\n\n校验器是 chk.cc。\n",
            "D/testdata/config.yaml": "time: 1000ms\nmemory: 64mb\nchecker_type: testlib\nchecker: chk.cc\nfilename: sum\n",
            "D/testdata/chk.cc": AB_LENIENT_CHECKER, "D/testdata/1.in": "1 2\n", "D/testdata/1.ans": "3\n",
            "C/problem.yaml": "title: p9 本站格式的题\n",
            "C/problem_zh.md": "## 题目描述\n\n带着 problem.conf。\n",
            **{"C/testdata/" + name: content for name, content in ab_problem_files(time_limit=4).items()},
            "__MACOSX/A/._problem.yaml": "junk",
        })  # fmt: skip

    def test_package_of_hydro_is_its_problems_with_their_data(self):
        admin, solver = uoj.admin(), p3.account("p9_imp_solver")
        made, page = import_files(admin, "/problems/import", [("contest.zip", self.hydro_package())], public="on")
        self.assertEqual(len(made), 4, uoj.text_of(page)[-600:])
        a, b, c, d = made
        self.assertEqual([db_value("select title from problems where id = %d" % i) for i in made], ["p9 Hydro 来的题", "p9 没有 config 的题", "p9 本站格式的题", "p9 带校验器的题"])
        for problem_id in made:
            self.assertEqual(uoj.wait_data_version(problem_id), "", problem_id)

        # ---- A: what Hydro's config.yaml says, as the settings of this site
        conf = published_conf(a)
        self.assertEqual((conf["time_limit"], conf["memory_limit"], conf["n_tests"], conf["n_subtasks"]), ("2", "128", "3", "2"))
        self.assertEqual((conf["subtask_end_1"], conf["subtask_score_1"], conf["subtask_score_2"]), ("2", "40", "60"))
        self.assertEqual(sorted(row[0] for row in db("select tag from problems_tags where problem_id = %d" % a)), ["p9包", "图论"])
        # a file that looks like a test and is none of the tests the config names did not come along
        self.assertIn("unused9.in", uoj.text_of(page))
        # the files that come with it, and the places of the statement that speak of them
        files = dict(db("select name, id from attachments where owner_type = 'problem' and owner_id = %d" % a))
        self.assertEqual(sorted(files), ["pic.png", "tool.py"])
        shown, kept = (bytes.fromhex(col).decode() for col in db("select hex(statement), hex(statement_md) from problems_contents where id = %d" % a)[0])
        self.assertIn('<img src="/attachment/%s" alt="图"' % files["pic.png"], shown)
        self.assertIn('<a href="/attachment/%s">工具</a>' % files["tool.py"], shown)
        self.assertNotIn("file://", kept)
        self.assertNotIn("English", kept)
        picture = solver.get("/attachment/%s" % files["pic.png"])
        self.assertEqual((picture.status_code, picture.headers["Content-Type"]), (200, "image/png"))
        self.assertEqual(uoj.wait_submission(solver.submit(a, AB)).score, 100)
        self.assertLess(uoj.wait_submission(solver.submit(a, AB_WRONG)).score, 100)

        # ---- B: no config.yaml: the tests are found by their names, and the lines at the top of the statement count
        conf = published_conf(b)
        self.assertEqual((conf["n_tests"], conf["time_limit"]), ("2", "3"))
        self.assertNotIn("time_limit", bytes.fromhex(db_value("select hex(statement_md) from problems_contents where id = %d" % b)).decode())
        self.assertEqual(uoj.wait_submission(solver.submit(b, AB)).score, 100)
        # ---- C: a problem.conf of this site says everything
        conf = published_conf(c)
        self.assertEqual((conf["time_limit"], conf["use_builtin_checker"], conf["n_tests"]), ("4", "ncmp", "3"))
        self.assertEqual(uoj.wait_submission(solver.submit(c, AB)).score, 100)
        self.assertIn("数据里带有 problem.conf", uoj.text_of(page))
        # ---- D: a checker written with testlib, in a file called the way Hydro calls C++
        conf = published_conf(d)
        self.assertEqual((conf["chk_source"], conf["time_limit"], conf["memory_limit"], conf["n_tests"]), ("chk.cpp", "1", "64", "1"))
        self.assertNotIn("use_builtin_checker", conf)
        # the checker of the problem lets a sum that is one too large pass, which no builtin way of comparing does
        self.assertEqual(uoj.wait_submission(solver.submit(d, AB_WRONG)).score, 100)
        # what this site does not do is said: the problem reads a file on Hydro
        self.assertIn("文件输入输出（sum.in / .out）", uoj.text_of(page))

    def test_problems_are_imported_into_a_domain_by_who_teaches_there(self):
        admin = uoj.admin()
        teacher, member = p3.account("p9_imp_teacher"), p3.account("p9_imp_member")
        self.assertEqual(admin.change_user("p9_imp_teacher", "grant:teacher"), "")
        slug = "p9-import"
        if db_value("select count(*) from domains where slug = '%s'" % slug) == "0":
            teacher.new_domain(slug)
        self.assertEqual(teacher.form("/d/%s/members" % slug, "add", username="p9_imp_member", role="member"), "")
        here = "/d/%s/problems/import" % slug
        self.assertIn('href="%s"' % here, teacher.get("/d/%s/problems" % slug).text)
        self.assertEqual(member.get(here).status_code, 403)
        self.assertEqual(member.post(here, {"form": "import"}).status_code, 403)
        self.assertIn("title:", teacher.get(here + "?template=1").text)
        made, page = import_files(teacher, here, [("one.md", self.TEMPLATE % "true"), ("pack.zip", self.hydro_package())])
        self.assertEqual(len(made), 5)
        self.assertEqual(db("select domain_pid, title, is_hidden from problems where owner_domain_id = (select id from domains where slug = '%s') order by domain_pid" % slug),
                         [["1", "p9 模板导入的题", "0"], ["2", "p9 Hydro 来的题", "1"], ["3", "p9 没有 config 的题", "1"], ["4", "p9 本站格式的题", "1"],
                          ["5", "p9 带校验器的题", "1"]])  # fmt: skip
        self.assertIn('href="/d/%s/problem/2"' % slug, page)
        self.assertEqual(uoj.wait_data_version(made[1]), "")
        self.assertEqual(uoj.wait_submission(teacher.submit(made[1], AB, path="/d/%s/problem/2" % slug)).score, 100)


def picture_bytes(width=40, height=20):
    """a small picture that is a picture"""
    import struct
    import zlib

    rows = b"".join(b"\x00" + bytes((60, 110, 200)) * width for _ in range(height))
    chunk = lambda kind, data: struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(rows, 9)) + chunk(b"IEND", b"")
