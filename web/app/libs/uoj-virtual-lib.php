<?php

// Virtual participation: sitting a contest that is over, alone, against the clock, the way
// QOJ has it. It lasts as long as the contest did and starts now, or at a time that was
// reserved. While it runs, the pages of the contest are to its user what they were to a
// contestant: the problems, a clock that runs down, the list of what they submitted, and the
// board - which is the board as it stood at the same moment of the contest.
//
// Whoever sat a contest virtually is on its board as somebody who did not take part in it:
// under a star, ranked among the contestants, for whoever asks to see the board with them.
// To the board, somebody who sat the contest virtually is the person "*" and their name;
// a username has no star in it, and a contestant who sat the contest again is two people.
//
// What is submitted during a virtual participation is judged with all the data, also where
// the contest only judged with the samples. As in the contest, how it did on the single
// tests is not shown while the participation runs.

// ---- deciding without the database

// 'upcoming', 'running' or 'ended'; $now is a timestamp
function virtualPhase($virtual, $now) {
	$start = strtotime($virtual['start_time']);
	if ($now < $start) {
		return 'upcoming';
	}
	return $now < $start + $virtual['last_min'] * 60 ? 'running' : 'ended';
}
// the seconds of it that have gone by, never more than it lasts
function virtualElapsed($virtual, $now) {
	return max(0, min($virtual['last_min'] * 60, $now - strtotime($virtual['start_time'])));
}
// The time that the field for a reservation offers: the next minute that ends in 0 or 5,
// with its date, so that a time need not be typed from nothing. $now is a timestamp; the
// time is given the way a field for a date and a time wants it, as '2026-10-12T19:05'.
// (10:33 gives 10:35, 10:37 gives 10:40, and a minute that ends in 5 gives the next one:
// by the time the form is sent, the minute it was opened in is over.)
function virtualDefaultStart($now) {
	$minute = (int)date('i', $now);
	$next = $now - (int)date('s', $now) + (5 - $minute % 5) * 60;
	return date('Y-m-d\TH:i', $next);
}
// Returns '' when a virtual participation may start at $start, or why not. An empty $start
// is now.
function virtualStartError($start, $now) {
	if ($start === '' || $start === null) {
		return '';
	}
	$time = is_string($start) ? DateTime::createFromFormat('Y-m-d H:i:s', $start) : false;
	if (!$time || $time->format('Y-m-d H:i:s') !== $start) {
		return '开始时间的格式不对';
	}
	if ($time->getTimestamp() < $now - 60) {
		return '开始时间已经过去了';
	}
	if ($time->getTimestamp() > $now + 30 * 86400) {
		return '最多预约 30 天之内的时间';
	}
	return '';
}
function virtualClock($seconds) {
	return sprintf('%d:%02d:%02d', floor($seconds / 3600), floor($seconds / 60) % 60, $seconds % 60);
}

// who a row of a board is: array(whether they sat the contest virtually, their username)
function virtualPersonOfKey($key) {
	$key = (string)$key;
	return $key !== '' && $key[0] === '*' ? array(true, substr($key, 1)) : array(false, $key);
}
// Ranks the contestants of a contest and the people who sat it virtually together.
//   $standings   the standings of the contestants: rows of score, penalty, person (and
//                maybe a rank, which is not looked at)
//   $unofficial  the same of the people who sat it virtually: rows of score, penalty, person
// Returns the rows of both, the best first, each with its rank among all of them: more is
// better, then less penalty; who is level has the same rank.
function virtualRankTogether($standings, $unofficial) {
	$rows = array();
	foreach (array_merge(array_values($standings), array_values($unofficial)) as $row) {
		$rows[] = array($row[0], $row[1], $row[2]);
	}
	usort($rows, function($lhs, $rhs) {
		if ($lhs[0] != $rhs[0]) {
			return $rhs[0] - $lhs[0];
		}
		if ($lhs[1] != $rhs[1]) {
			return $lhs[1] - $rhs[1];
		}
		return strcmp((string)$lhs[2][0], (string)$rhs[2][0]);
	});
	foreach ($rows as $index => &$row) {
		$level = $index > 0 && $rows[$index - 1][0] == $row[0] && $rows[$index - 1][1] == $row[1];
		$row[3] = $level ? $rows[$index - 1][3] : $index + 1;
	}
	unset($row);
	return $rows;
}
// What a contest tells somebody before they sit it virtually: its rules, in lines. A line
// that begins with "!" is one to notice.
//   $rule            'OI', 'IOI' or 'ICPC'
//   $minutes         how long the contest lasts
//   $freeze_offset   seconds into it at which its board freezes, or null
function virtualRules($rule, $minutes, $freeze_offset) {
	$lines = array("比赛时长为 $minutes 分钟，从你点“开始”的那一刻算起，中途不能暂停。");
	if ($rule === 'ICPC') {
		$lines[] = '每道题目均不计算部分分。';
		$lines[] = '比赛中途可以提交，在第一次通过提交后，这道题此后的提交均会被忽略。';
		$lines[] = '每一次错误的提交（不含 Compile Error）会计算 ' . (CONTEST_ICPC_PENALTY / 60) . ' 分钟的罚时。没有通过的题目不会计算罚时。';
	} else {
		$lines[] = '每道题目满分 100 分，可以得到部分分。';
		$lines[] = '比赛中途可以多次提交，每道题目以最后一次提交为准（Compile Error 不算）。';
		if ($rule === 'OI') {
			$lines[] = '虚拟参赛时，提交直接用全部数据评测并显示得分；正式比赛中只用样例评测，这一点不同。';
		}
	}
	$lines[] = '!比赛中途不会显示提交记录在任何测试点（包括样例）的详细信息，只会显示评测结果。';
	$lines[] = '比赛过程中可以查看排行榜，排行榜是原比赛进行到同一时刻的样子：包含所有选手在此时刻前的提交与结果。';
	if ($freeze_offset !== null) {
		$lines[] = '比赛开始 ' . contestClock($freeze_offset) . ' 后将会封榜。在此之后其他选手的提交结果不可见，你自己的照常显示。';
	}
	$lines[] = $rule === 'ICPC'
		? '比赛排名以解决问题的数量从高到低排序，解决问题相同的选手按照罚时从低到高排序。'
		: '比赛排名以总分从高到低排序，总分相同的选手按照得分提交的用时从低到高排序。';
	$lines[] = '虚拟参赛是非官方成绩：不影响 Rating，在排行榜上名字前带 * 号，勾选“显示非官方排行榜”才会出现。';
	$lines[] = '全凭自觉：比赛已经结束，题解和别人的代码可能都看得到，请像正式比赛一样独立完成。';
	return $lines;
}

// ---- queries

function queryVirtual($contest_id, $username) {
	return DB::selectFirst("select * from contest_virtuals where contest_id = ".(int)$contest_id." and username = '".DB::escape($username)."'", MYSQLI_ASSOC);
}
// the virtual participation of a user in a contest that runs right now, or null
function runningVirtual($contest_id, $user) {
	if ($user == null) {
		return null;
	}
	$virtual = queryVirtual($contest_id, $user['username']);
	return $virtual && virtualPhase($virtual, UOJTime::$time_now->getTimestamp()) === 'running' ? $virtual : null;
}
// What the user of a virtual participation submitted in it, as a condition on the submissions:
// to the problems of the contest, while it lasted - and not in a contest. What somebody
// submits to the same problem in another contest that runs meanwhile is of that contest.
function virtualSubmissionsCond($contest, $virtual) {
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	if (!$problem_ids) {
		return '1 = 0';
	}
	$until = date('Y-m-d H:i:s', strtotime($virtual['start_time']) + $virtual['last_min'] * 60);
	return "submissions.submitter = '".DB::escape($virtual['username'])."' and submissions.problem_id in (".join(',', $problem_ids).")"
		." and submissions.contest_id is null"
		." and submissions.submit_time >= '".DB::escape($virtual['start_time'])."' and submissions.submit_time < '$until'";
}
// The contest whose virtual participation a submission is part of, while that participation
// runs: its id, or null. How such a submission did on the single tests is not told yet.
function virtualRunningOfSubmission($submission) {
	$now = DB::escape(UOJTime::$time_now_str);
	$time = DB::escape($submission['submit_time']);
	$row = DB::selectFirst("select contest_virtuals.contest_id from contest_virtuals join contests_problems on contests_problems.contest_id = contest_virtuals.contest_id"
		." where contest_virtuals.username = '".DB::escape($submission['submitter'])."' and contests_problems.problem_id = ".(int)$submission['problem_id']
		." and contest_virtuals.start_time <= '$time' and '$time' < date_add(contest_virtuals.start_time, interval contest_virtuals.last_min minute)"
		." and '$now' < date_add(contest_virtuals.start_time, interval contest_virtuals.last_min minute) limit 1", MYSQLI_ASSOC);
	return $row ? (int)$row['contest_id'] : null;
}
// Everybody who sat a contest virtually or sits it now, as people of its board: rows of "*"
// and the username, rating, nickname.
function virtualPeople($contest, $limit = 2000) {
	$now = DB::escape(UOJTime::$time_now_str);
	$people = array();
	foreach (DB::selectAll("select contest_virtuals.username, user_info.rating, ifnull(user_info.nickname, '') as nickname from contest_virtuals"
			." left join user_info on user_info.username = contest_virtuals.username"
			." where contest_virtuals.contest_id = {$contest['id']} and contest_virtuals.start_time <= '$now' order by contest_virtuals.id limit ".(int)$limit, MYSQLI_ASSOC) as $row) {
		$people[] = array('*' . $row['username'], (int)$row['rating'], $row['nickname']);
	}
	return $people;
}
// What the contestants of a contest had submitted $elapsed seconds into it, in the shape
// queryContestData() gives a contest that runs: the board made of it is the board of that
// moment. Under the ICPC rule the failed attempts are on it as they were made.
function virtualOfficialData($contest, $elapsed) {
	$data = queryContestData(array('cur_progress' => CONTEST_IN_PROGRESS) + $contest);
	$until = $contest['start_time']->getTimestamp() + $elapsed;
	$rows = array();
	foreach ($data['data'] as $row) {
		// (a contest that is over has nothing that still waits to be judged)
		if ($row[4] !== null && strtotime($row[1]) <= $until) {
			$rows[] = $row;
		}
	}
	$data['data'] = $rows;
	return $data;
}
// Puts everybody who sat the contest virtually among its standings, as they stood $elapsed
// seconds into their participations, and ranks everybody anew. $standings and $score are
// what calcStandings() made; with $freeze_offset, what was submitted from that second of a
// participation on is counted and not told, as on the frozen board of the contest.
function virtualAddUnofficial($contest, &$standings, &$score, $elapsed, $freeze_offset = null) {
	$people = virtualPeople($contest);
	if (!$people) {
		return;
	}
	$pos = array_flip(array_map('intval', contestProblemIds($contest['id'])));
	$rows = array();
	if ($pos) {
		$now = DB::escape(UOJTime::$time_now_str);
		foreach (DB::selectAll("select submissions.id, timestampdiff(second, contest_virtuals.start_time, submissions.submit_time) as seconds_in, contest_virtuals.username, submissions.problem_id, submissions.score"
				." from contest_virtuals join submissions on submissions.submitter = contest_virtuals.username"
				." where contest_virtuals.contest_id = {$contest['id']} and contest_virtuals.start_time <= '$now' and submissions.problem_id in (".join(',', array_keys($pos)).")"
				." and submissions.contest_id is null and submissions.score is not null and submissions.submit_time >= contest_virtuals.start_time"
				." and submissions.submit_time < date_add(contest_virtuals.start_time, interval contest_virtuals.last_min minute) order by submissions.id", MYSQLI_ASSOC) as $row) {
			if ($row['seconds_in'] <= $elapsed) {
				$rows[] = array((int)$row['id'], (int)$row['seconds_in'], '*' . $row['username'], $pos[(int)$row['problem_id']], (int)$row['score']);
			}
		}
	}
	$cells = contestCells(contestRule($contest), $contest['extra_config']['standings_version'], $rows, $freeze_offset);
	$unofficial = array();
	foreach ($people as $person) {
		$score[$person[0]] = isset($cells[$person[0]]) ? $cells[$person[0]] : array();
		$row = array(0, 0, $person);
		foreach ($score[$person[0]] as $cell) {
			$row[0] += $cell[0];
			$row[1] += $cell[1];
		}
		$unofficial[] = $row;
	}
	$standings = virtualRankTogether($standings, $unofficial);
}
// The board of a contest for somebody who sits it virtually and is $elapsed seconds into
// it: the contest as it stood then, with the people who sat it virtually when $unofficial.
// Once the board of the contest froze, it is frozen for them as well ($frozen says so).
// Returns array($standings, $score, $contest_data, $frozen).
function virtualBoard($contest, $elapsed, $unofficial) {
	$freeze = contestFreezeOffset($contest);
	$frozen = $freeze !== null && $elapsed >= $freeze;
	$contest_data = virtualOfficialData($contest, $elapsed);
	calcStandings($contest, $contest_data, $score, $standings, false, $frozen ? $freeze : null);
	if ($unofficial) {
		virtualAddUnofficial($contest, $standings, $score, $elapsed, $frozen ? $freeze : null);
	}
	return array($standings, $score, $contest_data, $frozen);
}
// How it stands with somebody who sat a contest virtually, on the board of the contest with
// everybody who did: array('rank', 'score', 'penalty', 'people' => how many are on that
// board), or null if they are not on it.
function virtualResult($contest, $username) {
	calcStandings($contest, queryContestData($contest), $score, $standings);
	virtualAddUnofficial($contest, $standings, $score, $contest['last_min'] * 60);
	foreach ($standings as $row) {
		if ((string)$row[2][0] === '*' . $username) {
			return array('rank' => $row[3], 'score' => $row[0], 'penalty' => $row[1], 'people' => count($standings));
		}
	}
	return null;
}

// ---- changes; each returns '' or why it was refused

// Starts a virtual participation now, or reserves one for $start ('Y-m-d H:i:s'). One that
// has not begun is moved; one that is over is replaced; one that runs stays.
function virtualStart($contest, $user, $start) {
	if (!can($user, 'contest.virtual', $contest)) {
		return '这场比赛现在不能虚拟参赛';
	}
	$now = UOJTime::$time_now->getTimestamp();
	$err = virtualStartError($start, $now);
	if ($err !== '') {
		return $err;
	}
	$existing = queryVirtual($contest['id'], $user['username']);
	if ($existing && virtualPhase($existing, $now) === 'running') {
		return '你的虚拟参赛正在进行，结束之后才能重新开始';
	}
	$start_time = $start === '' || $start === null ? UOJTime::$time_now_str : $start;
	$esc_username = DB::escape($user['username']);
	DB::insert("insert into contest_virtuals (contest_id, username, start_time, last_min, created_at) values ({$contest['id']}, '$esc_username', '".DB::escape($start_time)."', ".(int)$contest['last_min'].", '".DB::escape(UOJTime::$time_now_str)."')"
		." on duplicate key update start_time = values(start_time), last_min = values(last_min), created_at = values(created_at)");
	auditLog('contest.virtual_start', 'contest', $contest['id'], null, array('username' => $user['username'], 'start_time' => $start_time), $user);
	return '';
}
// gives up a reservation
function virtualCancel($contest, $user) {
	$existing = queryVirtual($contest['id'], $user['username']);
	if (!$existing || virtualPhase($existing, UOJTime::$time_now->getTimestamp()) !== 'upcoming') {
		return '没有可以取消的预约';
	}
	DB::delete("delete from contest_virtuals where id = {$existing['id']}");
	auditLog('contest.virtual_cancel', 'contest', $contest['id'], array('username' => $user['username'], 'start_time' => $existing['start_time']), null, $user);
	return '';
}
