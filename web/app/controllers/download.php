<?php
	requirePHPLib('judger');
	requirePHPLib('data');
	switch ($_GET['type']) {
		case 'problem':
			if (!validateUInt($_GET['id']) || !($problem = queryProblemBrief($_GET['id']))) {
				become404Page();
			}
			
			// whoever reads the problem, on its page or in a contest or a homework
			if (!can($myUser, 'problem.read', $problem)) {
				become404Page();
			}

			$id = $_GET['id'];
			
			$file_name = "/var/uoj_data/$id/download.zip";
			$download_name = "problem_$id.zip";
			break;
		case 'problem-data':
			// the complete data of a version of a problem, for the people who manage the problem
			if (!validateUInt($_GET['id']) || !($problem = queryProblemBrief($_GET['id']))) {
				become404Page();
			}
			if (!can($myUser, 'problem.manage', $problem)) {
				become404Page();
			}
			if (!validateUInt($_GET['version']) || !($version_row = queryProblemDataVersion($problem['id'], $_GET['version']))) {
				become404Page();
			}
			$file_name = dataArchiveOfVersion($version_row);
			if ($file_name === null) {
				become404Page();
			}
			$download_name = "problem_{$problem['id']}_data_{$version_row['version']}.zip";
			break;
		case 'testlib.h':
			$file_name = "/opt/uoj/judger/uoj_judger/include/testlib.h";
			$download_name = "testlib.h";
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
	
	header("X-Sendfile: $file_name");
	header("Content-type: $mimetype");
	header("Content-Disposition: attachment; filename=$download_name");
?>