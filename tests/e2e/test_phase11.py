"""End-to-end tests of phase 11: problems whose program runs more than once on a test, in the
three ways other judges have (Hydro, DOMjudge, Polygon), what syncing does to the files of a
problem, and statements that are PDF files.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import test_phase3 as p3
import test_phase6 as p6
import uoj
from fixtures import *
from uoj import db, db_value


def setUpModule():
    uoj.admin()


def published_file(problem_id, name):
    """a file of the data of a problem as the judgers get it, as bytes"""
    return bytes.fromhex(uoj.docker_exec(uoj.WEB, "od -An -v -tx1 /var/uoj_data/%d/%s | tr -d ' \\n'" % (problem_id, name)).strip())


def data_settings(client, problem_id, **fields):
    """send the form of the judging settings of a problem: the answer"""
    form = dict(form="judge_settings", type="traditional", time_limit="1", memory_limit="256", checker="wcmp", scoring="per_test")
    form.update(fields)
    return client.post("/problem/%d/manage/data" % problem_id, form)


# ---------------------------------------------------------------------- Hydro

# A checker written for Hydro, the way the checkers of Hydro are: it knows the pass from
# HYDRO_MULTI_PASS, draws a token from /dev/urandom and keeps it in state.txt for the program of
# the second pass to read, looks at the code that was submitted, makes a file of its own and
# renames and removes it, and removes state.txt when it is done with it - the last of which is
# what a checker could not do here before.
HYDRO_CHECKER = r"""
#include "testlib.h"
#include <cstdio>
#include <cstdlib>
#include <fstream>
#include <iterator>
#include <string>

int main(int argc, char *argv[]) {
    registerTestlibCmd(argc, argv);
    const char *pass_said = getenv("HYDRO_MULTI_PASS");
    if (pass_said == NULL || getenv("HYDRO_TESTCASE") == NULL || getenv("HYDRO_TIME_USAGE") == NULL || getenv("HYDRO_MEMORY_USAGE") == NULL) {
        quitf(_fail, "the environment of Hydro is not there");
    }
    int pass = atoi(pass_said);
    std::ifstream code("user_code");
    std::string text((std::istreambuf_iterator<char>(code)), std::istreambuf_iterator<char>());
    if (text.find("p11-hydro-solution") == std::string::npos) {
        quitf(_fail, "user_code is not the code that was submitted");
    }
    if (pass == 1) {
        long long n = inf.readLong();
        long long x = ouf.readLong();
        if (x != 2 * n) {
            quitf(_wa, "pass 1: expected %lld, found %lld", 2 * n, x);
        }
        unsigned int token = 0;
        FILE *random = fopen("/dev/urandom", "rb");
        if (random == NULL || fread(&token, sizeof token, 1, random) != 1) {
            quitf(_fail, "no randomness");
        }
        fclose(random);
        token = token % 900000000u + 100000000u;
        FILE *scratch = fopen("scratch.tmp", "w");
        if (scratch == NULL) {
            quitf(_fail, "can not make a file");
        }
        fprintf(scratch, "%u\n", token);
        fclose(scratch);
        if (rename("scratch.tmp", "state.txt") != 0) {
            quitf(_fail, "can not rename a file");
        }
        FILE *next = fopen("nextpass.in", "w");
        fprintf(next, "second\n");
        fclose(next);
        quitf(_ok, "pass 1 of test %s", getenv("HYDRO_TESTCASE"));
    }
    FILE *state = fopen("state.txt", "r");
    unsigned int token = 0;
    if (state == NULL || fscanf(state, "%u", &token) != 1) {
        quitf(_fail, "state.txt is gone");
    }
    fclose(state);
    if (remove("state.txt") != 0) {
        quitf(_fail, "can not remove state.txt");
    }
    long long x = ouf.readLong();
    if (x != (long long)token) {
        quitf(_wa, "pass 2: the token was not read");
    }
    quitf(_ok, "pass %d: the token was read", pass);
}
"""

# doubles the number in the first pass; in the second, says what the checker left in state.txt
HYDRO_SOLUTION = r"""
// p11-hydro-solution
#include <cstdio>
#include <cstdlib>
#include <cstring>
int main() {
    char word[32];
    if (scanf("%31s", word) != 1) {
        return 0;
    }
    if (strcmp(word, "second") == 0) {
        FILE *state = fopen("state.txt", "r");
        unsigned int token = 0;
        if (state != NULL && fscanf(state, "%u", &token) == 1) {
            printf("%u\n", token);
        } else {
            printf("0\n");
        }
        return 0;
    }
    printf("%lld\n", 2 * atoll(word));
}
"""
# a second pass that does not read state.txt guesses
HYDRO_GUESS = HYDRO_SOLUTION.replace('FILE *state = fopen("state.txt", "r");', "FILE *state = NULL;")


def hydro_problem_files():
    files = {
        "problem.conf": conf(use_builtin_judger="on", multi_pass=2, n_tests=2, n_ex_tests=0, n_sample_tests=0,
                             input_pre="data", input_suf="in", output_pre="data", output_suf="out", time_limit=1, memory_limit=256),
        "chk.cpp": HYDRO_CHECKER,
    }  # fmt: skip
    for num, n in ((1, 5), (2, 123456)):
        files["data%d.in" % num] = "%d\n" % n
        files["data%d.out" % num] = ""
    return files


# ---------------------------------------------------------------------- DOMjudge

# An output validator of the problem package format of ICPC: chk input answer feedback_dir <
# output. It keeps what the first pass wrote in its feedback folder, and reads the input of the
# test in every pass - which it is given, and not the input of the pass. It is only compiled
# with DOMJUDGE defined, as DOMjudge compiles it.
DOMJUDGE_VALIDATOR = r"""
#include <fstream>
#include <iostream>
#include <string>
#ifndef DOMJUDGE
#error "an output validator of DOMjudge is compiled with DOMJUDGE defined"
#endif

static std::string dir;

static int verdict(int code, const std::string &message) {
    std::ofstream(dir + "judgemessage.txt", std::ios::app) << message << "\n";
    return code;
}

int main(int argc, char **argv) {
    if (argc < 4) {
        return 1;
    }
    dir = argv[3];
    if (!dir.empty() && dir[dir.size() - 1] != '/') {
        dir += '/';
    }
    long long n = 0, x = 0, seen = 0;
    std::ifstream input(argv[1]);
    input >> n;
    if (!(std::cin >> x)) {
        return verdict(43, "no number");
    }
    std::ifstream state(dir + "seen.txt");
    if (!(state >> seen)) {
        if (x != 2 * n) {
            return verdict(43, "pass 1: expected " + std::to_string(2 * n) + ", found " + std::to_string(x));
        }
        std::ofstream(dir + "seen.txt") << x << "\n";
        std::ofstream(dir + "nextpass.in") << x << "\n";
        return verdict(42, "pass 1 fine");
    }
    if (x != 4 * n) {
        return verdict(43, "pass 2: expected " + std::to_string(4 * n) + ", found " + std::to_string(x));
    }
    return verdict(42, "pass 2 fine, after " + std::to_string(seen));
}
"""

DOUBLING = r"""
#include <cstdio>
int main() {
    long long v;
    scanf("%lld", &v);
    printf("%lld\n", 2 * v);
}
"""
DOUBLING_WRONG = DOUBLING.replace("2 * v", "2 * v + 1")


def domjudge_problem_files(**overrides):
    settings = dict(use_builtin_judger="on", domjudge_multipass="on", validation_passes=2, n_tests=2, n_ex_tests=0, n_sample_tests=0,
                    input_pre="data", input_suf="in", output_pre="data", output_suf="out", time_limit=1, memory_limit=256)  # fmt: skip
    settings.update(overrides)
    files = {"problem.conf": conf(**settings), "chk.cpp": DOMJUDGE_VALIDATOR}
    for num, n in ((1, 3), (2, 1000)):
        files["data%d.in" % num] = "%d\n" % n
        files["data%d.out" % num] = ""
    return files


# ---------------------------------------------------------------------- Polygon

# The interactor of a problem of Polygon that is run twice: interactor input tout answer. In
# the first run its input is the test, a number n: it tells the program "first n", wants n + 1
# back, and writes "second <that>" into its tout, the input of the second run. In a second run
# that talks to it, its input is that line: it tells the program, wants twice the number back,
# and writes the answer into its tout for the checker.
POLYGON_INTERACTOR = r"""
#include "testlib.h"
#include <iostream>
#include <string>

int main(int argc, char *argv[]) {
    registerInteraction(argc, argv);
    std::string head = inf.readToken();
    if (head != "second") {
        long long n = atoll(head.c_str());
        std::cout << "first " << n << std::endl;
        long long r = ouf.readLong();
        if (r != n + 1) {
            quitf(_wa, "run 1: expected %lld, found %lld", n + 1, r);
        }
        tout << "second " << r << std::endl;
        quitf(_ok, "run 1 fine");
    }
    long long r = inf.readLong();
    std::cout << "second " << r << std::endl;
    long long y = ouf.readLong();
    if (y != 2 * r) {
        quitf(_wa, "run 2: expected %lld, found %lld", 2 * r, y);
    }
    tout << y << std::endl;
    quitf(_ok, "run 2 fine");
}
"""

POLYGON_SOLUTION = r"""
#include <iostream>
#include <string>
int main() {
    std::string head;
    long long v;
    std::cin >> head >> v;
    if (head == "first") {
        std::cout << v + 1 << std::endl;
    } else {
        std::cout << 2 * v << std::endl;
    }
}
"""
POLYGON_WRONG_FIRST = POLYGON_SOLUTION.replace("v + 1", "v + 2")
POLYGON_WRONG_SECOND = POLYGON_SOLUTION.replace("2 * v", "2 * v + 1")


def polygon_problem_files(**overrides):
    settings = dict(use_builtin_judger="on", polygon_runtwice="on", use_builtin_checker="ncmp", n_tests=2, n_ex_tests=0, n_sample_tests=0,
                    input_pre="data", input_suf="in", output_pre="data", output_suf="out", time_limit=1, memory_limit=256)  # fmt: skip
    settings.update(overrides)
    files = {"problem.conf": conf(**settings), "interactor.cpp": POLYGON_INTERACTOR}
    for num, n in ((1, 4), (2, 99)):
        files["data%d.in" % num] = "%d\n" % n
        files["data%d.out" % num] = "%d\n" % (2 * (n + 1))
    return files


def pass_labels(details):
    """what a test of several passes shows: the labels of its inputs and outputs, in order"""
    return re.findall(r'<(in|out) label="(#\d+)"', details)


class HydroPassesTest(unittest.TestCase):
    """a problem of several passes written for Hydro is judged here as Hydro judges it"""

    def test_checker_of_hydro_runs_as_it_does_on_hydro(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(hydro_problem_files())
        j = uoj.wait_submission(admin.submit(problem_id, HYDRO_SOLUTION))
        self.assertEqual((j.score, j.infos), (100, ["Accepted"] * 2), j)
        self.assertIn("pass 2: the token was read", j.details)
        # every pass is shown with what it read and wrote
        self.assertEqual(pass_labels(j.details)[:4], [("in", "#1"), ("out", "#1"), ("in", "#2"), ("out", "#2")])
        # the program of a pass that does not read state.txt does not know the token
        j = uoj.wait_submission(admin.submit(problem_id, HYDRO_GUESS))
        self.assertEqual((j.score, j.infos), (0, ["Wrong Answer"] * 2), j)
        self.assertIn("pass 2: the token was not read", j.details)
        # a wrong first pass is wrong in the first pass
        j = uoj.wait_submission(admin.submit(problem_id, HYDRO_SOLUTION.replace("2 * atoll", "3 * atoll")))
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)
        self.assertIn("pass 1: expected 10, found 15", j.details)
        self.assertNotIn("Dangerous Syscalls", j.details)


class DomjudgePassesTest(unittest.TestCase):
    """a problem of several passes written for DOMjudge is judged here as DOMjudge judges it"""

    def test_output_validator_of_domjudge_runs_as_it_does_on_domjudge(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(domjudge_problem_files())
        # the validator is compiled the way DOMjudge compiles it: it says so, or it is not compiled at all
        steps = json.loads(db_value("select prepare from problem_data_versions where problem_id = %d order by version desc limit 1" % problem_id))
        self.assertEqual([(step["name"], step.get("define")) for step in steps], [("chk", ["DOMJUDGE"])])
        j = uoj.wait_submission(admin.submit(problem_id, DOUBLING))
        self.assertEqual((j.score, j.infos), (100, ["Accepted"] * 2), j)
        # what it wrote to the judge is shown; the input it read in the second pass was the input of the test
        self.assertIn("pass 1 fine\npass 2 fine, after 2000", j.details)
        self.assertEqual(pass_labels(j.details)[:4], [("in", "#1"), ("out", "#1"), ("in", "#2"), ("out", "#2")])
        j = uoj.wait_submission(admin.submit(problem_id, DOUBLING_WRONG))
        self.assertEqual((j.score, j.infos), (0, ["Wrong Answer"] * 2), j)
        self.assertIn("pass 1: expected 6, found 7", j.details)

    def test_validator_that_wants_more_passes_than_allowed_is_wrong_itself(self):
        admin = uoj.admin()
        # one pass allowed by the validator's count: the second asks for a third
        files = domjudge_problem_files(validation_passes=2)
        files["chk.cpp"] = DOMJUDGE_VALIDATOR.replace('return verdict(42, "pass 2 fine, after "', 'std::ofstream(dir + "nextpass.in") << x << "\\n";\n    return verdict(42, "pass 2 fine, after "')
        problem_id = admin.create_problem(files)
        j = uoj.wait_submission(admin.submit(problem_id, DOUBLING))
        self.assertEqual(j.infos, ["Checker Judgment Failed"] * 2, j)
        self.assertIn("asked for another pass after pass 2", j.details)


class PolygonRunTwiceTest(unittest.TestCase):
    """a problem of Polygon that is run twice is judged here as QOJ judges it, in its three forms"""

    def judge(self, problem_id, code):
        return uoj.wait_submission(uoj.admin().submit(problem_id, code))

    def test_second_run_reads_what_the_interactor_wrote(self):
        problem_id = uoj.admin().create_problem(polygon_problem_files())
        j = self.judge(problem_id, POLYGON_SOLUTION)
        self.assertEqual((j.score, j.infos), (100, ["Accepted"] * 2), j)
        self.assertEqual(pass_labels(j.details)[:4], [("in", "#1"), ("out", "#1"), ("in", "#2"), ("out", "#2")])
        # what the interactor wrote is the input of the second run
        self.assertIn("second 5", j.details)
        j = self.judge(problem_id, POLYGON_WRONG_FIRST)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)
        self.assertIn("run 1: expected 5, found 6", j.details)
        j = self.judge(problem_id, POLYGON_WRONG_SECOND)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)

    def test_second_run_talks_to_the_interactor_and_the_checker_or_the_interactor_decides(self):
        for interactor_verdict in ("off", "on"):
            files = polygon_problem_files(polygon_runtwice_interactive="on", polygon_runtwice_interactor_verdict=interactor_verdict)
            problem_id = uoj.admin().create_problem(files)
            j = self.judge(problem_id, POLYGON_SOLUTION)
            self.assertEqual((j.score, j.infos), (100, ["Accepted"] * 2), (interactor_verdict, j))
            self.assertIn("run 2 fine" if interactor_verdict == "on" else "ok", j.details)
            j = self.judge(problem_id, POLYGON_WRONG_SECOND)
            self.assertEqual(j.infos, ["Wrong Answer"] * 2, (interactor_verdict, j))
            self.assertIn("run 2: expected 10, found 11", j.details)


class CommunicationSettingsTest(unittest.TestCase):
    """the way of a communication problem is chosen in the form, and the form writes the keys
    QOJ reads"""

    def test_way_is_chosen_in_the_form_and_written_as_qoj_writes_it(self):
        admin = uoj.admin()
        problem_id = admin.new_problem(title="p11 通信题")
        files = polygon_problem_files()
        del files["problem.conf"]
        r = p6.upload_files(admin, problem_id, files)
        self.assertIn(r.status_code, (200, 302))
        manage = "/problem/%d/manage/data" % problem_id
        page = admin.get(manage).text
        for way in ("hydro", "domjudge", "polygon"):
            self.assertIn('id="input-problem-comm_style-%s" name="comm_style" value="%s"' % (way, way), page)
        for field in ("run_twice_interactive", "run_twice_interactor_verdict", "formatter"):
            self.assertIn('name="%s"' % field, page)
        # Polygon, run twice, with the second run talking as well
        r = data_settings(admin, problem_id, type="multi_pass", comm_style="polygon", run_twice_interactive="on", checker="ncmp")
        self.assertEqual(r.status_code, 302, uoj.text_of(r.text)[-400:])
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["polygon_runtwice"], conf["polygon_runtwice_interactive"], conf["use_builtin_checker"], conf["interactor_source"]), ("on", "on", "ncmp", "interactor.cpp"))
        self.assertNotIn("polygon_runtwice_interactor_verdict", conf)
        self.assertRegex(admin.get(manage).text, r'id="input-problem-comm_style-polygon" name="comm_style" value="polygon" checked')
        j = uoj.wait_submission(admin.submit(problem_id, POLYGON_SOLUTION))
        self.assertEqual(j.score, 100, j)
        # DOMjudge, with three passes: a validator is needed, and is not there yet
        r = data_settings(admin, problem_id, type="multi_pass", comm_style="domjudge", passes="3")
        self.assertEqual(r.status_code, 302)
        conf = dict(line.split(None, 1) for line in uoj.docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/problem.conf" % problem_id).splitlines() if line.strip())
        self.assertEqual((conf["domjudge_multipass"], conf["validation_passes"]), ("on", "3"))
        for gone in ("polygon_runtwice", "polygon_runtwice_interactive", "multi_pass", "use_builtin_checker"):
            self.assertNotIn(gone, conf)
        # a way that there is not is refused
        r = data_settings(admin, problem_id, type="multi_pass", comm_style="kattis")
        self.assertEqual(r.status_code, 200)
        self.assertIn("请选择通信题的做法", uoj.text_of(r.text))


class FormatterTest(unittest.TestCase):
    """what syncing does to the files of a problem is chosen in the form, as QOJ has it"""

    def test_files_are_formatted_as_the_problem_says(self):
        admin = uoj.admin()
        files = ab_problem_files()
        files["input1.txt"] = "1   2  \r\n"
        problem_id = admin.create_problem(files)
        manage = "/problem/%d/manage/data" % problem_id
        self.assertIn('<option value="auto" selected="selected">', admin.get(manage).text)
        # as it always was: line breaks of Windows and blanks at the ends of lines go
        self.assertEqual(published_file(problem_id, "input1.txt"), b"1   2\n")
        fields = dict(time_limit="1", memory_limit="256", checker="ncmp", scoring="per_test")
        for formatter, published in (("remove_cr", b"1   2  \n"), ("none", b"1   2  \r\n"), ("auto", b"1   2\n")):
            r = data_settings(admin, problem_id, formatter=formatter, **fields)
            self.assertEqual(r.status_code, 302, uoj.text_of(r.text)[-300:])
            self.assertEqual(uoj.wait_data_version(problem_id), "")
            self.assertEqual(published_file(problem_id, "input1.txt"), published, formatter)
            self.assertIn('<option value="%s" selected="selected">' % formatter, admin.get(manage).text)
        # it is kept where QOJ keeps it
        self.assertEqual(json.loads(db_value("select extra_config from problems where id = %d" % problem_id)).get("remove_cr"), None)
        data_settings(admin, problem_id, formatter="remove_cr", **fields)
        self.assertEqual(json.loads(db_value("select extra_config from problems where id = %d" % problem_id)).get("remove_cr"), True)
        self.assertIn("problem.edit_formatter", [row[0] for row in db("select action from audit_logs where resource_type = 'problem' and resource_id = '%d'" % problem_id)])
