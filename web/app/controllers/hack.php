<?php
	requirePHPLib('form');
	
	if (!validateUInt($_GET['id']) || !($hack = queryHack($_GET['id']))) {
		become404Page();
	}
	$submission = querySubmission($hack['submission_id']);	
	$problem = queryProblemBrief($submission['problem_id']);
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

<?php if (isset($delete_form)): ?>
	<?php $delete_form->printHTML() ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
