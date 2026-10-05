<?php
	// A contest is made with one form: what it is called, when it is held, by which rule, who
	// may take part, and its problems. In a domain it belongs to the domain and is made by
	// the people who teach there.
	$domain = isset($_GET['slug']) ? domainOfPage() : null;
	if ($domain ? !can($myUser, 'domain.teach', $domain) : !can($myUser, 'contest.create')) {
		become403Page();
	}
	$may_rate = !$domain && can($myUser, 'contest.rate');
	$settings = contestDefaultSettings();
	// a contest of the site that an administrator makes counts for the ratings unless they say otherwise
	$settings['rated'] = $may_rate;
	$problem_numbers = isset($_POST['problems']) && is_string($_POST['problems']) ? $_POST['problems'] : '';

	$error = domainHandleForms(array(
		'create' => function() use ($domain, $may_rate, $settings, $problem_numbers) {
			global $myUser;
			list($checked, $err) = contestSettingsFromForm($_POST, $settings, $may_rate);
			if ($err !== '') {
				return $err;
			}
			list($problems, $err) = contestProblemsByNumbers(array('domain_id' => $domain ? $domain['id'] : null), $problem_numbers, $myUser);
			if ($err !== '') {
				return $err;
			}
			$contest_id = contestCreateWithSettings($checked, $problems, $myUser, $domain);
			// the files that were sent along: one that is refused does not undo the contest
			list($added, $refused) = attachmentsAddUploaded('contest', $contest_id, 'attachments', $myUser);
			domainFlash('比赛已创建。' . ($problems ? '' : '还没有试题，可以在“试题”页里添加。') . ($refused ? '有附件没有添加：' . join('；', $refused) : ''), $refused ? 'warning' : 'success');
			redirectTo("/contest/$contest_id/manage" . ($refused ? '#tab-attachments' : ($problems ? '' : '#tab-problems')));
		}
	));
	// a form that was refused is shown again with what was typed into it
	if ($error !== '') {
		foreach (array('name', 'start_time', 'last_min', 'rule', 'freeze_minutes', 'standings_version', 'rating_k', 'join_mode') as $field) {
			if (isset($_POST[$field]) && is_string($_POST[$field])) {
				$settings[$field] = $_POST[$field];
			}
		}
		$settings['rated'] = isset($_POST['rated']);
	}
?>
<?php if ($domain): ?>
<?php echoDomainPageHeader($domain, 'contests', '新建比赛') ?>
<p class="uoj-domain-back"><a href="<?= domainUrl($domain, '/contests') ?>"><span class="glyphicon glyphicon-chevron-left"></span> 比赛</a></p>
<?php else: ?>
<?php echoUOJPageHeader('新建比赛') ?>
<?php endif ?>
<h2 class="mb-3">新建比赛</h2>
<?php echoDomainError($error) ?>
<form method="post" enctype="multipart/form-data" id="form-new-contest" class="text-left" style="max-width:52em">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="create" />
	<?php uojIncludeView('contest-settings-form', array('settings' => $settings, 'may_rate' => $may_rate, 'in_domain' => $domain !== null, 'has_password' => false)) ?>
	<div class="form-group">
		<label for="input-contest-problems">试题</label>
		<input type="text" class="form-control" id="input-contest-problems" name="problems" placeholder="例如 12 15 23" value="<?= HTML::escape($problem_numbers) ?>" />
		<small class="form-text text-muted">
			<?= $domain ? '填本域的题号' : '填题号' ?>，用空格或逗号分开，按这里的顺序编为 A、B、C……只能加入你管理的题目。可以先留空，创建之后在“试题”页里添加、调整顺序。
			<?php if ($domain): ?>要用主站的题目，先在 <a href="<?= domainUrl($domain, '/problems') ?>">题目</a> 页把它复制到本域。<?php endif ?>
		</small>
	</div>
	<div class="form-group">
		<label for="input-contest-attachments">附件 <small class="text-muted">（可选）</small></label>
		<input type="file" class="form-control-file" id="input-contest-attachments" name="attachments[]" multiple="multiple" />
		<small class="form-text text-muted">整场比赛的附加文件，比如 PDF 题面，可以一次选几个。它们显示在比赛主页上，比赛开始之后能进入比赛的人才能下载。</small>
	</div>
	<button type="submit" class="btn btn-primary" id="button-create-contest">创建比赛</button>
	<a class="btn btn-link" href="<?= $domain ? domainUrl($domain, '/contests') : '/contests' ?>">取消</a>
	<small class="form-text text-muted">创建之后这些都还能改。管理者（负责人和助理）在创建之后添加。</small>
</form>
<?php echoUOJPageFooter() ?>
