<?php
	// The files that come with a problem for the people who read it: a tool to try a solution
	// with, a larger sample, a picture. They are no part of the data the problem is judged with.
	if (!($problem = problemOfPage())) {
		become404Page();
	}
	if (!can($myUser, 'problem.manage', $problem)) {
		become403Page();
	}
	$here = problemUrl($problem, '/manage/attachments');
	$error = domainHandleForms(attachmentForms('problem', $problem['id'], function($message, $type) use ($here) {
		domainFlash($message, $type);
		redirectTo($here);
	}));
	$flash = domainTakeFlash();
?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - 附件 - 题目管理') ?>
<h1 class="page-header" align="center">#<?= problemNumber($problem) ?> : <?= $problem['title'] ?> 管理</h1>
<?php echoProblemManageTabs($problem, 'attachments') ?>
<div class="text-left">
	<?php if ($flash): ?>
	<div class="alert alert-<?= $flash[0] ?>" role="alert"><?= HTML::escape($flash[1]) ?></div>
	<?php endif ?>
	<?php echoDomainError($error) ?>
	<p class="text-muted">附件显示在题面下面，能看到这道题的人都能下载：比赛或作业里的题，就是能在比赛或作业里看到它的人。它们和评测数据无关，改动不需要同步数据。</p>
	<?php echoAttachmentsManager(attachmentsOf('problem', $problem['id'])) ?>
</div>
<?php echoUOJPageFooter() ?>
