// Regression tests for subtasks that depend on others.
//
// A subtask whose dependencies did not pass is skipped. A subtask of type min takes the least
// score of its dependencies with its own: a packed subtask that was skipped is a score of 0 to
// it, as is one whose tests failed. (packed -> packed -> min, the first failing: the third got
// its full 40 points, from the 100 the skipped second never put down.)

#include "uoj_judger.h"

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

struct Subtask {
    string type;
    score_t full_score;
    vector<int> dependencies;
    score_t point_score; // what its one test scores, of 100
};

// The total of the subtasks, judged as main_data_test() judges them: a subtask whose
// dependencies say so is skipped, the others have their one test judged.
static score_t judge(const vector<Subtask> &list, string &tested) {
    map<int, SubtaskInfo> subtasks;
    score_t total = 0;
    tested = "";
    for (int t = 1; t <= (int)list.size(); t++) {
        SubtaskMetaInfo meta;
        meta.num = t;
        meta.points_id = {t};
        meta.subtask_type = list[t - 1].type;
        meta.subtask_used_time_type = "sum";
        meta.subtask_dependencies = list[t - 1].dependencies;
        meta.full_score = list[t - 1].full_score;
        SubtaskInfo info(meta);
        if (info.resolve_dependencies(subtasks)) {
            tested += to_string(t);
            info.add_point_info(PointInfo(t, list[t - 1].point_score, 1, 1));
        } else {
            info.info = "Skipped";
        }
        subtasks[t] = info;
        total += info.scr;
    }
    return total;
}

int main() {
    string tested;
    // packed -> packed -> min, the first failing: nothing
    CHECK_SAME(0, judge({{"packed", 30, {}, 0}, {"packed", 30, {1}, 100}, {"min", 40, {2}, 100}}, tested));
    CHECK_SAME(string("1"), tested);
    // all passing: everything
    CHECK_SAME(100, judge({{"packed", 30, {}, 100}, {"packed", 30, {1}, 100}, {"min", 40, {2}, 100}}, tested));
    CHECK_SAME(string("123"), tested);
    // the min subtask right after the failing one, and all packed: nothing, as before
    CHECK_SAME(0, judge({{"packed", 30, {}, 0}, {"min", 70, {1}, 100}}, tested));
    CHECK_SAME(0, judge({{"packed", 30, {}, 0}, {"packed", 30, {1}, 100}, {"packed", 40, {2}, 100}}, tested));
    // a packed subtask skipped because a min one before it got only part: the min one after it
    // gets nothing either
    CHECK_SAME(15, judge({{"min", 30, {}, 50}, {"packed", 30, {1}, 100}, {"min", 40, {2}, 100}}, tested));
    CHECK_SAME(string("1"), tested);
    // min after min takes the least of them: half of 30 and half of 40
    CHECK_SAME(35, judge({{"min", 30, {}, 50}, {"min", 40, {1}, 100}}, tested));
    // a dependency of several, one of them skipped: nothing
    CHECK_SAME(30, judge({{"packed", 30, {}, 100}, {"packed", 30, {}, 0}, {"packed", 10, {2}, 100}, {"min", 30, {1, 3}, 100}}, tested));
    CHECK_SAME(string("12"), tested);

    if (n_failures > 0) {
        cerr << "uoj_judger.h: " << n_failures << " of " << n_checks << " checks of subtask dependencies failed" << endl;
        return 1;
    }
    cout << "uoj_judger.h: all " << n_checks << " checks of subtask dependencies passed" << endl;
    return 0;
}
