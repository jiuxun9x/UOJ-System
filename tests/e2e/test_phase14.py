"""Phase 14: judging faster. A precompiled <bits/stdc++.h> for every standard of C++, which
changes how long a program takes to compile and nothing else."""

import unittest

import uoj
from fixtures import ab_problem_files

COMPILE = "/opt/uoj_judger/uoj_judger/run/compile"
PCH_DIR = "/usr/local/lib/uoj-pch"
STANDARDS = ["C++98", "C++03", "C++11", "C++14", "C++17", "C++20", "C++23", "C++26"]

AB_BITS = r"""#include <bits/stdc++.h>
using namespace std;
int main() {
    long long a, b;
    cin >> a >> b;
    cout << a + b << endl;
}
"""

# what can come before the #include, and changes what the header means
AB_BITS_DEFINE_FIRST = r"""#define int long long
#include <bits/stdc++.h>
using namespace std;
signed main() {
    int a, b;
    cin >> a >> b;
    cout << a + b << endl;
}
"""

AB_BITS_PRAGMA_FIRST = r"""#pragma GCC optimize("O3,unroll-loops")
#include <bits/stdc++.h>
using namespace std;
int main() {
    long long a, b;
    cin >> a >> b;
    cout << a + b << endl;
}
"""

AB_BITS_DEBUG_MODE = r"""#define _GLIBCXX_DEBUG
#include <bits/stdc++.h>
using namespace std;
int main() {
    vector<long long> v(2);
    cin >> v[0] >> v[1];
    cout << v[0] + v[1] << endl;
}
"""

# right only where ONLINE_JUDGE is defined, as it is for every program
AB_BITS_ONLINE_JUDGE = r"""// A+B
#include <bits/stdc++.h>
int main() {
    long long a, b;
    std::cin >> a >> b;
#ifdef ONLINE_JUDGE
    std::cout << a + b << std::endl;
#else
    std::cout << "not on the judge" << std::endl;
#endif
}
"""

# compiles in C++20 and not in C++17: the header of each standard is that of its own
AB_BITS_CPP20 = r"""#include <bits/stdc++.h>
using namespace std;
int main() {
    vector<long long> v(2);
    cin >> v[0] >> v[1];
    ranges::sort(v);
    cout << reduce(v.begin(), v.end()) << endl;
}
"""

COMPILE_ERRORS = {
    "undeclared": "#include <bits/stdc++.h>\nint main() {\n    return undeclared_thing;\n}\n",
    "missing semicolon": "#include <bits/stdc++.h>\nint main() {\n    int x = 1\n    return x;\n}\n",
    "deep in the library": (
        "#include <bits/stdc++.h>\nstruct P { int x; };\nint main() {\n"
        "    std::vector<P> v(3);\n    std::sort(v.begin(), v.end());\n    std::set<P> s;\n    s.insert(P{1});\n}\n"
    ),
}


def judger():
    return uoj.JUDGERS[0]


def put(path, content):
    uoj.run("docker", "exec", "-i", judger(), "sh", "-c", "mkdir -p $(dirname %s) && cat > %s" % (path, path), stdin=content.encode())


def compile_in_judger(source, language, *options):
    """run run/compile of the judger on a program, outside the sandbox: its exit status, the
    options it ran g++ with, and what the compiler said"""
    folder = "/tmp/uoj_e2e_pch"
    uoj.docker_exec(judger(), "rm -rf %s; mkdir -p %s" % (folder, folder))
    put(folder + "/answer.code", source)
    p = uoj.run(
        "docker", "exec", "-w", folder, judger(), COMPILE, "--verbose", "--lang", language, *options, "answer",
        check=False,
    )  # fmt: skip
    lines = p.stderr.decode(errors="replace").splitlines()
    return p.returncode, lines[0] if lines else "", "\n".join(lines[1:])


def errors_of(message):
    """the errors a compiler reported, without what it says about where they were included from"""
    return [line for line in message.splitlines() if "error:" in line]


class PrecompiledHeaderTest(unittest.TestCase):
    """the judger image holds <bits/stdc++.h> compiled for every standard, and gcc takes it"""

    def test_every_standard_has_a_precompiled_header_that_gcc_takes(self):
        for language in STANDARDS:
            std = language.lower()
            code, options, message = compile_in_judger(AB_BITS, language)
            self.assertEqual(code, 0, (language, message))
            self.assertIn("-I%s/%s" % (PCH_DIR, std), options.split(), language)
            # the same options again, with gcc telling which headers it read: "!" before a
            # precompiled header it took, "x" before one it did not
            shown = uoj.run(
                "docker", "exec", "-w", "/tmp/uoj_e2e_pch", judger(),
                "sh", "-c", "/usr/bin/g++ %s -H -fsyntax-only -x c++ answer.code" % options,
                check=False,
            ).stderr.decode(errors="replace")  # fmt: skip
            self.assertIn("! %s/%s/bits/stdc++.h.gch" % (PCH_DIR, std), shown, language)

    def test_precompiled_header_is_used_only_where_it_means_the_same(self):
        for source in (AB_BITS_DEFINE_FIRST, AB_BITS_PRAGMA_FIRST, AB_BITS_DEBUG_MODE):
            code, options, message = compile_in_judger(source, "C++17")
            self.assertEqual(code, 0, message)
            self.assertNotIn(PCH_DIR, options, source)
        code, options, message = compile_in_judger(AB_BITS, "C++17", "--no-pch")
        self.assertEqual(code, 0, message)
        self.assertNotIn(PCH_DIR, options)
        # nothing of it for C
        code, options, message = compile_in_judger("#include <stdio.h>\nint main(void) { return 0; }\n", "C17")
        self.assertEqual(code, 0, message)
        self.assertNotIn(PCH_DIR, options)

    def test_compile_errors_are_those_without_the_precompiled_header(self):
        for name, source in COMPILE_ERRORS.items():
            for language in ("C++11", "C++17", "C++20"):
                code, options, message = compile_in_judger(source, language)
                plain_code, plain_options, plain_message = compile_in_judger(source, language, "--no-pch")
                self.assertIn(PCH_DIR, options)
                self.assertNotIn(PCH_DIR, plain_options)
                self.assertEqual((code != 0, plain_code != 0), (True, True), (name, language))
                self.assertTrue(errors_of(message), (name, language, message[-2000:]))
                self.assertEqual(errors_of(message), errors_of(plain_message), (name, language))


class PrecompiledHeaderVerdictTest(unittest.TestCase):
    """what is judged with the precompiled header gets the verdict it got without it"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(ab_problem_files())

    def judge(self, code, language="C++17"):
        return uoj.wait_submission(self.admin.submit(self.problem_id, code, language))

    def test_programs_with_the_header_are_accepted_in_every_standard(self):
        for language in STANDARDS:
            j = self.judge(AB_BITS, language)
            self.assertEqual((j.score, j.error), (100, None), (language, j))

    def test_programs_that_are_compiled_the_ordinary_way_are_accepted(self):
        for source in (AB_BITS_DEFINE_FIRST, AB_BITS_PRAGMA_FIRST, AB_BITS_DEBUG_MODE, AB_BITS_ONLINE_JUDGE):
            j = self.judge(source)
            self.assertEqual((j.score, j.error), (100, None), (source, j))

    def test_each_standard_has_a_library_of_its_own(self):
        j = self.judge(AB_BITS_CPP20, "C++20")
        self.assertEqual(j.score, 100, j)
        j = self.judge(AB_BITS_CPP20, "C++17")
        self.assertEqual(j.error, "Compile Error", j)

    def test_compile_error_is_reported_as_before(self):
        j = self.judge(COMPILE_ERRORS["undeclared"])
        self.assertEqual(j.error, "Compile Error", j)
        self.assertIn("undeclared_thing", j.details, j)
        self.assertIn("was not declared in this scope", j.details, j)

    def test_precompiled_header_makes_compiling_faster(self):
        # the same program, once with the header precompiled and once with a line before the
        # #include that keeps the precompiled header from being used
        fast = [self.admin.submit(self.problem_id, AB_BITS) for _ in range(2)]
        slow = [self.admin.submit(self.problem_id, "#define UOJ_E2E_NOTHING\n" + AB_BITS) for _ in range(2)]
        for submission_id in fast + slow:
            j = uoj.wait_submission(submission_id)
            self.assertEqual(j.score, 100, j)
        with_pch = min(uoj.task_timing("submission", i)["compile_ms"] for i in fast)
        without = min(uoj.task_timing("submission", i)["compile_ms"] for i in slow)
        self.assertLess(with_pch, without * 0.7, (with_pch, without))

if __name__ == "__main__":
    unittest.main()
