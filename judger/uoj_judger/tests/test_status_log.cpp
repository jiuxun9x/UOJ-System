// Regression tests for the times of the statuses a judgement reports.
//
// Every status that is reported is also written to status_log.txt with the time it was reported
// at, which judge_client reads to tell how long compiling and the tests took. Build with
// -DUOJ_TEST_JUDGER_V2 to test uoj_judger_v2.h, without it to test uoj_judger.h.

#ifdef UOJ_TEST_JUDGER_V2
#include "uoj_judger_v2.h"
#define TEST_HEADER_NAME "uoj_judger_v2.h"
#else
#include "uoj_judger.h"
#define TEST_HEADER_NAME "uoj_judger.h"
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

static long long now_ms() {
    struct timespec now;
    clock_gettime(CLOCK_REALTIME, &now);
    return (long long)now.tv_sec * 1000 + now.tv_nsec / 1000000;
}

int main() {
    char dir[] = "/tmp/uoj_status_log_XXXXXX";
    if (mkdtemp(dir) == NULL) {
        cerr << "cannot make a temporary folder" << endl;
        return 1;
    }
    result_path = dir;

    long long before = now_ms();
    report_judge_status_f("Compiling");
    usleep(20000);
    report_judge_status_f("Judging Test #%d", 1);
    report_judge_status_f("Judging Test #%d", 2);
    long long after = now_ms();

    ifstream fin(string(dir) + "/status_log.txt");
    vector<pair<long long, string>> lines;
    long long ms;
    string status;
    while (fin >> ms && getline(fin, status)) {
        lines.push_back({ms, status});
    }
    CHECK(lines.size() == 3);
    if (lines.size() == 3) {
        CHECK(lines[0].second == " Compiling");
        CHECK(lines[1].second == " Judging Test #1");
        CHECK(lines[2].second == " Judging Test #2");
        CHECK(before <= lines[0].first);
        // the time of a status is when it was reported, not when the file was written last
        CHECK(lines[0].first + 20 <= lines[1].first);
        CHECK(lines[1].first <= lines[2].first);
        CHECK(lines[2].first <= after);
    }

    // the status that is shown is still only the last one
    ifstream cur(string(dir) + "/cur_status.txt");
    string shown;
    getline(cur, shown);
    CHECK(shown == "Judging Test #2");

    remove((string(dir) + "/status_log.txt").c_str());
    remove((string(dir) + "/cur_status.txt").c_str());
    rmdir(dir);

    if (n_failures != 0) {
        cerr << TEST_HEADER_NAME << ": " << n_failures << " of " << n_checks << " checks failed" << endl;
        return 1;
    }
    cout << TEST_HEADER_NAME << ": all " << n_checks << " status log checks passed" << endl;
    return 0;
}
