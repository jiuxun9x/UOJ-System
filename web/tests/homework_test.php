<?php

require_once __DIR__ . '/../app/libs/uoj-homework-lib.php';

// a homework from Monday to the next Monday, with two days of grace before that
$homework = array(
	'status' => 'published',
	'begin_at' => '2026-10-05 08:00:00',
	'penalty_since' => '2026-10-10 00:00:00',
	'end_at' => '2026-10-12 00:00:00',
	'penalty_rules' => json_encode(array(array('after_hours' => 0, 'multiplier' => 0.8), array('after_hours' => 24, 'multiplier' => 0.6)))
);
$at = function($time) {
	return strtotime($time);
};

// ---- what a homework is at a moment
check_same('upcoming', homeworkPhase($homework, $at('2026-10-05 07:59:59')), 'before it begins');
check_same('running', homeworkPhase($homework, $at('2026-10-05 08:00:00')), 'the moment it begins');
check_same('running', homeworkPhase($homework, $at('2026-10-09 23:59:59')), 'the last second on time');
check_same('penalty', homeworkPhase($homework, $at('2026-10-10 00:00:00')), 'the moment the penalty starts');
check_same('penalty', homeworkPhase($homework, $at('2026-10-11 23:59:59')), 'the last second');
check_same('ended', homeworkPhase($homework, $at('2026-10-12 00:00:00')), 'the moment it ends');
check_same('running', homeworkPhase(array('penalty_since' => null) + $homework, $at('2026-10-11 00:00:00')), 'a homework without a penalty period');
check_same('draft', homeworkPhase(array('status' => 'draft') + $homework, $at('2026-10-11 00:00:00')), 'a draft is a draft whatever the time');
check_same('publishing', homeworkPhase(array('status' => 'publishing') + $homework, $at('2026-10-11 00:00:00')), 'a homework that is being published');

// ---- what a submission is worth by the time it was made
check_same(null, homeworkMultiplier($homework, $at('2026-10-05 07:59:59')), 'before the homework begins');
check_same(1.0, homeworkMultiplier($homework, $at('2026-10-05 08:00:00')), 'on time');
check_same(1.0, homeworkMultiplier($homework, $at('2026-10-09 23:59:59')), 'the last second on time');
check_same(0.8, homeworkMultiplier($homework, $at('2026-10-10 00:00:00')), 'the first second late');
check_same(0.8, homeworkMultiplier($homework, $at('2026-10-10 23:59:59')), 'not yet a day late');
check_same(0.6, homeworkMultiplier($homework, $at('2026-10-11 00:00:00')), 'a day late');
check_same(null, homeworkMultiplier($homework, $at('2026-10-12 00:00:00')), 'after the end');
check_same(1.0, homeworkMultiplier(array('penalty_since' => null) + $homework, $at('2026-10-11 12:00:00')), 'without a penalty period');
// rules that start later leave the time before them unpunished
$grace = array('penalty_rules' => json_encode(array(array('after_hours' => 12, 'multiplier' => 0.5)))) + $homework;
check_same(1.0, homeworkMultiplier($grace, $at('2026-10-10 11:59:59')), 'before the first step of the rules');
check_same(0.5, homeworkMultiplier($grace, $at('2026-10-10 12:00:00')), 'at the first step of the rules');
check_same(1.0, homeworkMultiplier(array('penalty_rules' => '') + $homework, $at('2026-10-11 00:00:00')), 'a penalty period without rules');

// ---- the steps of the penalty, as the form of a homework posts them
$from_form = function($rows) {
	return homeworkPenaltyRulesFromForm(array_column($rows, 0), array_column($rows, 1), array_column($rows, 2));
};
check_same(array(array(array('after_hours' => 0.0, 'multiplier' => 0.8), array('after_hours' => 24.0, 'multiplier' => 0.6)), ''), $from_form(array(array('0', 'hour', '80'), array('1', 'day', '60'))), 'two steps, in hours and in days');
check_same(array(array(array('after_hours' => 1.5, 'multiplier' => 0.755), array('after_hours' => 48.0, 'multiplier' => 0.0)), ''), $from_form(array(array('2', 'day', '0'), array('', 'hour', ''), array(' 1.5 ', 'hour', '75.5'))), 'the steps are put in order, and empty rows are skipped');
check_same(array(array(), ''), $from_form(array()), 'no steps');
check_same(array(array(), ''), homeworkPenaltyRulesFromForm(null, null, null), 'a form without the fields of the steps');
check_same(array(array(array('after_hours' => 0.0, 'multiplier' => 1.0)), ''), $from_form(array(array('0', 'hour', '100'))), 'a step that takes nothing away');
$wrong = array(
	'a percentage above 100' => array(array('0', 'hour', '120')),
	'a negative percentage' => array(array('0', 'hour', '-5')),
	'a percentage that is no number' => array(array('0', 'hour', 'most')),
	'a time that is no number' => array(array('soon', 'hour', '80')),
	'a negative time' => array(array('-1', 'day', '80')),
	'a time without a percentage' => array(array('24', 'hour', '')),
	'a percentage without a time' => array(array('', 'hour', '80')),
	'two steps from the same moment' => array(array('24', 'hour', '80'), array('1', 'day', '60')),
	'a step ten years away and more' => array(array('99999', 'day', '80')),
	'too many steps' => array_map(function($n) {
		return array((string)$n, 'hour', '50');
	}, range(0, 20)),
);
foreach ($wrong as $what => $rows) {
	$parsed = $from_form($rows);
	check_same(true, $parsed[0] === null && $parsed[1] !== '', "$what is refused");
}
// what was stored comes back to the form as it was entered
check_same(
	array(array('after' => '0', 'unit' => 'hour', 'percent' => '80'), array('after' => '1', 'unit' => 'day', 'percent' => '60'), array('after' => '36', 'unit' => 'hour', 'percent' => '12.5')),
	homeworkPenaltyRuleRows(array(array('after_hours' => 0, 'multiplier' => 0.8), array('after_hours' => 24, 'multiplier' => 0.6), array('after_hours' => 36, 'multiplier' => 0.125))),
	'the steps as the form shows them'
);
// and is told to the students in words
check_same(
	array('2026-10-10 00:00 前提交：按 100% 计分', '迟交不超过 1 天：按 80% 计分', '迟交超过 1 天：按 60% 计分', '2026-10-12 00:00 后提交：不计入正式成绩'),
	homeworkDescribePenalty($homework),
	'the penalty in words'
);
check_same(
	array('2026-10-10 00:00 前提交：按 100% 计分', '迟交不超过 12 小时：按 100% 计分', '迟交超过 12 小时：按 50% 计分', '2026-10-12 00:00 后提交：不计入正式成绩'),
	homeworkDescribePenalty($grace),
	'rules that start later, in words'
);
check_same(
	array('2026-10-12 00:00 前提交：按 100% 计分', '之后提交：不计入正式成绩'),
	homeworkDescribePenalty(array('penalty_since' => null) + $homework),
	'a homework that can not be handed in late, in words'
);
check_same(
	array('2026-10-10 00:00 前提交：按 100% 计分', '迟交：按 100% 计分', '2026-10-12 00:00 后提交：不计入正式成绩'),
	homeworkDescribePenalty(array('penalty_rules' => '[]') + $homework),
	'a penalty period without steps, in words'
);
$three = array('penalty_rules' => json_encode(array(array('after_hours' => 0, 'multiplier' => 0.9), array('after_hours' => 6, 'multiplier' => 0.7), array('after_hours' => 24, 'multiplier' => 0.5)))) + $homework;
check_same(
	array('2026-10-10 00:00 前提交：按 100% 计分', '迟交不超过 6 小时：按 90% 计分', '迟交 6 小时 到 1 天：按 70% 计分', '迟交超过 1 天：按 50% 计分', '2026-10-12 00:00 后提交：不计入正式成绩'),
	homeworkDescribePenalty($three),
	'three steps, in words'
);
check_same(0.7, homeworkMultiplier($three, $at('2026-10-10 06:00:00')), 'the second of three steps');

// ---- the scores
$problems = array(1 => 100, 2 => 50);
$row = function($id, $submitter, $problem_id, $score, $time, $data_version = 1) use ($at) {
	return array('id' => $id, 'submitter' => $submitter, 'problem_id' => $problem_id, 'score' => $score, 'submit_time' => $at($time), 'data_version' => $data_version);
};
$scores = homeworkComputeScores($homework, $problems, array(
	// alice: 60 on time, then 100 late for 80: the late one is worth more
	$row(11, 'alice', 1, 60, '2026-10-06 10:00:00'),
	$row(12, 'alice', 1, 100, '2026-10-10 10:00:00', 2),
	// and 100 two days late is worth 60, less than what she has
	$row(13, 'alice', 1, 100, '2026-10-11 10:00:00'),
	// problem 2 is worth half
	$row(14, 'alice', 2, 100, '2026-10-06 10:00:00'),
	// bob: 100 on time, and a worse submission afterwards does not take it away
	$row(21, 'bob', 1, 100, '2026-10-06 10:00:00'),
	$row(22, 'bob', 1, 0, '2026-10-07 10:00:00'),
	// of two that are worth the same, the earlier
	$row(24, 'bob', 2, 80, '2026-10-08 10:00:00'),
	$row(23, 'bob', 2, 80, '2026-10-07 10:00:00'),
	// what does not count: after the end, before the begin, not judged, another problem
	$row(31, 'carol', 1, 100, '2026-10-12 00:00:00'),
	$row(32, 'carol', 1, 100, '2026-10-05 07:00:00'),
	$row(33, 'carol', 1, null, '2026-10-06 10:00:00'),
	$row(34, 'carol', 9, 100, '2026-10-06 10:00:00'),
	// a score of zero is a score
	$row(41, 'dave', 1, 0, '2026-10-06 10:00:00'),
));
check_same(array('submission_id' => 12, 'raw_score' => 100, 'multiplier' => 0.8, 'score' => 80.0, 'data_version' => 2), $scores['alice'][1], 'a late submission that is worth more');
check_same(array('submission_id' => 14, 'raw_score' => 100, 'multiplier' => 1.0, 'score' => 50.0, 'data_version' => 1), $scores['alice'][2], 'a problem that is worth half');
check_same(21, $scores['bob'][1]['submission_id'], 'the best submission stays');
check_same(array(23, 40.0), array($scores['bob'][2]['submission_id'], $scores['bob'][2]['score']), 'the earlier of two that are worth the same');
check_same(false, isset($scores['carol']), 'what does not count gives no score');
check_same(array('submission_id' => 41, 'raw_score' => 0, 'multiplier' => 1.0, 'score' => 0.0, 'data_version' => 1), $scores['dave'][1], 'a score of zero');
check_same(array(), homeworkComputeScores($homework, $problems, array()), 'no submissions');

// ---- the settings
$settings = array('title' => '第 3 次作业：链表', 'begin_at' => '2026-10-05 08:00:00', 'penalty_since' => '2026-10-10 00:00:00', 'end_at' => '2026-10-12 00:00:00', 'claim_end_at' => '');
check_same('', homeworkSettingsError($settings), 'the settings of a homework');
check_same('', homeworkSettingsError(array('penalty_since' => '', 'begin_at' => '2026-10-05 08:00') + $settings), 'without a penalty period, and without seconds');
check_same('', homeworkSettingsError(array('penalty_since' => '2026-10-12 00:00:00') + $settings), 'a penalty period of no length');
$wrong = array(
	'no title' => array('title' => ' '),
	'a begin that is no time' => array('begin_at' => 'next monday'),
	'an end that is no time' => array('end_at' => '2026-13-45 00:00:00'),
	'an end before the begin' => array('end_at' => '2026-10-05 07:00:00'),
	'an end at the begin' => array('end_at' => '2026-10-05 08:00:00'),
	'a penalty before the begin' => array('penalty_since' => '2026-10-01 00:00:00'),
	'a penalty after the end' => array('penalty_since' => '2026-10-13 00:00:00'),
	'claiming after the end' => array('claim_end_at' => '2026-10-13 00:00:00'),
	'a penalty that is no time' => array('penalty_since' => 'later'),
);
foreach ($wrong as $what => $changed) {
	check_same(true, homeworkSettingsError($changed + $settings) !== '', "$what is refused");
}

// ---- trainings: what somebody has done of a list of problems
require_once __DIR__ . '/../app/libs/uoj-training-lib.php';

$training_problems = array(
	array('problem_id' => '11', 'required' => '1'),
	array('problem_id' => '12', 'required' => '1'),
	array('problem_id' => '13', 'required' => '0'),
);
$progress = trainingProgress($training_problems, array());
check_same(array(3, 0, 0, 2, 0, false), array($progress['total'], $progress['solved'], $progress['tried'], $progress['required'], $progress['required_solved'], $progress['done']), 'nothing done');
$progress = trainingProgress($training_problems, array(11 => 100, 12 => 60));
check_same(array(1, 2, 1, false), array($progress['solved'], $progress['tried'], $progress['required_solved'], $progress['done']), 'one solved and one tried');
$progress = trainingProgress($training_problems, array(11 => 100, 12 => 100));
check_same(array(2, true), array($progress['solved'], $progress['done']), 'done without the problem that does not have to be solved');
$progress = trainingProgress($training_problems, array(11 => 100, 13 => 100));
check_same(false, $progress['done'], 'the problem that does not have to be solved does not stand in for one that has to');
$optional = array(array('problem_id' => '11', 'required' => '0'), array('problem_id' => '12', 'required' => '0'));
check_same(false, trainingProgress($optional, array(11 => 100))['done'], 'when nothing has to be solved, everything has to');
check_same(true, trainingProgress($optional, array(11 => 100, 12 => 100))['done'], 'all of a training nothing of which has to be solved');
check_same(false, trainingProgress(array(), array())['done'], 'a training without problems is never done');

check_same('', trainingSettingsError(array('title' => '第一章 线性表', 'description_md' => '', 'status' => 'published')), 'the settings of a training');
foreach (array('no title' => array('title' => ' '), 'a title that is too long' => array('title' => str_repeat('长', 101)), 'an unknown status' => array('status' => 'secret')) as $what => $changed) {
	check_same(true, trainingSettingsError($changed + array('title' => 'x', 'description_md' => '', 'status' => 'draft')) !== '', "$what is refused");
}

// ---- watching over the judgers and the queue: what counts as wrong
require_once __DIR__ . '/../app/libs/uoj-monitor-lib.php';

$judger = function($name, $silent_seconds, $enabled = true) {
	return array('name' => $name, 'enabled' => $enabled, 'silent_seconds' => $silent_seconds);
};
$calm = array('waiting' => 0, 'oldest_wait_seconds' => null);
$kinds = function($problems) {
	$kinds = array();
	foreach ($problems as $problem) {
		$kinds[] = $problem['kind'] . ($problem['subject'] !== '' ? ':' . $problem['subject'] : '');
	}
	return $kinds;
};
check_same(array(), $kinds(monitorFindProblems(array($judger('a', 3), $judger('b', 120)), $calm, 120, 600)), 'judgers that answer in time');
check_same(array('judger_silent:b'), $kinds(monitorFindProblems(array($judger('a', 3), $judger('b', 121)), $calm, 120, 600)), 'one judger is gone');
check_same(array('no_judger'), $kinds(monitorFindProblems(array($judger('a', 500), $judger('b', 121)), $calm, 120, 600)), 'all of them are gone: one alert, not one each');
check_same(array('no_judger'), $kinds(monitorFindProblems(array(), $calm, 120, 600)), 'there is no judger at all');
check_same(array(), $kinds(monitorFindProblems(array($judger('a', 3), $judger('b', 9999, false)), $calm, 120, 600)), 'a judger that was switched off is not missed');
check_same(array('no_judger'), $kinds(monitorFindProblems(array($judger('a', 3, false)), $calm, 120, 600)), 'but it does not judge either');
check_same(array(), $kinds(monitorFindProblems(array($judger('a', 3), $judger('new', null)), $calm, 120, 600)), 'a judger that never connected is still being set up');
check_same(array('no_judger'), $kinds(monitorFindProblems(array($judger('new', null)), $calm, 120, 600)), 'which does not make it one that judges');
check_same(array(), $kinds(monitorFindProblems(array($judger('a', 3)), array('waiting' => 40, 'oldest_wait_seconds' => 600), 120, 600)), 'a queue that moves');
check_same(array('queue_stuck'), $kinds(monitorFindProblems(array($judger('a', 3)), array('waiting' => 40, 'oldest_wait_seconds' => 601), 120, 600)), 'a submission that waits too long');
check_same(array('no_judger', 'queue_stuck'), $kinds(monitorFindProblems(array($judger('a', 900)), array('waiting' => 1, 'oldest_wait_seconds' => 900), 120, 600)), 'both');
check_same(array('59 秒', '2 分钟', '119 分钟', '2 小时', '2 天'), array(monitorDuration(59), monitorDuration(120), monitorDuration(7199), monitorDuration(7200), monitorDuration(172800)), 'durations for people');

// ---- backups: which are kept, when one is due, and what is wrong with them
require_once __DIR__ . '/../app/libs/uoj-backup-lib.php';

foreach (array('uoj-20261004-030000', 'uoj-20270101-235959') as $name) {
	check_same(true, validateBackupName($name), "$name is the name of a backup");
}
foreach (array('', 'uoj-2026-10-04', 'uoj-20261004-030000/', '../uoj-20261004-030000', "uoj-20261004-030000\n", 'uoj-20261004-03000', array('uoj-20261004-030000')) as $bad) {
	check_same(false, validateBackupName($bad), 'refused as the name of a backup: ' . json_encode($bad));
}
$at = function($time) {
	return strtotime($time);
};
check_same($at('2026-10-04 03:00:00'), backupTimeOf('uoj-20261004-030000'), 'the name of a backup says when it was made');
$kept = array('uoj-20261001-030000', 'uoj-20261002-030000', 'uoj-20261003-030000', 'uoj-20261004-030000');
check_same(array(), backupNamesToPrune($kept, 7, $at('2026-10-04 03:05:00')), 'backups of the last days are kept');
check_same(array('uoj-20261001-030000', 'uoj-20261002-030000'), backupNamesToPrune($kept, 2, $at('2026-10-04 03:05:00')), 'the ones that are older go');
check_same(array('uoj-20261001-030000', 'uoj-20261002-030000', 'uoj-20261003-030000'), backupNamesToPrune($kept, 1, $at('2026-12-01 00:00:00')), 'the newest one stays however old it is');
check_same(array(), backupNamesToPrune(array(), 7, $at('2026-10-04 03:05:00')), 'nothing to throw away where there is nothing');

$now = $at('2026-10-04 03:00:30');
check_same(true, backupIsDue(true, 3, $now, null), 'the hour has come and there never was a backup');
check_same(true, backupIsDue(true, 3, $now, $at('2026-10-03 03:00:10')), 'the one before was yesterday');
check_same(false, backupIsDue(true, 3, $now, $at('2026-10-04 03:00:05')), 'the one of today was started');
check_same(false, backupIsDue(true, 3, $at('2026-10-04 02:59:59'), $at('2026-10-03 03:00:10')), 'not before the hour');
check_same(true, backupIsDue(true, 3, $at('2026-10-04 17:20:00'), $at('2026-10-03 03:00:10')), 'the site was down at the hour: later the same day');
check_same(false, backupIsDue(false, 3, $now, null), 'backups are switched off');

$problem_kinds = function($problems) {
	$kinds = array();
	foreach ($problems as $problem) {
		$kinds[] = $problem['kind'];
	}
	return $kinds;
};
$now = $at('2026-10-10 12:00:00');
$old_site = $at('2026-09-01 00:00:00');
$ok = array('status' => 'ok', 'message' => '');
$failed = array('status' => 'failed', 'message' => '磁盘已满');
check_same(array(), $problem_kinds(backupFindProblems(true, $ok, $at('2026-10-10 03:00:00'), $old_site, $now)), 'a backup this morning');
check_same(array('backup_failed'), $problem_kinds(backupFindProblems(true, $failed, $at('2026-10-09 03:00:00'), $old_site, $now)), 'the newest backup failed');
check_same(true, strpos(backupFindProblems(true, $failed, null, $old_site, $now)[0]['message'], '磁盘已满') !== false, 'and the alert says why');
check_same(array('backup_overdue'), $problem_kinds(backupFindProblems(true, $ok, $at('2026-10-08 03:00:00'), $old_site, $now)), 'no backup for more than a day and a half');
check_same(array('backup_overdue'), $problem_kinds(backupFindProblems(true, null, null, $old_site, $now)), 'never a backup on a site that is not new');
check_same(array(), $problem_kinds(backupFindProblems(true, null, null, $at('2026-10-10 09:00:00'), $now)), 'a site that was set up this morning');
check_same(array(), $problem_kinds(backupFindProblems(false, $ok, $at('2026-09-01 03:00:00'), $old_site, $now)), 'backups are switched off: nothing is overdue');
check_same(array('backup_failed', 'backup_overdue'), $problem_kinds(backupFindProblems(true, $failed, $at('2026-10-01 03:00:00'), $old_site, $now)), 'failing for days');
check_same(array('—', '512 B', '1.5 KB', '2.0 MB', '3.5 GB'), array(backupSize(null), backupSize(512), backupSize(1536), backupSize(2097152), backupSize(3758096384)), 'sizes for people');

// ---- sitting a contest virtually: its phases, and the standings replayed
require_once __DIR__ . '/../app/libs/uoj-contest-lib.php';
require_once __DIR__ . '/../app/libs/uoj-virtual-lib.php';

$virtual = array('start_time' => '2026-10-10 14:00:00', 'last_min' => 180);
check_same('upcoming', virtualPhase($virtual, $at('2026-10-10 13:59:59')), 'a virtual participation that was reserved');
check_same('running', virtualPhase($virtual, $at('2026-10-10 14:00:00')), 'the moment it starts');
check_same('running', virtualPhase($virtual, $at('2026-10-10 16:59:59')), 'its last second');
check_same('ended', virtualPhase($virtual, $at('2026-10-10 17:00:00')), 'it lasts as long as the contest did');
check_same(array(0, 1800, 10800), array(virtualElapsed($virtual, $at('2026-10-10 13:00:00')), virtualElapsed($virtual, $at('2026-10-10 14:30:00')), virtualElapsed($virtual, $at('2026-10-11 09:00:00'))), 'how much of it has gone by');
check_same(array('0:00:00', '0:05:09', '2:59:59', '26:00:00'), array(virtualClock(0), virtualClock(309), virtualClock(10799), virtualClock(93600)), 'a clock for people');

// the time that the field for a reservation offers: the next minute that ends in 0 or 5
foreach (array(
	'2026-10-10 10:33:00' => '2026-10-10T10:35',
	'2026-10-10 10:37:59' => '2026-10-10T10:40',
	'2026-10-10 10:34:59' => '2026-10-10T10:35',
	// the minute the page is opened in is over by the time the form is sent
	'2026-10-10 10:35:00' => '2026-10-10T10:40',
	'2026-10-10 10:39:01' => '2026-10-10T10:40',
	'2026-10-10 10:58:30' => '2026-10-10T11:00',
	// the date is the date of that minute
	'2026-10-10 23:57:10' => '2026-10-11T00:00',
	'2026-12-31 23:55:00' => '2027-01-01T00:00',
) as $opened => $offered) {
	check_same($offered, virtualDefaultStart($at($opened)), "the time offered for a reservation at $opened");
	// and it is a time that can be reserved as it stands
	check_same('', virtualStartError(str_replace('T', ' ', $offered) . ':00', $at($opened)), "reserving the time offered at $opened");
}

$now = $at('2026-10-10 12:00:00');
check_same('', virtualStartError('', $now), 'starting now');
check_same('', virtualStartError('2026-10-10 19:30:00', $now), 'reserving this evening');
check_same('', virtualStartError('2026-11-09 12:00:00', $now), 'reserving thirty days ahead');
foreach (array('2026-10-10 11:00:00', '2026-11-09 12:00:01', '2026-10-10T19:30', 'tomorrow', '2026-13-40 00:00:00', array('x')) as $bad) {
	check_same(true, virtualStartError($bad, $now) !== '', 'refused as the start of a virtual participation: ' . json_encode($bad));
}

// a contest of three problems and an hour: ann solved A after 5 minutes and B after 40,
// bob solved A after 30 and never got anything for C
$people = array(array('ann', 1500, ''), array('bob', 1600, '小波'), array('20260101', 1500, ''));
$final = array(array('ann', 0, 100, 300, 11), array('ann', 1, 60, 2400, 12), array('bob', 0, 100, 1800, 13), array('bob', 2, 0, 0, 14));
$me = array('username' => 'me', 'nickname' => '', 'rating' => 1500);
$board = function($mine, $elapsed, $ended = false, $version = 2) use ($people, $final, $me) {
	$lines = array();
	foreach (virtualStandings($people, $final, $me, $mine, $elapsed, $ended, $version) as $row) {
		$lines[] = $row['rank'] . ' ' . $row['username'] . ($row['virtual'] ? '*' : '') . ' ' . $row['score'] . '/' . $row['penalty'];
	}
	return join(', ', $lines);
};
check_same('1 me* 0/0, 1 20260101 0/0, 1 ann 0/0, 1 bob 0/0', $board(array(), 0), 'at the start nobody has anything');
check_same('1 ann 100/300, 2 me* 0/0, 2 20260101 0/0, 2 bob 0/0', $board(array(), 600), 'ten minutes in, ann has her first problem');
check_same('1 ann 100/300, 2 me* 100/900, 3 20260101 0/0, 3 bob 0/0', $board(array(array(21, 900, 0, 100)), 1000), 'solving it later ranks behind');
check_same('1 me* 100/200, 2 ann 100/300, 3 20260101 0/0, 3 bob 0/0', $board(array(array(21, 200, 0, 100)), 1000), 'and sooner ranks ahead');
check_same('1 ann 160/2700, 2 me* 100/900, 3 bob 100/1800, 4 20260101 0/0', $board(array(array(21, 900, 0, 100)), 3000), 'the others get what they got when they got it');
check_same('1 me* 100/300, 1 ann 100/300, 3 20260101 0/0, 3 bob 0/0', $board(array(array(21, 300, 0, 100)), 600), 'the same score at the same time is the same rank');
// the last submission to a problem is the one that counts, as in a contest
check_same('1 ann 100/300, 2 me* 0/0, 2 20260101 0/0, 2 bob 0/0', $board(array(array(21, 200, 0, 100), array(22, 500, 0, 0)), 600), 'a later submission that fails takes the points away');
check_same('1 ann 100/300, 2 20260101 0/0, 2 bob 0/0, 4 me* 0/500', $board(array(array(21, 200, 0, 100), array(22, 500, 0, 0)), 600, false, 1), 'with the time of it, by the old way of counting');
check_same('1 me* 100/200, 2 ann 100/300, 3 20260101 0/0, 3 bob 0/0', $board(array(array(21, 200, 0, 100), array(22, 900, 0, 0)), 600), 'what is submitted later has not happened yet');
// a zero says nothing about when it was earned, so it is shown when everything is
$cells_of = function($username, $elapsed, $ended) use ($people, $final, $me) {
	foreach (virtualStandings($people, $final, $me, array(), $elapsed, $ended) as $row) {
		if ($row['username'] === $username && !$row['virtual']) {
			return array_keys($row['cells']);
		}
	}
};
check_same(array(0), $cells_of('bob', 3600, false), 'a zero is not shown while the participation runs');
check_same(array(0, 2), $cells_of('bob', 3600, true), 'and is when it is over');
check_same('20260101', virtualStandings($people, $final, $me, array(), 0, false)[1]['username'], 'a username that is a number is a string in the standings');
// who sat the real contest and sits it again is there twice: as they were, and as they are now
$again = virtualStandings($people, $final, array('username' => 'ann', 'nickname' => '', 'rating' => 1500), array(array(31, 100, 0, 100)), 600, false);
check_same(array('ann', true, 'ann', false), array($again[0]['username'], $again[0]['virtual'], $again[1]['username'], $again[1]['virtual']), 'a contestant who sits the contest again');

// ---- who sat a contest afterwards is put on its board, and the board is what it was
// the board as calcStandings() leaves it: score, penalty, who, rank
$board = array(
	array(200, 900, array('ann', 1600, ''), 1),
	array(100, 300, array('bob', 1500, '小波'), 2),
	array(100, 300, array('cat', 1500, ''), 2),
	array(0, 0, array('dan', 1500, ''), 4)
);
$cells = array('ann' => array(0 => array(100, 300, 1), 1 => array(100, 600, 2)), 'bob' => array(0 => array(100, 300, 3)), 'cat' => array(0 => array(100, 300, 4)));
$sat = function($username, $score, $penalty) {
	return array('username' => $username, 'rating' => 1500, 'nickname' => '', 'cells' => $score > 0 ? array(0 => array($score, $penalty, 9)) : array(), 'score' => $score, 'penalty' => $penalty);
};
$joined = function($rows) use ($board, $cells) {
	$standings = $board;
	$score = $cells;
	virtualJoinStandings($standings, $score, $rows);
	$lines = array();
	foreach ($standings as $row) {
		$lines[] = (isset($row[2][3]) && $row[2][3] === 'v' ? '(' . $row[3] . ')' : $row[3]) . ' ' . $row[2][0];
	}
	return array(join(', ', $lines), $standings, $score);
};
check_same('1 ann, 2 bob, 2 cat, 4 dan', $joined(array())[0], 'a board nobody sat afterwards is the board');
check_same('(1) eve, 1 ann, 2 bob, 2 cat, 4 dan', $joined(array($sat('eve', 300, 0)))[0], 'better than everybody: before everybody, and nobody moves down');
check_same('1 ann, (2) eve, 2 bob, 2 cat, 4 dan', $joined(array($sat('eve', 100, 200)))[0], 'between two contestants: with the rank of the one it comes before');
check_same('1 ann, (2) eve, 2 bob, 2 cat, 4 dan', $joined(array($sat('eve', 100, 300)))[0], 'level with contestants: with their rank, before them');
check_same('1 ann, 2 bob, 2 cat, (4) eve, 4 dan', $joined(array($sat('eve', 100, 301)))[0], 'behind two who are level: the rank after both');
check_same('1 ann, 2 bob, 2 cat, (4) eve, 4 dan', $joined(array($sat('eve', 0, 0)))[0], 'with nothing: level with who has nothing');
check_same('1 ann, 2 bob, 2 cat, 4 dan, (5) eve', $joined(array($sat('eve', -5, 0)))[0], 'worse than everybody: after everybody');
check_same('(1) fay, 1 ann, (2) eve, (2) gus, 2 bob, 2 cat, 4 dan', $joined(array($sat('gus', 100, 250), $sat('eve', 100, 200), $sat('fay', 300, 0)))[0],
	'several of them: each where it would have stood alone, level ones by their names');
list($line, $with_ann, $with_score) = $joined(array($sat('ann', 100, 100)));
check_same('1 ann, (2) ann, 2 bob, 2 cat, 4 dan', $line, 'a contestant who sat it again is there twice');
check_same(array(array('ann', 1500, '', 'v'), 2), array($with_ann[1][2], $with_ann[1][3]), 'a row that is virtual says so of its person');
check_same(array(array(0 => array(100, 100, 9)), 2), array($with_score['v/ann'], count($with_score['ann'])), 'and its cells are kept beside the ones of the contestant');
check_same($board, array_values(array_filter($with_ann, function($row) {
	return !isset($row[2][3]);
})), 'the rows of the contestants are untouched');

// under the ICPC rule the replay shows a solved problem from the moment it was solved, though
// its penalty says more, and counts the participation as the contest would
$icpc_final = array(array('ann', 0, 100, 300 + 2 * 1200, 11, 2), array('bob', 0, 100, 1800, 13, 0), array('bob', 1, 0, 0, 14, 3));
$icpc_board = function($mine, $elapsed, $ended = false) use ($people, $icpc_final, $me) {
	$lines = array();
	foreach (virtualStandings($people, $icpc_final, $me, $mine, $elapsed, $ended, 2, 'ICPC') as $row) {
		$lines[] = $row['rank'] . ' ' . $row['username'] . ($row['virtual'] ? '*' : '') . ' ' . $row['score'] . '/' . $row['penalty'];
	}
	return join(', ', $lines);
};
check_same('1 ann 100/2700, 2 me* 0/0, 2 20260101 0/0, 2 bob 0/0', $icpc_board(array(), 600), 'ann solved it after five minutes, with the penalty of her two attempts in vain');
check_same('1 me* 0/0, 1 20260101 0/0, 1 ann 0/0, 1 bob 0/0', $icpc_board(array(), 200), 'and not before');
check_same('1 me* 100/1700, 2 ann 100/2700, 3 20260101 0/0, 3 bob 0/0', $icpc_board(array(array(21, 100, 0, 0), array(22, 500, 0, 100), array(23, 550, 0, 0)), 600), 'one attempt in vain costs twenty minutes, and what comes after solving changes nothing');
check_same('1 ann 100/2700, 2 me* 0/0, 2 20260101 0/0, 2 bob 0/0', $icpc_board(array(array(21, 100, 0, 0), array(22, 300, 0, 60)), 600), 'a problem that is not solved costs nothing');
$icpc_cells = function($username, $ended) use ($people, $icpc_final, $me) {
	foreach (virtualStandings($people, $icpc_final, $me, array(), 3600, $ended, 2, 'ICPC') as $row) {
		if ($row['username'] === $username && !$row['virtual']) {
			return $row['cells'];
		}
	}
};
check_same(array(0 => array(100, 1800, 13, 0, 0)), $icpc_cells('bob', false), 'what bob never solved is not shown while the participation runs');
check_same(array(0, 0, 14, 3, 0), $icpc_cells('bob', true)[1], 'and is when it is over, with how often he tried');

