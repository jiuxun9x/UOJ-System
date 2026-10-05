"""Helpers of the end-to-end tests: a client for the web interface of UOJ and access to
the containers started by docker compose."""

import contextlib
import hashlib
import hmac
import io
import json
import os
import re
import subprocess
import time
import zipfile

import requests

BASE_URL = os.environ.get("UOJ_URL", "http://localhost")
WEB = "uoj-web"
DB = "uoj-db"
# the judgers that were started: the containers, the names they log in with, and their logs
N_JUDGERS = int(os.environ.get("UOJ_E2E_JUDGERS", "2"))
JUDGERS = ["uoj-judger", "uoj-judger-2", "uoj-judger-3", "uoj-judger-4"][:N_JUDGERS]
JUDGER_NAMES = ["compose_judger", "compose_judger_2", "compose_judger_3", "compose_judger_4"][:N_JUDGERS]
JUDGER_LOGS = {
    "uoj-judger": "uoj_data/judger/log/judge.log",
    "uoj-judger-2": "uoj_data/judger2/log/judge.log",
    "uoj-judger-3": "uoj_data/judger3/log/judge.log",
    "uoj-judger-4": "uoj_data/judger4/log/judge.log",
}
FAKE_JUDGER = {"judger_name": "e2e_fake_judger", "password": "_fake_judger_password_"}

ADMIN = ("e2e_admin", "admin-password")
MANAGER = ("e2e_manager", "manager-password")


# ---------------------------------------------------------------------- containers


def run(*cmd, check=True, stdin=None):
    p = subprocess.run(list(cmd), input=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and p.returncode != 0:
        raise Exception(
            "%r failed with status %d\n%s\n%s"
            % (cmd, p.returncode, p.stdout.decode(errors="replace"), p.stderr.decode(errors="replace"))
        )
    return p


def docker_exec(container, script, check=True):
    """run a shell script in a container, return what it prints"""
    return run("docker", "exec", container, "sh", "-c", script, check=check).stdout.decode(
        errors="replace"
    )


def db(sql):
    """run a query, return its rows as lists of strings"""
    p = run(
        "docker", "exec", "-i", DB,
        "mysql", "-uroot", "-proot", "--default-character-set=utf8mb4", "-N", "-B", "app_uoj233",
        stdin=sql.encode(),
    )  # fmt: skip
    return [line.split("\t") for line in p.stdout.decode().split("\n") if line != ""]


def db_value(sql):
    rows = db(sql)
    return rows[0][0] if rows else None


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def file_sha256(container, path):
    return docker_exec(container, "sha256sum %s" % path).split()[0]


def tree_sha256(container, path):
    """a hash of the names and the contents of all files in a folder"""
    return docker_exec(
        container,
        "cd %s && find . -type f | LC_ALL=C sort | xargs sha256sum | sha256sum" % path,
    ).split()[0]


def files_sha256(container, path):
    """the SHA256 of every file in a folder, by name"""
    out = docker_exec(container, "cd %s && find . -type f | LC_ALL=C sort | xargs -r sha256sum" % path)
    return dict(reversed(line.split(None, 1)) for line in out.splitlines())


def data_syncs(judger, problem_id):
    """what a judger logged every time it fetched the data of a problem"""
    events = []
    for line in judger_log(judger).splitlines():
        if "] problem_data_sync {" in line:
            event = json.loads(line[line.index("{") :])
            if event["problem_id"] == problem_id:
                events.append(event)
    return events


def judger_log(judger):
    p = run("sudo", "cat", JUDGER_LOGS[judger], check=False)
    return p.stdout.decode(errors="replace")


def wait_until(what, condition, timeout=300, interval=1):
    deadline = time.time() + timeout
    while True:
        value = condition()
        if value:
            return value
        if time.time() > deadline:
            raise Exception("timed out waiting until " + what)
        time.sleep(interval)


def make_zip(files):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        for name, content in files.items():
            z.writestr(name, content)
    return buf.getvalue()


def pid(problem_id):
    """the number people know a problem by: its id on the site, its number in its domain"""
    return int(db_value("select ifnull(domain_pid, id) from problems where id = %d" % problem_id))


def conf(**settings):
    return "".join("%s %s\n" % (key, val) for key, val in settings.items())


# ---------------------------------------------------------------------- judge API


def judge_api(path, data=None, files=None, auth=FAKE_JUDGER):
    """call the API of the judgers the way judge_client does"""
    payload = dict(auth)
    payload.update(data or {})
    return requests.post(BASE_URL + path, data=payload, files=files)


def fake_fetch(**fields):
    """ask for work the way a judger does, return the task or None"""
    data = {"protocol": "2", "judger_version": "e2e-fake", "toolchain": "{}"}
    data.update(fields)
    r = judge_api("/judge/submit", data)
    if r.status_code != 200:
        raise Exception("HTTP %d: %s" % (r.status_code, r.text[:300]))
    return None if r.text == "Nothing to judge" else r.json()


def wait_idle(timeout=600):
    """wait until the judgers have nothing left to do"""

    def idle():
        return (
            db_value("select count(*) from submissions where status != 'Judged'") == "0"
            and db_value("select count(*) from custom_test_submissions where status != 'Judged'") == "0"
            and db_value("select count(*) from hacks where success is null") == "0"
            and db_value("select count(*) from problem_data_versions where status in ('pending', 'preparing')") == "0"
        )

    wait_until("the judgers are idle", idle, timeout)


@contextlib.contextmanager
def judgers_paused():
    """freeze the judgers, so that what is submitted meanwhile waits for the test"""
    wait_idle()
    for judger in JUDGERS:
        run("docker", "pause", judger)
    try:
        yield
    finally:
        for judger in JUDGERS:
            run("docker", "unpause", judger)


# ---------------------------------------------------------------------- web interface


class Client:
    def __init__(self):
        self.http = requests.Session()
        self.username = None
        r = self.http.get(BASE_URL + "/login")
        r.raise_for_status()
        self.token = re.search(r'_token : "([0-9a-zA-Z]+)"', r.text).group(1)
        self.salt = re.search(r"\.val\(\), \"([^\"]*)\"\)", r.text).group(1)

    def password_hash(self, password):
        return hmac.new(self.salt.encode(), password.encode(), "md5").hexdigest()

    def register(self, username, password):
        r = self.http.post(BASE_URL + "/register", data={
            "_token": self.token,
            "register": "",
            "username": username,
            "email": username + "@example.com",
            "password": self.password_hash(password),
        })  # fmt: skip
        if not r.text.startswith("欢迎你"):
            raise Exception("failed to register %s: %s" % (username, r.text[:200]))

    def login(self, username, password):
        r = self.http.post(BASE_URL + "/login", data={
            "_token": self.token,
            "login": "",
            "username": username,
            "password": self.password_hash(password),
        })  # fmt: skip
        if r.text != "ok":
            raise Exception("failed to log in as %s: %s" % (username, r.text[:200]))
        self.username = username
        self.password = password

    def update_profile(self, token=True, **changes):
        """post the form of the profile, return what the server answers: 'ok' or why not"""
        data = {
            "change": "",
            "username": self.username,
            # db() drops empty lines, so an empty nickname comes back as no row at all
            "nickname": db_value("select nickname from user_info where username = '%s'" % self.username) or "",
            "email": self.username + "@example.com",
            "old_password": self.password_hash(self.password),
            "ptag": "0",
            "Qtag": "0",
            "sex": "U",
            "motto": "",
        }
        data.update(changes)
        r = self.post("/user/modify-profile", data, token=token)
        if r.text == "ok":
            self.username = data["username"]
        return r.text

    def post(self, path, data=None, files=None, token=True):
        data = dict(data or {})
        if token:
            data["_token"] = self.token
        return self.http.post(BASE_URL + path, data=data, files=files, allow_redirects=False)

    def get(self, path, **kwargs):
        return self.http.get(BASE_URL + path, allow_redirects=False, **kwargs)

    def submit_form(self, path, form, fields=None, files=None):
        """submit a UOJForm, return '' on success or the text of the page that reports the error"""
        data = dict(fields or {})
        data["submit-" + form] = form
        r = self.post(path, data, files)
        if r.status_code in (301, 302):
            return ""
        if r.status_code == 200 and r.text == "":
            return ""
        return "HTTP %d: %s" % (r.status_code, text_of(r.text))

    # ---- problems

    def new_problem_form(self, domain=None, files=None, **fields):
        """send the form that makes a problem, on the site or in the domain with this slug:
        '' or why it was refused. files: (field, (name, content, type)) pairs."""
        form = {"form": "create", "title": "New Problem", "type": "traditional", "time_limit": "1", "memory_limit": "256",
                "checker": "wcmp", "scoring": "per_test"}  # fmt: skip
        form.update(fields)
        r = self.post("/d/%s/problem/new" % domain if domain else "/problem/new", form, files)
        if r.status_code in (301, 302):
            return ""
        return "HTTP %d: %s" % (r.status_code, text_of(r.text))

    def new_problem(self, domain=None, files=None, **fields):
        """make a problem and return its id: hidden and without data unless told otherwise"""
        err = self.new_problem_form(domain, files, **fields)
        if err:
            raise Exception("failed to create a problem: " + err[-800:])
        if domain:
            return int(db_value(
                "select max(problems.id) from problems, domains where domains.slug = '%s' and owner_domain_id = domains.id" % domain
            ))  # fmt: skip
        # the problems of domains have ids of their own, far above those of the site
        return int(db_value("select max(id) from problems where owner_domain_id is null"))

    def copy_problem(self, slug, source):
        """copy a problem into a domain: source is a number of the site, or '<slug>#<number>';
        returns the id of the copy"""
        err = self.form("/d/%s/problems" % slug, "copy", problem_id=str(source))
        if err:
            raise Exception("failed to copy problem %s: %s" % (source, err[-600:]))
        return int(db_value(
            "select max(problems.id) from problems, domains where domains.slug = '%s' and owner_domain_id = domains.id" % slug
        ))  # fmt: skip

    def upload_data(self, problem_id, files, token=True):
        return self.post(
            "/problem/%d/manage/data" % problem_id,
            {"problem_data_file_submit": "submit"},
            {"problem_data_file": ("data.zip", make_zip(files), "application/zip")},
            token=token,
        )

    def sync(self, problem_id, wait=True):
        """sync the data of a problem, return '' or why there is no new version of the data"""
        err = self.submit_form("/problem/%d/manage/data" % problem_id, "data")
        if err or not wait:
            return err
        return wait_data_version(problem_id)

    def toggle_hackable(self, problem_id, wait=True):
        err = self.submit_form("/problem/%d/manage/data" % problem_id, "hackable")
        if err or not wait:
            return err
        return wait_data_version(problem_id)

    def create_problem(self, files, extra_config=None, hackable=False):
        """create a public problem with the given data, return its id"""
        problem_id = self.new_problem()
        r = self.upload_data(problem_id, files)
        if "上传成功" not in r.text:
            raise Exception("failed to upload the data: " + text_of(r.text)[-800:])
        if extra_config is not None:
            db("update problems set extra_config = '%s' where id = %d" % (json.dumps(extra_config), problem_id))
        err = self.sync(problem_id)
        if err:
            raise Exception("failed to sync problem #%d: %s" % (problem_id, err[-800:]))
        db("update problems set is_hidden = 0 where id = %d" % problem_id)
        if hackable:
            err = self.toggle_hackable(problem_id)
            if err:
                raise Exception("failed to enable hacks of problem #%d: %s" % (problem_id, err[-800:]))
        return problem_id

    # ---- contests

    def new_contest(self, name, starts_in=3600, minutes=60, domain=None, **settings):
        """create a contest that starts so many seconds from now, return its id; domain is the
        slug of the domain it is made in"""
        fields = {"name": name, "start_time": web_time(starts_in), "last_min": str(minutes),
                  "rule": "OI", "standings_version": "2", "join_mode": "open", "problems": ""}  # fmt: skip
        if domain is None:
            # as the form of somebody who decides about ratings has it from the start
            fields["rated"] = "on"
        fields.update(settings)
        fields = {key: value for key, value in fields.items() if value is not None}
        err = self.form("/d/%s/contest/new" % domain if domain else "/contest/new", "create", **fields)
        if err:
            raise Exception("failed to create a contest: " + err[-800:])
        return int(db_value("select max(id) from contests"))

    def contest_settings(self, contest_id, **changes):
        """save the settings of a contest with some of them changed: '' or why it was refused.
        rated=None leaves the box unticked."""
        name, start_time, last_min, extra_config, freeze_minutes, join_mode = db(
            "select name, start_time, last_min, extra_config, freeze_minutes, join_mode from contests where id = %d" % contest_id
        )[0]
        config = json.loads(extra_config)
        fields = {
            "name": name, "start_time": start_time, "last_min": last_min,
            "rule": {"ACM": "ICPC"}.get(config.get("contest_type", "OI"), config.get("contest_type", "OI")),
            "freeze_minutes": freeze_minutes, "standings_version": str(config.get("standings_version", 2)),
            "rating_k": str(config.get("rating_k", 400)), "join_mode": join_mode, "tab": "settings",
        }  # fmt: skip
        if "unrated" not in config:
            fields["rated"] = "on"
        fields.update(changes)
        fields = {key: value for key, value in fields.items() if value is not None}
        return self.form("/contest/%d/manage" % contest_id, "settings", **fields)

    def contest_commands(self, contest_id, form, commands):
        """add and remove problems or managers of a contest, one a line: '+12' and '-12' for
        the problem with the number 12, '+mike', '+mike [owner]' and '-mike' for a manager.
        Returns '' or why the first of them that was refused was."""
        manage = "/contest/%d/manage" % contest_id
        for command in commands.split("\n"):
            sign, rest = command[0], command[1:].strip()
            if form == "problems":
                if sign == "+":
                    err = self.form(manage, "add_problem", number=rest, tab="problems")
                else:
                    domain = db_value("select ifnull(domain_id, 'NULL') from contests where id = %d" % contest_id)
                    where = "owner_domain_id is null and id = %s" % rest if domain == "NULL" else "owner_domain_id = %s and domain_pid = %s" % (domain, rest)
                    err = self.form(manage, "remove_problem", problem_id=db_value("select id from problems where " + where) or "0", tab="problems")
            else:
                if sign == "+":
                    username, _, role = rest.partition(" ")
                    err = self.form(manage, "add_manager", username=username, role=role.strip("[] ") or "assistant", tab="managers")
                else:
                    err = self.form(manage, "remove_manager", username=rest, tab="managers")
            if err:
                return err
        return ""

    def register_for_contest(self, contest_id):
        err = self.submit_form("/contest/%d/register" % contest_id, "register")
        if err:
            raise Exception("failed to register for contest #%d: %s" % (contest_id, err[-800:]))

    def submit_in_contest(self, contest_id, problem_id, code, language="C++17"):
        err = self.submit_form("/contest/%d/problem/%d" % (contest_id, pid(problem_id)), "answer", {
            "answer_answer_upload_type": "editor",
            "answer_answer_editor": code,
            "answer_answer_language": language,
        })  # fmt: skip
        if err:
            raise Exception("failed to submit: " + err[-800:])
        return int(db_value(
            "select max(id) from submissions where submitter = '%s' and problem_id = %d"
            % (self.username, problem_id)
        ))  # fmt: skip

    # ---- domains

    def form(self, path, form, **fields):
        """post one of the plain forms of the pages of the domains: '' when it went through and
        the page was loaded again, else the status and the text of the page that refused it"""
        fields["form"] = form
        r = self.post(path, fields)
        if r.status_code in (301, 302):
            return ""
        return "HTTP %d: %s" % (r.status_code, text_of(r.text))

    def new_domain(self, slug, **settings):
        """create a domain"""
        fields = {"name": "域 " + slug, "slug": slug, "description": "", "type": "course"}
        fields.update(settings)
        err = self.form("/domain/new", "create", **fields)
        if err:
            raise Exception("failed to create the domain %s: %s" % (slug, err[-600:]))
        return int(db_value("select id from domains where slug = '%s'" % slug))

    # ---- administration

    def change_user(self, username, operation):
        """the user form of the administrators, returns '' or why it was refused"""
        return self.submit_form("/super-manage/users", "user", {"username": username, "op-type": operation})

    # ---- submissions

    def submit(self, problem_id, code, language="C++17", path=None):
        """submit to a problem; path is the address of the problem where it is not /problem/<id>"""
        err = self.submit_form(path or "/problem/%d" % problem_id, "answer", {
            "answer_answer_upload_type": "editor",
            "answer_answer_editor": code,
            "answer_answer_language": language,
        })  # fmt: skip
        if err:
            raise Exception("failed to submit: " + err[-800:])
        return int(db_value(
            "select max(id) from submissions where submitter = '%s' and problem_id = %d"
            % (self.username, problem_id)
        ))  # fmt: skip

    def custom_test(self, problem_id, code, input_text, language="C++17"):
        err = self.submit_form("/problem/%d" % problem_id, "custom_test", {
            "custom_test_answer_upload_type": "editor",
            "custom_test_answer_editor": code,
            "custom_test_answer_language": language,
            "custom_test_input_upload_type": "editor",
            "custom_test_input_editor": input_text,
        })  # fmt: skip
        if err:
            raise Exception("failed to submit a custom test: " + err[-800:])
        return int(db_value(
            "select max(id) from custom_test_submissions where submitter = '%s' and problem_id = %d"
            % (self.username, problem_id)
        ))  # fmt: skip

    def hack(self, submission_id, data, use_formatter=False):
        fields = {"input_upload_type": "file"}
        if use_formatter:
            fields["use_formatter"] = "on"
        err = self.submit_form(
            "/submission/%d" % submission_id, "hack", fields,
            {"input_file": ("hack.txt", data, "application/octet-stream")},
        )  # fmt: skip
        if err:
            raise Exception("failed to hack: " + err[-800:])
        return int(db_value("select max(id) from hacks where submission_id = %d" % submission_id))


def web_time(offset=0):
    """a time on the clock of the web server, the way the forms and the database write it"""
    return docker_exec(WEB, "php -r 'echo date(\"Y-m-d H:i:s\", time() + (%d));'" % offset).strip()


def move_contest(contest_id, starts_in, minutes=60):
    """move a contest in time: a negative start is in the past"""
    db(
        "update contests set start_time = '%s', last_min = %d where id = %d"
        % (web_time(starts_in), minutes, contest_id)
    )


def columns_holding(value):
    """every text column of the database that holds exactly this value, as 'table.column'"""
    columns = db(
        "select table_name, column_name from information_schema.columns"
        " where table_schema = 'app_uoj233' and data_type in ('char', 'varchar')"
        " and character_maximum_length >= %d" % len(value)
    )
    queries = [
        "(select '%s.%s' from `%s` where `%s` = '%s' limit 1)" % (table, column, table, column, value)
        for table, column in columns
    ]
    return sorted(row[0] for row in db(" union all ".join(queries)))


def wait_data_version(problem_id, timeout=600):
    """wait until no version of the data of a problem waits for a judger any more, return '' when
    the newest version is published and the message of the judger when it is not"""

    def newest():
        row = db(
            "select status, ifnull(hex(message), '') from problem_data_versions"
            " where problem_id = %d order by version desc limit 1" % problem_id
        )[0]
        return row if row[0] in ("ready", "failed") else None

    status, message = wait_until("the data of problem #%d is checked" % problem_id, newest, timeout)
    if status == "ready":
        return ""
    return bytes.fromhex(message).decode(errors="replace") or "the version was not published"


def text_of(html):
    """the text of a page without its markup"""
    body = re.sub(r"(?s)<(script|style|head)\b.*?</\1>", " ", html)
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", body)).strip()


# ---------------------------------------------------------------------- results


class Judgement:
    def __init__(self, status, score, used_time, used_memory, raw_result):
        self.status = status
        self.score = None if score == "NULL" else int(score)
        self.used_time = int(used_time)
        self.used_memory = int(used_memory)
        self.raw_result = raw_result
        self.result = json.loads(raw_result.decode())
        self.error = self.result.get("error")
        self.details = self.result.get("details", "")
        # the verdict of every test, in order
        self.infos = re.findall(r'<(?:test|custom-test)\b[^>]*\binfo="([^"]*)"', self.details)

    def __repr__(self):
        return "<Judgement %s score=%s error=%s infos=%s details=%r>" % (
            self.status, self.score, self.error, self.infos[:8], self.details[:1500],
        )  # fmt: skip


def get_submission(submission_id):
    row = db(
        "select status, ifnull(score, 'NULL'), used_time, used_memory, hex(result)"
        " from submissions where id = %d" % submission_id
    )[0]
    if row[0] != "Judged":
        return None
    return Judgement(row[0], row[1], row[2], row[3], bytes.fromhex(row[4]))


def wait_submission(submission_id, timeout=300):
    return wait_until(
        "submission #%d is judged" % submission_id, lambda: get_submission(submission_id), timeout
    )


def wait_custom_test(custom_test_id, timeout=300):
    def get():
        row = db("select status, hex(result) from custom_test_submissions where id = %d" % custom_test_id)[0]
        if row[0] != "Judged":
            return None
        return Judgement(row[0], "NULL", 0, 0, bytes.fromhex(row[1]))

    return wait_until("custom test #%d is judged" % custom_test_id, get, timeout)


def wait_hack(hack_id, timeout=300):
    """wait until a hack is judged, return whether it succeeded"""

    def get():
        success = db_value("select ifnull(success, 'NULL') from hacks where id = %d" % hack_id)
        return None if success == "NULL" else success

    return wait_until("hack #%d is judged" % hack_id, get, timeout) == "1"


# ---------------------------------------------------------------------- shared state


def judgements(kind, target_id):
    """who judged something: (judger, outcome) of every time it was given to a judger"""
    return [
        tuple(row)
        for row in db(
            "select judger_name, ifnull(outcome, 'NULL') from submission_judgements"
            " where kind = '%s' and target_id = %d order by id" % (kind, target_id)
        )
    ]


def wait_for_web(timeout=900):
    def ready():
        try:
            return requests.get(BASE_URL + "/login", timeout=5).status_code == 200
        except requests.RequestException:
            return False

    wait_until("the web server answers", ready, timeout, interval=3)


_clients = {}


def client(account):
    """a logged in client, the first account that registers becomes the super user"""
    username, password = account
    if username not in _clients:
        c = Client()
        if db_value("select count(*) from user_info where username = '%s'" % username) == "0":
            c.register(username, password)
        c.login(username, password)
        _clients[username] = c
    return _clients[username]


def admin():
    wait_for_web()
    return client(ADMIN)


def manager():
    admin()
    return client(MANAGER)
