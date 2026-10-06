"""End-to-end tests of phase 8: a statement is kept as it was written, is saved by itself while
it is edited, problems are found by their tags, and what a contest or a homework hid is shown
when it is over.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import test_phase3 as p3
import uoj
from fixtures import *
from uoj import db, db_value


def setUpModule():
    uoj.admin()


def stored_statement(problem_id):
    """the statement of a problem as it is kept: (as it is shown, as it was written), or None"""
    rows = db("select hex(statement), hex(statement_md) from problems_contents where id = %d" % problem_id)
    if not rows:
        return None
    return tuple(bytes.fromhex(column).decode() for column in rows[0])


def save_statement(client, problem_id, statement_md, title="p8 statement", tags="", hidden=True, **more):
    """what the page that edits a statement sends when it saves: the answer, as the page reads it"""
    fields = {"problem_title": title, "problem_tags": tags, "problem_content_md": statement_md, "save-problem": ""}
    if hidden:
        fields["problem_is_hidden"] = "on"
    fields.update(more)
    r = client.post("/problem/%d/manage/statement" % problem_id, fields)
    assert r.status_code == 200, "HTTP %d: %s" % (r.status_code, uoj.text_of(r.text)[:300])
    # nothing to say is said as an empty list
    answer = json.loads(r.text)
    return answer if isinstance(answer, dict) else {}


class StatementIsKeptTest(unittest.TestCase):
    """what is written into a statement is what the statement says: nothing of it is dropped on
    the way to the page, and a problem does not come to be without it"""

    def test_text_around_formulas_is_all_there(self):
        admin = uoj.admin()
        written = (
            "## 题目描述\n\n"
            "$$\\sum_{i=1}^{n} a_i$$，其中 $a_i$ 是第 $i$ 个数，价格是 \\$5。\n\n"
            "若 $1\\le i<j\\le n$ 且 2*3 = $a*b$，路径是 C:\\\\dir。\n\n"
            "| 范围 | 分值 |\n|:-:|:-:|\n| $|a_i| \\le 10^9$ | 30 |\n\n"
            "$$\nx_1 + y_1\n$$ \n\n## 输入格式\n\n一行，这里的字都还在。\n"
        )
        problem_id = admin.new_problem(statement_md=written)
        shown, kept = stored_statement(problem_id)
        self.assertEqual(kept, written)
        for there in (
            "<p>$$\\sum_{i=1}^{n} a_i$$，其中 $a_i$ 是第 $i$ 个数，价格是 \\$5。</p>",
            "<p>若 $1\\le i&lt;j\\le n$ 且 2*3 = $a*b$，路径是 C:\\dir。</p>",
            "$|a_i| \\le 10^9$</td>",
            "<p>$$\nx_1 + y_1\n$$",
            "<h2>输入格式</h2>",
            "<p>一行，这里的字都还在。</p>",
        ):
            self.assertIn(there, shown)
        self.assertEqual(shown.count("<td"), 2)
        self.assertNotIn("UOJFORMULA", shown)

    def test_punctuation_stays_when_a_character_is_cleaned_away(self):
        # A character that a page may not have (they come along out of a PDF) is taken out of
        # the text. With it went every full-width comma, question mark and bracket of the text.
        admin = uoj.admin()
        for what, stray in (("a control character", "\x0c"), ("a character of the C1 block", "\u0085"), ("a noncharacter", "\uffff")):
            problem_id = admin.new_problem(statement_md="前文%s，后文？（完）！：；\n\n第二段，也在。" % stray)
            shown, kept = stored_statement(problem_id)
            self.assertEqual(shown, "<p>前文，后文？（完）！：；</p>\n<p>第二段，也在。</p>", what)

    def test_statement_does_not_end_at_a_closing_tag_too_many(self):
        # as it is copied out of the page of another judge, with one "</div>" more than "<div>"
        admin = uoj.admin()
        written = '<div class="statement"><p>第一段</p></div></div><div class="input"><p>输入一行</p></div>\n\n## 输出格式\n\n一行，还在。\n'
        problem_id = admin.new_problem(statement_md=written)
        shown, kept = stored_statement(problem_id)
        for there in ("<p>第一段</p>", "<p>输入一行</p>", "<h2>输出格式</h2>", "<p>一行，还在。</p>"):
            self.assertIn(there, shown)
        # and what a page must not have is still taken out
        problem_id = admin.new_problem(statement_md='正文<script>alert(1)</script> <a href="javascript:alert(1)" onclick="x()">链</a> <img src="/a.png" onerror="x()">')
        shown, kept = stored_statement(problem_id)
        for gone in ("script", "javascript", "onclick", "onerror", "alert"):
            self.assertNotIn(gone, shown)
        self.assertIn('<img src="/a.png"', shown)

    def test_problem_and_its_statement_are_made_together_and_a_lost_one_comes_back(self):
        admin = uoj.admin()
        # ---- a row that was left behind under the number the next problem gets is not what
        # the new problem says
        next_id = 1 + int(db_value("select max(id) from problems where owner_domain_id is null"))
        db("insert into problems_contents (id, statement, statement_md) values (%d, '<p>left behind</p>', 'left behind')" % next_id)
        problem_id = admin.new_problem(statement_md="新的题面，$n$ 个数。")
        self.assertEqual(problem_id, next_id)
        self.assertEqual(stored_statement(problem_id), ("<p>新的题面，$n$ 个数。</p>", "新的题面，$n$ 个数。"))

        # ---- a problem that has no row for its statement gets one when a statement is saved:
        # an update of the row that is not there changed nothing, and said that it was saved
        db("delete from problems_contents where id = %d" % problem_id)
        self.assertIsNone(stored_statement(problem_id))
        self.assertEqual(admin.get("/problem/%d/manage/statement" % problem_id).status_code, 200)
        self.assertEqual(admin.get("/problem/%d" % problem_id).status_code, 200)
        self.assertEqual(save_statement(admin, problem_id, "又写了一遍，$m$ 行。"), {})
        self.assertEqual(stored_statement(problem_id), ("<p>又写了一遍，$m$ 行。</p>", "又写了一遍，$m$ 行。"))
        self.assertIn("<p>又写了一遍，$m$ 行。</p>", admin.get("/problem/%d" % problem_id).text)


class PassedMarkTest(unittest.TestCase):
    """a submission that passed is seen to have passed without reading its score"""

    MARK = '<span class="glyphicon glyphicon-ok uoj-passed-mark"'

    def test_full_score_has_the_mark_and_nothing_else_has(self):
        admin = uoj.admin()
        solver = p3.account("p8_mark_solver")
        problem_id = admin.create_problem(ab_problem_files())
        right, wrong = solver.submit(problem_id, AB), solver.submit(problem_id, AB_WRONG)
        self.assertEqual(uoj.wait_submission(right).score, 100)
        self.assertLess(uoj.wait_submission(wrong).score, 100)
        for page in (solver.get("/submissions?problem_id=%d" % problem_id).text, uoj.Client().get("/submissions?problem_id=%d" % problem_id).text):
            self.assertRegex(page, r'href="/submission/%d" class="uoj-score">100 %s' % (right, re.escape(self.MARK)))
            self.assertRegex(page, r'href="/submission/%d" class="uoj-score">\d+</a>' % wrong)
            self.assertEqual(page.count(self.MARK), 1)
        # on the page of the submission itself as well
        self.assertEqual(solver.get("/submission/%d" % right).text.count(self.MARK), 1)
        self.assertNotIn(self.MARK, solver.get("/submission/%d" % wrong).text)


class TagSearchTest(unittest.TestCase):
    """a problem is found by a tag it has, where problems are looked for"""

    def test_problems_are_found_by_their_tags(self):
        admin, visitor = uoj.admin(), uoj.Client()
        paths = admin.new_problem(title="p8 回家的路", tags="图论, p8最短路径", public="on")
        flows = admin.new_problem(title="p8最短路练习", tags="p8网络流", public="on")
        other = admin.new_problem(title="p8 排队", tags="贪心", public="on")
        listed = lambda page: [int(n) for n in re.findall(r'<a href="/problem/(\d+)">', page)]

        # ---- the list of the problems of the site: the field in the navigation looks here
        page = visitor.get("/problems", params={"search": "p8最短路径"}).text
        self.assertEqual(listed(page), [paths])
        # the tags are shown, so that it is seen why a problem was found
        self.assertIn('<span class="badge badge-pill badge-secondary">p8最短路径</span>', page)
        self.assertIn('id="problem-search-note"', page)
        # a piece of a tag is enough, and titles and numbers are looked at as before
        self.assertEqual(listed(visitor.get("/problems", params={"search": "8最短路径"}).text), [paths])
        self.assertEqual(listed(visitor.get("/problems", params={"search": "p8最短路"}).text), [paths, flows])
        self.assertEqual(listed(visitor.get("/problems", params={"search": "p8 排队"}).text), [other])
        self.assertIn(other, listed(visitor.get("/problems", params={"search": str(other)}).text))
        self.assertEqual(listed(visitor.get("/problems", params={"search": "p8没有这个"}).text), [])
        # what is typed is looked for as it is, not as a pattern
        self.assertEqual(listed(visitor.get("/problems", params={"search": "p8%路径"}).text), [])
        self.assertEqual(listed(visitor.get("/problems", params={"search": "p8_短路径"}).text), [])
        # without a search the list is as it was
        self.assertNotIn('id="problem-search-note"', visitor.get("/problems").text)

        # ---- the field that picks problems: by title first, then by tag
        picked = admin.get("/problems/pick", params={"scope": "site", "q": "p8最短路"}).json()["problems"]
        self.assertEqual([(row["number"], row.get("tags")) for row in picked], [(flows, None), (paths, ["p8最短路径"])])
        picked = admin.get("/problems/pick", params={"scope": "site", "q": "p8网络"}).json()["problems"]
        self.assertEqual([(row["number"], row.get("tags")) for row in picked], [(flows, ["p8网络流"])])
        self.assertEqual(admin.get("/problems/pick", params={"scope": "site", "q": "p8%路径"}).json()["problems"], [])

        # ---- the problems of a domain
        teacher, student = p3.account("p8_tag_teacher"), p3.account("p8_tag_student")
        self.assertEqual(admin.change_user("p8_tag_teacher", "grant:teacher"), "")
        slug = "p8-tags"
        if db_value("select count(*) from domains where slug = '%s'" % slug) == "0":
            teacher.new_domain(slug)
        self.assertEqual(teacher.form("/d/%s/members" % slug, "add", username="p8_tag_student", role="member"), "")
        shown = uoj.pid(teacher.new_problem(slug, title="p8 域里的树", tags="p8树形DP, 图论", public="on"))
        hidden = uoj.pid(teacher.new_problem(slug, title="p8 域里的藏题", tags="p8树形DP"))
        uoj.pid(teacher.new_problem(slug, title="p8 域里的别的", tags="模拟", public="on"))
        here = "/d/%s/problems" % slug
        numbers = lambda page: [int(n) for n in re.findall(r'<a href="/d/%s/problem/(\d+)">' % slug, page)]
        page = teacher.get(here, params={"q": "p8树形"}).text
        self.assertEqual(numbers(page), [shown, hidden])
        self.assertIn('id="form-search-domain-problems"', page)
        self.assertRegex(page, r'class="badge badge-pill badge-light border uoj-domain-problem-tag" href="/d/%s/problems\?q=[^"]+">p8树形DP</a>' % slug)
        # somebody who does not teach there finds what they may see
        self.assertEqual(numbers(student.get(here, params={"q": "p8树形"}).text), [shown])
        self.assertEqual(numbers(student.get(here, params={"q": "域里的树"}).text), [shown])
        self.assertEqual(numbers(student.get(here, params={"q": str(shown)}).text), [shown])
        self.assertEqual(numbers(student.get(here, params={"q": "p8没有这个"}).text), [])
        self.assertIn("没有题号、标题或标签里有", student.get(here, params={"q": "p8没有这个"}).text)
        self.assertEqual(len(numbers(student.get(here).text)), 2)
        picked = teacher.get("/problems/pick", params={"scope": slug, "q": "p8树形"}).json()["problems"]
        self.assertEqual([(row["number"], row["tags"]) for row in picked], [(hidden, ["p8树形DP"]), (shown, ["p8树形DP"])])


def site_tick():
    """what the web server does every minute"""
    return uoj.docker_exec(uoj.WEB, "php /opt/uoj/web/app/cli.php site:tick")


def hidden(*problem_ids):
    """whether each of these problems is hidden"""
    return [db_value("select is_hidden from problems where id = %d" % problem_id) == "1" for problem_id in problem_ids]


class RevealProblemsTest(unittest.TestCase):
    """a contest or a homework that was told to shows its problems when it is over, by itself"""

    def test_contest_shows_its_problems_when_it_is_over(self):
        admin, visitor = uoj.admin(), uoj.Client()
        first, second, third, fourth = (admin.create_problem(ab_problem_files()) for _ in range(4))
        for problem_id in (first, second, third, fourth):
            db("update problems set is_hidden = 1 where id = %d" % problem_id)
        kept = admin.submit(first, AB)
        self.assertEqual(db_value("select is_hidden from submissions where id = %d" % kept), "1")

        # the form asks, and the answer is no unless somebody says yes
        self.assertRegex(admin.get("/contest/new").text, r'id="input-contest-reveal_problems" name="reveal_problems" />')
        told = admin.new_contest("p8 结束后公开", problems="%d, %d" % (first, second), reveal_problems="on")
        untold = admin.new_contest("p8 结束后不公开", problems=str(third))
        later = admin.new_contest("p8 以后的比赛", starts_in=86400, problems=str(second))
        frozen = admin.new_contest("p8 封榜的比赛", minutes=120, rule="ICPC", freeze_minutes="30", problems=str(fourth), reveal_problems="on")
        told_to = lambda contest_id: db("select reveal_problems, ifnull(problems_revealed_at, 'not yet') from contests where id = %d" % contest_id)[0]
        self.assertEqual([told_to(c)[0] for c in (told, untold, later, frozen)], ["1", "0", "0", "1"])
        self.assertRegex(admin.get("/contest/%d/manage" % told).text, r'name="reveal_problems" checked="checked"')
        self.assertRegex(admin.get("/contest/%d/manage" % untold).text, r'name="reveal_problems" />')

        # ---- while the contest runs nothing is shown
        uoj.move_contest(told, -600, 60)
        site_tick()
        admin.get("/contest/%d" % told)
        self.assertEqual(hidden(first, second, third, fourth), [True] * 4)

        # ---- it is over: what it hid is shown, to whoever looks at the contest first or to the
        # minute, but for the problem that a contest which is still to come needs hidden
        uoj.move_contest(told, -7200, 60)
        uoj.move_contest(untold, -7200, 60)
        self.assertEqual(visitor.get("/problem/%d" % first).status_code, 404)
        admin.get("/contest/%d" % told)
        self.assertEqual(hidden(first, second, third, fourth), [False, True, True, True])
        self.assertEqual(told_to(told), ["1", "not yet"])
        self.assertEqual(visitor.get("/problem/%d" % first).status_code, 200)
        self.assertIn('<a href="/problem/%d">' % first, visitor.get("/problems", params={"search": str(first)}).text)
        # what was submitted to it is shown with it
        self.assertEqual(db_value("select is_hidden from submissions where id = %d" % kept), "0")
        self.assertEqual(
            db("select actor_type, after_json from audit_logs where action = 'contest.reveal_problems' and resource_id = '%d'" % told),
            [["system", json.dumps({"problems": [first], "waiting": [second]}, separators=(",", ":"))]],
        )
        # the minute does nothing more to it while the other contest is not over
        site_tick()
        self.assertEqual(hidden(first, second, third), [False, True, True])

        # ---- the other contest is over: its problem was waiting to be shown
        uoj.move_contest(later, -7200, 60)
        site_tick()
        self.assertEqual(hidden(first, second, third), [False, False, True])
        self.assertNotEqual(told_to(told)[1], "not yet")

        # ---- shown once: somebody who hides a problem afterwards is not overruled
        db("update problems set is_hidden = 1 where id = %d" % first)
        site_tick()
        admin.get("/contest/%d" % told)
        self.assertEqual(hidden(first), [True])

        # ---- a contest that was not told keeps its problems hidden, until it is told
        self.assertEqual(hidden(third), [True])
        self.assertEqual(admin.contest_settings(untold, reveal_problems="on"), "")
        site_tick()
        self.assertEqual(hidden(third), [False])
        # saving the settings without the box takes the choice back
        self.assertEqual(admin.contest_settings(untold), "")
        self.assertEqual(told_to(untold)[0], "0")

        # ---- a contest whose board froze shows them when its results are published
        uoj.move_contest(frozen, -3 * 3600, 120)
        site_tick()
        admin.get("/contest/%d" % frozen)
        self.assertEqual(hidden(fourth), [True])
        self.assertEqual(admin.submit_form("/contest/%d" % frozen, "publish_result"), "")
        self.assertEqual(hidden(fourth), [False])
        self.assertNotEqual(told_to(frozen)[1], "not yet")

    def test_homework_shows_its_problems_when_it_is_over(self):
        import test_phase4 as p4

        admin = uoj.admin()
        teacher, student = p3.account("p8_reveal_teacher"), p3.account("p8_reveal_student")
        self.assertEqual(admin.change_user("p8_reveal_teacher", "grant:teacher"), "")
        slug = "p8-reveal"
        if db_value("select count(*) from domains where slug = '%s'" % slug) == "0":
            teacher.new_domain(slug)
        self.assertEqual(teacher.form("/d/%s/members" % slug, "add", username="p8_reveal_student", role="member"), "")
        problems = []
        for title in ("p8 作业题一", "p8 作业题二", "p8 作业题三"):
            problem_id = teacher.new_problem(slug, title=title)
            self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
            self.assertEqual(teacher.sync(problem_id), "")
            problems.append(problem_id)
        first, second, third = problems
        self.assertEqual(hidden(first, second, third), [True] * 3)

        def homework(title, of, **settings):
            homework_id = p4.new_homework(teacher, slug, title=title, **settings)
            for problem_id in of:
                self.assertEqual(p4.homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(uoj.pid(problem_id)), score="100"), "")
            self.assertEqual(p4.homework_form(teacher, slug, homework_id, "publish"), "")
            uoj.wait_until("homework #%d is published" % homework_id,
                           lambda: p4.tick() and p4.homework_row(homework_id, "status")[0] != "publishing", timeout=300)  # fmt: skip
            self.assertEqual(p4.homework_row(homework_id, "status"), ["published"])
            return homework_id

        end = lambda homework_id: db("update homeworks set begin_at = '%s', penalty_since = null, end_at = '%s' where id = %d"
                                     % (uoj.web_time(-20 * 86400), uoj.web_time(-3600), homework_id))  # fmt: skip
        # the form asks, and the answer is no unless somebody says yes
        self.assertRegex(teacher.get("/d/%s/homework/new" % slug).text, r'id="input-reveal_problems" name="reveal_problems" />')
        told = homework("p8 截止后公开", (first, second), reveal_problems="on")
        untold = homework("p8 截止后不公开", (third,))
        running = homework("p8 还在做的作业", (second,))
        self.assertEqual([p4.homework_row(h, "reveal_problems")[0] for h in (told, untold, running)], ["1", "0", "0"])
        # the next homework is offered what the last one chose; a copy of a homework chooses as it does
        self.assertRegex(teacher.get("/d/%s/homework/new" % slug).text, r'name="reveal_problems" />')
        self.assertRegex(teacher.get("/d/%s/homework/%d/manage" % (slug, told)).text, r'name="reveal_problems" checked="checked"')

        listed = lambda client: [int(n) for n in re.findall(r'<a href="/d/%s/problem/(\d+)">' % slug, client.get("/d/%s/problems" % slug).text)]
        # ---- while it runs nothing is shown
        p4.tick()
        self.assertEqual(hidden(first, second, third), [True] * 3)
        self.assertEqual(listed(student), [])

        # ---- it is over: its problems are shown to the members of the domain, but for the one
        # that a homework which is still running needs hidden
        end(told)
        end(untold)
        p4.tick()
        self.assertEqual(hidden(first, second, third), [False, True, True])
        self.assertEqual(listed(student), [uoj.pid(first)])
        self.assertEqual(student.get("/d/%s/problem/%d" % (slug, uoj.pid(first))).status_code, 200)
        self.assertEqual(p4.homework_row(told, "ifnull(problems_revealed_at, 'not yet')"), ["not yet"])
        self.assertEqual(
            db("select actor_type, after_json from audit_logs where action = 'homework.reveal_problems' and resource_id = '%d'" % told),
            [["system", json.dumps({"problems": [first], "waiting": [second]}, separators=(",", ":"))]],
        )
        # ---- the other homework is over as well
        end(running)
        teacher.get("/d/%s/homework/%d" % (slug, told))
        self.assertEqual(hidden(first, second, third), [False, False, True])
        self.assertIn('id="homework-problems-revealed"', teacher.get("/d/%s/homework/%d/manage" % (slug, told)).text)
        # ---- shown once, and a homework that was not told keeps its problems hidden
        db("update problems set is_hidden = 1 where id = %d" % first)
        p4.tick()
        site_tick()
        self.assertEqual(hidden(first, second, third), [True, False, True])


def statement_changes(problem_id):
    """what is written down of the changes of a statement: (who, what it was, what it became)"""
    return [
        (row[0], json.loads(row[1]), json.loads(row[2]))
        for row in db("select actor, before_json, after_json from audit_logs where action = 'problem.edit_statement'"
                      " and resource_id = '%d' order by id" % problem_id)  # fmt: skip
    ]


class StatementIsNotLostTest(unittest.TestCase):
    """a statement is not lost because a button was not pressed or a page was left: the page
    that edits it saves by itself, and the form that makes a problem keeps what is typed.
    What the pages do in a browser is looked at in screenshots.py; here is the server's side."""

    def test_form_that_makes_a_problem_does_not_throw_away_what_was_typed(self):
        admin = uoj.admin()
        page = admin.get("/problem/new").text
        self.assertIn("uoj_form_draft(\"new-problem-site\", ['title', 'statement_md', 'tags']", page)
        self.assertIn('id="draft-note"', page)
        written = "## 题目描述\n\n写了很久的题面，$n$ 个数 <不能丢>。"
        fields = {"form": "create", "title": "p8 写了很久", "statement_md": written, "tags": "p8草稿", "type": "traditional",
                  "time_limit": "1", "memory_limit": "256", "checker": "wcmp", "scoring": "per_test"}  # fmt: skip
        before = db_value("select count(*) from problems")

        # ---- the page was open while its user logged in anew: nothing is made, and the form is
        # there again with what was typed into it, to be sent again
        r = admin.post("/problem/new", fields, token=False)
        self.assertEqual(r.status_code, 200)
        self.assertEqual(db_value("select count(*) from problems"), before)
        self.assertIn("这次提交没有生效", r.text)
        self.assertIn('value="p8 写了很久"', r.text)
        self.assertIn("写了很久的题面，$n$ 个数 &lt;不能丢&gt;。</textarea>", r.text)
        self.assertIn('value="p8草稿"', r.text)
        # ---- so is a form with something wrong in it
        r = admin.post("/problem/new", dict(fields, time_limit="很快"))
        self.assertEqual(r.status_code, 200)
        self.assertEqual(db_value("select count(*) from problems"), before)
        self.assertIn("写了很久的题面，$n$ 个数 &lt;不能丢&gt;。</textarea>", r.text)

        # ---- the problem is made: the browser is told that it need not keep the form any more
        r = admin.post("/problem/new", fields)
        self.assertEqual(r.status_code, 302)
        self.assertRegex(r.headers.get("Set-Cookie", ""), r"uoj_draft_done=new-problem-site;.*path=/")
        problem_id = int(db_value("select max(id) from problems where owner_domain_id is null"))
        self.assertEqual(stored_statement(problem_id)[1], written)
        self.assertIn("<p>写了很久的题面，$n$ 个数 &lt;不能丢&gt;。</p>", stored_statement(problem_id)[0])
        # in a domain the form is kept under the name of the domain
        slug = "p8-draft"
        if db_value("select count(*) from domains where slug = '%s'" % slug) == "0":
            admin.new_domain(slug)
        self.assertIn('uoj_form_draft("new-problem-d-%s"' % slug, admin.get("/d/%s/problem/new" % slug).text)
        r = admin.post("/d/%s/problem/new" % slug, fields)
        self.assertEqual(r.status_code, 302)
        self.assertRegex(r.headers.get("Set-Cookie", ""), r"uoj_draft_done=new-problem-d-%s;.*path=/" % slug)
