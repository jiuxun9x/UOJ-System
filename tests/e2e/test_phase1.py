"""End-to-end regression tests for the judging bugs fixed in phase 1.

They need the containers of docker-compose.yml and tests/e2e/docker-compose.e2e.yml:

    bash prepare.sh
    docker compose -f docker-compose.yml -f tests/e2e/docker-compose.e2e.yml up -d --build
    python3 -m unittest discover -s tests/e2e -v
"""

import json
import os
import re
import time
import unittest
import zipfile
import io

import uoj
from uoj import conf, db, db_value, docker_exec, judge_api, sha256, wait_until

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# ---------------------------------------------------------------------- programs

AB = r"""
#include <cstdio>
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    printf("%lld\n", a + b);
}
"""

AB_WRONG = AB.replace("a + b", "a + b + 1")

# gives a wrong answer for one value of a only, so it passes every test until it is hacked
AB_HACKABLE = r"""
#include <cstdio>
int main() {
    long long a, b;
    scanf("%%lld%%lld", &a, &b);
    printf("%%lld\n", a == %d ? 0 : a + b);
}
"""

# burns a few tenths of a second on every test, to keep a judger busy
AB_SLOW = r"""
#include <cstdio>
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    volatile unsigned long long x = 0;
    for (int i = 0; i < 150000000; i++) x = x + 1;
    printf("%lld\n", a + b);
}
"""

# the three ways a program usually dies: SIGSEGV, SIGFPE and SIGABRT
AB_NULL_POINTER = r"""
#include <cstdio>
int main() {
    int * volatile p = nullptr;
    printf("%d\n", *p);
}
"""

AB_DIVISION_BY_ZERO = r"""
#include <cstdio>
int main() {
    volatile int zero = 0;
    printf("%d\n", 100 / zero);
}
"""

AB_ABORT = r"""
#include <cstdio>
#include <cstdlib>
int main() {
    puts("0");
    abort();
}
"""

AB_TIME_LIMIT = r"""
int main() {
    volatile unsigned long long x = 0;
    while (true) x = x + 1;
}
"""

AB_COMPILE_ERROR = "int main() { return undeclared; }\n"

# uses about 90 MiB of stack
AB_DEEP_RECURSION = r"""
#include <cstdio>
int f(int n);
int (* volatile fp)(int) = f;
int f(int n) {
    volatile char pad[200];
    pad[0] = (char)n;
    if (n == 0) return 0;
    int r = fp(n - 1);
    return r + (pad[0] & 0);
}
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    printf("%lld\n", a + b + f(400000));
}
"""

# a local array of 100 MiB
AB_LARGE_LOCAL_ARRAY = r"""
#include <cstdio>
int main() {
    volatile char big[100 << 20];
    for (int i = 0; i < (100 << 20); i += 4096) big[i] = 1;
    long long a, b;
    scanf("%lld%lld", &a, &b);
    printf("%lld\n", a + b + big[4096] - 1);
}
"""

AB_VALIDATOR = r"""
#include "testlib.h"
int main(int argc, char **argv) {
    registerValidation(argc, argv);
    inf.readInt(0, 1000000000, "a");
    inf.readSpace();
    inf.readInt(0, 1000000000, "b");
    inf.readEoln();
    inf.readEof();
}
"""

# prints k, but first keeps allocating memory until it holds `megabytes` MiB when k is `trigger`
ECHO_ALLOCATING = r"""
#include <cstdio>
#include <cstdlib>
#include <cstring>
// the blocks are kept where the compiler can not prove that they are unused
char * volatile blocks[128];
int main() {
    int k;
    scanf("%%d", &k);
    int n = 0, sum = 0;
    if (k == %(trigger)d) {
        for (; n < %(megabytes)d / 8; n++) {
            char *p = (char *)malloc(8 << 20);
            if (p == NULL) return 1;
            memset(p, 1, 8 << 20);
            blocks[n] = p;
        }
    }
    for (int i = 0; i < n; i++) sum += blocks[i][4096];
    printf("%%d\n", k + sum - n);
}
"""

COUNT_BYTES = r"""
#include <cstdio>
int main() {
    static char buf[1 << 16];
    long long n = 0;
    size_t got;
    while ((got = fread(buf, 1, sizeof(buf), stdin)) > 0) n += got;
    printf("%lld\n", n);
}
"""

# stops at the first byte 0xff, which a signed char can not tell from EOF
COUNT_BYTES_WRONG = r"""
#include <cstdio>
int main() {
    long long n = 0;
    char c;
    while ((c = getchar()) != EOF) n++;
    printf("%lld\n", n);
}
"""

ACCEPT_ANYTHING = r"""
#include <cstdio>
int main() {
    static char buf[1 << 16];
    while (fread(buf, 1, sizeof(buf), stdin) > 0);
    return 0;
}
"""

CUSTOM_JUDGER_MAKEFILE = """INCLUDE_PATH ?= .
CXXFLAGS = -I$(INCLUDE_PATH) -O2 -std=c++17

all: judger std val

%: %.cpp
\t$(CXX) $(CXXFLAGS) $< -o $@
"""

# bytes that a text mode reader or the formatter would reject or rewrite
BINARY_HACK = b"ab\x00cd\xff\xfe\r\nline\r\n\x80\xc3\x28 end  \n\x00"


# ---------------------------------------------------------------------- problems


def ab_problem_files(**overrides):
    settings = dict(
        use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=3, n_ex_tests=1, n_sample_tests=1,
        input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
        time_limit=1, memory_limit=256,
    )  # fmt: skip
    settings.update(overrides)
    files = {"problem.conf": conf(**settings), "std.cpp": AB, "val.cpp": AB_VALIDATOR}
    for num, (a, b) in enumerate([(1, 2), (1000, 2000), (999999999, 1)], start=1):
        files["input%d.txt" % num] = "%d %d\n" % (a, b)
        files["output%d.txt" % num] = "%d\n" % (a + b)
    files["ex_input1.txt"] = "5 7\n"
    files["ex_output1.txt"] = "12\n"
    return files


def echo_problem_files():
    files = {
        "problem.conf": conf(
            use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=3, n_ex_tests=1, n_sample_tests=1,
            input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
            time_limit=1, memory_limit=64, stack_limit=8,
        ),  # fmt: skip
        "ex_input1.txt": "1000\n",
        "ex_output1.txt": "1000\n",
    }
    for k in (1, 2, 3):
        files["input%d.txt" % k] = "%d\n" % k
        files["output%d.txt" % k] = "%d\n" % k
    return files


def count_bytes_problem_files():
    files = {
        "problem.conf": conf(
            use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=2, n_ex_tests=1, n_sample_tests=0,
            input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
            time_limit=1, memory_limit=256,
        ),  # fmt: skip
        "std.cpp": COUNT_BYTES,
        "val.cpp": ACCEPT_ANYTHING,
    }
    for name, data in (("input1.txt", "hello\n"), ("input2.txt", "a b c\nd e f\n"), ("ex_input1.txt", "x\n")):
        files[name] = data
        files[name.replace("input", "output")] = "%d\n" % len(data)
    return files


def custom_judger_problem_files():
    files = ab_problem_files(use_builtin_judger="off")
    with open(os.path.join(REPO, "judger/uoj_judger/builtin/judger/judger.cpp")) as f:
        files["judger.cpp"] = f.read()
    files["Makefile"] = CUSTOM_JUDGER_MAKEFILE
    return files


def published_conf(problem_id):
    text = docker_exec(uoj.WEB, "cat /var/uoj_data/%d/problem.conf" % problem_id)
    return dict(line.split(None, 1) for line in text.splitlines() if line.strip())


def downloaded_sha256(judger, problem_id):
    """the SHA256 of the data of a problem that a judger downloaded last, or None"""
    found = re.findall(
        r"downloaded problem data: problem=%d size=\d+ sha256=([0-9a-f]{64})" % problem_id,
        uoj.judger_log(judger),
    )
    return found[-1] if found else None


def setUpModule():
    uoj.admin()


# ---------------------------------------------------------------------- tests


class DatabaseUpgradeTest(unittest.TestCase):
    """2.5 and 2.6: upgrades run against a database on another host, and fail loudly"""

    CLI = "php /opt/uoj/web/app/cli.php"

    def test_upgrades_were_applied_when_the_web_server_started(self):
        self.assertEqual(db("select name, status from upgrades"), [["1001_expand_judgement_storage", "up"]])

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
                alt_db("select name, status from upgrades").stdout.decode().split(),
                ["1001_expand_judgement_storage", "up"],
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


class VerdictTest(unittest.TestCase):
    """2.1 and a first slice of the conformance tests"""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())

    def judge(self, code):
        return uoj.wait_submission(uoj.admin().submit(self.problem_id, code))

    def test_accepted(self):
        j = self.judge(AB)
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 3 + ["Extra Test Passed"], j)

    def test_wrong_answer(self):
        j = self.judge(AB_WRONG)
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 3, j)

    def test_runtime_error(self):
        for code in (AB_NULL_POINTER, AB_DIVISION_BY_ZERO, AB_ABORT):
            j = self.judge(code)
            self.assertEqual(j.infos, ["Runtime Error"] * 3, j)
            self.assertEqual(j.score, 0, j)

    def test_time_limit_exceeded(self):
        j = self.judge(AB_TIME_LIMIT)
        self.assertEqual(j.infos, ["Time Limit Exceeded"] * 3, j)

    def test_memory_limit_exceeded(self):
        j = self.judge(ECHO_ALLOCATING % {"trigger": 1, "megabytes": 400})
        self.assertEqual(j.infos[0], "Memory Limit Exceeded", j)

    def test_compile_error(self):
        j = self.judge(AB_COMPILE_ERROR)
        self.assertEqual(j.error, "Compile Error", j)
        self.assertIsNone(j.score)

    def test_stack_is_as_large_as_the_memory_limit_by_default(self):
        j = self.judge(AB_DEEP_RECURSION)
        self.assertEqual(j.score, 100, j)
        j = self.judge(AB_LARGE_LOCAL_ARRAY)
        self.assertEqual(j.score, 100, j)
        self.assertGreater(j.used_memory, 100 << 10)


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

    def post_result(self, submission_id, details):
        db("update submissions set status = 'Judging' where id = %d" % submission_id)
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

        db("update submissions set status = 'Judging' where id = %d" % submission_id)
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

    SETTINGS = dict(
        problem_settings_file_submit="submit", use_builtin_checker="ncmp", n_tests="3", n_ex_tests="1",
        n_sample_tests="1", input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
        time_limit="1", memory_limit="256",
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
            ("input_pre", "input\nuse_builtin_judger off"),
            ("memory_limit", "256\ntime_limit 100"),
            ("output_suf", "txt extra"),
        ):
            r = self.post_settings(**{name: value})
            self.assertIn("添加配置文件失败", r.text, name)
            self.assertEqual(uoj.tree_sha256(uoj.WEB, self.upload_dir), before, name)

    def test_valid_settings_are_written(self):
        r = self.post_settings(time_limit="2", memory_limit="128")
        self.assertIn("替换成功", r.text)
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

        # every judger that has the data has the same data as the web server
        slow = [admin.submit(problem_id, COUNT_BYTES) for _ in range(6)]
        for submission_id in slow:
            self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        expected = uoj.tree_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id)
        for judger in uoj.JUDGERS:
            data = "/opt/uoj_judger/uoj_judger/data/%d" % problem_id
            if docker_exec(judger, "test -d %s && echo yes || true" % data).strip() == "yes":
                # a copy may be out of date until the judger gets a submission of the problem
                if downloaded_sha256(judger, problem_id) == uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % problem_id):
                    self.assertEqual(uoj.tree_sha256(judger, data), expected, judger)


class CustomJudgerHackTest(unittest.TestCase):
    """2.2: hacks of a problem with a custom judger"""

    def test_hacks_of_a_problem_with_a_custom_judger(self):
        admin = uoj.admin()
        manager = uoj.manager()

        problem_id = admin.create_problem(custom_judger_problem_files())
        fingerprint = json.loads(db_value("select extra_config from problems where id = %d" % problem_id)).get(
            "custom_judger_fingerprint"
        )
        self.assertRegex(fingerprint or "", "^[0-9a-f]{64}$")

        # a super user can enable hacks
        self.assertEqual(admin.toggle_hackable(problem_id), "")
        self.assertEqual(db_value("select hackable from problems where id = %d" % problem_id), "1")

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
        r = manager.upload_data(problem_id, custom_judger_problem_files())
        self.assertIn("上传成功", r.text)
        self.assertIn("use_builtin_judger must be on", manager.sync(problem_id))
        self.assertEqual(docker_exec(uoj.WEB, "ls /var/uoj_data/%d" % problem_id).strip(), "")


class TwoJudgersTest(unittest.TestCase):
    """2.7: every judger works, and all of them judge with the data of the web server"""

    def test_both_judgers_judge_with_the_same_data(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())

        started = time.time()
        submissions = [admin.submit(problem_id, AB_SLOW) for _ in range(10)]
        for submission_id in submissions:
            j = uoj.wait_submission(submission_id)
            self.assertEqual(j.score, 100, j)
        elapsed = time.time() - started

        expected = uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % problem_id)
        tree = uoj.tree_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id)
        for judger in uoj.JUDGERS:
            self.assertEqual(downloaded_sha256(judger, problem_id), expected, "%s after %ds" % (judger, elapsed))
            self.assertEqual(
                uoj.tree_sha256(judger, "/opt/uoj_judger/uoj_judger/data/%d" % problem_id), tree, judger
            )

        # new data reaches every judger that judges the problem again
        files = ab_problem_files()
        files["input1.txt"] = "40 2\n"
        files["output1.txt"] = "42\n"
        self.assertIn("上传成功", admin.upload_data(problem_id, files).text)
        self.assertEqual(admin.sync(problem_id), "")
        expected = uoj.file_sha256(uoj.WEB, "/var/uoj_data/%d.zip" % problem_id)
        tree = uoj.tree_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id)

        submissions = [admin.submit(problem_id, AB_SLOW) for _ in range(10)]
        for submission_id in submissions:
            j = uoj.wait_submission(submission_id)
            self.assertEqual(j.score, 100, j)
        for judger in uoj.JUDGERS:
            self.assertEqual(downloaded_sha256(judger, problem_id), expected, judger)
            self.assertEqual(
                uoj.tree_sha256(judger, "/opt/uoj_judger/uoj_judger/data/%d" % problem_id), tree, judger
            )


if __name__ == "__main__":
    unittest.main()
