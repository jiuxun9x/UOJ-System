<?php

// The outer board.
//
// A contest has a board for the people who are in it, and one for the people who watch: on
// the wall of the room, or from outside. It is the one the room looks at, so it shows what
// everybody may know - it is frozen when the board is frozen, for whoever looks - and it says
// more at a glance, the way the outer boards of QOJ and of the regional contests do: how
// many solved each problem out of how many submissions, who was first, and how much of what
// a contestant submitted was for nothing ("dirt").
//   $rule        the rule of the contest
//   $n_problems  how many problems it has
//   $standings   what calcStandings() made: rows of score, penalty, person, rank
//   $score       what calcStandings() made: who => position => cell
//   $last_try    who => position => seconds into the contest of their last submission to
//                the problem, where it is known: it stands under what was tried in vain
// Returns array(
//   'problems' => position => array('solved' => how many solved it (under the other rules:
//                 got all of it), 'tries' => how many submissions to it count (under the
//                 other rules: how many tried it)),
//   'rows'     => of each row of the standings: array('key', 'person', 'rank', 'solved'
//                 (under the other rules the score), 'penalty' in seconds, 'dirt' => of the
//                 submissions to what was solved, the percentage that failed, or null where
//                 nothing was solved or the rule knows no such thing, 'band' => 0 or 1,
//                 which changes wherever the next row has solved less, 'cells' => position
//                 => array('kind' => 'none', 'first', 'solved', 'part', 'failed' or
//                 'pending', 'says', 'under'))
// )
function contestOuterBoard($rule, $n_problems, $standings, $score, $last_try = array()) {
	$icpc = $rule === 'ICPC';
	$problems = array();
	for ($pos = 0; $pos < $n_problems; $pos++) {
		$problems[$pos] = array('solved' => 0, 'tries' => 0);
	}
	// when a problem was solved for the first time, and by whom
	$first = array();
	foreach ($standings as $row) {
		$key = (string)$row[2][0];
		foreach (isset($score[$key]) ? $score[$key] : array() as $pos => $cell) {
			if ($cell[0] < 100) {
				continue;
			}
			$at = $icpc ? $cell[1] - CONTEST_ICPC_PENALTY * (isset($cell[3]) ? (int)$cell[3] : 0) : $cell[1];
			if (!isset($first[$pos]) || $at < $first[$pos][0]) {
				$first[$pos] = array($at, $key);
			}
		}
	}
	$rows = array();
	$band = 0;
	$before = null;
	foreach ($standings as $row) {
		$key = (string)$row[2][0];
		$solved = 0;
		$wasted = 0;
		$cells = array();
		for ($pos = 0; $pos < $n_problems; $pos++) {
			$cell = isset($score[$key][$pos]) ? $score[$key][$pos] : null;
			$shown = array('kind' => 'none', 'says' => '', 'under' => '');
			$tried_at = isset($last_try[$key][$pos]) ? contestClock($last_try[$key][$pos]) : '';
			$is_first = isset($first[$pos]) && $first[$pos][1] === $key;
			if ($cell && $icpc) {
				$failed = isset($cell[3]) ? (int)$cell[3] : 0;
				$pending = isset($cell[4]) ? (int)$cell[4] : 0;
				list($says, $under) = contestIcpcCell($cell);
				if ($cell[0] == 100) {
					$shown = array('kind' => $is_first ? 'first' : 'solved', 'says' => $says, 'under' => $under);
					$solved++;
					$wasted += $failed;
					$problems[$pos]['solved']++;
					$problems[$pos]['tries'] += $failed + 1;
				} elseif ($pending > 0) {
					$shown = array('kind' => 'pending', 'says' => $says, 'under' => $under);
					$problems[$pos]['tries'] += $failed + $pending;
				} elseif ($failed > 0) {
					$shown = array('kind' => 'failed', 'says' => $says, 'under' => $tried_at);
					$problems[$pos]['tries'] += $failed;
				}
			} elseif ($cell) {
				$full = $cell[0] >= 100;
				$shown = array(
					'kind' => $full ? ($is_first ? 'first' : 'solved') : ($cell[0] > 0 ? 'part' : 'failed'),
					'says' => (string)(int)$cell[0],
					'under' => $cell[0] > 0 ? contestClock($cell[1]) : $tried_at
				);
				$solved += $full ? 1 : 0;
				$problems[$pos]['solved'] += $full ? 1 : 0;
				$problems[$pos]['tries']++;
			}
			$cells[$pos] = $shown;
		}
		$level = $icpc ? $solved : (int)$row[0];
		if ($before !== null && $level !== $before) {
			$band = 1 - $band;
		}
		$before = $level;
		$rows[] = array(
			'key' => $key, 'person' => $row[2], 'rank' => $row[3], 'solved' => $level, 'penalty' => (int)$row[1],
			'dirt' => $icpc && $solved > 0 ? (int)floor(100 * $wasted / ($solved + $wasted)) : null,
			'band' => $band, 'cells' => $cells
		);
	}
	return array('problems' => $problems, 'rows' => $rows);
}

// ---- queries

// When each contestant last submitted to each problem of a contest, in seconds into it, up to
// $until seconds into it (null: to its end): who => position => seconds.
function boardLastTries($contest, $until = null) {
	$pos = array_flip(array_map('intval', contestProblemIds($contest['id'])));
	$start = $contest['start_time']->getTimestamp();
	$cond = $until === null ? '' : " and submit_time <= '" . date('Y-m-d H:i:s', $start + $until) . "'";
	$tries = array();
	foreach (DB::selectAll("select submitter, problem_id, max(submit_time) as last_time from submissions where contest_id = {$contest['id']}$cond group by submitter, problem_id", MYSQLI_ASSOC) as $row) {
		if (isset($pos[(int)$row['problem_id']])) {
			$tries[(string)$row['submitter']][$pos[(int)$row['problem_id']]] = max(0, strtotime($row['last_time']) - $start);
		}
	}
	return $tries;
}
// What the outer board is made of, as a word that changes when it does: the board is asked
// for every few seconds by everybody who watches, and is put together anew only when
// something happened. (Every half minute it is, whatever happened: a name may have changed.)
function boardVersion($contest) {
	$id = (int)$contest['id'];
	$facts = array(
		DB::selectFirst("select count(*), ifnull(max(id), 0), ifnull(sum(score), 0), ifnull(sum(status like 'Judged%'), 0), ifnull(sum(unix_timestamp(submit_time) % 100000), 0) from submissions where contest_id = $id", MYSQLI_NUM),
		DB::selectFirst("select count(*), ifnull(sum(has_participated), 0) from contests_registrants where contest_id = $id", MYSQLI_NUM),
		DB::selectFirst("select count(*), ifnull(sum(score), 0), ifnull(sum(penalty), 0) from contests_submissions where contest_id = $id", MYSQLI_NUM),
		contestProblemIds($id), $contest['cur_progress'], $contest['start_time_str'], (int)$contest['last_min'], contestFreezeMinutes($contest),
		contestBoardIsFrozen($contest), balloonsEnabled($contest), (int)floor(UOJTime::$time_now->getTimestamp() / 30)
	);
	return md5(json_encode($facts));
}
// The outer board as a page is handed it: the problems and the rows, with nothing in them
// that is not for everybody - no titles of problems, no numbers of submissions.
function boardTable($contest, $standings, $score, $last_try) {
	$rule = contestRule($contest);
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$board = contestOuterBoard($rule, count($problem_ids), $standings, $score, $last_try);
	$colors = balloonsEnabled($contest) ? balloonColors($contest) : array();
	$problems = array();
	foreach ($problem_ids as $pos => $problem_id) {
		$problems[] = array(
			'letter' => chr(ord('A') + $pos % 26),
			'solved' => $board['problems'][$pos]['solved'], 'tries' => $board['problems'][$pos]['tries'],
			'color' => isset($colors[$problem_id]) ? $colors[$problem_id]['color'] : null
		);
	}
	$rows = array();
	foreach ($board['rows'] as $row) {
		$cells = array();
		foreach ($row['cells'] as $cell) {
			$cells[] = array($cell['kind'], $cell['says'], $cell['under']);
		}
		$rows[] = array(
			'key' => $row['key'], 'username' => (string)$row['person'][0], 'nickname' => isset($row['person'][2]) ? (string)$row['person'][2] : '',
			'rank' => (int)$row['rank'], 'solved' => $row['solved'], 'penalty' => $row['penalty'], 'dirt' => $row['dirt'], 'band' => $row['band'], 'cells' => $cells
		);
	}
	return array('problems' => $problems, 'rows' => $rows);
}
// The outer board of a contest as it is for everybody right now, kept between two times it
// is asked for as long as nothing happened.
function boardTableNow($contest) {
	$version = boardVersion($contest);
	$file = sys_get_temp_dir() . '/uoj-outer-board-' . (int)$contest['id'] . '.json';
	$kept = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
	if (is_array($kept) && isset($kept['version']) && $kept['version'] === $version) {
		return $kept['table'];
	}
	// frozen for whoever looks: this is the board of the room
	$frozen = contestBoardIsFrozen($contest);
	calcStandings($contest, queryContestData($contest), $score, $standings, false, $frozen ? contestFreezeOffset($contest) : null);
	$table = boardTable($contest, $standings, $score, boardLastTries($contest));
	$draft = $file . '.' . uojRandString(8);
	if (@file_put_contents($draft, json_encode(array('version' => $version, 'table' => $table))) !== false) {
		@rename($draft, $file);
	}
	return $table;
}
// Everything the page of the outer board shows, for a viewer:
//   'phase'    'before' (not begun: nothing is shown, not even how many problems there are),
//              'running', 'waiting' (over, results not published), 'final', or 'replay' - for
//              somebody who sits the contest virtually, to whom it is the board of the
//              moment they are at, as the pages of the contest are
//   'elapsed'  seconds into the contest that the board is of; 'ticking' whether they go on
//   'frozen'   whether it is the board as it froze
//   'hash'     a word that changes when what is shown changes (the clock aside)
function boardState($contest, $viewer) {
	$now = UOJTime::$time_now->getTimestamp();
	$start = $contest['start_time']->getTimestamp();
	$duration = (int)$contest['last_min'] * 60;
	$rule = contestRule($contest);
	$state = array(
		'id' => (int)$contest['id'], 'name' => html_entity_decode(strip_tags((string)$contest['name']), ENT_QUOTES, 'UTF-8'), 'rule' => $rule,
		'duration' => $duration, 'freeze' => contestFreezeOffset($contest), 'frozen' => false, 'ticking' => false,
		'elapsed' => 0, 'starts_in' => null, 'problems' => array(), 'rows' => array()
	);
	$virtual = $contest['cur_progress'] == CONTEST_FINISHED && $viewer != null && can($viewer, 'contest.virtual', $contest) ? runningVirtual($contest['id'], $viewer) : null;
	if ($contest['cur_progress'] == CONTEST_NOT_STARTED) {
		$state['phase'] = 'before';
		$state['starts_in'] = max(0, $start - $now);
	} elseif ($virtual) {
		$state['phase'] = 'replay';
		$state['elapsed'] = virtualElapsed($virtual, $now);
		$state['ticking'] = true;
		list($standings, $score, $contest_data, $state['frozen']) = virtualBoard($contest, $state['elapsed'], false);
		$state = boardTable($contest, $standings, $score, boardLastTries($contest, $state['elapsed'])) + $state;
	} else {
		$state['phase'] = $contest['cur_progress'] <= CONTEST_IN_PROGRESS ? 'running' : ($contest['cur_progress'] < CONTEST_FINISHED ? 'waiting' : 'final');
		$state['elapsed'] = max(0, min($duration, $now - $start));
		$state['ticking'] = $state['phase'] === 'running';
		$state['frozen'] = contestBoardIsFrozen($contest);
		$state = boardTableNow($contest) + $state;
	}
	$state['hash'] = md5(json_encode(array($state['phase'], $state['frozen'], $state['duration'], $state['freeze'], $state['problems'], $state['rows'])));
	return $state;
}
