"""Takes pictures of the pages of a domain, so that their layout can be looked at: nothing of
the web interface can be rendered where it is developed.

    pip install playwright && python3 -m playwright install --with-deps chromium
    python3 tests/e2e/screenshots.py <folder for the pictures>

It fills a domain of its own with what a class looks like, and takes every page at the width
of a desktop and of a phone.
"""

import os
import sys

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
    teacher.form("/d/%s/problems" % SLUG, "new")
    own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
    teacher.upload_data(own_id, ab_problem_files())
    teacher.sync(own_id)
    db("update problems set title = '链表的中间结点', is_hidden = 0 where id = %d" % own_id)
    # a statement with formulas, to see that they are typeset without anything from elsewhere
    statement = (
        r"<h3>题目描述</h3><p>给定 $n$ 个整数 $a_1, a_2, \dots, a_n$，求下面的值对 $10^9+7$ 取模的结果：</p>"
        r"<p>$$\sum_{i=1}^{n} a_i^2 + \left\lfloor \frac{n}{2} \right\rfloor$$</p>"
        r"<h3>数据范围</h3><p>$1 \le n \le 10^5$，$|a_i| \le 10^9$。</p>"
    )
    db("update problems_contents set statement = '%s' where id = %d" % (statement.replace("\\", "\\\\"), own_id))
    public_id = admin.create_problem(ab_problem_files())
    db("update problems set title = 'A + B Problem' where id = %d" % public_id)
    teacher.form("/d/%s/problems" % SLUG, "copy", problem_id=str(public_id))
    copy_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
    db("update problems set title = '两数之和（改编）', is_hidden = 0 where id = %d" % copy_id)

    def homework(title, **settings):
        homework_id = p4.new_homework(teacher, SLUG, title=title, description_md="请独立完成。**不要**抄袭。", **settings)
        for problem_id, score in ((public_id, 60), (own_id, 40)):
            p4.homework_form(teacher, SLUG, homework_id, "add_problem", problem_id=str(problem_id), score=str(score))
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
                    uoj.wait_submission(client.submit(problem_id, code, path="/d/%s/homework/%d/problem/%d" % (SLUG, homework_id, problem_id)))

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
    for problem_id, optional in ((own_id, {}), (public_id, {}), (copy_id, {"optional": "on"})):
        teacher.form("/d/%s/training/%d/manage" % (SLUG, training), "add_problem", problem_id=str(problem_id), **optional)
    teacher.form(new_training, "save", title="第二章 树与二叉树", status="draft", description_md="")
    uoj.wait_submission(students[0].submit(public_id, AB))
    uoj.wait_submission(students[2].submit(public_id, AB_WRONG))
    uoj.wait_idle()
    return {"teacher": teacher, "student": students[0], "outsider": p3.account("shot_outsider"), "visitor": None,
            "admin": admin, "past": past, "current": current, "training": training, "problem": own_id}  # fmt: skip


def pages(seeded):
    """name of the picture, who looks, address"""
    d = "/d/" + SLUG
    past, current = d + "/homework/%d" % seeded["past"], d + "/homework/%d" % seeded["current"]
    training = d + "/training/%d" % seeded["training"]
    return [
        ("grades", "teacher", d + "/grades"),
        ("problem-statement", "student", d + "/problem/%d" % seeded["problem"]),
        ("profile", "teacher", "/user/profile/" + STUDENTS[0][0]),
        ("monitor", "admin", "/super-manage/monitor"),
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
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        for name, who, path in pages(clients):
            for label, viewport in VIEWPORTS.items():
                context = browser.new_context(viewport=viewport, locale="zh-CN")
                client = clients[who]
                if client is not None:
                    context.add_cookies(
                        [{"name": c.name, "value": c.value, "url": uoj.BASE_URL} for c in client.http.cookies]
                    )
                page = context.new_page()
                page.goto(uoj.BASE_URL + path, wait_until="networkidle")
                page.screenshot(path=os.path.join(out, "%s-%s.png" % (name, label)), full_page=True)
                context.close()
                print("took", name, label)
        browser.close()
    p3.IDP.stop()


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "screenshots")
