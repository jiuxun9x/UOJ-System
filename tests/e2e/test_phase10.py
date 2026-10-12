"""End-to-end tests of phase 10: problems are imported by the problem.conf of this site, which
may carry comments, and what was put right on the way.

See test_phase1.py for how to start the containers.
"""

import io
import re
import unittest
import zipfile

import test_phase3 as p3
import test_phase4 as p4
import test_phase6 as p6
import test_phase8 as p8
import test_phase9 as p9
import uoj
from fixtures import *
from uoj import db, db_value


def setUpModule():
    uoj.admin()


def uploaded_conf_text(problem_id):
    """the problem.conf that lies with the uploaded data of a problem, as it is written"""
    return uoj.docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/problem.conf" % problem_id)


# gives two numbers that add up to n, and not the two the answers of the package give
SPLIT = r"""
#include <cstdio>
int main() {
    long long n;
    scanf("%lld", &n);
    printf("%lld %lld\n", n - 1, 1LL);
}
"""
SPLIT_WRONG = SPLIT.replace("n - 1, 1LL", "n, 1LL")
# right while n is small, which is the first subtask
SPLIT_SMALL = SPLIT.replace("n - 1, 1LL", "n <= 100 ? n - 1 : n, 1LL")


class ConfImportTest(unittest.TestCase):
    """a package of problems says how they are judged in the problem.conf of this site; the one
    that is handed out as an example is imported as it is"""

    def test_package_that_is_handed_out_is_imported_as_it_is(self):
        admin, student = uoj.admin(), p3.account("p10_imp_student")
        # ---- what is handed out: the package, and the problem.conf alone
        page = admin.get("/problems/import").text
        for link in ('id="link-import-package-template" href="/problems/import?template=zip"', 'id="link-import-conf-template" href="/problems/import?template=conf"',
                     'id="link-import-template" href="/problems/import?template=1"'):  # fmt: skip
            self.assertIn(link, page)
        self.assertIn("config.yaml", uoj.text_of(page))
        self.assertEqual(student.get("/problems/import?template=zip").status_code, 403)
        r = admin.get("/problems/import?template=conf")
        self.assertEqual(r.status_code, 200)
        self.assertIn("attachment", r.headers["Content-Disposition"])
        conf_template = r.content.decode()
        for part in ("use_builtin_judger on", "\ntime_limit 1", "\nuse_builtin_checker wcmp", "\n# n_tests 10", "\n# chk_source chk.cpp", "\n# n_subtasks 3"):
            self.assertIn(part, conf_template)
        r = admin.get("/problems/import?template=zip")
        self.assertEqual((r.status_code, r.headers["Content-Type"]), (200, "application/zip"))
        self.assertIn("attachment", r.headers["Content-Disposition"])
        package = zipfile.ZipFile(io.BytesIO(r.content))
        names = package.namelist()
        for name in ("README.md", "a-plus-b/problem.md", "a-plus-b/problem.conf", "a-plus-b/data/1.in", "with-checker/problem.conf", "with-checker/data/chk.cpp",
                     "with-checker/additional_file/figure.png"):  # fmt: skip
            self.assertIn(name, names)
        self.assertEqual(package.read("a-plus-b/problem.conf").decode(), conf_template)
        self.assertIn("problem.conf", package.read("README.md").decode())

        # ---- it is imported as it is handed out: two problems, hidden as their statements say
        made, page = p9.import_files(admin, "/problems/import", [("题目包模板.zip", r.content)], public="on")
        self.assertEqual(len(made), 2, uoj.text_of(page)[-600:])
        ab, split = made
        self.assertEqual(db("select title, is_hidden from problems where id in (%d, %d) order by id" % (ab, split)), [["A + B 问题", "1"], ["拆成两个数", "1"]])
        told = uoj.text_of(page)
        self.assertIn("它没有写测试点个数，测试点是按文件名找的", told)
        self.assertNotIn("没有采用", told)
        for problem_id in made:
            self.assertEqual(uoj.wait_data_version(problem_id), "", problem_id)

        # ---- the first: the problem.conf that is handed out, without its comments and with its tests
        written = uploaded_conf_text(ab)
        self.assertNotIn("#", written)
        conf = published_conf(ab)
        self.assertEqual((conf["time_limit"], conf["memory_limit"], conf["use_builtin_checker"], conf["n_tests"], conf["n_ex_tests"], conf["n_sample_tests"]),
                         ("1", "256", "wcmp", "3", "1", "1"))  # fmt: skip
        self.assertNotIn("n_subtasks", conf)
        self.assertEqual(sorted(row[0] for row in db("select tag from problems_tags where problem_id = %d" % ab)), ["入门"])
        self.assertEqual(uoj.wait_submission(admin.submit(ab, AB)).score, 100)
        self.assertLess(uoj.wait_submission(admin.submit(ab, AB_WRONG)).score, 100)

        # ---- the second: a checker of its own and two subtasks, the last of which ends where
        # the tests end, which the package does not say
        conf = published_conf(split)
        self.assertEqual((conf["chk_source"], conf["n_tests"], conf["n_subtasks"], conf["subtask_end_1"], conf["subtask_score_1"], conf["subtask_end_2"], conf["subtask_score_2"]),
                         ("chk.cpp", "4", "2", "2", "40", "4", "60"))  # fmt: skip
        self.assertNotIn("use_builtin_checker", conf)
        self.assertNotIn("#", uploaded_conf_text(split))
        # the checker decides: two numbers that are not the ones in the answer files pass
        self.assertEqual(uoj.wait_submission(admin.submit(split, SPLIT)).score, 100)
        self.assertEqual(uoj.wait_submission(admin.submit(split, SPLIT_WRONG)).score, 0)
        self.assertEqual(uoj.wait_submission(admin.submit(split, SPLIT_SMALL)).score, 40)
        # its picture came along, and the statement shows it
        files = dict(db("select name, id from attachments where owner_type = 'problem' and owner_id = %d" % split))
        self.assertEqual(sorted(files), ["figure.png"])
        shown = bytes.fromhex(db_value("select hex(statement) from problems_contents where id = %d" % split)).decode()
        self.assertIn('<img src="/attachment/%s" alt="示意图"' % files["figure.png"], shown)
        self.assertEqual(admin.get("/attachment/%s" % files["figure.png"]).headers["Content-Type"], "image/png")

    def package(self):
        commented = "# 这是一道本站格式的题\nuse_builtin_judger on\t# 内置评测\n" + "".join(
            "%s  # %s\n" % (line, "说明") for line in ab_problem_files(time_limit=3)["problem.conf"].splitlines() if not line.startswith("use_builtin_judger")
        )
        whole = {"whole/data/" + name: content for name, content in ab_problem_files().items()}
        whole["whole/data/problem.conf"] = commented
        return uoj.make_zip({
            "README.md": "# 说明\n\n这个文件不是题目。\n",
            # says how many tests it has: taken at its word, with the names its files have
            "whole/problem.md": "---\ntitle: p10 写全了的题\n---\n\n## 题目描述\n\n求和。\n",
            **whole,
            # says what it wants and not which its tests are; a checker in a file Hydro would call so,
            # and settings the form has no word for
            "part/problem.md": "---\ntitle: p10 只写了设置的题\ntime_limit: 9\nmemory_limit: 64\n---\n\n## 题目描述\n\n求和，多 1 也算对。\n",
            "part/problem.conf": "use_builtin_judger on\n# time_limit 5\nchk_source my_chk.cc   # 校验器\nn_subtasks 1\ntime_limit_2 3\noutput_limit 32\n",
            "part/data/my_chk.cc": AB_LENIENT_CHECKER,
            "part/data/a.in": "1 2\n", "part/data/a.ans": "3\n", "part/data/b.in": "10 20\n", "part/data/b.ans": "30\n",
            "part/data/sample1.in": "5 7\n", "part/data/sample1.ans": "12\n",
            "part/data/require/helper.h": "// compiled with the programs\n", "part/data/download/tool.py": "print(1)\n",
            # a problem.conf that is wrong: the problem is made, and it is said what was not taken
            "wrong/problem.md": "---\ntitle: p10 写错了的题\n---\n\n## 题目描述\n\n求和。\n",
            "wrong/problem.conf": "use_builtin_judger on\ntime_limit 2\ntime limit 3\n",
            "wrong/data/1.in": "1 2\n", "wrong/data/1.out": "3\n",
            # both: the problem.conf decides
            "both/problem.md": "---\ntitle: p10 两种都带的题\n---\n\n## 题目描述\n\n求和。\n",
            "both/testdata/problem.conf": "use_builtin_judger on\ntime_limit 4\nmemory_limit 128\nuse_builtin_checker ncmp\n",
            "both/testdata/config.yaml": "time: 9s\nmemory: 16m\n",
            "both/testdata/1.in": "1 2\n", "both/testdata/1.out": "3\n",
        })  # fmt: skip

    def test_problem_conf_of_a_package_decides_how_its_problem_is_judged(self):
        admin = uoj.admin()
        made, page = p9.import_files(admin, "/problems/import", [("pack.zip", self.package())])
        self.assertEqual(len(made), 4, uoj.text_of(page)[-800:])
        self.assertEqual([db_value("select title from problems where id = %d" % i) for i in made], ["p10 两种都带的题", "p10 只写了设置的题", "p10 写全了的题", "p10 写错了的题"])
        both, part, whole, wrong = made
        told = uoj.text_of(page)
        for problem_id in made:
            self.assertEqual(uoj.wait_data_version(problem_id), "", problem_id)
            self.assertNotIn("#", uploaded_conf_text(problem_id))

        # ---- whole: as it says, with the names its files have
        conf = published_conf(whole)
        self.assertEqual((conf["time_limit"], conf["n_tests"], conf["input_pre"], conf["input_suf"], conf["use_builtin_checker"]), ("3", "3", "input", "txt", "ncmp"))
        self.assertIn("它写明了测试点，文件名没有改动", told)
        names = uoj.docker_exec(uoj.WEB, "ls /var/uoj_data/upload/%d" % whole).split()
        self.assertIn("input1.txt", names)
        self.assertNotIn("data1.in", names)
        self.assertEqual(uoj.wait_submission(admin.submit(whole, AB)).score, 100)

        # ---- part: what it says, what the statement says where it says nothing, the tests by
        # their names, and its other lines as they are
        conf = published_conf(part)
        self.assertEqual((conf["time_limit"], conf["memory_limit"], conf["n_tests"], conf["n_ex_tests"], conf["n_subtasks"], conf["chk_source"], conf["time_limit_2"], conf["output_limit"]),
                         ("9", "64", "2", "1", "1", "my_chk.cpp", "3", "32"))  # fmt: skip
        self.assertNotIn("use_builtin_checker", conf)
        names = uoj.docker_exec(uoj.WEB, "cd /var/uoj_data/upload/%d && find . -type f | sort" % part).split()
        for name in ("./data1.in", "./data2.out", "./ex_data1.in", "./my_chk.cpp", "./require/helper.h", "./download/tool.py"):
            self.assertIn(name, names)
        # the checker of the package lets a sum that is one too large pass; every test has to pass
        self.assertEqual(uoj.wait_submission(admin.submit(part, AB_WRONG)).score, 100)
        self.assertEqual(uoj.wait_submission(admin.submit(part, AB.replace("a + b", "a + b + (a > 5 ? 5 : 0)"))).score, 0)

        # ---- wrong: made with what a problem has when nothing is said, and told why
        conf = published_conf(wrong)
        self.assertEqual((conf["time_limit"], conf["n_tests"]), ("1", "1"))
        self.assertIn("problem.conf 没有采用（第 3 行", told)
        # ---- both: the config.yaml is not read, and it is said
        conf = published_conf(both)
        self.assertEqual((conf["time_limit"], conf["memory_limit"], conf["use_builtin_checker"], conf["n_tests"]), ("4", "128", "ncmp", "1"))
        self.assertIn("按 problem.conf 导入，config.yaml 没有读", told)
        self.assertFalse(p9.exists("/var/uoj_data/upload/%d/config.yaml" % both))

    def test_comments_of_a_problem_conf_never_reach_the_judgers(self):
        admin = uoj.admin()
        problem_id = admin.new_problem(title="p10 带注释的 conf")
        manage = "/problem/%d/manage/data" % problem_id
        plain = ab_problem_files()["problem.conf"]
        commented = "﻿# 评测设置\r\n" + "".join("%s\t# 说明 %d\r\n" % (line.replace(" ", "\t"), n) for n, line in enumerate(plain.splitlines())) + "# n_subtasks 1\r\n"
        # ---- in an archive of data
        files = ab_problem_files()
        files["problem.conf"] = commented
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        self.assertEqual(uploaded_conf_text(problem_id), plain)
        self.assertEqual(admin.sync(problem_id), "")
        self.assertEqual(published_conf(problem_id)["n_tests"], "3")
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)
        # ---- as a file of its own
        r = p6.upload_files(admin, problem_id, {"problem.conf": commented.replace("time_limit\t1", "time_limit\t2")})
        self.assertIn(r.status_code, (200, 302))
        self.assertEqual(uploaded_conf_text(problem_id), plain.replace("time_limit 1", "time_limit 2"))
        # ---- typed into the page
        r = admin.post(manage, {"form": "conf_text", "conf_text": commented.replace("time_limit\t1", "time_limit\t3")})
        self.assertEqual(r.status_code, 302, uoj.text_of(r.text)[-300:])
        self.assertEqual(uploaded_conf_text(problem_id), plain.replace("time_limit 1", "time_limit 3"))
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(published_conf(problem_id)["time_limit"], "3")
        self.assertNotIn("#", uoj.docker_exec(uoj.WEB, "cat /var/uoj_data/%d/problem.conf" % problem_id))
        # the page says that comments may be typed
        self.assertIn("保存时注释会去掉", uoj.text_of(admin.get(manage).text))


class CopiedStatementTest(unittest.TestCase):
    """a copy of a problem says what the problem says, to everybody the copy is for: in its
    domain and in the homework it is set in"""

    STATEMENT = "## 题目描述\n\n把 $a$ 和 $b$ 加起来，价格 \\$5。\n\n看图：![示意图](/attachment/%d)\n\n```input1\n1 2\n```\n\n```output1\n3\n```\n"

    def test_copy_in_a_homework_says_what_the_problem_says(self):
        admin = uoj.admin()
        teacher, student = p3.account("p10_copy_teacher"), p3.account("p10_copy_student")
        self.assertEqual(admin.change_user("p10_copy_teacher", "grant:teacher"), "")
        slug = "p10-copy"
        teacher.new_domain(slug)
        self.assertEqual(teacher.form("/d/%s/members" % slug, "add", username="p10_copy_student", role="member"), "")

        # ---- a public problem of the site, whose statement shows a picture of its own
        source = admin.create_problem(ab_problem_files())
        self.assertEqual(p6.attach(admin, "/problem/%d/manage/attachments" % source, [("figure.png", p9.picture_bytes())]), "")
        picture = p6.attachments_of("problem", source)["figure.png"][0]
        p8.save_statement(admin, source, self.STATEMENT % picture, title="p10 有题面的题", hidden=False)
        page = student.get("/problem/%d" % source).text
        self.assertIn("把 $a$ 和 $b$ 加起来", uoj.text_of(page))
        self.assertNotIn('id="statement-empty"', page)

        # ---- copied into a domain: the copy says the same, and the picture it shows is its own
        copy = teacher.copy_problem(slug, source)
        self.assertEqual(uoj.wait_data_version(copy), "")
        number = uoj.pid(copy)
        copied_picture = p6.attachments_of("problem", copy)["figure.png"][0]
        self.assertNotEqual(copied_picture, picture)
        shown, kept = (bytes.fromhex(c).decode() for c in db("select hex(statement), hex(statement_md) from problems_contents where id = %d" % copy)[0])
        self.assertIn("<p>把 $a$ 和 $b$ 加起来，价格 \\$5。</p>", shown)
        self.assertIn('<pre><code class="language-input1">1 2</code></pre>', shown)
        self.assertEqual(re.findall(r"/attachment/(\d+)", shown), [str(copied_picture)])
        self.assertEqual(re.findall(r"/attachment/(\d+)", kept), [str(copied_picture)])
        in_domain = "/d/%s/problem/%d" % (slug, number)
        page = teacher.get(in_domain).text
        self.assertIn("把 $a$ 和 $b$ 加起来", uoj.text_of(page))
        self.assertIn('src="/attachment/%d"' % copied_picture, page)
        self.assertNotIn('id="statement-empty"', page)
        # (a copy starts hidden: the members of the domain do not see it there yet)
        self.assertEqual(student.get(in_domain).status_code, 404)

        # ---- set as homework: whoever takes part reads it there, picture and all, also when
        # the problem it was copied from is hidden from them
        db("update problems set is_hidden = 1 where id = %d" % source)
        homework_id = p4.new_homework(teacher, slug, title="p10 作业")
        self.assertEqual(p4.homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(number), score="100"), "")
        self.assertEqual(p4.homework_form(teacher, slug, homework_id, "publish"), "")
        uoj.wait_until("the homework is published", lambda: p4.tick() and p4.homework_row(homework_id, "status")[0] == "published", timeout=300)
        self.assertEqual(student.form("/d/%s/homework/%d" % (slug, homework_id), "claim"), "")
        in_homework = "/d/%s/homework/%d/problem/%d" % (slug, homework_id, number)
        for client in (teacher, student):
            r = client.get(in_homework)
            self.assertEqual(r.status_code, 200)
            self.assertIn("把 $a$ 和 $b$ 加起来", uoj.text_of(r.text))
            self.assertIn('src="/attachment/%d"' % copied_picture, r.text)
            self.assertNotIn('id="statement-empty"', r.text)
        r = student.get("/attachment/%d" % copied_picture)
        self.assertEqual((r.status_code, r.headers["Content-Type"]), (200, "image/png"))
        self.assertEqual(student.get("/attachment/%d" % picture).status_code, 404)
        self.assertEqual(uoj.wait_submission(student.submit(copy, AB, path=in_homework)).score, 100)

        # ---- the problem it was copied from changes: the copy says what it said, until
        # somebody who manages it has it say what the problem says now
        p8.save_statement(admin, source, "## 题目描述\n\n改过的题面：求 $a+b$，图换了位置：![新图](/attachment/%d)\n" % picture, title="p10 有题面的题", hidden=True)
        self.assertIn("把 $a$ 和 $b$ 加起来", uoj.text_of(student.get(in_homework).text))
        manage = "/d/%s/problem/%d/manage/statement" % (slug, number)
        page = teacher.get(manage).text
        self.assertIn('id="statement-source"', page)
        self.assertIn("复制自 主站 #%d" % source, uoj.text_of(page))
        # (the problem is hidden from the teacher now: there is nothing to take from it)
        self.assertNotIn('id="button-refresh-statement"', page)
        self.assertIn("没有权限复制它的来源", teacher.form(manage, "refresh_statement"))
        db("update problems set is_hidden = 0 where id = %d" % source)
        self.assertIn('id="button-refresh-statement"', teacher.get(manage).text)
        self.assertEqual(student.post(manage, {"form": "refresh_statement"}).status_code, 403)
        self.assertIn("把 $a$ 和 $b$ 加起来", uoj.text_of(student.get(in_homework).text))
        self.assertEqual(teacher.form(manage, "refresh_statement"), "")
        page = student.get(in_homework).text
        self.assertIn("改过的题面：求 $a+b$", uoj.text_of(page))
        self.assertNotIn("把 $a$ 和 $b$ 加起来", uoj.text_of(page))
        # the picture is still the one of the copy: the same file, which kept its number
        self.assertIn('src="/attachment/%d" alt="新图"' % copied_picture, page)
        self.assertEqual(sorted(p6.attachments_of("problem", copy)), ["figure.png"])
        self.assertEqual(db_value("select count(*) from audit_logs where action = 'problem.refresh_statement' and resource_id = '%d'" % copy), "1")
        # a problem that was not copied has nothing to take a statement from
        own = teacher.new_problem(domain=slug, title="p10 自己的题")
        page = teacher.get("/d/%s/problem/%d/manage/statement" % (slug, uoj.pid(own))).text
        self.assertNotIn('id="statement-source"', page)
        self.assertNotEqual(teacher.form("/d/%s/problem/%d/manage/statement" % (slug, uoj.pid(own)), "refresh_statement"), "")

    def test_problem_without_a_statement_says_so(self):
        admin, student = uoj.admin(), p3.account("p10_empty_student")
        problem_id = admin.new_problem(title="p10 没有题面的题", public="on")
        # whoever manages it is told what its readers see and where to change it
        page = admin.get("/problem/%d" % problem_id).text
        self.assertRegex(page, r'<div class="alert alert-warning[^"]*" role="alert" id="statement-empty">')
        self.assertIn('href="/problem/%d/manage/statement"' % problem_id, page)
        # its readers are told that there is nothing to read
        page = student.get("/problem/%d" % problem_id).text
        self.assertIn('<p class="text-muted top-buffer-md" id="statement-empty">这道题还没有题面。</p>', page)
        self.assertNotIn("去写题面", page)
        # a statement that is a picture and nothing else is a statement
        self.assertEqual(p6.attach(admin, "/problem/%d/manage/attachments" % problem_id, [("statement.png", p9.picture_bytes())]), "")
        picture = p6.attachments_of("problem", problem_id)["statement.png"][0]
        # (a problem whose statement is a file it comes with: its readers find the file under it)
        page = student.get("/problem/%d" % problem_id).text
        self.assertNotIn('id="statement-empty"', page)
        self.assertIn('href="/attachment/%d"' % picture, page)
        self.assertIn('id="statement-empty"', admin.get("/problem/%d" % problem_id).text)
        p8.save_statement(admin, problem_id, "![题面](/attachment/%d)\n" % picture, title="p10 没有题面的题", hidden=False)
        for client in (admin, student):
            self.assertNotIn('id="statement-empty"', client.get("/problem/%d" % problem_id).text)
