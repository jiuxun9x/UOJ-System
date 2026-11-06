#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>

/*
 * What syncing the data of a problem does to its files, from stdin to stdout.
 *
 *     formatter              \r\n becomes \n, the blanks at the ends of lines go, and the file
 *                            ends with a line break
 *     formatter --remove-cr  \r\n becomes \n, and nothing else changes
 */

static int format_all() {
    // the last character written: the file ends with a line break, and is given one only where
    // what it ends with is not one (blanks after the last line break are dropped, as at the end
    // of any line)
    int written = '?';
    std::string buf;
    for (int c = getchar(); c != EOF; c = getchar()) {
        if (c == ' ' || c == '\r') {
            buf.push_back((char)c);
            continue;
        }
        if (!buf.empty()) {
            if (c != '\n') {
                fputs(buf.c_str(), stdout);
            }
            buf.clear();
        }
        putchar(c);
        written = c;
    }
    if (written != '\n') {
        putchar('\n');
    }
    return 0;
}

static int remove_cr() {
    bool held = false;  // a \r that is dropped if a \n follows it
    for (int c = getchar(); c != EOF; c = getchar()) {
        if (held && c != '\n') {
            putchar('\r');
        }
        held = c == '\r';
        if (!held) {
            putchar(c);
        }
    }
    if (held) {
        putchar('\r');
    }
    return 0;
}

int main(int argc, char **argv) {
    if (argc == 2 && strcmp(argv[1], "--remove-cr") == 0) {
        return remove_cr();
    }
    return format_all();
}
