"""Phase 24: the answers of tests that came without them, made by the standard program of the
problem, as Polygon makes them with its main solution."""

import unittest

import uoj
from fixtures import AB, AB_WRONG
from test_phase3 import account
from test_phase9 import import_files
from test_phase18 import polygon_xml, uploaded
from uoj import db, db_value

# a main solution that fails on the third test, whose first number is 100
CRASHES_ON_100 = r"""
#include <cstdio>
#include <cstdlib>
int main() {
    long long a, b;
    if (scanf("%lld %lld", &a, &b) != 2) return 1;
    if (a == 100) abort();
    printf("%lld\n", a + b);
    return 0;
}
"""

STATEMENT = (
    "\\begin{problem}{p24 加法}{standard input}{standard output}{1 second}{256 megabytes}\n求和。\n"
    "\\InputFile\n两个数。\n\\OutputFile\n和。\n\\Examples\n\\exmp{1 2\n}{3\n}%\n\\end{problem}\n"
)


def package(solution):
    """a package of Polygon with the inputs of its tests and no answers, as Polygon hands them out"""
    return {
        "problem.xml": polygon_xml([(True, "0", 0), (False, "1", 100), (False, "1", 100)], groups=False,
                                   checker='<checker name="std::ncmp.cpp" type="testlib"><source path="files/check.cpp" type="cpp.g++17"/></checker>'),
        "tests/01": "1 2\n", "tests/02": "10 20\n", "tests/03": "100 200\n",
        "files/check.cpp": "// ncmp\n", "solutions/main.cpp": solution,
        "statements/chinese/problem.tex": STATEMENT,
    }  # fmt: skip


def answers_of(problem_id):
    """the answer files of the tests of a problem, as its problem.conf names them: name => text"""
    conf = dict(line.split(" ", 1) for line in uploaded(problem_id, "problem.conf").strip().split("\n") if " " in line)
    pre, suf = conf.get("output_pre", "output"), conf.get("output_suf", "txt")
    names = ["%s%d.%s" % (pre, n, suf) for n in range(1, int(conf["n_tests"]) + 1)]
    names += ["ex_%s%d.%s" % (pre, n, suf) for n in range(1, int(conf.get("n_ex_tests", "0")) + 1)]
    return {name: uploaded(problem_id, name) for name in names}


def jobs(problem_id):
    return int(db_value("select count(*) from problem_answer_jobs where problem_id = %d" % problem_id))


def wait_job(problem_id):
    """wait until the last job of making answers of a problem is over: (its status, what it says)"""

    def over():
        row = db("select status, hex(ifnull(message, '')) from problem_answer_jobs where problem_id = %d order by id desc limit 1" % problem_id)
        return row[0] if row and row[0][0] in ("done", "failed") else None

    status, message = uoj.wait_until("the answers of problem #%d are made" % problem_id, over, timeout=300)
    return status, bytes.fromhex(message).decode(errors="replace")


def make_answers(client, problem_id, **fields):
    """the button of the data page: what the page says after it"""
    page = "/problem/%d/manage/data" % problem_id
    assert client.form(page, "make_answers", **fields) == ""
    return uoj.text_of(client.get(page).text)


class PolygonAnswersTest(unittest.TestCase):
    """A package of Polygon has the inputs of its tests and its main solution: Polygon makes the
    answers with it, and the package has none. Imported, every answer was empty, and the problem
    could not be judged. A judger makes them with the main solution now, and the data is synced
    with them."""

    def test_the_answers_of_a_package_of_polygon_are_made(self):
        admin = uoj.admin()
        made, page = import_files(admin, "/problems/import", [("p24.zip", uoj.make_zip(package(AB)))])
        self.assertEqual(len(made), 1, uoj.text_of(page)[-800:])
        problem_id = made[0]
        self.assertIn("已交给评测机用标准程序 main.cpp 生成", uoj.text_of(page))
        self.assertEqual(wait_job(problem_id), ("done", "生成了 4 个答案"))
        # the answers of the tests, and of the sample among them
        self.assertEqual(sorted(answers_of(problem_id).values()), ["3\n", "3\n", "30\n", "300\n"])
        # and the data is synced with them, by itself
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(db_value("select reason from problem_data_versions where problem_id = %d order by version desc limit 1" % problem_id), "answers")
        self.assertEqual(int(db_value("select count(*) from audit_logs where action = 'problem.make_answers' and resource_id = '%d'" % problem_id)), 1)
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB_WRONG)).score, 0)
        # the data page says what was done; every test has its answer now
        page = admin.get("/problem/%d/manage/data" % problem_id).text
        self.assertIn('id="data-answers" data-status="done"', page)
        self.assertIn("每个测试点都有答案", uoj.text_of(page))

    def test_a_program_that_fails_writes_nothing(self):
        admin = uoj.admin()
        made, page = import_files(admin, "/problems/import", [("p24-crash.zip", uoj.make_zip(package(CRASHES_ON_100)))])
        problem_id = made[0]
        status, message = wait_job(problem_id)
        self.assertEqual(status, "failed")
        # which test, and how; and not one answer
        self.assertIn("标准程序在 1 个测试点上出错，一个答案也没有写入", message)
        self.assertIn("3.in：运行错误", message)
        self.assertEqual(set(answers_of(problem_id).values()), {""})
        self.assertIn("一个答案也没有写入", uoj.text_of(admin.get("/problem/%d/manage/data" % problem_id).text))
        self.assertIsNone(db_value("select id from problem_data_versions where problem_id = %d and reason = 'answers'" % problem_id))

        # the program put right, the button makes them
        self.assertIn("上传成功", admin.upload_data(problem_id, {"main.cpp": AB}).text)
        said = make_answers(admin, problem_id)
        self.assertIn("已交给评测机用标准程序生成缺少的答案", said)
        self.assertEqual(wait_job(problem_id)[0], "done")
        self.assertEqual(sorted(answers_of(problem_id).values()), ["3\n", "3\n", "30\n", "300\n"])
        self.assertEqual(uoj.wait_data_version(problem_id), "")

        # nothing is missing: only all of them are made again, over what is there
        before = jobs(problem_id)
        self.assertIn("没有生成答案：每个测试点都有答案了", make_answers(admin, problem_id))
        self.assertEqual(jobs(problem_id), before)
        wrong = [name for name, text in answers_of(problem_id).items() if text == "30\n"][0]
        self.assertIn("上传成功", admin.upload_data(problem_id, {wrong: "999\n"}).text)
        self.assertIn("重新生成全部答案", make_answers(admin, problem_id, all="1"))
        self.assertEqual(wait_job(problem_id), ("done", "生成了 4 个答案"))
        self.assertEqual(uploaded(problem_id, wrong), "30\n")

    def test_only_an_ordinary_problem_and_only_its_managers(self):
        admin, stranger = uoj.admin(), account("p24_answers_stranger")
        problem_id = admin.new_problem()
        conf = uoj.conf(use_builtin_judger="on", n_tests=1, input_pre="data", input_suf="in", output_pre="data", output_suf="out",
                        time_limit=1, memory_limit=256, interaction_mode="on", std_source="std.cpp")  # fmt: skip
        self.assertIn("上传成功", admin.upload_data(problem_id, {"problem.conf": conf, "data1.in": "1 2\n", "std.cpp": AB, "interactor.cpp": "// i\n"}).text)
        self.assertIn("只有普通的题", make_answers(admin, problem_id))
        self.assertEqual(jobs(problem_id), 0)
        # without a standard program there is nothing to make them with
        plain = admin.new_problem()
        conf = uoj.conf(use_builtin_judger="on", n_tests=1, input_pre="data", input_suf="in", output_pre="data", output_suf="out", time_limit=1, memory_limit=256)
        self.assertIn("上传成功", admin.upload_data(plain, {"problem.conf": conf, "data1.in": "1 2\n"}).text)
        self.assertIn("没有标准程序", make_answers(admin, plain))
        # and who does not manage a problem has no button to press
        r = stranger.post("/problem/%d/manage/data" % plain, {"form": "make_answers"})
        self.assertIn(r.status_code, (403, 404))
        self.assertEqual(jobs(plain), 0)


if __name__ == "__main__":
    unittest.main()
