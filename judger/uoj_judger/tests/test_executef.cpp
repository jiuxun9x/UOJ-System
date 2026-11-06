// Regression tests for executef(), which runs a command made as printf makes a text: a command
// longer than 1024 bytes was not run at all.

#include "uoj_run.h"

#include <unistd.h>

static int n_checks = 0;
static int n_failures = 0;

#define CHECK(condition)                                                     \
    do {                                                                     \
        n_checks++;                                                          \
        if (!(condition)) {                                                  \
            n_failures++;                                                    \
            std::cerr << __FILE__ << ":" << __LINE__ << ": " << #condition << std::endl; \
        }                                                                    \
    } while (false)

int main() {
    std::string file = "/tmp/uoj_test_executef_" + std::to_string(getpid());
    CHECK(executef("printf %%s %s > %s", "short", file.c_str()) == 0);
    CHECK(std::ifstream(file).good());
    // a command of some kilobytes, as a deep folder makes one
    std::string long_word(5000, 'x');
    CHECK(executef("printf %%s %s > %s", long_word.c_str(), file.c_str()) == 0);
    std::string written;
    std::getline(std::ifstream(file), written);
    CHECK(written == long_word);
    CHECK(executef("exit %d", 3) == 3);
    std::remove(file.c_str());
    if (n_failures) {
        std::cerr << n_failures << " of " << n_checks << " checks of executef failed" << std::endl;
        return 1;
    }
    std::cout << "all " << n_checks << " checks of executef passed" << std::endl;
    return 0;
}
