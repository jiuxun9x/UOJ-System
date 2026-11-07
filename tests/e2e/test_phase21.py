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


def frozen_contest(prefix, username):
    """an ICPC contest that runs with its board frozen, and a contestant who submitted to it after
    the board froze"""
    admin = uoj.admin()
    problem_id = admin.create_problem(ab_problem_files())
    contest_id = admin.new_contest(prefix + " 封榜", minutes=300, rule="ICPC", freeze_minutes="60", problems=str(problem_id))
    pupil = account(username)
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
        admin, pupil, contest_id = frozen_contest("p21 内榜", "p21_inner_pupil")
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


class OuterBoardTest(unittest.TestCase):
    """The outer board said under its bar when it would freeze, that it froze, whose view it was
    and how that was counted; its legend explained its colours, the stars and dirt in sentences,
    its medals what they were of, its charts and its submissions how they were counted. It says
    what is: the pill says the phase, the legend names the colours in a word, a medal says how
    many got it."""

    def test_the_outer_board_explains_nothing(self):
        admin, pupil, contest_id = frozen_contest("p21 外榜", "p21_outer_pupil")
        page = uoj.Client().get("/contest/%d/board" % contest_id).text
        self.assertIn('id="outer-board"', page)
        for sentence in ('id="outer-board-note"', "尝试过，未通过", "结果待揭晓", "Dirt：", "名字前带 *", "灰色是交过这道题的人数", "只算正式选手",
                         'id="outer-board-runs-note"', "同名次的拿同一档"):  # fmt: skip
            self.assertNotIn(sentence, page, sentence)
        for word in ("最先通过", "通过", "未通过", "待定", "每题通过 / 尝试人数"):
            self.assertIn(word, page)
        # nor does the page write any sentence of its own
        script = uoj.Client().get("/js/uoj-board.js").text
        for sentence in ("之后封榜", "此刻看到的榜单", "这是比赛的最终榜单", "按时间从新到旧", "有效人数，", "每 ' + minutesOf", "选手打开题目之后就会出现"):
            self.assertNotIn(sentence, script, sentence)


class ResolverTest(unittest.TestCase):
    """The resolver said how it is used over the board, and what its exports hold, for whom, and
    how other tools count, under it. It has its buttons, the exports among them."""

    def test_the_resolver_explains_nothing(self):
        admin, pupil, contest_id = frozen_contest("p21 滚榜", "p21_rs_pupil")
        uoj.move_contest(contest_id, -400 * 60, 300)
        page = admin.get("/contest/%d/resolver" % contest_id).text
        self.assertIn('id="table-resolver"', page)
        for button in ('id="resolver-export-clics"', 'id="resolver-export-xcpcio"', "导出 CLICS", "导出 XCPCIO"):
            self.assertIn(button, page)
        for sentence in ('id="resolver-note"', "按“下一步”", 'id="resolver-export"', "只给工作人员下载", "罚时按秒计算", "前三档奖牌对应"):
            self.assertNotIn(sentence, page, sentence)


if __name__ == "__main__":
    unittest.main()
