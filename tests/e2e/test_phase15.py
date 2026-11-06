"""Phase 15: what a check of the whole system found, mended. Each class is one of the defects,
told by what it does wrong and kept from coming back."""

import json
import unittest

import uoj
from fixtures import AB, ab_problem_files
from uoj import db, db_value, docker_exec

# A+B in the languages of the site but C++ (which every other test submits in)
AB_IN = {
    "C11": r"""#include <stdio.h>
int main(void) {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    printf("%lld\n", a + b);
    return 0;
}
""",
    "Pascal": """var a, b: int64;
begin
    readln(a, b);
    writeln(a + b);
end.
""",
    "Python3": """import sys
a, b = map(int, sys.stdin.read().split())
print(a + b)
""",
}

# what lists the processes of a judger that run judge_client, the oldest first, as root sees them
JUDGE_CLIENT_PIDS = r"""python3 -c '
import os
for p in sorted((p for p in os.listdir("/proc") if p.isdigit()), key=int):
    try:
        cmdline = open("/proc/%s/cmdline" % p, "rb").read()
    except OSError:
        continue
    if b"judge_client" in cmdline and not cmdline.startswith(b"python3\x00-c"):
        print(p)
'"""


def judge_client_pid(judger):
    pids = docker_exec(judger, JUDGE_CLIENT_PIDS).split()
    if not pids:
        raise AssertionError("no judge_client runs in %s" % judger)
    return int(pids[0])


def judger_password(judger):
    return json.loads(docker_exec(judger, "cat /opt/uoj_judger/.conf.json"))["judger_password"]


class CompilerReadsNoOtherProcessTest(unittest.TestCase):
    """A program that is compiled can #include any file it names, and the compiler shows what it
    read: it may read nothing of the other processes of the judger in /proc, such as the
    environment of judge_client, and judge_client keeps no password in its environment."""

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())
        cls.student = uoj.client(("p15_compiler", "p15-password"))

    def test_judge_client_keeps_no_password_in_its_environment(self):
        for judger in uoj.JUDGERS:
            pid = judge_client_pid(judger)
            environment = docker_exec(judger, "tr '\\0' '\\n' < /proc/%d/environ" % pid)
            self.assertNotIn("JUDGER_PASSWORD", environment, judger)
            self.assertNotIn("SOCKET_PASSWORD", environment, judger)
            self.assertNotIn(judger_password(judger), environment, judger)

    def test_what_is_compiled_can_not_include_the_files_of_judge_client(self):
        passwords = {judger_password(judger) for judger in uoj.JUDGERS}
        pids = {judge_client_pid(judger) for judger in uoj.JUDGERS}
        # the judgers start the same way, so judge_client has the same pid in each of them, and
        # whichever judger compiles the program finds a process there that it may not look at
        refused = "Permission denied" if len(pids) == 1 else "(Permission denied|No such file)"
        for name in ("environ", "cmdline", "status"):
            code = '#include "/proc/%d/%s"\nint main() {}\n' % (min(pids), name)
            judgement = uoj.wait_submission(self.student.submit(self.problem_id, code))
            self.assertEqual(judgement.error, "Compile Error", judgement)
            self.assertRegex(judgement.details, refused, judgement)
            for password in passwords:
                self.assertNotIn(password, judgement.raw_result.decode(), name)

    def test_the_languages_compile_as_before(self):
        # the compilers read their own entry of /proc and the files of the system there
        for language, code in AB_IN.items():
            judgement = uoj.wait_submission(self.student.submit(self.problem_id, code, language=language))
            self.assertEqual(judgement.score, 100, (language, judgement))
        self.assertEqual(uoj.wait_submission(self.student.submit(self.problem_id, AB)).score, 100)


if __name__ == "__main__":
    unittest.main()
