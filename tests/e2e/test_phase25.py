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
        # (nothing of scores that were settled: no corrections beside official ones)
        self.assertNotIn('id="my-correction-score"', mine)
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


class SiteProblemsTest(unittest.TestCase):
    """A homework, a training and a contest of a domain are made of the problems of the domain: a
    problem of the site had to be copied on the page of the problems first, and its copy looked
    for afterwards. It is named where the problems are picked, and copied then and there."""

    def test_a_problem_of_the_site_is_put_in_as_it_is(self):
        slug, teacher, (ann,), own = classroom("p25_site", ("ann",))
        did = int(db_value("select id from domains where slug = '%s'" % slug))
        public = uoj.admin().create_problem(ab_problem_files())
        copies = lambda: db("select id, domain_pid from problems where owner_domain_id = %d and source_problem_id = %d order by id" % (did, public))

        # ---- where problems are picked, the problems of the site stand behind those of the domain
        found = teacher.get("/problems/pick", params={"scope": slug + "+site", "q": ""}).json()["problems"]
        self.assertEqual([row.get("key") for row in found if "from" not in row], [None])
        offered = teacher.get("/problems/pick", params={"scope": slug + "+site", "q": "#%d" % public}).json()["problems"]
        self.assertIn({"key": "s%d" % public, "from": "主站", "number": public}, [{k: row.get(k) for k in ("key", "from", "number")} for row in offered])

        # ---- a homework: it is copied into the domain, and its copy is what the homework has
        homework_id = new_homework(teacher, slug, title="p25 主站题")
        manage = "/d/%s/homework/%d/manage" % (slug, homework_id)
        self.assertEqual(homework_form(teacher, slug, homework_id, "add_problem", problem_id="s%d %d" % (public, uoj.pid(own)), score="50"), "")
        self.assertEqual(len(copies()), 1)
        copy_id, copy_number = (int(x) for x in copies()[0])
        self.assertIn("主站 #%d 已复制为本域 #%d" % (public, copy_number), uoj.text_of(teacher.get(manage + "?tab=problems").text))
        self.assertEqual(db("select problem_id from homework_problems where homework_id = %d order by position" % homework_id), [[str(copy_id)], [str(own)]])
        self.assertEqual(uoj.wait_data_version(copy_id), "")
        # one the site does not have, or that is not to be seen, is refused
        self.assertIn("主站没有题号为 99999999 的题", homework_form(teacher, slug, homework_id, "add_problem", problem_id="s99999999", score="10"))

        # ---- a contest of the domain: the copy the domain has is used, and no other is made
        contest_id = teacher.new_contest("p25 域内赛", domain=slug, problems="s%d" % public)
        self.assertEqual(db("select problem_id from contests_problems where contest_id = %d" % contest_id), [[str(copy_id)]])
        self.assertEqual(len(copies()), 1)
        offered = teacher.get("/problems/pick", params={"scope": slug + "+site", "q": "#%d" % public}).json()["problems"]
        self.assertEqual([row.get("copy") for row in offered if row.get("key") == "s%d" % public], [copy_number])
        # who does not teach in the domain copies nothing into it
        other = uoj.admin().create_problem(ab_problem_files())
        ann.post("/d/%s/contest/new" % slug, {"form": "create", "name": "x", "problems": "s%d" % other})
        self.assertEqual(db_value("select count(*) from problems where owner_domain_id = %d and source_problem_id = %d" % (did, other)), "0")

    def test_a_training_that_is_published_shows_its_problems(self):
        slug, teacher, (ann,), own = classroom("p25_train", ("ann",))
        did = int(db_value("select id from domains where slug = '%s'" % slug))
        public = uoj.admin().create_problem(ab_problem_files())
        self.assertEqual(db_value("select is_hidden from problems where id = %d" % own), "1")
        self.assertEqual(teacher.form("/d/%s/training/new" % slug, "save", title="p25 训练", description_md="", status="draft"), "")
        training_id = int(db_value("select max(id) from trainings where domain_id = %d" % did))
        manage = "/d/%s/training/%d/manage" % (slug, training_id)
        self.assertEqual(teacher.form(manage, "add_problem", problem_id=str(uoj.pid(own))), "")
        # a draft shows nothing
        self.assertEqual(db_value("select is_hidden from problems where id = %d" % own), "1")
        self.assertEqual(ann.get("/d/%s/problem/%d" % (slug, uoj.pid(own))).status_code, 404)

        # published, its hidden problems are the domain's to see
        self.assertEqual(teacher.form(manage, "save", title="p25 训练", description_md="", status="published"), "")
        self.assertEqual(db_value("select is_hidden from problems where id = %d" % own), "0")
        self.assertIn("1 道题已对本域成员公开", uoj.text_of(teacher.get(manage).text))
        self.assertEqual(ann.get("/d/%s/problem/%d" % (slug, uoj.pid(own))).status_code, 200)
        # and so is what is added to it afterwards: a problem of the site, copied as it is added
        self.assertEqual(teacher.form(manage, "add_problem", problem_id="s%d" % public), "")
        copy = db("select id, domain_pid, is_hidden from problems where owner_domain_id = %d and source_problem_id = %d" % (did, public))
        self.assertEqual([row[2] for row in copy], ["0"])
        self.assertEqual(ann.get("/d/%s/problem/%s" % (slug, copy[0][1])).status_code, 200)

        # what a homework that runs holds hidden stays hidden, and the page says so
        self.assertEqual(teacher.new_problem_form(slug, title="p25 作业里的题"), "")
        held = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
        self.assertIn("上传成功", teacher.upload_data(held, ab_problem_files()).text)
        self.assertEqual(teacher.sync(held), "")
        published_homework(teacher, slug, held, title="p25 占着题的作业")
        self.assertEqual(teacher.form(manage, "add_problem", problem_id=str(uoj.pid(held))), "")
        self.assertEqual(db_value("select is_hidden from problems where id = %d" % held), "1")
        self.assertIn("暂时保持隐藏", uoj.text_of(teacher.get(manage).text))


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
