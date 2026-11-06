"""Phase 16: what a review of the site found, mended. Each class is one of the defects, told by
what it did wrong and kept from coming back."""

import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account
from uoj import db, db_value


def members(client, slug, form, **fields):
    """the form of the members of a domain: '' or why it was refused"""
    return client.form("/d/%s/members" % slug, form, **fields)


def managers(problem_id):
    return [row[0] for row in db("select username from problems_permissions where problem_id = %d order by username" % problem_id)]


def title_of(problem_id):
    return db_value("select title from problems where id = %d" % problem_id)


class DomainProblemManagerTest(unittest.TestCase):
    """A problem of a domain could be given to anybody to manage, and stayed theirs when they
    left the domain or were taken out of it: from its address on the site they changed it still."""

    def test_who_is_taken_out_of_a_domain_manages_its_problems_no_more(self):
        admin = uoj.admin()
        teacher = account("p16_mgr_teacher")
        self.assertEqual(admin.change_user("p16_mgr_teacher", "grant:teacher"), "")
        teacher.new_domain("p16-managers")
        helper = account("p16_mgr_helper")
        outsider = account("p16_mgr_outsider")
        self.assertEqual(members(teacher, "p16-managers", "add", username="p16_mgr_helper", role="member"), "")
        problem_id = teacher.new_problem("p16-managers")
        manage = "/problem/%d/manage" % problem_id

        # it is given to members of the domain only
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "+p16_mgr_outsider"})
        self.assertEqual(managers(problem_id), [])
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "+p16_mgr_helper"})
        self.assertEqual(managers(problem_id), ["p16_mgr_helper"])
        self.assertEqual(helper.get(manage + "/statement").status_code, 200)
        self.assertIn("上传成功", teacher.upload_data(problem_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(problem_id), "")
        submission_id = teacher.submit(problem_id, AB, path="/d/p16-managers/problem/%d" % uoj.pid(problem_id))
        link = 'href="/submission/%d"' % submission_id
        self.assertIn(link, helper.get("/submissions?problem_id=%d" % problem_id).text)

        # taken out of the domain, the helper manages it no more, from no address
        self.assertEqual(members(teacher, "p16-managers", "remove", username="p16_mgr_helper"), "")
        self.assertEqual(managers(problem_id), [])
        for page in ("statement", "data", "managers"):
            self.assertIn(helper.get("%s/%s" % (manage, page)).status_code, (403, 404), page)
        before = title_of(problem_id)
        helper.post(manage + "/statement", {"problem_title": "p16 changed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""})
        self.assertEqual(title_of(problem_id), before)
        self.assertNotIn(link, helper.get("/submissions?problem_id=%d" % problem_id).text)

        # nor does anybody who was given it before this was so, and is not in the domain
        db("insert into problems_permissions (problem_id, username) values (%d, 'p16_mgr_outsider')" % problem_id)
        self.assertIn(outsider.get(manage + "/statement").status_code, (403, 404))
        outsider.post(manage + "/statement", {"problem_title": "p16 changed", "problem_tags": "", "problem_content_md": "x", "save-problem": ""})
        self.assertEqual(title_of(problem_id), before)
        # and who manages it can still take that away
        teacher.submit_form(manage + "/managers", "managers", {"managers_cmds": "-p16_mgr_outsider"})
        self.assertEqual(managers(problem_id), [])


if __name__ == "__main__":
    unittest.main()
