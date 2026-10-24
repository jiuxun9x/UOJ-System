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
    int c = '?', last;
    std::string buf;
    while (true) {
        last = c;
        c = getchar();
        if (c == EOF) {
            if (last != '\n') {
                putchar('\n');
            }
            break;
        } else if (c == ' ' || c == '\r') {
            buf.push_back((char)c);
        } else {
            if (!buf.empty()) {
                if (c != '\n') {
                    printf("%s", buf.c_str());
                }
                buf.clear();
            }
            putchar(c);
        }
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
