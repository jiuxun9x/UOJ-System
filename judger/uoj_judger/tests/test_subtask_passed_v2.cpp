// Regression tests for the subtasks of uoj_judger_v2.h that depend on others.
//
// A subtask of type min takes the least score of its dependencies with its own: one that took
// 50 from a dependency and passed its own tests has not passed, and a packed subtask after it is
// skipped. (min -> min -> packed: the second said Accepted, and the third was judged.)

#include "uoj_judger_v2.h"

static int n_checks = 0;
static int n_failures = 0;

#define CHECK_SAME(expected, actual)                                                             \
    do {                                                                                         \
        n_checks++;                                                                              \
        if ((expected) != (actual)) {                                                            \
            n_failures++;                                                                        \
            cerr << __FILE__ << ":" << __LINE__ << ": expected " << (expected) << ", got "       \
                 << (actual) << endl;                                                            \
        }                                                                                        \
    } while (false)

int main() {
    // a min that inherited less than full has not passed, whatever its own tests said
    CHECK_SAME(false, v2_subtask_passed("min", "Accepted", 50));
    CHECK_SAME(true, v2_subtask_passed("min", "Accepted", 100));
    CHECK_SAME(false, v2_subtask_passed("min", "Wrong Answer", 100));
    // a packed one passed where its tests did
    CHECK_SAME(true, v2_subtask_passed("packed", "Accepted", 100));
    CHECK_SAME(false, v2_subtask_passed("packed", "Wrong Answer", 100));
    if (n_failures) {
        cerr << n_failures << " of " << n_checks << " checks failed" << endl;
        return 1;
    }
    cout << "all " << n_checks << " checks passed" << endl;
    return 0;
}
