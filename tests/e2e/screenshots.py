"""Takes pictures of the pages of a domain, so that their layout can be looked at: nothing of
the web interface can be rendered where it is developed.

    pip install playwright && python3 -m playwright install --with-deps chromium
    python3 tests/e2e/screenshots.py <folder for the pictures>

It fills a domain of its own with what a class looks like, and takes every page at the width
of a desktop and of a phone.
"""

import json
import os
import struct
import sys
import zlib

from playwright.sync_api import sync_playwright

import test_phase3 as p3
import test_phase4 as p4
import uoj
from fixtures import AB, AB_WRONG, ab_problem_files
from uoj import db, db_value

SLUG = "ds-2026-a"
VIEWPORTS = {"desktop": {"width": 1280, "height": 900}, "mobile": {"width": 390, "height": 844}}
# student numbers as the school writes them: two capital letters and eight digits
STUDENTS = [("CS26010001", "陈一鸣"), ("CS26010002", "林晓雨"), ("CS26010003", "王子涵"), ("CS26010004", "赵思远"),
            ("CS26010005", "刘欣怡"), ("CS26010006", "黄浩然")]  # fmt: skip


STATEMENT = r"""## **题目描述**

给定 $n$ 个整数 $a_1, a_2, \dots, a_n$，求下面的值对 $10^9+7$ 取模的结果：

$$\sum_{i=1}^{n} a_i^2 + \left\lfloor \frac{n}{2} \right\rfloor$$

> 中间结果会超过 32 位整数，要用 `long long`。

## **输入格式**

第一行一个整数 $n$ $(1\le n\le 10^5)$；第二行 $n$ 个整数 $a_1,a_2,\ldots,a_n$ $(|a_i|\le 10^9)$。

## **样例**

```input1
5
2 7 8 1 4
```

```output1
136
```

```input2
1
1000000000
```

```output2
49
```
## **数据范围**

| 子任务 | $n \le$ | 分值 |
|:-:|:-:|:-:|
| 1 | $10^3$ | 30 |
| 2 | $10^5$ | 70 |
"""


def picture(width=360, height=140):
    """a picture large enough to be seen: bands of colour"""
    rows = b""
    for y in range(height):
        rows += b"\x00" + b"".join(bytes((60 + 150 * x // width, 110 + 90 * y // height, 200 - 120 * x // width)) for x in range(width))
    chunk = lambda kind, data: struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)
    header = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", header) + chunk(b"IDAT", zlib.compress(rows, 9)) + chunk(b"IEND", b"")


def seed():
    """a class with its teachers, its students and what they see"""
    admin = uoj.admin()
    teacher = p3.account("shot_teacher")
    admin.change_user("shot_teacher", "grant:teacher")
    teacher.update_profile(nickname="周老师")
    if db_value("select count(*) from domains where slug = '%s'" % SLUG) == "0":
        teacher.new_domain(SLUG, name="2026 秋 数据结构 计科 1 班",
                           description="周二 3-4 节，实验楼 302。\n作业每周日 23:59 截止，迟交两天内按 80% 计分。")  # fmt: skip
    members = "/d/%s/members" % SLUG
    for name, role in (("shot_co", "admin"), ("shot_ta", "ta")):
        p3.account(name)
        teacher.form(members, "add", username=name, role=role)
    students = []
    if "/login/sso/cas" in uoj.Client().get("/login").text:
        p3.IDP.start()
        for number, real_name in STUDENTS[:4]:
            client = uoj.Client()
            p3.cas_login(client, "login_" + number, employeeNumber=number, cn=real_name)
            client.username, client.password = number, ""
            students.append(client)
        students[0].update_profile(nickname="一鸣惊人")
    else:
        for number, real_name in STUDENTS[:4]:
            students.append(p3.account("shot_" + number))
    # two of the roster have not logged in yet
    roster = "\n".join(number for number, real_name in STUDENTS) if p3.IDP.server else "\n".join(c.username for c in students)
    teacher.form(members, "import", roster=roster, role="member")
    teacher.get(members)

    news = "/d/%s/announcements" % SLUG
    did = p4.domain_id(SLUG)
    if db_value("select count(*) from domain_announcements where domain_id = %d" % did) == "0":
        teacher.form(news, "save", title="期中考试安排", pinned="on",
                     content_md="期中考试在 **第 9 周周二** 随堂进行，范围是前 6 章。\n\n- 闭卷，可以带一张 A4 纸\n- 上机部分在本域的比赛里进行")  # fmt: skip
        teacher.form(news, "save", title="第 2 次作业讲评",
                     content_md="大部分同学的问题出在边界：链表为空时 `head` 是 `NULL`。\n\n复杂度应为 $O(n)$。")  # fmt: skip
    teacher.form(members, "invite", label="周二班", hours="168", max_uses="60")

    # a problem of the domain, a homework that is over and settled, one that runs, and a draft
    # A statement as it is written for Hydro, to see what a reader of it sees: sections under
    # "##" in bold, formulas, and samples in blocks of code named input1 and output1.
    teacher.new_problem_form(SLUG, statement_md=STATEMENT)
    own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
    teacher.upload_data(own_id, ab_problem_files())
    teacher.sync(own_id)
    db("update problems set title = '链表的中间结点', is_hidden = 0 where id = %d" % own_id)
    public_id = admin.create_problem(ab_problem_files())
    db("update problems set title = 'A + B Problem' where id = %d" % public_id)
    copy_id = teacher.copy_problem(SLUG, public_id)
    uoj.wait_data_version(copy_id)
    db("update problems set title = '两数之和（改编）', is_hidden = 0 where id = %d" % copy_id)

    def homework(title, **settings):
        homework_id = p4.new_homework(teacher, SLUG, title=title, description_md="请独立完成。**不要**抄袭。", **settings)
        for problem_id, score in ((copy_id, 60), (own_id, 40)):
            p4.homework_form(teacher, SLUG, homework_id, "add_problem", problem_id=str(uoj.pid(problem_id)), score=str(score))
        return homework_id

    def publish(homework_id):
        p4.homework_form(teacher, SLUG, homework_id, "publish")
        uoj.wait_until("published", lambda: p4.tick() and p4.homework_row(homework_id, "status")[0] != "publishing")

    def hand_in(homework_id, programs):
        problem_ids = [int(row[0]) for row in db("select problem_id from homework_problems where homework_id = %d order by position" % homework_id)]
        for client, codes in zip(students, programs):
            client.form("/d/%s/homework/%d" % (SLUG, homework_id), "claim")
            for problem_id, code in zip(problem_ids, codes):
                if code is not None:
                    uoj.wait_submission(client.submit(problem_id, code, path="/d/%s/homework/%d/problem/%d" % (SLUG, homework_id, uoj.pid(problem_id))))

    past = homework("第 2 次作业 栈与队列")
    publish(past)
    hand_in(past, [(AB, AB), (AB, AB_WRONG), (AB_WRONG, None)])
    db("update submissions set submit_time = '%s' where homework_id = %d" % (uoj.web_time(-5 * 86400), past))
    db("update submissions set submit_time = '%s' where homework_id = %d and submitter = '%s'" % (uoj.web_time(-2 * 86400), past, students[1].username))
    db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-3600), past))
    p4.tick()

    current = homework("第 3 次作业 链表")
    publish(current)
    hand_in(current, [(AB, AB_WRONG), (AB, None)])
    homework("第 4 次作业 树", begin_at=uoj.web_time(5 * 86400), penalty_since=uoj.web_time(12 * 86400), end_at=uoj.web_time(14 * 86400))

    # a training that is out, and one that is being written
    new_training = "/d/%s/training/new" % SLUG
    teacher.form(new_training, "save", title="第一章 线性表", status="published",
                 description_md="配合第 2、3 周的课。**必做题**做完就算完成，选做题有余力再做。")  # fmt: skip
    training = int(db_value("select max(id) from trainings where domain_id = %d" % did))
    for problem_id, optional in ((own_id, {}), (copy_id, {"optional": "on"})):
        teacher.form("/d/%s/training/%d/manage" % (SLUG, training), "add_problem", problem_id=str(uoj.pid(problem_id)), **optional)
    teacher.form(new_training, "save", title="第二章 树与二叉树", status="draft", description_md="")
    own_page = "/d/%s/problem/%d" % (SLUG, uoj.pid(own_id))
    uoj.wait_submission(students[0].submit(own_id, AB, path=own_page))
    uoj.wait_submission(students[2].submit(own_id, AB_WRONG, path=own_page))

    # a contest of the site that is over, and a student who sits it again
    second_public = admin.create_problem(ab_problem_files())
    db("update problems set title = '括号匹配' where id = %d" % second_public)
    contest_id = admin.new_contest("2026 秋 月赛（一）", minutes=180)
    admin.contest_commands(contest_id, "problems", "+%d\n+%d" % (public_id, second_public))
    for client in students[:2]:
        client.register_for_contest(contest_id)
    uoj.move_contest(contest_id, -600, minutes=180)
    handed_in = [
        (students[0].submit_in_contest(contest_id, public_id, AB), 1200),
        (students[0].submit_in_contest(contest_id, second_public, AB), 5400),
        (students[1].submit_in_contest(contest_id, public_id, AB), 3000),
        (students[1].submit_in_contest(contest_id, second_public, AB_WRONG), 7000),
    ]
    uoj.wait_idle()
    uoj.move_contest(contest_id, -5 * 86400, minutes=180)
    for submission_id, seconds in handed_in:
        db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d second) where id = %d"
           % (contest_id, seconds, submission_id))  # fmt: skip
    admin.submit_form("/contest/%d" % contest_id, "start_test")
    uoj.wait_idle()
    admin.submit_form("/contest/%d" % contest_id, "publish_result")
    admin.form("/contest/%d/manage" % contest_id, "allow", names="\n".join(number for number, real_name in STUDENTS[:5]))
    sitter = students[2]
    sitter.form("/contest/%d/virtual" % contest_id, "start")
    virtual_one = sitter.submit(public_id, AB, path="/contest/%d/problem/%d" % (contest_id, public_id))
    uoj.wait_submission(virtual_one)
    # an hour and a half into it
    db("update contest_virtuals set start_time = '%s' where contest_id = %d" % (uoj.web_time(-5400), contest_id))
    db("update submissions set submit_time = '%s' where id = %d" % (uoj.web_time(-5400 + 2100), virtual_one))
    uoj.wait_idle()

    # a file that comes with a problem, and an ICPC contest that runs with its board frozen
    teacher.post("/d/%s/problem/%d/manage/attachments" % (SLUG, uoj.pid(own_id)), {"form": "add_attachments"},
                 [("attachments[]", ("本地测试工具.py", b"print('try your solution')\n", "text/x-python")),
                  ("attachments[]", ("大样例.zip", b"PK\x05\x06" + bytes(18), "application/zip"))])  # fmt: skip
    icpc = admin.new_contest("2026 秋 ICPC 校内选拔", minutes=300, rule="ICPC", freeze_minutes="60", problems="%d %d" % (public_id, second_public))
    admin.post("/contest/%d/manage" % icpc, {"form": "add_attachments", "tab": "attachments"},
               [("attachments[]", ("statements.pdf", b"%PDF-1.4\n%%EOF\n", "application/pdf"))])  # fmt: skip
    for client in students[:3]:
        client.register_for_contest(icpc)
    uoj.move_contest(icpc, -250 * 60, 300)
    # who submitted what, and how many minutes into the contest; None is now, after the board froze
    timeline = [(students[0], public_id, AB_WRONG, 10), (students[0], public_id, AB, 20), (students[1], public_id, AB, 30),
                (students[1], second_public, AB_WRONG, 50), (students[0], second_public, AB, 95),
                (students[1], second_public, AB, None), (students[2], public_id, AB, None)]  # fmt: skip
    for client, problem_id, code, minute in timeline:
        submission_id = client.submit_in_contest(icpc, problem_id, code)
        if minute is not None:
            db("update submissions set submit_time = date_add((select start_time from contests where id = %d), interval %d minute) where id = %d"
               % (icpc, minute, submission_id))  # fmt: skip
    uoj.wait_idle()
    # an announcement of the site with a picture in it, and a judging account nobody uses yet
    admin.post("/announcement/new", {"form": "save", "title": "2026 秋季学期上机安排", "level": "1",
                                     "content_md": "第 3 周起，每周三晚 **19:00** 在实验楼 305 上机。\n\n- 带校园卡\n- 提前 10 分钟到"},
               [("media[]", ("机房.png", picture(), "image/png")), ("media[]", ("座位表.pdf", b"%PDF-1.4\n%%EOF\n", "application/pdf"))])  # fmt: skip
    announcement = int(db_value("select max(blog_id) from important_blogs"))
    admin.post("/super-manage/judger", {"submit-judger_adder": "judger_adder", "judger_adder_name": "lab305", "judger_adder_note": "实验楼 305 的机器"})
    return {"announcement": announcement, "teacher": teacher, "student": students[0], "outsider": p3.account("shot_outsider"), "visitor": None,
            "admin": admin, "past": past, "current": current, "training": training, "problem": own_id,
            "sitter": sitter, "contest": contest_id, "icpc": icpc, "public": public_id, "second_public": second_public}  # fmt: skip


# ---- what is done on a page before its picture is taken. Each of these also checks that the
# page did what it is there for: this is the only place where the scripts of the pages run.


def pick_problem(page, seeded):
    """the field that picks problems: what is typed is looked up, and offered"""
    field = "#input-contest-problem-number"
    page.click(field + "-search")
    page.fill(field + "-search", "括号")
    page.wait_for_selector(field + "-picker .uoj-picker-menu.show .dropdown-item.active")
    offered = page.inner_text(field + "-picker .uoj-picker-menu .dropdown-item.active")
    assert "括号匹配" in offered and "#%d" % seeded["second_public"] in offered, offered


def picked_problem(page, seeded):
    """and what is chosen stands in the field as a tag, and is what the form will send"""
    field = "#input-contest-problem-number"
    pick_problem(page, seeded)
    page.keyboard.press("Enter")
    page.wait_for_selector(field + "-picker .uoj-picker-chip")
    assert "括号匹配" in page.inner_text(field + "-picker .uoj-picker-chip")
    sent = page.eval_on_selector(field, "e => e.value")
    assert sent == str(seeded["second_public"]), sent
    # a second one, by its number, comes after it; the cross takes one away again
    page.fill(field + "-search", "#%d" % seeded["public"])
    page.wait_for_selector(field + "-picker .uoj-picker-menu.show .dropdown-item.active")
    page.keyboard.press("Enter")
    page.wait_for_function("document.querySelectorAll('%s-picker .uoj-picker-chip').length == 2" % field)
    sent = page.eval_on_selector(field, "e => e.value")
    assert sent == "%d %d" % (seeded["second_public"], seeded["public"]), sent
    page.click(field + "-picker .uoj-picker-chip:first-of-type .uoj-picker-remove")
    page.wait_for_function("document.querySelectorAll('%s-picker .uoj-picker-chip').length == 1" % field)
    sent = page.eval_on_selector(field, "e => e.value")
    assert sent == str(seeded["public"]), sent
    # Enter that comes before the answer chooses what the answer begins with
    page.fill(field + "-search", "括号")
    page.keyboard.press("Enter")
    page.wait_for_function("document.querySelectorAll('%s-picker .uoj-picker-chip').length == 2" % field)
    assert page.url.endswith("#tab-problems"), page.url
    sent = page.eval_on_selector(field, "e => e.value")
    assert sent == "%d %d" % (seeded["public"], seeded["second_public"]), sent


CONF_STATE = "document.querySelector('#conf-preview-state').textContent"
CONF_TEXT = "document.querySelector('#conf-text').value"


def conf_follows_the_form(page, seeded):
    """the page of the data of a problem: problem.conf beside the form shows what saving the
    form would write, while the form is filled in"""
    page.wait_for_function(CONF_STATE + ".length > 0")
    assert "n_tests 3" in page.input_value("#conf-text"), page.input_value("#conf-text")
    # another kind of problem: the fields that belong to it come, and problem.conf says so
    page.click("label[for=input-problem-type-multi_pass]")
    page.wait_for_function(CONF_TEXT + ".indexOf('multi_pass 2') >= 0")
    assert page.is_visible("#group-problem-passes") and page.is_visible("#group-problem-checker_file")
    assert not page.is_visible("#group-problem-checker")
    assert "还没有校验器" in page.inner_text("#conf-preview-state"), page.inner_text("#conf-preview-state")
    page.click("label[for=input-problem-type-traditional]")
    page.wait_for_function(CONF_TEXT + ".indexOf('multi_pass') < 0")
    assert not page.is_visible("#group-problem-passes") and page.is_visible("#group-problem-checker")
    # a checker of the problem's own is a file that is chosen
    page.select_option("#input-problem-checker", "custom")
    page.wait_for_selector("#group-problem-checker_file", state="visible")
    page.select_option("#input-problem-checker_file", "val.cpp")
    page.wait_for_function(CONF_TEXT + ".indexOf('chk_source val.cpp') >= 0")
    # the subtasks are written into a table, a row for each
    page.click("label[for=input-problem-scoring-subtasks]")
    page.wait_for_selector("#table-problem-subtasks", state="visible")
    ends, scores = page.locator("#table-problem-subtasks .subtask-end"), page.locator("#table-problem-subtasks .subtask-score")
    for row, (end, score) in enumerate((("1", "40"), ("3", "60"))):
        ends.nth(row).fill(end)
        scores.nth(row).fill(score)
    page.wait_for_function(CONF_TEXT + ".indexOf('subtask_score_2 60') >= 0")
    assert "subtask_end_1 1\n" in page.input_value("#conf-text")
    assert "分值合计 100" in page.inner_text("#subtasks-total"), page.inner_text("#subtasks-total")
    sent = page.eval_on_selector("textarea[name=subtasks]", "e => e.value")
    assert sent == "1 40\n3 60", sent
    # what is wrong is said where problem.conf would be
    scores.nth(1).fill("50")
    page.wait_for_function(CONF_STATE + ".indexOf('100') >= 0")
    scores.nth(1).fill("60")
    page.wait_for_function(CONF_STATE + ".indexOf('还没有保存') >= 0")


def conf_is_edited_by_hand(page, seeded):
    """problem.conf is typed into when one says so, and the form stands still meanwhile"""
    page.wait_for_function(CONF_STATE + ".length > 0")
    as_the_form_writes_it = page.input_value("#conf-text")
    assert page.get_attribute("#conf-text", "readonly") is not None
    assert not page.is_visible("#button-save-conf-text")
    page.click("label[for=switch-edit-conf]")
    page.wait_for_selector("#button-save-conf-text", state="visible")
    assert page.get_attribute("#conf-text", "readonly") is None
    assert page.is_disabled("#input-problem-time_limit") and page.is_disabled("#button-save-judge-settings")
    saved = page.input_value("#conf-text")
    page.fill("#conf-text", saved + "time_limit_2 3\n")
    # given up: what the form would write is back, and so is the form
    page.click("label[for=switch-edit-conf]")
    page.wait_for_function(CONF_TEXT + ".indexOf('time_limit_2') < 0")
    page.wait_for_function("!document.querySelector('#input-problem-time_limit').disabled")
    page.wait_for_function(CONF_TEXT + " === " + json.dumps(as_the_form_writes_it))
    page.click("label[for=switch-edit-conf]")
    page.wait_for_selector("#button-save-conf-text", state="visible")
    assert page.input_value("#conf-text") == saved
    page.fill("#conf-text", saved + "time_limit_2 3\n")


def upload_dialog(page, seeded):
    """the dialog that takes files"""
    page.click("#button-upload-files")
    page.wait_for_selector("#UploadDataModal.show #input-data-files", state="visible")
    assert page.get_attribute("#input-data-files", "multiple") is not None


def board_names_problems_by_letter(page, seeded):
    """the board the browser draws: its problems are reached by their letters"""
    page.wait_for_selector("#standings thead a")
    links = page.eval_on_selector_all("#standings thead a", "links => links.map(a => a.getAttribute('href') + ' ' + a.textContent)")
    here = "/contest/%d/problem/" % seeded["contest"]
    assert links == [here + "A A", here + "B B"], links


def statement_is_read_as_it_was_written(page, seeded):
    """a statement written for Hydro: its formulas are set in the fonts of the site, also on a
    machine that has fonts MathJax would rather take, and its samples are samples"""
    formulas = "document.querySelectorAll('article script[type^=\"math/tex\"]').length"
    page.wait_for_function("%s > 0 && document.querySelectorAll('article .MathJax').length === %s" % (formulas, formulas))
    seen = page.evaluate(
        """() => {
            const width = font => {
                const pen = document.createElement('canvas').getContext('2d');
                pen.font = '40px ' + font;
                return pen.measureText('() {} []').width;
            };
            const jax = MathJax.OutputJax['HTML-CSS'];
            const style = selector => getComputedStyle(document.querySelector(selector));
            return {
                stix: width('STIXSizeOneSym, monospace') !== width('monospace'),
                font: jax.fontInUse,
                web: !!jax.webFonts,
                letter: style('article .MathJax .mi').fontFamily,
                heading: parseFloat(style('article h2').fontSize),
                text: parseFloat(style('article p').fontSize),
                indent: style('article p').textIndent,
                samples: [...document.querySelectorAll('article .uoj-samples')].map(
                    row => [...row.querySelectorAll('.uoj-sample-title span')].map(title => title.textContent).join(' + ')),
                left: document.querySelectorAll('article pre > code[class*="language-input"], article pre > code[class*="language-output"]').length,
                wide: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };
        }"""
    )
    # A Mac has the STIX fonts, and MathJax left to itself takes them: the machine that takes
    # these pictures is given them too, or this would show nothing of what a Mac shows.
    assert seen["stix"], "this browser has no STIX fonts (the package fonts-stix): %r" % seen
    assert seen["font"] == "TeX" and seen["web"], seen
    assert "MathJax_Math" in seen["letter"], seen
    assert seen["samples"] == ["输入 #1 + 输出 #1", "输入 #2 + 输出 #2"] and seen["left"] == 0, seen
    # a section of a text is not as large as the title of the page, and a paragraph is not indented
    assert seen["text"] < seen["heading"] < 1.6 * seen["text"] and seen["indent"] == "0px", seen
    assert not seen["wide"], seen
    # ---- the button of a sample copies it, with the end of its last line
    page.evaluate(
        """() => {
            window.copied = [];
            if (navigator.clipboard) {
                navigator.clipboard.writeText = text => { window.copied.push(text); return Promise.resolve(); };
            }
            document.execCommand = () => { window.copied.push(document.activeElement.value); return true; };
        }"""
    )
    button = "article .uoj-samples >> nth=0 >> .uoj-sample[data-kind=input] .uoj-sample-copy"
    assert page.inner_text(button) == "复制", page.inner_text(button)
    page.click(button)
    page.wait_for_function("window.copied.length > 0")
    assert page.evaluate("window.copied") == ["5\n2 7 8 1 4\n"], page.evaluate("window.copied")
    assert page.inner_text(button) == "已复制", page.inner_text(button)


def statement_saves_itself(page, seeded):
    """the page that edits a statement saves what is typed by itself and says that it did; what
    it could not save stays in the browser and is put back when the page is opened again"""
    problem_id = seeded["second_public"]
    kept = lambda: bytes.fromhex(db_value("select hex(statement_md) from problems_contents where id = %d" % problem_id)).decode()
    draft = "window.localStorage.getItem('uoj-draft:statement-%d')" % problem_id
    status = "document.querySelector('#status-problem').textContent"
    write = "text => { const editor = document.querySelector('.CodeMirror').CodeMirror; editor.replaceRange((editor.getValue() === '' ? '' : '\\n\\n') + text, {line: editor.lastLine(), ch: 99999}); }"
    width = page.viewport_size["width"]
    page.wait_for_selector(".CodeMirror")
    assert page.evaluate(status) == "", page.evaluate(status)

    first = "这一行是页面自己保存的（宽 %d）。" % width
    page.evaluate(write, first)
    assert page.evaluate(status) == "有还没保存的修改…", page.evaluate(status)
    page.wait_for_function(status + ".indexOf('已自动保存 ') === 0", timeout=20000)
    assert kept().endswith(first), kept()[-80:]
    assert page.evaluate(draft) is None
    assert "btn-success" in page.get_attribute(".blog-content-md-editor-toolbar .btn >> nth=0", "class")

    # ---- the server can not be reached: it is said, and the text stays in this browser
    page.route("**/manage/statement", lambda route: route.abort() if route.request.method == "POST" else route.continue_())
    page.evaluate("() => { navigator.sendBeacon = () => false; }")
    second = "这一行没有保存上（宽 %d）。" % width
    page.evaluate(write, second)
    page.wait_for_function(status + ".indexOf('没有保存下来：连不上服务器。') === 0", timeout=20000)
    assert not kept().endswith(second)
    assert second in page.evaluate(draft), page.evaluate(draft)
    assert "text-danger" in page.get_attribute("#status-problem", "class")
    # ---- the page is opened again: the text is put back, and saved now that the server answers
    page.unroute("**/manage/statement")
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector("#draft-note-problem")
    assert "已恢复" in page.inner_text("#draft-note-problem"), page.inner_text("#draft-note-problem")
    assert page.evaluate("document.querySelector('.CodeMirror').CodeMirror.getValue()").endswith(second)
    page.wait_for_function(status + ".indexOf('已自动保存 ') === 0", timeout=20000)
    assert kept().endswith(second), kept()[-80:]
    assert page.evaluate(draft) is None


def new_problem_is_kept(page, seeded):
    """what is typed into the form that makes a problem is there again when the page is opened
    again, and is thrown away when its writer says so"""
    draft = "window.localStorage.getItem('uoj-draft:new-problem-d-%s')" % SLUG
    written = "## 题目描述\n\n写到一半的题面，$n$ 个数。"
    assert not page.is_visible("#draft-note")
    page.fill("#input-problem-title", "写到一半的题")
    page.fill("#input-problem-statement", written)
    page.wait_for_function(draft + " !== null")
    page.reload(wait_until="networkidle")
    assert page.input_value("#input-problem-title") == "写到一半的题", page.input_value("#input-problem-title")
    assert page.input_value("#input-problem-statement") == written, page.input_value("#input-problem-statement")
    assert "已恢复" in page.inner_text("#draft-note"), page.inner_text("#draft-note")
    page.click("#draft-discard")
    assert page.input_value("#input-problem-statement") == "" and not page.is_visible("#draft-note")
    assert page.evaluate(draft) is None
    # the picture is taken of the form with what was put back
    page.fill("#input-problem-title", "链表的倒数第 k 个结点")
    page.fill("#input-problem-statement", written)
    page.wait_for_function(draft + " !== null")
    page.reload(wait_until="networkidle")
    assert page.is_visible("#draft-note")


def pages(seeded):
    """name of the picture, who looks, address, and what is done there before the picture"""
    d = "/d/" + SLUG
    past, current = d + "/homework/%d" % seeded["past"], d + "/homework/%d" % seeded["current"]
    training = d + "/training/%d" % seeded["training"]
    return [
        ("problem-new", "teacher", d + "/problem/new", new_problem_is_kept),
        ("statement-edit", "admin", "/problem/%d/manage/statement" % seeded["second_public"], statement_saves_itself),
        ("problem-data", "teacher", d + "/problem/%d/manage/data" % uoj.pid(seeded["problem"]), conf_follows_the_form),
        ("problem-data-editing", "teacher", d + "/problem/%d/manage/data" % uoj.pid(seeded["problem"]), conf_is_edited_by_hand),
        ("problem-data-upload", "teacher", d + "/problem/%d/manage/data" % uoj.pid(seeded["problem"]), upload_dialog),
        ("problem-attachments", "teacher", d + "/problem/%d/manage/attachments" % uoj.pid(seeded["problem"])),
        ("contest-new", "admin", "/contest/new"),
        ("contest-manage", "admin", "/contest/%d/manage" % seeded["icpc"]),
        ("contest-manage-problems", "admin", "/contest/%d/manage#tab-problems" % seeded["icpc"]),
        ("picker-menu", "admin", "/contest/%d/manage#tab-problems" % seeded["icpc"], pick_problem),
        ("picker-chosen", "admin", "/contest/%d/manage#tab-problems" % seeded["icpc"], picked_problem),
        ("icpc-home", "student", "/contest/%d" % seeded["icpc"]),
        ("icpc-standings-frozen", "student", "/contest/%d/standings" % seeded["icpc"]),
        ("icpc-standings-staff", "admin", "/contest/%d/standings" % seeded["icpc"]),
        ("icpc-submissions", "student", "/contest/%d/submissions" % seeded["icpc"]),
        ("grades", "teacher", d + "/grades"),
        ("problem-statement", "student", d + "/problem/%d" % uoj.pid(seeded["problem"]), statement_is_read_as_it_was_written),
        ("profile", "teacher", "/user/profile/" + STUDENTS[0][0]),
        ("monitor", "admin", "/super-manage/monitor"),
        ("judgers", "admin", "/super-manage/judger"),
        ("announcements-admin", "admin", "/announcements"),
        ("announcement", "visitor", "/announcement/%d" % seeded["announcement"]),
        ("announcement-edit", "admin", "/announcement/%d/edit" % seeded["announcement"]),
        ("home", "visitor", "/"),
        ("contests", "student", "/contests"),
        ("submissions", "student", "/submissions"),
        ("contest-submissions", "admin", "/contest/%d/submissions" % seeded["contest"]),
        ("contest-standings", "student", "/contest/%d/standings" % seeded["contest"], board_names_problems_by_letter),
        ("contest-access", "admin", "/contest/%d/manage#tab-access" % seeded["contest"]),
        ("virtual", "sitter", "/contest/%d/virtual" % seeded["contest"]),
        ("virtual-standings", "sitter", "/contest/%d/virtual?tab=standings" % seeded["contest"]),
        ("trainings-student", "student", d + "/trainings"),
        ("trainings-teacher", "teacher", d + "/trainings"),
        ("training-student", "student", training),
        ("training-progress", "teacher", training + "?view=progress"),
        ("training-manage", "teacher", training + "/manage"),
        ("homeworks-teacher", "teacher", d + "/homeworks"),
        ("homeworks-student", "student", d + "/homeworks"),
        ("homework-running-student", "student", current),
        ("homework-ended-student", "student", past),
        ("homework-new", "teacher", d + "/homework/new"),
        ("homework-manage-settings", "teacher", current + "/manage"),
        ("homework-manage-problems", "teacher", current + "/manage?tab=problems"),
        ("homework-manage-participants", "teacher", current + "/manage?tab=participants"),
        ("homework-manage-scores", "teacher", past + "/manage?tab=scores"),
        ("homework-scoreboard", "teacher", past + "/scoreboard"),
        ("problems-teacher", "teacher", d + "/problems"),
        ("contests-teacher", "teacher", d + "/contests"),
        ("domains-teacher", "teacher", "/domains"),
        ("domains-outsider", "outsider", "/domains"),
        ("domains-admin", "admin", "/domains"),
        ("site-settings", "admin", "/super-manage/settings"),
        ("domain-new", "teacher", "/domain/new"),
        ("domain-join", "outsider", "/domains/join"),
        ("overview-teacher", "teacher", d),
        ("overview-student", "student", d),
        ("members-teacher", "teacher", d + "/members"),
        ("members-student", "student", d + "/members"),
        ("announcements-teacher", "teacher", d + "/announcements"),
        ("announcements-student", "student", d + "/announcements"),
        ("settings-teacher", "teacher", d + "/settings"),
    ]


def main(out):
    os.makedirs(out, exist_ok=True)
    clients = seed()
    # a page that does not do what it is there for does not stop the pictures of the others:
    # all of them are taken, and then all that went wrong is said
    failures = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        for name, who, path, *then in pages(clients):
            for label, viewport in VIEWPORTS.items():
                context = browser.new_context(viewport=viewport, locale="zh-CN")
                client = clients[who]
                if client is not None:
                    context.add_cookies(
                        [{"name": c.name, "value": c.value, "url": uoj.BASE_URL} for c in client.http.cookies]
                    )
                page = context.new_page()
                page.goto(uoj.BASE_URL + path, wait_until="networkidle")
                try:
                    for act in then:
                        act(page, clients)
                except Exception as e:
                    failures.append("%s (%s): %s: %s" % (name, label, type(e).__name__, str(e).strip().split("\n")[0][:400]))
                page.screenshot(path=os.path.join(out, "%s-%s.png" % (name, label)), full_page=True)
                context.close()
                print("took", name, label)
        browser.close()
    p3.IDP.stop()
    if failures:
        raise SystemExit("pages that did not do what they are there for:\n" + "\n".join(failures))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "screenshots")
