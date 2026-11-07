<?php

// What an ICPC contest is, written for the resolvers of others: the event feed of the Contest
// API of the CCS specifications (2023-06), which the resolver of the ICPC Tools reads, and the
// files of XCPCIO (config.json, team.json and run.json). Both hold everything, what was
// submitted since the board froze too: for the staff of a contest that is over, as the
// resolver of the site is (contest_resolver.php).

// ---- how things are written

// a moment, as the specifications write it: 2026-11-07T19:00:00.000+08:00
function feedTime($timestamp) {
	return date('Y-m-d\TH:i:s.000P', (int)$timestamp);
}
// a length of time: 4:10:00.000
function feedRelTime($seconds) {
	$seconds = max(0, (int)$seconds);
	return sprintf('%d:%02d:%02d.000', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
}
// The judgement types of the specifications, as the board counts them: what did not compile, and
// what nobody could judge, cost nothing (contestCells() does not count them).
function feedJudgementTypes() {
	return array(
		'AC' => array('name' => 'Accepted', 'penalty' => false, 'solved' => true),
		'WA' => array('name' => 'Wrong Answer', 'penalty' => true, 'solved' => false),
		'TLE' => array('name' => 'Time Limit Exceeded', 'penalty' => true, 'solved' => false),
		'MLE' => array('name' => 'Memory Limit Exceeded', 'penalty' => true, 'solved' => false),
		'OLE' => array('name' => 'Output Limit Exceeded', 'penalty' => true, 'solved' => false),
		'RTE' => array('name' => 'Run-Time Error', 'penalty' => true, 'solved' => false),
		'CE' => array('name' => 'Compile Error', 'penalty' => false, 'solved' => false),
		'JE' => array('name' => 'Judging Error', 'penalty' => false, 'solved' => false)
	);
}
// The judgement type of a submission (see feedJudgementTypes()), or null while it is not judged.
//   $score, $status, $error   what the submission says: its score, its status, its result_error
//   $details                  the details of its result, which say what failed
function feedJudgementOf($score, $status, $error, $details) {
	if (strpos((string)$status, 'Judged') !== 0) {
		return null;
	}
	if ($score === null) {
		return $error === 'Compile Error' ? 'CE' : 'JE';
	}
	if (judgedScore($score) == 100) {
		return 'AC';
	}
	$types = array(
		'Wrong Answer' => 'WA', 'Time Limit Exceeded' => 'TLE', 'Memory Limit Exceeded' => 'MLE', 'Output Limit Exceeded' => 'OLE',
		'Runtime Error' => 'RTE', 'Dangerous Syscalls' => 'RTE', 'Judgement Failed' => 'JE', 'Judgment Failed' => 'JE'
	);
	$verdict = submissionVerdictOf($score, $details);
	return isset($types[$verdict]) ? $types[$verdict] : 'WA';
}
// what XCPCIO calls a judgement type
function feedXcpcioStatus($type) {
	$status = array(
		'AC' => 'ACCEPTED', 'WA' => 'WRONG_ANSWER', 'TLE' => 'TIME_LIMIT_EXCEEDED', 'MLE' => 'MEMORY_LIMIT_EXCEEDED',
		'OLE' => 'OUTPUT_LIMIT_EXCEEDED', 'RTE' => 'RUNTIME_ERROR', 'CE' => 'COMPILATION_ERROR', 'JE' => 'JUDGEMENT_FAILED'
	);
	return $type === null ? 'PENDING' : $status[$type];
}
// A language of the site as an id of the specifications, and the extension of its files.
function feedLanguage($language) {
	$language = (string)$language;
	$id = preg_replace('/[^A-Za-z0-9_.-]/', '', str_replace('+', 'p', strtolower($language)));
	if (preg_match('/^C\+\+/', $language)) {
		$extension = 'cpp';
	} elseif (preg_match('/^C\d/', $language)) {
		$extension = 'c';
	} elseif (preg_match('/^Java/', $language)) {
		$extension = 'java';
	} elseif (preg_match('/^Python/', $language)) {
		$extension = 'py';
	} elseif ($language === 'Pascal') {
		$extension = 'pas';
	} else {
		$extension = 'txt';
	}
	return array($id === '' ? 'other' : $id, $extension);
}

// ---- what is written: the facts of a contest, gathered once
//   $facts  array(
//     'id', 'name', 'start' => its start as a timestamp, 'duration', 'freeze' (seconds into
//     it, or null), 'published' => whether its results are, 'now' => when it is written,
//     'problems' => rows of array('letter', 'name', 'color', 'time_limit', 'tests'),
//     'teams' => rows of array('id', 'name'), the contestants,
//     'submissions' => rows of array('id', 'team', 'position', 'offset', 'language', 'type'
//                      (see feedJudgementOf()), 'judged' => a timestamp or null), the oldest first,
//     'medals' => rows of array('name', 'count', 'given' => the ids it went to),
//     'firsts' => position => the id of who solved it first, 'winner' => ids, the first
//   )

// The event feed: a line of JSON for every event, as /contests/<id>/event-feed serves it.
function feedClics($facts) {
	$lines = array();
	$event = function($type, $id, $data) use (&$lines) {
		$lines[] = json_encode(array('type' => $type, 'id' => $id, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	};
	$contest_id = 'uoj-' . $facts['id'];
	$contest = array(
		'id' => $contest_id, 'name' => $facts['name'], 'formal_name' => $facts['name'],
		'start_time' => feedTime($facts['start']), 'duration' => feedRelTime($facts['duration']),
		'scoreboard_type' => 'pass-fail', 'penalty_time' => intdiv(CONTEST_ICPC_PENALTY, 60)
	);
	if ($facts['freeze'] !== null) {
		$contest['scoreboard_freeze_duration'] = feedRelTime($facts['duration'] - $facts['freeze']);
	}
	$event('contest', null, $contest);
	foreach (feedJudgementTypes() as $id => $type) {
		$event('judgement-types', $id, array('id' => $id) + $type);
	}
	$languages = array();
	foreach ($facts['submissions'] as $submission) {
		list($id, $extension) = feedLanguage($submission['language']);
		$languages[$id] = array('id' => $id, 'name' => (string)$submission['language'], 'entry_point_required' => false, 'extensions' => array($extension));
	}
	foreach ($languages as $id => $language) {
		$event('languages', $id, $language);
	}
	foreach ($facts['problems'] as $ordinal => $problem) {
		$data = array(
			'id' => $problem['letter'], 'label' => $problem['letter'], 'name' => $problem['name'], 'ordinal' => $ordinal,
			'time_limit' => (float)$problem['time_limit'], 'test_data_count' => (int)$problem['tests']
		);
		if ($problem['color'] !== null) {
			$data['rgb'] = $problem['color'];
		}
		$event('problems', $problem['letter'], $data);
	}
	$event('groups', 'participants', array('id' => 'participants', 'name' => '正式选手'));
	foreach ($facts['teams'] as $team) {
		$event('teams', $team['id'], array(
			'id' => $team['id'], 'name' => $team['name'], 'label' => $team['id'], 'display_name' => $team['name'],
			'group_ids' => array('participants'), 'hidden' => false
		));
	}
	$event('state', null, array('started' => feedTime($facts['start']), 'frozen' => null, 'ended' => null, 'thawed' => null, 'finalized' => null, 'end_of_updates' => null));
	foreach ($facts['submissions'] as $submission) {
		$time = $facts['start'] + $submission['offset'];
		$event('submissions', (string)$submission['id'], array(
			'id' => (string)$submission['id'], 'language_id' => feedLanguage($submission['language'])[0], 'problem_id' => $facts['problems'][$submission['position']]['letter'],
			'team_id' => $submission['team'], 'time' => feedTime($time), 'contest_time' => feedRelTime($submission['offset']), 'files' => array()
		));
		$judgement = array(
			'id' => (string)$submission['id'], 'submission_id' => (string)$submission['id'], 'judgement_type_id' => $submission['type'],
			'start_time' => feedTime($time), 'start_contest_time' => feedRelTime($submission['offset'])
		);
		if ($submission['type'] !== null) {
			$judged = max($time, $submission['judged'] === null ? $time : $submission['judged']);
			$judgement += array('end_time' => feedTime($judged), 'end_contest_time' => feedRelTime($judged - $facts['start']));
		}
		$event('judgements', (string)$submission['id'], $judgement);
	}
	// the medals, the winner and who solved a problem first, as the resolver of the ICPC Tools
	// knows them: the first three kinds of medals are its gold, silver and bronze
	$medal_ids = array('gold-medal', 'silver-medal', 'bronze-medal');
	foreach ($facts['medals'] as $index => $medal) {
		$id = isset($medal_ids[$index]) ? $medal_ids[$index] : 'honors-' . ($index + 1);
		$event('awards', $id, array('id' => $id, 'citation' => $medal['name'], 'team_ids' => $medal['given']));
	}
	if ($facts['winner']) {
		$event('awards', 'winner', array('id' => 'winner', 'citation' => '冠军', 'team_ids' => $facts['winner']));
	}
	foreach ($facts['firsts'] as $position => $team) {
		$letter = $facts['problems'][$position]['letter'];
		$event('awards', 'first-to-solve-' . $letter, array('id' => 'first-to-solve-' . $letter, 'citation' => $letter . ' 题一血', 'team_ids' => array($team)));
	}
	$end = $facts['start'] + $facts['duration'];
	$pending = count(array_filter($facts['submissions'], function($submission) {
		return $submission['type'] === null;
	}));
	// over, and final once nothing waits to be judged
	$final = $pending === 0 ? feedTime(max($end, $facts['now'])) : null;
	$event('state', null, array(
		'started' => feedTime($facts['start']), 'frozen' => $facts['freeze'] === null ? null : feedTime($facts['start'] + $facts['freeze']),
		'ended' => feedTime($end), 'thawed' => $facts['published'] && $facts['freeze'] !== null ? feedTime(max($end, $facts['now'])) : null,
		'finalized' => $final, 'end_of_updates' => $final
	));
	return implode("\n", $lines) . "\n";
}

// The files of XCPCIO: file name => what is in it.
function feedXcpcio($facts) {
	$medal = array();
	foreach (array('gold', 'silver', 'bronze') as $index => $metal) {
		if (isset($facts['medals'][$index])) {
			$medal[$metal] = $facts['medals'][$index]['count'];
		}
	}
	$config = array(
		'contest_name' => $facts['name'],
		'start_time' => (int)$facts['start'],
		'end_time' => (int)$facts['start'] + $facts['duration'],
		'frozen_time' => $facts['freeze'] === null ? 0 : $facts['duration'] - $facts['freeze'],
		'penalty' => CONTEST_ICPC_PENALTY,
		'problem_quantity' => count($facts['problems']),
		'problem_id' => array_column($facts['problems'], 'letter'),
		'group' => array('official' => '正式选手'),
		'status_time_display' => array('correct' => true, 'incorrect' => true, 'pending' => true),
		// the board of the site counts the penalty in seconds
		'options' => array('calculation_of_penalty' => 'in_seconds')
	);
	if ($medal) {
		$config['medal'] = array('official' => $medal);
	}
	$colors = array_column($facts['problems'], 'color');
	if (array_filter($colors)) {
		$config['balloon_color'] = array_map(function($color) {
			$color = $color === null ? '#cccccc' : $color;
			$dark = function($hex) {
				$c = hexdec($hex) / 255;
				return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
			};
			$l = 0.2126 * $dark(substr($color, 1, 2)) + 0.7152 * $dark(substr($color, 3, 2)) + 0.0722 * $dark(substr($color, 5, 2));
			return array('background_color' => $color, 'color' => $l > 0.36 ? '#000000' : '#ffffff');
		}, $colors);
	}
	$teams = array();
	foreach ($facts['teams'] as $team) {
		$teams[$team['id']] = array('team_id' => $team['id'], 'name' => $team['name'], 'organization' => '', 'group' => array('official'), 'official' => true);
	}
	$runs = array();
	foreach ($facts['submissions'] as $submission) {
		$runs[] = array(
			'submission_id' => (string)$submission['id'], 'team_id' => $submission['team'], 'problem_id' => $submission['position'],
			'timestamp' => $submission['offset'], 'status' => feedXcpcioStatus($submission['type']), 'language' => (string)$submission['language']
		);
	}
	$json = function($value) {
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
	};
	return array('config.json' => $json($config), 'team.json' => $json($teams ? $teams : new stdClass()), 'run.json' => $json($runs));
}

// ---- the facts of a contest, as the database has them (see above)
function feedFacts($contest) {
	$data = boardResolverData($contest);
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$pos = array_flip($problem_ids);
	$start = $contest['start_time']->getTimestamp();
	$duration = (int)$contest['last_min'] * 60;
	$problems = array();
	foreach ($problem_ids as $at => $problem_id) {
		$problem = queryProblemBrief($problem_id);
		// its time limit and how many tests it has, as its problem.conf says (or what the judgers take)
		$conf = (string)@file_get_contents("/var/uoj_data/$problem_id/problem.conf");
		$problems[] = array(
			'letter' => $data['problems'][$at]['letter'], 'color' => $data['problems'][$at]['color'],
			'name' => $problem ? html_entity_decode(strip_tags((string)$problem['title']), ENT_QUOTES, 'UTF-8') : $data['problems'][$at]['letter'],
			'time_limit' => preg_match('/^[ \t]*time_limit[ \t]+([0-9]+(?:\.[0-9]+)?)[ \t]*\r?$/m', $conf, $m) ? (float)$m[1] : 1.0,
			'tests' => preg_match('/^[ \t]*n_tests[ \t]+([0-9]+)[ \t]*\r?$/m', $conf, $m) ? (int)$m[1] : 0
		);
	}
	$teams = array();
	$who = array();
	foreach ($data['people'] as $person) {
		$teams[] = array('id' => $person['username'], 'name' => $person['nickname'] !== '' ? $person['nickname'] : $person['username']);
		$who[$person['username']] = true;
	}
	// what the contestants submitted while the contest ran
	$submissions = array();
	foreach (DB::selectAll("select id, submitter, problem_id, submit_time, judge_time, score, status, result_error, result, language from submissions where contest_id = {$contest['id']} order by id", MYSQLI_ASSOC) as $row) {
		$offset = strtotime($row['submit_time']) - $start;
		if (!isset($who[(string)$row['submitter']]) || !isset($pos[(int)$row['problem_id']]) || $offset < 0 || $offset > $duration) {
			continue;
		}
		$result = json_decode((string)$row['result'], true);
		$submissions[] = array(
			'id' => (int)$row['id'], 'team' => (string)$row['submitter'], 'position' => $pos[(int)$row['problem_id']], 'offset' => $offset,
			'language' => (string)$row['language'], 'judged' => $row['judge_time'] === null ? null : strtotime($row['judge_time']),
			'type' => feedJudgementOf($row['score'], $row['status'], $row['result_error'], is_array($result) && isset($result['details']) ? $result['details'] : '')
		);
	}
	// the medals and the firsts, of the board as it really is
	$medals = array();
	foreach ($data['medals'] as $index => $kind) {
		$medals[$index] = array('name' => $kind['name'], 'count' => $kind['count'], 'given' => array());
	}
	$winner = array();
	$firsts = array();
	$best = null;
	foreach ($data['people'] as $person) {
		$medal = isset($data['medal'][$person['key']]) ? $data['medal'][$person['key']] : 0;
		if ($medal > 0) {
			$medals[$medal - 1]['given'][] = $person['username'];
		}
		foreach ($person['cells'] as $at => $cell) {
			if ($cell[1][0] === 'first') {
				$firsts[$at] = $person['username'];
			}
		}
	}
	ksort($firsts);
	// who is first in the end: who solved the most, with the least penalty
	$standing = array();
	foreach ($data['people'] as $person) {
		$solved = 0;
		$penalty = 0;
		foreach ($person['cells'] as $cell) {
			if ($cell[1][3] !== null) {
				$solved++;
				$penalty += $cell[1][3];
			}
		}
		if ($solved > 0) {
			$standing[] = array($solved, $penalty, $person['username']);
		}
	}
	foreach ($standing as $row) {
		if ($best === null || $row[0] > $best[0] || ($row[0] == $best[0] && $row[1] < $best[1])) {
			$best = $row;
		}
	}
	foreach ($standing as $row) {
		if ($best !== null && $row[0] == $best[0] && $row[1] == $best[1]) {
			$winner[] = $row[2];
		}
	}
	return array(
		'id' => (int)$contest['id'], 'name' => html_entity_decode(strip_tags((string)$contest['name']), ENT_QUOTES, 'UTF-8'),
		'start' => $start, 'duration' => $duration, 'freeze' => $data['freeze'], 'published' => $contest['cur_progress'] >= CONTEST_FINISHED,
		'now' => UOJTime::$time_now->getTimestamp(), 'problems' => $problems, 'teams' => $teams, 'submissions' => $submissions,
		'medals' => $medals, 'firsts' => $firsts, 'winner' => $winner
	);
}
