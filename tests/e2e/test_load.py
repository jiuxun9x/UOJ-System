"""The verdict of a program must not depend on how busy the machine of the judger is.

See test_phase1.py for how to start the containers.
"""

import os
import re
import subprocess
import sys
import unittest

import uoj
from fixtures import *

RUN_PROGRAM = "/opt/uoj_judger/uoj_judger/run/run_program"

# uses four tenths of a second of CPU time, however fast the machine is
BUSY = r"""
#include <ctime>
int main() {
    volatile unsigned long long x = 0;
    while (clock() < CLOCKS_PER_SEC * 4 / 10) x = x + 1;
}
"""

SLEEPING = AB_SLEEPING


def setUpModule():
    uoj.admin()


def build(judger, name, code):
    uoj.run("docker", "exec", "-i", judger, "sh", "-c", "cat > /tmp/%s.cpp" % name, stdin=code.encode())
    uoj.docker_exec(judger, "cd /tmp && g++ -O2 %s.cpp -o %s" % (name, name))


def run_in_sandbox(judger, name, time_limit=1):
    """run a program in the sandbox of a judger, return its verdict, the real time it took in
    milliseconds, and what the sandbox says about it"""
    report = uoj.docker_exec(
        judger,
        "%s --res=/tmp/%s.res --in=/dev/null --out=/dev/null --err=/dev/null --tl=%d --ml=256"
        " --work-path=/tmp /tmp/%s; cat /tmp/%s.res" % (RUN_PROGRAM, name, time_limit, name, name),
    )
    verdict = int(report.split()[0])
    elapsed = re.search(r"(\d+)ms / (\d+)ms / (\d+)ms", report)
    return verdict, int(elapsed.group(3)) if elapsed else None, report


class BusyMachineTest(unittest.TestCase):
    # with this many busy processes on every CPU, a program gets a tenth of a CPU or less
    BUSY_PROCESSES_PER_CPU = 10

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(ab_problem_files())
        cls.judger = uoj.JUDGERS[0]
        build(cls.judger, "e2e_busy", BUSY)
        build(cls.judger, "e2e_sleeping", SLEEPING)

        # what the programs do on a quiet machine
        cls.quiet_busy = run_in_sandbox(cls.judger, "e2e_busy")
        cls.quiet_sleeping = run_in_sandbox(cls.judger, "e2e_sleeping")
        cls.quiet = {
            name: uoj.wait_submission(uoj.admin().submit(cls.problem_id, code))
            for name, code in cls.submissions().items()
        }

        cls.busy_processes = [
            subprocess.Popen(["sh", "-c", "while :; do :; done"])
            for _ in range(cls.BUSY_PROCESSES_PER_CPU * os.cpu_count())
        ]

    @classmethod
    def tearDownClass(cls):
        for process in cls.busy_processes:
            process.kill()
        for process in cls.busy_processes:
            process.wait()

    @staticmethod
    def submissions():
        return {
            "accepted": AB_BUSY,
            "wrong answer": AB_WRONG,
            "runtime error": AB_NULL_POINTER,
            "cpu time limit": AB_TIME_LIMIT,
            "real time limit": AB_SLEEPING,
            "memory limit": ECHO_ALLOCATING % {"trigger": 1, "megabytes": 400},
            "compile error": AB_COMPILE_ERROR,
        }

    def test_quiet_machine(self):
        verdict, elapsed, report = self.quiet_busy
        self.assertEqual(verdict, 0, report)
        self.assertLess(elapsed, 3000, report)
        verdict, elapsed, report = self.quiet_sleeping
        self.assertEqual(verdict, 4, report)
        self.assertEqual(self.quiet["accepted"].score, 100, self.quiet["accepted"])

    def test_time_spent_waiting_for_a_cpu_does_not_count(self):
        verdict, elapsed, report = run_in_sandbox(self.judger, "e2e_busy")
        self.assertEqual(verdict, 0, report)
        # the machine really was busy: the program took longer than the real time limit of three
        # seconds that comes with a time limit of one second
        self.assertGreater(elapsed, 3000, report)

    def test_time_spent_sleeping_still_counts(self):
        verdict, elapsed, report = run_in_sandbox(self.judger, "e2e_sleeping")
        self.assertEqual(verdict, 4, report)
        self.assertIn("elapsed real time limit exceeded", report)
        self.assertLess(elapsed, 15000, report)

    def test_verdicts_are_the_same_as_on_a_quiet_machine(self):
        for name, code in self.submissions().items():
            j = uoj.wait_submission(uoj.admin().submit(self.problem_id, code), timeout=900)
            quiet = self.quiet[name]
            self.assertEqual((j.score, j.error, j.infos), (quiet.score, quiet.error, quiet.infos), name)


class MemoryPressureTest(unittest.TestCase):
    """most of the memory of the machine is in use by something else"""

    ALLOCATE = (
        "import sys, time\n"
        "block = bytearray(int(sys.argv[1]))\n"
        "for i in range(0, len(block), 4096):\n"
        "    block[i] = 1\n"
        "print('ready', flush=True)\n"
        "time.sleep(3600)\n"
    )

    @classmethod
    def setUpClass(cls):
        cls.problem_id = uoj.admin().create_problem(echo_problem_files())
        with open("/proc/meminfo") as f:
            available = int(re.search(r"MemAvailable:\s+(\d+) kB", f.read()).group(1)) << 10
        # leave room for the containers, and stay away from the limits of the machine
        amount = min(available - (3 << 30), 10 << 30)
        if amount < (1 << 30):
            raise unittest.SkipTest("not enough memory to take away")
        cls.hog = subprocess.Popen([sys.executable, "-c", cls.ALLOCATE, str(amount)], stdout=subprocess.PIPE)
        assert cls.hog.stdout.readline().strip() == b"ready"

    @classmethod
    def tearDownClass(cls):
        cls.hog.kill()
        cls.hog.wait()

    def program(self, megabytes):
        return ECHO_ALLOCATING % {"trigger": 3, "megabytes": megabytes}

    def test_memory_limit_is_enforced_the_same(self):
        admin = uoj.admin()
        j = uoj.wait_submission(admin.submit(self.problem_id, self.program(120)))
        self.assertEqual(j.infos, ["Accepted", "Accepted", "Memory Limit Exceeded"], j)

        j = uoj.wait_submission(admin.submit(self.problem_id, self.program(40)))
        self.assertEqual(j.score, 100, j)
        self.assertGreater(j.used_memory, 40 << 10)
        self.assertLess(j.used_memory, 64 << 10)

        j = uoj.wait_custom_test(admin.custom_test(self.problem_id, self.program(120), "3\n"))
        self.assertEqual(j.infos, ["Memory Limit Exceeded"], j)


if __name__ == "__main__":
    unittest.main()
