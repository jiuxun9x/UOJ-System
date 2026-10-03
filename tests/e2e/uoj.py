"""Helpers of the end-to-end tests: a client for the web interface of UOJ and access to
the containers started by docker compose."""

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
JUDGERS = ["uoj-judger", "uoj-judger-2"]
JUDGER_LOGS = {"uoj-judger": "uoj_data/judger/log/judge.log", "uoj-judger-2": "uoj_data/judger2/log/judge.log"}
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


def conf(**settings):
    return "".join("%s %s\n" % (key, val) for key, val in settings.items())


# ---------------------------------------------------------------------- judge API


def judge_api(path, data=None, files=None, auth=FAKE_JUDGER):
    """call the API of the judgers the way judge_client does"""
    payload = dict(auth)
    payload.update(data or {})
    return requests.post(BASE_URL + path, data=payload, files=files)


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

    def post(self, path, data=None, files=None, token=True):
        data = dict(data or {})
        if token:
            data["_token"] = self.token
        return self.http.post(BASE_URL + path, data=data, files=files, allow_redirects=False)

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

    def new_problem(self):
        err = self.submit_form("/problems", "new_problem")
        if err:
            raise Exception("failed to create a problem: " + err[-800:])
        return int(db_value("select max(id) from problems"))

    def upload_data(self, problem_id, files, token=True):
        return self.post(
            "/problem/%d/manage/data" % problem_id,
            {"problem_data_file_submit": "submit"},
            {"problem_data_file": ("data.zip", make_zip(files), "application/zip")},
            token=token,
        )

    def sync(self, problem_id):
        return self.submit_form("/problem/%d/manage/data" % problem_id, "data")

    def toggle_hackable(self, problem_id):
        return self.submit_form("/problem/%d/manage/data" % problem_id, "hackable")

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

    # ---- submissions

    def submit(self, problem_id, code, language="C++17"):
        err = self.submit_form("/problem/%d" % problem_id, "answer", {
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
        return "<Judgement %s score=%s error=%s infos=%s>" % (
            self.status, self.score, self.error, self.infos[:8],
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
