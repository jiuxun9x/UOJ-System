"""The programs and the problems that the end-to-end tests use."""

import os

import uoj
from uoj import conf, docker_exec

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
    """the SHA256 of the data of a problem that a judger fetched last, or None"""
    events = [event for event in uoj.data_syncs(judger, problem_id) if event["result"] == "ok"]
    return events[-1]["sha256"] if events else None


def assert_judger_has_data_of_web(test, judger, problem_id):
    """every file that the web server published is the same on the judger, which has built the
    programs of the problem on top of them"""
    published = uoj.files_sha256(uoj.WEB, "/var/uoj_data/%d" % problem_id)
    copy = uoj.files_sha256(judger, "/opt/uoj_judger/uoj_judger/data/%d" % problem_id)
    test.assertEqual({name: copy.get(name) for name in published}, published, judger)




# ---------------------------------------------------------------------- more programs

# waits for an event that never comes, without using the CPU
AB_SLEEPING = r"""
#include <cstdio>
#include <sys/epoll.h>
int main() {
    struct epoll_event event;
    epoll_wait(epoll_create1(0), &event, 1, 30000);
    puts("0");
}
"""

# uses four tenths of a second of CPU time on every test, however fast the machine is
AB_BUSY = r"""
#include <cstdio>
#include <ctime>
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    volatile unsigned long long x = 0;
    while (clock() < CLOCKS_PER_SEC * 4 / 10) x = x + 1;
    printf("%lld\n", a + b);
}
"""

AB_OUTPUT_LIMIT = r"""
#include <cstdio>
int main() {
    static char buf[1 << 20];
    for (int i = 0; i < (1 << 20); i++) buf[i] = 'a';
    for (int i = 0; i < 100; i++) fwrite(buf, 1, sizeof(buf), stdout);
}
"""

AB_FORK = r"""
#include <cstdio>
#include <unistd.h>
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    if (fork() == 0) return 0;
    printf("%lld\n", a + b);
}
"""

AB_THREAD = r"""
#include <cstdio>
#include <thread>
int main() {
    long long a, b, sum = 0;
    scanf("%lld%lld", &a, &b);
    std::thread t([&] { sum = a + b; });
    t.join();
    printf("%lld\n", sum);
}
"""

AB_WRITE_FILE = r"""
#include <cstdio>
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    FILE *f = fopen("/tmp/uoj_e2e_escape.txt", "w");
    if (f != NULL) {
        fputs("escaped", f);
        fclose(f);
    }
    printf("%lld\n", a + b);
}
"""

AB_READ_SECRET = r"""
#include <cstdio>
int main() {
    long long a, b;
    scanf("%lld%lld", &a, &b);
    FILE *f = fopen("/opt/uoj_judger/.conf.json", "r");
    printf("%lld\n", f != NULL ? -1 : a + b);
}
"""

# accepts the sum, and also one more than the sum
AB_LENIENT_CHECKER = r"""
#include "testlib.h"
int main(int argc, char **argv) {
    registerTestlibCmd(argc, argv);
    long long expected = ans.readLong();
    long long found = ouf.readLong();
    if (found != expected && found != expected + 1) {
        quitf(_wa, "expected %lld or %lld, found %lld", expected, expected + 1, found);
    }
    quitf(_ok, "fine");
}
"""

# the interactor gives a number and wants twice the number back
DOUBLE_INTERACTOR = r"""
#include "testlib.h"
#include <iostream>
int main(int argc, char **argv) {
    registerInteraction(argc, argv);
    int n = inf.readInt();
    std::cout << n << std::endl;
    int answer = ouf.readInt();
    if (answer != 2 * n) {
        quitf(_wa, "expected %d, found %d", 2 * n, answer);
    }
    quitf(_ok, "fine");
}
"""

DOUBLE = r"""
#include <cstdio>
int main() {
    int n;
    scanf("%d", &n);
    printf("%d\n", 2 * n);
    fflush(stdout);
}
"""

DOUBLE_WRONG = DOUBLE.replace("2 * n", "2 * n + 1")


def checker_problem_files(checker=AB_LENIENT_CHECKER):
    """a + b with a checker of its own instead of a builtin one"""
    files = ab_problem_files()
    files["problem.conf"] = "".join(
        line + "\n" for line in files["problem.conf"].splitlines() if not line.startswith("use_builtin_checker")
    )
    files["chk.cpp"] = checker
    return files


def interactive_problem_files():
    files = {
        "problem.conf": conf(
            use_builtin_judger="on", interaction_mode="on", n_tests=2, n_ex_tests=0, n_sample_tests=0,
            input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
            time_limit=1, memory_limit=256,
        ),  # fmt: skip
        "interactor.cpp": DOUBLE_INTERACTOR,
    }
    for num, n in enumerate([21, 1000], start=1):
        files["input%d.txt" % num] = "%d\n" % n
        files["output%d.txt" % num] = "%d\n" % (2 * n)
    return files
