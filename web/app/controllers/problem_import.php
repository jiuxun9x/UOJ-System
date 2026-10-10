<?php
	// Problems that come from somewhere else: filled-in templates and whole packages, several
	// at once. See uoj-import-lib.php for what they are.
	requirePHPLib('judger');
	requirePHPLib('data');
	requirePHPLib('problem');
	requirePHPLib('import');

	$domain = isset($_GET['slug']) ? domainOfPage() : null;
	if ($domain ? !can($myUser, 'domain.teach', $domain) : !can($myUser, 'problem.create')) {
		become403Page();
	}
	$here = $domain ? domainUrl($domain, '/problems/import') : '/problems/import';
	$list = $domain ? domainUrl($domain, '/problems') : '/problems';

	// the template, for people to fill in
	if (isset($_GET['template'])) {
		header('Content-Type: text/markdown; charset=utf-8');
		header("Content-Disposition: attachment; filename=\"problem-template.md\"; filename*=UTF-8''" . rawurlencode('题目模板.md'));
		die(importTemplateText());
	}

	$results = null;
	$error = '';
	if (isset($_POST['form']) && $_POST['form'] === 'import') {
		if (!crsf_check()) {
			$error = '这个页面打开之后你重新登录过，这次提交没有生效。请再选一次文件。';
		} else {
			list($results, $error) = importUploaded('packages', $myUser, $domain, isset($_POST['public']));
		}
	}
	$limits = uploadLimits();
?>
<?php if ($domain): ?>
<?php echoDomainPageHeader($domain, 'problems', '导入题目') ?>
<p class="uoj-domain-back"><a href="<?= $list ?>"><span class="glyphicon glyphicon-chevron-left"></span> 题目</a></p>
<?php else: ?>
<?php echoUOJPageHeader('导入题目') ?>
<?php endif ?>
<h2 class="mb-3">导入题目</h2>
<?php echoDomainError($error) ?>

<?php if ($results): ?>
<div class="card mb-4" id="import-results">
	<div class="card-header">导入结果</div>
	<ul class="list-group list-group-flush">
		<?php foreach ($results as $result): ?>
		<li class="list-group-item" data-imported="<?= $result['problem'] ? (int)$result['problem']['id'] : '' ?>">
			<?php if ($result['problem']): ?>
			<span class="badge badge-success mr-2">已导入</span>
			<strong><a href="<?= problemUrl($result['problem']) ?>">#<?= problemNumber($result['problem']) ?>. <?= $result['problem']['title'] ?></a></strong>
			<?php if ($result['problem']['is_hidden']): ?><span class="badge badge-secondary">隐藏</span><?php endif ?>
			<a class="btn btn-light btn-sm border ml-2" href="<?= problemUrl($result['problem'], '/manage/data') ?>">数据与评测</a>
			<?php else: ?>
			<span class="badge badge-danger mr-2">没有导入</span>
			<span class="text-danger"><?= HTML::escape($result['error']) ?></span>
			<?php endif ?>
			<small class="text-muted d-block">来自 <?= HTML::escape($result['from']) ?></small>
			<?php if ($result['notes']): ?>
			<ul class="small text-muted mb-0 mt-1 pl-3">
				<?php foreach ($result['notes'] as $note): ?>
				<li><?= HTML::escape($note) ?></li>
				<?php endforeach ?>
			</ul>
			<?php endif ?>
		</li>
		<?php endforeach ?>
	</ul>
</div>
<?php endif ?>

<div class="row">
	<div class="col-lg-7 mb-3">
		<form method="post" enctype="multipart/form-data" id="form-import-problems" class="card">
			<div class="card-body">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="import" />
				<div class="form-group">
					<label for="input-import-packages">选择文件 <small class="text-muted">（可以一次选好几个）</small></label>
					<input type="file" class="form-control-file" id="input-import-packages" name="packages[]" multiple="multiple" accept=".md,.zip,text/markdown,application/zip" required="required" />
					<small class="form-text text-muted">填好的模板（.md），或者题目包（.zip）。一次最多导入 <?= UOJ_IMPORT_MAX_PROBLEMS ?> 道题，每道题的数据解压后不超过 <?= round($limits['bytes'] / 1048576) ?> MB。</small>
				</div>
				<div class="custom-control custom-checkbox mb-3">
					<input type="checkbox" class="custom-control-input" id="input-import-public" name="public" />
					<label class="custom-control-label" for="input-import-public">导入后<?= $domain ? '对本域的成员' : '对所有人' ?>公开</label>
					<small class="form-text text-muted">不勾选时导入的题目是隐藏的。模板里写了 <code>public</code> 的以模板为准。</small>
				</div>
				<button type="submit" class="btn btn-primary" id="button-import-problems"><span class="glyphicon glyphicon-import"></span> 导入</button>
				<a class="btn btn-link" href="<?= $list ?>">返回题目列表</a>
			</div>
		</form>
	</div>
	<div class="col-lg-5">
		<div class="card mb-3">
			<div class="card-header">题目模板</div>
			<div class="card-body">
				<p class="mb-2">一个 Markdown 文件：开头几行写题目名称、时间和内存限制、标签，后面是题面。填好后在左边选它，题目就建好了，数据之后再传。</p>
				<a class="btn btn-outline-primary btn-sm" id="link-import-template" href="<?= $here ?>?template=1"><span class="glyphicon glyphicon-download-alt"></span> 下载模板</a>
				<p class="text-muted small mb-0 mt-2">想一次建很多题：每道题填一个模板，一起选上，或者打成一个 zip。</p>
			</div>
		</div>
		<div class="card mb-3">
			<div class="card-header">题目包</div>
			<div class="card-body small">
				<p class="mb-2">和 Hydro 导出的题目包同一种格式，一个 zip 里可以有很多题，每道题一个文件夹：</p>
<pre class="mb-2">A/
  problem.yaml        题目名称（title）、标签（tag）
  problem_zh.md       题面
  testdata/           测试数据、校验器，和 config.yaml
  additional_file/    给做题的人下载的文件</pre>
				<ul class="pl-3 mb-0 text-muted">
					<li>时间、内存限制、子任务、校验器按 <code>testdata/config.yaml</code> 导入；没有 config.yaml 时测试点按文件名自动识别。</li>
					<li><code>testdata/</code> 里放本站的 <code>problem.conf</code> 的话，完全按它来评测。</li>
					<li>题面里写 <code>file://图片名</code> 的地方会换成导入后的附件地址。</li>
					<li>这里没有的功能（文件输入输出、非 testlib 的校验器、子任务依赖）会在导入结果里逐条说明。</li>
				</ul>
			</div>
		</div>
	</div>
</div>
<?php echoUOJPageFooter() ?>
