// Regression tests for the language run/compile takes a program of a problem to be in, from the
// name of its file: chk11.java is Java 11, chk.java the Java of a file with no number, as
// chk.cpp is C++17. (There was a Java14, which nothing compiles, and no Java11.)

#include <unistd.h>

#define main compile_main
#include "../run/compile.cpp"
#undef main

static int n_checks = 0;
static int n_failures = 0;

static void check(const std::string &file, const std::string &expected) {
    n_checks++;
    std::string dir = "/tmp/uoj_test_compile_lang_" + std::to_string(getpid());
    fs::create_directories(dir);
    std::ofstream(dir + "/" + file) << "class Main {}\n";
    compile_config conf;
    conf.name = dir + "/chk";
    conf.lang = "auto";
    conf.auto_find_src();
    fs::remove_all(dir);
    if (conf.lang != expected) {
        n_failures++;
        std::cerr << file << " is taken for " << conf.lang << ", not " << expected << std::endl;
    }
}

int main() {
    check("chk8.java", "Java8");
    check("chk11.java", "Java11");
    check("chk17.java", "Java17");
    check("chk21.java", "Java21");
    check("chk.java", "Java17");
    check("chk.cpp", "C++17");
    check("chk11.cpp", "C++11");
    if (n_failures) {
        std::cerr << n_failures << " of " << n_checks << " checks of the languages of files failed" << std::endl;
        return 1;
    }
    std::cout << "all " << n_checks << " checks of the languages of files passed" << std::endl;
    return 0;
}
