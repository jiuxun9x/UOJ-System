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
//   $waiting     what waits to be judged, see contestFirstSolves(): who solved first is told
//                only once nothing submitted before waits
//   $medals      the medals, as contestMedals() says them; null for those of a contest that
//                says nothing
// Returns array(
//   'problems' => position => array('solved' => how many solved it (under the other rules:
//                 got all of it), 'tries' => how many submissions to it count (under the
//                 other rules: how many tried it)),
//   'rows'     => of each row of the standings: array('key', 'person', 'rank', 'solved'
//                 (under the other rules the score), 'penalty' in seconds, 'dirt' => of the
//                 submissions to what was solved, the percentage that failed, or null where
//                 nothing was solved or the rule knows no such thing, 'band' => 0 or 1,
//                 which changes wherever the next row has solved less, 'virtual' => whether it
//                 is somebody who sat the contest virtually ("*" and the name), 'medal' => which
//                 of the medals they get, from 1 for the first kind, else 0 (who sat it
//                 virtually takes none and pushes nobody out of one), 'cells' => position =>
//                 array('kind' => 'none', 'first', 'solved', 'part', 'failed' or 'pending',
//                 'says', 'under')),
//   'medals'   => of each kind of medal: array('name', 'color', 'amount', 'of' (what a percentage
//                 is of, else null), 'count' => how many it is for, 'given' => how many got it:
//                 who is level with somebody may get one beyond),
//   'counted'  => what percentages are of: array('all' => how many contestants are on the
//                 board, 'effective' => how many of them solved something)
//   'stats'    => of each problem, what XCPCIO tells under its board: array('people' => how many
//                 of the board tried it, 'rejected' => how many submissions to it failed (under
//                 the other rules: how many got less than all of it), 'pending' => how many
//                 nobody knows the outcome of yet, 'first' and 'last' => seconds into the
//                 contest it was first and last solved (got all of it), or null)
// )
function contestOuterBoard($rule, $n_problems, $standings, $score, $last_try = array(), $waiting = array(), $medals = null) {
	$icpc = $rule === 'ICPC';
	$problems = array();
	$stats = array();
	for ($pos = 0; $pos < $n_problems; $pos++) {
		$problems[$pos] = array('solved' => 0, 'tries' => 0);
		$stats[$pos] = array('people' => 0, 'rejected' => 0, 'pending' => 0, 'first' => null, 'last' => null);
	}
	// when somebody solved a problem: the first and the last of the board
	$solved_at = function($pos, $at) use (&$stats) {
		$stats[$pos]['first'] = $stats[$pos]['first'] === null ? $at : min($stats[$pos]['first'], $at);
		$stats[$pos]['last'] = $stats[$pos]['last'] === null ? $at : max($stats[$pos]['last'], $at);
	};
	// who solved a problem first, of the contestants on the board, once that is sure (who sat
	// the contest virtually was not there to be first)
	$on_board = array();
	foreach ($standings as $row) {
		$key = (string)$row[2][0];
		if (!virtualPersonOfKey($key)[0]) {
			$on_board[$key] = isset($score[$key]) ? $score[$key] : array();
		}
	}
	$first = array();
	foreach (contestFirstSolves(contestPassedOfScore($icpc ? 'ICPC' : $rule, $on_board), $waiting)['problems'] as $pos => $solve) {
		if ($solve['sure']) {
			$first[$pos] = array($solve['offset'], $solve['key']);
		}
	}
	$rows = array();
	$band = 0;
	$before = null;
	// the rank of a contestant among the contestants alone: what the medals go by
	$contestants = 0;
	$contestant_rank = 0;
	$last_contestant = null;
	foreach ($standings as $row) {
		$key = (string)$row[2][0];
		list($virtual) = virtualPersonOfKey($key);
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
					$solved_at($pos, (int)$cell[1] - CONTEST_ICPC_PENALTY * $failed);
				} elseif ($pending > 0) {
					$shown = array('kind' => 'pending', 'says' => $says, 'under' => $under);
					$problems[$pos]['tries'] += $failed + $pending;
					$stats[$pos]['pending'] += $pending;
				} elseif ($failed > 0) {
					$shown = array('kind' => 'failed', 'says' => $says, 'under' => $tried_at);
					$problems[$pos]['tries'] += $failed;
				}
				if ($shown['kind'] !== 'none') {
					$stats[$pos]['people']++;
					$stats[$pos]['rejected'] += $failed;
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
				$stats[$pos]['people']++;
				if ($full) {
					$solved_at($pos, (int)$cell[1]);
				} else {
					$stats[$pos]['rejected']++;
				}
			}
			$cells[$pos] = $shown;
		}
		$level = $icpc ? $solved : (int)$row[0];
		if ($before !== null && $level !== $before) {
			$band = 1 - $band;
		}
		$before = $level;
		// what the medals go by: the rank among the contestants alone
		$among = null;
		if (!$virtual) {
			$contestants++;
			if ($last_contestant === null || $last_contestant !== array($row[0], $row[1])) {
				$contestant_rank = $contestants;
				$last_contestant = array($row[0], $row[1]);
			}
			$among = $contestant_rank;
		}
		$rows[] = array(
			'key' => $key, 'person' => $row[2], 'rank' => $row[3], 'solved' => $level, 'penalty' => (int)$row[1],
			'dirt' => $icpc && $solved > 0 ? (int)floor(100 * $wasted / ($solved + $wasted)) : null,
			'band' => $band, 'virtual' => $virtual, 'medal' => 0, 'cells' => $cells, 'among' => $among
		);
	}
	list($rows, $kinds, $counted) = boardGiveMedals($rows, $medals === null ? contestDefaultMedals() : $medals);
	return array('problems' => $problems, 'rows' => $rows, 'medals' => $kinds, 'counted' => $counted, 'stats' => $stats);
}
// Gives the rows of a board their medals (see contestMedals()): every kind goes to so many of
// the contestants after the ones the kinds before went to, by their rank among the contestants;
// who is level with the last of them gets it too, and who did nothing gets none. The rows have
// 'among' (the rank among the contestants, null for who sat the contest virtually), which
// they lose. Returns the rows, the kinds with how many each is for and how many got it, and
// what the percentages were of.
function boardGiveMedals($rows, $medals) {
	// what percentages are of: the contestants, and those of them who solved something
	$of = array('all' => 0, 'effective' => 0);
	foreach ($rows as $row) {
		if ($row['among'] !== null) {
			$of['all']++;
			$of['effective'] += $row['solved'] > 0 ? 1 : 0;
		}
	}
	$kinds = array();
	$up_to = 0;
	foreach ($medals as $tier) {
		$base = isset($tier['of']) && $tier['of'] === 'all' ? 'all' : 'effective';
		$count = (int)medalAmountCount($tier['amount'], $of[$base]);
		$up_to += $count;
		$kinds[] = array(
			'name' => $tier['name'], 'color' => $tier['color'], 'amount' => $tier['amount'], 'of' => substr($tier['amount'], -1) === '%' ? $base : null,
			'count' => $count, 'given' => 0, 'up_to' => $up_to
		);
	}
	foreach ($rows as &$row) {
		if ($row['among'] !== null && $row['solved'] > 0) {
			foreach ($kinds as $index => $kind) {
				if ($kind['count'] > 0 && $row['among'] <= $kind['up_to']) {
					$row['medal'] = $index + 1;
					$kinds[$index]['given']++;
					break;
				}
			}
		}
		unset($row['among']);
	}
	unset($row);
	foreach ($kinds as &$kind) {
		unset($kind['up_to']);
	}
	unset($kind);
	return array($rows, $kinds, $of);
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
// What was submitted to a contest as its outer board tells it (its tab of submissions, and
// what its statistics over time are drawn from): what the board counts and nothing more - no
// number of a submission, no code, no verdict but that it passed, failed, or is not told yet;
// as DOMjudge counts (see contestCells()), nothing that did not compile and nothing submitted to
// a problem after it was solved. Under the ICPC rule only: the other rules tell a score that
// is not final while the contest runs.
//   $people  the usernames whose submissions are told: the contestants on the board
//   $frozen  whether the board is frozen: what was submitted since it froze is not told
//   $until   up to how many seconds into the contest (null: to its end)
// Returns rows of array(seconds into the contest, username, position of the problem, 'yes',
// 'no' or 'wait'), the oldest first.
function boardRuns($contest, $people, $frozen, $until = null) {
	if (contestRule($contest) !== 'ICPC' || !$people) {
		return array();
	}
	$pos = array_flip(array_map('intval', contestProblemIds($contest['id'])));
	$people = array_flip(array_map('strval', $people));
	$start = $contest['start_time']->getTimestamp();
	$end = $until === null ? (int)$contest['last_min'] * 60 : (int)$until;
	$freeze = $frozen ? contestFreezeOffset($contest) : null;
	$runs = array();
	$closed = array();
	foreach (DB::selectAll("select submitter, problem_id, submit_time, score, status from submissions where contest_id = {$contest['id']} order by id", MYSQLI_ASSOC) as $row) {
		$name = (string)$row['submitter'];
		$offset = strtotime($row['submit_time']) - $start;
		if (!isset($people[$name]) || !isset($pos[(int)$row['problem_id']]) || $offset < 0 || $offset > $end) {
			continue;
		}
		$at = $pos[(int)$row['problem_id']];
		$judged = strpos((string)$row['status'], 'Judged') === 0;
		if (isset($closed[$name][$at]) || ($row['score'] === null && $judged)) {
			continue;
		}
		if ($row['score'] === null || ($freeze !== null && $offset >= $freeze)) {
			$outcome = 'wait';
		} else {
			$outcome = judgedScore($row['score']) == 100 ? 'yes' : 'no';
		}
		if ($row['score'] !== null && judgedScore($row['score']) == 100) {
			$closed[$name][$at] = true;
		}
		$runs[] = array($offset, $name, $at, $outcome);
	}
	return $runs;
}
// ---- the resolver
//
// The board of an ICPC contest as it froze, and what became of everything it did not tell, for
// the staff to reveal one by one in front of the room - the lowest of the board first, as the
// resolver of the ICPC Tools does it. Both are worked out from what was submitted, so that a
// contest whose results are published can be resolved as well (the board it keeps then is the
// one of the end alone).
// Returns array(
//   'problems' => rows of array('letter', 'color' => of its balloon, or null),
//   'people'   => of every contestant: array('key', 'username', 'nickname', 'cells' =>
//                 position => array(as the board froze, as it really is), each a cell
//                 array(kind, says, under, penalty in seconds where it is solved, else null)),
//   'medals'   => the kinds of medals, 'medal' => key => which of them they get in the end,
//   'freeze'   => seconds into the contest the board froze at, or null
// )
function boardResolverData($contest) {
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$pos = array_flip($problem_ids);
	$start = $contest['start_time']->getTimestamp();
	$duration = (int)$contest['last_min'] * 60;
	// what the board counts, as it counts it while the contest runs (see queryContestData())
	$data = array();
	foreach (DB::selectAll("select id, submit_time, submitter, problem_id, score from submissions where contest_id = {$contest['id']} and (score is not null or status not like 'Judged%') order by id", MYSQLI_NUM) as $row) {
		$offset = strtotime($row[1]) - $start;
		if (isset($pos[(int)$row[3]]) && $offset >= 0 && $offset <= $duration) {
			$data[] = array((int)$row[0], $row[1], (string)$row[2], $pos[(int)$row[3]], $row[4] === null ? null : (int)$row[4]);
		}
	}
	$contest_data = array('problems' => $problem_ids, 'data' => $data, 'people' => queryContestData($contest)['people'], 'final' => false);
	$freeze = contestFreezeOffset($contest);
	calcStandings($contest, $contest_data, $frozen_score, $frozen_standings, false, $freeze);
	calcStandings($contest, $contest_data, $true_score, $true_standings);
	$medals = contestMedals($contest);
	$boards = array(
		array(contestOuterBoard('ICPC', count($problem_ids), $frozen_standings, $frozen_score, boardLastTries($contest, $freeze === null ? null : $freeze - 1), array(), $medals), $frozen_score),
		array(contestOuterBoard('ICPC', count($problem_ids), $true_standings, $true_score, boardLastTries($contest), array(), $medals), $true_score)
	);
	$people = array();
	$medal = array();
	foreach ($boards as $which => $board) {
		list($table, $score) = $board;
		foreach ($table['rows'] as $row) {
			$key = $row['key'];
			if (!isset($people[$key])) {
				$people[$key] = array('key' => $key, 'username' => $key, 'nickname' => isset($row['person'][2]) ? (string)$row['person'][2] : '', 'cells' => array());
			}
			foreach ($row['cells'] as $at => $cell) {
				$solved = isset($score[$key][$at]) && $score[$key][$at][0] == 100;
				$people[$key]['cells'][$at][$which] = array($cell['kind'], $cell['says'], $cell['under'], $solved ? (int)$score[$key][$at][1] : null);
			}
			if ($which === 1) {
				$medal[$key] = $row['medal'];
			}
		}
	}
	$colors = balloonsEnabled($contest) ? balloonColors($contest) : array();
	$problems = array();
	foreach ($problem_ids as $at => $problem_id) {
		$problems[] = array('letter' => chr(ord('A') + $at % 26), 'color' => isset($colors[$problem_id]) ? $colors[$problem_id]['color'] : null);
	}
	return array('problems' => $problems, 'people' => array_values($people), 'medals' => $boards[1][0]['medals'], 'medal' => $medal, 'freeze' => $freeze);
}

// Up to when the last tries of a board are told, in seconds into the contest: a frozen board
// tells nothing that was submitted since it froze, and so no time of it either (a submission that
// did not compile is no attempt on the board, but was a last try). null: to $until, the moment
// the board is of (null for the end).
function boardLastTriesUntil($contest, $frozen, $until) {
	if (!$frozen) {
		return $until;
	}
	$before_freeze = contestFreezeOffset($contest) - 1;
	return $until === null ? $before_freeze : min($until, $before_freeze);
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
		contestBoardIsFrozen($contest), balloonsEnabled($contest), contestMedals($contest), (int)floor(UOJTime::$time_now->getTimestamp() / 30)
	);
	return md5(json_encode($facts));
}
// The outer board as a page is handed it: the problems and the rows, with nothing in them
// that is not for everybody - no titles of problems, no numbers of submissions.
function boardTable($contest, $standings, $score, $last_try) {
	$rule = contestRule($contest);
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$board = contestOuterBoard($rule, count($problem_ids), $standings, $score, $last_try, contestWaitingSubmissions($contest), contestMedals($contest));
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
		// who sat the contest virtually is "*" and their name to the board: the page puts the star
		list($virtual, $username) = virtualPersonOfKey($row['key']);
		$rows[] = array(
			'key' => $row['key'], 'username' => $username, 'nickname' => isset($row['person'][2]) ? (string)$row['person'][2] : '',
			'rank' => (int)$row['rank'], 'solved' => $row['solved'], 'penalty' => $row['penalty'], 'dirt' => $row['dirt'], 'band' => $row['band'],
			'virtual' => $virtual, 'medal' => $row['medal'], 'cells' => $cells
		);
	}
	return array('problems' => $problems, 'rows' => $rows, 'medals' => $board['medals'], 'counted' => $board['counted'], 'stats' => $board['stats']);
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
	$table = boardTable($contest, $standings, $score, boardLastTries($contest, boardLastTriesUntil($contest, $frozen, null)));
	$draft = $file . '.' . uojRandString(8);
	if (@file_put_contents($draft, json_encode(array('version' => $version, 'table' => $table))) !== false) {
		@rename($draft, $file);
	}
	return $table;
}
// The people who sat a contest virtually, or sit it now, for the page to offer whose view of
// the board to see: rows of 'username', 'nickname', 'running'; who sits it now first.
function boardVirtuals($contest) {
	$now = UOJTime::$time_now->getTimestamp();
	$running = array();
	$ended = array();
	foreach (DB::selectAll("select contest_virtuals.username, contest_virtuals.start_time, contest_virtuals.last_min, ifnull(user_info.nickname, '') as nickname from contest_virtuals"
			." left join user_info on user_info.username = contest_virtuals.username"
			." where contest_virtuals.contest_id = {$contest['id']} and contest_virtuals.start_time <= '".DB::escape(UOJTime::$time_now_str)."'"
			." order by contest_virtuals.start_time desc limit 500", MYSQLI_ASSOC) as $row) {
		$is_running = virtualPhase($row, $now) === 'running';
		$person = array('username' => $row['username'], 'nickname' => $row['nickname'], 'running' => $is_running);
		if ($is_running) {
			$running[] = $person;
		} else {
			$ended[] = $person;
		}
	}
	return array_merge($running, $ended);
}
// Everything the page of the outer board shows, for a viewer:
//   'phase'    'before' (not begun: nothing is shown, not even how many problems there are),
//              'running', 'waiting' (over, results not published), 'final', or 'replay' - the
//              board as somebody who sits the contest virtually sees it: the contest as it
//              stood at the moment of their participation they are at, as the pages of the
//              contest show it to them, with everybody who sat it virtually at the same moment
//              of theirs, under a star. Whoever sits it sees nothing else; anybody else may ask
//              to see it as one of them does ('vp'), the way Codeforces shows the standings of
//              a participant. A participation that is over is seen at its end, not frozen.
//   'elapsed'  seconds into the contest that the board is of; 'ticking' whether they go on
//   'frozen'   whether it is the board as it froze
//   'viewing'  in a replay, whose view it is: 'key', 'username', 'nickname', 'start_time',
//              'running', 'mine' (whether it is the viewer's own); else null
//   'virtual'  whether the people who sat it virtually are on the board
//   'virtuals' when it is over, the people who sat it virtually: see boardVirtuals() (nothing
//              for whoever sits it now)
//   'hash'     a word that changes when what is shown changes (the clock aside)
//   'runs'     where it was asked for ('runs' => true), what the contestants on the board
//              submitted, as boardRuns() tells it
// $options: 'vp' => whose view is asked for (a username), 'virtual' => false for the board of a
// contest that is over without the people who sat it virtually, 'runs' => true for the
// submissions as well.
function boardState($contest, $viewer, $options = array()) {
	$now = UOJTime::$time_now->getTimestamp();
	$start = $contest['start_time']->getTimestamp();
	$duration = (int)$contest['last_min'] * 60;
	$rule = contestRule($contest);
	$state = array(
		'id' => (int)$contest['id'], 'name' => html_entity_decode(strip_tags((string)$contest['name']), ENT_QUOTES, 'UTF-8'), 'rule' => $rule,
		'duration' => $duration, 'freeze' => contestFreezeOffset($contest), 'frozen' => false, 'ticking' => false,
		'elapsed' => 0, 'starts_in' => null, 'problems' => array(), 'rows' => array(), 'medals' => array(), 'counted' => array('all' => 0, 'effective' => 0), 'stats' => array(),
		'viewing' => null, 'virtual' => false, 'virtuals' => array()
	);
	$finished = $contest['cur_progress'] == CONTEST_FINISHED;
	$mine = $finished && $viewer != null && can($viewer, 'contest.virtual', $contest) ? runningVirtual($contest['id'], $viewer) : null;
	// whose view it is: the viewer's own while they sit the contest (they see nothing but the
	// moment they are at), else the one asked for, of somebody who has begun
	$viewed = $mine;
	if (!$viewed && $finished && isset($options['vp']) && validateUsername($options['vp'])) {
		$asked = queryVirtual($contest['id'], (string)$options['vp']);
		if ($asked && virtualPhase($asked, $now) !== 'upcoming') {
			$viewed = $asked;
		}
	}
	if ($contest['cur_progress'] == CONTEST_NOT_STARTED) {
		$state['phase'] = 'before';
		$state['starts_in'] = max(0, $start - $now);
	} elseif ($viewed) {
		$running = virtualPhase($viewed, $now) === 'running';
		$state['phase'] = 'replay';
		$state['elapsed'] = virtualElapsed($viewed, $now);
		$state['ticking'] = $running;
		$state['virtual'] = true;
		list($standings, $score, $contest_data, $state['frozen']) = virtualBoard($running ? $contest : array('freeze_minutes' => 0) + $contest, $state['elapsed'], true);
		$state = boardTable($contest, $standings, $score, boardLastTries($contest, boardLastTriesUntil($contest, $state['frozen'], $state['elapsed']))) + $state;
		$person = queryUser($viewed['username']);
		$state['viewing'] = array(
			'key' => '*' . $viewed['username'], 'username' => $viewed['username'], 'nickname' => $person && $person['nickname'] !== null ? (string)$person['nickname'] : '',
			'start_time' => $viewed['start_time'], 'running' => $running, 'mine' => $mine !== null
		);
	} else {
		$state['phase'] = $contest['cur_progress'] <= CONTEST_IN_PROGRESS ? 'running' : ($contest['cur_progress'] < CONTEST_FINISHED ? 'waiting' : 'final');
		$state['elapsed'] = max(0, min($duration, $now - $start));
		$state['ticking'] = $state['phase'] === 'running';
		$state['frozen'] = contestBoardIsFrozen($contest);
		if ($finished && (!isset($options['virtual']) || $options['virtual']) && virtualPeople($contest, 1)) {
			// the contest is over: everybody who sat it virtually, as they stood at the end of
			// their participation (or stand now, while it runs), under a star
			$state['virtual'] = true;
			calcStandings($contest, queryContestData($contest), $score, $standings);
			virtualAddUnofficial($contest, $standings, $score, $duration);
			$state = boardTable($contest, $standings, $score, boardLastTries($contest)) + $state;
		} else {
			$state = boardTableNow($contest) + $state;
		}
	}
	if ($finished && !$mine) {
		$state['virtuals'] = boardVirtuals($contest);
	}
	if (!empty($options['runs'])) {
		// of who is on the board as a contestant, up to the moment the board is of
		$people = array();
		foreach ($state['rows'] as $row) {
			if (!$row['virtual']) {
				$people[] = $row['username'];
			}
		}
		$state['runs'] = $state['phase'] === 'before' ? array() : boardRuns($contest, $people, $state['frozen'], $state['phase'] === 'replay' ? $state['elapsed'] : null);
	}
	$state['hash'] = md5(json_encode(array(isset($state['runs']) ? $state['runs'] : null,$state['phase'], $state['frozen'], $state['duration'], $state['freeze'], $state['problems'], $state['rows'], $state['medals'], $state['stats'], $state['viewing'], $state['virtual'], $state['virtuals'])));
	return $state;
}
