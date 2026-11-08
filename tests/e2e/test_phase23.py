"""Phase 23: problems ticked in a list and made public, hidden or deleted at once; the box code is
written in; the outer board in the colours of the balloons; the resolver."""

import unittest

import uoj
from test_phase21 import frozen_contest
from test_phase3 import account
from test_phase4 import member_form
from uoj import db, db_value


def hidden(problem_id):
    return int(db_value("select is_hidden from problems where id = %d" % problem_id))


def batch(client, path, action, ids, **fields):
    """the form above a list of problems: '' or why it was refused"""
    return client.form(path, "batch", action=action, **{"problems[]": [str(i) for i in ids]}, **fields)


class BatchProblemsTest(unittest.TestCase):
    """A list of problems had no way of doing anything to several of them: each was opened, and
    made public, hidden or deleted on its own. Who manages them ticks them in the list."""

    def test_the_problems_of_the_site_are_ticked(self):
        admin = uoj.admin()
        ids = [admin.new_problem(title="p23 批量 %d" % n) for n in range(4)]
        page = admin.get("/problems").text
        self.assertIn('id="form-problems-batch"', page)
        for problem_id in ids:
            self.assertIn('name="problems[]" value="%d"' % problem_id, page)
        # who manages none of them has nothing to tick, and what they send does nothing
        pupil = account("p23_batch_pupil")
        self.assertNotIn('id="form-problems-batch"', pupil.get("/problems").text)
        self.assertNotEqual(batch(pupil, "/problems", "show", ids), "")
        self.assertEqual([hidden(i) for i in ids], [1, 1, 1, 1])

        # made public at once, and each of them written down
        self.assertEqual(batch(admin, "/problems", "show", ids[:3]), "")
        self.assertEqual([hidden(i) for i in ids], [0, 0, 0, 1])
        self.assertEqual(int(db_value("select count(*) from audit_logs where action = 'problem.set_hidden' and resource_id in ('%d', '%d', '%d')" % tuple(ids[:3]))), 3)
        self.assertIn("已公开 3 道题", admin.get("/problems").text)
        # and hidden again
        self.assertEqual(batch(admin, "/problems", "hide", ids[1:3]), "")
        self.assertEqual([hidden(i) for i in ids], [0, 1, 1, 1])

        # deleted only when the number of them is typed
        self.assertNotEqual(batch(admin, "/problems", "delete", ids[:2]), "")
        self.assertNotEqual(batch(admin, "/problems", "delete", ids[:2], confirm="3"), "")
        self.assertEqual(int(db_value("select count(*) from problems where id in (%d, %d)" % tuple(ids[:2]))), 2)
        # what is still used is not deleted, and the others are
        contest_id = admin.new_contest("p23 批量删除", rule="ICPC")
        db("insert into contests_problems (contest_id, problem_id, position) values (%d, %d, 1)" % (contest_id, ids[2]))
        self.assertEqual(batch(admin, "/problems", "delete", ids[:3], confirm="3"), "")
        self.assertEqual(int(db_value("select count(*) from problems where id in (%d, %d, %d)" % tuple(ids[:3]))), 1)
        self.assertIsNotNone(db_value("select id from problems where id = %d" % ids[2]))
        said = admin.get("/problems").text
        self.assertIn("已删除 2 道题", said)
        self.assertIn("没有删除的", said)
        self.assertEqual(int(db_value("select count(*) from audit_logs where action = 'problem.delete' and resource_id in ('%d', '%d')" % tuple(ids[:2]))), 2)
        db("delete from contests_problems where contest_id = %d" % contest_id)

    def test_the_problems_of_a_domain_are_ticked(self):
        admin = uoj.admin()
        teacher, pupil = account("p23_batch_teacher"), account("p23_batch_member")
        self.assertEqual(admin.change_user("p23_batch_teacher", "grant:teacher"), "")
        slug = "p23-batch"
        teacher.new_domain(slug)
        self.assertEqual(member_form(teacher, slug, "add", username="p23_batch_member", role="member"), "")
        ids = [teacher.new_problem(slug, title="p23 域题 %d" % n) for n in range(3)]
        path = "/d/%s/problems" % slug
        page = teacher.get(path).text
        self.assertIn('id="form-problems-batch"', page)
        for problem_id in ids:
            self.assertIn('name="problems[]" value="%d"' % problem_id, page)

        self.assertEqual(batch(teacher, path, "show", ids), "")
        self.assertEqual([hidden(i) for i in ids], [0, 0, 0])
        # a member sees them, and has nothing to tick
        page = pupil.get(path).text
        self.assertIn("p23 域题 0", page)
        self.assertNotIn('id="form-problems-batch"', page)
        self.assertNotEqual(batch(pupil, path, "hide", ids), "")
        self.assertEqual([hidden(i) for i in ids], [0, 0, 0])
        # the list of a domain touches its own problems, and no others
        site_problem = admin.new_problem(title="p23 主站题")
        self.assertNotEqual(batch(teacher, path, "show", [site_problem]), "")
        self.assertEqual(hidden(site_problem), 1)

        self.assertEqual(batch(teacher, path, "delete", ids[:2], confirm="2"), "")
        self.assertEqual([int(r[0]) for r in db("select id from problems where owner_domain_id = (select id from domains where slug = '%s')" % slug)], ids[2:])


class CodeBoxTest(unittest.TestCase):
    """The box code is written in grew with what was in it, with a long program as long as the
    program: it is as long as most of a screen at most, and scrolls inside."""

    def test_the_box_is_bounded(self):
        page = uoj.Client().get("/css/uoj-domain.css").text
        self.assertIn(".uoj-code-input", page)
        self.assertIn("max-height: 60vh", page)
        self.assertIn("uoj-code-input", uoj.Client().get("/js/uoj.js").text)


class ResolverPageTest(unittest.TestCase):
    """The resolver was the outer board with buttons, in the light: a table whose rows jumped, the
    buttons over it, the exports at their end. It is a black stage as the resolvers of the ICPC
    Tools and of Hydro have it, with its own stylesheet and script; outside the whole screen the
    buttons stand over it, the exports with them, and in the whole screen the stage is alone."""

    def test_the_stage_and_the_buttons_over_it(self):
        admin, pupil, contest_id = frozen_contest("p23 滚榜", "p23_rs_pupil")
        uoj.move_contest(contest_id, -400 * 60, 300)
        page = admin.get("/contest/%d/resolver" % contest_id).text
        for part in ("/css/uoj-resolver.css", "/js/uoj-resolver.js", 'id="resolver-bar"', 'id="resolver-stage"', 'id="table-resolver"',
                     'id="resolver-full"', 'id="resolver-award"'):  # fmt: skip
            self.assertIn(part, page)
        # the buttons, the exports among them, come before the stage
        bar, stage = page.index('id="resolver-bar"'), page.index('id="resolver-stage"')
        for button in ('id="resolver-export-clics"', 'id="resolver-export-xcpcio"', 'id="resolver-next"', 'id="resolver-back"', 'id="resolver-auto"'):
            self.assertTrue(bar < page.index(button) < stage, button)
        # it is black, always: no light and dark to choose
        self.assertNotIn('id="outer-board-theme"', page)
        self.assertNotIn("/css/uoj-board.css", page)
        # and it still says nothing it need not
        for sentence in ('id="resolver-note"', "按“下一步”", "只给工作人员下载", "罚时按秒计算"):
            self.assertNotIn(sentence, page, sentence)
        script = uoj.Client().get("/js/uoj-resolver.js").text
        for part in ("uoj-rs-uncover", "uoj-rs-moving", "awardDue", "requestFullscreen"):
            self.assertIn(part, script)


if __name__ == "__main__":
    unittest.main()
