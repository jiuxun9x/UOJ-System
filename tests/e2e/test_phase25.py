"""Phase 25: what a look through the domains found, and the domains made simpler to use: problems
of the site are put into a homework, a training or a contest of a domain as they are, a homework
is set in one go, and the pages of a domain are pages of the site."""

import unittest

import uoj
from fixtures import AB, AB_WRONG, ab_problem_files
from test_phase3 import account
from test_phase4 import homework_form, homework_row, homework_settings, member_form, new_homework, role_in, scores_of, tick
from uoj import db, db_value


def classroom(prefix, pupils=()):
    """a domain with a teacher, pupils and a problem of its own that can be judged:
    (slug, teacher, the pupils, the id of the problem)"""
    admin = uoj.admin()
    teacher = account(prefix + "_teacher")
    assert admin.change_user(prefix + "_teacher", "grant:teacher") == ""
    slug = prefix.replace("_", "-")
    teacher.new_domain(slug)
    clients = [account("%s_%s" % (prefix, name)) for name in pupils]
    for client in clients:
        assert member_form(teacher, slug, "add", username=client.username, role="member") == ""
    assert teacher.new_problem_form(slug) == ""
    problem_id = int(db_value("select max(id) from problems where owner_domain_id = (select id from domains where slug = '%s')" % slug))
    assert "上传成功" in teacher.upload_data(problem_id, ab_problem_files()).text
    assert teacher.sync(problem_id) == ""
    return slug, teacher, clients, problem_id


def published_homework(teacher, slug, problem_id, **settings):
    """a homework of one problem worth 100 that is published: its id"""
    homework_id = new_homework(teacher, slug, **settings)
    assert homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(uoj.pid(problem_id)), score="100") == ""
    assert homework_form(teacher, slug, homework_id, "publish") == ""
    uoj.wait_until("the homework is published", lambda: tick() and homework_row(homework_id, "status")[0] == "published", timeout=300)
    return homework_id


class HomeworkBoardTest(unittest.TestCase):
    """What a look through the homework of the domains found of its scores: a homework whose end
    was moved on showed the scores it was settled with as official ones while it ran again; who
    had as much as another stood a place below them; and what a pupil called themselves was
    written into the file of the scores as a formula."""

    def test_the_scores_of_a_homework(self):
        slug, teacher, (ann, bob, cat), problem_id = classroom("p25_board", ("ann", "bob", "cat"))
        # no penalty for being late: what is submitted counts in full
        homework_id = published_homework(teacher, slug, problem_id, title="p25 成绩", allow_late=None, penalty_since="")
        page = "/d/%s/homework/%d" % (slug, homework_id)
        here = "%s/problem/%d" % (page, uoj.pid(problem_id))
        for client in (ann, bob, cat):
            self.assertEqual(client.form(page, "claim"), "")
        uoj.wait_submission(ann.submit(problem_id, AB_WRONG, path=here))
        uoj.wait_submission(bob.submit(problem_id, AB, path=here))

        # ---- it ends, and is settled: ann has nothing
        db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-60), homework_id))
        uoj.wait_until("the homework is settled", lambda: tick() and homework_row(homework_id, "settle_state")[0] == "settled")
        self.assertRegex(ann.get(page).text, r'正式成绩</div>\s*<div[^>]*id="my-official-score">0 ')

        # ---- its end is moved on: it runs again, and what counts is what is submitted now
        self.assertEqual(homework_form(teacher, slug, homework_id, "save", **homework_settings(title="p25 成绩", allow_late=None, penalty_since="", end_at=uoj.web_time(86400))), "")
        self.assertEqual(homework_row(homework_id, "settle_state")[0], "open")
        uoj.wait_submission(ann.submit(problem_id, AB, path=here))
        mine = ann.get(page).text
        self.assertRegex(mine, r'当前得分</div>\s*<div[^>]*id="my-official-score">100 ')
        self.assertNotIn("正式成绩", uoj.text_of(mine))
        scores = scores_of(teacher, slug, homework_id)
        self.assertEqual({name: row["Total"] for name, row in scores.items()}, {"p25_board_ann": "100", "p25_board_bob": "100", "p25_board_cat": "0"})
        self.assertIn("按当前提交实时计算", teacher.get(page + "/scoreboard").text)

        # ---- who has as much as another has the same place, on the page and in the file
        self.assertEqual({name: row["Rank"] for name, row in scores.items()}, {"p25_board_ann": "1", "p25_board_bob": "1", "p25_board_cat": "3"})
        board = teacher.get(page + "/scoreboard").text
        for name, place in (("p25_board_ann", 1), ("p25_board_bob", 1), ("p25_board_cat", 3)):
            self.assertIn('data-username="%s" data-rank="%d"' % (name, place), board)

        # ---- a name that begins like a formula is text in the file
        db("update user_info set nickname = '=1+1' where username = 'p25_board_cat'")
        self.assertEqual(scores_of(teacher, slug, homework_id)["p25_board_cat"]["Nickname"], "'=1+1")

        # ---- and at its new end it is settled anew, with what was submitted since
        db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-30), homework_id))
        uoj.wait_until("the homework is settled again", lambda: tick() and homework_row(homework_id, "settle_state")[0] == "settled")
        self.assertRegex(ann.get(page).text, r'正式成绩</div>\s*<div[^>]*id="my-official-score">100 ')
        self.assertEqual(db_value("select count(*) from homework_snapshots where homework_id = %d and status = 'official'" % homework_id), "2")

    def test_a_homework_that_is_over_already_is_not_published(self):
        slug, teacher, _, problem_id = classroom("p25_late")
        homework_id = new_homework(teacher, slug, title="p25 过期")
        self.assertEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(uoj.pid(problem_id)), score="100"), "")
        # (as a copy of a homework of last term is)
        db("update homeworks set begin_at = '%s', penalty_since = null, end_at = '%s' where id = %d" % (uoj.web_time(-9 * 86400), uoj.web_time(-86400), homework_id))
        said = homework_form(teacher, slug, homework_id, "publish")
        self.assertIn("截止时间已经过了", said)
        self.assertEqual(homework_row(homework_id, "status")[0], "draft")


class MemberFormTest(unittest.TestCase):
    """A form of the members that sent no text for a role (role[]=...) was no page at all, but an
    error of the server."""

    def test_a_role_is_a_text(self):
        slug, teacher, _, _ = classroom("p25_door")
        other = account("p25_door_other")
        r = teacher.post("/d/%s/members" % slug, {"form": "add", "username": "p25_door_other", "role[]": "member"})
        self.assertEqual(r.status_code, 200)
        self.assertIn("无效的角色", r.text)
        self.assertIsNone(role_in(slug, other.username))
        # and what is no number names no invitation and nobody who waits
        for form in ("unpend", "revoke"):
            self.assertEqual(teacher.post("/d/%s/members" % slug, {"form": form, "id[]": "1"}).status_code, 302)


if __name__ == "__main__":
    unittest.main()
