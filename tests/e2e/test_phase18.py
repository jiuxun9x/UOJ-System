"""Phase 18: tests that are an input alone, where the checker of a problem is its own; and the
packages of Polygon, imported as they are."""

import unittest

import uoj
from fixtures import AB, AB_WRONG
from test_phase9 import import_files
from test_phase11 import pdf_bytes, pdf_statements
from uoj import db, db_value, docker_exec

# a checker that needs no answer: the sum is what the input says it is
SUM_CHECKER = r"""
#include "testlib.h"
int main(int argc, char *argv[]) {
    registerTestlibCmd(argc, argv);
    long long a = inf.readLong(), b = inf.readLong();
    long long found = ouf.readLong();
    if (found != a + b) {
        quitf(_wa, "expected %lld, found %lld", a + b, found);
    }
    quitf(_ok, "the sum");
}
"""

# right where both numbers are below a million: the first tests, not the last
AB_SMALL = AB.replace("a + b", "a < 1000000 ? a + b : 0")


def data_settings(client, problem_id, **fields):
    """send the form of the judging settings of a problem: the answer"""
    form = dict(form="judge_settings", type="traditional", time_limit="1", memory_limit="256", checker="wcmp", scoring="per_test")
    form.update(fields)
    return client.post("/problem/%d/manage/data" % problem_id, form)


def uploaded(problem_id, name):
    return docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/%s" % (problem_id, name))


class InputOnlyTest(unittest.TestCase):
    """A problem whose own checker needs nothing but the input to judge what a program wrote had
    to have an answer file for every test all the same: without one, a test was not found, or the
    data did not pass the check."""

    def test_a_checker_of_its_own_needs_no_answers(self):
        admin = uoj.admin()
        problem_id = admin.new_problem()
        files = {"data1.in": "1 2\n", "data2.in": "1000 2000\n", "data3.in": "999999999 1\n", "chk.cpp": SUM_CHECKER}
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        data_settings(admin, problem_id, checker="custom", checker_file="chk.cpp")
        # the tests are found, and handed an empty answer
        self.assertIn("n_tests 3", uploaded(problem_id, "problem.conf"))
        for number in (1, 2, 3):
            self.assertEqual(docker_exec(uoj.WEB, "stat -c %%s /var/uoj_data/upload/%d/data%d.out" % (problem_id, number)).strip(), "0")
        self.assertEqual(admin.sync(problem_id), "")
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)
        judgement = uoj.wait_submission(admin.submit(problem_id, AB_SMALL))
        self.assertEqual((judgement.score, judgement.infos), (66, ["Accepted", "Accepted", "Wrong Answer"]), judgement)

    def test_a_problem_conf_written_by_hand(self):
        admin = uoj.admin()
        conf = uoj.conf(use_builtin_judger="on", n_tests=2, input_pre="data", input_suf="in", output_pre="data", output_suf="out", time_limit=1, memory_limit=256)
        # its own checker: the answers that are not there are empty
        own = admin.new_problem()
        self.assertIn("上传成功", admin.upload_data(own, {"problem.conf": conf, "data1.in": "1 2\n", "data2.in": "3 4\n", "chk.cpp": SUM_CHECKER}).text)
        self.assertEqual(admin.sync(own), "")
        self.assertEqual(uoj.wait_submission(admin.submit(own, AB)).score, 100)
        self.assertEqual(uoj.wait_submission(admin.submit(own, AB_WRONG)).score, 0)
        # a way of comparing of the site compares with the answer, and wants one: it says so
        builtin = admin.new_problem()
        self.assertIn("上传成功", admin.upload_data(builtin, {"problem.conf": conf + "use_builtin_checker wcmp\n", "data1.in": "1 2\n", "data2.in": "3 4\n"}).text)
        said = admin.sync(builtin)
        self.assertIn("data1.out", said)
        self.assertIn("内置的比较方式要拿答案比较", said)


def polygon_xml(tests, groups=True, checker='<checker name="check.cpp" type="testlib"><source path="files/check.cpp" type="cpp.g++17"/></checker>'):
    """problem.xml of a package of Polygon with these tests: rows of (sample, group, points)"""
    lines = []
    for sample, group, points in tests:
        lines.append('<test method="manual"%s%s/>' % (' sample="true"' if sample else "", ' group="%s" points="%s"' % (group, points) if groups else ""))
    # a group is worth what its tests are
    worth = {}
    for _, group, points in tests:
        worth[group] = worth.get(group, 0) + points
    group_lines = "".join('<group feedback-policy="complete" name="%s" points="%s" points-policy="complete-group"/>' % (name, worth[name]) for name in sorted(worth))
    return """<?xml version="1.0" encoding="utf-8" standalone="no"?>
<problem revision="5" short-name="p18-sum">
  <names><name language="english" value="p18 Sum"/><name language="chinese" value="p18 Polygon 加法"/></names>
  <statements>
    <statement charset="UTF-8" language="chinese" mathjax="true" path="statements/chinese/problem.tex" type="application/x-tex"/>
    <statement language="chinese" path="statements/.pdf/chinese/problem.pdf" type="application/pdf"/>
  </statements>
  <judging cpu-name="x" cpu-speed="3600" input-file="" output-file="" run-count="1">
    <testset name="tests">
      <time-limit>2000</time-limit>
      <memory-limit>134217728</memory-limit>
      <test-count>%d</test-count>
      <input-path-pattern>tests/%%02d</input-path-pattern>
      <answer-path-pattern>tests/%%02d.a</answer-path-pattern>
      <tests>%s</tests>
      %s
    </testset>
  </judging>
  <files><resources><file path="files/testlib.h" type="h.g++"/></resources>
    <executables><executable><source path="files/gen.cpp" type="cpp.g++17"/></executable></executables></files>
  <assets>
    %s
    <solutions><solution tag="main"><source path="solutions/main.cpp" type="cpp.g++17"/></solution></solutions>
  </assets>
  <tags><tag value="p18-math"/></tags>
</problem>
""" % (len(tests), "".join(lines), "<groups>%s</groups>" % group_lines if groups else "", checker)


class PolygonImportTest(unittest.TestCase):
    """The import took the packages of Hydro and of this site: a problem made on Polygon had to be
    laid out again by hand. Its package is imported as it is now."""

    def test_a_package_of_polygon_is_a_problem(self):
        admin = uoj.admin()
        files = {
            "p18-sum/problem.xml": polygon_xml([(True, "0", 0), (False, "1", 20), (False, "1", 20), (False, "2", 60)]),
            "p18-sum/tests/01": "1 2\n", "p18-sum/tests/01.a": "3\n",
            "p18-sum/tests/02": "10 20\n", "p18-sum/tests/02.a": "30\n",
            "p18-sum/tests/03": "100 200\n", "p18-sum/tests/03.a": "300\n",
            "p18-sum/tests/04": "999999999 1\n", "p18-sum/tests/04.a": "1000000000\n",
            "p18-sum/files/check.cpp": SUM_CHECKER, "p18-sum/files/testlib.h": "// testlib\n", "p18-sum/files/gen.cpp": "// a generator\n",
            "p18-sum/solutions/main.cpp": AB,
            "p18-sum/statement-sections/chinese/legend.tex": "计算 $a+b$，其中\\textbf{两个}整数都不超过~$10^9$。",
            "p18-sum/statement-sections/chinese/input.tex": "一行两个整数 $a, b$。",
            "p18-sum/statement-sections/chinese/output.tex": "一个整数。",
            "p18-sum/statement-sections/chinese/notes.tex": "\\begin{itemize}\n\\item 注意 \\t{long long}。\n\\end{itemize}",
            "p18-sum/statement-sections/chinese/example.01": "1 2\n", "p18-sum/statement-sections/chinese/example.01.a": "3\n",
            "p18-sum/statements/.pdf/chinese/problem.pdf": pdf_bytes("p18 polygon"),
        }  # fmt: skip
        made, page = import_files(admin, "/problems/import", [("polygon.zip", uoj.make_zip(files))])
        self.assertEqual(len(made), 1, uoj.text_of(page)[-800:])
        problem_id = made[0]
        self.assertIn("这是 Polygon 的题目包", uoj.text_of(page))
        self.assertIn("没有导入：数据生成器", uoj.text_of(page))
        self.assertEqual(db_value("select title from problems where id = %d" % problem_id), "p18 Polygon 加法")
        self.assertEqual(db_value("select tag from problems_tags where problem_id = %d" % problem_id), "p18-math")
        # its limits, its tests, the sample among them, its groups as subtasks, its checker and its main solution
        conf = dict(line.split(" ", 1) for line in uploaded(problem_id, "problem.conf").strip().split("\n") if " " in line)
        for key, value in (("time_limit", "2"), ("memory_limit", "128"), ("n_tests", "4"), ("n_ex_tests", "1"), ("n_sample_tests", "1"), ("n_subtasks", "3"),
                           ("subtask_end_1", "1"), ("subtask_score_1", "0"), ("subtask_end_2", "3"), ("subtask_score_2", "40"), ("subtask_score_3", "60"),
                           ("chk_source", "check.cpp"), ("std_source", "main.cpp")):  # fmt: skip
            self.assertEqual(conf.get(key), value, (key, conf))
        # the statement: from LaTeX, and the PDF file
        statement = bytes.fromhex(db_value("select hex(statement_md) from problems_contents where id = %d" % problem_id)).decode()
        for part in ("## 题目描述\n\n计算 $a+b$，其中**两个**整数都不超过 $10^9$。", "## 输入格式", "```input1\n1 2\n```", "```output1\n3\n```", "## 提示\n\n- 注意 `long long`。"):
            self.assertIn(part, statement)
        self.assertEqual(sorted(pdf_statements(problem_id)), ["zh_cn"])
        # it is judged as on Polygon: by its groups
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB_SMALL)).score, 40)

    def test_a_package_without_its_tests_says_where_they_are(self):
        admin = uoj.admin()
        files = {
            "problem.xml": polygon_xml([(True, "0", 0), (False, "1", 100)], groups=False, checker='<checker name="std::ncmp.cpp" type="testlib"><source path="files/check.cpp" type="cpp.g++17"/></checker>'),
            "files/check.cpp": "// ncmp\n", "solutions/main.cpp": AB,
            "statements/chinese/problem.tex": "\\begin{problem}{p18 只有生成器}{standard input}{standard output}{1 second}{256 megabytes}\n求和。\n\\InputFile\n两个数。\n\\OutputFile\n和。\n\\Examples\n\\exmp{1 2\n}{3\n}%\n\\end{problem}\n",
        }  # fmt: skip
        made, page = import_files(admin, "/problems/import", [("bare.zip", uoj.make_zip(files))])
        self.assertEqual(len(made), 1, uoj.text_of(page)[-800:])
        text = uoj.text_of(page)
        self.assertIn("请在 Polygon 上下载 Standard 格式的包", text)
        self.assertIn("没有测试数据", text)
        # what problem.tex says is the statement; the checker is the standard one of the judgers
        statement = bytes.fromhex(db_value("select hex(statement_md) from problems_contents where id = %d" % made[0])).decode()
        self.assertIn("## 输入格式\n\n两个数。", statement)
        self.assertIn("```input1\n1 2\n```", statement)
        self.assertIn("use_builtin_checker ncmp", uploaded(made[0], "problem.conf"))


if __name__ == "__main__":
    unittest.main()
