<?php
	requirePHPLib('judger');
	requirePHPLib('data');
	
	requireJudgerAuthentication();
	
	// how many judgers in a row may lose a task before it is failed
	define('JUDGE_MAX_ATTEMPTS', 3);
	
	function judgerName() {
		global $uojJudger;
		return $uojJudger['judger_name'];
	}
	// The worker of the judging account that asks (judge protocol 3): several may work for one
	// account, each under a name of its own that it keeps. null when it says none.
	function judgeWorker() {
		return isset($_POST['worker']) && is_string($_POST['worker']) && preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $_POST['worker']) ? $_POST['worker'] : null;
	}
	// the claim on a task that the request reports on, or null
	function postedClaim() {
		return isset($_POST['claim']) && is_string($_POST['claim']) && preg_match('/^[0-9a-f]{32}$/D', $_POST['claim']) ? $_POST['claim'] : null;
	}
	// how a row is told to have a claim: one that has none is one of before the claims
	function claimIs($claim) {
		return $claim === null ? 'judge_claim is null' : "judge_claim = '$claim'";
	}
	// A claim lasts as long as its lease, which the worker renews whenever it reports, at least
	// every few seconds; a worker not heard of for judger.task-timeout seconds has lost it.
	function leaseUntil() {
		$timeout = isset(UOJConfig::$data['judger']['task-timeout']) ? (int)UOJConfig::$data['judger']['task-timeout'] : 300;
		return "now() + interval $timeout second";
	}
	// what a task that is no longer given out keeps of its claim: nothing
	define('CLAIM_DROPPED', 'judge_claim = null, judge_worker = null, judge_lease_until = null');
	// the tables of what is given out, under the kinds the judgers name them by
	function claimTables() {
		return array('submission' => 'submissions', 'custom_test' => 'custom_test_submissions', 'hack' => 'hacks', 'prepare' => 'problem_data_versions', 'answers' => 'problem_answer_jobs');
	}
	function renewLease($claim) {
		foreach (claimTables() as $table) {
			DB::update("update $table set judge_lease_until = ".leaseUntil()." where judge_claim = '$claim'");
		}
	}
	function claimIsHeld($claim) {
		foreach (claimTables() as $table) {
			if (DB::selectFirst("select 1 from $table where judge_claim = '$claim'") != null) {
				return true;
			}
		}
		return false;
	}
	
	// The result is sent by a judger, which runs the code of problem setters, so every field is
	// brought into the expected type before it is used in a query.
	function getPostedJudgementResult() {
		$result = isset($_POST['result']) ? json_decode($_POST['result'], true) : null;
		if (!is_array($result)) {
			$result = array('error' => 'Judgement Failed', 'details' => '<error>The judger sent an invalid result.</error>');
		}
		$result['status'] = 'Judged';
		$result['score'] = isset($result['score']) && is_numeric($result['score']) ? $result['score'] + 0 : 0;
		$result['time'] = isset($result['time']) && is_numeric($result['time']) ? (int)$result['time'] : 0;
		$result['memory'] = isset($result['memory']) && is_numeric($result['memory']) ? (int)$result['memory'] : 0;
		$result['details'] = uojTextEncode(isset($result['details']) && is_string($result['details']) ? $result['details'] : '');
		if (isset($result['error'])) {
			$result['error'] = is_string($result['error']) ? $result['error'] : 'Judgement Failed';
		}
		return $result;
	}
	
	// Runs the update that stores a judgement result. $make_query builds it from the escaped JSON
	// of the result. When the database refuses the update, which happens when the result is
	// larger than max_allowed_packet, the details are dropped and the update is run again, so
	// that the submission does not stay in the "Judging" state forever.
	// Returns whether the result was stored this time: the query stores it only while the task
	// has the claim it was judged under.
	function storeJudgementResult($result, $make_query) {
		$esc_result = DB::escape(json_encode($result, JSON_UNESCAPED_UNICODE));
		if (DB::update($make_query($esc_result))) {
			return DB::affected_rows() > 0;
		}
		error_log("judge/submit: failed to store a result of " . strlen($esc_result) . " bytes: " . DB::error());
		
		// the server closes the connection when it receives a packet that is too large
		DB::init();
		$msg = "<error>The details of this judgement are too large to be stored.</error>";
		if (isset($result['final_result'])) {
			$result['final_result']['details'] = $msg;
		}
		$result['details'] = $msg;
		$esc_result = DB::escape(json_encode($result, JSON_UNESCAPED_UNICODE));
		return DB::update($make_query($esc_result)) && DB::affected_rows() > 0;
	}
	
	// the result of a task that no judger managed to judge
	function lostTaskResult() {
		return array(
			'status' => 'Judged', 'score' => 0, 'time' => 0, 'memory' => 0, 'error' => 'Judgement Failed',
			'details' => '<error>The judgers stopped responding or were overloaded while judging this ' . JUDGE_MAX_ATTEMPTS . ' times in a row.</error>'
		);
	}
	
	// ---- the record of who judged what, with which data and which tools
	
	function startJudgement($kind, $target_id, $problem_id, $claim) {
		global $uojJudger, $worker;
		$version_row = dataCurrentVersion(array('id' => $problem_id));
		$version = $version_row ? $version_row['version'] : 'null';
		$sha256 = $version_row ? "'{$version_row['sha256']}'" : 'null';
		$esc_judger = DB::escape(judgerName());
		$esc_version = DB::escape($uojJudger['version']);
		$esc_toolchain = DB::escape($uojJudger['toolchain']);
		// a judgement that was still open is over: the task was put back in the queue meanwhile
		DB::update("update submission_judgements set finished_at = now(), outcome = 'superseded' where kind = '$kind' and target_id = $target_id and finished_at is null");
		DB::insert("insert into submission_judgements (kind, target_id, problem_id, judger_name, worker, problem_data_version, problem_data_sha256, judger_version, toolchain, started_at, claim) values ('$kind', $target_id, $problem_id, '$esc_judger', '$worker', $version, $sha256, '$esc_version', '$esc_toolchain', now(), '$claim')");
		return $version_row;
	}
	function finishJudgement($kind, $target_id, $outcome, $claim, $score = null) {
		$set = "finished_at = now(), outcome = '$outcome'";
		if ($score !== null) {
			$set .= ", score = " . judgedScore($score);
		}
		// the judger reports the data it really used
		if ($outcome == 'judged' && isset($_POST['problem_data_version']) && validateUInt($_POST['problem_data_version'])
				&& isset($_POST['problem_data_sha256']) && preg_match('/^[0-9a-f]{64}$/', $_POST['problem_data_sha256'])) {
			$set .= ", problem_data_version = {$_POST['problem_data_version']}, problem_data_sha256 = '{$_POST['problem_data_sha256']}'";
		}
		DB::update("update submission_judgements set $set where kind = '$kind' and target_id = $target_id and finished_at is null and claim <=> ".($claim === null ? 'null' : "'$claim'"));
	}
	
	// ---- results
	
	// Stores the result of a submission, whichever of its two tests in a contest it comes from,
	// while it has the claim it was judged under. Returns whether it was stored.
	function storeSubmissionResult($id, $submission, $result) {
		$held = "id = $id and ".claimIs($submission['judge_claim']);
		$content = json_decode($submission['content'], true);
		
		if (isset($content['first_test_config'])) {
			$final_result = $result;
			$result = json_decode($submission['result'], true);
			$result['final_result'] = $final_result;
			
			$content['final_test_config'] = $content['config'];
			$content['config'] = $content['first_test_config'];
			unset($content['first_test_config']);
			$esc_content = DB::escape(json_encode($content));
			
			if (!storeJudgementResult($result, function($esc_result) use ($esc_content, $held) {
				return "update submissions set status = 'Judged', judge_attempts = 0, result = '$esc_result', content = '$esc_content', ".CLAIM_DROPPED." where $held";
			})) {
				return false;
			}
		} else {
			// The first of two tests: its result is stored, and the submission waits for the
			// second, in one statement under the claim. Set apart, a rejudge asked for between
			// them was overwritten, and the second test was judged on a first that was gone.
			$next = "status = 'Judged'";
			if (isset($content['final_test_config'])) {
				$content['first_test_config'] = $content['config'];
				$content['config'] = $content['final_test_config'];
				unset($content['final_test_config']);
				$next = "status = 'Judged, Waiting', content = '" . DB::escape(json_encode($content)) . "'";
			}
			if (!storeJudgementResult($result, function($esc_result) use ($result, $held, $next) {
				if (isset($result["error"])) {
					$esc_error = DB::escape($result['error']);
					return "update submissions set $next, judge_attempts = 0, result_error = '$esc_error', result = '$esc_result', score = null, used_time = null, used_memory = null, ".CLAIM_DROPPED." where $held";
				} else {
					$score = judgedScore($result['score']);
					return "update submissions set $next, judge_attempts = 0, result_error = null, result = '$esc_result', score = $score, used_time = {$result['time']}, used_memory = {$result['memory']}, ".CLAIM_DROPPED." where $held";
				}
			})) {
				return false;
			}
		}
		DB::update("update submissions set status_details = '' where id = $id");
		updateBestACSubmissions($submission['submitter'], $submission['problem_id']);
		return true;
	}
	function storeCustomTestResult($id, $submission, $result) {
		$held = "id = $id and ".claimIs($submission['judge_claim']);
		if (!storeJudgementResult($result, function($esc_result) use ($held) {
			return "update custom_test_submissions set status = 'Judged', judge_attempts = 0, result = '$esc_result', ".CLAIM_DROPPED." where $held";
		})) {
			return false;
		}
		DB::update("update custom_test_submissions set status_details = '' where id = $id");
		return true;
	}
	// Returns whether this call is the one that set the result of the hack. A judgement that
	// failed (the result has an error) is no success: the hack says why (result_error).
	function storeHackResult($id, $hack, $result) {
		$held = "id = $id and success is null and ".claimIs($hack['judge_claim']);
		$failed = isset($result['error']);
		$success = !$failed && $result['score'] ? 1 : 0;
		$error = $failed ? "'" . DB::escape(mb_substr($result['error'], 0, 100, 'UTF-8')) . "'" : 'null';
		$esc_details = DB::escape($result['details']);
		$ok = DB::update("update hacks set success = $success, result_error = $error, judge_attempts = 0, details = '$esc_details', ".CLAIM_DROPPED." where $held");
		if (!$ok) {
			error_log("judge/submit: failed to store hack details of " . strlen($esc_details) . " bytes: " . DB::error());
			DB::init();
			$esc_details = DB::escape("<error>The details of this judgement are too large to be stored.</error>");
			$ok = DB::update("update hacks set success = $success, result_error = $error, judge_attempts = 0, details = '$esc_details', ".CLAIM_DROPPED." where $held");
		}
		// a judger sends a result again when it gets no answer: it only counts once
		return $ok && DB::affected_rows() == 1;
	}
	
	// What is being judged under a claim: what a judger says of a task counts while the task has
	// the claim the judger was given it under, and not after it was taken back or given out anew.
	function querySubmissionBeingJudged($id, $claim) {
		return DB::selectFirst("select id, submitter, status, content, result, problem_id, contest_id, judger_name, judge_attempts, judge_claim from submissions where id = $id and judge_claim = '$claim' and status in ('Judging', 'Judged, Judging')");
	}
	function queryCustomTestBeingJudged($id, $claim) {
		return DB::selectFirst("select id, problem_id, status, judger_name, judge_attempts, judge_claim from custom_test_submissions where id = $id and judge_claim = '$claim' and status = 'Judging'");
	}
	function queryHackBeingJudged($id, $claim) {
		return DB::selectFirst("select id, problem_id, input, judger_name, judge_attempts, judge_claim from hacks where id = $id and judge_claim = '$claim' and success is null and judge_time is not null");
	}
	
	function submissionJudged($id, $claim) {
		$submission = querySubmissionBeingJudged($id, $claim);
		if ($submission == null) {
			return;
		}
		$result = getPostedJudgementResult();
		if (storeSubmissionResult($id, $submission, $result)) {
			finishJudgement('submission', $id, 'judged', $claim, isset($result['error']) ? null : $result['score']);
			// the board of its contest may wait for this to be judged
			if ($submission['contest_id']) {
				contestUpdateStandingsIfDue($submission['contest_id']);
			}
		}
	}

	function customTestSubmissionJudged($id, $claim) {
		$submission = queryCustomTestBeingJudged($id, $claim);
		if ($submission == null) {
			return;
		}
		if (storeCustomTestResult($id, $submission, getPostedJudgementResult())) {
			finishJudgement('custom_test', $id, 'judged', $claim);
		}
	}
	
	function hackJudged($id, $claim) {
		$hack = queryHackBeingJudged($id, $claim);
		if ($hack == null) {
			return;
		}
		$result = getPostedJudgementResult();
		if (!storeHackResult($id, $hack, $result)) {
			return;
		}
		// A judgement that failed went wrong on the side of the problem (its checker, say): the
		// hack neither succeeded nor failed, nothing is added to the data, and its input is kept
		// to be judged again once the problem is put right (J03: it was a success).
		if (isset($result['error'])) {
			finishJudgement('hack', $id, 'judged', $claim);
			return;
		}
		finishJudgement('hack', $id, 'judged', $claim, $result['score'] ? 1 : 0);

		// The input of a hack that succeeded is kept until its test is added to the data: where
		// that fails, the hack is judged again from it, once the data is put right (see hack.php).
		$err = '';
		if ($result['score']) {
			$problem = queryProblemBrief($hack['problem_id']);
			if (validateUploadedFile('hack_input') && validateUploadedFile('std_output')) {
				$err = dataAddExtraTest($problem, $_FILES["hack_input"]["tmp_name"], $_FILES["std_output"]["tmp_name"], $id);
			} else {
				$err = 'the judger sent no data';
			}
			if ($err !== '') {
				error_log("hack #$id succeeded but its extra test was not added: $err");
				notifyHackNotApplied($problem, $id, $err);
			}
		}
		if ($err === '') {
			@unlink(UOJContext::storagePath().$hack['input']);
		}
	}
	
	// ---- tasks that their judger does not judge any more
	//
	// Such a task goes back to the queue. When several judgers in a row lost it, the task itself
	// is the likely reason, and it is failed instead of taking down one judger after another.
	
	// ---- the memory of the judgers
	//
	// A judger that has a memory limit says with every asking for work how much memory the
	// programs of a problem may need for it to judge them, and gives back a problem it can not
	// judge, saying how much that one needs (reason=memory): see judge_client. The problem keeps
	// that for the version of its data; while a judger that can judge it was heard of lately, it
	// is not given to judgers with less, and giving it back is no failed attempt. With none around
	// it is failed at once, saying why, rather than waiting for a judger nobody has.

	// What the judgers heard of in the last two minutes can judge, of the accounts that are on:
	// array('unlimited' => whether one has no limit or does not say, 'most' => the largest limit
	// one says, or null). (The workers and the accounts are read apart: their names are kept in
	// different collations.)
	function judgingMemoryAround() {
		$enabled = array();
		foreach (DB::selectAll("select judger_name from judger_info where enabled = 1") as $row) {
			$enabled[(string)$row['judger_name']] = true;
		}
		$around = array('unlimited' => false, 'most' => null);
		foreach (DB::selectAll("select judger_name, memory_mb from judger_workers where last_seen_at > now() - interval 120 second") as $row) {
			if (!isset($enabled[(string)$row['judger_name']])) {
				continue;
			}
			if ($row['memory_mb'] === null) {
				$around['unlimited'] = true;
			} else {
				$around['most'] = max((int)$around['most'], (int)$row['memory_mb']);
			}
		}
		return $around;
	}
	function judgerAroundFor($needed, $around) {
		return $around['unlimited'] || ($around['most'] !== null && $around['most'] >= $needed);
	}
	// what a task was given back for want of memory says: array('needed', 'version'), or null
	function givenBackForMemory() {
		if (!isset($_POST['reason']) || $_POST['reason'] !== 'memory') {
			return null;
		}
		foreach (array('needed_mb', 'problem_data_version') as $field) {
			if (!isset($_POST[$field]) || !is_string($_POST[$field]) || !validateUInt($_POST[$field]) || strlen($_POST[$field]) > 9) {
				return null;
			}
		}
		return array('needed' => (int)$_POST['needed_mb'], 'version' => (int)$_POST['problem_data_version']);
	}
	// The problem of a task given back for want of memory keeps what it needs. Returns null where
	// a judger that can judge it is around, or else the result the task fails with.
	function memoryShortage($problem_id, $memory) {
		DB::update("update problems set judge_memory_mb = {$memory['needed']}, judge_memory_version = {$memory['version']} where id = " . (int)$problem_id);
		$around = judgingMemoryAround();
		if (judgerAroundFor($memory['needed'], $around)) {
			return null;
		}
		return array(
			'status' => 'Judged', 'score' => 0, 'time' => 0, 'memory' => 0, 'error' => 'Judgement Failed',
			'details' => '<error>没有内存足够的评测机：这道题允许 ' . $memory['needed'] . ' MB，在线的评测机最多能评 ' . (int)$around['most'] . ' MB 的题。</error>'
		);
	}
	// The problems the worker that asks is not given, as a condition on problem_id ('' for none):
	// those it is known to have too little memory for, while a judger that has enough is around.
	function memoryExclusion() {
		global $memory_mb;
		if ($memory_mb === null) {
			return '';
		}
		$around = judgingMemoryAround();
		$ids = array();
		foreach (DB::selectAll("select id, judge_memory_mb from problems where judge_memory_version = data_version and judge_memory_mb > $memory_mb") as $row) {
			if (judgerAroundFor((int)$row['judge_memory_mb'], $around)) {
				$ids[] = (int)$row['id'];
			}
		}
		return $ids ? ' and problem_id not in (' . join(', ', $ids) . ')' : '';
	}

	// Each takes a task back under the claim it has, and only while it has it. $memory: what the
	// judger said when it gave the task back for want of memory, see givenBackForMemory().
	function releaseSubmission($submission, $memory = null) {
		$id = $submission['id'];
		$claim = $submission['judge_claim'];
		if ($memory !== null) {
			$failed = memoryShortage($submission['problem_id'], $memory);
		} else {
			$failed = $submission['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS ? lostTaskResult() : null;
		}
		if ($failed !== null) {
			if (storeSubmissionResult($id, $submission, $failed)) {
				finishJudgement('submission', $id, 'failed', $claim);
			}
		} elseif (DB::update("update submissions set judge_time = if(status = 'Judging', null, judge_time), status = if(status = 'Judging', 'Waiting', 'Judged, Waiting'), status_details = '', judger_name = null, judge_attempts = judge_attempts + " . ($memory === null ? 1 : 0) . ", " . CLAIM_DROPPED . " where id = $id and " . claimIs($claim) . " and status in ('Judging', 'Judged, Judging')") && DB::affected_rows() == 1) {
			finishJudgement('submission', $id, $memory === null ? 'reclaimed' : 'memory', $claim);
		}
	}
	function releaseCustomTest($submission, $memory = null) {
		$id = $submission['id'];
		$claim = $submission['judge_claim'];
		if ($memory !== null) {
			$failed = memoryShortage($submission['problem_id'], $memory);
		} else {
			$failed = $submission['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS ? lostTaskResult() : null;
		}
		if ($failed !== null) {
			if (storeCustomTestResult($id, $submission, $failed)) {
				finishJudgement('custom_test', $id, 'failed', $claim);
			}
		} elseif (DB::update("update custom_test_submissions set judge_time = null, status = 'Waiting', status_details = '', judger_name = null, judge_attempts = judge_attempts + " . ($memory === null ? 1 : 0) . ", " . CLAIM_DROPPED . " where id = $id and " . claimIs($claim) . " and status = 'Judging'") && DB::affected_rows() == 1) {
			finishJudgement('custom_test', $id, $memory === null ? 'reclaimed' : 'memory', $claim);
		}
	}
	function releaseHack($hack, $memory = null) {
		$id = $hack['id'];
		$claim = $hack['judge_claim'];
		if ($memory !== null) {
			$failed = memoryShortage($hack['problem_id'], $memory);
		} else {
			$failed = $hack['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS ? lostTaskResult() : null;
		}
		if ($failed !== null) {
			if (storeHackResult($id, $hack, $failed)) {
				finishJudgement('hack', $id, 'failed', $claim);
			}
		} elseif (DB::update("update hacks set judge_time = null, judger_name = null, judge_attempts = judge_attempts + " . ($memory === null ? 1 : 0) . ", " . CLAIM_DROPPED . " where id = $id and " . claimIs($claim) . " and success is null") && DB::affected_rows() == 1) {
			finishJudgement('hack', $id, $memory === null ? 'reclaimed' : 'memory', $claim);
		}
	}
	// a job of making answers that was not done goes back to the queue, a few times
	function releaseAnswerJob($job) {
		if ($job['attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			dataFinishAnswerJob($job, false, '评测机多次没有做完：标准程序可能太慢，或者评测机出了问题');
		} else {
			DB::update("update problem_answer_jobs set status = 'pending', judger_name = null, attempts = attempts + 1, ".CLAIM_DROPPED." where id = {$job['id']} and ".claimIs($job['judge_claim'])." and status = 'running'");
		}
	}
	function releaseVersion($version_row, $why = 'the judgers stopped responding while building the programs of the problem') {
		if ($version_row['attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			dataFailVersion($version_row, $why);
		} else {
			DB::update("update problem_data_versions set status = 'pending', judger_name = null, attempts = attempts + 1, ".CLAIM_DROPPED." where id = {$version_row['id']} and ".claimIs($version_row['judge_claim'])." and status = 'preparing'");
		}
	}
	
	// Takes a task that was found lost from its worker, in one statement that finds it lost
	// still: the worker may have renewed its lease since it was looked at, and keeps it then.
	// The task is given a claim of the taking, which nothing the worker sends of it any more
	// has, and is released under it. Returns the row with that claim, or null.
	function takeLostTask($kind, $table, $row, $lost) {
		$taken = bin2hex(random_bytes(16));
		if (!DB::update("update $table set judge_claim = '$taken' where id = {$row['id']} and " . claimIs($row['judge_claim']) . " and $lost") || DB::affected_rows() != 1) {
			return null;
		}
		if ($kind !== null) {
			DB::update("update submission_judgements set claim = '$taken' where kind = '$kind' and target_id = {$row['id']} and finished_at is null and claim <=> " . ($row['judge_claim'] === null ? 'null' : "'{$row['judge_claim']}'"));
		}
		$row['judge_claim'] = $taken;
		return $row;
	}
	// Takes back what is not being worked on: a task whose lease ran out (one given out before
	// the claims has none), and what this worker had, which asks for another task. What the
	// other workers of the same account have is theirs.
	function reclaimLostTasks($worker) {
		$lost = "(judge_lease_until is null or judge_lease_until < now() or judger_name = '".DB::escape(judgerName())."' and judge_worker = '".DB::escape($worker)."')";
		foreach (DB::selectAll("select id, submitter, status, content, result, problem_id, judger_name, judge_attempts, judge_claim from submissions where status in ('Judging', 'Judged, Judging') and $lost") as $submission) {
			if ($submission = takeLostTask('submission', 'submissions', $submission, $lost)) {
				releaseSubmission($submission);
			}
		}
		foreach (DB::selectAll("select id, judge_attempts, judge_claim from custom_test_submissions where status = 'Judging' and $lost") as $submission) {
			if ($submission = takeLostTask('custom_test', 'custom_test_submissions', $submission, $lost)) {
				releaseCustomTest($submission);
			}
		}
		foreach (DB::selectAll("select id, judge_attempts, judge_claim from hacks where success is null and judge_time is not null and $lost") as $hack) {
			if ($hack = takeLostTask('hack', 'hacks', $hack, $lost)) {
				releaseHack($hack);
			}
		}
		foreach (DB::selectAll("select * from problem_data_versions where status = 'preparing' and $lost") as $version_row) {
			if ($version_row = takeLostTask(null, 'problem_data_versions', $version_row, $lost)) {
				releaseVersion($version_row);
			}
		}
		foreach (DB::selectAll("select * from problem_answer_jobs where status = 'running' and $lost") as $job) {
			if ($job = takeLostTask(null, 'problem_answer_jobs', $job, $lost)) {
				releaseAnswerJob($job);
			}
		}
	}
	
	// ---- what a judger reports
	
	// A worker says which it is, and every report says which claim it is about: one that says
	// neither is of a judger of before protocol 3, and is not listened to.
	$worker = judgeWorker();
	if ($worker !== null) {
		DB::insert("insert into judger_workers (judger_name, worker, first_seen_at, last_seen_at) values ('".DB::escape(judgerName())."', '$worker', now(), now()) on duplicate key update last_seen_at = if(last_seen_at < now() - interval 3 second, now(), last_seen_at)");
	}
	$claim = postedClaim();

	if ((isset($_POST['submit']) || isset($_POST['requeue'])) && $claim !== null) {
		if (!validateUInt($_POST['id'])) {
			die("Wow! hacker! T_T....");
		}
		$id = $_POST['id'];
		if (isset($_POST['requeue'])) {
			// the judger could not judge the task: it was overloaded, or had too little memory
			$memory = givenBackForMemory();
			if (isset($_POST['is_hack'])) {
				$hack = queryHackBeingJudged($id, $claim);
				if ($hack != null) {
					releaseHack($hack, $memory);
				}
			} elseif (isset($_POST['is_custom_test'])) {
				$submission = queryCustomTestBeingJudged($id, $claim);
				if ($submission != null) {
					releaseCustomTest($submission, $memory);
				}
			} else {
				$submission = querySubmissionBeingJudged($id, $claim);
				if ($submission != null) {
					releaseSubmission($submission, $memory);
				}
			}
		} elseif (isset($_POST['is_hack'])) {
			hackJudged($id, $claim);
		} elseif (isset($_POST['is_custom_test'])) {
			customTestSubmissionJudged($id, $claim);
		} else {
			submissionJudged($id, $claim);
		}
	}
	if (isset($_POST['prepare_result']) && $claim !== null) {
		if (!validateUInt($_POST['id'])) {
			die("Wow! hacker! T_T....");
		}
		$version_row = queryProblemDataVersionById($_POST['id']);
		if ($version_row != null && $version_row['status'] == 'preparing' && $version_row['judge_claim'] === $claim) {
			if (isset($_POST['retry']) && $_POST['retry'] === '1') {
				// the judger could not fetch the data, or went wrong itself: another one tries,
				// and what it said is why, where they all gave up
				$message = isset($_POST['message']) && is_string($_POST['message']) ? $_POST['message'] : '';
				releaseVersion($version_row, 'the judgers could not build the data, the last one said: ' . uojTextEncode(substr($message, 0, 20000)));
			} elseif (isset($_POST['ok']) && $_POST['ok'] === '1') {
				dataPublishVersion($version_row);
			} else {
				$message = isset($_POST['message']) && is_string($_POST['message']) ? $_POST['message'] : '';
				dataFailVersion($version_row, uojTextEncode(substr($message, 0, 20000)));
			}
		}
	}
	// What the standard program wrote on the inputs of a job, or why it could not: the judger
	// says which tests failed, and how, which is said here in words
	if (isset($_POST['answers_result']) && $claim !== null) {
		if (!validateUInt($_POST['id'])) {
			die("Wow! hacker! T_T....");
		}
		$job = DB::selectFirst("select * from problem_answer_jobs where id = {$_POST['id']} and status = 'running' and judge_claim = '$claim'");
		if ($job != null) {
			if (isset($_POST['retry']) && $_POST['retry'] === '1') {
				// not the program's doing: another judger tries
				releaseAnswerJob($job);
			} elseif (isset($_POST['ok']) && $_POST['ok'] === '1' && isset($_FILES['answers']) && is_string($_FILES['answers']['tmp_name']) && is_uploaded_file($_FILES['answers']['tmp_name'])) {
				dataFinishAnswerJob($job, true, '', $_FILES['answers']['tmp_name']);
			} else {
				dataFinishAnswerJob($job, false, answerJobFailure($job, isset($_POST['message']) && is_string($_POST['message']) ? $_POST['message'] : ''));
			}
		}
	}
	// The progress of a task is shown only while the task has the claim it is reported under,
	// and renews its lease, as a sign of life with the claim does.
	if (isset($_POST['update-status'])) {
		if ($claim !== null && isset($_POST['status']) && is_string($_POST['status'])) {
			$esc_status_details = DB::escape(mb_strcut($_POST['status'], 0, 100, 'UTF-8'));
			$table = isset($_POST['is_custom_test']) ? 'custom_test_submissions' : 'submissions';
			DB::update("update $table set status_details = '$esc_status_details', judge_lease_until = ".leaseUntil()." where judge_claim = '$claim' and status in ('Judging', 'Judged, Judging')");
		}
		die();
	}
	if (isset($_POST['heartbeat'])) {
		// the authentication has already noted that the account is alive
		if ($claim !== null) {
			renewLease($claim);
		}
		die();
	}
	// A judger that has nothing to judge asks which data the problems have, to fetch what it
	// lacks before a submission needs it. With the question it says how much of what it was
	// told the last time it holds. An account that is switched off is told of nothing.
	if (isset($_POST['data_versions'])) {
		$set = 'data_checked_at = now()';
		foreach (array('data_have', 'data_total') as $field) {
			if (isset($_POST[$field]) && is_string($_POST[$field]) && validateUInt($_POST[$field]) && strlen($_POST[$field]) <= 9) {
				$set .= ", $field = {$_POST[$field]}";
			}
		}
		DB::update("update judger_info set $set where judger_name = '" . DB::escape(judgerName()) . "'");
		die(json_encode(array('versions' => $uojJudger['enabled'] ? judgerDataVersions() : array())));
	}
	
	// ---- what a judger is given to do
	
	function queryProblemDataMTime($problem_id) {
		// While new data is being published the folder is missing for an instant.
		for ($i = 0; $i < 20; $i++) {
			clearstatcache();
			$mtime = @filemtime("/var/uoj_data/$problem_id");
			if ($mtime !== false) {
				return $mtime;
			}
			usleep(50000);
		}
		return false;
	}

	$submission = null;
	$hack = null;
	$kind = null;
	// the claim that is given out with a task, if one is
	$new_claim = bin2hex(random_bytes(16));
	// what a task that is given out is set to: its judger, its claim, its worker, its lease
	function claimedBy() {
		global $new_claim, $worker;
		return "judger_name = '".DB::escape(judgerName())."', judge_claim = '$new_claim', judge_worker = '$worker', judge_lease_until = ".leaseUntil();
	}
	function querySubmissionToJudge($status, $set_q) {
		global $submission, $kind;
		global $skip_problems;
		$submission = DB::selectFirst("select id, problem_id, content from submissions where status = '$status'$skip_problems order by id limit 1");
		if ($submission) {
			DB::update("update submissions set $set_q, ".claimedBy()." where id = {$submission['id']} and status = '$status'");
			if (DB::affected_rows() != 1) {
				$submission = null;
			}
		}
		if ($submission) {
			$kind = 'submission';
		}
	}
	function queryCustomTestSubmissionToJudge() {
		global $submission, $kind;
		global $skip_problems;
		$submission = DB::selectFirst("select id, problem_id, content from custom_test_submissions where judge_time is null$skip_problems order by id limit 1");
		if ($submission) {
			DB::update("update custom_test_submissions set judge_time = now(), status = 'Judging', ".claimedBy()." where id = {$submission['id']} and judge_time is null");
			if (DB::affected_rows() != 1) {
				$submission = null;
			}
		}
		if ($submission) {
			$submission['is_custom_test'] = '';
			$kind = 'custom_test';
		}
	}
	function queryHackToJudge() {
		global $hack;
		global $skip_problems;
		$hack = DB::selectFirst("select id, submission_id, input, input_type from hacks where judge_time is null$skip_problems order by id limit 1");
		if ($hack) {
			DB::update("update hacks set judge_time = now(), ".claimedBy()." where id = {$hack['id']} and judge_time is null");
			if (DB::affected_rows() != 1) {
				$hack = null;
			}
		}
	}
	// Why the standard program made no answers, in words: the judger says it as JSON, the
	// compiler's complaint ({"compile": text}) or the tests it failed on ({"failed": [{"test":
	// place in the job, "verdict": ..., "exit_code": ...}]}), or else as text.
	function answerJobFailure($job, $message) {
		$said = json_decode($message, true);
		if (!is_array($said)) {
			return '标准程序没有生成答案：' . uojTextEncode(mb_substr($message, 0, 2000, 'UTF-8'));
		}
		if (isset($said['compile']) && is_string($said['compile'])) {
			return "标准程序编译失败：\n" . uojTextEncode(mb_substr($said['compile'], 0, 4000, 'UTF-8'));
		}
		$verdicts = array('Runtime Error' => '运行错误', 'Memory Limit Exceeded' => '超出内存限制', 'Time Limit Exceeded' => '超时',
			'Output Limit Exceeded' => '输出超出限制', 'Dangerous Syscalls' => '危险系统调用', 'Judgement Failed' => '评测失败');
		$tests = json_decode($job['tests'], true);
		$lines = array();
		foreach (isset($said['failed']) && is_array($said['failed']) ? array_slice($said['failed'], 0, 20) : array() as $failed) {
			$at = isset($failed['test']) && is_int($failed['test']) ? $failed['test'] : 0;
			$name = isset($tests[$at - 1]) ? $tests[$at - 1][0] : "#$at";
			$verdict = isset($failed['verdict']) && is_string($failed['verdict']) && isset($verdicts[$failed['verdict']]) ? $verdicts[$failed['verdict']] : '出错';
			// (a program that was killed has no value it returned)
			$code = isset($failed['exit_code']) && is_int($failed['exit_code']) && $failed['exit_code'] > 0 && $verdict === '运行错误' ? "（返回值 {$failed['exit_code']}）" : '';
			$lines[] = "{$name}：{$verdict}{$code}";
		}
		$count = isset($said['failed']) && is_array($said['failed']) ? count($said['failed']) : 0;
		return "标准程序在 $count 个测试点上出错，一个答案也没有写入：\n" . join("\n", $lines) . ($count > 20 ? "\n……" : '');
	}
	function findAnswerJobToRun() {
		$job = DB::selectFirst("select * from problem_answer_jobs where status = 'pending' order by id limit 1");
		if ($job) {
			DB::update("update problem_answer_jobs set status = 'running', claimed_at = now(), ".claimedBy()." where id = {$job['id']} and status = 'pending'");
			if (DB::affected_rows() != 1) {
				$job = null;
			}
		}
		return $job;
	}
	function findVersionToPrepare() {
		$version_row = DB::selectFirst("select * from problem_data_versions where status = 'pending' order by id limit 1");
		if ($version_row) {
			DB::update("update problem_data_versions set status = 'preparing', claimed_at = now(), ".claimedBy()." where id = {$version_row['id']} and status = 'pending'");
			if (DB::affected_rows() != 1) {
				$version_row = null;
			}
		}
		return $version_row;
	}
	function findSubmissionToJudge() {
		global $submission, $hack, $kind;
		// what an earlier look found and could not give out is nothing to give out now
		$submission = null;
		$hack = null;
		$kind = null;
		querySubmissionToJudge('Waiting', "judge_time = now(), status = 'Judging'");
		if ($submission) {
			return true;
		}

		queryCustomTestSubmissionToJudge();
		if ($submission) {
			return true;
		}
		
		querySubmissionToJudge('Waiting Rejudge', "judge_time = now(), status = 'Judging'");
		if ($submission) {
			return true;
		}
		
		querySubmissionToJudge('Judged, Waiting', "status = 'Judged, Judging'");
		if ($submission) {
			return true;
		}
		
		queryHackToJudge();
		if ($hack) {
			$submission = DB::selectFirst("select id, problem_id, content from submissions where id = {$hack['submission_id']} and score = 100");
			if (!$submission) {
				$details = "<error>the score gained by the hacked submission is not 100.\n</error>";
				$esc_details = DB::escape(uojTextEncode($details));
				DB::update("update hacks set success = 0, details = '$esc_details', ".CLAIM_DROPPED." where id = {$hack['id']}");
				return false;
			}
			$kind = 'hack';
			return true;
		}
		return false;
	}
	
	// Older versions of judge_client send Python's False, which arrives as the string "False".
	if (isset($_POST['fetch_new']) && in_array(strtolower($_POST['fetch_new']), array('', '0', 'false'), true)) {
		die("Nothing to judge");
	}
	// A judger has to know versions of problem data and build the programs of a problem itself
	// (protocol 2), and work under claims (protocol 3): it says which worker it is, and which
	// asking for work this is.
	if (!isset($_POST['protocol']) || !validateUInt($_POST['protocol']) || $_POST['protocol'] < 3) {
		die("Nothing to judge");
	}
	$fetch_id = isset($_POST['fetch_id']) && is_string($_POST['fetch_id']) && preg_match('/^[0-9a-f]{32}$/D', $_POST['fetch_id']) ? $_POST['fetch_id'] : null;
	if ($worker === null || $fetch_id === null) {
		die("Nothing to judge");
	}
	if (isset($_POST['judger_version']) && is_string($_POST['judger_version']) && isset($_POST['toolchain']) && is_string($_POST['toolchain'])) {
		$uojJudger['version'] = substr($_POST['judger_version'], 0, 64);
		$uojJudger['toolchain'] = substr($_POST['toolchain'], 0, 4000);
		DB::update("update judger_info set version = '" . DB::escape($uojJudger['version']) . "', toolchain = '" . DB::escape($uojJudger['toolchain']) . "' where judger_name = '" . DB::escape(judgerName()) . "'");
	}
	
	// The same asking again: its answer was lost on the way. It is given again, under the same
	// claim while that is held, and nothing is taken back from the worker for it.
	$esc_worker_of = "judger_name = '".DB::escape(judgerName())."' and worker = '$worker'";
	// what the worker can judge, which only an asking for work says: null for no limit
	$memory_mb = isset($_POST['memory_mb']) && is_string($_POST['memory_mb']) && validateUInt($_POST['memory_mb']) && strlen($_POST['memory_mb']) <= 7 ? (int)$_POST['memory_mb'] : null;
	DB::update("update judger_workers set memory_mb = ".($memory_mb === null ? 'null' : $memory_mb)." where $esc_worker_of");
	$answer_again = function() use ($esc_worker_of) {
		$asked = DB::selectFirst("select last_claim, last_answer from judger_workers where $esc_worker_of");
		if ($asked && $asked['last_claim'] !== null && $asked['last_answer'] !== null && claimIsHeld($asked['last_claim'])) {
			renewLease($asked['last_claim']);
			die($asked['last_answer']);
		}
		die("Nothing to judge");
	};
	$asked = DB::selectFirst("select last_fetch_id from judger_workers where $esc_worker_of");
	if ($asked && $asked['last_fetch_id'] === $fetch_id) {
		$answer_again();
	}
	// The asking is taken in one statement that finds it new still: two requests of the same asking
	// at once (sent twice on the way, or asked again while the first was being answered) both found
	// it new, and each took a task, the one of them the judger never heard of.
	if (!DB::update("update judger_workers set last_fetch_id = '$fetch_id', last_claim = null, last_answer = null where $esc_worker_of and (last_fetch_id is null or last_fetch_id <> '$fetch_id')") || DB::affected_rows() != 1) {
		$answer_again();
	}
	// what is answered with a task is kept, for the same asking again
	function answerWith($answer) {
		global $new_claim, $esc_worker_of;
		$answer['claim'] = $new_claim;
		$json = json_encode($answer);
		DB::update("update judger_workers set last_claim = '$new_claim', last_answer = '".DB::escape($json)."' where $esc_worker_of");
		die($json);
	}
	
	reclaimLostTasks($worker);
	
	if (!$uojJudger['enabled']) {
		die("Nothing to judge");
	}
	
	$skip_problems = memoryExclusion();
	$version_row = findVersionToPrepare();
	if ($version_row) {
		answerWith(array(
			'prepare' => array('id' => (int)$version_row['id']),
			'problem_id' => (int)$version_row['problem_id'],
			'problem_data_version' => (int)$version_row['version'],
			'problem_data_sha256' => $version_row['sha256'],
			'problem_data_prepare' => json_decode($version_row['prepare'], true)
		));
	}
	$job = findAnswerJobToRun();
	if ($job) {
		$limits = json_decode($job['limits'], true);
		answerWith(array(
			'answers' => array(
				'id' => (int)$job['id'],
				'tests' => count(json_decode($job['tests'], true)),
				'program' => $limits['program'],
				'sha256' => $job['sha256'],
				'time_limit' => (int)$limits['time'],
				'memory_limit' => (int)$limits['memory'],
				'output_limit' => (int)$limits['output']
			),
			'problem_id' => (int)$job['problem_id']
		));
	}
	
	// ---- waiting for work
	//
	// A judger with nothing to do may ask to wait (wait=<seconds>): the request is answered with
	// "Ask again" as soon as something comes to be judged, and the judger asks for it at once,
	// instead of when it would have asked next. The request that waits gives nothing out itself,
	// so a judger that went away while it waited is never given a task it will not do. The wait is
	// as long as the judger asks and the site allows (judger.long-poll-seconds, 0 for not at all),
	// and never longer than 25 seconds: a judger that waits is still seen as online, and a proxy in
	// front of the site still waits for the answer.
	function longPollSeconds() {
		$limit = isset(UOJConfig::$data['judger']['long-poll-seconds']) ? (int)UOJConfig::$data['judger']['long-poll-seconds'] : 20;
		$asked = isset($_POST['wait']) && is_string($_POST['wait']) && validateUInt($_POST['wait']) && strlen($_POST['wait']) <= 4 ? (int)$_POST['wait'] : 0;
		return max(0, min($asked, $limit, 25));
	}
	// whether anything waits that the worker may be given, in one look that costs little
	function somethingToJudge() {
		global $skip_problems;
		return DB::selectCount("select exists(select 1 from problem_data_versions where status = 'pending')
			or exists(select 1 from problem_answer_jobs where status = 'pending')
			or exists(select 1 from submissions where status in ('Waiting', 'Waiting Rejudge', 'Judged, Waiting')$skip_problems)
			or exists(select 1 from custom_test_submissions where judge_time is null$skip_problems)
			or exists(select 1 from hacks where judge_time is null$skip_problems)") == 1;
	}
	// How many requests may wait at the same time (judger.long-poll-max-waiting): each holds a
	// process of the web server and a connection to the database while it waits. Beyond that a
	// judger is answered at once, and asks again two seconds later, as without waiting.
	function longPollMaxWaiting() {
		return isset(UOJConfig::$data['judger']['long-poll-max-waiting']) ? max(0, (int)UOJConfig::$data['judger']['long-poll-max-waiting']) : 30;
	}
	// whether the worker that waits has not asked again meanwhile, and may still be given work
	function stillWaiting() {
		global $esc_worker_of, $fetch_id;
		$asked = DB::selectFirst("select last_fetch_id from judger_workers where $esc_worker_of");
		$enabled = DB::selectCount("select enabled from judger_info where judger_name = '".DB::escape(judgerName())."'");
		return $asked && $asked['last_fetch_id'] === $fetch_id && $enabled == 1;
	}
	
	if (!findSubmissionToJudge()) {
		$wait = longPollSeconds();
		if ($wait > 0 && DB::selectCount("select count(*) from judger_workers where waiting_until > now()") >= longPollMaxWaiting()) {
			$wait = 0;
		}
		if ($wait > 0) {
			DB::update("update judger_workers set waiting_until = now() + interval $wait second where $esc_worker_of");
			register_shutdown_function(function() use ($esc_worker_of) {
				DB::update("update judger_workers set waiting_until = null where $esc_worker_of");
			});
		}
		$deadline = microtime(true) + $wait;
		$checked_at = microtime(true);
		while (microtime(true) < $deadline) {
			usleep(200000);
			if (somethingToJudge()) {
				die("Ask again");
			}
			if (microtime(true) - $checked_at >= 5) {
				$checked_at = microtime(true);
				if (!stillWaiting()) {
					break;
				}
				// the judgers that are around come and go
				$skip_problems = memoryExclusion();
			}
		}
		die("Nothing to judge");
	}
	
	$submission['id'] = (int)$submission['id'];
	$submission['problem_id'] = (int)$submission['problem_id'];
	$submission['problem_mtime'] = queryProblemDataMTime($submission['problem_id']);
	$submission['content'] = json_decode($submission['content']);
	
	$version_row = startJudgement($kind, $kind == 'hack' ? $hack['id'] : $submission['id'], $submission['problem_id'], $new_claim);
	if ($version_row) {
		$submission['problem_data_version'] = (int)$version_row['version'];
		$submission['problem_data_sha256'] = $version_row['sha256'];
		$submission['problem_data_prepare'] = json_decode($version_row['prepare'], true);
	}
	
	if ($hack) {
		$submission['is_hack'] = "";
		$submission['hack']['id'] = (int)$hack['id'];
		$submission['hack']['input'] = $hack['input'];
		$submission['hack']['input_type'] = $hack['input_type'];
	}
	
	answerWith($submission);
?>
