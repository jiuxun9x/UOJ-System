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


# ---------------------------------------------------------------------- multi-pass problems
#
# The program of a multi-pass problem is run on a test more than once. After every pass the
# checker looks at what the program wrote; when it wants another pass, it writes the input of
# that pass to nextpass.in in its work folder and says ok, and keeps what it has to remember
# in state.txt. This is the convention of Hydro.
#
# The first problem has two passes. The first pass is given numbers and writes a message of at
# most 40 zeros and ones for each of them. The checker hands the messages to the second pass
# in reverse order, and the second pass has to tell the numbers.

MESSAGES_CHECKER = r"""
#include "testlib.h"
#include <fstream>
#include <string>
#include <vector>
int main(int argc, char **argv) {
    registerTestlibCmd(argc, argv);
    std::string pass = inf.readToken();
    int t = inf.readInt();
    if (pass == "first") {
        // after the first pass: the input has the numbers, the program wrote the messages
        std::vector<long long> numbers(t);
        for (int i = 0; i < t; i++) {
            numbers[i] = inf.readLong();
        }
        std::vector<std::string> messages(t);
        for (int i = 0; i < t; i++) {
            messages[i] = ouf.readToken();
            if (messages[i].size() > 40) {
                quitf(_wa, "message %d is longer than 40 characters", i + 1);
            }
            for (char c : messages[i]) {
                if (c != '0' && c != '1') {
                    quitf(_wa, "message %d is not made of zeros and ones", i + 1);
                }
            }
        }
        std::ofstream next("nextpass.in");
        std::ofstream state("state.txt");
        next << "second\n" << t << "\n";
        for (int i = t - 1; i >= 0; i--) {
            next << messages[i] << "\n";
            // for itself: the number that the message at this place stands for
            state << numbers[i] << "\n";
        }
        next.close();
        state.close();
        quitf(_ok, "%d messages passed on", t);
    }
    // after the second pass: the input is what this checker wrote, the numbers are in its state
    std::ifstream state("state.txt");
    for (int i = 0; i < t; i++) {
        long long expected;
        if (!(state >> expected)) {
            quitf(_fail, "the state of the first pass is gone");
        }
        long long found = ouf.readLong();
        if (found != expected) {
            quitf(_wa, "number %d: expected %lld, found %lld", i + 1, expected, found);
        }
    }
    quitf(_ok, "%d numbers", t);
}
"""

MESSAGES = r"""
#include <cstdio>
#include <cstring>
#include <ctime>
int main() {
    char run[16];
    int t;
    scanf("%15s%d", run, &t);
    BURN
    if (strcmp(run, "first") == 0) {
        for (int i = 0; i < t; i++) {
            long long x;
            scanf("%lld", &x);
            for (int bit = WIDTH - 1; bit >= 0; bit--) putchar('0' + (int)(x >> bit & 1));
            putchar('\n');
        }
    } else {
        SECOND
        for (int i = 0; i < t; i++) {
            char message[64];
            scanf("%63s", message);
            int x = 0;
            for (char *c = message; *c; c++) x = x * 2 + (*c - '0');
            printf("%d\n", x);
        }
    }
}
"""


def messages_solution(width=30, burn="", second=""):
    return MESSAGES.replace("WIDTH", str(width)).replace("BURN", burn).replace("SECOND", second)


# What a program could try in order to tell its second pass something behind the back of the
# checker. The first pass of each says nothing in its messages; the size of what it writes is
# the number, in a way that is told apart from the size of anything else: 2 + 7919 * (x + 1).
MESSAGES_FIRST_PASS_OF_A_CHEAT = r"""
        int x;
        scanf("%d", &x);
        puts("0");
        for (int i = 0; i < 7919 * (x + 1); i++) putchar(' ');
"""

# leaves a file for its second pass
MESSAGES_STASH = r"""
#include <cstdio>
#include <cstring>
int main() {
    char run[16];
    int t;
    scanf("%15s%d", run, &t);
    if (strcmp(run, "first") == 0) {
        int x;
        scanf("%d", &x);
        FILE *f = fopen("stash.txt", "w");
        if (f != NULL) {
            fprintf(f, "%d\n", x);
            fclose(f);
        }
        puts("0");
    } else {
        int x = -1;
        FILE *f = fopen("stash.txt", "r");
        if (f != NULL) {
            fscanf(f, "%d", &x);
        }
        printf("%d\n", x);
    }
}
"""

# Looks where the judger keeps what the checker remembers and what the first pass wrote: the
# state of the checker has the very number, and the size of the output of the first pass says it.
MESSAGES_READ_THE_CHECKER = r"""
#include <cstdio>
#include <cstring>
#include <sys/stat.h>
int main() {
    char run[16];
    int t;
    scanf("%15s%d", run, &t);
    if (strcmp(run, "first") == 0) {
""" + MESSAGES_FIRST_PASS_OF_A_CHEAT + r"""
    } else {
        const char *names[] = {
            "PLACE/state.txt", "PLACE/nextpass.in", "PLACE/../pass_output.txt", "PLACE/../pass_input.txt",
        };
        long long x = -1;
        for (const char *name : names) {
            struct stat st;
            if (HOW == 0) {
                // how large it is
                if (stat(name, &st) == 0 && st.st_size > 2 && (st.st_size - 2) % 7919 == 0) {
                    x = (st.st_size - 2) / 7919 - 1;
                }
            } else {
                // what is in it
                FILE *f = fopen(name, "r");
                long long seen;
                if (f != NULL && fscanf(f, "%lld", &seen) == 1) {
                    x = seen;
                }
            }
        }
        printf("%lld\n", x);
    }
}
"""


def messages_cheat_reading_the_checker(place, read):
    """a cheat that looks for the files of the checker in a folder, by reading them or by
    asking for their size"""
    return MESSAGES_READ_THE_CHECKER.replace("PLACE", place).replace("HOW", "1" if read else "0")


# lists its work folder and looks at the size of everything in it: a program may do both
MESSAGES_LIST_WORK_FOLDER = r"""
import os
import sys

run = sys.stdin.readline().strip()
t = int(sys.stdin.readline())
if run == "first":
    x = int(sys.stdin.readline())
    sys.stdout.write("0\n" + " " * (7919 * (x + 1)))
else:
    found = -1
    seen = 0
    for folder in (".", "answer"):
        try:
            names = os.listdir(folder)
        except OSError:
            continue
        for name in names:
            try:
                size = os.stat(os.path.join(folder, name)).st_size
            except OSError:
                continue
            seen += 1
            if size > 2 and (size - 2) % 7919 == 0:
                found = (size - 2) // 7919 - 1
            if name in ("state.txt", "nextpass.in", "pass_output.txt", "pass_input.txt"):
                found = -2
    # a second pass that could not look around proves nothing
    print(found if seen > 0 else "blind")
"""


def multi_pass_problem_files(**overrides):
    settings = dict(
        use_builtin_judger="on", multi_pass=2, n_tests=2, n_ex_tests=0, n_sample_tests=0,
        input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
        time_limit=1, memory_limit=256,
    )  # fmt: skip
    settings.update(overrides)
    return {
        "problem.conf": conf(**settings),
        "chk.cpp": MESSAGES_CHECKER,
        "input1.txt": "first\n3\n5\n123456789\n0\n",
        "output1.txt": "\n",
        # a single number, small enough for the cheats above
        "input2.txt": "first\n1\n7\n",
        "output2.txt": "\n",
    }


# The second problem has as many passes as its checker likes. A pass is given the number of
# the step and a value, and writes the value plus one; the checker goes on until step 3. In its
# state it counts the passes it has seen, and the last pass has to find exactly the ones before.
STEPS_CHECKER = r"""
#include "testlib.h"
#include <fstream>
int main(int argc, char **argv) {
    registerTestlibCmd(argc, argv);
    int step = inf.readInt();
    long long value = inf.readLong();
    long long found = ouf.readLong();
    if (found != value + 1) {
        quitf(_wa, "step %d: expected %lld, found %lld", step, value + 1, found);
    }
    // the passes this checker has seen on this test, this one included
    int seen = 0;
    {
        std::ifstream state("state.txt");
        if (state) {
            state >> seen;
        }
    }
    seen++;
    {
        std::ofstream state("state.txt");
        state << seen << "\n";
    }
    if (step < 3) {
        std::ofstream next("nextpass.in");
        next << step + 1 << " " << found << "\n";
        next.close();
        quitf(_ok, "step %d done", step);
    }
    // the test began at the step its input says: so many passes there were, and no more
    int first_step = ans.readInt();
    if (seen != 3 - first_step + 1) {
        quitf(_wa, "the checker remembers %d passes, there were %d", seen, 3 - first_step + 1);
    }
    quitf(_ok, "%d passes", seen);
}
"""

STEPS = r"""
#include <cstdio>
int main() {
    int step;
    long long value;
    scanf("%d%lld", &step, &value);
    printf("%lld\n", value + 1);
}
"""


def steps_problem_files(passes):
    files = {
        "problem.conf": conf(
            use_builtin_judger="on", multi_pass=passes, n_tests=3, n_ex_tests=0, n_sample_tests=0,
            input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
            time_limit=1, memory_limit=256,
        ),  # fmt: skip
        "chk.cpp": STEPS_CHECKER,
    }
    # a test that takes three passes, one that takes two, and one that is over after its first
    for num, first_step in enumerate([1, 2, 3], start=1):
        files["input%d.txt" % num] = "%d %d\n" % (first_step, 10 * num)
        files["output%d.txt" % num] = "%d\n" % first_step
    return files


# A checker that does what a checker should not, as the first word of the input of the test
# tells it. The program has to add one to the number after that word, in every pass.
UNRULY_CHECKER = r"""
#include "testlib.h"
#include <cstdio>
#include <fstream>
#include <string>
#include <sys/stat.h>
int main(int argc, char **argv) {
    registerTestlibCmd(argc, argv);
    std::string kind = inf.readToken();
    long long value = inf.readLong();
    long long found = ouf.readLong();
    if (found != value + 1) {
        quitf(_wa, "expected %lld, found %lld", value + 1, found);
    }
    if (kind == "spin") {
        // never ends
        volatile unsigned long long spin = 0;
        for (;;) spin = spin + 1;
    }
    if (kind == "flood") {
        // writes the input of the next pass without end
        static char block[1 << 20];
        FILE *next = fopen("nextpass.in", "w");
        for (;;) fwrite(block, 1, sizeof(block), next);
    }
    if (kind == "folder") {
        // a folder where the input of the next pass should be, that nobody may look into
        mkdir("nextpass.in", 0);
        quitf(_ok, "a folder for the next pass");
    }
    if (kind == "stray") {
        // a file that is neither the input of the next pass nor its state
        FILE *other = fopen("other.txt", "w");
        if (other != NULL) {
            fputs("x\n", other);
            fclose(other);
        }
        quitf(_ok, "a file of its own");
    }
    int seen = 0;
    {
        std::ifstream state("state.txt");
        if (state) {
            state >> seen;
        }
    }
    seen++;
    {
        std::ofstream state("state.txt");
        state << seen << "\n";
    }
    // "more" never has enough, anything else is content with three passes
    if (kind == "more" || seen < 3) {
        std::ofstream next("nextpass.in");
        next << kind << " " << found << "\n";
        next.close();
        quitf(_ok, "pass %d done", seen);
    }
    quitf(_ok, "%d passes", seen);
}
"""

UNRULY = r"""
#include <cstdio>
int main() {
    char kind[16];
    long long value;
    scanf("%15s%lld", kind, &value);
    printf("%lld\n", value + 1);
}
"""

UNRULY_KINDS = ["spin", "more", "flood", "folder", "stray", "fine"]


def unruly_checker_problem_files():
    files = {
        "problem.conf": conf(
            use_builtin_judger="on", multi_pass=3, n_tests=len(UNRULY_KINDS), n_ex_tests=0, n_sample_tests=0,
            input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
            time_limit=1, memory_limit=256,
        ),  # fmt: skip
        "chk.cpp": UNRULY_CHECKER,
    }
    for num, kind in enumerate(UNRULY_KINDS, start=1):
        files["input%d.txt" % num] = "%s %d\n" % (kind, 10 * num)
        files["output%d.txt" % num] = "\n"
    return files


# The judger of a problem that never ends. It starts a process that leaves its process group,
# which is where a judger is looked for when it is killed for taking too long.
HANGING_JUDGER = r"""
#include <unistd.h>
int main() {
    if (fork() == 0) {
        setpgid(0, 0);
        for (;;) pause();
    }
    for (;;) pause();
}
"""


def hanging_judger_problem_files():
    # the judger is given three seconds, not the ten minutes that are usual
    files = ab_problem_files(use_builtin_judger="off", judger_time_limit=3)
    files["judger.cpp"] = HANGING_JUDGER
    files["Makefile"] = CUSTOM_JUDGER_MAKEFILE
    return files
