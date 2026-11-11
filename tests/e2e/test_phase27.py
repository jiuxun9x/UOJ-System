"""Phase 27: what a review of the whole site found, put right one by one."""

import json
import re
import time
import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account
from test_phase25 import classroom
from uoj import db, db_value


class DataLockTest(unittest.TestCase):
    """A sync copied the files of a problem one by one while an upload could replace them: a sync
    that read the inputs before the upload and the answers after published a version made of both.
    Whatever changes the files of a problem, and whatever reads them all, holds its lock."""

    def test_an_upload_and_a_sync_wait_for_whoever_holds_the_lock(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        for what, act in (("an upload", lambda: self.assertIn("上传成功", admin.upload_data(problem_id, {"input1.txt": "3 4\n"}).text)),
                          ("a sync", lambda: self.assertEqual(admin.sync(problem_id, wait=False), ""))):  # fmt: skip
            uoj.hold_data_lock(problem_id, 6)
            started = time.time()
            act()
            self.assertGreaterEqual(time.time() - started, 3, what)
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertEqual(uoj.docker_exec(uoj.WEB, "cat /var/uoj_data/%d/input1.txt" % problem_id), "3 4\n")


class CopyOfWhatWasPublishedTest(unittest.TestCase):
    """A copy of a problem was made of the files uploaded for it, synced or not, and said it was
    a copy of the version that was published: answers made for a contest to come, uploaded and not
    synced yet, went to whoever copied the problem. A copy is made of what was published, or not
    made."""

    def copies(self, source):
        return int(db_value("select count(*) from problems where source_problem_id = %d" % source))

    def test_files_that_were_not_synced_are_not_copied(self):
        admin = uoj.admin()
        source = admin.create_problem(ab_problem_files())
        slug, teacher, _, _ = classroom("p27_copy")
        self.assertIn("上传成功", admin.upload_data(source, {"output1.txt": "p27-not-yet\n"}).text)
        said = teacher.form("/d/%s/problems" % slug, "copy", problem_id=str(source))
        self.assertIn("有还没同步的数据改动", said)
        self.assertEqual(self.copies(source), 0)

        # synced, it is copied, and the copy says of which version
        self.assertEqual(admin.sync(source), "")
        copy_id = teacher.copy_problem(slug, source)
        self.assertEqual(uoj.wait_data_version(copy_id), "")
        self.assertEqual(db_value("select source_data_version from problems where id = %d" % copy_id), db_value("select data_version from problems where id = %d" % source))
        self.assertEqual(uoj.docker_exec(uoj.WEB, "cat /var/uoj_data/%d/output1.txt" % copy_id), "p27-not-yet\n")

        # a version from before the files had a state: nothing changed after it is taken
        db("update problem_data_versions set upload_state = null where problem_id = %d" % source)
        self.assertEqual(teacher.form("/d/%s/problems" % slug, "copy", problem_id=str(source)), "")
        time.sleep(1.5)
        uoj.docker_exec(uoj.WEB, "touch /var/uoj_data/upload/%d/input2.txt" % source)
        self.assertIn("有还没同步的数据改动", teacher.form("/d/%s/problems" % slug, "copy", problem_id=str(source)))
        self.assertEqual(self.copies(source), 2)


class ContestStatisticsTest(unittest.TestCase):
    """The statistics of a problem in a contest were shown for any problem by its number: through a
    contest that was over and open to everybody, the title and the scores of a hidden problem that
    was in no contest at all, or of a problem of a domain, were everybody's."""

    def test_only_a_problem_of_the_contest(self):
        admin = uoj.admin()
        own = admin.create_problem(ab_problem_files())
        hidden = admin.create_problem(ab_problem_files())
        db("update problems set is_hidden = 1, title = 'p27 隐藏的题' where id = %d" % hidden)
        contest_id = admin.new_contest("p27 统计", rule="ICPC", problems=str(own))
        uoj.move_contest(contest_id, -7200, 60)
        visitor = uoj.Client()
        self.assertEqual(visitor.get("/contest/%d/problem/%d/statistics" % (contest_id, own)).status_code, 200)
        self.assertEqual(visitor.get("/contest/%d/problem/A/statistics" % contest_id).status_code, 200)
        for path in ("/contest/%d/problem/%d/statistics" % (contest_id, hidden), "/problem/%d/statistics" % hidden):
            page = visitor.get(path)
            self.assertEqual(page.status_code, 404, path)
            self.assertNotIn("p27 隐藏的题", page.text, path)


def full_marks_counted(client, problem_id):
    """how many submissions of full marks the chart of the statistics of a problem counts"""
    page = client.get("/problem/%d/statistics" % problem_id).text
    bars = json.loads(re.search(r"data: (\[.*?\]),", page).group(1))
    return sum(int(bar["count"]) for bar in bars if float(bar["score"]) == 100)


class StatisticsChartTest(unittest.TestCase):
    """The chart of the statistics of a problem counted every score, but those of a contest that
    ran: who could not get inside a contest for a list, or with a password, could not see what was
    submitted in it, and was shown how many scored what there."""

    def test_the_chart_counts_what_one_may_see(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p27 统计图", rule="ICPC", problems=str(problem_id), join_mode="password", join_password="p27 sesame")
        pupil, stranger = account("p27_chart_pupil"), account("p27_chart_stranger")
        self.assertEqual(pupil.submit_form("/contest/%d/register" % contest_id, "register", {"join_password": "p27 sesame"}), "")
        uoj.move_contest(contest_id, -60, 60)
        self.assertEqual(uoj.wait_submission(pupil.submit_in_contest(contest_id, problem_id, AB)).score, 100)
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(full_marks_counted(pupil, problem_id), 1)
        self.assertEqual(full_marks_counted(admin, problem_id), 1)
        for client in (stranger, uoj.Client()):
            self.assertEqual(full_marks_counted(client, problem_id), 0)


class HackOfASubmissionTest(unittest.TestCase):
    """A hack was shown as its problem is, whatever it hacked: a hack of what was submitted in a
    contest with a password was everybody's, with the names, the result and the details of the
    test, while what it hacked was not; and of a problem that stays hidden it was its hacker's no
    more. A hack is shown where what it hacked is, and to its hacker."""

    def test_a_hack_is_where_the_submission_is(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files(), extra_config={"view_content_type": "ALL", "view_all_details_type": "ALL"}, hackable=True)
        contest_id = admin.new_contest("p27 Hack", rule="IOI", problems=str(problem_id), join_mode="password", join_password="p27 hack")
        pupil, mate, stranger = account("p27_hack_pupil"), account("p27_hack_mate"), account("p27_hack_stranger")
        for client in (pupil, mate):
            self.assertEqual(client.submit_form("/contest/%d/register" % contest_id, "register", {"join_password": "p27 hack"}), "")
        uoj.move_contest(contest_id, -60, 60)
        submission_id = pupil.submit_in_contest(contest_id, problem_id, AB)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        hack_id = mate.hack(submission_id, b"5 6\n")
        uoj.wait_hack(hack_id)
        listed = 'href="/hack/%d"' % hack_id
        for client in (mate, pupil, admin):
            self.assertEqual(client.get("/hack/%d" % hack_id).status_code, 200, client.username)
            self.assertIn(listed, client.get("/hacks?submission_id=%d" % submission_id).text, client.username)
        for client in (stranger, uoj.Client()):
            self.assertNotEqual(client.get("/submission/%d" % submission_id).status_code, 200)
            self.assertNotEqual(client.get("/hack/%d" % hack_id).status_code, 200)
            self.assertNotIn(listed, client.get("/hacks?submission_id=%d" % submission_id).text)


if __name__ == "__main__":
    unittest.main()
