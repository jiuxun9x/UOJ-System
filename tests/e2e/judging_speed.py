"""How fast submissions are judged, and where the time goes.

Not a test: it measures, prints a table and writes what it measured as JSON. It runs on a site
that nothing else uses (the "Judging speed" job of the Tests workflow):

    python3 tests/e2e/judging_speed.py latency judging-speed/latency.json
    python3 tests/e2e/judging_speed.py burst judging-speed/burst-1.json
    python3 tests/e2e/judging_speed.py report judging-speed

latency: one submission at a time, each after the judgers have been idle for a few seconds,
so that what is measured is what a student waits for when nobody else submits. The times of a
judgement come from the line "timing {...}" judge_client logs for every task.

burst: many submissions at once, as at the start of an exam: how long until all are judged.
"""

import json
import os
import platform
import random
import statistics
import subprocess
import sys
import time

import uoj
from fixtures import AB, AB_VALIDATOR
from uoj import conf

AB_BITS = r"""#include <bits/stdc++.h>
using namespace std;
int main() {
    long long a, b;
    cin >> a >> b;
    cout << a + b << endl;
}
"""

# what is measured: (name, problem, program, language)
SCENARIOS = [
    ("cstdio C++17", "ab", AB, "C++17"),
    ("bits C++14", "ab", AB_BITS, "C++14"),
    ("bits C++17", "ab", AB_BITS, "C++17"),
    ("bits C++20", "ab", AB_BITS, "C++20"),
    ("bits C++23", "ab", AB_BITS, "C++23"),
    ("cstdio C++17, 100 tests", "many", AB, "C++17"),
]
REPEATS = int(os.environ.get("UOJ_SPEED_REPEATS", "5"))
BURST = int(os.environ.get("UOJ_SPEED_BURST", "24"))

# the containers of the judgers that are measured, where they are not those of the tests
if os.environ.get("UOJ_SPEED_JUDGERS"):
    uoj.JUDGERS = os.environ["UOJ_SPEED_JUDGERS"].split(",")

PHASES = ["fetch_ms", "data_ms", "download_ms", "startup_ms", "compile_ms", "tests_ms", "upload_ms"]


def ab_files(n_tests):
    settings = dict(
        use_builtin_judger="on", use_builtin_checker="ncmp", n_tests=n_tests, n_ex_tests=0, n_sample_tests=0,
        input_pre="input", input_suf="txt", output_pre="output", output_suf="txt",
        time_limit=1, memory_limit=256,
    )  # fmt: skip
    files = {"problem.conf": conf(**settings), "std.cpp": AB, "val.cpp": AB_VALIDATOR}
    rng = random.Random(n_tests)
    for num in range(1, n_tests + 1):
        a, b = rng.randint(0, 10**9), rng.randint(0, 10**9)
        files["input%d.txt" % num] = "%d %d\n" % (a, b)
        files["output%d.txt" % num] = "%d\n" % (a + b)
    return files


TITLES = {"ab": "judging speed: A+B", "many": "judging speed: A+B, 100 tests"}


def problems():
    """the problems that are measured on, made the first time"""
    # the site is up, and the first user is its administrator
    uoj.admin()
    made = {}
    for key, title in TITLES.items():
        found = uoj.db_value("select max(id) from problems where title = '%s'" % title)
        if found in (None, "NULL"):
            admin = uoj.admin()
            problem_id = admin.new_problem(title=title)
            r = admin.upload_data(problem_id, ab_files(100 if key == "many" else 3))
            if "上传成功" not in r.text:
                raise Exception("failed to upload the data: " + uoj.text_of(r.text)[-800:])
            err = admin.sync(problem_id)
            if err:
                raise Exception("failed to sync problem #%d: %s" % (problem_id, err[-800:]))
            uoj.db("update problems set is_hidden = 0 where id = %d" % problem_id)
            found = problem_id
        made[key] = int(found)
    return made


def submit(client, problem_id, code, language):
    """submit, return the id and the time the page answered at"""
    err = client.submit_form("/problem/%d" % problem_id, "answer", {
        "answer_answer_upload_type": "editor",
        "answer_answer_editor": code,
        "answer_answer_language": language,
    })  # fmt: skip
    submitted_at = time.time()
    if err:
        raise Exception("failed to submit: " + err[-800:])
    submission_id = int(uoj.db_value(
        "select max(id) from submissions where submitter = '%s' and problem_id = %d" % (client.username, problem_id)
    ))  # fmt: skip
    return submission_id, submitted_at


def wait_data_everywhere(problem_ids, timeout=300):
    """every judger holds the data of these problems"""

    def held():
        for judger in uoj.JUDGERS:
            for problem_id in problem_ids:
                p = uoj.run("docker", "exec", judger, "test", "-f", "/opt/uoj_judger/uoj_judger/data/%d.version" % problem_id, check=False)
                if p.returncode != 0:
                    return False
        return True

    uoj.wait_until("every judger holds the data", held, timeout, interval=2)


def environment():
    def out(*cmd):
        try:
            return subprocess.run(list(cmd), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL).stdout.decode().strip()
        except OSError:
            return ""

    model = ""
    for line in out("lscpu").splitlines():
        if line.startswith("Model name:"):
            model = line.split(":", 1)[1].strip()
    return {
        "judgers": len(uoj.JUDGERS),
        "cpus": os.cpu_count(),
        "cpu": model,
        "machine": platform.machine(),
        "commit": os.environ.get("GITHUB_SHA", out("git", "rev-parse", "HEAD"))[:12],
        "judger_image": out("docker", "image", "ls", "--format", "{{.Size}}", "ghcr.io/universaloj/uoj-judger:latest"),
        # the precompiled headers in the image, MB by standard of C++
        "pch_mb": out("docker", "exec", uoj.JUDGERS[0], "sh", "-c", "cd /usr/local/lib/uoj-pch 2>/dev/null && du -sm * | tr '\\t\\n' ': '"),
    }


def measure_one(client, problem_id, code, language):
    submission_id, submitted_at = submit(client, problem_id, code, language)
    judgement = uoj.wait_submission(submission_id)
    event = uoj.task_timing("submission", submission_id)
    row = {name: event.get(name) for name in PHASES + ["judger_ms", "total_ms", "tests", "reports", "report_ms", "data_fetched"]}
    row["id"] = submission_id
    row["pickup_ms"] = int(round((event["fetched_at"] - submitted_at) * 1000))
    row["turnaround_ms"] = int(round((event["fetched_at"] + event["total_ms"] / 1000.0 - submitted_at) * 1000))
    row["score"] = judgement.score
    row["error"] = judgement.error
    return row


def latency(out_path):
    made = problems()
    wait_data_everywhere(made.values())
    client = uoj.admin()
    # the first judgement of each kind warms what is cached: the compiler, the headers, the data
    for name, problem, code, language in SCENARIOS:
        measure_one(client, made[problem], code, language)

    results = {"environment": environment(), "scenarios": {}}
    for name, problem, code, language in SCENARIOS:
        rows = []
        for _ in range(REPEATS):
            # judgers that have been idle for a while, asked at a random moment of their wait
            time.sleep(random.uniform(2.5, 4.5))
            rows.append(measure_one(client, made[problem], code, language))
        results["scenarios"][name] = rows
        print("measured", name, file=sys.stderr)
    write(out_path, results)
    print_latency(results)


def burst(out_path):
    made = problems()
    wait_data_everywhere(made.values())
    client = uoj.admin()
    uoj.wait_idle()
    time.sleep(3)
    first = None
    ids = []
    for _ in range(BURST):
        submission_id, submitted_at = submit(client, made["ab"], AB_BITS, "C++17")
        first = first or submitted_at
        ids.append(submission_id)
    for submission_id in ids:
        uoj.wait_submission(submission_id, timeout=900)
    found = uoj.task_timings()
    events = [found[("submission", i)] for i in ids if ("submission", i) in found]
    last = max(e["fetched_at"] + e["total_ms"] / 1000.0 for e in events)
    results = {
        "environment": environment(),
        "submissions": BURST,
        "makespan_ms": int(round((last - first) * 1000)),
        "per_minute": round(BURST / (last - first) * 60, 1),
        "judger_ms": [e["judger_ms"] for e in events],
        "compile_ms": [e["compile_ms"] for e in events],
        "by_judger": {j: sum(1 for e in events if e["judger"] == j) for j in uoj.JUDGERS},
        "scores": sorted(set(int(uoj.db_value("select ifnull(score, -1) from submissions where id = %d" % i)) for i in ids)),
    }
    write(out_path, results)
    print_burst(results)


def write(path, results):
    os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    with open(path, "w") as f:
        json.dump(results, f, indent=1, sort_keys=True)


def median(values):
    values = [v for v in values if v is not None]
    return int(statistics.median(values)) if values else None


def table(rows):
    widths = [max(len(str(row[i])) for row in rows) for i in range(len(rows[0]))]
    lines = []
    for n, row in enumerate(rows):
        lines.append("| " + " | ".join(str(cell).rjust(widths[i]) if i else str(cell).ljust(widths[i]) for i, cell in enumerate(row)) + " |")
        if n == 0:
            lines.append("|" + "|".join("-" * (w + 2) for w in widths) + "|")
    return "\n".join(lines)


def print_latency(results):
    env = results["environment"]
    head = ["scenario (median ms)", "pickup", "fetch", "data", "download", "startup", "compile", "tests", "upload", "judger", "turnaround", "min-max turnaround", "reports"]
    rows = [head]
    for name, entries in results["scenarios"].items():
        turnaround = [e["turnaround_ms"] for e in entries]
        row = [name, median(e["pickup_ms"] for e in entries)]
        row += [median(e[p] for e in entries) for p in PHASES + ["total_ms"]]
        row += [median(turnaround), "%d-%d" % (min(turnaround), max(turnaround)), median(e["reports"] for e in entries)]
        rows.append(row)
    text = "### One submission at a time (%d judger(s), %s, %d CPUs, commit %s, judger image %s)\n\n%s\n" % (
        env["judgers"], env["cpu"], env["cpus"], env["commit"], env["judger_image"], table(rows)
    )
    if env.get("pch_mb"):
        text += "\nprecompiled headers (MB): %s\n" % env["pch_mb"]
    text += "\npickup: from the answer of the submit page to the moment a judger had the task; judger: from then until the result was stored; turnaround: both.\n"
    emit(text)


def print_burst(results):
    env = results["environment"]
    text = "### %d submissions at once, %d judger(s) (%s, %d CPUs, commit %s)\n\n" % (
        results["submissions"], env["judgers"], env["cpu"], env["cpus"], env["commit"]
    )
    text += table([
        ["all judged after (ms)", "per minute", "median judger ms", "median compile ms", "by judger", "scores"],
        [results["makespan_ms"], results["per_minute"], median(results["judger_ms"]), median(results["compile_ms"]),
         " ".join("%s:%d" % (j.replace("uoj-", ""), n) for j, n in sorted(results["by_judger"].items())), results["scores"]],
    ])  # fmt: skip
    emit(text + "\n")


def emit(text):
    print(text)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a") as f:
            f.write(text + "\n")


def report(folder):
    for name in sorted(os.listdir(folder)):
        if not name.endswith(".json"):
            continue
        with open(os.path.join(folder, name)) as f:
            results = json.load(f)
        if "scenarios" in results:
            print_latency(results)
        else:
            print_burst(results)


if __name__ == "__main__":
    command, target = sys.argv[1], sys.argv[2]
    {"latency": latency, "burst": burst, "report": report}[command](target)
