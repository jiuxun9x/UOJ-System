"""End-to-end tests of phase 6: run-twice problems, numbers of problems inside a domain, the
switch for blogs, what a contest shows while it runs, the ICPC rule, and the forms that make a
problem or a contest in one go.

See test_phase1.py for how to start the containers.
"""

import json
import re
import unittest

import test_phase3 as p3
import test_phase4 as p4
import uoj
from fixtures import *
from uoj import db, db_value, docker_exec


def setUpModule():
    uoj.admin()


class RunTwiceTest(unittest.TestCase):
    """a program is run twice, and its second run knows of the first what the relay tells it"""

    @classmethod
    def setUpClass(cls):
        cls.admin = uoj.admin()
        cls.problem_id = cls.admin.create_problem(run_twice_problem_files())

    def judge(self, code, language="C++17"):
        return uoj.wait_submission(self.admin.submit(self.problem_id, code, language))

    def test_relay_is_built_by_a_judger(self):
        prepare, status, judger = db(
            "select prepare, status, judger_name from problem_data_versions where problem_id = %d" % self.problem_id
        )[0]
        self.assertEqual(
            sorted(step["name"] for step in json.loads(prepare)), ["chk", "relay"]
        )
        self.assertEqual(status, "ready")
        container = uoj.JUDGERS[uoj.JUDGER_NAMES.index(judger)]
        built = docker_exec(container, "ls /opt/uoj_judger/uoj_judger/data/%d | grep relay" % self.problem_id).split()
        self.assertEqual(sorted(built), ["relay", "relay.cpp"])

    def test_second_run_is_given_what_the_relay_made_of_the_first(self):
        # the relay hands the messages over in another order, which only its notes tell the checker
        j = self.judge(messages_solution())
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 2, j)
        self.assertIn("3 numbers", j.details)

    def test_relay_can_refuse_what_the_first_run_wrote(self):
        j = self.judge(messages_solution(width=41))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)
        self.assertIn("message 1 is longer than 40 characters", j.details)

    def test_second_run_that_dies(self):
        j = self.judge(messages_solution(second="return 3;"))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Runtime Error"] * 2, j)
        self.assertIn("in the second run", j.details)

    def test_first_run_that_dies(self):
        j = self.judge(messages_solution(burn='if (strcmp(run, "first") == 0) return 3;'))
        self.assertEqual(j.infos, ["Runtime Error"] * 2, j)
        self.assertIn("in the first run", j.details)

    def test_each_run_has_the_time_limit_to_itself(self):
        # Six tenths of a second in each run, with a limit of one second. The clock is asked
        # now and then only: asking is a system call, and the time that counts is the time
        # the program computes.
        burn = ("volatile unsigned long long spin = 0; "
                "while (clock() < CLOCKS_PER_SEC * 6 / 10) for (int i = 0; i < 1000000; i++) spin = spin + 1;")  # fmt: skip
        j = self.judge(messages_solution(burn=burn))
        self.assertEqual(j.score, 100, j)
        # and the time of a test is that of its longer run, not of both: two tests of it
        self.assertGreaterEqual(j.used_time, 2 * 450, j)
        self.assertLess(j.used_time, 2 * 1000, j)

    def test_first_run_can_not_leave_a_file(self):
        j = self.judge(MESSAGES_STASH)
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Dangerous Syscalls"] * 2, j)

    def test_second_run_can_not_look_at_what_the_first_wrote(self):
        j = self.judge(MESSAGES_STAT_RESULT_FOLDER)
        self.assertEqual(j.score, 0, j)
        self.assertIn(j.infos[1], ("Wrong Answer", "Dangerous Syscalls"), j)
        self.assertNotIn("found 7", j.details)

    def test_work_folder_tells_the_second_run_nothing(self):
        # A program may list its work folder and see how large everything in it is. This one
        # does, in its second run, and looks for a file as large as its first run made it.
        j = self.judge(MESSAGES_LIST_WORK_FOLDER, "Python3")
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos[1], "Wrong Answer", j)
        self.assertIn("expected 7, found -1", j.details)

    def test_builtin_checker_reads_the_answer_file_when_the_relay_wrote_no_notes(self):
        problem_id = self.admin.create_problem(run_twice_plain_problem_files())
        j = uoj.wait_submission(self.admin.submit(problem_id, ECHO_THEN_DOUBLE))
        self.assertEqual(j.score, 100, j)
        self.assertEqual(j.infos, ["Accepted"] * 2 + ["Extra Test Passed"], j)
        j = uoj.wait_submission(self.admin.submit(problem_id, ECHO_THEN_DOUBLE.replace("2 * n", "3 * n")))
        self.assertEqual(j.score, 0, j)
        self.assertEqual(j.infos, ["Wrong Answer"] * 2, j)

    def test_run_twice_problem_can_not_be_hacked(self):
        problem_id = self.admin.create_problem(run_twice_plain_problem_files())
        r = self.admin.upload_data(problem_id, {"std.cpp": ECHO_THEN_DOUBLE, "val.cpp": ACCEPT_ANYTHING})
        self.assertIn("上传成功", r.text)
        err = self.admin.toggle_hackable(problem_id)
        self.assertIn("hackable", err)
        self.assertEqual(db_value("select hackable from problems where id = %d" % problem_id), "0")

    def test_run_twice_problem_without_a_relay_is_not_synced(self):
        files = run_twice_plain_problem_files()
        del files["relay.cpp"]
        problem_id = self.admin.new_problem()
        self.admin.upload_data(problem_id, files)
        self.assertIn("relay", self.admin.sync(problem_id))


class DomainContestRatingTest(unittest.TestCase):
    """what happens in a domain stays out of the ratings of the site"""

    def test_contest_of_a_domain_counts_for_no_rating(self):
        admin = uoj.admin()
        teacher = p3.account("p6_rate_teacher")
        self.assertEqual(admin.change_user("p6_rate_teacher", "grant:teacher"), "")
        did = teacher.new_domain("p6-rating")
        pupils = [p3.account("p6_rate_pupil%d" % n) for n in range(2)]
        for n in range(2):
            self.assertEqual(p4.member_form(teacher, "p6-rating", "add", username="p6_rate_pupil%d" % n, role="member"), "")
        self.assertEqual(teacher.form("/d/p6-rating/problems", "new"), "")
        own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
        self.assertIn("上传成功", teacher.upload_data(own_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(own_id), "")
        self.assertEqual(teacher.form("/d/p6-rating/contests", "new", name="p6 域内赛", start_time=uoj.web_time(3600), last_min="60"), "")
        contest_id = int(db_value("select id from contests where domain_id = %d" % did))
        self.assertEqual(teacher.contest_commands(contest_id, "problems", "+%d" % uoj.pid(own_id)), "")

        # a setting that says the contest is rated is not believed, whoever wrote it
        db("update contests set extra_config = '{}' where id = %d" % contest_id)
        self.assertIn("不计入", uoj.text_of(admin.get("/contest/%d/manage" % contest_id).text))
        for pupil in pupils:
            pupil.register_for_contest(contest_id)
        uoj.move_contest(contest_id, -60, 600)
        uoj.wait_submission(pupils[0].submit_in_contest(contest_id, own_id, AB))
        uoj.wait_submission(pupils[1].submit_in_contest(contest_id, own_id, AB_WRONG))
        uoj.wait_idle()
        uoj.move_contest(contest_id, -7200, 60)
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "start_test"), "")
        uoj.wait_idle()
        self.assertEqual(admin.submit_form("/contest/%d" % contest_id, "publish_result"), "")
        self.assertEqual(db_value("select status from contests where id = %d" % contest_id), "finished")

        for n in range(2):
            name = "p6_rate_pupil%d" % n
            self.assertEqual(db_value("select rating from user_info where username = '%s'" % name), "1500")
            self.assertEqual(db_value("select count(*) from user_system_msg where receiver = '%s' and title like 'Rating%%'" % name), "0")
            # and the history of the rating on the profile does not tell of the contest
            profile = admin.get("/user/profile/" + name).text
            self.assertNotIn("p6 域内赛", profile)
        self.assertIn('"rated":false', db_value(
            "select after_json from audit_logs where action = 'contest.publish_results' and resource_id = '%d'" % contest_id
        ))  # fmt: skip
        # the standings of the contest are there all the same
        self.assertEqual(
            db("select username, `rank` from contests_registrants where contest_id = %d order by `rank`" % contest_id),
            [["p6_rate_pupil0", "1"], ["p6_rate_pupil1", "2"]],
        )


def submit_and_follow(client, path, code):
    """submit a program to the problem at an address: where the site sends the user afterwards"""
    r = client.post(path, {
        "submit-answer": "answer",
        "answer_answer_upload_type": "editor",
        "answer_answer_editor": code,
        "answer_answer_language": "C++17",
    })  # fmt: skip
    assert r.status_code in (301, 302), r.status_code
    return r.headers["Location"]


def last_submission(username):
    return int(db_value("select max(id) from submissions where submitter = '%s'" % username))


class AfterSubmittingTest(unittest.TestCase):
    """where somebody is taken after submitting: to what they submitted, where they submitted it"""

    def test_submitting_to_a_homework_leads_to_what_one_submitted_to_it(self):
        admin = uoj.admin()
        site_problem = admin.create_problem(ab_problem_files())
        teacher, pupil, other = (p3.account("p6_hw_" + name) for name in ("teacher", "pupil", "other"))
        self.assertEqual(admin.change_user("p6_hw_teacher", "grant:teacher"), "")
        slug = "p6-homework"
        did = teacher.new_domain(slug)
        for name in ("pupil", "other"):
            self.assertEqual(p4.member_form(teacher, slug, "add", username="p6_hw_" + name, role="member"), "")
        self.assertEqual(teacher.form("/d/%s/problems" % slug, "new"), "")
        own_id = int(db_value("select max(id) from problems where owner_domain_id = %d" % did))
        self.assertIn("上传成功", teacher.upload_data(own_id, ab_problem_files()).text)
        self.assertEqual(teacher.sync(own_id), "")
        db("update problems set is_hidden = 0 where id = %d" % own_id)
        homework_id = p4.new_homework(teacher, slug, title="p6 作业")
        self.assertEqual(p4.homework_form(teacher, slug, homework_id, "add_problem", problem_id=str(uoj.pid(own_id)), score="100"), "")
        self.assertEqual(p4.homework_form(teacher, slug, homework_id, "publish"), "")
        uoj.wait_until("published", lambda: p4.tick() and p4.homework_row(homework_id, "status")[0] == "published")
        for client in (pupil, other):
            self.assertEqual(client.form("/d/%s/homework/%d" % (slug, homework_id), "claim"), "")

        # in a homework: the list of what one submitted to the homework
        in_homework = "/d/%s/homework/%d/problem/%d" % (slug, homework_id, uoj.pid(own_id))
        mine = "/submissions?homework_id=%d&submitter=p6_hw_pupil" % homework_id
        self.assertEqual(submit_and_follow(pupil, in_homework, AB_WRONG), mine)
        submission_id = last_submission("p6_hw_pupil")
        uoj.wait_submission(submission_id)
        listing = pupil.get(mine)
        self.assertEqual(listing.status_code, 200)
        self.assertIn('href="/submission/%d"' % submission_id, listing.text)
        self.assertIn('id="submissions-of-homework"', listing.text)
        self.assertIn("p6 作业", listing.text)
        # and how it did on every test, as when practising
        page = pupil.get("/submission/%d" % submission_id).text
        self.assertIn("Test #", page)
        self.assertIn("Wrong Answer", page)
        # what the others submitted to it is not there while it runs
        self.assertNotIn('href="/submission/%d"' % submission_id, other.get("/submissions?homework_id=%d" % homework_id).text)
        self.assertIn('href="/submission/%d"' % submission_id, teacher.get("/submissions?homework_id=%d" % homework_id).text)
        # a homework nobody may see filters nothing, and names nothing
        stranger = p3.account("p6_hw_stranger")
        listing = stranger.get("/submissions?homework_id=%d" % homework_id).text
        self.assertNotIn("p6 作业", listing)
        self.assertNotIn('id="submissions-of-homework"', listing)

        # outside of the homework, in the domain: what one submitted to the problem
        in_domain = "/d/%s/problem/%d" % (slug, uoj.pid(own_id))
        self.assertEqual(submit_and_follow(pupil, in_domain, AB), "/submissions?problem_id=%d&submitter=p6_hw_pupil" % own_id)
        practice = last_submission("p6_hw_pupil")
        self.assertIn('href="/submission/%d"' % practice, pupil.get("/submissions?problem_id=%d&submitter=p6_hw_pupil" % own_id).text)
        # and on the site as ever
        self.assertEqual(submit_and_follow(pupil, "/problem/%d" % site_problem, AB), "/submissions")
        uoj.wait_idle()


class BlogSwitchTest(unittest.TestCase):
    """blogs are closed unless the system administrator opens them; announcements stay"""

    def post(self, poster, title, announcement=False):
        db("insert into blogs (title, content, content_md, post_time, poster, zan, is_hidden, type, is_draft)"
           " values ('%s', '<p>text of %s</p>', 'text of %s', now(), '%s', 0, 0, 'B', 0)" % (title, title, title, poster))  # fmt: skip
        blog_id = int(db_value("select max(id) from blogs where poster = '%s'" % poster))
        if announcement:
            db("insert into important_blogs (blog_id, level) values (%d, 0)" % blog_id)
        return blog_id

    def comment(self, client, blog_id, poster):
        return client.submit_form("/blog/%s/post/%d" % (poster, blog_id), "comment", {"comment": "p6 comment by " + client.username})

    def test_blogs_are_closed_until_the_system_administrator_opens_them(self):
        import test_phase5 as p5

        admin, visitor = uoj.admin(), uoj.Client()
        writer, reader = p3.account("p6_blog_writer"), p3.account("p6_blog_reader")
        oj_admin = p3.account("p6_blog_ojadmin")
        self.assertEqual(admin.change_user("p6_blog_ojadmin", "grant:oj_admin"), "")
        self.assertIsNone(db_value("select value from site_settings where name = 'blog.enabled'"))
        news = self.post(uoj.ADMIN[0], "p6 期末安排", announcement=True)
        diary = self.post("p6_blog_writer", "p6 日记")
        comments = lambda: int(db_value("select count(*) from blogs_comments where blog_id in (%d, %d)" % (news, diary)))

        # ---- closed: no blogs in the navigation, nobody but the administrators has one
        for client in (reader, visitor):
            self.assertNotIn('href="/blogs"', client.get("/").text)
        r = reader.get("/blogs")
        self.assertEqual((r.status_code, r.headers.get("Location")), (302, "/announcements"))
        for client in (writer, reader, visitor):
            for path in ("/", "/archive", "/post/%d" % diary, "/post/new/write"):
                self.assertEqual(client.get("/blog/p6_blog_writer" + path).status_code, 404, path)
        self.assertEqual(reader.get("/blogs/%d" % diary).headers.get("Location"), "/blog/p6_blog_writer/post/%d" % diary)
        self.assertNotIn("/blog/p6_blog_writer", reader.get("/user/profile/p6_blog_writer").text)
        # the announcements are there for everybody, and are not discussed
        for client in (reader, visitor):
            self.assertIn("p6 期末安排", client.get("/").text)
            page = client.get("/blog/%s/post/%d" % (uoj.ADMIN[0], news))
            self.assertEqual(page.status_code, 200)
            self.assertIn("text of p6 期末安排", page.text)
            self.assertIn('id="comments-closed"', page.text)
            self.assertNotIn('id="form-comment"', page.text)
        self.comment(reader, news, uoj.ADMIN[0])
        self.assertEqual(comments(), 0)
        # the administrators go on writing them
        self.assertEqual(admin.get("/blog/%s/post/new/write" % uoj.ADMIN[0]).status_code, 200)
        self.assertEqual(oj_admin.get("/blog/p6_blog_ojadmin/post/new/write").status_code, 200)

        # ---- the switch is the system administrator's
        settings = "/super-manage/settings"
        self.assertEqual(oj_admin.get(settings).status_code, 403)
        p5.site_settings(oj_admin, blog_enabled=True)
        self.assertIsNone(db_value("select value from site_settings where name = 'blog.enabled'"))
        self.assertIn("开放用户博客", admin.get(settings).text)
        self.assertEqual(p5.site_settings(admin, blog_enabled=True), "")
        try:
            # ---- open: everybody has a blog, and posts are discussed
            self.assertIn('href="/blogs"', reader.get("/").text)
            self.assertEqual(reader.get("/blogs").status_code, 200)
            self.assertIn("p6 日记", reader.get("/blogs").text)
            self.assertEqual(reader.get("/blog/p6_blog_writer/post/%d" % diary).status_code, 200)
            self.assertEqual(writer.get("/blog/p6_blog_writer/post/new/write").status_code, 200)
            self.assertIn("/blog/p6_blog_writer", reader.get("/user/profile/p6_blog_writer").text)
            page = reader.get("/blog/%s/post/%d" % (uoj.ADMIN[0], news)).text
            self.assertIn('id="form-comment"', page)
            self.assertNotIn('id="comments-closed"', page)
            self.assertEqual(self.comment(reader, diary, "p6_blog_writer"), "")
            self.assertEqual(comments(), 1)
        finally:
            self.assertEqual(p5.site_settings(admin, blog_enabled=False), "")
        # ---- closed again: what was written is kept, and is out of sight
        self.assertEqual(reader.get("/blog/p6_blog_writer/post/%d" % diary).status_code, 404)
        self.assertEqual(db_value("select count(*) from blogs where id = %d" % diary), "1")
        self.assertEqual(comments(), 1)
        self.assertEqual(
            db("select action, actor from audit_logs where resource_type = 'site_setting' and resource_id = 'blog.enabled' order by id"),
            [["site.edit_setting", uoj.ADMIN[0]]] * 2,
        )


if __name__ == "__main__":
    unittest.main()
