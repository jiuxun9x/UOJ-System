<?php
	requirePHPLib('judger');
	requirePHPLib('data');
	
	requireJudgerAuthentication();
	
	switch ($_GET['type']) {
		case 'submission':		
			$file_name = UOJContext::storagePath()."/submission/{$_GET['id']}/{$_GET['rand_str_id']}";
			$download_name = "submission.zip";
			break;
		case 'tmp':
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
