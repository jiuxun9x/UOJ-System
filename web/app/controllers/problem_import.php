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

	// what is handed out for people to fill in: the statement of one problem, the settings
	// of one problem with what each of them does, and a package of problems as an example
	if (isset($_GET['template'])) {
		if ($_GET['template'] === 'zip') {
			$zip_path = importPackageTemplateZip();
			if ($zip_path === null) {
				become404Page();
			}
			header('Content-Type: application/zip');
			header("Content-Disposition: attachment; filename=\"problem-package-template.zip\"; filename*=UTF-8''" . rawurlencode('题目包模板.zip'));
			header('Content-Length: ' . filesize($zip_path));
			readfile($zip_path);
			unlink($zip_path);
			die();
		}
		if ($_GET['template'] === 'conf') {
			header('Content-Type: text/plain; charset=utf-8');
			header('Content-Disposition: attachment; filename="problem.conf"');
			die(importConfTemplateText());
		}
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
					<small class="form-text text-muted">题目包（.zip），或者填好的题面模板（.md）。一次最多导入 <?= UOJ_IMPORT_MAX_PROBLEMS ?> 道题，每道题的数据解压后不超过 <?= round($limits['bytes'] / 1048576) ?> MB。</small>
				</div>
				<div class="custom-control custom-checkbox mb-3">
					<input type="checkbox" class="custom-control-input" id="input-import-public" name="public" />
					<label class="custom-control-label" for="input-import-public">导入后<?= $domain ? '对本域的成员' : '对所有人' ?>公开</label>
					<small class="form-text text-muted">不勾选时导入的题目是隐藏的。模板里写了 <code>public</code> 的以模板为准。</small>
				</div>
				<div class="uoj-actions">
					<button type="submit" class="btn btn-primary" id="button-import-problems"><span class="glyphicon glyphicon-import"></span> 导入</button>
					<a class="btn btn-link" href="<?= $list ?>">返回题目列表</a>
				</div>
			</div>
		</form>
	</div>
	<div class="col-lg-5">
		<div class="card mb-3" id="import-about-package">
			<div class="card-header">题目包 <small class="text-muted">一次导入很多题，连同数据</small></div>
			<div class="card-body">
				<p class="mb-2">一个 zip，每道题一个文件夹：</p>
<pre class="mb-2 small">a-plus-b/
  problem.md          题面，开头几行写题目名称、标签
  problem.conf        评测设置（时限、比较方式、子任务……）
  data/               测试数据、校验器
  additional_file/    附件、题面里的图片</pre>
				<div class="uoj-actions">
					<a class="btn btn-primary btn-sm" id="link-import-package-template" href="<?= $here ?>?template=zip"><span class="glyphicon glyphicon-download-alt"></span> 下载题目包模板</a>
					<a class="btn btn-outline-secondary btn-sm" id="link-import-conf-template" href="<?= $here ?>?template=conf">只要 problem.conf</a>
				</div>
				<ul class="pl-3 mb-0 mt-3 small text-muted">
					<li>模板本身就能导入，里面有两道例题和一份完整的格式说明（<code>README.md</code>）。把说明和你的题目一起交给 AI，可以让它批量整理成这个格式。</li>
					<li><code>problem.conf</code> 里每个设置都有注释，用不到的默认注释掉了；注释在导入时自动去掉。</li>
					<li>不写 <code>n_tests</code> 时测试点按文件名自动识别（推荐）；写了就完全按 problem.conf 来，文件名不会改。</li>
				</ul>
			</div>
		</div>
		<div class="card mb-3" id="import-about-template">
			<div class="card-header">题面模板 <small class="text-muted">只建题目，数据之后再传</small></div>
			<div class="card-body">
				<p class="mb-2">一个 Markdown 文件：开头几行写题目名称、时间和内存限制、标签，后面是题面。想一次建很多题：每道题填一个，一起选上。</p>
				<a class="btn btn-outline-secondary btn-sm" id="link-import-template" href="<?= $here ?>?template=1"><span class="glyphicon glyphicon-download-alt"></span> 下载题面模板</a>
			</div>
		</div>
		<div class="card mb-3" id="import-about-hydro">
			<div class="card-header">从 Hydro 搬题</div>
			<div class="card-body small text-muted">
				Hydro 导出的题目包（<code>problem.yaml</code>、<code>problem_zh.md</code>、<code>testdata/config.yaml</code>）可以直接导入：
				<code>config.yaml</code> 会被翻译成本站的评测设置，翻译不了的地方在导入结果里逐条说明。
				两边的功能并不完全对应，所以建议整理成上面的格式，用 <code>problem.conf</code> 写评测设置；包里有 <code>problem.conf</code> 时不读 <code>config.yaml</code>。
			</div>
		</div>
	</div>
</div>
<?php echoUOJPageFooter() ?>
