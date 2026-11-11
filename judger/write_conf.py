#!/usr/bin/env python3
"""Writes the .conf.json of judge_client from what install.sh hands it in the environment, every
value as JSON has it. (The file was a text with the values put in as they were: a password with
a quote, a backslash or a line break in it made a file that was no JSON, or that said another
password, and the judger did not start.)

    write_conf.py FILE
"""

import json
import os
import sys


def text(name, default=""):
    return os.environ.get(name) or default


def number(name, default):
    value = os.environ.get(name, "").strip()
    return int(value) if value else default


def flag(name, default):
    value = os.environ.get(name, "").strip().lower()
    if not value:
        return default
    return value not in ("false", "0", "no", "off")


def conf():
    return {
        "uoj_protocol": text("UOJ_PROTOCOL"),
        "uoj_host": text("UOJ_HOST"),
        "judger_name": text("JUDGER_NAME"),
        "judger_password": text("JUDGER_PASSWORD"),
        "socket_port": number("SOCKET_PORT", 2333),
        "socket_password": text("SOCKET_PASSWORD"),
        "max_judging_seconds": number("MAX_JUDGING_SECONDS", 3600),
        "data_sync": flag("DATA_SYNC", True),
        "data_cache_problems": number("DATA_CACHE_PROBLEMS", 300),
        "long_poll_seconds": number("LONG_POLL_SECONDS", 20),
        "worker_label": text("WORKER_LABEL"),
    }


def main(path):
    with open(path, "w") as f:
        json.dump(conf(), f, indent=4)
        f.write("\n")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__.strip())
    main(sys.argv[1])
