<?php
	requirePHPLib('judger');
	requirePHPLib('data');
	
	requireJudgerAuthentication();
	
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
	
	function submissionJudged() {
		$submission = DB::selectFirst("select submitter, status, content, result, problem_id from submissions where id = {$_POST['id']}");
		if ($submission == null) {
			return;
		}
		if ($submission['status'] != 'Judging' && $submission['status'] != 'Judged, Judging') {
			return;
		}
		$content = json_decode($submission['content'], true);
		
		if (isset($content['first_test_config'])) {
			$result = json_decode($submission['result'], true);
			$result['final_result'] = getPostedJudgementResult();
			
			$content['final_test_config'] = $content['config'];
			$content['config'] = $content['first_test_config'];
			unset($content['first_test_config']);
			$esc_content = DB::escape(json_encode($content));
			
			storeJudgementResult($result, function($esc_result) use ($esc_content) {
				return "update submissions set status = 'Judged', result = '$esc_result', content = '$esc_content' where id = {$_POST['id']}";
			});
		} else {
			$result = getPostedJudgementResult();
			storeJudgementResult($result, function($esc_result) use ($result) {
				if (isset($result["error"])) {
					$esc_error = DB::escape($result['error']);
					return "update submissions set status = 'Judged', result_error = '$esc_error', result = '$esc_result', score = null, used_time = null, used_memory = null where id = {$_POST['id']}";
				} else {
					$score = sprintf('%F', $result['score']);
					return "update submissions set status = 'Judged', result_error = null, result = '$esc_result', score = $score, used_time = {$result['time']}, used_memory = {$result['memory']} where id = {$_POST['id']}";
				}
			});
			
			if (isset($content['final_test_config'])) {
				$content['first_test_config'] = $content['config'];
				$content['config'] = $content['final_test_config'];
				unset($content['final_test_config']);
				$esc_content = DB::escape(json_encode($content));
			
				DB::update("update submissions set status = 'Judged, Waiting', content = '$esc_content' where id = ${_POST['id']}");
			}
		}
		DB::update("update submissions set status_details = '' where id = {$_POST['id']}");
		updateBestACSubmissions($submission['submitter'], $submission['problem_id']);
	}

	function customTestSubmissionJudged() {
		$submission = DB::selectFirst("select submitter, status, content, result, problem_id from custom_test_submissions where id = {$_POST['id']}");
		if ($submission == null) {
			return;
		}
		if ($submission['status'] != 'Judging') {
			return;
		}
		$content = json_decode($submission['content'], true);
		$result = getPostedJudgementResult();
		storeJudgementResult($result, function($esc_result) {
			return "update custom_test_submissions set status = 'Judged', result = '$esc_result' where id = {$_POST['id']}";
		});
		DB::update("update custom_test_submissions set status_details = '' where id = {$_POST['id']}");
	}
	
	// A successful hack that changes nothing must not go unnoticed: tell the people who can fix it.
	function notifyHackNotApplied($problem, $hack_id, $err) {
		$title = "Hack #$hack_id 成功，但题目 #{$problem['id']} 的数据未更新";
		$reason = HTML::escape(mb_substr(trim(strip_tags($err)), 0, 150, 'UTF-8'));
		if (mb_strlen($reason, 'UTF-8') > 200) {
			// the message is stored in 300 characters, do not cut an entity in two
			$reason = preg_replace('/&[^;]*$/', '', mb_substr($reason, 0, 200, 'UTF-8'));
		}
		$content = "新的 Extra Test 未生效，已通过的提交未重测。请检查数据后重新同步并重测。原因：$reason";
		
		$receivers = array();
		foreach (DB::selectAll("select username from problems_permissions where problem_id = {$problem['id']}") as $row) {
			$receivers[$row['username']] = true;
		}
		foreach (DB::selectAll("select username from user_info where usergroup = 'S'") as $row) {
			$receivers[$row['username']] = true;
		}
		foreach (array_keys($receivers) as $username) {
			sendSystemMsg($username, $title, $content);
		}
	}
	
	function hackJudged() {
		$result = getPostedJudgementResult();
		$success = $result['score'] ? 1 : 0;
		$esc_details = DB::escape($result['details']);
		$ok = DB::update("update hacks set success = $success, details = '$esc_details' where id = {$_POST['id']} and success is null");
		if (!$ok) {
			error_log("judge/submit: failed to store hack details of " . strlen($esc_details) . " bytes: " . DB::error());
			DB::init();
			$esc_details = DB::escape("<error>The details of this judgement are too large to be stored.</error>");
			$ok = DB::update("update hacks set success = $success, details = '$esc_details' where id = {$_POST['id']} and success is null");
		}
		
		// a judger sends a result again when it gets no answer: add the extra test only once
		if ($ok && DB::affected_rows() == 1) {
			list($hack_input) = DB::fetch(DB::query("select input from hacks where id = {$_POST['id']}"), MYSQLI_NUM);
			unlink(UOJContext::storagePath().$hack_input);

			if ($result['score']) {
				list($problem_id) = DB::selectFirst("select problem_id from hacks where id = {$_POST['id']}", MYSQLI_NUM);
				$problem = queryProblemBrief($problem_id);
				if (validateUploadedFile('hack_input') && validateUploadedFile('std_output')) {
					$err = dataAddExtraTest($problem, $_FILES["hack_input"]["tmp_name"], $_FILES["std_output"]["tmp_name"]);
				} else {
					$err = 'the judger sent no data';
				}
				if ($err !== '') {
					error_log("hack #{$_POST['id']} succeeded but its extra test was not added: $err");
					notifyHackNotApplied($problem, $_POST['id'], $err);
				}
			}
		}
	}
	
	if (isset($_POST['submit'])) {
		if (!validateUInt($_POST['id'])) {
			die("Wow! hacker! T_T....");
		}
		if (isset($_POST['is_hack'])) {
			hackJudged();
		} elseif (isset($_POST['is_custom_test'])) {
			customTestSubmissionJudged();
		} else {
			submissionJudged();
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
	function querySubmissionToJudge($status, $set_q) {
		global $submission;
		$submission = DB::selectFirst("select id, problem_id, content from submissions where status = '$status' order by id limit 1");
		if ($submission) {
			DB::update("update submissions set $set_q where id = {$submission['id']} and status = '$status'");
			if (DB::affected_rows() != 1) {
				$submission = null;
			}
		}
	}
	function queryCustomTestSubmissionToJudge() {
		global $submission;
		$submission = DB::selectFirst("select id, problem_id, content from custom_test_submissions where judge_time is null order by id limit 1");
		if ($submission) {
			DB::update("update custom_test_submissions set judge_time = now(), status = 'Judging' where id = {$submission['id']} and judge_time is null");
			if (DB::affected_rows() != 1) {
				$submission = null;
			}
		}
		if ($submission) {
			$submission['is_custom_test'] = '';
		}
	}
	function queryHackToJudge() {
		global $hack;
		$hack = DB::selectFirst("select id, submission_id, input, input_type from hacks where judge_time is null order by id limit 1");
		if ($hack) {
			DB::update("update hacks set judge_time = now() where id = {$hack['id']} and judge_time is null");
			if (DB::affected_rows() != 1) {
				$hack = null;
			}
		}
	}
	function findSubmissionToJudge() {
		global $submission, $hack;
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
			return true;
		}
		return false;
	}
	
	
	
	// Older versions of judge_client send Python's False, which arrives as the string "False".
	if (isset($_POST['fetch_new']) && in_array(strtolower($_POST['fetch_new']), array('', '0', 'false'), true)) {
		die("Nothing to judge");
	}
	if (!findSubmissionToJudge()) {
		die("Nothing to judge");
	}
	
	$submission['id'] = (int)$submission['id'];
	$submission['problem_id'] = (int)$submission['problem_id'];
	$submission['problem_mtime'] = queryProblemDataMTime($submission['problem_id']);
	$submission['content'] = json_decode($submission['content']);
	
	if ($hack) {
		$submission['is_hack'] = "";
		$submission['hack']['id'] = (int)$hack['id'];
		$submission['hack']['input'] = $hack['input'];
		$submission['hack']['input_type'] = $hack['input_type'];
	}
	
	echo json_encode($submission);
?>
