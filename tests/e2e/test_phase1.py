"""End-to-end regression tests for the judging bugs fixed in phase 1.

They need the containers of docker-compose.yml and tests/e2e/docker-compose.e2e.yml:

    bash prepare.sh
    docker compose -f docker-compose.yml -f tests/e2e/docker-compose.e2e.yml up -d --build
    python3 -m unittest discover -s tests/e2e -v
"""

import io
import json
import os
import re
import time
import unittest
import zipfile

import uoj
from fixtures import *
from uoj import conf, db, db_value, docker_exec, judge_api, sha256, wait_until

def setUpModule():
    uoj.admin()


# ---------------------------------------------------------------------- tests


class DatabaseUpgradeTest(unittest.TestCase):
    """2.5 and 2.6: upgrades run against a database on another host, and fail loudly"""

    CLI = "php /opt/uoj/web/app/cli.php"
    # every upgrade that comes with the source, in the order they are applied
    UPGRADES = sorted(
        name for name in os.listdir(os.path.join(REPO, "web", "app", "upgrade")) if re.match(r"^\d+_", name)
    )

    def test_upgrades_were_applied_when_the_web_server_started(self):
        self.assertEqual(
            db("select name, status from upgrades order by name"), [[name, "up"] for name in self.UPGRADES]
        )

    def test_judgement_columns_are_wide_enough(self):
        types = dict(
            ((table, column), data_type)
            for table, column, data_type in db(
                "select table_name, column_name, data_type from information_schema.columns"
                " where table_schema = 'app_uoj233' and column_name in ('result', 'details', 'extra_config')"
            )
        )
        self.assertEqual(types[("submissions", "result")], "longblob")
        self.assertEqual(types[("custom_test_submissions", "result")], "longblob")
        self.assertEqual(types[("hacks", "details")], "longblob")
        self.assertEqual(db_value("select @@max_allowed_packet"), str(64 << 20))

    def test_upgrade_is_idempotent(self):
        out = docker_exec(uoj.WEB, self.CLI + " upgrade:latest")
        self.assertIn("up 1001_expand_judgement_storage: OK", out)

    def test_failed_upgrade_exits_with_an_error(self):
        bad = "/opt/uoj/web/app/upgrade/9999_e_two_e_broken"
        try:
            docker_exec(uoj.WEB, "mkdir %s && echo 'THIS IS NOT SQL;' > %s/up.sql" % (bad, bad))
            p = uoj.run("docker", "exec", uoj.WEB, "sh", "-c", self.CLI + " upgrade:latest", check=False)
        finally:
            docker_exec(uoj.WEB, "rm -rf " + bad)
        self.assertNotEqual(p.returncode, 0)
        self.assertIn("run sql failed", p.stderr.decode())
        self.assertEqual(db_value("select count(*) from upgrades where name like '9999%'"), "0")

    def test_invalid_command_exits_with_an_error(self):
        p = uoj.run("docker", "exec", uoj.WEB, "sh", "-c", self.CLI + " no-such-task", check=False)
        self.assertNotEqual(p.returncode, 0)

    def test_upgrade_with_another_port_a_socket_and_a_strange_password(self):
        # a user whose password would break a shell command line
        password = "p\"a$s'w d\\`x`"
        sql_password = password.replace("\\", "\\\\").replace("'", "\\'")
        php_password = password.replace("\\", "\\\\").replace("'", "\\'")
        network = uoj.run(
            "docker", "inspect", "-f", "{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}", uoj.DB
        ).stdout.decode().strip()  # fmt: skip

        config = "/tmp/uoj-e2e-alt-config-%d.php"
        uoj.run("docker", "rm", "-f", "uoj-db-alt", check=False)
        uoj.run("docker", "volume", "rm", "-f", "uoj-e2e-alt-socket", check=False)
        uoj.run(
            "docker", "run", "-d", "--name", "uoj-db-alt", "--network", network,
            "-e", "MYSQL_ROOT_PASSWORD=root", "-v", "uoj-e2e-alt-socket:/var/run/mysqld",
            "ghcr.io/universaloj/uoj-db:latest", "--port=3307",
        )  # fmt: skip
        try:
            def alt_db(sql, check=True):
                return uoj.run(
                    "docker", "exec", "-i", "uoj-db-alt", "mysql", "-uroot", "-proot", "-P3307",
                    "-h127.0.0.1", "-N", "-B", "app_uoj233", stdin=sql.encode(), check=check,
                )  # fmt: skip

            wait_until(
                "the other database is ready",
                lambda: alt_db("select count(*) from submissions", check=False).returncode == 0,
                timeout=180, interval=2,
            )  # fmt: skip
            alt_db(
                "create user 'uoj'@'%%' identified by '%s'; grant all on app_uoj233.* to 'uoj'@'%%';"
                % sql_password
            )

            def upgrade(n, database):
                path = config % n
                with open(path, "w") as f:
                    f.write(
                        "<?php\nreturn ['database' => %s + ['database' => 'app_uoj233', 'username' => 'uoj',"
                        " 'password' => '%s']];\n" % (database, php_password)
                    )
                os.chmod(path, 0o644)
                return uoj.run(
                    "docker", "run", "--rm", "--network", network,
                    "-v", "%s:/opt/uoj/web/app/.config.php:ro" % path,
                    "-v", "uoj-e2e-alt-socket:/var/run/mysqld",
                    "ghcr.io/universaloj/uoj-web:latest", "sh", "-c", self.CLI + " upgrade:latest",
                    check=False,
                )  # fmt: skip

            p = upgrade(1, "['host' => 'uoj-db-alt', 'port' => 3307, 'socket' => '']")
            self.assertEqual(p.returncode, 0, p.stdout.decode() + p.stderr.decode())
            self.assertIn("up 1001_expand_judgement_storage: DONE", p.stdout.decode())
            self.assertEqual(
                alt_db("select name, status from upgrades order by name").stdout.decode().split(),
                [word for name in self.UPGRADES for word in (name, "up")],
            )

            p = upgrade(2, "['host' => 'no-such-host', 'socket' => '/var/run/mysqld/mysqld.sock']")
            self.assertEqual(p.returncode, 0, p.stdout.decode() + p.stderr.decode())
            self.assertIn("up 1001_expand_judgement_storage: OK", p.stdout.decode())

            # the default port is not the one this server listens on
            p = upgrade(3, "['host' => 'uoj-db-alt', 'socket' => '']")
            self.assertNotEqual(p.returncode, 0)
        finally:
            uoj.run("docker", "rm", "-f", "uoj-db-alt", check=False)
            uoj.run("docker", "volume", "rm", "-f", "uoj-e2e-alt-socket", check=False)


class JudgeApiTest(unittest.TestCase):
    """2.3 and 2.7: what judgers download, and how they are told apart from strangers"""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())

    def test_wrong_password_is_rejected_explicitly(self):
        for path in ("/judge/submit", "/judge/download/problem/%d" % self.problem_id, "/judge/sync-judge-client"):
            r = judge_api(path, auth={"judger_name": "e2e_fake_judger", "password": "wrong"})
            self.assertEqual(r.status_code, 403, path)
            self.assertEqual(r.text, "judger authentication failed", path)

    def test_unknown_judger_is_rejected_explicitly(self):
        r = judge_api("/judge/submit", auth={"judger_name": "nobody", "password": ""})
        self.assertEqual(r.status_code, 403)

    def test_missing_problem_is_not_found(self):
        self.assertEqual(judge_api("/judge/download/problem/999999").status_code, 404)

    def test_judger_downloads_the_complete_data(self):
        r = judge_api("/judge/download/problem/%d" % self.problem_id)
        self.assertEqual(r.status_code, 200)
        self.assertEqual(sha256(r.content), uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % self.problem_id))
        self.assertEqual(r.headers["X-UOJ-SHA256"], sha256(r.content))
        self.assertNotEqual(
            sha256(r.content), uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d/download.zip" % self.problem_id)
        )

        names = zipfile.ZipFile(io.BytesIO(r.content)).namelist()
        for name in ("problem.conf", "input1.txt", "output3.txt", "ex_input1.txt", "download.zip"):
            self.assertIn("%d/%s" % (self.problem_id, name), names)

    def test_archive_and_folder_carry_the_same_modification_time(self):
        r = judge_api("/judge/download/problem/%d" % self.problem_id)
        extra = zipfile.ZipFile(io.BytesIO(r.content)).getinfo("%d/" % self.problem_id).extra
        folder_mtime = int(docker_exec(uoj.WEB, "stat -c %%Y /var/uoj_data/%d" % self.problem_id))
        # look for the extended timestamp of the entry: tag "UT", size, flags, modification time
        archive_mtime = None
        while len(extra) >= 4:
            size = int.from_bytes(extra[2:4], "little")
            if extra[:2] == b"UT":
                archive_mtime = int.from_bytes(extra[5:9], "little")
            extra = extra[4 + size :]
        self.assertEqual(archive_mtime, folder_mtime)

    def test_sync_leaves_no_staging_folder(self):
        self.assertEqual(uoj.admin().sync(self.problem_id), "")
        self.assertEqual(docker_exec(uoj.WEB, "ls /var/uoj_data | grep prepare || true").strip(), "")


class MemoryLimitTest(unittest.TestCase):
    """2.8: a submission, a custom test and an extra test enforce the same memory limit"""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(echo_problem_files())

    def program(self, trigger, megabytes):
        return ECHO_ALLOCATING % {"trigger": trigger, "megabytes": megabytes}

    def test_submission_over_the_limit(self):
        j = uoj.wait_submission(uoj.admin().submit(self.problem_id, self.program(3, 120)))
        self.assertEqual(j.infos, ["Accepted", "Accepted", "Memory Limit Exceeded"], j)

    def test_custom_test_over_the_limit(self):
        j = uoj.wait_custom_test(uoj.admin().custom_test(self.problem_id, self.program(3, 120), "3\n"))
        self.assertEqual(j.infos, ["Memory Limit Exceeded"], j)

    def test_extra_test_over_the_limit(self):
        j = uoj.wait_submission(uoj.admin().submit(self.problem_id, self.program(1000, 120)))
        self.assertEqual(j.infos, ["Accepted"] * 3 + ["Extra Test Failed : Memory Limit Exceeded on 1"], j)
        self.assertEqual(j.score, 97, j)

    def test_all_three_accept_a_program_under_the_limit(self):
        j = uoj.wait_submission(uoj.admin().submit(self.problem_id, self.program(3, 40)))
        self.assertEqual(j.score, 100, j)
        self.assertGreater(j.used_memory, 40 << 10)
        self.assertLess(j.used_memory, 64 << 10)

        j = uoj.wait_custom_test(uoj.admin().custom_test(self.problem_id, self.program(3, 40), "3\n"))
        self.assertEqual(j.infos, ["Success"], j)

        j = uoj.wait_submission(uoj.admin().submit(self.problem_id, self.program(1000, 40)))
        self.assertEqual(j.score, 100, j)

    def test_explicit_stack_limit_is_enforced(self):
        j = uoj.wait_submission(uoj.admin().submit(self.problem_id, AB_DEEP_RECURSION))
        self.assertEqual(j.infos, ["Runtime Error"] * 3, j)


class LargeResultTest(unittest.TestCase):
    """2.5: the result of a judgement may be larger than 64 KiB"""

    N_TESTS = 800

    def give_to_the_fake_judger(self, submission_id):
        # a result is only taken from the judger that the submission was given to, and only
        # while that judger is known to be alive
        judge_api("/judge/submit", {"heartbeat": "1"})
        db(
            "update submissions set status = 'Judging', judger_name = '%s' where id = %d"
            % (uoj.FAKE_JUDGER["judger_name"], submission_id)
        )

    def post_result(self, submission_id, details):
        self.give_to_the_fake_judger(submission_id)
        result = {"score": 100, "time": 1, "memory": 1, "details": details, "status": "Judged"}
        r = judge_api("/judge/submit", {
            "submit": "1", "fetch_new": "", "id": submission_id, "result": json.dumps(result),
        })  # fmt: skip
        self.assertEqual(r.status_code, 200)
        self.assertEqual(r.text, "Nothing to judge")
        return uoj.get_submission(submission_id)

    def test_problem_with_many_tests(self):
        files = {
            "problem.conf": conf(
                use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=self.N_TESTS, n_ex_tests=0,
                n_sample_tests=0, input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
                time_limit=1, memory_limit=256,
            ),  # fmt: skip
        }
        for num in range(1, self.N_TESTS + 1):
            files["input%d.txt" % num] = "%d %d\n" % (num, num)
            files["output%d.txt" % num] = "%d\n" % (2 * num)
        problem_id = uoj.admin().create_problem(files)

        j = uoj.wait_submission(uoj.admin().submit(problem_id, AB), timeout=900)
        self.assertGreater(len(j.raw_result), 65535)
        self.assertEqual(j.infos, ["Accepted"] * self.N_TESTS, j)

    def test_result_of_several_megabytes_is_stored_intact(self):
        problem_id = uoj.admin().create_problem(ab_problem_files())
        submission_id = uoj.admin().submit(problem_id, AB)
        uoj.wait_submission(submission_id)

        details = "<tests>" + '<test num="1" score="100" info="Accepted" time="1" memory="1"><res>%s</res></test>' % ("x" * 4000) * 1000 + "</tests>"
        j = self.post_result(submission_id, details)
        self.assertEqual(j.details, details)
        self.assertEqual(j.score, 100)

    def test_result_that_the_database_refuses_does_not_block_the_submission(self):
        problem_id = uoj.admin().create_problem(ab_problem_files())
        submission_id = uoj.admin().submit(problem_id, AB)
        uoj.wait_submission(submission_id)

        details = "<tests>" + "x" * (3 << 20) + "</tests>"
        db("set global max_allowed_packet = %d" % (1 << 20))
        try:
            j = self.post_result(submission_id, details)
        finally:
            db("set global max_allowed_packet = %d" % (64 << 20))
        self.assertIsNotNone(j, "the submission is still being judged")
        self.assertEqual(j.score, 100)
        self.assertIn("too large to be stored", j.details)

    def test_invalid_result_is_a_failed_judgement(self):
        problem_id = uoj.admin().create_problem(ab_problem_files())
        submission_id = uoj.admin().submit(problem_id, AB)
        uoj.wait_submission(submission_id)

        self.give_to_the_fake_judger(submission_id)
        r = judge_api("/judge/submit", {"submit": "1", "fetch_new": "", "id": submission_id, "result": "'; --"})
        self.assertEqual(r.status_code, 200)
        j = uoj.get_submission(submission_id)
        self.assertEqual(j.error, "Judgement Failed")
        self.assertIsNone(j.score)


class ProblemDataFormTest(unittest.TestCase):
    """3.1: the forms that change the data of a problem"""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())
        cls.upload_dir = "/var/uoj_data/upload/%d" % cls.problem_id

    # the form that says how a problem is judged: its tests are found by the names of its files
    SETTINGS = dict(
        form="judge_settings", type="traditional", time_limit="1", memory_limit="256", checker="ncmp",
        scoring="per_test", n_samples="1",
    )  # fmt: skip

    def post_settings(self, token=True, **overrides):
        settings = dict(self.SETTINGS)
        settings.update(overrides)
        return uoj.admin().post("/problem/%d/manage/data" % self.problem_id, settings, token=token)

    def test_upload_without_a_token_is_refused(self):
        before = uoj.tree_sha256(uoj.WEB, self.upload_dir)
        r = uoj.admin().upload_data(self.problem_id, {"evil.txt": "evil\n"}, token=False)
        self.assertIn("This page has expired.", r.text)
        self.assertEqual(uoj.tree_sha256(uoj.WEB, self.upload_dir), before)

    def test_settings_without_a_token_are_refused(self):
        before = uoj.tree_sha256(uoj.WEB, self.upload_dir)
        r = self.post_settings(token=False, time_limit="9")
        self.assertIn("This page has expired.", r.text)
        self.assertEqual(uoj.tree_sha256(uoj.WEB, self.upload_dir), before)

    def test_settings_can_not_add_lines_to_problem_conf(self):
        before = uoj.tree_sha256(uoj.WEB, self.upload_dir)
        for name, value in (
            ("time_limit", "1\nuse_builtin_judger off"),
            ("memory_limit", "256\ntime_limit 100"),
            ("checker", "ncmp extra"),
            ("type", "traditional\nmulti_pass 2"),
            ("n_samples", "1\nn_tests 99"),
        ):
            r = self.post_settings(**{name: value})
            self.assertIn('id="judge-settings-error"', r.text, name)
            self.assertEqual(uoj.tree_sha256(uoj.WEB, self.upload_dir), before, name)

    def test_valid_settings_are_written(self):
        r = self.post_settings(time_limit="2", memory_limit="128")
        self.assertEqual(r.status_code, 302, uoj.text_of(r.text)[-300:])
        # saving them has the data published with them
        self.assertEqual(uoj.wait_data_version(self.problem_id), "")
        self.assertEqual(published_conf(self.problem_id)["time_limit"], "2")
        written = docker_exec(uoj.WEB, "cat %s/problem.conf" % self.upload_dir)
        self.assertEqual(written, conf(
            use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=3, n_ex_tests=1, n_sample_tests=1,
            input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
            time_limit=2, memory_limit=128,
        ))  # fmt: skip


class BinaryHackTest(unittest.TestCase):
    """2.4, and 2.2 for a problem with the builtin judger: a hack with binary data becomes an
    extra test byte for byte, and the accepted submissions are judged again"""

    def test_binary_hack_becomes_an_extra_test(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(
            count_bytes_problem_files(),
            extra_config={"view_content_type": "ALL", "view_details_type": "ALL", "dont_use_formatter": True},
            hackable=True,
        )
        wrong = admin.submit(problem_id, COUNT_BYTES_WRONG)
        correct = admin.submit(problem_id, COUNT_BYTES)
        self.assertEqual(uoj.wait_submission(wrong).score, 100)
        self.assertEqual(uoj.wait_submission(correct).score, 100)

        hack_id = admin.hack(wrong, BINARY_HACK)
        self.assertTrue(uoj.wait_hack(hack_id), db("select hex(details) from hacks where id = %d" % hack_id))

        # both accepted submissions are judged again, only the wrong one fails now
        j = wait_until(
            "the hacked submission is judged again",
            lambda: (lambda j: j if j and j.score != 100 else None)(uoj.get_submission(wrong)),
        )
        self.assertEqual(j.score, 97, j)
        self.assertEqual(j.infos[-1], "Extra Test Failed : Wrong Answer on 2", j)
        self.assertEqual(uoj.wait_submission(correct).score, 100)

        # the extra test is the hack, byte for byte, in the upload folder and in the published data
        for folder in ("/var/uoj_data/upload/%d" % problem_id, "/var/uoj_data/%d" % problem_id):
            self.assertEqual(uoj.file_sha256(uoj.WEB, folder + "/ex_input2.txt"), sha256(BINARY_HACK), folder)
            self.assertEqual(docker_exec(uoj.WEB, "cat %s/ex_output2.txt" % folder), "%d\n" % len(BINARY_HACK))
        self.assertEqual(published_conf(problem_id)["n_ex_tests"], "2")

        # nobody has to be told about a hack that was applied
        self.assertEqual(db_value("select count(*) from user_system_msg where title like 'Hack #%d %%'" % hack_id), "0")

        # every judger that has fetched the new data has the same data as the web server
        slow = [admin.submit(problem_id, COUNT_BYTES) for _ in range(6)]
        for submission_id in slow:
            self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        current = uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % problem_id)
        up_to_date = [judger for judger in uoj.JUDGERS if downloaded_sha256(judger, problem_id) == current]
        self.assertTrue(up_to_date)
        for judger in up_to_date:
            assert_judger_has_data_of_web(self, judger, problem_id)


class CustomJudgerHackTest(unittest.TestCase):
    """2.2: hacks of a problem with a custom judger"""

    def test_hacks_of_a_problem_with_a_custom_judger(self):
        admin = uoj.admin()
        manager = uoj.manager()

        problem_id = admin.create_problem(custom_judger_problem_files())

        # a super user can enable hacks, they are on once a judger has built the data for them
        self.assertEqual(admin.toggle_hackable(problem_id), "")
        self.assertEqual(db_value("select hackable from problems where id = %d" % problem_id), "1")
        fingerprint = json.loads(db_value("select extra_config from problems where id = %d" % problem_id)).get(
            "custom_judger_fingerprint"
        )
        self.assertRegex(fingerprint or "", "^[0-9a-f]{64}$")

        first = admin.submit(problem_id, AB_HACKABLE % 12345)
        second = admin.submit(problem_id, AB_HACKABLE % 777)
        correct = admin.submit(problem_id, AB)
        for submission_id in (first, second, correct):
            j = uoj.wait_submission(submission_id)
            self.assertEqual(j.score, 100, j)

        # a successful hack adds an extra test and the accepted submissions are judged again
        hack_id = admin.hack(first, b"12345 1\n", use_formatter=True)
        self.assertTrue(uoj.wait_hack(hack_id), db("select hex(details) from hacks where id = %d" % hack_id))
        j = wait_until(
            "the hacked submission is judged again",
            lambda: (lambda j: j if j and j.score != 100 else None)(uoj.get_submission(first)),
        )
        self.assertEqual(j.score, 97, j)
        self.assertEqual(published_conf(problem_id)["n_ex_tests"], "2")
        self.assertEqual(uoj.wait_submission(second).score, 100)
        self.assertEqual(uoj.wait_submission(correct).score, 100)
        self.assertEqual(db_value("select count(*) from user_system_msg where title like 'Hack #%d %%'" % hack_id), "0")

        # a manager who is not a super user changes what the judger is built from
        db("insert into problems_permissions (username, problem_id) values ('%s', %d)" % (uoj.MANAGER[0], problem_id))
        r = manager.upload_data(problem_id, {"Makefile": CUSTOM_JUDGER_MAKEFILE + "\n# changed by a manager\n"})
        self.assertIn("上传成功", r.text)
        self.assertIn("use_builtin_judger must be on", manager.sync(problem_id))

        # now a successful hack must not get the changed judger built
        published = uoj.tree_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id)
        hack_id = admin.hack(second, b"777 1\n", use_formatter=True)
        self.assertTrue(uoj.wait_hack(hack_id), db("select hex(details) from hacks where id = %d" % hack_id))

        # the people who can fix it are told
        wait_until(
            "the managers are told about the hack",
            lambda: db("select receiver from user_system_msg where title like 'Hack #%d %%'" % hack_id),
        )
        receivers = sorted(
            row[0] for row in db("select receiver from user_system_msg where title like 'Hack #%d %%'" % hack_id)
        )
        self.assertEqual(receivers, sorted([uoj.ADMIN[0], uoj.MANAGER[0]]))
        self.assertEqual(uoj.tree_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id), published)
        self.assertEqual(db_value("select status from submissions where id = %d" % second), "Judged")
        self.assertEqual(uoj.get_submission(second).score, 100)
        content = db_value("select content from user_system_msg where title like 'Hack #%d %%' limit 1" % hack_id)
        self.assertIn("use_builtin_judger must be on", content)

        # a super user approves the change by syncing, which also publishes the extra test of the hack
        self.assertEqual(admin.sync(problem_id), "")
        self.assertEqual(published_conf(problem_id)["n_ex_tests"], "3")
        self.assertNotEqual(
            json.loads(db_value("select extra_config from problems where id = %d" % problem_id))[
                "custom_judger_fingerprint"
            ],
            fingerprint,
        )

    def test_manager_can_not_sync_a_custom_judger(self):
        admin = uoj.admin()
        manager = uoj.manager()
        problem_id = admin.new_problem()
        db("insert into problems_permissions (username, problem_id) values ('%s', %d)" % (uoj.MANAGER[0], problem_id))
        # a judger that no super user has seen: approval goes by the content of the files, and
        # other tests have the very files of the fixture approved
        files = custom_judger_problem_files()
        files["Makefile"] += "\n# nobody approved this\n"
        r = manager.upload_data(problem_id, files)
        self.assertIn("上传成功", r.text)
        self.assertIn("use_builtin_judger must be on", manager.sync(problem_id))
        self.assertEqual(docker_exec(uoj.WEB, "ls /var/uoj_data/%d" % problem_id).strip(), "")


@unittest.skipUnless(len(uoj.JUDGERS) >= 2, "needs several judgers")
class SeveralJudgersTest(unittest.TestCase):
    """2.7: every judger works, and all of them judge with the data of the web server"""

    def judge_on_every_judger(self, admin, problem_id):
        submissions = [admin.submit(problem_id, AB_SLOW) for _ in range(4 * len(uoj.JUDGERS) + 2)]
        for submission_id in submissions:
            j = uoj.wait_submission(submission_id)
            self.assertEqual(j.score, 100, j)
        return submissions

    def test_all_judgers_judge_with_the_same_data(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())

        submissions = self.judge_on_every_judger(admin, problem_id)
        expected = uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % problem_id)
        for judger in uoj.JUDGERS:
            self.assertEqual(downloaded_sha256(judger, problem_id), expected, judger)
            assert_judger_has_data_of_web(self, judger, problem_id)

        # the work was shared, and it is known who judged what
        judged_by = set(
            row[0] for row in db(
                "select judger_name from submissions where id in (%s)" % ", ".join(map(str, submissions))
            )
        )  # fmt: skip
        self.assertEqual(judged_by, set(uoj.JUDGER_NAMES))

        # new data reaches every judger that judges the problem again
        files = ab_problem_files()
        files["input1.txt"] = "40 2\n"
        files["output1.txt"] = "42\n"
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        self.assertEqual(admin.sync(problem_id), "")
        expected = uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % problem_id)

        self.judge_on_every_judger(admin, problem_id)
        for judger in uoj.JUDGERS:
            self.assertEqual(downloaded_sha256(judger, problem_id), expected, judger)
            assert_judger_has_data_of_web(self, judger, problem_id)
            self.assertEqual(
                docker_exec(judger, "cat /opt/uoj_judger/uoj_judger/data/%d/input1.txt" % problem_id), "40 2\n"
            )


if __name__ == "__main__":
    unittest.main()
