<?php
	requirePHPLib('form');
	requirePHPLib('judger');
	
	if (!validateUInt($_GET['id']) || !($submission = querySubmission($_GET['id']))) {
		become404Page();
	}
	$submission_result = json_decode($submission['result'], true);
	
	$problem = queryProblemBrief($submission['problem_id']);
	$problem_extra_config = getProblemExtraConfig($problem);
	
	if ($submission['contest_id']) {
		$contest = queryContest($submission['contest_id']);
		genMoreContestInfo($contest);
	} else {
		$contest = null;
	}
	if (!can($myUser, 'submission.view', $submission)) {
		become403Page();
	}
	
	$out_status = explode(', ', $submission['status'])[0];
	
	if ($_GET['get'] == 'status-details' && Auth::check() && $submission['submitter'] === Auth::id()) {
		echo json_encode(array(
			'judged' => $out_status == 'Judged',
			'html' => getSubmissionStatusDetails($submission)
		));
		die();
	}
	
	$hackable = $submission['score'] == 100 && $problem['hackable'] == 1;
	// a visitor is shown the form and sent to the login when they use it
	if ($hackable && !can($myUser, $myUser == null ? 'submission.view_source' : 'submission.hack', $submission)) {
		$hackable = false;
	}
	if ($hackable) {
		$hack_form = new UOJForm('hack');	
		
		$hack_form->addTextFileInput('input', '输入数据');
		$hack_form->addCheckBox('use_formatter', '帮我整理文末回车、行末空格、换行符', true);
		$hack_form->handle = function(&$vdata) {
			global $myUser, $problem, $submission;
			if ($myUser == null) {
				redirectToLogin();
			}
			
			if ($_POST["input_upload_type"] == 'file') {
				$tmp_name = UOJForm::uploadedFileTmpName("input_file");
				if ($tmp_name == null) {
					becomeMsgPage('你在干啥……怎么什么都没交过来……？');
				}
			}
			
			$fileName = uojRandAvailableTmpFileName();
			$fileFullName = UOJContext::storagePath().$fileName;
			if ($_POST["input_upload_type"] == 'editor') {
				file_put_contents($fileFullName, $_POST['input_editor']);
			} else {
				move_uploaded_file($_FILES["input_file"]['tmp_name'], $fileFullName);
			}
			$input_type = isset($_POST['use_formatter']) ? "USE_FORMATTER" : "DONT_USE_FORMATTER";
			DB::insert("insert into hacks (problem_id, submission_id, hacker, owner, input, input_type, submit_time, details, is_hidden) values ({$problem['id']}, {$submission['id']}, '{$myUser['username']}', '{$submission['submitter']}', '$fileName', '$input_type', now(), '', {$problem['is_hidden']})");
		};
		$hack_form->succ_href = "/hacks";
		
		$hack_form->runAtServer();
	}

	if ($submission['status'] == 'Judged' && can($myUser, 'submission.rejudge', $submission)) {
		$rejudge_form = new UOJForm('rejudge');
		$rejudge_form->handle = function() {
			global $submission;
			rejudgeSubmission($submission);
			auditLog('submission.rejudge', 'submission', $submission['id'], array('score' => $submission['score']), null);
		};
		$rejudge_form->submit_button_config['class_str'] = 'btn btn-primary';
		$rejudge_form->submit_button_config['text'] = '重新测试';
		$rejudge_form->submit_button_config['align'] = 'right';
		$rejudge_form->runAtServer();
	}
	
	if (can($myUser, 'submission.delete', $submission)) {
		$delete_form = new UOJForm('delete');
		$delete_form->handle = function() {
			global $submission;
			$content = json_decode($submission['content'], true);
			unlink(UOJContext::storagePath().$content['file_name']);
			DB::delete("delete from submissions where id = {$submission['id']}");
			updateBestACSubmissions($submission['submitter'], $submission['problem_id']);
			auditLog('submission.delete', 'submission', $submission['id'], array('submitter' => $submission['submitter'], 'problem_id' => (int)$submission['problem_id'], 'contest_id' => $submission['contest_id'], 'score' => $submission['score'], 'submit_time' => $submission['submit_time']), null);
		};
		$delete_form->submit_button_config['class_str'] = 'btn btn-danger';
		$delete_form->submit_button_config['text'] = '删除此提交记录';
		$delete_form->submit_button_config['align'] = 'right';
		$delete_form->submit_button_config['smart_confirm'] = '';
		$delete_form->succ_href = "/submissions";
		$delete_form->runAtServer();
	}
	
	$is_contest_staff = $contest != null && can($myUser, 'contest.assist', $contest);
	$should_show_content = can($myUser, 'submission.view_source', $submission);
	$should_show_all_details = can($myUser, 'submission.view_details', $submission);
	$should_show_details_to_me = can($myUser, 'submission.view_final_details', $submission);
	if ($out_status != 'Judged' && !$is_contest_staff) {
		$should_show_all_details = false;
	}
	
	if ($should_show_all_details) {
		$styler = new SubmissionDetailsStyler();
		if (!can($myUser, 'submission.view_test_details', $submission)) {
			$styler->fade_all_details = true;
			$styler->show_small_tip = false;
		}
	}
	// While a contest runs, the owner of a submission is not told how it did on the single
	// tests. What the compiler said of a program that did not compile is told all the same:
	// it says nothing about the tests.
	$is_owner = $myUser != null && $submission['submitter'] === $myUser['username'];
	$details_wait_for_the_contest = !$should_show_all_details && $is_owner && $out_status == 'Judged'
		&& $contest != null && $contest['cur_progress'] == CONTEST_IN_PROGRESS;
	$show_compile_error = $details_wait_for_the_contest && $submission['result_error'] === 'Compile Error';
?>
<?php 
	$REQUIRE_LIB['hljs'] = "";
?>
<?php echoUOJPageHeader(UOJLocale::get('problems::submission').' #'.$submission['id']) ?>
<?php echoSubmissionsListOnlyOne($submission, array(), $myUser) ?>

<?php if (can($myUser, 'problem.manage', $problem)): ?>
	<?php
		// who judged the submission, with which data and which tools
		$judgements = DB::selectAll("select * from submission_judgements where kind = 'submission' and target_id = {$submission['id']} order by id desc limit 10");
		$judgement_outcome_names = array('judged' => '完成', 'reclaimed' => '评测机未完成，已重新排队', 'failed' => '多次未完成，判为失败', 'superseded' => '评测中被要求重测');
	?>
	<?php if ($judgements): ?>
	<div class="card border-secondary mb-3">
		<div class="card-header bg-secondary text-white">评测记录</div>
		<div class="card-body table-responsive">
			<table class="table table-bordered table-text-center mb-0">
				<thead>
					<tr>
						<th>开始时间</th>
						<th>结束时间</th>
						<th>评测机</th>
						<th>数据版本</th>
						<th>数据 SHA256</th>
						<th>评测机版本</th>
						<th>结果</th>
						<th>得分</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($judgements as $judgement): ?>
					<tr>
						<td><?= $judgement['started_at'] ?></td>
						<td><?= $judgement['finished_at'] ?></td>
						<td><?= HTML::escape($judgement['judger_name']) ?></td>
						<td><?= $judgement['problem_data_version'] ?></td>
						<td><code><?= substr($judgement['problem_data_sha256'], 0, 16) ?></code></td>
						<td><span title="<?= HTML::escape($judgement['toolchain']) ?>"><?= HTML::escape($judgement['judger_version']) ?></span></td>
						<td><?= $judgement['outcome'] === null ? '评测中' : $judgement_outcome_names[$judgement['outcome']] ?></td>
						<td><?= $judgement['score'] ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif ?>
<?php endif ?>

<?php if ($should_show_content): ?>
	<?php echoSubmissionContent($submission, getProblemSubmissionRequirement($problem)) ?>
	<?php if ($hackable): ?>
		<p class="text-center">
			这程序好像有点Bug，我给组数据试试？ <button id="button-display-hack" type="button" class="btn btn-danger btn-xs">Hack!</button>
		</p>
		<div id="div-form-hack" style="display:none" class="bot-buffer-md">
			<?php $hack_form->printHTML() ?>
		</div>
		<script type="text/javascript">
			$(document).ready(function() {
				$('#button-display-hack').click(function() {
					$('#div-form-hack').toggle('fast');
				});
			});
		</script>
	<?php endif ?>
<?php endif ?>

<?php if ($should_show_all_details): ?>
	<div class="card border-info mb-3">
		<div class="card-header bg-info">
			<h4 class="card-title"><?= UOJLocale::get('details') ?></h4>
		</div>
		<div class="card-body">
			<?php echoJudgementDetails($submission_result['details'], $styler, 'details') ?>
			<?php if ($should_show_details_to_me): ?>
				<?php if (isset($submission_result['final_result'])): ?>
					<hr />
					<?php echoSubmissionDetails($submission_result['final_result']['details'], 'final_details') ?>
				<?php endif ?>
				<?php if ($styler->fade_all_details): ?>
					<hr />
					<?php echoSubmissionDetails($submission_result['details'], 'final_details') ?>
				<?php endif ?>
			<?php endif ?>
		</div>
	</div>
<?php endif ?>

<?php if ($show_compile_error): ?>
	<div class="card border-info mb-3" id="compile-error">
		<div class="card-header bg-info">
			<h4 class="card-title"><?= UOJLocale::get('details') ?></h4>
		</div>
		<div class="card-body">
			<?php echoJudgementDetails($submission_result['details'], new SubmissionDetailsStyler(), 'details') ?>
		</div>
	</div>
<?php elseif ($details_wait_for_the_contest): ?>
	<p class="text-muted text-center" id="details-after-contest">比赛进行中不显示每个测试点的结果，比赛结束后可以在这里看到。</p>
<?php endif ?>

<?php if (isset($rejudge_form)): ?>
	<?php $rejudge_form->printHTML() ?>
<?php endif ?>

<?php if (isset($delete_form)): ?>
	<div class="top-buffer-sm">
		<?php $delete_form->printHTML() ?>
	</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
