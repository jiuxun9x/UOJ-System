<?php

// Homework of a domain.
//
// A homework is a list of problems with points, and three moments: begin_at, when its
// problems open; penalty_since, from which on a submission counts for less; and end_at, when
// the official scores are settled. Members of the domain claim a homework to take part. What
// they submit to its problems through the homework counts; of every participant and problem,
// the submission that is worth the most.
//
// The first part of this file has no need of the database, and is tested without one.

// ---- times, without the database

// what a homework is at a moment: draft, publishing, upcoming, running, penalty or ended
function homeworkPhase($homework, $now) {
	if ($homework['status'] !== 'published') {
		return $homework['status'];
	}
	if ($now < strtotime($homework['begin_at'])) {
		return 'upcoming';
	}
	if ($now >= strtotime($homework['end_at'])) {
		return 'ended';
	}
	if ($homework['penalty_since'] !== null && $now >= strtotime($homework['penalty_since'])) {
		return 'penalty';
	}
	return 'running';
}
function homeworkPhaseName($phase) {
	$names = array(
		'draft' => array('草稿', 'badge-secondary'),
		'publishing' => array('发布中', 'badge-info'),
		'upcoming' => array('未开始', 'badge-info'),
		'running' => array('进行中', 'badge-success'),
		'penalty' => array('延期中', 'badge-warning'),
		'ended' => array('已截止', 'badge-dark')
	);
	return $names[$phase];
}

// ---- the penalty for being late
//
// Whoever sets a homework decides what a late submission is worth: a list of steps, each
// "from so many hours after penalty_since on, this share of the score". Before the first step
// a submission is worth all of its score; from end_at on it does not count at all.

// The steps as the form of a homework posts them: three lists of the same length, how long
// after the deadline a step starts, whether that is in hours or days, and the percentage of
// the score from there on. Rows that are left empty are skipped, and the steps are put in
// order. Returns array(rules, '') or array(null, what is wrong).
function homeworkPenaltyRulesFromForm($after, $unit, $percent) {
	if (!is_array($after) || !is_array($unit) || !is_array($percent)) {
		return array(array(), '');
	}
	$rules = array();
	foreach ($after as $index => $value) {
		$value = is_string($value) ? trim($value) : '';
		$share = isset($percent[$index]) && is_string($percent[$index]) ? trim($percent[$index]) : '';
		if ($value === '' && $share === '') {
			continue;
		}
		$number = count($rules) + 1;
		if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
			return array(null, "迟交规则第 {$number} 段：迟交时长应为不小于 0 的数字");
		}
		if (!preg_match('/^\d+(\.\d+)?$/', $share) || (float)$share > 100) {
			return array(null, "迟交规则第 {$number} 段：计分比例应在 0 到 100 之间");
		}
		$hours = (float)$value * (isset($unit[$index]) && $unit[$index] === 'day' ? 24 : 1);
		if ($hours > 24 * 3650) {
			return array(null, "迟交规则第 {$number} 段：迟交时长太长");
		}
		$rules[] = array('after_hours' => $hours, 'multiplier' => round((float)$share / 100, 4));
	}
	if (count($rules) > 20) {
		return array(null, '迟交规则最多 20 段');
	}
	usort($rules, function($a, $b) {
		return $a['after_hours'] < $b['after_hours'] ? -1 : ($a['after_hours'] > $b['after_hours'] ? 1 : 0);
	});
	for ($i = 1; $i < count($rules); $i++) {
		if ($rules[$i]['after_hours'] == $rules[$i - 1]['after_hours']) {
			return array(null, '迟交规则里有两段从同一时刻开始');
		}
	}
	return array($rules, '');
}
// the steps as the form shows them: how long after the deadline, in which unit, and the percentage
function homeworkPenaltyRuleRows($rules) {
	$rows = array();
	foreach ($rules as $rule) {
		$in_days = $rule['after_hours'] > 0 && fmod($rule['after_hours'], 24) == 0;
		$rows[] = array(
			'after' => homeworkTrimNumber($in_days ? $rule['after_hours'] / 24 : $rule['after_hours']),
			'unit' => $in_days ? 'day' : 'hour',
			'percent' => homeworkTrimNumber($rule['multiplier'] * 100)
		);
	}
	return $rows;
}
function homeworkTrimNumber($number) {
	return rtrim(rtrim(sprintf('%.2f', $number), '0'), '.');
}
// What the rules of a homework mean, for the people who take part: one line for every stretch of time.
function homeworkDescribePenalty($homework) {
	$lines = array();
	if ($homework['penalty_since'] === null || strtotime($homework['penalty_since']) >= strtotime($homework['end_at'])) {
		$lines[] = substr($homework['end_at'], 0, 16) . ' 前提交：按 100% 计分';
		$lines[] = '之后提交：不计入正式成绩';
		return $lines;
	}
	$lines[] = substr($homework['penalty_since'], 0, 16) . ' 前提交：按 100% 计分';
	$rules = homeworkPenaltyRules($homework);
	$span = function($hours) {
		return $hours > 0 && fmod($hours, 24) == 0 ? homeworkTrimNumber($hours / 24) . ' 天' : homeworkTrimNumber($hours) . ' 小时';
	};
	if (!$rules || $rules[0]['after_hours'] > 0) {
		$lines[] = '迟交' . ($rules ? '不超过 ' . $span($rules[0]['after_hours']) : '') . '：按 100% 计分';
	}
	foreach ($rules as $index => $rule) {
		$percent = homeworkTrimNumber($rule['multiplier'] * 100) . '%';
		if (isset($rules[$index + 1]) && $rule['after_hours'] == 0) {
			$lines[] = '迟交不超过 ' . $span($rules[$index + 1]['after_hours']) . "：按 {$percent} 计分";
		} elseif (isset($rules[$index + 1])) {
			$lines[] = '迟交 ' . $span($rule['after_hours']) . ' 到 ' . $span($rules[$index + 1]['after_hours']) . "：按 {$percent} 计分";
		} else {
			$lines[] = '迟交' . ($rule['after_hours'] > 0 ? '超过 ' . $span($rule['after_hours']) : '') . "：按 {$percent} 计分";
		}
	}
	$lines[] = substr($homework['end_at'], 0, 16) . ' 后提交：不计入正式成绩';
	return $lines;
}
function homeworkPenaltyRules($homework) {
	$rules = json_decode($homework['penalty_rules'], true);
	return is_array($rules) ? $rules : array();
}

// What a submission made at a moment is worth: 1 before penalty_since, then what the rules
// say, and null from end_at on and before begin_at, when it does not count at all.
function homeworkMultiplier($homework, $submit_time) {
	if ($submit_time < strtotime($homework['begin_at']) || $submit_time >= strtotime($homework['end_at'])) {
		return null;
	}
	if ($homework['penalty_since'] === null || $submit_time < strtotime($homework['penalty_since'])) {
		return 1.0;
	}
	$late_hours = ($submit_time - strtotime($homework['penalty_since'])) / 3600;
	$multiplier = 1.0;
	foreach (homeworkPenaltyRules($homework) as $rule) {
		if ($rule['after_hours'] <= $late_hours) {
			$multiplier = (float)$rule['multiplier'];
		} else {
			break;
		}
	}
	return $multiplier;
}

// The scores of a homework. $problems: problem id => points; $submissions: rows with id,
// submitter, problem_id, score (0 to 100), submit_time (a timestamp) and data_version, in any
// order. Of every user and problem the submission that is worth the most counts, the earlier
// one of two that are worth the same. Returns
//   username => problem id => array(submission_id, raw_score, multiplier, score, data_version)
function homeworkComputeScores($homework, $problems, $submissions) {
	$best = array();
	usort($submissions, function($a, $b) {
		return $a['id'] - $b['id'];
	});
	foreach ($submissions as $submission) {
		if (!isset($problems[$submission['problem_id']]) || $submission['score'] === null) {
			continue;
		}
		$multiplier = homeworkMultiplier($homework, $submission['submit_time']);
		if ($multiplier === null) {
			continue;
		}
		$score = round($submission['score'] * $multiplier * $problems[$submission['problem_id']] / 100, 2);
		$current = &$best[$submission['submitter']][$submission['problem_id']];
		if (!isset($current) || $score > $current['score']) {
			$current = array(
				'submission_id' => (int)$submission['id'],
				'raw_score' => (int)$submission['score'],
				'multiplier' => $multiplier,
				'score' => $score,
				'data_version' => $submission['data_version'] === null ? null : (int)$submission['data_version']
			);
		}
		unset($current);
	}
	return $best;
}

// Returns '' when the settings of a homework make sense, or what is wrong with them.
// The times are strings like '2026-10-12 23:59:00'.
function homeworkSettingsError($settings) {
	if (!isset($settings['title']) || trim($settings['title']) === '' || mb_strlen($settings['title'], 'UTF-8') > 200) {
		return '标题不能为空，且不超过 200 个字符';
	}
	foreach (array('begin_at' => '开始时间', 'end_at' => '截止时间') as $key => $name) {
		if (!isset($settings[$key]) || !homeworkValidTime($settings[$key])) {
			return "{$name}的格式应为 2026-10-12 23:59:00";
		}
	}
	foreach (array('penalty_since' => '延期开始时间', 'claim_end_at' => '认领截止时间') as $key => $name) {
		if (isset($settings[$key]) && $settings[$key] !== null && $settings[$key] !== '' && !homeworkValidTime($settings[$key])) {
			return "{$name}的格式应为 2026-10-12 23:59:00";
		}
	}
	if (strtotime($settings['begin_at']) >= strtotime($settings['end_at'])) {
		return '截止时间必须晚于开始时间';
	}
	if (!empty($settings['penalty_since'])) {
		if (strtotime($settings['penalty_since']) < strtotime($settings['begin_at']) || strtotime($settings['penalty_since']) > strtotime($settings['end_at'])) {
			return '延期开始时间必须在开始时间和截止时间之间';
		}
	}
	if (!empty($settings['claim_end_at']) && strtotime($settings['claim_end_at']) > strtotime($settings['end_at'])) {
		return '认领截止时间不能晚于截止时间';
	}
	return '';
}
function homeworkValidTime($time) {
	if (!is_string($time) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $time)) {
		return false;
	}
	return strtotime($time) !== false;
}

// ---- queries

function homeworkNow() {
	return UOJTime::$time_now->getTimestamp();
}
function queryHomework($id) {
	return DB::selectFirst("select * from homeworks where id = ".(int)$id, MYSQLI_ASSOC);
}
function homeworkUrl($domain, $homework, $path = '') {
	return domainUrl($domain, "/homework/{$homework['id']}$path");
}
// the problems of a homework in their order, each with what is known of the problem itself
function homeworkProblems($homework) {
	return DB::selectAll("select homework_problems.*, problems.title, problems.is_hidden, problems.owner_domain_id, problems.data_version from homework_problems left join problems on problems.id = homework_problems.problem_id where homework_id = {$homework['id']} order by position, problem_id");
}
// problem id => points
function homeworkProblemPoints($homework) {
	$points = array();
	foreach (homeworkProblems($homework) as $problem) {
		$points[(int)$problem['problem_id']] = (int)$problem['score'];
	}
	return $points;
}
function homeworkParticipation($homework_id, $username) {
	return DB::selectFirst("select * from homework_participants where homework_id = ".(int)$homework_id." and username = '".DB::escape($username)."'");
}
// the users who take part, in the order of their names
function homeworkParticipants($homework, $status = 'active') {
	$names = array();
	foreach (DB::selectAll("select username from homework_participants where homework_id = {$homework['id']} and status = '$status' order by username") as $row) {
		$names[] = $row['username'];
	}
	return $names;
}
function homeworkMaintainers($homework) {
	$names = array();
	foreach (DB::selectAll("select username from homework_maintainers where homework_id = {$homework['id']} order by username") as $row) {
		$names[] = $row['username'];
	}
	return $names;
}

// What was submitted to a homework and has been judged, as homeworkComputeScores() wants it.
// The time of a submission is read the way the times of the homework are, by the clock of
// the web server, and each row says which version of the data of its problem judged it.
function homeworkJudgedSubmissions($homework, $cond = '1') {
	$rows = DB::selectAll("select submissions.id, submissions.submitter, submissions.problem_id, submissions.score, submissions.submit_time, (select problem_data_version from submission_judgements where kind = 'submission' and target_id = submissions.id and outcome = 'judged' order by id desc limit 1) as data_version from submissions where submissions.homework_id = {$homework['id']} and submissions.status = 'Judged' and submissions.score is not null and ($cond)");
	foreach ($rows as &$row) {
		$row['submit_time'] = strtotime($row['submit_time']);
	}
	return $rows;
}
// the submissions from before the end that are not judged yet: the scores can not be settled while there are any
function homeworkUnjudgedSubmissions($homework) {
	$ids = array();
	foreach (DB::selectAll("select id from submissions where homework_id = {$homework['id']} and submit_time < '".DB::escape($homework['end_at'])."' and status != 'Judged' order by id") as $row) {
		$ids[] = (int)$row['id'];
	}
	return $ids;
}

// the scores by the rules as they are now: what the official scores would be if they were settled at this moment
function homeworkLiveScores($homework) {
	return homeworkComputeScores($homework, homeworkProblemPoints($homework), homeworkJudgedSubmissions($homework));
}
// How far everybody has got, whenever they submitted: the best score of every problem, without
// any penalty. This is what keeps moving after the end, while the official scores stand.
function homeworkCorrectionScores($homework) {
	$points = homeworkProblemPoints($homework);
	$best = array();
	foreach (homeworkJudgedSubmissions($homework) as $row) {
		if (!isset($points[$row['problem_id']]) || $row['submit_time'] < strtotime($homework['begin_at'])) {
			continue;
		}
		$score = round($row['score'] * $points[$row['problem_id']] / 100, 2);
		$current = &$best[$row['submitter']][$row['problem_id']];
		if (!isset($current) || $score > $current['score']) {
			$current = array('submission_id' => (int)$row['id'], 'raw_score' => (int)$row['score'], 'score' => $score);
		}
		unset($current);
	}
	return $best;
}
// the total of one user in scores of any of the shapes above
function homeworkTotal($scores, $username) {
	$total = 0;
	if (isset($scores[$username])) {
		foreach ($scores[$username] as $row) {
			$total += $row['score'];
		}
	}
	return $total;
}

// ---- snapshots

function queryHomeworkSnapshot($id) {
	return DB::selectFirst("select * from homework_snapshots where id = ".(int)$id, MYSQLI_ASSOC);
}
function homeworkSnapshots($homework) {
	return DB::selectAll("select * from homework_snapshots where homework_id = {$homework['id']} order by version desc");
}
// the snapshot that is being made, if there is one
function homeworkActiveSnapshot($homework) {
	return DB::selectFirst("select * from homework_snapshots where homework_id = {$homework['id']} and active_slot = 1", MYSQLI_ASSOC);
}
// the scores of a snapshot: username => problem id => row. Everybody who took part is in it.
function homeworkSnapshotScores($snapshot_id) {
	$scores = array();
	foreach (DB::selectAll("select * from homework_snapshot_scores where snapshot_id = ".(int)$snapshot_id." order by username, problem_id") as $row) {
		$scores[$row['username']][(int)$row['problem_id']] = array(
			'submission_id' => $row['submission_id'] === null ? null : (int)$row['submission_id'],
			'raw_score' => (int)$row['raw_score'],
			'multiplier' => (float)$row['multiplier'],
			'score' => (float)$row['score'],
			'data_version' => $row['data_version'] === null ? null : (int)$row['data_version']
		);
	}
	return $scores;
}
// the official scores of a homework, null as long as it has not been settled
function homeworkOfficialScores($homework) {
	if ($homework['current_official_snapshot_id'] === null) {
		return null;
	}
	return homeworkSnapshotScores($homework['current_official_snapshot_id']);
}
// Where two sets of scores differ: rows of username, problem id, the score before and after.
function homeworkScoreDifferences($before, $after) {
	$differences = array();
	foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $username) {
		$problem_ids = array_unique(array_merge(
			isset($before[$username]) ? array_keys($before[$username]) : array(),
			isset($after[$username]) ? array_keys($after[$username]) : array()
		));
		foreach ($problem_ids as $problem_id) {
			$old = isset($before[$username][$problem_id]) ? (float)$before[$username][$problem_id]['score'] : 0.0;
			$new = isset($after[$username][$problem_id]) ? (float)$after[$username][$problem_id]['score'] : 0.0;
			if (abs($old - $new) > 0.004) {
				$differences[] = array('username' => (string)$username, 'problem_id' => (int)$problem_id, 'before' => $old, 'after' => $new);
			}
		}
	}
	return $differences;
}

// Everything a snapshot was computed with, so that it explains itself whatever becomes of the
// homework and of its problems later.
function homeworkRulesForSnapshot($homework, $unjudged) {
	$problems = array();
	foreach (homeworkProblems($homework) as $problem) {
		$version = DB::selectFirst("select version, sha256 from problem_data_versions where problem_id = {$problem['problem_id']} and version = ".(int)$problem['data_version']);
		$problems[] = array(
			'problem_id' => (int)$problem['problem_id'],
			'title' => $problem['title'],
			'source_problem_id' => $problem['source_problem_id'] === null ? null : (int)$problem['source_problem_id'],
			'position' => (int)$problem['position'],
			'score' => (int)$problem['score'],
			'required' => (int)$problem['required'],
			'data_version' => $version ? (int)$version['version'] : null,
			'data_sha256' => $version ? $version['sha256'] : null
		);
	}
	return array(
		'begin_at' => $homework['begin_at'],
		'penalty_since' => $homework['penalty_since'],
		'end_at' => $homework['end_at'],
		'penalty_rules' => homeworkPenaltyRules($homework),
		'scoring' => 'of every participant and problem, the submission with the highest score x multiplier x points / 100',
		'problems' => $problems,
		'unjudged_submissions' => $unjudged,
		'computed_at' => UOJTime::$time_now_str
	);
}

// Fills a snapshot with the scores by the rules as they are now: a row for every participant
// and problem, also where nothing was submitted. Runs inside the transaction that decides
// what the snapshot becomes.
function homeworkFillSnapshot($snapshot_id, $homework, $unjudged) {
	$scores = homeworkLiveScores($homework);
	$points = homeworkProblemPoints($homework);
	DB::delete("delete from homework_snapshot_scores where snapshot_id = $snapshot_id");
	foreach (homeworkParticipants($homework) as $username) {
		foreach ($points as $problem_id => $problem_points) {
			$row = isset($scores[$username][$problem_id]) ? $scores[$username][$problem_id] : array('submission_id' => null, 'raw_score' => 0, 'multiplier' => 1.0, 'score' => 0.0, 'data_version' => null);
			DB::insert("insert into homework_snapshot_scores (snapshot_id, username, problem_id, submission_id, raw_score, multiplier, score, data_version) values ($snapshot_id, '".DB::escape($username)."', $problem_id, ".($row['submission_id'] === null ? 'null' : $row['submission_id']).", {$row['raw_score']}, {$row['multiplier']}, {$row['score']}, ".($row['data_version'] === null ? 'null' : $row['data_version']).")");
		}
	}
	DB::update("update homework_snapshots set rules_json = '".DB::escape(json_encode(homeworkRulesForSnapshot($homework, $unjudged), JSON_UNESCAPED_UNICODE))."' where id = $snapshot_id");
}

// ---- changing a homework; each returns '' or why it was refused

// Stores the settings of a homework, a new one when $homework is null. $input is what the form
// posted: title, description_md, begin_at, end_at, claim_end_at, allow_withdraw, and for the
// penalty allow_late, penalty_since and the three lists of its steps.
// Returns array(id, '') or array(null, what is wrong).
function homeworkSave($domain, $homework, $input, $actor) {
	$text = function($key) use ($input) {
		return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
	};
	$settings = array(
		'title' => $text('title'),
		'begin_at' => $text('begin_at'),
		'end_at' => $text('end_at'),
		'claim_end_at' => $text('claim_end_at'),
		// a homework that can not be handed in late has no penalty period
		'penalty_since' => !empty($input['allow_late']) ? $text('penalty_since') : ''
	);
	if (!empty($input['allow_late']) && $settings['penalty_since'] === '') {
		return array(null, '允许迟交时需要填写正常截止时间');
	}
	$err = homeworkSettingsError($settings);
	if ($err !== '') {
		return array(null, $err);
	}
	$rules = array();
	if ($settings['penalty_since'] !== '') {
		list($rules, $err) = homeworkPenaltyRulesFromForm(
			isset($input['penalty_after']) ? $input['penalty_after'] : null,
			isset($input['penalty_unit']) ? $input['penalty_unit'] : null,
			isset($input['penalty_percent']) ? $input['penalty_percent'] : null
		);
		if ($err !== '') {
			return array(null, $err);
		}
	}
	$description_md = isset($input['description_md']) && is_string($input['description_md']) ? $input['description_md'] : '';
	if (strlen($description_md) > 200000) {
		return array(null, '说明太长');
	}
	$time = function($value) {
		return $value === '' ? 'null' : "'".date('Y-m-d H:i:s', strtotime($value))."'";
	};
	$set = "title = '".DB::escape($settings['title'])."', description_md = '".DB::escape($description_md)."', description = '".DB::escape(domainRenderMarkdown($description_md))."', begin_at = ".$time($settings['begin_at']).", end_at = ".$time($settings['end_at']).", penalty_since = ".$time($settings['penalty_since']).", penalty_rules = '".DB::escape(json_encode($rules))."', claim_end_at = ".$time($settings['claim_end_at']).", allow_withdraw = ".(!empty($input['allow_withdraw']) ? 1 : 0).", updated_at = now()";
	$after = array('title' => $settings['title'], 'begin_at' => $settings['begin_at'], 'penalty_since' => $settings['penalty_since'], 'end_at' => $settings['end_at'], 'penalty_rules' => $rules);
	if ($homework === null) {
		DB::insert("insert into homeworks set domain_id = {$domain['id']}, created_by = '".DB::escape($actor['username'])."', created_at = now(), $set");
		$id = DB::insert_id();
		auditLog('homework.create', 'homework', $id, null, $after + array('domain_id' => (int)$domain['id']), $actor);
		return array($id, '');
	}
	// A homework whose end is moved into the future is open again: it is settled anew when
	// the new end comes. The snapshots it has stay as they are.
	if ($homework['settle_state'] !== 'open' && strtotime($settings['end_at']) > homeworkNow()) {
		$set .= ", settle_state = 'open', settle_waiting_since = null";
	}
	DB::update("update homeworks set $set where id = {$homework['id']}");
	auditLog('homework.edit', 'homework', $homework['id'], array('title' => $homework['title'], 'begin_at' => $homework['begin_at'], 'penalty_since' => $homework['penalty_since'], 'end_at' => $homework['end_at'], 'penalty_rules' => homeworkPenaltyRules($homework)), $after, $actor);
	return array((int)$homework['id'], '');
}

// The problems of a homework are chosen while it is a draft. Once it is published they are
// what its participants were promised.
function homeworkAddProblem($homework, $problem, $score, $required, $actor) {
	if ($homework['status'] !== 'draft') {
		return '作业发布后不能再改题目。开始之前可以先撤回发布';
	}
	// a problem that may not be used is refused like one that does not exist
	if (!$problem || !can($actor, 'problem.use', $problem) || ($problem['owner_domain_id'] && $problem['owner_domain_id'] != $homework['domain_id'])) {
		return '题目不存在，或者不能用在这个域的作业里';
	}
	if ($score < 1 || $score > 10000) {
		return '分值应在 1 到 10000 之间';
	}
	$position = 1 + (int)DB::selectFirst("select ifnull(max(position), 0) from homework_problems where homework_id = {$homework['id']}", MYSQLI_NUM)[0];
	if (!DB::insert("insert into homework_problems (homework_id, problem_id, position, score, required) values ({$homework['id']}, {$problem['id']}, $position, ".(int)$score.", ".($required ? 1 : 0).")")) {
		return '这道题已经在作业里了';
	}
	auditLog('homework.add_problem', 'homework', $homework['id'], null, array('problem_id' => (int)$problem['id'], 'score' => (int)$score, 'required' => $required ? 1 : 0), $actor);
	return '';
}
function homeworkUpdateProblem($homework, $problem_id, $score, $required, $actor) {
	if ($homework['status'] !== 'draft') {
		return '作业发布后不能再改题目。开始之前可以先撤回发布';
	}
	if ($score < 1 || $score > 10000) {
		return '分值应在 1 到 10000 之间';
	}
	DB::update("update homework_problems set score = ".(int)$score.", required = ".($required ? 1 : 0)." where homework_id = {$homework['id']} and problem_id = ".(int)$problem_id);
	auditLog('homework.edit_problem', 'homework', $homework['id'], null, array('problem_id' => (int)$problem_id, 'score' => (int)$score, 'required' => $required ? 1 : 0), $actor);
	return '';
}
function homeworkRemoveProblem($homework, $problem_id, $actor) {
	if ($homework['status'] !== 'draft') {
		return '作业发布后不能再改题目。开始之前可以先撤回发布';
	}
	DB::delete("delete from homework_problems where homework_id = {$homework['id']} and problem_id = ".(int)$problem_id);
	auditLog('homework.remove_problem', 'homework', $homework['id'], array('problem_id' => (int)$problem_id), null, $actor);
	return '';
}
// moves a problem one place up in the order
function homeworkMoveProblemUp($homework, $problem_id) {
	if ($homework['status'] !== 'draft') {
		return '作业发布后不能再改题目。开始之前可以先撤回发布';
	}
	$ids = array();
	foreach (homeworkProblems($homework) as $row) {
		$ids[] = (int)$row['problem_id'];
	}
	$index = array_search((int)$problem_id, $ids, true);
	if ($index === false || $index == 0) {
		return '';
	}
	$ids[$index] = $ids[$index - 1];
	$ids[$index - 1] = (int)$problem_id;
	foreach ($ids as $position => $id) {
		DB::update("update homework_problems set position = ".($position + 1)." where homework_id = {$homework['id']} and problem_id = $id");
	}
	return '';
}

// ---- the state machines of a homework
//
// Two things about a homework take time and must happen exactly once: publishing it, which
// copies the public problems it uses into the domain, and settling it, which turns the
// submissions into official scores. Both are advanced by homeworkAdvance(), one step at a
// time, each step starting from what the database says the state is. Pages and the tick of
// the command line only call it: whoever holds the lock of the homework does the work, and
// everybody else returns at once and shows the state as it is.

// Runs $fun if nobody else is working on the homework. Returns false if somebody is.
function homeworkWithLock($homework_id, $fun) {
	$name = 'uoj_homework_' . (int)$homework_id;
	$lock = DB::selectFirst("select get_lock('$name', 0)", MYSQLI_NUM);
	if (!$lock || $lock[0] != 1) {
		return false;
	}
	try {
		$fun();
	} finally {
		DB::query("select release_lock('$name')");
	}
	return true;
}

function homeworkAdvance($homework_id) {
	return homeworkWithLock($homework_id, function() use ($homework_id) {
		// read again, now that nobody else changes it
		$homework = queryHomework($homework_id);
		if (!$homework) {
			return;
		}
		if ($homework['status'] === 'publishing') {
			homeworkAdvancePublishing($homework);
		} elseif ($homework['status'] === 'published') {
			homeworkAdvanceSettlement($homework);
		}
	});
}
// whether there may be something to do for a homework: cheap, for pages that show it
function homeworkNeedsAdvance($homework) {
	if ($homework['status'] === 'publishing') {
		return true;
	}
	if ($homework['status'] !== 'published') {
		return false;
	}
	if ($homework['settle_state'] !== 'settled') {
		return homeworkNow() >= strtotime($homework['end_at']);
	}
	return DB::selectFirst("select 1 from homework_snapshots where homework_id = {$homework['id']} and status = 'rejudging'") != null;
}
// What a page that shows a homework calls: moves it on if it is due, and returns it as it is now.
function homeworkTouch($homework) {
	if (homeworkNeedsAdvance($homework)) {
		homeworkAdvance($homework['id']);
		$homework = queryHomework($homework['id']);
	}
	return $homework;
}
// What the tick of the command line calls: every homework that may have something to do.
function homeworkAdvanceDue() {
	$now = DB::escape(UOJTime::$time_now_str);
	$advanced = 0;
	foreach (DB::selectAll("select id from homeworks where status = 'publishing' or (status = 'published' and settle_state != 'settled' and end_at <= '$now') or id in (select homework_id from homework_snapshots where status = 'rejudging')") as $row) {
		if (homeworkAdvance($row['id'])) {
			$advanced++;
		}
	}
	return $advanced;
}

// ---- publishing

function homeworkRequestPublish($homework, $actor) {
	if ($homework['status'] !== 'draft') {
		return '作业已经发布';
	}
	if (!homeworkProblems($homework)) {
		return '作业里还没有题目';
	}
	DB::update("update homeworks set status = 'publishing', publish_error = null, publish_requested_by = '".DB::escape($actor['username'])."', updated_at = now() where id = {$homework['id']} and status = 'draft'");
	auditLog('homework.publish', 'homework', $homework['id'], null, null, $actor);
	homeworkAdvance($homework['id']);
	return '';
}
// Before it begins, a homework can be taken back to change it.
function homeworkUnpublish($homework, $actor) {
	if ($homework['status'] === 'draft') {
		return '';
	}
	if ($homework['status'] === 'published' && homeworkNow() >= strtotime($homework['begin_at'])) {
		return '作业已经开始，不能撤回发布';
	}
	DB::update("update homeworks set status = 'draft', publish_error = null, updated_at = now() where id = {$homework['id']}");
	auditLog('homework.unpublish', 'homework', $homework['id'], null, null, $actor);
	return '';
}

// Whether the data of a problem can be judged with: 'ready', 'building' while a judger still
// builds a version of it, or 'none'.
function homeworkProblemDataState($problem_id) {
	if (DB::selectFirst("select 1 from problem_data_versions where problem_id = ".(int)$problem_id." and status in ('pending', 'preparing')")) {
		return 'building';
	}
	$problem = queryProblemBrief($problem_id);
	return $problem && $problem['data_version'] > 0 ? 'ready' : 'none';
}

// One step of publishing. Every public problem of the site that the homework uses is replaced
// by a copy that belongs to the domain: the judgers judge with the data a problem has now, so
// only a problem of its own keeps a homework from changing under its participants when
// somebody else changes the problem. A copy the domain has already and has not touched is
// used again. Then the homework waits until the data of all its problems is built.
//
// Running this again after it was interrupted makes no second copy: the copy that was made is
// found as the untouched copy of its source.
function homeworkAdvancePublishing($homework) {
	$fail = function($message) use ($homework) {
		DB::update("update homeworks set status = 'draft', publish_error = '".DB::escape($message)."', updated_at = now() where id = {$homework['id']} and status = 'publishing'");
	};
	$domain = queryDomain($homework['domain_id']);
	$actor = $homework['publish_requested_by'] !== null ? queryUser($homework['publish_requested_by']) : null;
	if (!$domain || !$actor || !can($actor, 'homework.manage', $homework)) {
		return $fail('发布者已经无权管理这个作业');
	}
	$problems = homeworkProblems($homework);
	if (!$problems) {
		return $fail('作业里还没有题目');
	}
	foreach ($problems as $row) {
		$problem = queryProblemBrief($row['problem_id']);
		if (!$problem) {
			return $fail("题目 #{$row['problem_id']} 不存在");
		}
		if ($problem['owner_domain_id'] == $homework['domain_id']) {
			continue;
		}
		if ($problem['owner_domain_id'] || !can($actor, 'problem.copy', $problem)) {
			return $fail("题目 #{$problem['id']} 不能用在这个域的作业里");
		}
		$copy = domainUntouchedCopy($domain, $problem);
		if ($copy) {
			$copy_id = (int)$copy['id'];
			$source_version = (int)$copy['source_data_version'];
		} else {
			list($copy_id, $err) = domainCopyProblem($problem, $domain, $actor);
			if ($err !== '') {
				return $fail($err);
			}
			$source_version = (int)queryProblemBrief($copy_id)['source_data_version'];
		}
		if (DB::selectFirst("select 1 from homework_problems where homework_id = {$homework['id']} and problem_id = $copy_id")) {
			// the copy is in the homework already, next to the problem it was copied from
			DB::delete("delete from homework_problems where homework_id = {$homework['id']} and problem_id = {$problem['id']}");
		} else {
			DB::update("update homework_problems set problem_id = $copy_id, source_problem_id = {$problem['id']}, source_data_version = $source_version where homework_id = {$homework['id']} and problem_id = {$problem['id']}");
		}
	}
	foreach (homeworkProblems($homework) as $row) {
		$state = homeworkProblemDataState($row['problem_id']);
		if ($state === 'building') {
			// a judger is building it: there is nothing to do but to come back
			return;
		}
		if ($state !== 'ready') {
			return $fail("题目 #{$row['problem_id']} 还没有可以评测的数据");
		}
	}
	DB::update("update homeworks set status = 'published', publish_error = null, updated_at = now() where id = {$homework['id']} and status = 'publishing'");
	if (DB::affected_rows() == 1) {
		auditLog('homework.published', 'homework', $homework['id'], null, array('problems' => array_map(function($row) {
			return (int)$row['problem_id'];
		}, homeworkProblems($homework))), $actor);
		$link = '<a href="'.HTML::escape(homeworkUrl($domain, $homework)).'">'.HTML::escape($homework['title']).'</a>';
		foreach (DB::selectAll("select username from domain_members where domain_id = {$domain['id']} and role = 'member'") as $member) {
			sendSystemMsg($member['username'], '新作业', '<p>'.HTML::escape($domain['name']).' 发布了新作业 '.$link.'，'.substr($homework['begin_at'], 0, 16).' 开始，'.substr($homework['end_at'], 0, 16).' 截止。认领后才算参加。</p>');
		}
	}
}

// ---- settling

// how long the settlement waits for submissions that are not judged before it goes on without them
function homeworkSettleGrace() {
	return (int)UOJConfig::$data['homework']['settle-grace'];
}

// One step of the settlement of a published homework. Whatever it decides, it decides in a
// transaction that holds the row of the homework, on the state it reads there.
//
// - end_at has come and the homework is not settled: if submissions from before end_at are
//   still being judged, it waits (waiting_judgements). The judgers take back what a judger
//   lost, so that ends by itself; if it has not after the grace time, for want of a judger,
//   the homework is settled with what is judged, and the snapshot says which submissions
//   were left out. Otherwise the first official snapshot is made.
// - a new settlement was asked for and its submissions are judged again: once they are, or
//   after the grace time, its scores are computed, and it waits to be confirmed.
function homeworkAdvanceSettlement($homework) {
	$now = homeworkNow();
	$grace = homeworkSettleGrace();
	DB::transaction(function() use ($homework, $now, $grace) {
		$homework = DB::selectFirst("select * from homeworks where id = {$homework['id']} for update", MYSQLI_ASSOC);
		if (!$homework || $homework['status'] !== 'published') {
			return false;
		}
		if ($homework['settle_state'] !== 'settled') {
			if ($now < strtotime($homework['end_at'])) {
				return false;
			}
			$unjudged = homeworkUnjudgedSubmissions($homework);
			if ($unjudged) {
				if ($homework['settle_state'] === 'open') {
					DB::update("update homeworks set settle_state = 'waiting_judgements', settle_waiting_since = '".DB::escape(UOJTime::$time_now_str)."' where id = {$homework['id']}");
					return true;
				}
				if ($now < strtotime($homework['settle_waiting_since']) + $grace) {
					return false;
				}
			}
			$version = 1 + (int)DB::selectFirst("select ifnull(max(version), 0) from homework_snapshots where homework_id = {$homework['id']}", MYSQLI_NUM)[0];
			if (!DB::insert("insert into homework_snapshots (homework_id, version, status, active_slot, rules_json, reason, created_by, created_at, confirmed_at) values ({$homework['id']}, $version, 'official', null, '', '作业截止', '', '".DB::escape(UOJTime::$time_now_str)."', '".DB::escape(UOJTime::$time_now_str)."')")) {
				return false;
			}
			$snapshot_id = DB::insert_id();
			homeworkFillSnapshot($snapshot_id, $homework, $unjudged);
			DB::update("update homeworks set settle_state = 'settled', settle_waiting_since = null, current_official_snapshot_id = $snapshot_id where id = {$homework['id']}");
			auditLog('homework.settle', 'homework', $homework['id'], null, array('snapshot_id' => $snapshot_id, 'version' => $version, 'unjudged_submissions' => $unjudged), false);
			return true;
		}
		$snapshot = DB::selectFirst("select * from homework_snapshots where homework_id = {$homework['id']} and active_slot = 1 for update", MYSQLI_ASSOC);
		if ($snapshot && $snapshot['status'] === 'rejudging') {
			$unjudged = homeworkUnjudgedSubmissions($homework);
			if ($unjudged && $now < strtotime($snapshot['created_at']) + $grace) {
				return false;
			}
			homeworkFillSnapshot($snapshot['id'], $homework, $unjudged);
			DB::update("update homework_snapshots set status = 'candidate' where id = {$snapshot['id']} and status = 'rejudging'");
			return true;
		}
		return false;
	});
}

// Judges the submissions of a homework again, all of them or the ones to one problem, and
// nothing else that was ever submitted to the problem.
function homeworkRejudgeSubmissions($homework, $problem_id = null) {
	$cond = "homework_id = {$homework['id']}".($problem_id === null ? '' : ' and problem_id = '.(int)$problem_id);
	DB::update("update submissions set judge_time = NULL, result = '', score = NULL, status = 'Waiting Rejudge' where $cond");
	return DB::affected_rows();
}

// Asks for the scores of a homework to be made again, and says why. Before the end that is a
// rejudge and nothing more: the scores are not settled yet. After the end a new snapshot is
// started: once the submissions are judged again it holds the scores as they would be now,
// and nothing counts until somebody who manages the homework has seen them and confirms.
// $problem_id: the problem whose submissions are judged again, 0 for all, null for none.
function homeworkStartResettle($homework, $actor, $reason, $problem_id) {
	$reason = trim($reason);
	if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
		return '请填写原因（不超过 500 个字符）';
	}
	if ($homework['status'] !== 'published') {
		return '作业还没有发布';
	}
	if ($problem_id !== null && $problem_id !== 0 && !isset(homeworkProblemPoints($homework)[(int)$problem_id])) {
		return '这道题不在作业里';
	}
	if ($homework['settle_state'] !== 'settled') {
		if ($problem_id === null) {
			return '作业还没有结算，没有可以重新结算的成绩';
		}
		$count = homeworkRejudgeSubmissions($homework, $problem_id === 0 ? null : $problem_id);
		auditLog('homework.rejudge', 'homework', $homework['id'], null, array('reason' => $reason, 'problem_id' => $problem_id, 'submissions' => $count), $actor);
		return '';
	}
	$esc_reason = DB::escape($reason);
	$esc_actor = DB::escape($actor['username']);
	$snapshot_id = DB::transaction(function() use ($homework, $esc_reason, $esc_actor) {
		DB::selectFirst("select id from homeworks where id = {$homework['id']} for update");
		$version = 1 + (int)DB::selectFirst("select ifnull(max(version), 0) from homework_snapshots where homework_id = {$homework['id']}", MYSQLI_NUM)[0];
		// the unique key on active_slot lets one snapshot at a time be in the making
		if (!DB::insert("insert into homework_snapshots (homework_id, version, status, active_slot, rules_json, reason, created_by, created_at) values ({$homework['id']}, $version, 'rejudging', 1, '', '$esc_reason', '$esc_actor', '".DB::escape(UOJTime::$time_now_str)."')")) {
			return false;
		}
		return DB::insert_id();
	});
	if (!$snapshot_id) {
		return '已经有一次重新结算在进行，请先确认或放弃它';
	}
	$count = $problem_id === null ? 0 : homeworkRejudgeSubmissions($homework, $problem_id === 0 ? null : $problem_id);
	auditLog('homework.resettle', 'homework', $homework['id'], null, array('snapshot_id' => (int)$snapshot_id, 'reason' => $reason, 'problem_id' => $problem_id, 'submissions' => $count), $actor);
	homeworkAdvance($homework['id']);
	return '';
}

// Makes the scores of a candidate snapshot the official ones, or throws the snapshot away.
function homeworkDecideSnapshot($homework, $snapshot_id, $confirm, $actor) {
	$snapshot_id = (int)$snapshot_id;
	$before = $homework['current_official_snapshot_id'];
	$done = DB::transaction(function() use ($homework, $snapshot_id, $confirm) {
		DB::selectFirst("select id from homeworks where id = {$homework['id']} for update");
		$snapshot = DB::selectFirst("select * from homework_snapshots where id = $snapshot_id and homework_id = {$homework['id']} and active_slot = 1 for update");
		if (!$snapshot) {
			return false;
		}
		if (!$confirm) {
			return DB::update("update homework_snapshots set status = 'discarded', active_slot = null where id = $snapshot_id");
		}
		// only scores that somebody could look at are confirmed
		if ($snapshot['status'] !== 'candidate') {
			return false;
		}
		DB::update("update homework_snapshots set status = 'official', active_slot = null, confirmed_at = '".DB::escape(UOJTime::$time_now_str)."' where id = $snapshot_id");
		return DB::update("update homeworks set current_official_snapshot_id = $snapshot_id where id = {$homework['id']}");
	});
	if (!$done) {
		return $confirm ? '这份成绩还不能确认：重测还没有完成，或者它已经被处理过了' : '这份成绩已经被处理过了';
	}
	auditLog($confirm ? 'homework.confirm_snapshot' : 'homework.discard_snapshot', 'homework', $homework['id'], array('official_snapshot_id' => $before === null ? null : (int)$before), array('snapshot_id' => $snapshot_id), $actor);
	if ($confirm) {
		$snapshot = queryHomeworkSnapshot($snapshot_id);
		$domain = queryDomain($homework['domain_id']);
		$link = '<a href="'.HTML::escape(homeworkUrl($domain, $homework)).'">'.HTML::escape($homework['title']).'</a>';
		foreach (homeworkParticipants($homework) as $username) {
			sendSystemMsg($username, '作业成绩已重新结算', '<p>作业 '.$link.' 的正式成绩已重新结算。原因：'.HTML::escape($snapshot['reason']).'</p>');
		}
	}
	return '';
}

// ---- taking part

// A member of the domain claims a homework to take part in it.
function homeworkClaim($homework, $user) {
	if (!can($user, 'homework.claim', $homework)) {
		return '现在不能认领这个作业';
	}
	DB::insert("insert into homework_participants (homework_id, username, status, claimed_at) values ({$homework['id']}, '".DB::escape($user['username'])."', 'active', '".DB::escape(UOJTime::$time_now_str)."') on duplicate key update status = 'active'");
	return '';
}
// Before the homework begins, whoever claimed it may step back, unless the homework says otherwise.
function homeworkWithdraw($homework, $user) {
	$participation = homeworkParticipation($homework['id'], $user['username']);
	if (!$participation || $participation['status'] !== 'active') {
		return '你没有认领这个作业';
	}
	if (!$homework['allow_withdraw'] || homeworkNow() >= strtotime($homework['begin_at'])) {
		return '这个作业不能退出。如有需要请联系老师';
	}
	DB::update("update homework_participants set status = 'withdrawn' where homework_id = {$homework['id']} and username = '".DB::escape($user['username'])."'");
	return '';
}
// The people who manage a homework put a member of the domain into it, or take somebody out.
function homeworkSetParticipant($homework, $target, $active, $actor) {
	$esc_target = DB::escape($target['username']);
	if ($active) {
		$domain = queryDomain($homework['domain_id']);
		if (domainRoleOf($target['username'], $domain) === null) {
			return "{$target['username']} 不是这个域的成员";
		}
		DB::insert("insert into homework_participants (homework_id, username, status, claimed_at, added_by) values ({$homework['id']}, '$esc_target', 'active', '".DB::escape(UOJTime::$time_now_str)."', '".DB::escape($actor['username'])."') on duplicate key update status = 'active', added_by = '".DB::escape($actor['username'])."'");
	} else {
		DB::update("update homework_participants set status = 'withdrawn' where homework_id = {$homework['id']} and username = '$esc_target'");
	}
	auditLog($active ? 'homework.add_participant' : 'homework.remove_participant', 'homework', $homework['id'], null, array('username' => $target['username']), $actor);
	return '';
}
// the students of the domain who have not claimed a homework
function homeworkUnclaimedMembers($homework) {
	$names = array();
	foreach (DB::selectAll("select username from domain_members where domain_id = {$homework['domain_id']} and role = 'member' and username not in (select username from homework_participants where homework_id = {$homework['id']} and status = 'active') order by username") as $row) {
		$names[] = $row['username'];
	}
	return $names;
}
// Students forget to claim. This puts every student of the domain who has not into the homework.
function homeworkAddAllUnclaimed($homework, $actor) {
	$names = homeworkUnclaimedMembers($homework);
	foreach ($names as $username) {
		DB::insert("insert into homework_participants (homework_id, username, status, claimed_at, added_by) values ({$homework['id']}, '".DB::escape($username)."', 'active', '".DB::escape(UOJTime::$time_now_str)."', '".DB::escape($actor['username'])."') on duplicate key update status = 'active', added_by = '".DB::escape($actor['username'])."'");
	}
	auditLog('homework.add_all_participants', 'homework', $homework['id'], null, array('added' => count($names)), $actor);
	return count($names);
}

// Members of the domain who look after this homework without teaching in the whole domain.
function homeworkSetMaintainer($homework, $target, $is_maintainer, $actor) {
	$esc_target = DB::escape($target['username']);
	if ($is_maintainer) {
		$domain = queryDomain($homework['domain_id']);
		if (domainRoleOf($target['username'], $domain) === null) {
			return "{$target['username']} 不是这个域的成员";
		}
		DB::insert("insert ignore into homework_maintainers (homework_id, username) values ({$homework['id']}, '$esc_target')");
	} else {
		DB::delete("delete from homework_maintainers where homework_id = {$homework['id']} and username = '$esc_target'");
	}
	auditLog($is_maintainer ? 'homework.add_maintainer' : 'homework.remove_maintainer', 'homework', $homework['id'], null, array('username' => $target['username']), $actor);
	return '';
}

// A copy of a homework for the next class or the next term: its text, problems, points and
// penalty rules, as a draft. Who took part, what they submitted and the scores stay behind.
function homeworkClone($homework, $actor) {
	DB::insert("insert into homeworks (domain_id, title, description_md, description, status, begin_at, penalty_since, end_at, penalty_rules, claim_end_at, allow_withdraw, created_by, created_at, updated_at) select domain_id, concat(title, '（副本）'), description_md, description, 'draft', begin_at, penalty_since, end_at, penalty_rules, claim_end_at, allow_withdraw, '".DB::escape($actor['username'])."', now(), now() from homeworks where id = {$homework['id']}");
	$id = DB::insert_id();
	DB::insert("insert into homework_problems (homework_id, problem_id, source_problem_id, source_data_version, position, score, required) select $id, problem_id, source_problem_id, source_data_version, position, score, required from homework_problems where homework_id = {$homework['id']}");
	auditLog('homework.clone', 'homework', $id, null, array('from' => (int)$homework['id']), $actor);
	return $id;
}

// The problems of a homework whose data has changed since scores were made with it: for each,
// the version the data has now and how many of the scores that count were judged with another.
// $scores is what counts: the official scores, or the live ones before the homework is settled.
function homeworkDataDrift($homework, $scores) {
	$drift = array();
	foreach (homeworkProblems($homework) as $problem) {
		$stale = 0;
		$versions = array();
		foreach ($scores as $rows) {
			$row = isset($rows[$problem['problem_id']]) ? $rows[$problem['problem_id']] : null;
			if ($row && $row['submission_id'] !== null && $row['data_version'] !== null && $row['data_version'] != $problem['data_version']) {
				$stale++;
				$versions[$row['data_version']] = true;
			}
		}
		if ($stale > 0) {
			ksort($versions);
			$drift[(int)$problem['problem_id']] = array('title' => $problem['title'], 'current' => (int)$problem['data_version'], 'judged_with' => array_keys($versions), 'scores' => $stale);
		}
	}
	return $drift;
}
