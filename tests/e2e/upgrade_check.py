"""The upgrade of the database from MySQL 5.7 to 8.4 (upgrade-mysql.sh), as the CI runs it:
data is written through the site while it runs on MySQL 5.7, and is all there, the same, once
the site runs on MySQL 8.4.

    python3 tests/e2e/upgrade_check.py seed      on MySQL 5.7
    bash upgrade-mysql.sh
    python3 tests/e2e/upgrade_check.py verify    on MySQL 8.4
"""

import json
import os
import sys

import uoj
from fixtures import AB, AB_WRONG, ab_problem_files

STATE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "upgrade_state.json")
STUDENT = ("upgrade_student", "学生的密码-password")
# four bytes in UTF-8: a database that stored utf8mb3 would lose it
MOTTO = "升级之前写下的座右铭 😀"
# the tables whose rows are counted before and after (the logs grow by themselves)
TABLES = ["user_info", "problems", "problems_contents", "submissions", "problem_data_versions", "judger_info"]


def counts():
    return {table: int(uoj.db_value("select count(*) from `%s`" % table)) for table in TABLES}


def seed():
    assert uoj.db_value("select version()").startswith("5.7"), uoj.db_value("select version()")
    admin = uoj.admin()
    problem_id = admin.create_problem(ab_problem_files())
    student = uoj.client(STUDENT)
    assert student.update_profile(motto=MOTTO) == "ok"
    right_id, wrong_id = student.submit(problem_id, AB), student.submit(problem_id, AB_WRONG)
    right, wrong = uoj.wait_submission(right_id), uoj.wait_submission(wrong_id)
    assert (right.score, wrong.score) == (100, 0), (right, wrong)
    state = {"problem_id": problem_id, "right_id": right_id, "wrong_id": wrong_id, "counts": counts()}
    with open(STATE, "w") as f:
        json.dump(state, f, indent=1)
    print("written on MySQL %s: %s" % (uoj.db_value("select version()"), state["counts"]))


def verify():
    with open(STATE) as f:
        state = json.load(f)
    uoj.wait_for_web()
    version = uoj.db_value("select version()")
    assert version.startswith("8.4."), version
    # the accounts log in with the authentication of 8.4
    plugins = {tuple(row) for row in uoj.db("select user, plugin from mysql.user where user = 'root'")}
    assert plugins == {("root", "caching_sha2_password")}, plugins
    # every row is there, and what the site added since is its own doing
    assert counts() == state["counts"], (counts(), state["counts"])
    # a student logs in with the password of before, and the motto keeps its four-byte character
    student = uoj.client(STUDENT)
    page = student.get("/user/profile/" + STUDENT[0]).text
    assert MOTTO in page, "the motto is not on the profile"
    assert uoj.db_value("select motto from user_info where username = '%s'" % STUDENT[0]) == MOTTO
    # the judgements of before are as they were, and the site judges on
    right, wrong = uoj.get_submission(state["right_id"]), uoj.get_submission(state["wrong_id"])
    assert (right.score, wrong.score) == (100, 0), (right, wrong)
    again = uoj.wait_submission(student.submit(state["problem_id"], AB))
    assert again.score == 100, again
    # the data of the problem is synced once more, from the files of before
    err = uoj.admin().sync(state["problem_id"])
    assert not err, err
    assert uoj.wait_submission(student.submit(state["problem_id"], AB)).score == 100
    print("all there on MySQL %s" % version)


if __name__ == "__main__":
    {"seed": seed, "verify": verify}[sys.argv[1]]()
