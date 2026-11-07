<?php
	requirePHPLib('form');
	
	if (!validateUInt($_GET['id']) || !($hack = queryHack($_GET['id']))) {
		become404Page();
	}
	// a hack of a submission that was deleted since is gone with it
	if (!($submission = querySubmission($hack['submission_id'])) || !($problem = queryProblemBrief($submission['problem_id']))) {
		become404Page();
	}
	$problem_extra_config = getProblemExtraConfig($problem);

	if ($submission['contest_id']) {
		$contest = queryContest($submission['contest_id']);
		genMoreContestInfo($contest);
	} else {
		$contest = null;
	}

	if (!can($myUser, 'hack.view', $hack)) {
		become403Page();
	}
	
	if (can($myUser, 'hack.delete', $hack)) {
		$delete_form = new UOJForm('delete');
		$delete_form->handle = function() {
			global $hack;
			DB::query("delete from hacks where id = {$hack['id']}");
			auditLog('hack.delete', 'hack', $hack['id'], array('hacker' => $hack['hacker'], 'owner' => $hack['owner'], 'submission_id' => (int)$hack['submission_id'], 'success' => $hack['success']), null);
		};
		$delete_form->submit_button_config['class_str'] = 'btn btn-danger';
		$delete_form->submit_button_config['text'] = '删除此Hack';
		$delete_form->submit_button_config['align'] = 'right';
		$delete_form->submit_button_config['smart_confirm'] = '';
		$delete_form->succ_href = "/hacks";
		$delete_form->runAtServer();
	}
	
	// A hack whose judgement failed is judged again by who manages its problem, once the checker
	// (or what else of the problem went wrong) is put right: its input was kept for that.
	if ($hack['success'] !== null && $hack['result_error'] !== null && can($myUser, 'hack.rejudge', $hack)
			&& is_file(UOJContext::storagePath() . $hack['input'])) {
		$rejudge_form = new UOJForm('rejudge');
		$rejudge_form->handle = function() {
			global $hack, $myUser;
			DB::update("update hacks set success = null, result_error = null, judge_time = null, judger_name = null, judge_attempts = 0, details = '',"
				." judge_claim = null, judge_worker = null, judge_lease_until = null where id = {$hack['id']} and success is not null and result_error is not null");
			auditLog('hack.rejudge', 'hack', $hack['id'], array('result_error' => $hack['result_error']), null, $myUser);
		};
		$rejudge_form->submit_button_config['class_str'] = 'btn btn-primary';
		$rejudge_form->submit_button_config['text'] = '重新评测此 Hack';
		$rejudge_form->submit_button_config['align'] = 'right';
		$rejudge_form->succ_href = "/hack/{$hack['id']}";
		$rejudge_form->runAtServer();
	}

	$hack['submission'] = $submission;
	$should_show_content = can($myUser, 'hack.view_source', $hack);
	$should_show_all_details = $hack['success'] !== null && can($myUser, 'hack.view_details', $hack);
	$should_show_details_to_me = can($myUser, 'hack.view_final_details', $hack);
	
	if ($should_show_all_details) {
		$styler = new HackDetailsStyler();
		if (!can($myUser, 'hack.view_test_details', $hack)) {
			$styler->fade_all_details = true;
			$styler->show_small_tip = false;
		}
	}
?>
<?php
	$REQUIRE_LIB['hljs'] = "";
?>
<?php echoUOJPageHeader(UOJLocale::get('problems::hack').' #'.$hack['id']) ?>

<?php echoHackListOnlyOne($hack, array(), $myUser) ?>
<?php if ($should_show_all_details): ?>
	<div class="card border-info">
		<div class="card-header bg-info">
			<h4 class="card-title"><?= UOJLocale::get('details') ?></h4>
		</div>
		<div class="card-body">
			<?php echoJudgementDetails($hack['details'], $styler, 'details') ?>
			<?php if ($should_show_details_to_me): ?>
				<?php if ($styler->fade_all_details): ?>
					<hr />
					<?php echoHackDetails($hack['details'], 'final_details') ?>
				<?php endif ?>
			<?php endif ?>
		</div>
	</div>
<?php endif ?>
<?php echoSubmissionsListOnlyOne($submission, array(), $myUser) ?>
<?php if ($should_show_content): ?>
	<?php echoSubmissionContent($submission, getProblemSubmissionRequirement($problem)) ?>
<?php endif ?>

<?php if (isset($rejudge_form)): ?>
	<p class="text-muted text-right mb-1" id="hack-judgement-failed">这次 Hack 评测失败：题目的 checker、交互器、validator 或标准程序出了错（原因见上面的详细信息），不算成功也不算失败。修好之后可以重新评测。</p>
	<?php $rejudge_form->printHTML() ?>
<?php endif ?>
<?php if (isset($delete_form)): ?>
	<?php $delete_form->printHTML() ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
