<?php
define("CONTEST_NOT_STARTED", 0);
define("CONTEST_IN_PROGRESS", 1);
define("CONTEST_PENDING_FINAL_TEST", 2);
define("CONTEST_TESTING", 10);
define("CONTEST_FINISHED", 20);	

function calcRating($standings, $K = 400) {
	$DELTA = 500;

	$n = count($standings);
	
	$rating = array();
	for ($i = 0; $i < $n; ++$i) {
		$rating[$i] = $standings[$i][2][1];
	}
	
	$rank = array();
	$foot = array();
	for ($i = 0; $i < $n; ) {
		$j = $i;
		while ($j + 1 < $n && $standings[$j + 1][3] == $standings[$j][3]) {
			++$j;
		}
		$our_rk = 0.5 * (($i + 1) + ($j + 1));
		while ($i <= $j) {
			$rank[$i] = $our_rk;
			$foot[$i] = $n - $rank[$i];
			$i++;
		}
	}
	
	$weight = array();
	for ($i = 0; $i < $n; ++$i) {
		$weight[$i] = pow(7, $rating[$i] / $DELTA);
	}
	$exp = array_fill(0, $n, 0);
	for ($i = 0; $i < $n; ++$i) {
		for ($j = 0; $j < $n; ++$j) {
			if ($j != $i) {
				$exp[$i] += $weight[$i] / ($weight[$i] + $weight[$j]);
			}
		}
	}
	
	$new_rating = array();
	for ($i = 0; $i < $n; $i++) {
		$new_rating[$i] = $rating[$i];
		$new_rating[$i] += ceil($K * ($foot[$i] - $exp[$i]) / ($n - 1));
	}
	
	for ($i = $n - 1; $i >= 0; $i--) {
		if ($i + 1 < $n && $standings[$i][3] != $standings[$i + 1][3]) {
			break;
		}
		if ($new_rating[$i] > $rating[$i]) {
			$new_rating[$i] = $rating[$i];
		}
	}
	
	for ($i = 0; $i < $n; $i++) {
		if ($new_rating[$i] < 0) {
			$new_rating[$i] = 0;
		}
	}
	
	return $new_rating;
}

function calcRatingSelfTest() {
	$tests = [
		[[1500, 1], [1500, 1]],
		[[1500, 1], [1600, 1]],
		[[1500, 1], [1600, 2], [1600, 2]],
		[[1500, 1], [200, 2], [100, 2]],
		[[1500, 1], [100, 2], [200, 2]],
		[[1500, 1], [100, 2], [200, 3]],
		[[1500, 1], [200, 2], [100, 3]],
		[[1500, 1], [3000, 2], [1500, 3]],
		[[1500, 1], [3000, 2], [1500, 3], [1500, 3]],
		[[1500, 1], [1500, 2], [1500, 3], [3000, 4]],
		[[1500, 1], [1500, 2], [10, 3], [1, 4]]
	];
	foreach ($tests as $test_num => $test) {
		print "test #{$test_num}\n";
		
		$standings = array();
		$n = count($test);
		for ($i = 0; $i < $n; $i++) {
			$standings[] = [0, 0, [(string)$i, $test[$i][0]], $test[$i][1]];
		}
		$new_rating = calcRating($standings);
		
		for ($i = 0; $i < $n; $i++) {
			printf("%3d: %4d -> %4d delta: %+4d\n", $test[$i][1], $test[$i][0], $new_rating[$i], $new_rating[$i] - $test[$i][0]);
		}
		print "\n";
	}
}

// ---- the problems of a contest
//
// They are lettered A, B, C in the order the contest puts them in.

// the ids of the problems of a contest in their order
function contestProblemIds($contest_id) {
	$ids = array();
	foreach (DB::selectAll("select problem_id from contests_problems where contest_id = ".(int)$contest_id." order by position, problem_id") as $row) {
		$ids[] = (int)$row['problem_id'];
	}
	return $ids;
}
// The problems that numbers name to the people who run a contest: array(the problems, '') or
// array(null, why not). $numbers is what was typed: numbers with blanks or commas between.
function contestProblemsByNumbers($contest, $numbers, $actor) {
	$problems = array();
	foreach (preg_split('/[\s,，;；、]+/u', trim($numbers), -1, PREG_SPLIT_NO_EMPTY) as $number) {
		$number = ltrim($number, '#');
		$problem = contestProblemByNumber($contest, $number);
		if (!$problem) {
			return array(null, !empty($contest['domain_id']) ? "本域没有题号为 $number 的题。主站的题目要先在域的“题目”页复制到本域" : "没有题号为 $number 的题");
		}
		if (!can($actor, 'problem.manage', $problem)) {
			return array(null, "你不是题目 #$number 的管理者，不能把它加入比赛");
		}
		if (isset($problems[(int)$problem['id']])) {
			return array(null, "题号 $number 写了两次");
		}
		$problems[(int)$problem['id']] = $problem;
	}
	if (count($problems) > 26) {
		return array(null, '一场比赛最多 26 道题');
	}
	return array(array_values($problems), '');
}
// each of these returns '' or why it was refused
function contestAddProblem($contest, $problem, $actor) {
	$ids = contestProblemIds($contest['id']);
	if (in_array((int)$problem['id'], $ids, true)) {
		return '这道题已经在比赛里了';
	}
	if (count($ids) >= 26) {
		return '一场比赛最多 26 道题';
	}
	contestRenumberProblems($contest['id'], array_merge($ids, array((int)$problem['id'])));
	auditLog('contest.add_problem', 'contest', $contest['id'], null, array('problem_id' => (int)$problem['id']), $actor);
	return '';
}
function contestRemoveProblem($contest, $problem_id, $actor) {
	$problem_id = (int)$problem_id;
	DB::delete("delete from contests_problems where contest_id = {$contest['id']} and problem_id = $problem_id");
	if (DB::affected_rows() != 1) {
		return '比赛里没有这道题';
	}
	contestSetProblemJudging($contest, $problem_id, false, null);
	auditLog('contest.remove_problem', 'contest', $contest['id'], array('problem_id' => $problem_id), null, $actor);
	return '';
}
// Moves a problem one place in the order, up or down. A problem that stands at the end it
// would be moved past stays where it is.
function contestMoveProblem($contest, $problem_id, $down, $actor) {
	$ids = contestProblemIds($contest['id']);
	$index = array_search((int)$problem_id, $ids, true);
	if ($index === false) {
		return '比赛里没有这道题';
	}
	$other = $down ? $index + 1 : $index - 1;
	if (isset($ids[$other])) {
		$ids[$index] = $ids[$other];
		$ids[$other] = (int)$problem_id;
		contestRenumberProblems($contest['id'], $ids);
		auditLog('contest.order_problems', 'contest', $contest['id'], null, array('problems' => $ids), $actor);
	}
	return '';
}
// writes down the order of the problems of a contest, adding the ones that are not in it yet
function contestRenumberProblems($contest_id, $ids) {
	foreach (array_values($ids) as $index => $problem_id) {
		DB::insert("insert into contests_problems (contest_id, problem_id, position) values (".(int)$contest_id.", ".(int)$problem_id.", ".($index + 1).") on duplicate key update position = ".($index + 1));
	}
}
// Under the OI rule a problem can be judged with all its data while the contest runs,
// instead of with its samples.
function contestSetProblemJudging($contest, $problem_id, $full, $actor) {
	$config = queryContest($contest['id'])['extra_config'];
	$config = json_decode($config, true);
	$config = is_array($config) ? $config : array();
	$key = 'problem_' . (int)$problem_id;
	if (!$full && !isset($config[$key])) {
		return '';
	}
	if ($full) {
		$config[$key] = 'full';
	} else {
		unset($config[$key]);
	}
	DB::update("update contests set extra_config = '".DB::escape(json_encode($config ? $config : new stdClass()))."' where id = {$contest['id']}");
	if ($actor !== null) {
		auditLog('contest.edit_problem', 'contest', $contest['id'], null, array('problem_id' => (int)$problem_id, 'judged_with' => $full ? 'everything' : 'samples'), $actor);
	}
	return '';
}

// ---- the rules of a contest
//
// OI    while the contest runs a submission is judged with the samples, and its owner sees
//       how it did on them; when it is over everything is judged again with all the data.
//       The last submission to a problem counts.
// IOI   judged with all the data at once, and the owner sees the score.
//       The last submission to a problem counts.
// ICPC  judged with all the data at once, and the owner sees whether it passed. A problem is
//       solved or not; who solved more is ahead, then who has less penalty: the time of
//       every solved problem plus twenty minutes for every failed attempt at it before.
//       The board can be frozen for the last minutes.
//
// Under no rule does anybody but the staff see how a submission did on the single tests
// while the contest runs.
define('CONTEST_ICPC_PENALTY', 1200);

function contestRules() {
	return array(
		'OI' => array(
			'name' => 'OI',
			'description' => '比赛中只用样例评测，选手看到样例的得分；比赛结束后用全部数据重新评测。按总分排名，每题以最后一次提交为准。'
		),
		'IOI' => array(
			'name' => 'IOI',
			'description' => '比赛中用全部数据评测，选手立刻看到得分。按总分排名，每题以最后一次提交为准。'
		),
		'ICPC' => array(
			'name' => 'ICPC',
			'description' => '比赛中用全部数据评测，选手立刻看到是否通过。按通过题数排名，题数相同按罚时：每道通过的题的通过时间，加上此前每次未通过的提交 20 分钟。可以封榜。'
		)
	);
}
function contestRule($contest) {
	$rule = isset($contest['extra_config']['contest_type']) ? $contest['extra_config']['contest_type'] : 'OI';
	if ($rule === 'ACM') {
		// what the rule was called before it was one
		$rule = 'ICPC';
	}
	return isset(contestRules()[$rule]) ? $rule : 'OI';
}
// whether a submission to a problem of the contest is judged with the samples only while it runs
function contestJudgesSamplesOnly($contest, $problem_id) {
	return contestRule($contest) === 'OI' && !isset($contest['extra_config']["problem_$problem_id"]);
}

// ---- a frozen board
//
// An ICPC contest may freeze its board for its last minutes: from then on, and until its
// results are published, everybody but its staff sees the board as it was, with what was
// submitted since counted but not judged, and nobody sees what others submitted.
function contestFreezeMinutes($contest) {
	return contestRule($contest) === 'ICPC' && isset($contest['freeze_minutes']) ? (int)$contest['freeze_minutes'] : 0;
}
// how many seconds into the contest its board freezes, or null
function contestFreezeOffset($contest) {
	$minutes = contestFreezeMinutes($contest);
	return $minutes > 0 ? max(0, ((int)$contest['last_min'] - $minutes) * 60) : null;
}
// whether the board is frozen now: for the people who are not its staff
function contestBoardIsFrozen($contest) {
	$offset = contestFreezeOffset($contest);
	return $offset !== null && $contest['cur_progress'] < CONTEST_FINISHED
		&& UOJTime::$time_now->getTimestamp() >= $contest['start_time']->getTimestamp() + $offset;
}
// Whether what was submitted to a contest is still kept from everybody but its owner and the
// staff: while it runs, and, where the board freezes, until the results are published.
function contestKeepsResults($contest) {
	return $contest['cur_progress'] <= CONTEST_IN_PROGRESS
		|| ($contest['cur_progress'] < CONTEST_FINISHED && contestFreezeMinutes($contest) > 0);
}

// What the standings of a contest count of what was submitted to it.
//   $rows           what was submitted, the oldest first: rows of the id of the submission,
//                   seconds since the start of the contest, username, position of the
//                   problem, score. Under the ICPC rule a submission that waits to be judged
//                   is among them, with null for its score.
//   $freeze_offset  under the ICPC rule: from how many seconds into the contest on
//                   submissions are counted without being judged, or null
// Returns username => position of the problem => array(score, penalty in seconds, id of the
// submission that counts, failed attempts, attempts whose outcome is not told). The last two
// are there under the ICPC rule only.
//
// The ICPC rule is counted the way DOMjudge counts it:
// - a problem is solved by the first submission that passes, and nothing that is submitted
//   to it after that one is looked at any more, on any board;
// - what was submitted before it and did not pass costs twenty minutes each, and costs
//   nothing while the problem is not solved; a submission that does not compile is not
//   among the rows at all;
// - a submission that is not judged yet is an attempt whose outcome nobody knows;
// - on the frozen board, so is every submission made since the board froze, whatever became
//   of it. What was solved before the board froze stays solved.
function contestCells($rule, $standings_version, $rows, $freeze_offset = null) {
	$cells = array();
	// username => position => true: solved in truth, whether the board says so or not
	$closed = array();
	foreach ($rows as $row) {
		list($id, $offset, $name, $pos, $score) = $row;
		if ($rule !== 'ICPC') {
			// the last submission to a problem is the one that counts
			$cells[$name][$pos] = array((int)$score, $score == 0 && $standings_version >= 2 ? 0 : (int)$offset, (int)$id);
			continue;
		}
		if (isset($closed[$name][$pos])) {
			continue;
		}
		$cell = isset($cells[$name][$pos]) ? $cells[$name][$pos] : array(0, 0, (int)$id, 0, 0);
		if ($score === null) {
			$cell[4]++;
		} elseif ($freeze_offset !== null && $offset >= $freeze_offset) {
			$cell[4]++;
			if ($score == 100) {
				$closed[$name][$pos] = true;
			}
		} elseif ($score == 100) {
			$cell = array(100, (int)$offset + CONTEST_ICPC_PENALTY * $cell[3], (int)$id, $cell[3], $cell[4]);
			$closed[$name][$pos] = true;
		} else {
			$cell[2] = (int)$id;
			$cell[3]++;
		}
		$cells[$name][$pos] = $cell;
	}
	return $cells;
}

// a time of a contest as hours and minutes: 0:06, 17:02
function contestClock($seconds) {
	return sprintf('%d:%02d', floor($seconds / 3600), floor($seconds / 60) % 60);
}
// What a contest tells its contestants about its rule, in a few short lines.
function contestRuleFacts($contest) {
	$rule = contestRule($contest);
	if ($rule === 'OI') {
		$facts = array('比赛中只用样例评测', '比赛结束后用全部数据重测', '每题以最后一次提交为准');
	} elseif ($rule === 'IOI') {
		$facts = array('比赛中用全部数据评测', '提交后立刻看到得分', '每题以最后一次提交为准');
	} else {
		$facts = array('比赛中用全部数据评测', '提交后立刻看到是否通过', '罚时：每次未通过的提交 ' . (CONTEST_ICPC_PENALTY / 60) . ' 分钟');
		$freeze = contestFreezeOffset($contest);
		$facts[] = $freeze === null ? '不封榜' : '封榜：最后 ' . contestFreezeMinutes($contest) . ' 分钟（开始后 ' . virtualClock($freeze) . ' 起），公布成绩时揭晓';
	}
	$facts[] = '比赛中不显示每个测试点的结果';
	return $facts;
}
// How a cell of an ICPC board reads: array(what it says, what stands under it, its class).
// A solved problem says + and how often it was tried in vain, over the time it was solved
// at; a problem that was tried says - and how often; while the board is frozen a problem
// says ?, over the attempts that count and the ones nobody was told about.
function contestIcpcCell($cell) {
	if (!$cell) {
		return array('', '', '');
	}
	$failed = isset($cell[3]) ? (int)$cell[3] : 0;
	$pending = isset($cell[4]) ? (int)$cell[4] : 0;
	if ($cell[0] == 100) {
		return array('+' . ($failed > 0 ? $failed : ''), contestClock($cell[1] - CONTEST_ICPC_PENALTY * $failed), 'uoj-icpc-solved');
	}
	if ($pending > 0) {
		// "1 + 2": one attempt that is known to have failed, two whose outcome is not told
		return array('?', $failed . ' + ' . $pending, 'uoj-icpc-pending');
	}
	return $failed > 0 ? array('-' . $failed, '', 'uoj-icpc-failed') : array('', '', '');
}

// What a submission that was judged is said to be where only passing counts: Accepted, or
// what went wrong on the first test that it failed.
function submissionVerdictOf($score, $details) {
	if ($score == 100) {
		return 'Accepted';
	}
	if (is_string($details) && preg_match_all('/<test\b[^>]*\binfo="([^"]*)"/', $details, $matches)) {
		foreach ($matches[1] as $info) {
			if ($info !== 'Accepted' && $info !== 'Extra Test Passed') {
				return htmlspecialchars_decode($info);
			}
		}
	}
	return 'Wrong Answer';
}
// Whether the results of a contest change the ratings of the site. A contest of a domain
// never does, whatever its settings say.
function contestIsRated($contest) {
	return empty($contest['domain_id']) && !isset($contest['extra_config']['unrated']);
}

// The problem that a number names to the people who run a contest: a problem of the site in
// a contest of the site, a problem of the domain in a contest of a domain. A contest uses the
// problems of where it is held; a domain copies a problem of the site before it uses it.
function contestProblemByNumber($contest, $number) {
	if (!validateUInt($number)) {
		return null;
	}
	if ($contest['domain_id']) {
		return queryDomainProblem($contest['domain_id'], $number);
	}
	$problem = queryProblemBrief($number);
	return $problem && !$problem['owner_domain_id'] ? $problem : null;
}

// ---- the settings of a contest
//
// One form says everything about a contest that is not its problems or its people: what it
// is called, when it is held, by which rule, whether it counts for the ratings and who may
// take part. The same form makes a contest and changes it.

// What the settings are when nobody has said anything: for the form that makes a contest.
function contestDefaultSettings() {
	return array(
		'name' => '',
		'start_time' => date('Y-m-d H:00:00', time() + 86400),
		'last_min' => 180,
		'rule' => 'OI',
		'freeze_minutes' => 0,
		'standings_version' => 2,
		'rated' => false,
		'rating_k' => 400,
		'join_mode' => 'open',
		'join_password' => '',
		'reveal_problems' => false
	);
}
// the settings a contest has: $contest as genMoreContestInfo() leaves it
function contestSettings($contest) {
	return array(
		'name' => $contest['name'],
		'start_time' => $contest['start_time_str'],
		'last_min' => (int)$contest['last_min'],
		'rule' => contestRule($contest),
		'freeze_minutes' => contestFreezeMinutes($contest),
		'standings_version' => (int)$contest['extra_config']['standings_version'],
		'rated' => contestIsRated($contest),
		'rating_k' => isset($contest['extra_config']['rating_k']) ? (int)$contest['extra_config']['rating_k'] : 400,
		'join_mode' => $contest['join_mode'],
		'join_password' => '',
		'reveal_problems' => !empty($contest['reveal_problems'])
	);
}
// What a form says, checked: array(the settings, '') or array(null, why not).
//   $may_rate       whether whoever sent the form decides about ratings: otherwise what the
//                   contest has stays, and a new contest is unrated
//   $has_password   whether the contest has a password already, which an empty field keeps
function contestSettingsFromForm($input, $current, $may_rate, $has_password = false) {
	$get = function($name) use ($input) {
		return isset($input[$name]) && is_string($input[$name]) ? trim($input[$name]) : '';
	};
	$settings = $current;

	$settings['name'] = $get('name');
	if ($settings['name'] === '' || mb_strlen($settings['name'], 'UTF-8') > 100) {
		return array(null, '比赛名称不能为空，且不超过 100 个字符');
	}
	// what a date and time field of a browser sends, or the same with a blank and seconds
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/D', $get('start_time'), $t)
			|| !checkdate((int)$t[2], (int)$t[3], (int)$t[1]) || $t[4] > 23 || $t[5] > 59 || (isset($t[6]) && $t[6] > 59)) {
		return array(null, '开始时间的格式应为 2026-10-01 19:00');
	}
	$settings['start_time'] = sprintf('%s-%s-%s %s:%s:%s', $t[1], $t[2], $t[3], $t[4], $t[5], isset($t[6]) ? $t[6] : '00');
	if (!validateUInt($get('last_min')) || $get('last_min') < 1 || $get('last_min') > 525600) {
		return array(null, '时长应为 1 到 525600 分钟');
	}
	$settings['last_min'] = (int)$get('last_min');

	if (!isset(contestRules()[$get('rule')])) {
		return array(null, '请选择赛制');
	}
	$settings['rule'] = $get('rule');
	$settings['freeze_minutes'] = 0;
	if ($settings['rule'] === 'ICPC' && $get('freeze_minutes') !== '') {
		if (!validateUInt($get('freeze_minutes')) || $get('freeze_minutes') > $settings['last_min']) {
			return array(null, '封榜的分钟数应在 0 和比赛时长之间');
		}
		$settings['freeze_minutes'] = (int)$get('freeze_minutes');
	}
	$settings['standings_version'] = $get('standings_version') === '1' ? 1 : 2;

	if ($may_rate) {
		$settings['rated'] = isset($input['rated']);
		if ($get('rating_k') !== '') {
			if (!validateUInt($get('rating_k')) || $get('rating_k') < 1 || $get('rating_k') > 1000) {
				return array(null, 'Rating 变化上限应在 1 到 1000 之间');
			}
			$settings['rating_k'] = (int)$get('rating_k');
		}
	}

	if (!isset(contestJoinModes()[$get('join_mode')])) {
		return array(null, '请选择参加方式');
	}
	$settings['join_mode'] = $get('join_mode');
	$settings['join_password'] = '';
	if ($settings['join_mode'] === 'password') {
		// a password is taken as it was typed
		$password = isset($input['join_password']) && is_string($input['join_password']) ? $input['join_password'] : '';
		if ($password === '' && !$has_password) {
			return array(null, '选择“密码限制”时请设置参赛密码');
		}
		if ($password !== '' && (strlen($password) < 4 || strlen($password) > 64 || preg_match('/[\x00-\x1f\x7f]/', $password))) {
			return array(null, '参赛密码应为 4 到 64 个字符');
		}
		$settings['join_password'] = $password;
	}
	$settings['reveal_problems'] = isset($input['reveal_problems']);
	return array($settings, '');
}
// what the settings are in the columns of a contest, as SQL
function contestSettingsSql($settings, $extra_config) {
	$extra_config['contest_type'] = $settings['rule'];
	$extra_config['standings_version'] = $settings['standings_version'];
	$extra_config['rating_k'] = $settings['rating_k'];
	if ($settings['rated']) {
		unset($extra_config['unrated']);
	} else {
		$extra_config['unrated'] = '';
	}
	$set = array(
		'name' => "'".DB::escape(HTML::pruifier()->purify($settings['name']))."'",
		'start_time' => "'".DB::escape($settings['start_time'])."'",
		'last_min' => (int)$settings['last_min'],
		'freeze_minutes' => (int)$settings['freeze_minutes'],
		'join_mode' => "'".DB::escape($settings['join_mode'])."'",
		'reveal_problems' => $settings['reveal_problems'] ? 1 : 0,
		'extra_config' => "'".DB::escape(json_encode($extra_config))."'"
	);
	if ($settings['join_password'] !== '') {
		$set['join_password'] = "'".DB::escape(password_hash($settings['join_password'], PASSWORD_BCRYPT))."'";
	}
	return $set;
}
// what of the settings is written down where changes are: never the password
function contestSettingsForAudit($settings) {
	$settings['password_changed'] = $settings['join_password'] !== '';
	unset($settings['join_password']);
	return $settings;
}
// Makes a contest with these settings and these problems, and returns its id. A contest of a
// domain belongs to the domain and is run by the people who teach there; any other belongs
// to whoever makes it.
function contestCreateWithSettings($settings, $problems, $actor, $domain = null) {
	$set = contestSettingsSql($settings, array());
	$set['status'] = "'unfinished'";
	$set['domain_id'] = $domain === null ? 'null' : (int)$domain['id'];
	DB::insert("insert into contests (".join(', ', array_keys($set)).") values (".join(', ', $set).")");
	$contest_id = DB::insert_id();
	DB::insert("insert into contests_permissions (username, contest_id, role) values ('".DB::escape($actor['username'])."', $contest_id, 'owner')");
	$problem_ids = array();
	foreach ($problems as $problem) {
		$problem_ids[] = (int)$problem['id'];
	}
	contestRenumberProblems($contest_id, $problem_ids);
	auditLog('contest.create', 'contest', $contest_id, null, contestSettingsForAudit($settings) + array('problems' => $problem_ids) + ($domain === null ? array() : array('domain_id' => (int)$domain['id'])), $actor);
	return $contest_id;
}
function contestSaveSettings($contest, $settings, $actor) {
	$extra_config = queryContest($contest['id'])['extra_config'];
	$extra_config = json_decode($extra_config, true);
	$set = array();
	foreach (contestSettingsSql($settings, is_array($extra_config) ? $extra_config : array()) as $column => $value) {
		$set[] = "$column = $value";
	}
	DB::update("update contests set ".join(', ', $set)." where id = {$contest['id']}");
	auditLog('contest.edit', 'contest', $contest['id'], contestSettingsForAudit(contestSettings($contest)), contestSettingsForAudit($settings), $actor);
}

// ---- problems that are shown when what hid them is over
//
// The problems of a contest or of a homework are hidden so that nobody sees them before it
// begins. When it is over they stay hidden until somebody shows them, one by one, unless the
// contest or the homework was told to show them: then they are shown by themselves, once.
//
// A contest shows them when what was submitted to it is no longer kept from everybody: when
// it ends, or, where its board freezes, when its results are published. A homework shows
// them when it ends. A problem that is also in something that is not over yet waits for that.

// Of these problems, the ones that something which is not over yet still needs hidden:
// another contest that has not ended or still keeps its results, another homework that is
// published and has not ended. Returns array(id of the problem => what holds it).
function problemsHeldHidden($problem_ids, $but_contest = null, $but_homework = null) {
	$held = array();
	if (!$problem_ids) {
		return $held;
	}
	$ids = join(', ', array_map('intval', $problem_ids));
	foreach (DB::selectAll("select contests_problems.problem_id, contests.* from contests_problems join contests on contests.id = contests_problems.contest_id where contests_problems.problem_id in ($ids)".($but_contest === null ? '' : ' and contests.id != '.(int)$but_contest)) as $contest) {
		genMoreContestInfo($contest);
		if (contestKeepsResults($contest)) {
			$held[(int)$contest['problem_id']] = "contest {$contest['id']}";
		}
	}
	$now = DB::escape(UOJTime::$time_now_str);
	foreach (DB::selectAll("select homework_problems.problem_id, homeworks.id from homework_problems join homeworks on homeworks.id = homework_problems.homework_id where homework_problems.problem_id in ($ids) and homeworks.status in ('publishing', 'published') and homeworks.end_at > '$now'".($but_homework === null ? '' : ' and homeworks.id != '.(int)$but_homework)) as $homework) {
		$held[(int)$homework['problem_id']] = "homework {$homework['id']}";
	}
	return $held;
}
// Shows the hidden ones among these problems, but for the ones that are held hidden.
// Returns array(the ids that were shown, the ids that wait).
function problemsReveal($problem_ids, $but_contest = null, $but_homework = null) {
	$shown = array();
	$waiting = array();
	if (!$problem_ids) {
		return array($shown, $waiting);
	}
	$held = problemsHeldHidden($problem_ids, $but_contest, $but_homework);
	foreach (DB::selectAll("select id from problems where is_hidden = 1 and id in (".join(', ', array_map('intval', $problem_ids)).") order by id") as $problem) {
		if (isset($held[(int)$problem['id']])) {
			$waiting[] = (int)$problem['id'];
		} else {
			problemSetHidden($problem['id'], false);
			$shown[] = (int)$problem['id'];
		}
	}
	return array($shown, $waiting);
}
// whether a contest has problems to show now: $contest as genMoreContestInfo() leaves it
function contestRevealIsDue($contest) {
	return !empty($contest['reveal_problems']) && $contest['problems_revealed_at'] === null
		&& $contest['cur_progress'] > CONTEST_IN_PROGRESS && !contestKeepsResults($contest);
}
// Shows the problems of a contest that is over and was told to show them. It is done with
// the contest when none of its problems waits for something else any more.
function contestRevealProblems($contest) {
	if (!contestRevealIsDue($contest)) {
		return array();
	}
	list($shown, $waiting) = problemsReveal(contestProblemIds($contest['id']), $contest['id']);
	if (!$waiting) {
		DB::update("update contests set problems_revealed_at = now() where id = {$contest['id']} and problems_revealed_at is null");
	}
	if ($shown) {
		// nobody did this: the contest was told to, by whoever set it up
		auditLog('contest.reveal_problems', 'contest', $contest['id'], null, array('problems' => $shown, 'waiting' => $waiting), false);
	}
	return $shown;
}
// What the tick calls: every contest that may have problems to show. Returns how many
// problems were shown.
function contestsRevealDue() {
	$now = DB::escape(UOJTime::$time_now_str);
	$n = 0;
	foreach (DB::selectAll("select * from contests where reveal_problems = 1 and problems_revealed_at is null and date_add(start_time, interval last_min minute) <= '$now'") as $contest) {
		genMoreContestInfo($contest);
		$n += count(contestRevealProblems($contest));
	}
	return $n;
}

// ---- who may take part in a contest

function contestJoinModes() {
	return array(
		'open' => '自由参加：能看到这场比赛的人都可以报名',
		'list' => '名单限制：只有名单里的人能看到并报名',
		'password' => '密码限制：知道参赛密码的人才能报名'
	);
}
// the list of a contest, each line with the user it stands for if there is one yet
function contestAllowedUsers($contest) {
	$rows = DB::selectAll("select username, added_by, added_at from contest_allowed_users where contest_id = {$contest['id']} order by username");
	foreach ($rows as &$row) {
		$user = validateUsername($row['username']) ? queryUser($row['username']) : null;
		if (!$user) {
			$identity = DB::selectFirst("select username from external_identities where student_id = '".DB::escape($row['username'])."' order by id limit 1");
			$user = $identity ? queryUser($identity['username']) : null;
		}
		$row['user'] = $user ? $user['username'] : null;
	}
	unset($row);
	return $rows;
}
// Puts the lines of a text on the list of a contest: usernames and student numbers, one a
// line. Returns array(how many were added, the lines that are neither).
function contestAllowUsers($contest, $text, $actor) {
	$added = 0;
	$refused = array();
	$lines = array_slice(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $text)), 'strlen')), 0, 5000);
	foreach ($lines as $line) {
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $line)) {
			$refused[] = $line;
			continue;
		}
		// the name as the user writes it, where there is such a user
		$user = validateUsername($line) ? queryUser($line) : null;
		$entry = $user ? $user['username'] : $line;
		DB::insert("insert ignore into contest_allowed_users (contest_id, username, added_by, added_at) values ({$contest['id']}, '".DB::escape($entry)."', '".DB::escape($actor['username'])."', now())");
		$added += DB::affected_rows() == 1 ? 1 : 0;
	}
	auditLog('contest.allow_users', 'contest', $contest['id'], null, array('added' => $added, 'refused' => count($refused)), $actor);
	return array($added, $refused);
}
function contestDisallowUser($contest, $entry, $actor) {
	DB::delete("delete from contest_allowed_users where contest_id = {$contest['id']} and username = '".DB::escape($entry)."'");
	auditLog('contest.disallow_user', 'contest', $contest['id'], array('username' => $entry), null, $actor);
	return '';
}

function genMoreContestInfo(&$contest) {
	$contest['start_time_str'] = $contest['start_time'];
	$contest['start_time'] = new DateTime($contest['start_time']);
	$contest['end_time'] = clone $contest['start_time'];
	$contest['end_time']->add(new DateInterval("PT${contest['last_min']}M"));
	
	if ($contest['status'] == 'unfinished') {
		if (UOJTime::$time_now < $contest['start_time']) {
			$contest['cur_progress'] = CONTEST_NOT_STARTED;
		} elseif (UOJTime::$time_now < $contest['end_time']) {
			$contest['cur_progress'] = CONTEST_IN_PROGRESS;
		} else {
			$contest['cur_progress'] = CONTEST_PENDING_FINAL_TEST;
		}
	} elseif ($contest['status'] == 'testing') {
		$contest['cur_progress'] = CONTEST_TESTING;
	} elseif ($contest['status'] == 'finished') {
		$contest['cur_progress'] = CONTEST_FINISHED;
	}
	$contest['extra_config'] = json_decode($contest['extra_config'], true);
	
	if (!isset($contest['extra_config']['standings_version'])) {
		$contest['extra_config']['standings_version'] = 2;
	}
}

// ---- deleting a contest
//
// A contest is deleted with what is the contest's: who registered and who ran it, its
// notices and questions, its board, its files, the virtual participations in it. Its problems
// are problems of their own and stay. What was submitted to it stays as well, as what was
// submitted to its problems: it is somebody's work, and the contest was only where it was
// handed in.

// what goes with a contest when it is deleted, and what stays
function contestDeletionFacts($contest) {
	$id = (int)$contest['id'];
	return array(
		'contestants' => (int)DB::selectCount("select count(*) from contests_registrants where contest_id = $id"),
		'problems' => count(contestProblemIds($id)),
		'submissions' => (int)DB::selectCount("select count(*) from submissions where contest_id = $id"),
		'questions' => (int)DB::selectCount("select count(*) from contests_asks where contest_id = $id"),
		'attachments' => count(attachmentsOf('contest', $id))
	);
}
// what has to be typed to delete a contest: its name, as it reads
function contestDeletionWord($contest) {
	return trim(html_entity_decode(strip_tags((string)$contest['name']), ENT_QUOTES, 'UTF-8'));
}
function contestDelete($contest, $actor) {
	$id = (int)$contest['id'];
	$facts = contestDeletionFacts($contest);
	auditLog('contest.delete', 'contest', $id, array('name' => $contest['name'], 'domain_id' => empty($contest['domain_id']) ? null : (int)$contest['domain_id'], 'problem_ids' => contestProblemIds($id)) + $facts, null, $actor);
	DB::delete("delete from contests where id = $id");
	// what was submitted to it is what was submitted to its problems
	DB::update("update submissions set contest_id = null where contest_id = $id");
	DB::update("update hacks set contest_id = null where contest_id = $id");
	foreach (array('contests_registrants', 'contests_permissions', 'contests_problems', 'contests_submissions', 'contests_notice', 'contests_asks', 'contest_allowed_users', 'contest_virtuals') as $table) {
		DB::delete("delete from $table where contest_id = $id");
	}
	DB::delete("delete from click_zans where type = 'C' and target_id = $id");
	foreach (attachmentsOf('contest', $id) as $attachment) {
		attachmentDelete($attachment, $actor);
	}
	return '';
}

// ---- the contestants of a contest, as the people who run it see to them
//
// Somebody registers for a contest by themselves, before it begins or while it runs. The
// people who run it can put somebody in as well, and take somebody out, at any time: a
// contest that is held in a room has people who come late, and people who sit somewhere.

// the contestants of a contest: rows of username, seat, has_participated
function contestRegistrants($contest) {
	return DB::selectAll("select username, seat, has_participated from contests_registrants where contest_id = {$contest['id']} order by username", MYSQLI_ASSOC);
}
// what may be written down as a seat: a short word, like "A-12" or "3 排 7 座"
function contestSeatError($seat) {
	if (!is_string($seat) || mb_strlen($seat, 'UTF-8') > 20 || preg_match('/[\x00-\x1f\x7f<>"\'&]/', $seat)) {
		return '座位最多 20 个字符，不能有引号和尖括号';
	}
	return '';
}
// the user that a line of a list names: by username, or by the student number they logged in with
function contestUserOfListEntry($entry) {
	$user = validateUsername($entry) ? queryUser($entry) : null;
	if (!$user && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $entry)) {
		$identity = DB::selectFirst("select username from external_identities where student_id = '".DB::escape($entry)."' order by id limit 1");
		$user = $identity ? queryUser($identity['username']) : null;
	}
	return $user;
}
// Puts people into a contest. A line of the text names one of them, by username or student
// number, and may say where they sit: "CS26010001 A-12". Somebody who is in it already gets
// the seat that the line says, if it says one. The people who run a contest are not put in.
// Returns array(how many were put in, how many seats were changed, the lines that were refused
// as "the line: why").
function contestAddRegistrants($contest, $text, $actor) {
	$added = 0;
	$seated = 0;
	$refused = array();
	$lines = array_slice(array_unique(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string)$text)), 'strlen')), 0, 5000);
	foreach ($lines as $line) {
		$parts = preg_split('/[\s,，]+/u', $line, 2);
		$seat = isset($parts[1]) ? trim($parts[1]) : null;
		$user = contestUserOfListEntry($parts[0]);
		if (!$user) {
			$refused[] = "{$parts[0]}：没有这个用户（用学号登录过一次之后才有账号）";
			continue;
		}
		if ($seat !== null && contestSeatError($seat) !== '') {
			$refused[] = "{$parts[0]}：" . contestSeatError($seat);
			continue;
		}
		if (can($user, 'contest.assist', $contest)) {
			$refused[] = "{$parts[0]}：是这场比赛的工作人员，不参赛";
			continue;
		}
		$esc_username = DB::escape($user['username']);
		DB::insert("insert ignore into contests_registrants (username, user_rating, contest_id, has_participated, seat) values ('$esc_username', ".(int)$user['rating'].", {$contest['id']}, 0, '".DB::escape((string)$seat)."')");
		if (DB::affected_rows() == 1) {
			$added++;
		} elseif ($seat !== null) {
			DB::update("update contests_registrants set seat = '".DB::escape($seat)."' where contest_id = {$contest['id']} and username = '$esc_username' and seat != '".DB::escape($seat)."'");
			$seated += DB::affected_rows() == 1 ? 1 : 0;
		}
	}
	updateContestPlayerNum($contest);
	auditLog('contest.add_contestants', 'contest', $contest['id'], null, array('added' => $added, 'seated' => $seated, 'refused' => count($refused)), $actor);
	return array($added, $seated, $refused);
}
// Takes somebody out of a contest. What they submitted to it stays where it is, and counts
// again if they are put back in.
function contestRemoveRegistrant($contest, $username, $actor) {
	DB::delete("delete from contests_registrants where contest_id = {$contest['id']} and username = '".DB::escape($username)."'");
	if (DB::affected_rows() != 1) {
		return '这个用户没有报名这场比赛';
	}
	updateContestPlayerNum($contest);
	auditLog('contest.remove_contestant', 'contest', $contest['id'], array('username' => $username), null, $actor);
	return '';
}
function contestSetSeat($contest, $username, $seat, $actor) {
	$err = contestSeatError($seat);
	if ($err !== '') {
		return $err;
	}
	DB::update("update contests_registrants set seat = '".DB::escape(trim($seat))."' where contest_id = {$contest['id']} and username = '".DB::escape($username)."'");
	return '';
}

function updateContestPlayerNum($contest) {
	DB::update("update contests set player_num = (select count(*) from contests_registrants where contest_id = {$contest['id']}) where id = {$contest['id']}");
}

// problems: pos => id
// data    : id, submit_time, submitter, problem_pos, score
// people  : username, user_rating, nickname
function queryContestData($contest, $config = array()) {
	mergeConfig($config, [
		'pre_final' => false
	]);
	
	$problems = [];
	$prob_pos = [];
	$n_problems = 0;
	foreach (contestProblemIds($contest['id']) as $problem_id) {
		$prob_pos[$problems[] = $problem_id] = $n_problems++;
	}

	$data = [];
	if ($config['pre_final']) {
		$result = DB::query("select id, submit_time, submitter, problem_id, result from submissions"
				." where contest_id = {$contest['id']} and score is not null order by id");
		while ($row = DB::fetch($result, MYSQLI_NUM)) {
			$r = json_decode($row[4], true);
			if (!isset($r['final_result'])) {
				continue;
			}
			$row[0] = (int)$row[0];
			$row[3] = $prob_pos[$row[3]];
			$row[4] = (int)($r['final_result']['score']);
			$data[] = $row;
		}
	} else {
		if ($contest['cur_progress'] < CONTEST_FINISHED) {
			// Under the ICPC rule what waits to be judged is counted as well: it stands on the
			// board as an attempt whose outcome nobody knows. (What did not compile has no
			// score and is judged: it is not counted.)
			$unjudged = contestRule($contest) === 'ICPC' ? " or status not like 'Judged%'" : '';
			$result = DB::query("select id, submit_time, submitter, problem_id, score from submissions"
				." where contest_id = {$contest['id']} and (score is not null$unjudged) order by id");
		} else {
			$result = DB::query("select submission_id, date_add('{$contest['start_time_str']}', interval penalty second),"
				." submitter, problem_id, score, attempts from contests_submissions where contest_id = {$contest['id']}");
		}
		while ($row = DB::fetch($result, MYSQLI_NUM)) {
			$row[0] = (int)$row[0];
			$row[3] = $prob_pos[$row[3]];
			$row[4] = $row[4] === null ? null : (int)$row[4];
			$data[] = $row;
		}
	}

	$people = [];
	$result = DB::query("select contests_registrants.username, user_rating, ifnull(user_info.nickname, '') from contests_registrants left join user_info on user_info.username = contests_registrants.username where contest_id = {$contest['id']} and has_participated = 1");
	while ($row = DB::fetch($result, MYSQLI_NUM)) {
		$row[1] = (int)$row[1];
		$people[] = $row;
	}

	// 'final': the rows are what counted in the end, one for a problem somebody tried
	return ['problems' => $problems, 'data' => $data, 'people' => $people, 'final' => !$config['pre_final'] && $contest['cur_progress'] >= CONTEST_FINISHED];
}

// $freeze_offset: the standings as the people see them who are kept from what was submitted
// after so many seconds of the contest, see contestCells()
function calcStandings($contest, $contest_data, &$score, &$standings, $update_contests_submissions = false, $freeze_offset = null) {
	// score: username, problem_pos => score, penalty, id, and under the ICPC rule failed attempts, attempts that are not judged
	$rule = contestRule($contest);
	$n_people = count($contest_data['people']);
	$n_problems = count($contest_data['problems']);
	$rows = array();
	$cells = array();
	foreach ($contest_data['data'] as $submission) {
		$offset = (new DateTime($submission[1]))->getTimestamp() - $contest['start_time']->getTimestamp();
		if (!empty($contest_data['final']) && $rule === 'ICPC') {
			// what counted in the end: the penalty is the offset, and the attempts were kept
			$cells[$submission[2]][$submission[3]] = array($submission[4], $offset, $submission[0], isset($submission[5]) ? (int)$submission[5] : 0, 0);
		} else {
			$rows[] = array($submission[0], $offset, $submission[2], $submission[3], $submission[4]);
		}
	}
	$cells += contestCells($rule, $contest['extra_config']['standings_version'], $rows, $freeze_offset);
	$score = array();
	foreach ($contest_data['people'] as $person) {
		$score[$person[0]] = isset($cells[$person[0]]) ? $cells[$person[0]] : array();
	}

	// standings: rank => score, penalty, [username, user_rating, nickname], virtual_rank
	$standings = array();
	foreach ($contest_data['people'] as $person) {
		$cur = array(0, 0, $person);
		for ($i = 0; $i < $n_problems; $i++) {
			if (isset($score[$person[0]][$i])) {
				$cur_row = $score[$person[0]][$i];
				$cur[0] += $cur_row[0];
				$cur[1] += $cur_row[1];
				if ($update_contests_submissions) {
					$attempts = isset($cur_row[3]) ? (int)$cur_row[3] : 0;
					DB::insert("insert into contests_submissions (contest_id, submitter, problem_id, submission_id, score, penalty, attempts) values ({$contest['id']}, '{$person[0]}', {$contest_data['problems'][$i]}, {$cur_row[2]}, {$cur_row[0]}, {$cur_row[1]}, $attempts)");
				}
			}
		}
		$standings[] = $cur;
	}

	usort($standings, function($lhs, $rhs) {
		if ($lhs[0] != $rhs[0]) {
			return $rhs[0] - $lhs[0];
		} elseif ($lhs[1] != $rhs[1]) {
			return $lhs[1] - $rhs[1];
		} else {
			return strcmp($lhs[2][0], $rhs[2][0]);
		}
	});

	$is_same_rank = function($lhs, $rhs) {
		return $lhs[0] == $rhs[0] && $lhs[1] == $rhs[1];
	};

	for ($i = 0; $i < $n_people; $i++) {
		if ($i == 0 || !$is_same_rank($standings[$i - 1], $standings[$i])) {
			$standings[$i][] = $i + 1;
		} else {
			$standings[$i][] = $standings[$i - 1][3];
		}
	}
}
