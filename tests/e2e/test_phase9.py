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
        rows, mine, page = icpc_board(admin, contest_id)
        self.assertEqual(sorted(rows), ["p9_staff_ann", "p9_staff_bob", "p9_staff_cat"])
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
