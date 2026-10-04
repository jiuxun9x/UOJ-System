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

// Creates a contest that belongs to the user who creates it, and returns its id. A contest of
// a domain is run by the people who teach in the domain. The ratings belong to the whole
// site: a contest only counts for them when an administrator created it for the whole site,
// or says so later.
function contestCreate($name, $start_time_str, $last_min, $actor, $domain = null) {
	$esc_name = DB::escape(HTML::pruifier()->purify($name));
	$rated = $domain === null && can($actor, 'contest.rate');
	$esc_extra_config = DB::escape(json_encode($rated ? new stdClass() : array('unrated' => '')));
	$domain_id = $domain === null ? 'null' : (int)$domain['id'];
	DB::insert("insert into contests (name, start_time, last_min, status, extra_config, domain_id) values ('$esc_name', '".DB::escape($start_time_str)."', ".(int)$last_min.", 'unfinished', '$esc_extra_config', $domain_id)");
	$contest_id = DB::insert_id();
	DB::insert("insert into contests_permissions (username, contest_id, role) values ('".DB::escape($actor['username'])."', $contest_id, 'owner')");
	auditLog('contest.create', 'contest', $contest_id, null, array('name' => $name, 'start_time' => $start_time_str, 'last_min' => (int)$last_min) + ($domain === null ? array() : array('domain_id' => (int)$domain['id'])), $actor);
	return $contest_id;
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

// ---- who may take part in a contest

function contestJoinModes() {
	return array(
		'open' => '自由参加：能看到这场比赛的人都可以报名',
		'list' => '名单限制：只有名单里的人能看到并报名',
		'password' => '密码限制：知道参赛密码的人才能报名'
	);
}
// Sets who may take part. $password is the new password of a contest that asks for one; ''
// keeps the one it has. Returns '' or why it was refused.
function contestSetJoinMode($contest, $mode, $password, $actor) {
	if (!isset(contestJoinModes()[$mode])) {
		return '无效的参加方式';
	}
	$set = "join_mode = '$mode'";
	if ($mode === 'password') {
		if (!is_string($password) || ($password === '' && $contest['join_password'] === '')) {
			return '请设置参赛密码';
		}
		if ($password !== '') {
			if (strlen($password) < 4 || strlen($password) > 64 || preg_match('/[\x00-\x1f\x7f]/', $password)) {
				return '参赛密码应为 4 到 64 个字符';
			}
			$set .= ", join_password = '".DB::escape(password_hash($password, PASSWORD_BCRYPT))."'";
		}
	}
	DB::update("update contests set $set where id = {$contest['id']}");
	// the password is not written down where changes are
	auditLog('contest.edit_access', 'contest', $contest['id'], array('join_mode' => $contest['join_mode']), array('join_mode' => $mode, 'password_changed' => $mode === 'password' && $password !== ''), $actor);
	return '';
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
	$result = DB::query("select problem_id from contests_problems where contest_id = {$contest['id']} order by problem_id");
	while ($row = DB::fetch($result, MYSQLI_NUM)) {
		$prob_pos[$problems[] = (int)$row[0]] = $n_problems++;
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
			$result = DB::query("select id, submit_time, submitter, problem_id, score from submissions"
				." where contest_id = {$contest['id']} and score is not null order by id");
		} else {
			$result = DB::query("select submission_id, date_add('{$contest['start_time_str']}', interval penalty second),"
				." submitter, problem_id, score from contests_submissions where contest_id = {$contest['id']}");
		}
		while ($row = DB::fetch($result, MYSQLI_NUM)) {
			$row[0] = (int)$row[0];
			$row[3] = $prob_pos[$row[3]];
			$row[4] = (int)$row[4];
			$data[] = $row;
		}
	}

	$people = [];
	$result = DB::query("select contests_registrants.username, user_rating, ifnull(user_info.nickname, '') from contests_registrants left join user_info on user_info.username = contests_registrants.username where contest_id = {$contest['id']} and has_participated = 1");
	while ($row = DB::fetch($result, MYSQLI_NUM)) {
		$row[1] = (int)$row[1];
		$people[] = $row;
	}

	return ['problems' => $problems, 'data' => $data, 'people' => $people];
}

function calcStandings($contest, $contest_data, &$score, &$standings, $update_contests_submissions = false) {
	// score: username, problem_pos => score, penalty, id
	$score = array();
	$n_people = count($contest_data['people']);
	$n_problems = count($contest_data['problems']);
	foreach ($contest_data['people'] as $person) {
		$score[$person[0]] = array();
	}
	foreach ($contest_data['data'] as $submission) {
		$penalty = (new DateTime($submission[1]))->getTimestamp() - $contest['start_time']->getTimestamp();
		if ($contest['extra_config']['standings_version'] >= 2) {
			if ($submission[4] == 0) {
				$penalty = 0;
			}
		}
		$score[$submission[2]][$submission[3]] = array($submission[4], $penalty, $submission[0]);
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
					DB::insert("insert into contests_submissions (contest_id, submitter, problem_id, submission_id, score, penalty) values ({$contest['id']}, '{$person[0]}', {$contest_data['problems'][$i]}, {$cur_row[2]}, {$cur_row[0]}, {$cur_row[1]})");
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
