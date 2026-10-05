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
	// a result is only taken from the judger that was given the task
	function isTaskOfThisJudger($row) {
		return $row['judger_name'] === null || $row['judger_name'] === judgerName();
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
	function storeJudgementResult($result, $make_query) {
		$esc_result = DB::escape(json_encode($result, JSON_UNESCAPED_UNICODE));
		if (DB::update($make_query($esc_result))) {
			return true;
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
		return DB::update($make_query($esc_result));
	}
	
	// the result of a task that no judger managed to judge
	function lostTaskResult() {
		return array(
			'status' => 'Judged', 'score' => 0, 'time' => 0, 'memory' => 0, 'error' => 'Judgement Failed',
			'details' => '<error>The judgers stopped responding or were overloaded while judging this ' . JUDGE_MAX_ATTEMPTS . ' times in a row.</error>'
		);
	}
	
	// ---- the record of who judged what, with which data and which tools
	
	function startJudgement($kind, $target_id, $problem_id) {
		global $uojJudger;
		$version_row = dataCurrentVersion(array('id' => $problem_id));
		$version = $version_row ? $version_row['version'] : 'null';
		$sha256 = $version_row ? "'{$version_row['sha256']}'" : 'null';
		$esc_judger = DB::escape(judgerName());
		$esc_version = DB::escape($uojJudger['version']);
		$esc_toolchain = DB::escape($uojJudger['toolchain']);
		// a judgement that was still open is over: the task was put back in the queue meanwhile
		DB::update("update submission_judgements set finished_at = now(), outcome = 'superseded' where kind = '$kind' and target_id = $target_id and finished_at is null");
		DB::insert("insert into submission_judgements (kind, target_id, problem_id, judger_name, problem_data_version, problem_data_sha256, judger_version, toolchain, started_at) values ('$kind', $target_id, $problem_id, '$esc_judger', $version, $sha256, '$esc_version', '$esc_toolchain', now())");
		return $version_row;
	}
	function finishJudgement($kind, $target_id, $outcome, $score = null) {
		$set = "finished_at = now(), outcome = '$outcome'";
		if ($score !== null) {
			$set .= ", score = " . (int)round($score);
		}
		// the judger reports the data it really used
		if ($outcome == 'judged' && isset($_POST['problem_data_version']) && validateUInt($_POST['problem_data_version'])
				&& isset($_POST['problem_data_sha256']) && preg_match('/^[0-9a-f]{64}$/', $_POST['problem_data_sha256'])) {
			$set .= ", problem_data_version = {$_POST['problem_data_version']}, problem_data_sha256 = '{$_POST['problem_data_sha256']}'";
		}
		DB::update("update submission_judgements set $set where kind = '$kind' and target_id = $target_id and finished_at is null");
	}
	
	// ---- results
	
	// stores the result of a submission, whichever of its two tests in a contest it comes from
	function storeSubmissionResult($id, $submission, $result) {
		$content = json_decode($submission['content'], true);
		
		if (isset($content['first_test_config'])) {
			$final_result = $result;
			$result = json_decode($submission['result'], true);
			$result['final_result'] = $final_result;
			
			$content['final_test_config'] = $content['config'];
			$content['config'] = $content['first_test_config'];
			unset($content['first_test_config']);
			$esc_content = DB::escape(json_encode($content));
			
			storeJudgementResult($result, function($esc_result) use ($esc_content, $id) {
				return "update submissions set status = 'Judged', judge_attempts = 0, result = '$esc_result', content = '$esc_content' where id = $id";
			});
		} else {
			storeJudgementResult($result, function($esc_result) use ($result, $id) {
				if (isset($result["error"])) {
					$esc_error = DB::escape($result['error']);
					return "update submissions set status = 'Judged', judge_attempts = 0, result_error = '$esc_error', result = '$esc_result', score = null, used_time = null, used_memory = null where id = $id";
				} else {
					$score = sprintf('%F', $result['score']);
					return "update submissions set status = 'Judged', judge_attempts = 0, result_error = null, result = '$esc_result', score = $score, used_time = {$result['time']}, used_memory = {$result['memory']} where id = $id";
				}
			});
			
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
	}
	function storeCustomTestResult($id, $result) {
		storeJudgementResult($result, function($esc_result) use ($id) {
			return "update custom_test_submissions set status = 'Judged', judge_attempts = 0, result = '$esc_result' where id = $id";
		});
		DB::update("update custom_test_submissions set status_details = '' where id = $id");
	}
	// returns whether this call is the one that set the result of the hack
	function storeHackResult($id, $result) {
		$success = $result['score'] ? 1 : 0;
		$esc_details = DB::escape($result['details']);
		$ok = DB::update("update hacks set success = $success, judge_attempts = 0, details = '$esc_details' where id = $id and success is null");
		if (!$ok) {
			error_log("judge/submit: failed to store hack details of " . strlen($esc_details) . " bytes: " . DB::error());
			DB::init();
			$esc_details = DB::escape("<error>The details of this judgement are too large to be stored.</error>");
			$ok = DB::update("update hacks set success = $success, judge_attempts = 0, details = '$esc_details' where id = $id and success is null");
		}
		// a judger sends a result again when it gets no answer: it only counts once
		return $ok && DB::affected_rows() == 1;
	}
	
	function querySubmissionBeingJudged($id) {
		$submission = DB::selectFirst("select id, submitter, status, content, result, problem_id, judger_name, judge_attempts from submissions where id = $id");
		if ($submission == null || ($submission['status'] != 'Judging' && $submission['status'] != 'Judged, Judging')) {
			return null;
		}
		return isTaskOfThisJudger($submission) ? $submission : null;
	}
	function queryCustomTestBeingJudged($id) {
		$submission = DB::selectFirst("select id, status, judger_name, judge_attempts from custom_test_submissions where id = $id");
		if ($submission == null || $submission['status'] != 'Judging') {
			return null;
		}
		return isTaskOfThisJudger($submission) ? $submission : null;
	}
	function queryHackBeingJudged($id) {
		$hack = DB::selectFirst("select id, problem_id, input, judger_name, judge_attempts from hacks where id = $id and success is null and judge_time is not null");
		return $hack != null && isTaskOfThisJudger($hack) ? $hack : null;
	}
	
	function submissionJudged($id) {
		$submission = querySubmissionBeingJudged($id);
		if ($submission == null) {
			return;
		}
		$result = getPostedJudgementResult();
		storeSubmissionResult($id, $submission, $result);
		finishJudgement('submission', $id, 'judged', isset($result['error']) ? null : $result['score']);
	}

	function customTestSubmissionJudged($id) {
		if (queryCustomTestBeingJudged($id) == null) {
			return;
		}
		storeCustomTestResult($id, getPostedJudgementResult());
		finishJudgement('custom_test', $id, 'judged');
	}
	
	function hackJudged($id) {
		$hack = queryHackBeingJudged($id);
		if ($hack == null) {
			return;
		}
		$result = getPostedJudgementResult();
		if (!storeHackResult($id, $result)) {
			return;
		}
		finishJudgement('hack', $id, 'judged', $result['score'] ? 1 : 0);
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
	
	function releaseSubmission($submission) {
		$id = $submission['id'];
		if ($submission['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			storeSubmissionResult($id, $submission, lostTaskResult());
			finishJudgement('submission', $id, 'failed');
		} else {
			DB::update("update submissions set judge_time = if(status = 'Judging', null, judge_time), status = if(status = 'Judging', 'Waiting', 'Judged, Waiting'), status_details = '', judger_name = null, judge_attempts = judge_attempts + 1 where id = $id and status in ('Judging', 'Judged, Judging')");
			finishJudgement('submission', $id, 'reclaimed');
		}
	}
	function releaseCustomTest($submission) {
		$id = $submission['id'];
		if ($submission['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			storeCustomTestResult($id, lostTaskResult());
			finishJudgement('custom_test', $id, 'failed');
		} else {
			DB::update("update custom_test_submissions set judge_time = null, status = 'Waiting', status_details = '', judger_name = null, judge_attempts = judge_attempts + 1 where id = $id and status = 'Judging'");
			finishJudgement('custom_test', $id, 'reclaimed');
		}
	}
	function releaseHack($hack) {
		$id = $hack['id'];
		if ($hack['judge_attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			storeHackResult($id, lostTaskResult());
			finishJudgement('hack', $id, 'failed');
		} else {
			DB::update("update hacks set judge_time = null, judger_name = null, judge_attempts = judge_attempts + 1 where id = $id and success is null");
			finishJudgement('hack', $id, 'reclaimed');
		}
	}
	function releaseVersion($version_row) {
		if ($version_row['attempts'] + 1 >= JUDGE_MAX_ATTEMPTS) {
			dataFailVersion($version_row, 'the judgers stopped responding while building the programs of the problem');
		} else {
			DB::update("update problem_data_versions set status = 'pending', judger_name = null, attempts = attempts + 1 where id = {$version_row['id']} and status = 'preparing'");
		}
	}
	
	function reclaimLostTasks() {
		$timeout = isset(UOJConfig::$data['judger']['task-timeout']) ? (int)UOJConfig::$data['judger']['task-timeout'] : 300;
		$esc_judger = DB::escape(judgerName());
		// A judger that asks for work is not judging what it was given before, and a judger that
		// has not been heard of for a while is not judging at all.
		$lost = "(judger_name = '$esc_judger' or judger_name not in (select judger_name from judger_info where last_heartbeat_at >= now() - interval $timeout second))";
		
		foreach (DB::selectAll("select id, submitter, status, content, result, problem_id, judger_name, judge_attempts from submissions where status in ('Judging', 'Judged, Judging') and judger_name is not null and $lost") as $submission) {
			releaseSubmission($submission);
		}
		foreach (DB::selectAll("select id, judge_attempts from custom_test_submissions where status = 'Judging' and judger_name is not null and $lost") as $submission) {
			releaseCustomTest($submission);
		}
		foreach (DB::selectAll("select id, judge_attempts from hacks where success is null and judge_time is not null and judger_name is not null and $lost") as $hack) {
			releaseHack($hack);
		}
		foreach (DB::selectAll("select * from problem_data_versions where status = 'preparing' and $lost") as $version_row) {
			releaseVersion($version_row);
		}
	}
	
	// ---- what a judger reports
	
	if (isset($_POST['submit']) || isset($_POST['requeue'])) {
		if (!validateUInt($_POST['id'])) {
			die("Wow! hacker! T_T....");
		}
		$id = $_POST['id'];
		if (isset($_POST['requeue'])) {
			// the judger could not judge the task, for example because it was overloaded
			if (isset($_POST['is_hack'])) {
				$hack = queryHackBeingJudged($id);
				if ($hack != null) {
					releaseHack($hack);
				}
			} elseif (isset($_POST['is_custom_test'])) {
				$submission = queryCustomTestBeingJudged($id);
				if ($submission != null) {
					releaseCustomTest($submission);
				}
			} else {
				$submission = querySubmissionBeingJudged($id);
				if ($submission != null) {
					releaseSubmission($submission);
				}
			}
		} elseif (isset($_POST['is_hack'])) {
			hackJudged($id);
		} elseif (isset($_POST['is_custom_test'])) {
			customTestSubmissionJudged($id);
		} else {
			submissionJudged($id);
		}
	}
	if (isset($_POST['prepare_result'])) {
		if (!validateUInt($_POST['id'])) {
			die("Wow! hacker! T_T....");
		}
		$version_row = queryProblemDataVersionById($_POST['id']);
		if ($version_row != null && $version_row['status'] == 'preparing' && $version_row['judger_name'] === judgerName()) {
			if (isset($_POST['ok']) && $_POST['ok'] === '1') {
				dataPublishVersion($version_row);
			} else {
				$message = isset($_POST['message']) && is_string($_POST['message']) ? $_POST['message'] : '';
				dataFailVersion($version_row, uojTextEncode(substr($message, 0, 20000)));
			}
		}
	}
	if (isset($_POST['update-status'])) {
		if (!validateUInt($_POST['id'])) {
			die("Wow! hacker! T_T....");
		}
		$esc_status_details = DB::escape($_POST['status']);
		if (isset($_POST['is_custom_test'])) {
			DB::update("update custom_test_submissions set status_details = '$esc_status_details' where id = {$_POST['id']}");
		} else {
			DB::update("update submissions set status_details = '$esc_status_details' where id = {$_POST['id']}");
		}
		die();
	}
	if (isset($_POST['heartbeat'])) {
		// the authentication has already noted that the judger is alive
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
	function querySubmissionToJudge($status, $set_q) {
		global $submission, $kind;
		$esc_judger = DB::escape(judgerName());
		$submission = DB::selectFirst("select id, problem_id, content from submissions where status = '$status' order by id limit 1");
		if ($submission) {
			DB::update("update submissions set $set_q, judger_name = '$esc_judger' where id = {$submission['id']} and status = '$status'");
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
		$esc_judger = DB::escape(judgerName());
		$submission = DB::selectFirst("select id, problem_id, content from custom_test_submissions where judge_time is null order by id limit 1");
		if ($submission) {
			DB::update("update custom_test_submissions set judge_time = now(), status = 'Judging', judger_name = '$esc_judger' where id = {$submission['id']} and judge_time is null");
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
		$esc_judger = DB::escape(judgerName());
		$hack = DB::selectFirst("select id, submission_id, input, input_type from hacks where judge_time is null order by id limit 1");
		if ($hack) {
			DB::update("update hacks set judge_time = now(), judger_name = '$esc_judger' where id = {$hack['id']} and judge_time is null");
			if (DB::affected_rows() != 1) {
				$hack = null;
			}
		}
	}
	function findVersionToPrepare() {
		$esc_judger = DB::escape(judgerName());
		$version_row = DB::selectFirst("select * from problem_data_versions where status = 'pending' order by id limit 1");
		if ($version_row) {
			DB::update("update problem_data_versions set status = 'preparing', judger_name = '$esc_judger', claimed_at = now() where id = {$version_row['id']} and status = 'pending'");
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
				DB::update("update hacks set success = 0, details = '$esc_details' where id = {$hack['id']}");
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
	// A judger has to know versions of problem data and build the programs of a problem itself.
	if (!isset($_POST['protocol']) || !validateUInt($_POST['protocol']) || $_POST['protocol'] < 2) {
		die("Nothing to judge");
	}
	if (isset($_POST['judger_version']) && is_string($_POST['judger_version']) && isset($_POST['toolchain']) && is_string($_POST['toolchain'])) {
		$uojJudger['version'] = substr($_POST['judger_version'], 0, 64);
		$uojJudger['toolchain'] = substr($_POST['toolchain'], 0, 4000);
		DB::update("update judger_info set version = '" . DB::escape($uojJudger['version']) . "', toolchain = '" . DB::escape($uojJudger['toolchain']) . "' where judger_name = '" . DB::escape(judgerName()) . "'");
	}
	
	reclaimLostTasks();
	
	if (!$uojJudger['enabled']) {
		die("Nothing to judge");
	}
	
	$version_row = findVersionToPrepare();
	if ($version_row) {
		die(json_encode(array(
			'prepare' => array('id' => (int)$version_row['id']),
			'problem_id' => (int)$version_row['problem_id'],
			'problem_data_version' => (int)$version_row['version'],
			'problem_data_sha256' => $version_row['sha256'],
			'problem_data_prepare' => json_decode($version_row['prepare'], true)
		)));
	}
	
	if (!findSubmissionToJudge()) {
		die("Nothing to judge");
	}
	
	$submission['id'] = (int)$submission['id'];
	$submission['problem_id'] = (int)$submission['problem_id'];
	$submission['problem_mtime'] = queryProblemDataMTime($submission['problem_id']);
	$submission['content'] = json_decode($submission['content']);
	
	$version_row = startJudgement($kind, $kind == 'hack' ? $hack['id'] : $submission['id'], $submission['problem_id']);
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
	
	echo json_encode($submission);
?>
