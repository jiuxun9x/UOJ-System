<?php
	// Deleting a problem: with everything that is the problem's, and only when nothing needs
	// it any more. See problemDelete().
	requirePHPLib('judger');
	requirePHPLib('data');
	requirePHPLib('problem');

	if (!($problem = problemOfPage())) {
		become404Page();
	}
	if (!can($myUser, 'problem.manage', $problem)) {
		become403Page();
	}
	$domain = $problem['owner_domain_id'] ? queryDomain($problem['owner_domain_id']) : null;
	$word = problemDeletionWord($problem);

	$error = domainHandleForms(array(
		'delete_problem' => function() use ($problem, $domain, $word) {
			global $myUser;
			$typed = isset($_POST['confirm']) && is_string($_POST['confirm']) ? trim($_POST['confirm']) : '';
			if ($typed !== $word) {
				return '输入的题目名称和这道题的不一样，题目没有删除';
			}
			$err = problemDelete($problem, $myUser);
			if ($err !== '') {
				return $err;
			}
			domainFlash('题目 #' . problemNumber($problem) . '“' . $word . '”已删除。');
			redirectTo($domain ? domainUrl($domain, '/problems') : '/problems');
		}
	));
	$uses = problemUses($problem);
	$facts = problemDeletionFacts($problem);
?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - 删除 - 题目管理') ?>
<h1 class="page-header" align="center">#<?= problemNumber($problem) ?> : <?= $problem['title'] ?> 管理</h1>
<?php echoProblemManageTabs($problem, 'delete') ?>
<?php echoDomainError($error) ?>
<div class="card border-danger text-left" id="card-delete-problem" style="max-width:48em">
	<div class="card-header bg-danger text-white">删除这道题</div>
	<div class="card-body">
		<p class="mb-2">删除之后<strong>不能恢复</strong>。和这道题一起删除的有：</p>
		<ul id="problem-deletion-facts">
			<li>题面、标签，以及全部 <?= $facts['data_files'] ?> 个数据文件和已经发布的数据</li>
			<li><strong><?= $facts['submissions'] ?></strong> 份提交<?= $facts['submissions'] > 0 ? '（来自 ' . $facts['submitters'] . ' 个人），包括他们的代码和评测结果' : '' ?></li>
			<?php if ($facts['hacks'] > 0): ?><li><?= $facts['hacks'] ?> 次 Hack</li><?php endif ?>
			<?php if ($facts['attachments'] > 0): ?><li><?= $facts['attachments'] ?> 个附件</li><?php endif ?>
		</ul>
		<p class="text-muted small">题号 #<?= problemNumber($problem) ?> 之后不会再分给别的题。站点每天的自动备份里还留着删除之前的样子，要找回只能从备份恢复。</p>
		<?php if ($uses): ?>
		<div class="alert alert-warning mb-0" id="problem-in-use">
			现在还不能删除：这道题还在下面这些地方用着。请先把它从那里移出。
			<ul class="mb-0 mt-1">
				<?php foreach ($uses as $use): ?>
				<li><?= HTML::escape($use) ?></li>
				<?php endforeach ?>
			</ul>
		</div>
		<?php else: ?>
		<form method="post" id="form-delete-problem">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="delete_problem" />
			<div class="form-group">
				<label for="input-confirm-delete">请输入这道题的名称 <strong><?= HTML::escape($word) ?></strong> 确认</label>
				<input type="text" class="form-control" id="input-confirm-delete" name="confirm" autocomplete="off" required="required" />
			</div>
			<button type="submit" class="btn btn-danger" id="button-delete-problem">删除这道题和它的全部提交</button>
		</form>
		<?php endif ?>
	</div>
</div>
<?php echoUOJPageFooter() ?>
