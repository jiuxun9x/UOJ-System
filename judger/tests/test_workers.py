"""Unit tests for judger/workers.sh, which writes the compose file of several judgers on one
machine, each on a CPU of its own, all with one judging account.

Run with: python3 -m unittest discover -s judger/tests -v
"""

import os
import re
import shutil
import stat
import subprocess
import tempfile
import unittest

WORKERS_SH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "workers.sh")
ACCOUNT = ["--url", "https://oj.example.edu.cn", "--name", "lab3", "--password", "s3cret"]


class WorkersTest(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.root)

    def topology(self, online, siblings):
        """a /sys/devices/system/cpu with these CPUs online and these groups of hyperthreads"""
        sys_cpu = os.path.join(self.root, "cpu")
        os.makedirs(sys_cpu, exist_ok=True)
        with open(os.path.join(sys_cpu, "online"), "w") as f:
            f.write(online + "\n")
        for group in siblings:
            for cpu in group.replace("-", ",").split(","):
                os.makedirs(os.path.join(sys_cpu, "cpu%s" % cpu, "topology"), exist_ok=True)
                with open(os.path.join(sys_cpu, "cpu%s" % cpu, "topology", "thread_siblings_list"), "w") as f:
                    f.write(group + "\n")
        return sys_cpu

    def run_workers(self, *args, sys_cpu=None):
        env = dict(os.environ)
        env.pop("JUDGER_PASSWORD", None)
        if sys_cpu is not None:
            env["UOJ_SYSFS_CPU"] = sys_cpu
        return subprocess.run(
            ["bash", WORKERS_SH] + list(args), cwd=self.root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE
        )

    def compose(self, *args, sys_cpu=None):
        p = self.run_workers(*(list(ACCOUNT) + list(args) + ["--output", "-"]), sys_cpu=sys_cpu)
        self.assertEqual(p.returncode, 0, p.stderr.decode())
        return p.stdout.decode()

    def services(self, text):
        """the services of the file: name => the lines of the service"""
        found = {}
        for block in re.split(r"\n(?=  [^ ])", text.split("\nservices:\n", 1)[1]):
            lines = block.strip("\n").split("\n")
            found[lines[0].strip().rstrip(":")] = "\n".join(lines[1:])
        return found

    def test_one_judger_on_each_cpu_that_is_named(self):
        services = self.services(self.compose("--cpus", "2-3,5"))
        self.assertEqual(sorted(services), ["uoj-judger-cpu2", "uoj-judger-cpu3", "uoj-judger-cpu5"])
        logs = set()
        for cpu in (2, 3, 5):
            service = services["uoj-judger-cpu%d" % cpu]
            self.assertIn('    cpuset: "%d"' % cpu, service)
            self.assertIn("    container_name: uoj-judger-cpu%d" % cpu, service)
            self.assertIn("      - SYS_PTRACE", service)
            self.assertIn("    tty: true", service)
            self.assertIn("      - 'UOJ_SERVER_URL=https://oj.example.edu.cn'", service)
            self.assertIn("      - 'JUDGER_NAME=lab3'", service)
            self.assertIn("      - 'JUDGER_PASSWORD=s3cret'", service)
            self.assertIn("      - 'WORKER_LABEL=cpu%d'" % cpu, service)
            self.assertNotIn("mem_limit", service)
            logs.update(re.findall(r"- '([^':]+):/opt/uoj_judger/log'", service))
        # every judger is a worker of its own: the name it keeps is in its own log folder
        self.assertEqual(logs, {"./judger-workers/cpu2", "./judger-workers/cpu3", "./judger-workers/cpu5"})

    def test_hyperthreads_of_one_core_and_the_core_of_cpu_0_are_left_out(self):
        sys_cpu = self.topology("0-7", ["0,4", "1,5", "2,6", "3,7"])
        self.assertEqual(sorted(self.services(self.compose(sys_cpu=sys_cpu))), ["uoj-judger-cpu1", "uoj-judger-cpu2", "uoj-judger-cpu3"])
        sys_cpu = self.topology("0-3", ["0-1", "2-3"])
        self.assertEqual(sorted(self.services(self.compose(sys_cpu=sys_cpu))), ["uoj-judger-cpu2"])

    def test_machine_of_one_core_judges_on_it(self):
        sys_cpu = self.topology("0-1", ["0-1"])
        self.assertEqual(sorted(self.services(self.compose(sys_cpu=sys_cpu))), ["uoj-judger-cpu0"])

    def test_how_many_how_much_memory_and_what_else(self):
        text = self.compose(
            "--cpus", "4-9", "--workers", "2", "--memory", "4g", "--image", "my-judger", "--prefix", "lab",
            "--add-host", "host.docker.internal:host-gateway", "--env", "DATA_CACHE_PROBLEMS=1000",
            "--log-dir", "/srv/judgers/",
        )  # fmt: skip
        services = self.services(text)
        self.assertEqual(sorted(services), ["lab-cpu4", "lab-cpu5"])
        service = services["lab-cpu4"]
        self.assertIn("    image: 'my-judger'", service)
        # no swap: as much as the memory
        self.assertIn("    mem_limit: 4g\n    memswap_limit: 4g", service)
        self.assertIn("    extra_hosts:\n      - 'host.docker.internal:host-gateway'", service)
        self.assertIn("      - 'DATA_CACHE_PROBLEMS=1000'", service)
        self.assertIn("      - '/srv/judgers/cpu4:/opt/uoj_judger/log'", service)

    def test_password_is_written_as_it_is(self):
        p = self.run_workers("--url", "http://10.0.0.5:8080/", "--name", "lab3", "--password", "it's #1: a\"b$HOME", "--cpus", "1", "--output", "-")
        self.assertEqual(p.returncode, 0, p.stderr.decode())
        text = p.stdout.decode()
        # quoted for YAML, and $$ for compose, which reads $ as the start of a variable
        self.assertIn("      - 'JUDGER_PASSWORD=it''s #1: a\"b$$HOME'", text)
        self.assertIn("      - 'UOJ_SERVER_URL=http://10.0.0.5:8080'", text)

    def test_file_with_the_password_is_for_its_owner_only(self):
        p = self.run_workers(*(ACCOUNT + ["--cpus", "1-2"]))
        self.assertEqual(p.returncode, 0, p.stderr.decode())
        path = os.path.join(self.root, "docker-compose.judgers.yml")
        self.assertEqual(stat.S_IMODE(os.stat(path).st_mode), 0o600)
        with open(path) as f:
            self.assertEqual(len(self.services(f.read())), 2)

    def test_what_makes_no_sense_is_refused(self):
        for args in (
            ["--name", "lab3", "--password", "x", "--cpus", "1"],
            ["--url", "https://oj.example.edu.cn", "--password", "x", "--cpus", "1"],
            ["--url", "https://oj.example.edu.cn", "--name", "lab3", "--cpus", "1"],
            ACCOUNT + ["--cpus", "3-1"],
            ACCOUNT + ["--cpus", "one"],
            ACCOUNT + ["--cpus", "1", "--workers", "0"],
            ACCOUNT + ["--cpus", "1", "--memory", "lots"],
            ACCOUNT + ["--cpus", "1", "--env", "not a setting"],
            ACCOUNT + ["--cpus", "1", "--prefix", "a b"],
            ["--url", "oj.example.edu.cn", "--name", "lab3", "--password", "x", "--cpus", "1"],
            ["--url", "https://oj.example.edu.cn", "--name", "a-b", "--password", "x", "--cpus", "1"],
            ACCOUNT + ["--frobnicate"],
        ):
            p = self.run_workers(*(args + ["--output", "-"]))
            self.assertEqual(p.returncode, 2, args)
            self.assertEqual(p.stdout, b"", args)


if __name__ == "__main__":
    unittest.main()
