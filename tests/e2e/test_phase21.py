"""Phase 21: the pages that are looked at - the boards of a contest, its outer board, its
resolver - say what is, and explain nothing; the pages where contests and problems are made keep
their explanations."""

import unittest

import uoj
from fixtures import AB, ab_problem_files
from test_phase3 import account

# what the boards said in sentences, and say no more
INNER_BOARD_SENTENCES = ("此后的提交只显示次数", "这是只有工作人员能看到的完整榜单", "表头：通过人数", "通过，此前 2 次未通过",
                         "交了 3 次都没通过", "有还不知道结果的提交", "上面一行是你自己的实际成绩", "（虚拟参赛）")  # fmt: skip


def frozen_contest(prefix):
    """an ICPC contest that runs with its board frozen, and a contestant who solved A before and
    submitted to it after the board froze"""
    admin = uoj.admin()
    problem_id = admin.create_problem(ab_problem_files())
    contest_id = admin.new_contest(prefix + " 封榜", minutes=300, rule="ICPC", freeze_minutes="60", problems=str(problem_id))
    pupil = account(prefix.replace(" ", "_") + "_pupil")
    pupil.register_for_contest(contest_id)
    uoj.move_contest(contest_id, -250 * 60, 300)
    pupil.submit_in_contest(contest_id, problem_id, AB)
    uoj.wait_idle()
    return admin, pupil, contest_id


class InnerBoardTest(unittest.TestCase):
    """The board of a contest said in sentences that it froze and when, what its colours and its
    signs mean, and that the row above it was the contestant's own: it says that it froze, and
    when, in two words."""

    def test_the_board_explains_nothing(self):
        admin, pupil, contest_id = frozen_contest("p21 内榜")
        standings = "/contest/%d/standings" % contest_id
        for client, words, link in ((pupil, "已封榜 4:00:00", None), (admin, "完整榜单", "封榜榜单")):
            page = client.get(standings).text
            self.assertIn('id="standings-frozen"', page)
            self.assertIn(words, page)
            if link:
                self.assertIn(link, page)
            for sentence in INNER_BOARD_SENTENCES:
                self.assertNotIn(sentence, page, sentence)
        self.assertIn("已封榜 4:00:00", admin.get(standings + "?frozen=1").text)


if __name__ == "__main__":
    unittest.main()
