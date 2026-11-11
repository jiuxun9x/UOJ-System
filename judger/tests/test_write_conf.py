"""What install.sh writes as the .conf.json of judge_client (write_conf.py): every value as JSON has
it. A password with a quote, a backslash or a line break in it, as workers.sh lets one be, was put
into the text of the file as it was: the file was no JSON, or said another password."""

import json
import os
import subprocess
import sys
import tempfile
import unittest

JUDGER = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
WRITE_CONF = os.path.join(JUDGER, "write_conf.py")


def written(**env):
    with tempfile.TemporaryDirectory() as folder:
        path = os.path.join(folder, ".conf.json")
        subprocess.run([sys.executable, WRITE_CONF, path], env=dict(os.environ, **env), check=True)
        with open(path) as f:
            return json.load(f)


class WriteConfTest(unittest.TestCase):
    def test_a_password_is_written_as_it_is(self):
        password = 'pa"ss\\word\nwith a line'
        conf = written(UOJ_PROTOCOL="http", UOJ_HOST="uoj-web", JUDGER_NAME="judger_1", JUDGER_PASSWORD=password,
                       SOCKET_PASSWORD='so"cket', WORKER_LABEL="room_1")  # fmt: skip
        self.assertEqual(conf["judger_password"], password)
        self.assertEqual(conf["socket_password"], 'so"cket')
        self.assertEqual((conf["uoj_protocol"], conf["uoj_host"], conf["judger_name"], conf["worker_label"]), ("http", "uoj-web", "judger_1", "room_1"))

    def test_what_is_not_said_is_what_it_was(self):
        conf = written(UOJ_PROTOCOL="http", UOJ_HOST="uoj-web", JUDGER_NAME="j", JUDGER_PASSWORD="p", SOCKET_PORT="", DATA_SYNC="")
        self.assertEqual(
            (conf["socket_port"], conf["max_judging_seconds"], conf["data_sync"], conf["data_cache_problems"], conf["long_poll_seconds"]),
            (2333, 3600, True, 300, 20),
        )
        conf = written(UOJ_PROTOCOL="http", UOJ_HOST="uoj-web", JUDGER_NAME="j", JUDGER_PASSWORD="p", SOCKET_PORT="4444", DATA_SYNC="false", LONG_POLL_SECONDS="5")
        self.assertEqual((conf["socket_port"], conf["data_sync"], conf["long_poll_seconds"]), (4444, False, 5))

    def test_install_writes_it_so(self):
        with open(os.path.join(JUDGER, "install.sh")) as f:
            install = f.read()
        self.assertIn("python3 /opt/uoj_judger/write_conf.py .conf.json", install)
        self.assertNotIn('"judger_password": "$JUDGER_PASSWORD"', install)


if __name__ == "__main__":
    unittest.main()
