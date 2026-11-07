<?php

// What an ICPC contest is, written for the resolvers of others: the event feed of the CCS
// specifications, and the files of XCPCIO.

require_once __DIR__ . '/../app/libs/uoj-contest-lib.php';
require_once __DIR__ . '/../app/libs/uoj-utility-lib.php';
require_once __DIR__ . '/../app/libs/uoj-feed-lib.php';

$zone = date_default_timezone_get();
date_default_timezone_set('Asia/Shanghai');

// ---- how things are written
check_same('2026-11-07T19:00:00.000+08:00', feedTime(strtotime('2026-11-07 19:00:00')), 'a moment, with its time zone');
check_same(array('0:00:00.000', '4:10:00.000', '12:03:09.000'), array(feedRelTime(0), feedRelTime(250 * 60), feedRelTime(12 * 3600 + 189)), 'a length of time');
check_same(array(array('cpp17', 'cpp'), array('c11', 'c'), array('python3', 'py'), array('java21', 'java'), array('pascal', 'pas')),
	array(feedLanguage('C++17'), feedLanguage('C11'), feedLanguage('Python3'), feedLanguage('Java21'), feedLanguage('Pascal')), 'the languages of the site');
// the judgement of a submission, as the board counts it
$wrong = '<tests><test num="1" score="0" info="Wrong Answer" time="1" memory="1"></test></tests>';
$slow = '<tests><test num="1" score="100" info="Accepted" time="1" memory="1"></test><test num="2" score="0" info="Time Limit Exceeded" time="1" memory="1"></test></tests>';
check_same(array(null, null, 'AC', 'WA', 'TLE', 'CE', 'JE', 'RTE'), array(
	feedJudgementOf(null, 'Waiting', null, ''), feedJudgementOf(100, 'Judging', null, ''),
	feedJudgementOf(100, 'Judged', null, ''), feedJudgementOf(0, 'Judged', null, $wrong), feedJudgementOf(50, 'Judged', null, $slow),
	feedJudgementOf(null, 'Judged', 'Compile Error', ''), feedJudgementOf(null, 'Judged', 'Judgement Failed', ''),
	feedJudgementOf(0, 'Judged', null, '<tests><test num="1" score="0" info="Dangerous Syscalls"></test></tests>')
), 'what a submission is judged, or nothing while it waits');
check_same(array(false, false, true), array(feedJudgementTypes()['CE']['penalty'], feedJudgementTypes()['JE']['penalty'], feedJudgementTypes()['WA']['penalty']),
	'what did not compile, and what nobody could judge, cost nothing, as on the board');

// ---- a contest of two problems: ann solved A at 0:20 after a wrong answer, bob B after the board froze
$start = strtotime('2026-11-07 09:00:00');
$facts = array(
	'id' => 7, 'name' => '校赛 "决赛"', 'start' => $start, 'duration' => 5 * 3600, 'freeze' => 4 * 3600, 'published' => false, 'now' => $start + 6 * 3600,
	'problems' => array(
		array('letter' => 'A', 'name' => '加法', 'color' => '#ff0000', 'time_limit' => 1.0, 'tests' => 10),
		array('letter' => 'B', 'name' => '减法', 'color' => null, 'time_limit' => 2.5, 'tests' => 3)
	),
	'teams' => array(array('id' => 'ann', 'name' => '安'), array('id' => 'bob', 'name' => 'bob')),
	'submissions' => array(
		array('id' => 11, 'team' => 'ann', 'position' => 0, 'offset' => 600, 'language' => 'C++17', 'type' => 'WA', 'judged' => $start + 610),
		array('id' => 12, 'team' => 'ann', 'position' => 0, 'offset' => 1200, 'language' => 'C++17', 'type' => 'AC', 'judged' => $start + 1205),
		array('id' => 13, 'team' => 'bob', 'position' => 1, 'offset' => 250 * 60, 'language' => 'Python3', 'type' => 'AC', 'judged' => null)
	),
	'medals' => array(array('name' => '金牌', 'count' => 1, 'given' => array('ann')), array('name' => '银牌', 'count' => 1, 'given' => array('bob')),
		array('name' => '铜牌', 'count' => 1, 'given' => array()), array('name' => '优胜奖', 'count' => 2, 'given' => array())),
	'firsts' => array(0 => 'ann', 1 => 'bob'), 'winner' => array('ann')
);
$events = array_map(function($line) {
	return json_decode($line, true);
}, explode("\n", trim(feedClics($facts))));
$of = function($type) use ($events) {
	return array_values(array_filter($events, function($event) use ($type) {
		return $event['type'] === $type;
	}));
};
check_same(array('contest', null), array($events[0]['type'], $events[0]['id']), 'the feed begins with the contest');
check_same(array('id' => 'uoj-7', 'name' => '校赛 "决赛"', 'formal_name' => '校赛 "决赛"', 'start_time' => '2026-11-07T09:00:00.000+08:00', 'duration' => '5:00:00.000',
	'scoreboard_type' => 'pass-fail', 'penalty_time' => 20, 'scoreboard_freeze_duration' => '1:00:00.000'), $events[0]['data'], 'the contest, frozen for its last hour');
check_same(array('AC', 'WA', 'TLE', 'MLE', 'OLE', 'RTE', 'CE', 'JE'), array_column($of('judgement-types'), 'id'), 'the judgement types');
check_same(array('cpp17', 'python3'), array_column($of('languages'), 'id'), 'the languages that were used');
check_same(array('id' => 'A', 'label' => 'A', 'name' => '加法', 'ordinal' => 0, 'time_limit' => 1.0, 'test_data_count' => 10, 'rgb' => '#ff0000'), $of('problems')[0]['data'], 'a problem, with its balloon');
check_same(false, isset($of('problems')[1]['data']['rgb']), 'a problem without a balloon has no colour');
check_same(array('id' => 'ann', 'name' => '安', 'label' => 'ann', 'display_name' => '安', 'group_ids' => array('participants'), 'hidden' => false), $of('teams')[0]['data'], 'a team');
check_same(array('id' => '12', 'language_id' => 'cpp17', 'problem_id' => 'A', 'team_id' => 'ann', 'time' => '2026-11-07T09:20:00.000+08:00', 'contest_time' => '0:20:00.000', 'files' => array()),
	$of('submissions')[1]['data'], 'a submission');
check_same(array('id' => '12', 'submission_id' => '12', 'judgement_type_id' => 'AC', 'start_time' => '2026-11-07T09:20:00.000+08:00', 'start_contest_time' => '0:20:00.000',
	'end_time' => '2026-11-07T09:20:05.000+08:00', 'end_contest_time' => '0:20:05.000'), $of('judgements')[1]['data'], 'its judgement');
// what was judged at a time nobody knows is judged when it was submitted
check_same('4:10:00.000', $of('judgements')[2]['data']['end_contest_time'], 'a judgement whose time is not known');
// every submission is followed by its judgement
$order = array();
foreach ($events as $event) {
	if ($event['type'] === 'submissions' || $event['type'] === 'judgements') {
		$order[] = $event['type'][0] . $event['id'];
	}
}
check_same(array('s11', 'j11', 's12', 'j12', 's13', 'j13'), $order, 'submissions and judgements, one after the other');
check_same(array('gold-medal', 'silver-medal', 'bronze-medal', 'honors-4', 'winner', 'first-to-solve-A', 'first-to-solve-B'), array_column($of('awards'), 'id'), 'the awards');
check_same(array('id' => 'gold-medal', 'citation' => '金牌', 'team_ids' => array('ann')), $of('awards')[0]['data'], 'a medal');
// the feed ends with the state: over, frozen, and final as nothing waits
$state = $events[count($events) - 1];
check_same(array('state', null), array($state['type'], $state['id']), 'the feed ends with the state');
check_same(array('started' => '2026-11-07T09:00:00.000+08:00', 'frozen' => '2026-11-07T13:00:00.000+08:00', 'ended' => '2026-11-07T14:00:00.000+08:00', 'thawed' => null,
	'finalized' => '2026-11-07T15:00:00.000+08:00', 'end_of_updates' => '2026-11-07T15:00:00.000+08:00'), $state['data'], 'the state at the end');
$waiting = $facts;
$waiting['submissions'][2]['type'] = null;
$waiting_events = explode("\n", trim(feedClics($waiting)));
$last = json_decode($waiting_events[count($waiting_events) - 1], true);
check_same(array(null, null), array($last['data']['finalized'], $last['data']['end_of_updates']), 'what waits to be judged leaves the contest not final');
check_same(false, array_key_exists('end_time', json_decode($waiting_events[count($waiting_events) - 9], true)['data']), 'a judgement that is not over has no end');
$published = $facts;
$published['published'] = true;
$lines = explode("\n", trim(feedClics($published)));
check_same('2026-11-07T15:00:00.000+08:00', json_decode($lines[count($lines) - 1], true)['data']['thawed'], 'a contest whose results are published is thawed');

// ---- the files of XCPCIO
$files = feedXcpcio($facts);
check_same(array('config.json', 'team.json', 'run.json'), array_keys($files), 'the files of XCPCIO');
$config = json_decode($files['config.json'], true);
check_same(array('校赛 "决赛"', $start, $start + 18000, 3600, 1200, array('A', 'B'), array('official' => array('gold' => 1, 'silver' => 1, 'bronze' => 1)), 'in_seconds'),
	array($config['contest_name'], $config['start_time'], $config['end_time'], $config['frozen_time'], $config['penalty'], $config['problem_id'], $config['medal'], $config['options']['calculation_of_penalty']),
	'the config: the contest, its freeze, its penalty in seconds, and the first three kinds of medals');
check_same(array(array('background_color' => '#ff0000', 'color' => '#ffffff'), array('background_color' => '#cccccc', 'color' => '#000000')), $config['balloon_color'], 'the balloons');
check_same(array('ann' => array('team_id' => 'ann', 'name' => '安', 'organization' => '', 'group' => array('official'), 'official' => true),
	'bob' => array('team_id' => 'bob', 'name' => 'bob', 'organization' => '', 'group' => array('official'), 'official' => true)), json_decode($files['team.json'], true), 'the teams');
check_same(array(
	array('submission_id' => '11', 'team_id' => 'ann', 'problem_id' => 0, 'timestamp' => 600, 'status' => 'WRONG_ANSWER', 'language' => 'C++17'),
	array('submission_id' => '12', 'team_id' => 'ann', 'problem_id' => 0, 'timestamp' => 1200, 'status' => 'ACCEPTED', 'language' => 'C++17'),
	array('submission_id' => '13', 'team_id' => 'bob', 'problem_id' => 1, 'timestamp' => 15000, 'status' => 'ACCEPTED', 'language' => 'Python3')
), json_decode($files['run.json'], true), 'the runs');
check_same('PENDING', feedXcpcioStatus(null), 'what waits to be judged');
check_same('{}', trim(feedXcpcio(array('teams' => array(), 'submissions' => array()) + $facts)['team.json']), 'no teams is an empty object');

date_default_timezone_set($zone);
