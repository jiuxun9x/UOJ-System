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
    return json.loads(r.text)


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
