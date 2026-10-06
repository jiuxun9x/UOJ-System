<?php
	// A problem is made with one form: its title and statement, what kind of problem it is and
	// how it is judged, its data and the files that come with it. In a domain it belongs to
	// the domain and is made by the people who teach there.
	requirePHPLib('judger');
	requirePHPLib('data');
	requirePHPLib('problem');

	$domain = isset($_GET['slug']) ? domainOfPage() : null;
	if ($domain ? !can($myUser, 'domain.teach', $domain) : !can($myUser, 'problem.create')) {
		become403Page();
	}
	$settings = problemDefaultSettings();
	// what is typed is kept in the browser under this name until the problem is made
	$draft_key = 'new-problem-' . ($domain ? 'd-' . $domain['slug'] : 'site');

	$error = domainHandleForms(array(
		'create' => function() use ($domain, $draft_key) {
			global $myUser;
			list($basics, $err) = problemBasicsFromForm($_POST);
			if ($err !== '') {
				return $err;
			}
			list($settings, $err) = problemSettingsFromForm($_POST);
			if ($err !== '') {
				return $err;
			}
			$id = problemCreateWithBasics($basics, $myUser, $domain);
			if ($id === null) {
				return '创建题目失败，请再试一次';
			}
			$problem = queryProblemBrief($id);
			// From here on the problem exists. What goes wrong with its files is told on the
			// page of its data, where it is put right, rather than undoing the problem.
			$notes = array();
			$all_well = true;

			list($added, $refused) = attachmentsAddUploaded('problem', $id, 'attachments', $myUser);
			if ($refused) {
				$notes[] = '有附件没有添加：' . join('；', $refused);
				$all_well = false;
			}
			list($uploaded, $message) = problemTakeUploadedData($problem, 'data', $myUser);
			if ($uploaded === 'refused') {
				$notes[] = '数据包没有被接受：' . $message;
				$all_well = false;
			}
			if ($uploaded === 'ok' && !problemAwaitsSetup($problem)) {
				// somebody who writes problem.conf says there what the form would have said
				$notes[] = '数据包里带有 problem.conf，按它来评测，没有使用表单里的题目类型和评测设置';
			} else {
				list($found, $err) = problemApplySettings($problem, $settings, $myUser);
				if ($err !== '') {
					$notes[] = '评测设置没有保存：' . $err;
					$all_well = false;
				} elseif ($uploaded === 'ok') {
					$notes = array_merge($notes, $found);
				}
			}
			if ($uploaded === 'ok' && problemUploadedTestCount($problem) > 0) {
				list($begun, $note) = problemSync($problem, $myUser);
				$notes[] = $note;
				$all_well = $all_well && $begun;
			} elseif ($uploaded !== 'refused') {
				$notes[] = '还没有测试数据：在这一页上传数据包后才能评测';
			}
			// the problem is made: the next page throws away what the browser kept of the form
			setcookie('uoj_draft_done', $draft_key, 0, '/');
			domainFlash('题目 #' . problemNumber($problem) . ' 已创建' . ($basics['is_hidden'] ? '，现在是隐藏的' : '') . '。' . join('。', $notes) . '。', $all_well ? 'success' : 'warning');
			redirectTo(problemUrl($problem, '/manage/data'));
		}
	));
	// a form that was refused is shown again with what was typed into it; the files have to be chosen again
	$typed = function($name, $default = '') {
		return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : $default;
	};
	if ($error !== '') {
		foreach (array('type', 'time_limit', 'memory_limit', 'checker', 'scoring') as $field) {
			$settings[$field] = $typed($field, $settings[$field]);
		}
		if (!isset(problemTypes()[$settings['type']])) {
			$settings['type'] = 'traditional';
		}
		$settings['n_samples'] = validateUInt($typed('n_samples')) ? (int)$typed('n_samples') : null;
		$settings['passes'] = validateUInt($typed('passes')) ? (int)$typed('passes') : 2;
		$typed_subtasks = $typed('subtasks');
	}
	$limits = uploadLimits();
?>
<?php if ($domain): ?>
<?php echoDomainPageHeader($domain, 'problems', '新建题目') ?>
<p class="uoj-domain-back"><a href="<?= domainUrl($domain, '/problems') ?>"><span class="glyphicon glyphicon-chevron-left"></span> 题目</a></p>
<?php else: ?>
<?php echoUOJPageHeader('新建题目') ?>
<?php endif ?>
<h2 class="mb-3">新建题目</h2>
<?php echoDomainError($error) ?>
<?php if ($error !== ''): ?>
<div class="alert alert-warning py-2">上面的问题改好后再提交。数据包和附件需要重新选择。</div>
<?php endif ?>
<div class="alert alert-info py-2" id="draft-note" style="display:none; max-width:60em"></div>
<form method="post" enctype="multipart/form-data" id="form-new-problem" class="text-left" style="max-width:60em">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="create" />

	<div class="card mb-3">
		<div class="card-header">① 题目</div>
		<div class="card-body">
			<div class="form-group">
				<label for="input-problem-title">标题</label>
				<input type="text" class="form-control" id="input-problem-title" name="title" maxlength="100" required="required" value="<?= HTML::escape($typed('title')) ?>" />
			</div>
			<div class="form-group">
				<label for="input-problem-statement">题面 <small class="text-muted">（Markdown，公式用 $…$）</small></label>
				<textarea class="form-control" id="input-problem-statement" name="statement_md" rows="12" style="font-family:monospace" placeholder="### 题目描述&#10;&#10;### 输入格式&#10;&#10;### 输出格式&#10;&#10;### 样例&#10;&#10;### 数据范围"><?= HTML::escape($typed('statement_md')) ?></textarea>
				<small class="form-text text-muted">可以先留空。创建之后在“题面”页里用带预览的编辑器修改。这里写的内容会自动留在这个浏览器里，直到题目创建成功。</small>
			</div>
			<div class="form-row">
				<div class="form-group col-md-7">
					<label for="input-problem-tags">标签 <small class="text-muted">（可选，用逗号分开）</small></label>
					<input type="text" class="form-control" id="input-problem-tags" name="tags" value="<?= HTML::escape($typed('tags')) ?>" placeholder="例如 动态规划, 图论" />
				</div>
				<div class="form-group col-md-5 d-flex align-items-end">
					<div class="custom-control custom-checkbox mb-2">
						<input type="checkbox" class="custom-control-input" id="input-problem-public" name="public"<?= isset($_POST['public']) ? ' checked="checked"' : '' ?> />
						<label class="custom-control-label" for="input-problem-public">创建后<?= $domain ? '对本域的成员' : '对所有人' ?>公开</label>
					</div>
				</div>
			</div>
			<small class="form-text text-muted">不勾选时题目是隐藏的，只有管理它的人能看到；比赛<?= $domain ? '和作业' : '' ?>用的题通常保持隐藏。</small>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">② 类型与评测</div>
		<div class="card-body">
			<?php uojIncludeView('problem-settings-form', array('settings' => $settings)) ?>
			<?php if (isset($typed_subtasks)): ?>
			<script type="text/javascript">$('textarea[name=subtasks]').val(<?= json_encode($typed_subtasks) ?>);</script>
			<?php endif ?>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">③ 测试数据</div>
		<div class="card-body">
			<div class="form-group mb-2">
				<input type="file" class="form-control-file" id="input-problem-data" name="data" accept=".zip,application/zip" />
			</div>
			<small class="form-text text-muted">
				一个 zip 压缩包，所有文件直接放在里面（放在一个文件夹里也可以）。可以先不传，创建之后在“数据与评测”页上传。
				<ul class="mb-1 mt-1 pl-3">
					<li><strong>测试点会自动识别</strong>：输入、输出文件成对即可，比如 <code>1.in</code> 和 <code>1.out</code>（或 <code>1.ans</code>）、<code>input1.txt</code> 和 <code>output1.txt</code>，按文件名的自然顺序编号。</li>
					<li>文件名以 <code>sample</code> 或 <code>ex_</code> 开头的是样例和额外测试点，例如 <code>sample1.in</code>、<code>sample1.out</code>。</li>
					<li>校验器、交互器的源文件放在同一个包里，由评测机编译。叫 <code>checker.cpp</code>、<code>chk.cpp</code>、<code>interactor.cpp</code> 之类的会自动认出来；叫别的名字也行，创建之后在“数据与评测”页里选它是哪个文件。</li>
					<li>包里如果自带 <code>problem.conf</code>，就完全按它来，上面第 ② 步的选择不起作用。</li>
				</ul>
				解压后不超过 <?= round($limits['bytes'] / 1048576) ?> MB、<?= $limits['files'] ?> 个文件。
			</small>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">④ 附件 <small class="text-muted">（可选）</small></div>
		<div class="card-body">
			<input type="file" class="form-control-file" id="input-problem-attachments" name="attachments[]" multiple="multiple" />
			<small class="form-text text-muted">给做题的人下载的文件，显示在题面下面：本地测试工具、较大的样例、交互库的头文件等，可以一次选几个。它们和评测无关。</small>
		</div>
	</div>

	<button type="submit" class="btn btn-primary" id="button-create-problem">创建题目</button>
	<a class="btn btn-link" href="<?= $domain ? domainUrl($domain, '/problems') : '/problems' ?>">取消</a>
	<small class="form-text text-muted">创建之后这些都还能改。<?= $domain ? '本域的教师都能管理这道题。' : '你是这道题的管理者，可以在“管理者”页里添加别人。' ?></small>
</form>
<script type="text/javascript">
// What is typed here is kept in this browser until the problem is made: a page that is left
// or closed, or a login that ran out, does not take a statement with it.
$('#form-new-problem').uoj_form_draft(<?= json_encode($draft_key) ?>, ['title', 'statement_md', 'tags'], $('#draft-note'));
</script>
<?php echoUOJPageFooter() ?>
