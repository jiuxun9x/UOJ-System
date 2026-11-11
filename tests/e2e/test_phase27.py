"""Phase 27: what a review of the whole site found, put right one by one."""

import time
import unittest

import uoj
from fixtures import ab_problem_files
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


if __name__ == "__main__":
    unittest.main()
