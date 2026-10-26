"""What the web server said while the tests ran. A PHP error that stops a page, or a query of
the site that failed, fails the run: on PHP 8 such a thing is a page that breaks, and on MySQL
8.4 a query that no longer means what it did. The warnings are listed to be looked at.

    python3 tests/e2e/check_php_log.py
"""

import collections
import re
import subprocess
import sys

FAILS = re.compile(r"PHP (Fatal error|Parse error|Recoverable fatal error)|UOJ: (a query failed|rows asked of a query that failed)")
SAID = re.compile(r"PHP (Warning|Deprecated|Notice|Fatal error|Parse error|Recoverable fatal error)|UOJ: ")


def said():
    """the lines of the logs of the web server, and what the container printed (the commands
    of the command line write there)"""
    texts = []
    for path in ("/var/log/apache2/uoj_error.log", "/var/log/apache2/error.log"):
        r = subprocess.run(["docker", "exec", "uoj-web", "cat", path], capture_output=True, text=True, errors="replace")
        texts.append(r.stdout)
    r = subprocess.run(["docker", "logs", "uoj-web"], capture_output=True, text=True, errors="replace")
    texts.append(r.stdout + "\n" + r.stderr)
    return [line for text in texts for line in text.splitlines() if SAID.search(line)]


def plain(line):
    """a line without the time, the process and the client, and with numbers that differ from
    one run to the next made one"""
    line = re.sub(r"^\[[^\]]*\] \[[^\]]*\] \[[^\]]*\] (\[client [^\]]*\] )?", "", line)
    line = re.sub(r", referer: .*$", "", line)
    return re.sub(r"\d{2,}", "N", line)


def main():
    lines = said()
    counts = collections.Counter(plain(line) for line in lines)
    failing = [line for line in counts if FAILS.search(line)]
    for line, count in counts.most_common():
        print("%6d  %s" % (count, line))
    if failing:
        print("\nthe web server stopped a page or failed a query %d way(s):" % len(failing), file=sys.stderr)
        for line in failing:
            print("  " + line, file=sys.stderr)
        sys.exit(1)
    print("\nno PHP error and no failed query in %d line(s) of the logs" % len(lines))


if __name__ == "__main__":
    main()
