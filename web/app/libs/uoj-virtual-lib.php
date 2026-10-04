<?php

// Virtual participation: sitting a contest that is over, alone, against the clock. It lasts
// as long as the contest did, starts now or at a time that was reserved, and what its user
// submits to the problems of the contest meanwhile is what counts for them. The standings
// are replayed beside it: every contestant of the real contest appears with what they had
// at the same moment of their contest.
//
// What is submitted during a virtual participation is judged with all the data and shown
// at once, also where the real contest only showed how the samples went.

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

// The standings of a contest as they were $elapsed seconds into it, with somebody who sits
// it virtually among the contestants.
//   $people   the contestants of the contest: rows of username, rating, nickname
//   $final    what counted for them in the end: rows of submitter, position of the problem,
//             score, seconds into the contest, id of the submission
//   $me       array('username', 'nickname') of who sits it virtually
//   $mine     what they submitted that was judged: rows of id, seconds into their
//             participation, position of the problem, score, in the order of the ids
//   $ended    whether the participation is over: only then is a zero shown, which says
//             nothing about when it was earned
// Returns rows of username, rating, nickname, virtual, score, penalty, cells (position of
// the problem => array(score, seconds, id of the submission)) and rank, the best first.
function virtualStandings($people, $final, $me, $mine, $elapsed, $ended, $standings_version = 2) {
	$rows = array();
	foreach ($people as $person) {
		$rows['r/' . $person[0]] = array('username' => (string)$person[0], 'rating' => (int)$person[1], 'nickname' => isset($person[2]) ? $person[2] : '', 'virtual' => false, 'score' => 0, 'penalty' => 0, 'cells' => array());
	}
	foreach ($final as $result) {
		list($submitter, $pos, $score, $penalty, $submission_id) = $result;
		if (!isset($rows['r/' . $submitter]) || $penalty > $elapsed || ($score == 0 && !$ended)) {
			continue;
		}
		$rows['r/' . $submitter]['cells'][$pos] = array((int)$score, (int)$penalty, (int)$submission_id);
	}
	$virtual = array('username' => (string)$me['username'], 'rating' => isset($me['rating']) ? (int)$me['rating'] : 0, 'nickname' => $me['nickname'], 'virtual' => true, 'score' => 0, 'penalty' => 0, 'cells' => array());
	foreach ($mine as $submission) {
		list($submission_id, $offset, $pos, $score) = $submission;
		if ($offset > $elapsed) {
			continue;
		}
		// as in a contest, the last submission to a problem is the one that counts
		$virtual['cells'][$pos] = array((int)$score, $score == 0 && $standings_version >= 2 ? 0 : (int)$offset, (int)$submission_id);
	}
	$rows['v'] = $virtual;
	foreach ($rows as &$row) {
		foreach ($row['cells'] as $cell) {
			$row['score'] += $cell[0];
			$row['penalty'] += $cell[1];
		}
	}
	unset($row);
	$rows = array_values($rows);
	usort($rows, function($lhs, $rhs) {
		if ($lhs['score'] != $rhs['score']) {
			return $rhs['score'] - $lhs['score'];
		}
		if ($lhs['penalty'] != $rhs['penalty']) {
			return $lhs['penalty'] - $rhs['penalty'];
		}
		if ($lhs['virtual'] != $rhs['virtual']) {
			return $lhs['virtual'] ? -1 : 1;
		}
		return strcmp($lhs['username'], $rhs['username']);
	});
	foreach ($rows as $index => &$row) {
		$same = $index > 0 && $rows[$index - 1]['score'] == $row['score'] && $rows[$index - 1]['penalty'] == $row['penalty'];
		$row['rank'] = $same ? $rows[$index - 1]['rank'] : $index + 1;
	}
	unset($row);
	return $rows;
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
// the problems of a contest in their order: rows of id, title and the letter they go by
function virtualProblems($contest) {
	$problems = array();
	foreach (DB::selectAll("select problems.id, problems.title, problems.owner_domain_id, problems.domain_pid from contests_problems join problems on problems.id = contests_problems.problem_id where contests_problems.contest_id = {$contest['id']} order by problems.id") as $index => $row) {
		// 'number' is what the address of the problem in the contest says
		$problems[] = array('id' => (int)$row['id'], 'number' => problemNumber($row), 'title' => $row['title'], 'letter' => chr(ord('A') + $index % 26));
	}
	return $problems;
}
// What the user of a virtual participation submitted to the problems of the contest while
// it lasted, the oldest first: rows of id, offset (seconds into it), pos, score (null when
// it is not judged, or did not compile) and status.
function virtualSubmissions($virtual, $problems) {
	$pos = array();
	foreach ($problems as $index => $problem) {
		$pos[$problem['id']] = $index;
	}
	if (!$pos) {
		return array();
	}
	$start = strtotime($virtual['start_time']);
	$from = DB::escape($virtual['start_time']);
	$until = date('Y-m-d H:i:s', $start + $virtual['last_min'] * 60);
	$rows = array();
	foreach (DB::selectAll("select id, submit_time, problem_id, score, status, result_error from submissions where submitter = '".DB::escape($virtual['username'])."' and problem_id in (".join(',', array_keys($pos)).") and submit_time >= '$from' and submit_time < '$until' order by id") as $row) {
		$rows[] = array(
			'id' => (int)$row['id'],
			'offset' => strtotime($row['submit_time']) - $start,
			'pos' => $pos[(int)$row['problem_id']],
			'score' => $row['score'] === null ? null : (int)$row['score'],
			'status' => $row['result_error'] !== null ? $row['result_error'] : $row['status']
		);
	}
	return $rows;
}
// the standings of a contest replayed for a virtual participation as it is now
function virtualStandingsNow($contest, $virtual, $problems) {
	$pos = array();
	foreach ($problems as $index => $problem) {
		$pos[$problem['id']] = $index;
	}
	$people = array();
	foreach (DB::selectAll("select contests_registrants.username, user_rating, ifnull(user_info.nickname, '') as nickname from contests_registrants left join user_info on user_info.username = contests_registrants.username where contest_id = {$contest['id']} and has_participated = 1") as $row) {
		$people[] = array($row['username'], (int)$row['user_rating'], $row['nickname']);
	}
	$final = array();
	foreach (DB::selectAll("select submitter, problem_id, score, penalty, submission_id from contests_submissions where contest_id = {$contest['id']}") as $row) {
		if (isset($pos[(int)$row['problem_id']])) {
			$final[] = array($row['submitter'], $pos[(int)$row['problem_id']], (int)$row['score'], (int)$row['penalty'], (int)$row['submission_id']);
		}
	}
	$mine = array();
	foreach (virtualSubmissions($virtual, $problems) as $submission) {
		if ($submission['score'] !== null) {
			$mine[] = array($submission['id'], $submission['offset'], $submission['pos'], $submission['score']);
		}
	}
	$user = queryUser($virtual['username']);
	$now = UOJTime::$time_now->getTimestamp();
	return virtualStandings($people, $final, array('username' => $virtual['username'], 'nickname' => $user ? $user['nickname'] : '', 'rating' => $user ? $user['rating'] : 0), $mine,
		virtualElapsed($virtual, $now), virtualPhase($virtual, $now) === 'ended', $contest['extra_config']['standings_version']);
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
