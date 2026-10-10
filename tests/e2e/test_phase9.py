"""End-to-end tests of phase 9: what a contest needs while it runs and after it (registration,
the frozen board, virtual participation, balloons, deletion), and how problems get in (several
copies at once, templates and packages).

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import test_phase3 as p3
import test_phase6 as p6
import uoj
from fixtures import *
from uoj import db, db_value


def setUpModule():
    uoj.admin()


def data_flash(client, problem_id):
    """what the page of the data of a problem says about what was done last: (kind, words)"""
    page = client.get("/problem/%d/manage/data" % problem_id).text
    found = re.search(r'(?s)class="alert alert-(\w+) text-left" role="alert" id="data-flash">(.*?)</div>', page)
    return found.groups() if found else (None, "")


class InputValidationTest(unittest.TestCase):
    """the inputs of a problem are run past a validator only where its settings say so, and
    what the settings say is seen in them"""

    REJECT_EVERYTHING = "int main() { return 1; }\n"

    def settings(self, admin, problem_id, **changes):
        fields = dict(form="judge_settings", type="multi_pass", passes="2", time_limit="1", memory_limit="256", checker="wcmp", scoring="per_test")
        fields.update(changes)
        r = admin.post("/problem/%d/manage/data" % problem_id, fields)
        self.assertEqual(r.status_code, 302, r.text[-300:])

    def test_validation_that_came_with_a_problem_conf_is_seen_and_turned_off(self):
        admin = uoj.admin()
        problem_id = admin.new_problem(title="p9 通信题")
        manage = "/problem/%d/manage/data" % problem_id
        # ---- a problem.conf as it came from somewhere, with the line in it and no validator
        self.assertIn("上传成功", admin.upload_data(problem_id, multi_pass_problem_files(validate_input_before_test="on")).text)
        # The data is not published, and it is said why: it used to be published, and every
        # submission then failed at a validator that was not there.
        self.assertIn("validate_input_before_test is on, but the problem has no validator", admin.sync(problem_id))
        self.assertEqual(db_value("select count(*) from problem_data_versions where problem_id = %d and status = 'ready'" % problem_id), "0")
        # the settings show what the problem.conf says, and the check of the files says what is missing
        page = admin.get(manage).text
        self.assertRegex(page, r'id="input-problem-validate_input" name="validate_input" checked="checked"')
        self.assertIn("缺少文件", uoj.text_of(page))

        # ---- the settings are saved without the box: the line is gone, the data is published,
        # and a program is judged
        self.settings(admin, problem_id)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertNotIn("validate_input_before_test", published_conf(problem_id))
        self.assertRegex(admin.get(manage).text, r'id="input-problem-validate_input" name="validate_input" />')
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, messages_solution())).score, 100)

        # ---- with the box and no validator nothing new is published, and it is said why
        versions = db_value("select count(*) from problem_data_versions where problem_id = %d and status = 'ready'" % problem_id)
        self.settings(admin, problem_id, validate_input="on")
        kind, said = data_flash(admin, problem_id)
        self.assertIn("还没有数据校验器", said)
        self.assertEqual(db_value("select count(*) from problem_data_versions where problem_id = %d and status = 'ready'" % problem_id), versions)

        # ---- with the box and a validator that was chosen, the inputs are validated
        self.assertEqual(p6.upload_files(admin, problem_id, {"accepts.cpp": ACCEPT_ANYTHING, "rejects.cpp": self.REJECT_EVERYTHING}).status_code, 302)
        self.assertRegex(admin.get(manage).text, r'(?s)<select[^>]*name="val_file".*?<option value="accepts.cpp">')
        self.settings(admin, problem_id, validate_input="on", val_file="accepts.cpp")
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["validate_input_before_test"], conf["val_source"]), ("on", "accepts.cpp"))
        self.assertEqual(uoj.wait_submission(admin.submit(problem_id, messages_solution())).score, 100)
        self.settings(admin, problem_id, validate_input="on", val_file="rejects.cpp")
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        refused = uoj.wait_submission(admin.submit(problem_id, messages_solution()))
        self.assertEqual((refused.score, set(refused.infos)), (0, {"Invalid Input"}))

    def test_problem_of_the_usual_kind_validates_nothing_unless_told(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        self.assertNotIn("validate_input_before_test", published_conf(problem_id))
        page = admin.get("/problem/%d/manage/data" % problem_id).text
        self.assertRegex(page, r'id="input-problem-validate_input" name="validate_input" />')
        # told to, with the validator that is called what validators are called
        self.settings(admin, problem_id, type="traditional", checker="ncmp", validate_input="on")
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        conf = published_conf(problem_id)
        self.assertEqual((conf["validate_input_before_test"], conf["val_source"]), ("on", "val.cpp"))
        solver = p3.account("p9_val_solver")
        self.assertEqual(uoj.wait_submission(solver.submit(problem_id, AB)).score, 100)
