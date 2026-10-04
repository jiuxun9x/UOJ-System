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
