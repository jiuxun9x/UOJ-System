// Regression tests for conf_run_limit().
//
// The stack limit must default to the stack limit of the fallback value, never to
// its real time limit. Build with -DUOJ_TEST_JUDGER_V2 to test uoj_judger_v2.h,
// without it to test uoj_judger.h.

#ifdef UOJ_TEST_JUDGER_V2
#include "uoj_judger_v2.h"
#define TEST_CONFIG uconfig
#define TEST_HEADER_NAME "uoj_judger_v2.h"
#else
#include "uoj_judger.h"
#define TEST_CONFIG config
#define TEST_HEADER_NAME "uoj_judger.h"
#endif

static int n_checks = 0;
static int n_failures = 0;

#define CHECK_EQ(actual, expected)                                                       \
    do {                                                                                 \
        n_checks++;                                                                      \
        auto actual_value = (actual);                                                    \
        auto expected_value = (expected);                                                \
        if (!(actual_value == expected_value)) {                                         \
            n_failures++;                                                                \
            cerr << __FILE__ << ":" << __LINE__ << ": " << #actual << " == " << actual_value \
                 << ", expected " << expected_value << endl;                             \
        }                                                                                \
    } while (false)

static void set_config(const map<string, string> &values) {
    TEST_CONFIG.clear();
    for (const auto &kv : values) {
        TEST_CONFIG[kv.first] = kv.second;
    }
}

static void test_stack_limit_unset_keeps_default() {
    set_config({});
    CHECK_EQ(conf_run_limit(1, RL_DEFAULT).stack, -1);

    runp::limits_t val(1, 256, 64);
    val.stack = 96;
    CHECK_EQ(conf_run_limit(1, val).stack, 96);
}

static void test_stack_limit_explicit() {
    set_config({{"stack_limit", "32"}});
    CHECK_EQ(conf_run_limit(1, RL_DEFAULT).stack, 32);

    set_config({{"stack_limit", "32"}, {"stack_limit_3", "48"}});
    CHECK_EQ(conf_run_limit(1, RL_DEFAULT).stack, 32);
    CHECK_EQ(conf_run_limit(3, RL_DEFAULT).stack, 48);
}

static void test_real_time_limit_does_not_leak_into_stack() {
    // The fallback value carries a real time limit but no stack limit.
    runp::limits_t val(1, 256, 64);
    val.real_time = 7;

    set_config({});
    runp::limits_t limits = conf_run_limit(1, val);
    CHECK_EQ(limits.real_time, 7.0);
    CHECK_EQ(limits.stack, -1);

    set_config({{"real_time_limit", "5"}});
    limits = conf_run_limit(1, RL_DEFAULT);
    CHECK_EQ(limits.real_time, 5.0);
    CHECK_EQ(limits.stack, -1);

    set_config({{"real_time_limit", "50"}, {"stack_limit", "32"}});
    CHECK_EQ(conf_run_limit(1, RL_DEFAULT).stack, 32);
}

static void test_nested_default_for_standard_program() {
    // This is how the limits of the standard program are derived when a hack is judged.
    set_config({{"real_time_limit", "5"}});
    runp::limits_t limits = conf_run_limit("standard", 0, conf_run_limit(0, RL_DEFAULT));
    CHECK_EQ(limits.real_time, 5.0);
    CHECK_EQ(limits.stack, -1);

    set_config({{"real_time_limit", "5"}, {"stack_limit", "128"}});
    limits = conf_run_limit("standard", 0, conf_run_limit(0, RL_DEFAULT));
    CHECK_EQ(limits.stack, 128);

    set_config({{"real_time_limit", "5"}, {"stack_limit", "128"}, {"standard_stack_limit", "16"}});
    limits = conf_run_limit("standard", 0, conf_run_limit(0, RL_DEFAULT));
    CHECK_EQ(limits.stack, 16);
    CHECK_EQ(conf_run_limit(0, RL_DEFAULT).stack, 128);
}

static void test_other_limits_are_unaffected() {
    set_config({{"time_limit", "2"},
                {"memory_limit", "64"},
                {"output_limit", "8"},
                {"real_time_limit", "9"},
                {"stack_limit", "16"}});
    runp::limits_t limits = conf_run_limit(1, RL_DEFAULT);
    CHECK_EQ(limits.time, 2.0);
    CHECK_EQ(limits.memory, 64);
    CHECK_EQ(limits.output, 8);
    CHECK_EQ(limits.real_time, 9.0);
    CHECK_EQ(limits.stack, 16);
}

int main() {
    test_stack_limit_unset_keeps_default();
    test_stack_limit_explicit();
    test_real_time_limit_does_not_leak_into_stack();
    test_nested_default_for_standard_program();
    test_other_limits_are_unaffected();

    if (n_failures != 0) {
        cerr << TEST_HEADER_NAME << ": " << n_failures << " of " << n_checks << " checks failed"
             << endl;
        return 1;
    }
    cout << TEST_HEADER_NAME << ": all " << n_checks << " checks passed" << endl;
    return 0;
}
