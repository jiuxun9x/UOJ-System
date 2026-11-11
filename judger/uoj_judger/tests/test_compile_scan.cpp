// Regression tests for what run/compile reads of a source before it compiles it: whether a
// program of C or C++ writes assembly, and the main class of a program of Java. What is written
// in a comment, a string or a character literal is no code: the word asm in a comment made a
// program that was fine a compile error, and "class Fake" in a string of Java was taken for its
// main class. Code that only looks like a literal is code still.

#include <unistd.h>

#define main compile_main
#include "../run/compile.cpp"
#undef main

static int n_checks = 0;
static int n_failures = 0;

static std::string write_source(const std::string &name, const std::string &text) {
    std::string dir = "/tmp/uoj_test_compile_scan_" + std::to_string(getpid());
    fs::create_directories(dir);
    std::ofstream(dir + "/" + name, std::ios::binary) << text;
    return dir + "/" + name;
}

static void check_asm(const std::string &text, bool expected) {
    n_checks++;
    bool found = has_illegal_keywords_in_file(write_source("a.cpp", text));
    if (found != expected) {
        n_failures++;
        std::cerr << "asm " << (found ? "found" : "not found") << " in: " << text << std::endl;
    }
}

static void check_main_class(const std::string &text, const std::string &expected) {
    n_checks++;
    std::string found = get_java_main_class(write_source("Main.java", text));
    if (found != expected) {
        n_failures++;
        std::cerr << "main class " << found << ", not " << expected << ", of: " << text << std::endl;
    }
}

int main() {
    // what is said of asm is no assembly
    check_asm("// no asm here\nint main() {}\n", false);
    check_asm("/* asm */ int main() {}\n", false);
    check_asm("#include <cstdio>\nint main() { puts(\"asm\"); }\n", false);
    check_asm("int main() { char c = 'a'; const char *s = \"\\\" asm \\\"\"; }\n", false);
    check_asm("int main() { const char *s = R\"x(asm \" )\" )x\"; }\n", false);
    // and assembly is assembly, however it is written
    check_asm("int main() { asm(\"nop\"); }\n", true);
    check_asm("int main() { __asm__ volatile(\"nop\"); }\n", true);
    check_asm("int main() { int a = 1'0; asm(\"nop\"); int b = 2'0; }\n", true);
    check_asm("int main() { as\\\nm(\"nop\"); }\n", true);
    check_asm("int main() { char c = '\\''; asm(\"nop\"); }\n", true);
    check_asm("int main() { const char *s = R\"(x)\"; asm(\"nop\"); }\n", true);

    // the main class of Java is the first class of its code
    check_main_class("public class Main { public static void main(String[] a) {} }\n", "Main");
    check_main_class("// class Fake\n/* class Fake */\nclass Main {}\n", "Main");
    check_main_class("@SuppressWarnings(\"class Fake\")\nclass Main { char c = '\"'; String s = \"class Other\"; }\n", "Main");
    check_main_class("class Main { String s = \"\"\"\n    class Fake\n    \"\"\"; }\n", "Main");

    fs::remove_all("/tmp/uoj_test_compile_scan_" + std::to_string(getpid()));
    if (n_failures) {
        std::cerr << n_failures << " of " << n_checks << " checks of what is read of a source failed" << std::endl;
        return 1;
    }
    std::cout << "all " << n_checks << " checks of what is read of a source passed" << std::endl;
    return 0;
}
