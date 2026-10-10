<?php

// Balloons: in a contest that is held in a room, whoever solves a problem is brought a
// balloon in the colour of the problem. The people who run the contest have a list of the
// balloons that are to be brought, with where the contestant sits, and tick off the ones
// that were. It is done the way DOMjudge does it:
// - a contestant gets one balloon for a problem, when they solve it for the first time;
// - a problem has a colour, which has a name that whoever carries the balloons can read;
// - the first to solve anything, and the first to solve a problem, are marked;
// - while the board is frozen no new balloons are given, unless the contest says otherwise:
//   a balloon on a desk tells the room what the board does not.
//
// A contest gives balloons when it says so ('balloons'). Under the OI rule nobody knows
// during the contest what was solved, and there are no balloons to give.

// ---- deciding without the database

// the colours that the problems of a contest have when nobody chose any, in their order:
// array(colour, its name)
function balloonPalette() {
	return array(
		array('#e53935', '红色'), array('#fb8c00', '橙色'), array('#fdd835', '黄色'), array('#43a047', '绿色'),
		array('#00acc1', '青色'), array('#1e88e5', '蓝色'), array('#8e24aa', '紫色'), array('#f06292', '粉色'),
		array('#ffffff', '白色'), array('#212121', '黑色'), array('#8d6e63', '棕色'), array('#9e9e9e', '灰色'),
		array('#c0ca33', '黄绿色'), array('#283593', '深蓝色'), array('#d4af37', '金色'), array('#80deea', '浅蓝色')
	);
}
// the colour of the problem at a position, when nobody chose one: array('color', 'name')
function balloonDefaultColor($pos) {
	$palette = balloonPalette();
	list($color, $name) = $palette[$pos % count($palette)];
	return array('color' => $color, 'name' => $name);
}
// Returns '' when a balloon can have this colour and this name for it, or why not.
function balloonColorError($color, $name) {
	if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $color)) {
		return '颜色要写成 #rrggbb 的样子，例如 #e53935';
	}
	if (!is_string($name) || mb_strlen($name, 'UTF-8') > 20 || preg_match('/[\x00-\x1f\x7f<>"\'&]/', $name)) {
		return '颜色的名字最多 20 个字，不能有引号和尖括号';
	}
	return '';
}
// What is written on a colour is read on it: '#000000' on a light one, '#ffffff' on a dark
// one. How light a colour is to the eye is not the mean of its parts: green is light, blue
// is dark.
function balloonInk($color) {
	$light = 0;
	foreach (array_combine(array('0.2126', '0.7152', '0.0722'), sscanf($color, '#%02x%02x%02x')) as $weight => $part) {
		$part /= 255;
		$light += $weight * ($part <= 0.03928 ? $part / 12.92 : pow(($part + 0.055) / 1.055, 2.4));
	}
	return $light > 0.25 ? '#000000' : '#ffffff';
}
// whether a contest of this rule has anything to give balloons for while it runs
function balloonRuleGives($rule) {
	return $rule !== 'OI';
}

// The balloons of a contest.
//   $passed  the submissions of its contestants that passed: rows of id, seconds into the
//            contest, username, position of the problem; in any order
//   $until   seconds into the contest from which on no balloon is given, or null
// Returns array(the balloons, how many were held back). A balloon is the first time a
// contestant solved a problem: array('username', 'pos', 'submission', 'offset',
// 'first_in_contest', 'first_for_problem', 'nth' => the how-manieth balloon of the
// contestant it is, 'has' => the positions of the balloons they have with this one, in the
// order they got them). The oldest balloon is first.
function balloonsOf($passed, $until = null) {
	usort($passed, function($lhs, $rhs) {
		return $lhs[1] != $rhs[1] ? $lhs[1] - $rhs[1] : $lhs[0] - $rhs[0];
	});
	$balloons = array();
	$held_back = 0;
	$has = array();
	$solved = array();
	foreach ($passed as $row) {
		list($id, $offset, $username, $pos) = $row;
		$username = (string)$username;
		if (isset($has[$username]) && in_array($pos, $has[$username], true)) {
			continue;
		}
		if ($until !== null && $offset >= $until) {
			// counted once for a problem of a contestant, as a balloon would be
			$has[$username][] = $pos;
			$held_back++;
			continue;
		}
		$has[$username][] = $pos;
		$balloons[] = array(
			'username' => $username, 'pos' => $pos, 'submission' => (int)$id, 'offset' => (int)$offset,
			'first_in_contest' => !$balloons, 'first_for_problem' => !isset($solved[$pos]),
			'nth' => count($has[$username]), 'has' => $has[$username]
		);
		$solved[$pos] = true;
	}
	return array($balloons, $held_back);
}

// ---- queries

// whether the contest gives balloons
function balloonsEnabled($contest) {
	return !empty($contest['balloons']) && balloonRuleGives(contestRule($contest));
}
// From when on the contest holds its balloons back, in seconds into it, or null: while its
// board is frozen, which it is until the results are published.
function balloonsHeldFrom($contest) {
	if (!empty($contest['balloons_after_freeze']) || $contest['cur_progress'] >= CONTEST_FINISHED) {
		return null;
	}
	return contestFreezeOffset($contest);
}
// the colours of the problems of a contest: id of the problem => array('color', 'name',
// 'chosen' => whether somebody chose it)
function balloonColors($contest) {
	$chosen = array();
	foreach (DB::selectAll("select problem_id, color, name from contest_balloon_colors where contest_id = {$contest['id']}", MYSQLI_ASSOC) as $row) {
		$chosen[(int)$row['problem_id']] = array('color' => strtolower($row['color']), 'name' => $row['name'], 'chosen' => true);
	}
	$colors = array();
	foreach (contestProblemIds($contest['id']) as $pos => $problem_id) {
		$colors[(int)$problem_id] = isset($chosen[(int)$problem_id]) ? $chosen[(int)$problem_id] : balloonDefaultColor($pos) + array('chosen' => false);
	}
	return $colors;
}
// The list of the balloons of a contest, the oldest first. Returns array('balloons' =>
// what balloonsOf() says of each, with 'problem_id', 'letter', 'color', 'color_name', 'seat',
// 'done_at' and 'done_by' (null while it was not brought), 'held_back' => how many are not
// in it because the board is frozen).
function balloonQueue($contest) {
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$pos = array_flip($problem_ids);
	$start = $contest['start_time']->getTimestamp();
	$passed = array();
	$seats = array();
	foreach (DB::selectAll("select username, seat from contests_registrants where contest_id = {$contest['id']}", MYSQLI_ASSOC) as $row) {
		$seats[(string)$row['username']] = $row['seat'];
	}
	// what passed, of the people who are contestants of it now
	foreach (DB::selectAll("select id, submit_time, submitter, problem_id from submissions where contest_id = {$contest['id']} and score = 100 order by id", MYSQLI_ASSOC) as $row) {
		if (isset($pos[(int)$row['problem_id']]) && isset($seats[(string)$row['submitter']])) {
			$passed[] = array((int)$row['id'], strtotime($row['submit_time']) - $start, (string)$row['submitter'], $pos[(int)$row['problem_id']]);
		}
	}
	list($balloons, $held_back) = balloonsOf($passed, balloonsHeldFrom($contest));
	$done = array();
	foreach (DB::selectAll("select username, problem_id, done_at, done_by from contest_balloons where contest_id = {$contest['id']}", MYSQLI_ASSOC) as $row) {
		$done[$row['username'] . '/' . (int)$row['problem_id']] = $row;
	}
	$colors = balloonColors($contest);
	foreach ($balloons as &$balloon) {
		$problem_id = $problem_ids[$balloon['pos']];
		$mark = isset($done[$balloon['username'] . '/' . $problem_id]) ? $done[$balloon['username'] . '/' . $problem_id] : null;
		$balloon += array(
			'problem_id' => $problem_id, 'letter' => chr(ord('A') + $balloon['pos'] % 26),
			'color' => $colors[$problem_id]['color'], 'color_name' => $colors[$problem_id]['name'],
			'seat' => $seats[$balloon['username']],
			'done_at' => $mark ? $mark['done_at'] : null, 'done_by' => $mark ? $mark['done_by'] : null
		);
	}
	unset($balloon);
	return array('balloons' => $balloons, 'held_back' => $held_back);
}
// how many balloons of a contest wait to be brought
function balloonPendingCount($contest) {
	$pending = 0;
	foreach (balloonQueue($contest)['balloons'] as $balloon) {
		$pending += $balloon['done_at'] === null ? 1 : 0;
	}
	return $pending;
}

// ---- changes; each returns '' or why it was refused

// whether the contest gives balloons, and whether it goes on when the board is frozen
function balloonSaveSettings($contest, $enabled, $after_freeze, $actor) {
	if ($enabled && !balloonRuleGives(contestRule($contest))) {
		return 'OI 赛制的比赛在进行中不公布结果，没有气球可发';
	}
	DB::update("update contests set balloons = ".($enabled ? 1 : 0).", balloons_after_freeze = ".($after_freeze ? 1 : 0)." where id = {$contest['id']}");
	auditLog('contest.balloon_settings', 'contest', $contest['id'],
		array('balloons' => !empty($contest['balloons']), 'after_freeze' => !empty($contest['balloons_after_freeze'])),
		array('balloons' => (bool)$enabled, 'after_freeze' => (bool)$after_freeze), $actor);
	return '';
}
// The colours of the problems: $colors is id of the problem => array(colour, name). A
// problem that is given the colour it would have anyway has no colour of its own.
function balloonSaveColors($contest, $colors, $actor) {
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$rows = array();
	foreach ($colors as $problem_id => $chosen) {
		list($color, $name) = $chosen;
		$pos = array_search((int)$problem_id, $problem_ids, true);
		if ($pos === false) {
			return '比赛里没有这道题';
		}
		$err = balloonColorError($color, $name);
		if ($err !== '') {
			return chr(ord('A') + $pos % 26) . ' 题：' . $err;
		}
		$rows[(int)$problem_id] = array(strtolower($color), trim($name), $pos);
	}
	foreach ($rows as $problem_id => $row) {
		list($color, $name, $pos) = $row;
		$default = balloonDefaultColor($pos);
		if ($color === $default['color'] && ($name === '' || $name === $default['name'])) {
			DB::delete("delete from contest_balloon_colors where contest_id = {$contest['id']} and problem_id = $problem_id");
		} else {
			DB::insert("insert into contest_balloon_colors (contest_id, problem_id, color, name) values ({$contest['id']}, $problem_id, '".DB::escape($color)."', '".DB::escape($name)."')"
				." on duplicate key update color = values(color), name = values(name)");
		}
	}
	auditLog('contest.balloon_colors', 'contest', $contest['id'], null, array('problems' => count($rows)), $actor);
	return '';
}
// Writes down that a balloon was brought, or that it was not after all. A balloon that is
// not on the list is not brought.
function balloonSetDone($contest, $username, $problem_id, $done, $actor) {
	if (!balloonsEnabled($contest)) {
		return '这场比赛没有启用气球';
	}
	$found = false;
	foreach (balloonQueue($contest)['balloons'] as $balloon) {
		$found = $found || ($balloon['username'] === (string)$username && $balloon['problem_id'] === (int)$problem_id);
	}
	if (!$found) {
		return '没有这个气球：可能是提交被重测了，或者选手被移出了比赛';
	}
	$esc_username = DB::escape($username);
	if ($done) {
		// the first to say so is who brought it
		DB::insert("insert ignore into contest_balloons (contest_id, username, problem_id, done_at, done_by) values ({$contest['id']}, '$esc_username', ".(int)$problem_id.", '".DB::escape(UOJTime::$time_now_str)."', '".DB::escape($actor['username'])."')");
	} else {
		DB::delete("delete from contest_balloons where contest_id = {$contest['id']} and username = '$esc_username' and problem_id = ".(int)$problem_id);
	}
	return '';
}
