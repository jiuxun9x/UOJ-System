// Regression tests for what syncing the data of a problem does to its files (run/formatter).
//
// \r\n becomes \n, the blanks at the ends of lines go, and the file ends with one line break:
// blanks after the last line break were taken for the end of a line that had none, and the file
// was given a second line break.

#define main formatter_main
#include "../run/formatter.cpp"
#undef main

#include <iostream>
#include <unistd.h>

static int n_checks = 0;
static int n_failures = 0;
static std::string dir;

static std::string formatted(const std::string &text) {
    std::string in = dir + "/in.txt", out = dir + "/out.txt";
    FILE *f = fopen(in.c_str(), "wb");
    fwrite(text.data(), 1, text.size(), f);
    fclose(f);
    if (!freopen(in.c_str(), "rb", stdin) || !freopen(out.c_str(), "wb", stdout)) {
        return "<cannot redirect>";
    }
    format_all();
    fflush(stdout);
    f = fopen(out.c_str(), "rb");
    std::string result;
    for (int c = fgetc(f); c != EOF; c = fgetc(f)) {
        result.push_back((char)c);
    }
    fclose(f);
    return result;
}

static void check(const std::string &text, const std::string &expected) {
    n_checks++;
    std::string got = formatted(text);
    if (got != expected) {
        n_failures++;
        std::cerr << "formatting " << '"' << text << '"' << " gave " << '"' << got << '"' << ", not " << '"' << expected << '"' << std::endl;
    }
}

int main() {
    char made[] = "/tmp/uoj_formatter_XXXXXX";
    if (mkdtemp(made) == NULL) {
        std::cerr << "cannot make a temporary folder" << std::endl;
        return 1;
    }
    dir = made;
    check("1   2  \r\n", "1   2\n");
    check("1 2", "1 2\n");
    check("1 2\n", "1 2\n");
    check("1 2\n  ", "1 2\n");
    check("1 2\n \r\n", "1 2\n\n");
    check("a \nb\r\n\r\n", "a\nb\n\n");
    check("", "\n");
    if (n_failures) {
        std::cerr << n_failures << " of " << n_checks << " checks of the formatter failed" << std::endl;
        return 1;
    }
    std::cerr << "all " << n_checks << " checks of the formatter passed" << std::endl;
    return 0;
}
