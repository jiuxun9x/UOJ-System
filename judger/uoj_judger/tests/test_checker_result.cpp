// Regression tests for how what a checker writes is read.
//
// A testlib checker (or interactor) that says FAIL, with quitf(_fail, ...), went wrong itself:
// the answer of the problem did not read, or one of its own checks failed. The program that was
// judged is not to blame: the test is a judgment failure, not a wrong answer. Build with
// -DUOJ_TEST_JUDGER_V2 to test uoj_judger_v2.h, without it to test uoj_judger.h.

#ifdef UOJ_TEST_JUDGER_V2
#include "uoj_judger_v2.h"
#define TEST_HEADER_NAME "uoj_judger_v2.h"
typedef run_checker_result CheckerResult;
#else
#include "uoj_judger.h"
#define TEST_HEADER_NAME "uoj_judger.h"
typedef RunCheckerResult CheckerResult;
#endif

static int n_checks = 0;
static int n_failures = 0;

#define CHECK(condition)                                                     \
    do {                                                                     \
        n_checks++;                                                          \
        if (!(condition)) {                                                  \
            n_failures++;                                                    \
            cerr << __FILE__ << ":" << __LINE__ << ": " << #condition << endl; \
        }                                                                    \
    } while (false)

static string checker_file;

// what a checker that ran to its end and wrote this is read as
static CheckerResult read_checker(const string &text) {
    FILE *f = fopen(checker_file.c_str(), "w");
    fputs(text.c_str(), f);
    fclose(f);
    return CheckerResult::from_file(checker_file, runp::result(runp::RS_AC, "", 3, 1024, 0));
}

int main() {
    char dir[] = "/tmp/uoj_checker_result_XXXXXX";
    if (mkdtemp(dir) == NULL) {
        cerr << "cannot make a temporary folder" << endl;
        return 1;
    }
    checker_file = string(dir) + "/checker_result.txt";

    CheckerResult ok = read_checker("ok 3 numbers\n");
    CHECK(ok.type == runp::RS_AC);
    CHECK(ok.scr == 100);

    CheckerResult wrong = read_checker("wrong answer 2nd numbers differ - expected: '3', found: '4'\n");
    CHECK(wrong.type == runp::RS_AC);
    CHECK(wrong.scr == 0);

    CheckerResult failed = read_checker("FAIL Expected integer, but \"x\" found (stdin, line 1)\n");
    CHECK(failed.type == runp::RS_JGF);
    CHECK(failed.scr == 0);
    // what the checker said of why is kept, for whoever looks into it
    CHECK(failed.info.find("Expected integer") != string::npos);

    // a file that says nothing is a checker that failed, as before
    CHECK(read_checker("").type == runp::RS_JGF);

    if (n_failures) {
        cerr << TEST_HEADER_NAME << ": " << n_failures << " of " << n_checks << " checks failed" << endl;
        return 1;
    }
    cout << TEST_HEADER_NAME << ": all " << n_checks << " checks of what a checker writes passed" << endl;
    return 0;
}
