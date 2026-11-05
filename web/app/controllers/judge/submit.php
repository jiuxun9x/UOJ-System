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
		return array('submission' => 'submissions', 'custom_test' => 'custom_test_submissions', 'hack' => 'hacks', 'prepare' => 'problem_data_versions');
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
		global $uojJudger;
		$version_row = dataCurrentVersion(array('id' => $problem_id));
		$version = $version_row ? $version_row['version'] : 'null';
		$sha256 = $version_row ? "'{$version_row['sha256']}'" : 'null';
		$esc_judger = DB::escape(judgerName());
		$esc_version = DB::escape($uojJudger['version']);
		$esc_toolchain = DB::escape($uojJudger['toolchain']);
		// a judgement that was still open is over: the task was put back in the queue meanwhile
		DB::update("update submission_judgements set finished_at = now(), outcome = 'superseded' where kind = '$kind' and target_id = $target_id and finished_at is null");
		DB::insert("insert into submission_judgements (kind, target_id, problem_id, judger_name, problem_data_version, problem_data_sha256, judger_version, toolchain, started_at, claim) values ('$kind', $target_id, $problem_id, '$esc_judger', $version, $sha256, '$esc_version', '$esc_toolchain', now(), '$claim')");
		return $version_row;
	}
	function finishJudgement($kind, $target_id, $outcome, $claim, $score = null) {
		$set = "finished_at = now(), outcome = '$outcome'";
		if ($score !== null) {
			$set .= ", score = " . (int)round($score);
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
			if (!storeJudgementResult($result, function($esc_result) use ($result, $held) {
				if (isset($result["error"])) {
					$esc_error = DB::escape($result['error']);
					return "update submissions set status = 'Judged', judge_attempts = 0, result_error = '$esc_error', result = '$esc_result', score = null, used_time = null, used_memory = null, ".CLAIM_DROPPED." where $held";
				} else {
					$score = sprintf('%F', $result['score']);
					return "update submissions set status = 'Judged', judge_attempts = 0, result_error = null, result = '$esc_result', score = $score, used_time = {$result['time']}, used_memory = {$result['memory']}, ".CLAIM_DROPPED." where $held";
				}
			})) {
				return false;
			}
			
			if (isset($content['final_test_config'])) {
				$content['first_test_config'] = $content['config'];
				$content['config'] = $content['final_test_config'];
				unset($content['final_test_config']);
				$esc_content = DB::escape(json_encode($content));
			
				DB::update("update submissions set status = 'Judged, Waiting', content = '$esc_content' where id = $id");
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
	// returns whether this call is the one that set the result of the hack
	function storeHackResult($id, $hack, $result) {
		$held = "id = $id and success is null and ".claimIs($hack['judge_claim']);
		$success = $result['score'] ? 1 : 0;
		$esc_details = DB::escape($result['details']);
		$ok = DB::update("update hacks set success = $success, judge_attempts = 0, details = '$esc_details', ".CLAIM_DROPPED." where $held");
		if (!$ok) {
			error_log("judge/submit: failed to store hack details of " . strlen($esc_details) . " bytes: " . DB::error());
			DB::init();
			$esc_details = DB::escape("<error>The details of this judgement are too large to be stored.</error>");
			$ok = DB::update("update hacks set success = $success, judge_attempts = 0, details = '$esc_details', ".CLAIM_DROPPED." where $held");
		}
		// a judger sends a result again when it gets no answer: it only counts once
		return $ok && DB::affected_rows() == 1;
	}
	
	// What is being judged under a claim: what a judger says of a task counts while the task has
	// the claim the judger was given it under, and not after it was taken back or given out anew.
	function querySubmissionBeingJudged($id, $claim) {
		return DB::selectFirst("select id, submitter, status, content, result, problem_id, judger_name, judge_attempts, judge_claim from submissions where id = $id and judge_claim = '$claim' and status in ('Judging', 'Judged, Judging')");
	}
	function queryCustomTestBeingJudged($id, $claim) {
		return DB::selectFirst("select id, status, judger_name, judge_attempts, judge_claim from custom_test_submissions where id = $id and judge_claim = '$claim' and status = 'Judging'");
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
		finishJudgement('hack', $id, 'judged', $claim, $result['score'] ? 1 : 0);
		unlink(UOJContext::storagePath().$hack['input']);

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
	}
	
	// ---- tasks that their judger does not judge any more
	//
	// Such a task goes back to the queue. When several judgers in a row lost it, the task itself
	// is the likely reason, and it is failed instead of taking down one judger after another.
	
	// Each takes a task back under the claim it has, and only while it has it.
	function releaseSubmission($submission) {
		$id = $submission['id'];
		$claim = $submission['judge_claim'];
		if ($submission['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			if (storeSubmissionResult($id, $submission, lostTaskResult())) {
				finishJudgement('submission', $id, 'failed', $claim);
			}
		} elseif (DB::update("update submissions set judge_time = if(status = 'Judging', null, judge_time), status = if(status = 'Judging', 'Waiting', 'Judged, Waiting'), status_details = '', judger_name = null, judge_attempts = judge_attempts + 1, ".CLAIM_DROPPED." where id = $id and ".claimIs($claim)." and status in ('Judging', 'Judged, Judging')") && DB::affected_rows() == 1) {
			finishJudgement('submission', $id, 'reclaimed', $claim);
		}
	}
	function releaseCustomTest($submission) {
		$id = $submission['id'];
		$claim = $submission['judge_claim'];
		if ($submission['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			if (storeCustomTestResult($id, $submission, lostTaskResult())) {
				finishJudgement('custom_test', $id, 'failed', $claim);
			}
		} elseif (DB::update("update custom_test_submissions set judge_time = null, status = 'Waiting', status_details = '', judger_name = null, judge_attempts = judge_attempts + 1, ".CLAIM_DROPPED." where id = $id and ".claimIs($claim)." and status = 'Judging'") && DB::affected_rows() == 1) {
			finishJudgement('custom_test', $id, 'reclaimed', $claim);
		}
	}
	function releaseHack($hack) {
		$id = $hack['id'];
		$claim = $hack['judge_claim'];
		if ($hack['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			if (storeHackResult($id, $hack, lostTaskResult())) {
				finishJudgement('hack', $id, 'failed', $claim);
			}
		} elseif (DB::update("update hacks set judge_time = null, judger_name = null, judge_attempts = judge_attempts + 1, ".CLAIM_DROPPED." where id = $id and ".claimIs($claim)." and success is null") && DB::affected_rows() == 1) {
			finishJudgement('hack', $id, 'reclaimed', $claim);
		}
	}
	function releaseVersion($version_row) {
		if ($version_row['attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			dataFailVersion($version_row, 'the judgers stopped responding while building the programs of the problem');
		} else {
			DB::update("update problem_data_versions set status = 'pending', judger_name = null, attempts = attempts + 1, ".CLAIM_DROPPED." where id = {$version_row['id']} and ".claimIs($version_row['judge_claim'])." and status = 'preparing'");
		}
	}
	
	// Takes back what is not being worked on: a task whose lease ran out (one given out before
	// the claims has none), and what this worker had, which asks for another task. What the
	// other workers of the same account have is theirs.
	function reclaimLostTasks($worker) {
		$lost = "(judge_lease_until is null or judge_lease_until < now() or judger_name = '".DB::escape(judgerName())."' and judge_worker = '".DB::escape($worker)."')";
		foreach (DB::selectAll("select id, submitter, status, content, result, problem_id, judger_name, judge_attempts, judge_claim from submissions where status in ('Judging', 'Judged, Judging') and $lost") as $submission) {
			releaseSubmission($submission);
		}
		foreach (DB::selectAll("select id, judge_attempts, judge_claim from custom_test_submissions where status = 'Judging' and $lost") as $submission) {
			releaseCustomTest($submission);
		}
		foreach (DB::selectAll("select id, judge_attempts, judge_claim from hacks where success is null and judge_time is not null and $lost") as $hack) {
			releaseHack($hack);
		}
		foreach (DB::selectAll("select * from problem_data_versions where status = 'preparing' and $lost") as $version_row) {
			releaseVersion($version_row);
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
			// the judger could not judge the task, for example because it was overloaded
			if (isset($_POST['is_hack'])) {
				$hack = queryHackBeingJudged($id, $claim);
				if ($hack != null) {
					releaseHack($hack);
				}
			} elseif (isset($_POST['is_custom_test'])) {
				$submission = queryCustomTestBeingJudged($id, $claim);
				if ($submission != null) {
					releaseCustomTest($submission);
				}
			} else {
				$submission = querySubmissionBeingJudged($id, $claim);
				if ($submission != null) {
					releaseSubmission($submission);
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
			if (isset($_POST['ok']) && $_POST['ok'] === '1') {
				dataPublishVersion($version_row);
			} else {
				$message = isset($_POST['message']) && is_string($_POST['message']) ? $_POST['message'] : '';
				dataFailVersion($version_row, uojTextEncode(substr($message, 0, 20000)));
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
		$submission = DB::selectFirst("select id, problem_id, content from submissions where status = '$status' order by id limit 1");
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
		$submission = DB::selectFirst("select id, problem_id, content from custom_test_submissions where judge_time is null order by id limit 1");
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
		$hack = DB::selectFirst("select id, submission_id, input, input_type from hacks where judge_time is null order by id limit 1");
		if ($hack) {
			DB::update("update hacks set judge_time = now(), ".claimedBy()." where id = {$hack['id']} and judge_time is null");
			if (DB::affected_rows() != 1) {
				$hack = null;
			}
		}
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
	$asked = DB::selectFirst("select last_fetch_id, last_claim, last_answer from judger_workers where $esc_worker_of");
	if ($asked && $asked['last_fetch_id'] === $fetch_id) {
		if ($asked['last_claim'] !== null && $asked['last_answer'] !== null && claimIsHeld($asked['last_claim'])) {
			renewLease($asked['last_claim']);
			die($asked['last_answer']);
		}
		die("Nothing to judge");
	}
	DB::update("update judger_workers set last_fetch_id = '$fetch_id', last_claim = null, last_answer = null where $esc_worker_of");
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
	
	if (!findSubmissionToJudge()) {
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
