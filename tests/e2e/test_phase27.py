"""Phase 27: what a review of the whole site found, put right one by one."""

import hashlib
import json
import re
import threading
import time
import unittest

import uoj
from fixtures import AB, AB_HACKABLE, DOUBLE, DOUBLE_INTERACTOR, ab_problem_files, checker_problem_files, interactive_problem_files
from test_phase3 import account
from test_phase25 import classroom, published_homework
from test_phase4 import homework_form, homework_row, tick
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


class PublishOnceTest(unittest.TestCase):
    """Two people who pressed "公布成绩" at the same time each rated the contest: the second from
    the ratings the first had made. Who publishes holds the lock of the contest, and looks again
    whether it was published, holding it."""

    def test_a_contest_is_published_by_one_request(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p27 公布", rule="ICPC", problems=str(problem_id), rated="on")
        pupil = account("p27_publish_pupil")
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 60)
        uoj.wait_submission(pupil.submit_in_contest(contest_id, problem_id, AB))
        uoj.move_contest(contest_id, -7200, 60)
        rating = db_value("select rating from user_info where username = 'p27_publish_pupil'")
        # (another request publishing it: it holds the lock for a while)
        uoj.run("docker", "exec", "-d", uoj.DB, "mysql", "-uroot", "-proot", "-e", "select get_lock('uoj_contest_publish_%d', 0), sleep(8)" % contest_id)
        uoj.wait_until("the lock is held", lambda: db_value("select is_used_lock('uoj_contest_publish_%d') is not null" % contest_id) == "1", timeout=30)
        said = admin.post("/contest/%d" % contest_id, {"submit-publish_result": "publish_result"})
        self.assertIn("正在公布", uoj.text_of(said.text))
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "unfinished")
        self.assertEqual(db_value("select rating from user_info where username = 'p27_publish_pupil'"), rating)
        # once it is let go, the contest is published, once
        uoj.wait_until("the lock is let go", lambda: db_value("select is_used_lock('uoj_contest_publish_%d') is null" % contest_id) == "1", timeout=30)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "finished")
        self.assertEqual(int(db_value("select count(*) from audit_logs where action = 'contest.publish_results' and resource_id = '%d'" % contest_id)), 1)


class TwoTestsTest(unittest.TestCase):
    """The result of the first of the two tests of a submission of an OI contest was stored under
    its claim, and the submission was then set to wait for the second without one: a rejudge asked
    for between the two statements was overwritten, and the second test judged on a first test that
    had been thrown away, which left it Judged without a score. Both are one statement now, under
    the claim; what comes after a rejudge stores nothing and switches nothing."""

    def test_the_first_result_and_the_wait_for_the_second_are_one(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        contest_id = admin.new_contest("p27 两段评测", rule="OI", problems=str(problem_id))
        pupil = account("p27_two_tests")
        pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        sample = {"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}
        with uoj.judgers_paused():
            submission_id = pupil.submit_in_contest(contest_id, problem_id, AB)
            task = uoj.fake_fetch()
            self.assertEqual(task["id"], submission_id)
            # a rejudge before the result comes: the result is of nothing any more
            self.assertEqual(admin.submit_form("/problem/%d/manage/data" % problem_id, "rejudge"), "")
            uoj.judge_api("/judge/submit", {"fetch_new": "0", "id": submission_id, "worker": uoj.FAKE_WORKER, "claim": task["claim"], "submit": "1", "result": json.dumps(sample)})
            status, content = db("select status, content from submissions where id = %d" % submission_id)[0]
            self.assertEqual(status, "Waiting Rejudge")
            self.assertNotIn("first_test_config", json.loads(content))
            # judged anew, the first result switches it to the second test at once
            task = uoj.fake_fetch()
            self.assertEqual(task["id"], submission_id)
            uoj.judge_api("/judge/submit", {"fetch_new": "0", "id": submission_id, "worker": uoj.FAKE_WORKER, "claim": task["claim"], "submit": "1", "result": json.dumps(sample)})
            status, content = db("select status, content from submissions where id = %d" % submission_id)[0]
            self.assertEqual(status, "Judged, Waiting")
            self.assertIn("first_test_config", json.loads(content))
        uoj.wait_idle()
        self.assertEqual(db("select status, score from submissions where id = %d" % submission_id)[0], ["Judged", "100"])


class ReclaimAndHeartbeatTest(unittest.TestCase):
    """A task whose lease ran out was looked up, then released with no look at its lease: a worker
    that renewed it in between lost a task it was working on, which was judged again, and three
    times over was failed. The task is taken in one statement that finds the lease out still."""

    def test_a_lease_renewed_meanwhile_keeps_the_task(self):
        problem_id = uoj.admin().create_problem(ab_problem_files())
        pupil = account("p27_lease")
        with uoj.judgers_paused():
            submission_id = pupil.submit(problem_id, AB)
            task = uoj.fake_fetch()
            self.assertEqual(task["id"], submission_id)
            uoj.expire_lease("submissions", submission_id)
            # The worker reports while another worker asks for work and finds the task lost:
            # the row is held, its lease renewed and let go while that one waits to take it.
            renew = ("start transaction; select id from submissions where id = %d for update; select sleep(5);"
                     " update submissions set judge_lease_until = now() + interval 300 second where id = %d; commit;") % (submission_id, submission_id)
            uoj.run("docker", "exec", "-d", uoj.DB, "mysql", "-uroot", "-proot", "app_uoj233", "-e", renew)
            time.sleep(1)
            uoj.fake_fetch(worker="e2efakeworker0002")
            status, claim, attempts = db("select status, judge_claim, judge_attempts from submissions where id = %d" % submission_id)[0]
            self.assertEqual((status, claim, attempts), ("Judging", task["claim"], "0"))
            # and what the worker sends is stored
            result = {"score": 100, "time": 1, "memory": 1, "details": "<tests></tests>"}
            uoj.judge_api("/judge/submit", {"fetch_new": "0", "id": submission_id, "worker": uoj.FAKE_WORKER, "claim": task["claim"], "submit": "1", "result": json.dumps(result)})
            self.assertEqual(db("select status, score from submissions where id = %d" % submission_id)[0], ["Judged", "100"])


class HackKeptUntilAddedTest(unittest.TestCase):
    """The input of a hack that succeeded was thrown away before its test was added to the data:
    where adding it failed - no data from the judger, a problem.conf that was broken - the hack
    was a success that changed nothing, and could not be judged again. The input is kept until
    the test is added, and the hack is judged again from it."""

    def test_a_hack_whose_test_was_not_added_is_judged_again(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files(), hackable=True)
        submission_id = admin.submit(problem_id, AB_HACKABLE % 7)
        self.assertEqual(uoj.wait_submission(submission_id).score, 100)
        kept = lambda hack_id: uoj.php("echo is_file(UOJContext::storagePath() . queryHack(%d)['input']) ? 'kept' : 'gone';" % hack_id)
        with uoj.judgers_paused():
            hack_id = admin.hack(submission_id, b"7 1\n")
            task = uoj.fake_fetch()
            self.assertEqual(task["hack"]["id"], hack_id)
            # it succeeded, and the judger sent nothing to add
            success = {"score": 1, "time": 0, "memory": 0, "details": "<tests></tests>"}
            uoj.judge_api("/judge/submit", {"fetch_new": "0", "id": hack_id, "is_hack": "1", "worker": uoj.FAKE_WORKER, "claim": task["claim"], "submit": "1", "result": json.dumps(success)})
            self.assertEqual(db_value("select success from hacks where id = %d" % hack_id), "1")
            self.assertEqual(kept(hack_id), "kept")
            self.assertIn("重新评测此 Hack", admin.get("/hack/%d" % hack_id).text)
            self.assertEqual(admin.submit_form("/hack/%d" % hack_id, "rejudge"), "")
        # judged again, its test is added, and its input goes
        self.assertTrue(uoj.wait_hack(hack_id))
        self.assertEqual(uoj.wait_data_version(problem_id), "")
        self.assertIn("n_ex_tests 2", uoj.docker_exec(uoj.WEB, "cat /var/uoj_data/upload/%d/problem.conf" % problem_id))
        self.assertEqual(kept(hack_id), "gone")


# stands for a judger: what it is sent to update, written down
UPDATE_LISTENER = r"""<?php
$_SERVER['DOCUMENT_ROOT'] = '/opt/uoj/web';
require '/opt/uoj/web/app/libs/uoj-lib.php';
$server = stream_socket_server('tcp://127.0.0.1:' . UOJConfig::$data['judger']['socket']['port']);
touch('/tmp/p27_listening');
$connection = stream_socket_accept($server, 60);
file_put_contents('/tmp/p27_update', $connection ? stream_get_contents($connection) : '');
"""


class JudgerUpdateTest(unittest.TestCase):
    """Any judging account, a disabled one among them, could tell every judger to download, build
    and restart, as often as it liked; and the first judger that could not be reached kept the
    ones after it from being told. Only an enabled main_judger tells them, and all of them are."""

    def test_only_main_judger_tells_the_others_and_tells_them_all(self):
        self.assertEqual(uoj.judge_api("/judge/sync-judge-client").status_code, 403)
        password = "p27-main-judger-password"
        saved = db("select judger_name, ip from judger_info where ip != ''")
        db("update judger_info set ip = ''")
        try:
            db("insert into judger_info (judger_name, password, ip, enabled, note, created_by, created_at) values ('main_judger', 'sha256:%s', '', 0, 'p27', 'e2e', now())"
               % hashlib.sha256(password.encode()).hexdigest())  # fmt: skip
            main = {"judger_name": "main_judger", "password": password}
            self.assertEqual(uoj.judge_api("/judge/sync-judge-client", auth=main).status_code, 403)
            db("update judger_info set enabled = 1 where judger_name = 'main_judger'")
            # one judger that can not be reached, then one that can
            db("insert into judger_info (judger_name, password, ip, enabled, note, created_by, created_at) values ('p27_a_lost', 'x', '192.0.2.1', 0, '', 'e2e', now())")
            db("insert into judger_info (judger_name, password, ip, enabled, note, created_by, created_at) values ('p27_b_here', 'x', '127.0.0.1', 0, '', 'e2e', now())")
            uoj.docker_exec(uoj.WEB, "rm -f /tmp/p27_listening /tmp/p27_update")
            uoj.run("docker", "exec", "-i", uoj.WEB, "sh", "-c", "cat > /tmp/p27_listener.php", stdin=UPDATE_LISTENER.encode())
            uoj.run("docker", "exec", "-d", uoj.WEB, "php", "/tmp/p27_listener.php")
            uoj.wait_until("the listener listens", lambda: uoj.docker_exec(uoj.WEB, "test -f /tmp/p27_listening && echo yes || true").strip() == "yes", timeout=30)
            r = uoj.judge_api("/judge/sync-judge-client", auth=main)
            self.assertEqual(r.text, "judge clients lost: 192.0.2.1")
            uoj.wait_until("the judger is told", lambda: '"cmd":"update"' in uoj.docker_exec(uoj.WEB, "cat /tmp/p27_update 2>/dev/null || true"), timeout=30)
        finally:
            db("delete from judger_info where judger_name in ('main_judger', 'p27_a_lost', 'p27_b_here')")
            for name, ip in saved:
                db("update judger_info set ip = '%s' where judger_name = '%s'" % (ip, name))


def virtual_cells(contest_id, username):
    """what the board of a virtual participation counts of somebody who sat the contest virtually"""
    return json.loads(uoj.php(
        "$c = queryContest(%d); genMoreContestInfo($c); list($s, $score) = virtualBoard($c, 7200, true);"
        " echo json_encode(isset($score['*%s']) ? $score['*%s'] : null);" % (contest_id, username, username)
    ))  # fmt: skip


class VirtualOfItsOwnTest(unittest.TestCase):
    """What somebody sitting a contest virtually submitted to one of its problems in the time was
    counted for it, wherever it was submitted: practising the same problem through another contest
    that was over, or for a homework, was a solution on the board of the virtual participation.
    What counts is what was submitted through the contest while sitting it."""

    def test_what_is_submitted_through_another_contest_is_not_of_it(self):
        admin = uoj.admin()
        problem_id = admin.create_problem(ab_problem_files())
        sat, other = (admin.new_contest(name, rule="ICPC", problems=str(problem_id)) for name in ("p27 虚拟参赛", "p27 另一场"))
        for contest_id in (sat, other):
            uoj.move_contest(contest_id, -7200, 60)
            self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        cat = account("p27_vp_own")
        self.assertEqual(cat.form("/contest/%d/virtual" % sat, "start"), "")
        elsewhere = cat.submit(problem_id, AB, path="/contest/%d/problem/%d" % (other, problem_id))
        self.assertEqual(uoj.wait_submission(elsewhere).score, 100)
        self.assertEqual(db("select practice_contest_id, is_virtual from submissions where id = %d" % elsewhere)[0], [str(other), "0"])
        self.assertNotIn('href="/submission/%d"' % elsewhere, cat.get("/contest/%d/submissions?mine=1" % sat).text)
        self.assertEqual(virtual_cells(sat, cat.username), [])
        # what is submitted through it is of it
        here = cat.submit(problem_id, AB, path="/contest/%d/problem/%d" % (sat, problem_id))
        self.assertEqual(uoj.wait_submission(here).score, 100)
        self.assertIn('href="/submission/%d"' % here, cat.get("/contest/%d/submissions?mine=1" % sat).text)
        self.assertEqual(len(virtual_cells(sat, cat.username)), 1)


def hold_homework_lock(homework_id, seconds):
    """hold the lock of a homework, as a settling of it holds it, for some seconds"""
    name = "uoj_homework_%d" % homework_id
    uoj.run("docker", "exec", "-d", uoj.DB, "mysql", "-uroot", "-proot", "-e", "select get_lock('%s', 0), sleep(%d)" % (name, seconds))
    uoj.wait_until("the lock is held", lambda: db_value("select is_used_lock('%s') is not null" % name) == "1", timeout=30)


class HomeworkRacesTest(unittest.TestCase):
    """What a homework is, and who takes part in it, was decided on what a request read when it
    began: a settling in between was undone, or taken in, wrongly."""

    def test_a_new_end_opens_a_homework_settled_meanwhile(self):
        # The end moved on, on a page read before the homework was settled: it was saved with
        # the new end, settled still, and never settled again.
        slug, teacher, _, problem_id = classroom("p27_hw_end")
        homework_id = published_homework(teacher, slug, problem_id, title="p27 延期")
        db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-60), homework_id))
        tick()
        self.assertEqual(homework_row(homework_id, "settle_state")[0], "settled")
        said = uoj.php(
            "$h = queryHomework(%d); $h['settle_state'] = 'open';"
            " $input = array('title' => 'p27 延期', 'description_md' => '', 'begin_at' => '%s', 'end_at' => '%s', 'claim_end_at' => '', 'allow_withdraw' => 'on');"
            " list($id, $err) = homeworkSave(queryDomain($h['domain_id']), $h, $input, queryUser('%s')); echo $err;"
            % (homework_id, uoj.web_time(-86400), uoj.web_time(86400), teacher.username)
        )  # fmt: skip
        self.assertEqual(said, "")
        self.assertEqual(homework_row(homework_id, "settle_state")[0], "open")

    def test_settling_anew_waits_for_a_settling(self):
        # The snapshot of a new settling was made before its submissions were set to be judged
        # again: a settling in between found nothing to judge, and took the scores as they were.
        slug, teacher, _, problem_id = classroom("p27_hw_resettle")
        homework_id = published_homework(teacher, slug, problem_id, title="p27 重新结算")
        db("update homeworks set end_at = '%s' where id = %d" % (uoj.web_time(-60), homework_id))
        tick()
        self.assertEqual(homework_row(homework_id, "settle_state")[0], "settled")
        hold_homework_lock(homework_id, 5)
        started = time.time()
        self.assertEqual(homework_form(teacher, slug, homework_id, "resettle", scope="all", reason="p27"), "")
        self.assertGreaterEqual(time.time() - started, 3)
        self.assertEqual(db_value("select count(*) from homework_snapshots where homework_id = %d and reason = 'p27'" % homework_id), "1")

    def test_what_comes_in_after_the_settling_is_a_correction(self):
        # Submitted before the end, and written down after the homework was settled: it was a
        # submission of before the end that the scores did not have, and nobody waited for.
        slug, teacher, (ann,), problem_id = classroom("p27_hw_late", ("ann",))
        homework_id = published_homework(teacher, slug, problem_id, title="p27 截止边上")
        here = "/d/%s/homework/%d" % (slug, homework_id)
        self.assertEqual(ann.form(here, "claim"), "")
        hold_homework_lock(homework_id, 6)
        sent = []
        submitting = threading.Thread(target=lambda: sent.append(ann.submit(problem_id, AB, path="%s/problem/%d" % (here, uoj.pid(problem_id)))))
        submitting.start()
        time.sleep(2)
        # the end comes, and the homework is settled, while the submission waits to be written down
        db("update homeworks set end_at = '%s', settle_state = 'settled' where id = %d" % (uoj.web_time(0), homework_id))
        submitting.join(60)
        self.assertEqual(len(sent), 1)
        late = db_value("select submit_time >= (select end_at from homeworks where id = %d) from submissions where id = %d" % (homework_id, sent[0]))
        self.assertEqual(late, "1")

    def test_who_was_taken_out_meanwhile_stays_out(self):
        # Allowed to claim the homework on what was read before a teacher took them out, a
        # pupil was put back in by claiming it.
        slug, teacher, (ann,), problem_id = classroom("p27_hw_out", ("ann",))
        homework_id = published_homework(teacher, slug, problem_id, title="p27 移出")
        db("insert into homework_participants (homework_id, username, status, claimed_at) values (%d, '%s', 'removed', now())" % (homework_id, ann.username))
        said = uoj.php(
            "class P27Stale extends UOJPermissionFacts { public function homeworkParticipantStatus($u, $h) { return 'withdrawn'; } }"
            " permissionFacts(new P27Stale()); echo homeworkClaim(queryHomework(%d), queryUser('%s'));" % (homework_id, ann.username)
        )  # fmt: skip
        self.assertEqual(said, "现在不能认领这个作业")
        self.assertEqual(db_value("select status from homework_participants where homework_id = %d and username = '%s'" % (homework_id, ann.username)), "removed")


# a checker that goes wrong on a sum of 3
FAILING_ON_3 = r"""
#include "testlib.h"
int main(int argc, char **argv) {
    registerTestlibCmd(argc, argv);
    long long expected = ans.readLong(), found = ouf.readLong();
    if (expected == 3) {
        quitf(_fail, "p27: this checker can not judge 3");
    }
    if (found != expected) {
        quitf(_wa, "expected %lld, found %lld", expected, found);
    }
    quitf(_ok, "fine");
}
"""


class JudgeSideFailureTest(unittest.TestCase):
    """A checker, an interactor or a validator that went wrong itself gave the program it judged a
    zero on that test, and a total that was a score like any other. The judgement fails now, as a
    whole, and says why."""

    def test_a_checker_that_fails_fails_the_judgement(self):
        problem_id = uoj.admin().create_problem(checker_problem_files(FAILING_ON_3))
        j = uoj.wait_submission(uoj.admin().submit(problem_id, AB))
        self.assertEqual((j.score, j.error), (None, "Judgment Failed"), j)
        self.assertIn("Test #1", j.details)
        self.assertIn("p27: this checker can not judge 3", j.details)


if __name__ == "__main__":
    unittest.main()
