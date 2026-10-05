"""End-to-end tests for phase 2: versions of problem data, programs built by the judgers, and
tasks that are followed from the judger that takes them to the result.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import uoj
from fixtures import *
from uoj import db, db_value, docker_exec, fake_fetch, judge_api, judgers_paused, sha256, wait_until

FAKE = uoj.FAKE_JUDGER["judger_name"]


def setUpModule():
    uoj.admin()


def submission_row(submission_id):
    status, judger, attempts = db(
        "select status, ifnull(judger_name, 'NULL'), judge_attempts from submissions where id = %d" % submission_id
    )[0]
    return status, judger, int(attempts)


def give_back(submission_id):
    r = judge_api("/judge/submit", {"requeue": "1", "id": submission_id, "fetch_new": "0"})
    assert r.text == "Nothing to judge", r.text


class JudgerProtocolTest(unittest.TestCase):
    """what a judger is given to do, and what happens to a task that its judger does not finish"""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())

    def submit_while_paused(self):
        submission_id = uoj.admin().submit(self.problem_id, AB)
        self.assertEqual(submission_row(submission_id), ("Waiting", "NULL", 0))
        return submission_id

    def test_judger_that_declines_work_is_given_none(self):
        with judgers_paused():
            submission_id = self.submit_while_paused()
            # "False" is what judge_client sent before it was fixed
            for value in ("False", "0", ""):
                self.assertIsNone(fake_fetch(fetch_new=value), value)
            self.assertEqual(submission_row(submission_id), ("Waiting", "NULL", 0))
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

    def test_judger_that_is_too_old_is_given_no_work(self):
        with judgers_paused():
            submission_id = self.submit_while_paused()
            r = judge_api("/judge/submit")
            self.assertEqual(r.text, "Nothing to judge")
            self.assertEqual(submission_row(submission_id), ("Waiting", "NULL", 0))
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

    def test_judger_that_is_switched_off_is_given_no_work(self):
        with judgers_paused():
            submission_id = self.submit_while_paused()
            db("update judger_info set enabled = 0 where judger_name = '%s'" % FAKE)
            try:
                self.assertIsNone(fake_fetch())
            finally:
                db("update judger_info set enabled = 1 where judger_name = '%s'" % FAKE)
            self.assertEqual(submission_row(submission_id), ("Waiting", "NULL", 0))
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

    def test_task_names_the_version_of_the_data(self):
        version, version_sha256 = db(
            "select version, sha256 from problem_data_versions where problem_id = %d and status = 'ready'"
            " order by version desc limit 1" % self.problem_id
        )[0]
        with judgers_paused():
            submission_id = self.submit_while_paused()
            task = fake_fetch()
            self.assertEqual(task["id"], submission_id)
            self.assertEqual(task["problem_data_version"], int(version))
            self.assertEqual(task["problem_data_sha256"], version_sha256)
            self.assertEqual(task["problem_data_prepare"], [])
            self.assertEqual(version_sha256, uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % self.problem_id))
            self.assertEqual(submission_row(submission_id), ("Judging", FAKE, 0))

            # the judger can not judge it and gives it back
            give_back(submission_id)
            self.assertEqual(submission_row(submission_id), ("Waiting", "NULL", 1))

        j = uoj.wait_submission(submission_id)
        self.assertEqual(j.score, 100)
        status, judger, attempts = submission_row(submission_id)
        self.assertIn(judger, uoj.JUDGER_NAMES)
        self.assertEqual(attempts, 0)
        self.assertEqual(uoj.judgements("submission", submission_id), [(FAKE, "reclaimed"), (judger, "judged")])

    def test_result_of_another_judger_is_ignored(self):
        submission_id = uoj.admin().submit(self.problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        judger = submission_row(submission_id)[1]

        # the submission is being judged by a real judger when the fake one reports a result
        with judgers_paused():
            db("update submissions set status = 'Judging' where id = %d" % submission_id)
            try:
                result = {"score": 0, "time": 1, "memory": 1, "details": "<error>forged</error>"}
                judge_api("/judge/submit", {
                    "submit": "1", "fetch_new": "0", "id": submission_id, "result": json.dumps(result),
                })  # fmt: skip
                self.assertEqual(submission_row(submission_id), ("Judging", judger, 0))
                self.assertEqual(db_value("select score from submissions where id = %d" % submission_id), "100")
            finally:
                db("update submissions set status = 'Judged' where id = %d" % submission_id)

    def test_task_of_a_judger_that_went_silent_is_given_to_another(self):
        with judgers_paused():
            submission_id = self.submit_while_paused()
            self.assertEqual(fake_fetch()["id"], submission_id)
            # the judger is not heard of for ten minutes
            db(
                "update judger_info set last_heartbeat_at = now() - interval 10 minute"
                " where judger_name = '%s'" % FAKE
            )
            self.assertEqual(submission_row(submission_id), ("Judging", FAKE, 0))

        j = uoj.wait_submission(submission_id)
        self.assertEqual(j.score, 100)
        judger = submission_row(submission_id)[1]
        self.assertIn(judger, uoj.JUDGER_NAMES)
        self.assertEqual(uoj.judgements("submission", submission_id), [(FAKE, "reclaimed"), (judger, "judged")])

    def test_task_that_is_lost_again_and_again_fails(self):
        with judgers_paused():
            submission_id = self.submit_while_paused()
            # a judger that asks for work is not judging what it was given before
            for attempts in range(3):
                self.assertEqual(fake_fetch()["id"], submission_id)
                self.assertEqual(submission_row(submission_id), ("Judging", FAKE, attempts))
            self.assertIsNone(fake_fetch())

            j = uoj.get_submission(submission_id)
            self.assertEqual(j.error, "Judgement Failed")
            self.assertIn("3 times in a row", j.details)
            self.assertEqual(
                uoj.judgements("submission", submission_id),
                [(FAKE, "reclaimed"), (FAKE, "reclaimed"), (FAKE, "failed")],
            )

    def test_custom_test_and_hack_of_a_silent_judger_are_given_to_another(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files(), hackable=True)
        submission_id = admin.submit(problem_id, AB_HACKABLE % 4242)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

        with judgers_paused():
            custom_test_id = admin.custom_test(problem_id, AB, "1 2\n")
            self.assertEqual(fake_fetch()["id"], custom_test_id)
            hack_id = admin.hack(submission_id, b"4242 1\n", use_formatter=True)
            # asking again gives the custom test back and hands out the same test again,
            # which is ahead of hacks in the queue
            self.assertEqual(fake_fetch()["id"], custom_test_id)
            db(
                "update judger_info set last_heartbeat_at = now() - interval 10 minute"
                " where judger_name = '%s'" % FAKE
            )

        j = uoj.wait_custom_test(custom_test_id)
        self.assertEqual(j.infos, ["Success"], j)
        self.assertTrue(uoj.wait_hack(hack_id))
        outcomes = [outcome for _, outcome in uoj.judgements("custom_test", custom_test_id)]
        self.assertEqual(outcomes, ["reclaimed", "reclaimed", "judged"])
        self.assertEqual([outcome for _, outcome in uoj.judgements("hack", hack_id)], ["judged"])

    def test_judgement_is_recorded_with_its_judger_its_data_and_its_tools(self):
        submission_id = uoj.admin().submit(self.problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

        row = db(
            "select judger_name, problem_data_version, problem_data_sha256, judger_version, toolchain,"
            " outcome, score, finished_at >= started_at from submission_judgements"
            " where kind = 'submission' and target_id = %d" % submission_id
        )
        self.assertEqual(len(row), 1)
        judger, version, version_sha256, judger_version, toolchain, outcome, score, in_order = row[0]
        self.assertIn(judger, uoj.JUDGER_NAMES)
        self.assertEqual(version, db_value("select data_version from problems where id = %d" % self.problem_id))
        self.assertEqual(version_sha256, uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % self.problem_id))
        self.assertRegex(judger_version, "^[0-9a-f]{16}$")
        self.assertIn("g++", json.loads(toolchain.replace("\\\\", "\\"))["g++"])
        self.assertEqual((outcome, score, in_order), ("judged", "100", "1"))

        # the judger itself is known as alive, with the same version
        alive, version = db(
            "select last_heartbeat_at >= now() - interval 60 second, version from judger_info"
            " where judger_name = '%s'" % judger
        )[0]
        self.assertEqual((alive, version), ("1", judger_version))

        # the people who manage the problem see it on the page of the submission
        page = uoj.admin().http.get(uoj.BASE_URL + "/submission/%d" % submission_id).text
        self.assertIn("评测记录", page)
        self.assertIn(judger, page)
        self.assertIn(version_sha256[:16], page)

    def test_judgers_are_listed_with_their_state(self):
        page = uoj.admin().http.get(uoj.BASE_URL + "/super-manage/judger").text
        for judger in uoj.JUDGER_NAMES:
            self.assertIn(judger, page)
        self.assertIn("在线", page)
        self.assertIn('value="judger_switch"', page)


class DataVersionTest(unittest.TestCase):
    """every sync of the data of a problem is a version that can be told apart later"""

    def versions(self, problem_id):
        return db(
            "select version, status, sha256, size, created_by, reason from problem_data_versions"
            " where problem_id = %d order by version" % problem_id
        )

    def sync_again(self, admin, problem_id, a):
        files = ab_problem_files()
        files["input1.txt"] = "%d 2\n" % a
        files["output1.txt"] = "%d\n" % (a + 2)
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        self.assertEqual(admin.sync(problem_id), "")

    def test_every_sync_makes_a_version(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        archive = "/var/uoj_data/%d.zip" % problem_id

        first = self.versions(problem_id)
        self.assertEqual(len(first), 1)
        version, status, version_sha256, size, created_by, reason = first[0]
        self.assertEqual((version, status, created_by, reason), ("1", "ready", uoj.ADMIN[0], "sync"))
        self.assertEqual(version_sha256, uoj.file_sha256(uoj.WEB, archive))
        self.assertEqual(size, docker_exec(uoj.WEB, "stat -c %%s %s" % archive).strip())
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "1")

        # what is in a version is on record, file by file
        manifest = json.loads(db_value(
            "select manifest from problem_data_versions where problem_id = %d and version = 1" % problem_id
        ).replace("\\\\", "\\"))  # fmt: skip
        self.assertEqual(manifest["input1.txt"], [4, sha256(b"1 2\n")])
        self.assertIn("problem.conf", manifest)

        self.sync_again(admin, problem_id, 40)
        second = self.versions(problem_id)
        self.assertEqual([row[0] for row in second], ["1", "2"])
        self.assertNotEqual(second[1][2], version_sha256)
        self.assertEqual(second[1][2], uoj.file_sha256(uoj.WEB, archive))
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "2")

        # a judger can still get the version it was told to use
        r = judge_api("/judge/download/problem/%d/1" % problem_id)
        self.assertEqual((r.status_code, sha256(r.content)), (200, version_sha256))
        r = judge_api("/judge/download/problem/%d/2" % problem_id)
        self.assertEqual((r.status_code, sha256(r.content)), (200, second[1][2]))
        self.assertEqual(judge_api("/judge/download/problem/%d/3" % problem_id).status_code, 404)

        # and so can the people who manage the problem, nobody else
        url = uoj.BASE_URL + "/download.php?type=problem-data&id=%d&version=1" % problem_id
        r = admin.http.get(url)
        self.assertEqual((r.status_code, sha256(r.content)), (200, version_sha256))
        self.assertEqual(uoj.manager().http.get(url).status_code, 404)

        page = admin.http.get(uoj.BASE_URL + "/problem/%d/manage/data" % problem_id).text
        self.assertIn("数据版本", page)
        self.assertIn(second[1][2][:16], page)

    def test_archives_of_the_most_recent_versions_are_kept(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        for a in range(2, 8):
            self.sync_again(admin, problem_id, a)

        self.assertEqual([row[0] for row in self.versions(problem_id)], [str(v) for v in range(1, 8)])
        kept = docker_exec(uoj.WEB, "ls /var/uoj_data/archive/%d" % problem_id).split()
        self.assertEqual(sorted(kept), ["3.zip", "4.zip", "5.zip", "6.zip"])
        self.assertEqual(judge_api("/judge/download/problem/%d/2" % problem_id).status_code, 404)
        self.assertEqual(judge_api("/judge/download/problem/%d/3" % problem_id).status_code, 200)
        self.assertEqual(judge_api("/judge/download/problem/%d/7" % problem_id).status_code, 200)

    def test_clearing_the_data_makes_a_version(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "clear_data"), "")

        versions = self.versions(problem_id)
        self.assertEqual([(row[0], row[1], row[5]) for row in versions], [("1", "ready", "sync"), ("2", "ready", "clear")])
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "2")
        self.assertEqual(docker_exec(uoj.WEB, "ls /var/uoj_data/%d /var/uoj_data/upload/%d" % (problem_id, problem_id)).split(), [
            "/var/uoj_data/%d:" % problem_id, "/var/uoj_data/upload/%d:" % problem_id,
        ])  # fmt: skip

    def test_data_from_before_versions_is_registered_when_it_is_used(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        # the state of a problem that was synced before versions existed
        db("delete from problem_data_versions where problem_id = %d" % problem_id)
        db("update problems set data_version = 0 where id = %d" % problem_id)

        submission_id = admin.submit(problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        versions = self.versions(problem_id)
        self.assertEqual([(row[0], row[1], row[5]) for row in versions], [("1", "ready", "legacy")])
        self.assertEqual(versions[0][2], uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % problem_id))


class ProblemProgramsTest(unittest.TestCase):
    """3.4: the programs that come with a problem are built by the judgers, never by the web server"""

    def test_web_server_can_not_compile_or_trace(self):
        self.assertEqual(docker_exec(uoj.WEB, "command -v gcc g++ cc c++ make || true").strip(), "")
        self.assertEqual(
            docker_exec(uoj.WEB, "ls /opt/uoj/judger/uoj_judger/run | grep -v '\\.' || true").split(), ["formatter"]
        )
        capabilities = uoj.run("docker", "inspect", "-f", "{{.HostConfig.CapAdd}}", uoj.WEB).stdout.decode().strip()
        self.assertIn(capabilities, ("[]", "<no value>", "<nil>"))
        # the tools that built the PHP extension are gone, the extension is not
        self.assertEqual(
            docker_exec(uoj.WEB, "php -d extension=yaml.so -r 'echo function_exists(\"yaml_parse\") ? \"yes\" : \"no\";'"),
            "yes",
        )

    def test_checker_is_built_by_a_judger(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(checker_problem_files())

        prepare, status, judger = db(
            "select prepare, status, judger_name from problem_data_versions where problem_id = %d" % problem_id
        )[0]
        self.assertEqual(json.loads(prepare), [{"type": "compile", "name": "chk", "include": True}])
        self.assertEqual(status, "ready")
        self.assertIn(judger, uoj.JUDGER_NAMES)

        # the web server has the source, the judger has the program
        self.assertEqual(
            docker_exec(uoj.WEB, "ls /var/uoj_data/%d | grep chk" % problem_id).split(), ["chk.cpp"]
        )
        container = uoj.JUDGERS[uoj.JUDGER_NAMES.index(judger)]
        built = docker_exec(container, "ls /opt/uoj_judger/uoj_judger/data/%d | grep chk" % problem_id).split()
        self.assertEqual(sorted(built), ["chk", "chk.cpp"])
        assert_judger_has_data_of_web(self, container, problem_id)

        # and the checker decides: one more than the sum is fine for this one, two more is not
        for code, score in ((AB, 100), (AB_WRONG, 100), (AB.replace("a + b", "a + b + 2"), 0)):
            j = uoj.wait_submission(admin.submit(problem_id, code))
            self.assertEqual(j.score, score, j)

    def test_data_whose_checker_does_not_compile_is_not_published(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(checker_problem_files())
        published = uoj.tree_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id)

        self.assertIn("上传成功", admin.upload_data(problem_id, {"chk.cpp": "this is not C++\n"}).text)
        message = admin.sync(problem_id)
        self.assertIn("chk: compile error", message)
        self.assertIn("error", message.split("\n", 1)[1])

        # the judgers go on with the data that works
        self.assertEqual(
            db("select version, status from problem_data_versions where problem_id = %d order by version" % problem_id),
            [["1", "ready"], ["2", "failed"]],
        )
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "1")
        self.assertEqual(uoj.tree_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id), published)
        self.assertEqual(docker_exec(uoj.WEB, "ls /var/uoj_data | grep prepare || true").strip(), "")
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB)).score, 100)

        page = admin.http.get(uoj.BASE_URL + "/problem/%d/manage/data" % problem_id).text
        self.assertIn("未能发布", page)
        self.assertIn("chk: compile error", page)

        # fixing the checker publishes again
        self.assertIn("上传成功", admin.upload_data(problem_id, {"chk.cpp": AB_LENIENT_CHECKER}).text)
        self.assertEqual(admin.sync(problem_id), "")
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "3")

    def test_data_waits_for_a_judger_and_is_published_by_it(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        with judgers_paused():
            self.assertIn("上传成功", admin.upload_data(problem_id, checker_problem_files()).text)
            self.assertEqual(admin.sync(problem_id, wait=False), "")
            self.assertEqual(
                db("select version, status from problem_data_versions where problem_id = %d order by version" % problem_id),
                [["1", "ready"], ["2", "pending"]],
            )
            self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "1")

            # one version at a time
            self.assertIn("please wait until a judger has checked", admin.sync(problem_id, wait=False))
            page = admin.http.get(uoj.BASE_URL + "/problem/%d/manage/data" % problem_id).text
            self.assertIn("正在等待评测机", page)

            # the task of a judger: the version, where it waits, and what to build
            task = fake_fetch()
            self.assertEqual(task["problem_id"], problem_id)
            self.assertEqual(task["problem_data_version"], 2)
            self.assertEqual(task["problem_data_prepare"], [{"type": "compile", "name": "chk", "include": True}])
            r = judge_api("/judge/download/problem/%d/2" % problem_id)
            self.assertEqual((r.status_code, sha256(r.content)), (200, task["problem_data_sha256"]))
            self.assertEqual(
                db_value("select status from problem_data_versions where id = %d" % task["prepare"]["id"]), "preparing"
            )

            # a judger that asks again has given up, the version waits for another one
            self.assertEqual(fake_fetch()["prepare"]["id"], task["prepare"]["id"])
            db(
                "update judger_info set last_heartbeat_at = now() - interval 10 minute"
                " where judger_name = '%s'" % FAKE
            )

        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "2")
        self.assertIn(
            db_value("select judger_name from problem_data_versions where problem_id = %d and version = 2" % problem_id),
            uoj.JUDGER_NAMES,
        )
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, AB_WRONG)).score, 100)

    def test_judger_that_reports_for_a_version_it_was_not_given_is_ignored(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        with judgers_paused():
            self.assertIn("上传成功", admin.upload_data(problem_id, checker_problem_files()).text)
            self.assertEqual(admin.sync(problem_id, wait=False), "")
            version_id = int(db_value(
                "select id from problem_data_versions where problem_id = %d and version = 2" % problem_id
            ))  # fmt: skip
            judge_api("/judge/submit", {"prepare_result": "1", "id": version_id, "ok": "1", "fetch_new": "0"})
            self.assertEqual(db_value("select status from problem_data_versions where id = %d" % version_id), "pending")
            self.assertEqual(db_value("select data_version from problems where id = %d" % problem_id), "1")
        self.assertEqual(uoj.wait_data_version(problem_id), "")

    def test_interactor_is_built_by_a_judger(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(interactive_problem_files())
        self.assertEqual(
            json.loads(db_value("select prepare from problem_data_versions where problem_id = %d" % problem_id)),
            [{"type": "compile", "name": "interactor", "include": True}],
        )
        j = uoj.wait_submission(admin.submit(problem_id, DOUBLE))
        self.assertEqual(j.score, 100, j)
        j = uoj.wait_submission(admin.submit(problem_id, DOUBLE_WRONG))
        self.assertEqual(j.score, 0, j)

    def test_hack_changes_the_problem_when_its_data_is_published(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files(), hackable=True)
        self.assertEqual(
            json.loads(db_value(
                "select prepare from problem_data_versions where problem_id = %d and version = 2" % problem_id
            )),
            [{"type": "compile", "name": "std"}, {"type": "compile", "name": "val", "include": True}],
        )  # fmt: skip
        submission_id = admin.submit(problem_id, AB_HACKABLE % 31337)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)

        # an input that the validator refuses is no hack
        hack_id = admin.hack(submission_id, b"31337 x\n", use_formatter=True)
        self.assertFalse(uoj.wait_hack(hack_id))
        self.assertIn("Invalid Input", bytes.fromhex(db_value("select hex(details) from hacks where id = %d" % hack_id)).decode())

        hack_id = admin.hack(submission_id, b"31337 1\n", use_formatter=True)
        self.assertTrue(uoj.wait_hack(hack_id))
        j = wait_until(
            "the hacked submission is judged again",
            lambda: (lambda j: j if j and j.score != 100 else None)(uoj.get_submission(submission_id)),
        )
        self.assertEqual(j.score, 97, j)
        version, status, created_by, reason = db(
            "select version, status, created_by, reason from problem_data_versions"
            " where problem_id = %d order by version desc limit 1" % problem_id
        )[0]
        self.assertEqual((version, status, created_by, reason), ("3", "ready", "", "hack"))


if __name__ == "__main__":
    unittest.main()
