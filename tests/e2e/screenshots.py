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
import uoj
from uoj import db, db_value

SLUG = "ds-2026-a"
VIEWPORTS = {"desktop": {"width": 1280, "height": 900}, "mobile": {"width": 390, "height": 844}}
STUDENTS = [("20260101", "陈一鸣"), ("20260102", "林晓雨"), ("20260103", "王子涵"), ("20260104", "赵思远"),
            ("20260105", "刘欣怡"), ("20260106", "黄浩然")]  # fmt: skip


def seed():
    """a class with its teachers, its students and what they see"""
    admin = uoj.admin()
    teacher = p3.account("shot_teacher")
    admin.change_user("shot_teacher", "grant:teacher")
    teacher.update_profile(nickname="周老师")
    if db_value("select count(*) from domains where slug = '%s'" % SLUG) == "0":
        teacher.new_domain(SLUG, name="2026 秋 数据结构 计科 1 班", join_method="code",
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
    if db_value("select count(*) from domain_announcements") == "0":
        teacher.form(news, "save", title="期中考试安排", pinned="on",
                     content_md="期中考试在 **第 9 周周二** 随堂进行，范围是前 6 章。\n\n- 闭卷，可以带一张 A4 纸\n- 上机部分在本域的比赛里进行")  # fmt: skip
        teacher.form(news, "save", title="第 2 次作业讲评",
                     content_md="大部分同学的问题出在边界：链表为空时 `head` 是 `NULL`。\n\n复杂度应为 $O(n)$。")  # fmt: skip
    teacher.form(members, "invite", label="周二班", hours="168", max_uses="60")
    return {"teacher": teacher, "student": students[0], "outsider": p3.account("shot_outsider"), "visitor": None}


def pages():
    """name of the picture, who looks, address"""
    d = "/d/" + SLUG
    return [
        ("domains-teacher", "teacher", "/domains"),
        ("domains-outsider", "outsider", "/domains"),
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
        for name, who, path in pages():
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
