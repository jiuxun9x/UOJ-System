<?php
	requirePHPLib('form');
	requirePHPLib('judger');
	
	// the number in the address is the number of the problem where the address is: on the
	// site, or in a domain
	if (!($problem = problemOfPage())) {
		become404Page();
	}
	
	$problem_content = queryProblemContent($problem['id']);
	
	// A problem of a domain is shown inside its domain, to the people of the domain.
	$domain = null;
	$homework = null;
	if (isset($_GET['slug'])) {
		$domain = domainOfPage();
		if ($problem['owner_domain_id'] != $domain['id']) {
			become404Page();
		}
		// A problem of a homework is reached through the homework, by whoever claimed it, once
		// it has begun. That it is hidden in the domain does not matter there.
		if (isset($_GET['homework_id'])) {
			$homework = queryHomework($_GET['homework_id']);
			if (!$homework || $homework['domain_id'] != $domain['id'] || !isset(homeworkProblemPoints($homework)[(int)$problem['id']]) || !can($myUser, 'homework.solve', $homework)) {
				become404Page();
			}
		}
	} elseif ($problem['owner_domain_id'] && !isset($_GET['contest_id'])) {
		$owner_domain = queryDomain($problem['owner_domain_id']);
		if (!$owner_domain || !can($myUser, 'problem.view', $problem)) {
			become404Page();
		}
		redirectTo(problemUrl($problem));
	}
	
	$contest = validateUInt($_GET['contest_id']) ? queryContest($_GET['contest_id']) : null;
	if ($contest != null) {
		genMoreContestInfo($contest);
		// a contest of a domain exists for the members of the domain
		if (!can($myUser, 'contest.view', $contest)) {
			become404Page();
		}
		$problem_rank = queryContestProblemRank($contest, $problem);
		if ($problem_rank == null) {
			become404Page();
		} else {
			$problem_letter = chr(ord('A') + $problem_rank - 1);
		}
	}
	
	$is_in_contest = false;
	$ban_in_contest = false;
	// who sits the contest virtually right now: array of the participation, or null
	$running_virtual = null;
	if ($contest != null) {
		if (!can($myUser, 'contest.assist', $contest)) {
			if ($contest['cur_progress'] == CONTEST_NOT_STARTED) {
				become404Page();
			} elseif ($contest['cur_progress'] == CONTEST_IN_PROGRESS) {
				if ($myUser == null || !hasRegistered($myUser, $contest)) {
					becomeMsgPage("<h1>比赛正在进行中</h1><p>很遗憾，您尚未报名。比赛结束后再来看吧～</p>");
				} else {
					$is_in_contest = true;
					DB::update("update contests_registrants set has_participated = 1 where username = '{$myUser['username']}' and contest_id = {$contest['id']}");
				}
			} else {
				// over: a contest that is not for everybody stays with the people who took part
				if (!can($myUser, 'contest.enter', $contest)) {
					become404Page();
				}
				$ban_in_contest = !can($myUser, 'problem.view', $problem);
			}
		}
		// Somebody who sits the contest virtually submits to its problems while that lasts,
		// to the ones that are still hidden as well: they are problems of the contest to them.
		if ($contest['cur_progress'] == CONTEST_FINISHED && can($myUser, 'contest.virtual', $contest)) {
			$running_virtual = runningVirtual($contest['id'], $myUser);
			if ($running_virtual) {
				$ban_in_contest = false;
			}
		}
	} elseif (!$homework) {
		if (!can($myUser, 'problem.view', $problem)) {
			become404Page();
		}
	}
	// what somebody who takes part submits through the homework is submitted to the homework
	$homework_participation = $homework && Auth::check() ? homeworkParticipation($homework['id'], Auth::id()) : null;
	$is_in_homework = $homework_participation && $homework_participation['status'] === 'active';

	$submission_requirement = json_decode($problem['submission_requirement'], true);
	$problem_extra_config = getProblemExtraConfig($problem);
	$custom_test_requirement = getProblemCustomTestRequirement($problem);

	if ($custom_test_requirement && Auth::check()) {
		$custom_test_submission = DB::selectFirst("select * from custom_test_submissions where submitter = '".Auth::id()."' and problem_id = {$problem['id']} order by id desc limit 1");
		$custom_test_submission_result = json_decode($custom_test_submission['result'], true);
	}
	if ($custom_test_requirement && $_GET['get'] == 'custom-test-status-details' && Auth::check()) {
		if ($custom_test_submission == null) {
			echo json_encode(null);
		} elseif ($custom_test_submission['status'] != 'Judged') {
			echo json_encode(array(
				'judged' => false,
				'html' => getSubmissionStatusDetails($custom_test_submission)
			));
		} else {
			ob_start();
			$styler = new CustomTestSubmissionDetailsStyler();
			if (!permissionViewTypeAllows('view_details_type', $myUser, array('problem_id' => $problem['id'], 'submitter' => null))) {
				$styler->fade_all_details = true;
			}
			echoJudgementDetails($custom_test_submission_result['details'], $styler, 'custom_test_details');
			$result = ob_get_contents();
			ob_end_clean();
			echo json_encode(array(
				'judged' => true,
				'html' => getSubmissionStatusDetails($custom_test_submission),
				'result' => $result
			));
		}
		die();
	}
	
	$can_use_zip_upload = true;
	foreach ($submission_requirement as $req) {
		if ($req['type'] == 'source code') {
			$can_use_zip_upload = false;
		}
	}
	
	function handleUpload($zip_file_name, $content, $tot_size) {
		global $problem, $contest, $myUser, $is_in_contest, $homework, $is_in_homework;
		
		$content['config'][] = array('problem_id', $problem['id']);
		if ($is_in_contest && contestJudgesSamplesOnly($contest, $problem['id'])) {
			$content['final_test_config'] = $content['config'];
			$content['config'][] = array('test_sample_only', 'on');
		}
		$esc_content = DB::escape(json_encode($content));

		$language = '/';
		foreach ($content['config'] as $row) {
			if (strEndWith($row[0], '_language')) {
				$language = $row[1];
				break;
			}
		}
		if ($language != '/') {
			Cookie::set('uoj_preferred_language', $language, time() + 60 * 60 * 24 * 365, '/');
		}
		$esc_language = DB::escape($language);
 		
		$result = array();
		$result['status'] = "Waiting";
		$result_json = json_encode($result);
		
		// what is submitted to a problem of a domain, or in a contest of one, belongs to the domain
		$domain_id = 'null';
		if ($problem['owner_domain_id']) {
			$domain_id = (int)$problem['owner_domain_id'];
		} elseif ($is_in_contest && $contest['domain_id']) {
			$domain_id = (int)$contest['domain_id'];
		}
		// The time of a submission is read off the clock that decides whether a contest or a
		// homework has begun or ended: the clock of the web server.
		$submit_time = UOJTime::$time_now_str;
		if ($is_in_contest) {
			DB::query("insert into submissions (problem_id, contest_id, domain_id, submit_time, submitter, content, language, tot_size, status, result, is_hidden) values (${problem['id']}, ${contest['id']}, $domain_id, '$submit_time', '${myUser['username']}', '$esc_content', '$esc_language', $tot_size, '${result['status']}', '$result_json', 0)");
		} elseif ($is_in_homework) {
			// who sees it is decided by the homework, not by whether the problem is hidden
			DB::query("insert into submissions (problem_id, domain_id, homework_id, submit_time, submitter, content, language, tot_size, status, result, is_hidden) values (${problem['id']}, $domain_id, ${homework['id']}, '$submit_time', '${myUser['username']}', '$esc_content', '$esc_language', $tot_size, '${result['status']}', '$result_json', 0)");
		} else {
			DB::query("insert into submissions (problem_id, domain_id, submit_time, submitter, content, language, tot_size, status, result, is_hidden) values (${problem['id']}, $domain_id, '$submit_time', '${myUser['username']}', '$esc_content', '$esc_language', $tot_size, '${result['status']}', '$result_json', {$problem['is_hidden']})");
		}
	}
	function handleCustomTestUpload($zip_file_name, $content, $tot_size) {
		global $problem, $contest, $myUser;
		
		$content['config'][] = array('problem_id', $problem['id']);
		$content['config'][] = array('custom_test', 'on');
		$esc_content = DB::escape(json_encode($content));

		$language = '/';
		foreach ($content['config'] as $row) {
			if (strEndWith($row[0], '_language')) {
				$language = $row[1];
				break;
			}
		}
		if ($language != '/') {
			Cookie::set('uoj_preferred_language', $language, time() + 60 * 60 * 24 * 365, '/');
		}
		$esc_language = DB::escape($language);
 		
		$result = array();
		$result['status'] = "Waiting";
		$result_json = json_encode($result);
		
		DB::insert("insert into custom_test_submissions (problem_id, submit_time, submitter, content, status, result) values ({$problem['id']}, now(), '{$myUser['username']}', '$esc_content', '{$result['status']}', '$result_json')");
	}
	
	// Where somebody is taken after submitting: to the list of what they submitted, in the
	// contest, the homework or the domain they submitted in.
	if ($is_in_contest) {
		$after_submitting = "/contest/{$contest['id']}/submissions";
	} elseif ($running_virtual) {
		$after_submitting = "/contest/{$contest['id']}/virtual";
	} elseif ($homework) {
		$after_submitting = '/submissions?homework_id=' . $homework['id'] . (Auth::check() ? '&submitter=' . Auth::id() : '');
	} elseif ($domain && Auth::check()) {
		$after_submitting = '/submissions?problem_id=' . $problem['id'] . '&submitter=' . Auth::id();
	} else {
		$after_submitting = '/submissions';
	}

	if ($can_use_zip_upload) {
		$zip_answer_form = newZipSubmissionForm('zip_answer',
			$submission_requirement,
			'uojRandAvailableSubmissionFileName',
			'handleUpload');
		$zip_answer_form->extra_validator = function() {
			global $ban_in_contest;
			if ($ban_in_contest) {
				return '请耐心等待比赛结束后题目对所有人可见了再提交';
			}
			return '';
		};
		$zip_answer_form->succ_href = $after_submitting;
		$zip_answer_form->runAtServer();
	}
	
	$answer_form = newSubmissionForm('answer',
		$submission_requirement,
		'uojRandAvailableSubmissionFileName',
		'handleUpload');
	$answer_form->extra_validator = function() {
		global $ban_in_contest;
		if ($ban_in_contest) {
			return '请耐心等待比赛结束后题目对所有人可见了再提交';
		}
		return '';
	};
	$answer_form->succ_href = $after_submitting;
	$answer_form->runAtServer();

	if ($custom_test_requirement) {
		$custom_test_form = newSubmissionForm('custom_test',
			$custom_test_requirement,
			function() {
				return uojRandAvailableFileName('/tmp/');
			},
			'handleCustomTestUpload');
		$custom_test_form->appendHTML(<<<EOD
<div id="div-custom_test_result"></div>
EOD
		);
		$custom_test_form->succ_href = 'none';
		$custom_test_form->extra_validator = function() {
			global $ban_in_contest, $custom_test_submission;
			if ($ban_in_contest) {
				return '请耐心等待比赛结束后题目对所有人可见了再提交';
			}
			if ($custom_test_submission && $custom_test_submission['status'] != 'Judged') {
				return '上一个测评尚未结束';
			}
			return '';
		};
		$custom_test_form->ctrl_enter_submit = true;
		$custom_test_form->setAjaxSubmit(<<<EOD
function(response_text) {custom_test_onsubmit(response_text, $('#div-custom_test_result')[0], '{$_SERVER['REQUEST_URI']}?get=custom-test-status-details')}
EOD
		);
		$custom_test_form->submit_button_config['text'] = UOJLocale::get('problems::run');
		$custom_test_form->runAtServer();
	}
?>
<?php
	$REQUIRE_LIB['mathjax'] = '';
	$REQUIRE_LIB['hljs'] = '';
?>
<?php if ($domain): ?>
<?php echoDomainPageHeader($domain, $homework ? 'homeworks' : 'problems', HTML::stripTags($problem['title'])) ?>
<?php if ($homework): ?>
<p class="uoj-domain-back"><a href="<?= homeworkUrl($domain, $homework) ?>"><span class="glyphicon glyphicon-chevron-left"></span> <?= HTML::escape($homework['title']) ?></a>
<?php $homework_phase = homeworkPhaseName(homeworkPhase($homework, homeworkNow())); ?>
<span class="badge <?= $homework_phase[1] ?>"><?= $homework_phase[0] ?></span>
<?php if (!$is_in_homework): ?><span class="text-muted">你没有参加这个作业，在这里提交不计入作业成绩。</span><?php endif ?></p>
<?php endif ?>
<?php else: ?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - ' . UOJLocale::get('problems::problem')) ?>
<?php if (Auth::check() && !$contest): ?>
<?php foreach (DB::selectAll("select homeworks.*, copies.domain_pid as copy_number from homework_problems, problems as copies, homeworks, homework_participants where copies.id = homework_problems.problem_id and copies.source_problem_id = {$problem['id']} and homeworks.id = homework_problems.homework_id and ".runningHomeworksCond()." and homework_participants.homework_id = homeworks.id and homework_participants.username = '".DB::escape(Auth::id())."' and homework_participants.status = 'active'") as $my_homework): ?>
<?php $my_homework_domain = queryDomain($my_homework['domain_id']); ?>
<div class="alert alert-info" role="alert">这道题是你的作业 <a href="<?= homeworkUrl($my_homework_domain, $my_homework) ?>"><?= HTML::escape($my_homework['title']) ?></a> 里的题目。在这里提交不计入作业成绩，请到 <a href="<?= homeworkUrl($my_homework_domain, $my_homework, '/problem/' . $my_homework['copy_number']) ?>">作业里提交</a>。</div>
<?php endforeach ?>
<?php endif ?>
<?php endif ?>
<?php
	$limit = getUOJConf("/var/uoj_data/{$problem['id']}/problem.conf");
	$time_limit = $limit['time_limit'];
	$memory_limit = $limit['memory_limit'];
?>
<div class="row d-flex justify-content-center">
	<span class="badge badge-secondary mr-1">时间限制:<?=$time_limit!=null?"$time_limit s":"N/A"?></span>
	<span class="badge badge-secondary mr-1">空间限制:<?=$memory_limit!=null?"$memory_limit MB":"N/A"?></span>
</div>
<div class="float-right">
	<?= getClickZanBlock('P', $problem['id'], $problem['zan']) ?>
</div>

<?php if ($contest): ?>
<div class="page-header row">
	<h1 class="col-md-3 text-left"><small><?= $contest['name'] ?></small></h1>
	<h1 class="col-md-7 text-center"><?= $problem_letter ?>. <?= $problem['title'] ?></h1>
	<div class="col-md-2 text-right" id="contest-countdown"></div>
</div>
<a role="button" class="btn btn-info float-right" href="<?= contestProblemUrl($contest['id'], $problem, '/statistics') ?>"><span class="glyphicon glyphicon-stats"></span> <?= UOJLocale::get('problems::statistics') ?></a>
<?php if ($contest['cur_progress'] <= CONTEST_IN_PROGRESS): ?>
<script type="text/javascript">
checkContestNotice(<?= $contest['id'] ?>, '<?= UOJTime::$time_now_str ?>');
$('#contest-countdown').countdown(<?= $contest['end_time']->getTimestamp() - UOJTime::$time_now->getTimestamp() ?>);
</script>
<?php elseif ($running_virtual): ?>
<div class="alert alert-success py-2 clearfix" id="virtual-banner">
	虚拟参赛进行中，这道题的提交会计入你的虚拟成绩。
	<a class="alert-link" href="/contest/<?= $contest['id'] ?>/virtual">回到虚拟参赛</a>
</div>
<script type="text/javascript">
$('#contest-countdown').countdown(<?= strtotime($running_virtual['start_time']) + $running_virtual['last_min'] * 60 - UOJTime::$time_now->getTimestamp() ?>);
</script>
<?php endif ?>
<?php else: ?>
<h1 class="page-header text-center">#<?= problemNumber($problem) ?>. <?= $problem['title'] ?></h1>
<a role="button" class="btn btn-info float-right" href="<?= problemUrl($problem, '/statistics') ?>"><span class="glyphicon glyphicon-stats"></span> <?= UOJLocale::get('problems::statistics') ?></a>
<?php endif ?>

<ul class="nav nav-tabs" role="tablist">
	<li class="nav-item"><a class="nav-link active" href="#tab-statement" role="tab" data-toggle="tab"><span class="glyphicon glyphicon-book"></span> <?= UOJLocale::get('problems::statement') ?></a></li>
	<li class="nav-item"><a class="nav-link" href="#tab-submit-answer" role="tab" data-toggle="tab"><span class="glyphicon glyphicon-upload"></span> <?= UOJLocale::get('problems::submit') ?></a></li>
	<?php if ($custom_test_requirement): ?>
	<li class="nav-item"><a class="nav-link" href="#tab-custom-test" role="tab" data-toggle="tab"><span class="glyphicon glyphicon-console"></span> <?= UOJLocale::get('problems::custom test') ?></a></li>
	<?php endif ?>
	<?php if (can($myUser, 'problem.manage', $problem)): ?>
	<li class="nav-item"><a class="nav-link" href="<?= problemUrl($problem, '/manage/statement') ?>" role="tab"><?= UOJLocale::get('problems::manage') ?></a></li>
	<?php endif ?>
	<?php if ($contest): ?>
	<li class="nav-item"><a class="nav-link" href="/contest/<?= $contest['id'] ?>" role="tab"><?= UOJLocale::get('contests::back to the contest') ?></a></li>
	<?php endif ?>
</ul>
<div class="tab-content">
	<div class="tab-pane active" id="tab-statement">
		<article class="top-buffer-md"><?= $problem_content['statement'] ?></article>
		<?php echoAttachments(attachmentsOf('problem', $problem['id'])) ?>
	</div>
	<div class="tab-pane" id="tab-submit-answer">
		<div class="top-buffer-sm"></div>
		<?php if ($can_use_zip_upload): ?>
		<?php $zip_answer_form->printHTML(); ?>
		<hr />
		<strong><?= UOJLocale::get('problems::or upload files one by one') ?><br /></strong>
		<?php endif ?>
		<?php $answer_form->printHTML(); ?>
	</div>
	<?php if ($custom_test_requirement): ?>
	<div class="tab-pane" id="tab-custom-test">
		<div class="top-buffer-sm"></div>
		<?php $custom_test_form->printHTML(); ?>
	</div>
	<?php endif ?>
</div>
<?php echoUOJPageFooter() ?>
