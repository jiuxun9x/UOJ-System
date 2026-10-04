<?php

if (!validateUInt($_GET['id']) || !($contest = queryContest($_GET['id']))) {
	become404Page();
}
genMoreContestInfo($contest);

if (!can(Auth::user(), 'contest.assist', $contest)) {
	become403Page();
}
if ($contest['cur_progress'] < CONTEST_FINISHED) {
	becomeMsgPage('比赛尚未结束，无法下载排名。');
}

$contest_data = queryContestData($contest);
calcStandings($contest, $contest_data, $score, $standings);

function array2csv(array &$array) {
	if (count($array) == 0) {
		return null;
	}

	ob_start();

	$df = fopen("php://output", 'w');

	fputcsv($df, array_keys(reset($array)));

	foreach ($array as $row) {
		fputcsv($df, $row);
	}

	fclose($df);

	return ob_get_clean();
}

$export_data = [];
// whoever may know who the users are gets their student numbers and real names
$with_identity = can(Auth::user(), 'user.view_identity');
$csv_header = ['Rank', 'Username', 'Nickname', 'Score', 'Penalty'];
if ($with_identity) {
	array_splice($csv_header, 3, 0, ['StudentID', 'RealName']);
}
$n_problems = count($contest_data['problems']);

for ($i = 0; $i < $n_problems; $i++) {
	$csv_header[] = chr(ord('A') + $i);
	$csv_header[] = chr(ord('A') + $i) . '_penalty';
	$csv_header[] = chr(ord('A') + $i) . '_submission_id';
}

$export_data[] = $csv_header;

// Convert data in $standings and $score to $export_data
foreach ($standings as $rank => $row) {
	// $row: rank, username, score, penalty
	$res = [$rank + 1, $row[2][0], $row[2][2], $row[0], $row[1]];
	if ($with_identity) {
		$identities = UOJSSO::identitiesOf($row[2][0]);
		array_splice($res, 3, 0, $identities ? [$identities[0]['student_id'], $identities[0]['real_name']] : ['', '']);
	}
	for ($i = 0; $i < $n_problems; $i++) {
		// $score[$row[2][0]][$i]: score, penalty, submission_id
		$res[] = isset($score[$row[2][0]][$i][0]) ? $score[$row[2][0]][$i][0] : "";
		$res[] = isset($score[$row[2][0]][$i][1]) ? $score[$row[2][0]][$i][1] : "";
		$res[] = isset($score[$row[2][0]][$i][2]) ? $score[$row[2][0]][$i][2] : "";
	}
	$export_data[] = $res;
}

$csv = array2csv($export_data);

header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
header("Last-Modified: {$now} GMT");
header("Content-Type: text/csv");
header("Content-Disposition: attachment;filename={$contest['id']}_standings.csv");

die($csv);
