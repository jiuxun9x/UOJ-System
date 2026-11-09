<?php
	requirePHPLib('judger');
	requirePHPLib('data');
	
	requireJudgerAuthentication();
	// An account that is turned off is given nothing more: no task, and no data of the problems
	// either. (What a judger of it says of the task it had is still taken, see judge/submit.)
	if (!$uojJudger['enabled']) {
		header($_SERVER['SERVER_PROTOCOL'] . " 403 Forbidden", true, 403);
		die("this judging account is turned off");
	}
	
	// the names that make the paths of the files are what they can be, and nothing that leaves
	// the folders of the files
	if (isset($_GET['rand_str_id']) && !(is_string($_GET['rand_str_id']) && preg_match('/^[A-Za-z0-9]{1,64}$/D', $_GET['rand_str_id']))) {
		become404Page();
	}
	switch ($_GET['type']) {
		case 'submission':
			if (!validateUInt($_GET['id']) || !isset($_GET['rand_str_id'])) {
				become404Page();
			}
			$file_name = UOJContext::storagePath()."/submission/{$_GET['id']}/{$_GET['rand_str_id']}";
			$download_name = "submission.zip";
			break;
		case 'tmp':
			if (!isset($_GET['rand_str_id'])) {
				become404Page();
			}
			$file_name = UOJContext::storagePath()."/tmp/{$_GET['rand_str_id']}";
			$download_name = "tmp";
			break;
		case 'problem':
			$id = $_GET['id'];
			if (!validateUInt($id) || !($problem = queryProblemBrief($id))) {
				become404Page();
			}
			if (isset($_GET['version'])) {
				// a version that is published, that waits for a judger, or whose archive was kept
				$version_row = queryProblemDataVersion($id, $_GET['version']);
				$file_name = $version_row ? dataArchiveOfVersion($version_row) : null;
				if ($file_name === null) {
					become404Page();
				}
			} else {
				$file_name = "/var/uoj_data/$id.zip";
			}
			$download_name = "$id.zip";
			break;
		case 'answers':
			// the inputs and the standard program of a job of making answers, while it is being done
			if (!validateUInt($_GET['id']) || !DB::selectFirst("select id from problem_answer_jobs where id = {$_GET['id']} and status = 'running'")) {
				become404Page();
			}
			$file_name = dataAnswerJobArchive($_GET['id']);
			$download_name = "answers.zip";
			break;
		case 'judger':
			$file_name = UOJContext::storagePath()."/judge_client.zip";
			$download_name = "judge_client.zip";
			break;
		default:
			become404Page();
	}
	
	$finfo = finfo_open(FILEINFO_MIME);
	$mimetype = finfo_file($finfo, $file_name);
	if ($mimetype === false) {
		become404Page();
	}
	finfo_close($finfo);
	
	header("X-UOJ-SHA256: " . hash_file('sha256', $file_name));
	header("X-Sendfile: $file_name");
	header("Content-type: $mimetype");
	header("Content-Disposition: attachment; filename=$download_name");
?>
