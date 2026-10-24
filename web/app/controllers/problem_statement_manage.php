<?php
	requirePHPLib('form');
	requirePHPLib('problem');
	
	// the number in the address is the number of the problem where the address is: on the
	// site, or in a domain
	if (!($problem = problemOfPage())) {
		become404Page();
	}
	if (!can($myUser, 'problem.manage', $problem)) {
		become403Page();
	}
	
	// A copy takes its statement from the problem it was copied from once more when somebody
	// who manages it says so: with the files it shows, in the place of what the copy says now.
	$source = !empty($problem['source_problem_id']) ? queryProblemBrief($problem['source_problem_id']) : null;
	$may_refresh = $source && can($myUser, 'problem.copy', $source);
	$refresh_error = domainHandleForms(array(
		// a statement that is a PDF file, in a language
		'add_pdf_statement' => function() use ($problem) {
			global $myUser;
			$lang = isset($_POST['lang']) && is_string($_POST['lang']) ? trim($_POST['lang']) : '';
			if ($lang === 'other') {
				$lang = isset($_POST['other_lang']) && is_string($_POST['other_lang']) ? strtolower(trim($_POST['other_lang'])) : '';
			}
			if (!isset($_FILES['pdf']) || is_array($_FILES['pdf']['name']) || $_FILES['pdf']['error'] == UPLOAD_ERR_NO_FILE) {
				return '请选择 PDF 文件';
			}
			if ($_FILES['pdf']['error'] != UPLOAD_ERR_OK) {
				return 'PDF 没有传完（错误 ' . (int)$_FILES['pdf']['error'] . '），可能是文件太大';
			}
			$err = attachmentSetPdfStatement($problem['id'], $lang, $_FILES['pdf']['tmp_name'], $myUser);
			if ($err === '') {
				domainFlash(attachmentStatementLangName($lang) . '的 PDF 题面已上传，题目页上直接显示。');
			}
			return $err;
		},
		'delete_pdf_statement' => function() use ($problem) {
			global $myUser;
			foreach (attachmentPdfStatements($problem['id']) as $attachment) {
				if (isset($_POST['attachment_id']) && (string)$attachment['id'] === (string)$_POST['attachment_id']) {
					attachmentDelete($attachment, $myUser);
					domainFlash(attachmentStatementLangName($attachment['statement_lang']) . '的 PDF 题面已删除。');
					return '';
				}
			}
			return '没有这个 PDF 题面';
		},
		'refresh_statement' => function() use ($problem) {
			global $myUser;
			$err = domainRefreshCopyStatement($problem, $myUser);
			if ($err === '') {
				domainFlash('题面已换成来源题目现在的题面，附件也一并复制了。');
			}
			return $err;
		}
	), problemUrl($problem, '/manage/statement'));
	$flash = domainTakeFlash();

	$problem_content = queryProblemContent($problem['id']);
	$problem_tags = queryProblemTags($problem['id']);
	
	$problem_editor = new UOJBlogEditor();
	$problem_editor->name = 'problem';
	$problem_editor->blog_url = problemUrl($problem);
	$problem_editor->cur_data = array(
		'title' => $problem['title'],
		'content_md' => $problem_content['statement_md'],
		'content' => $problem_content['statement'],
		'tags' => $problem_tags,
		'is_hidden' => $problem['is_hidden']
	);
	$problem_editor->label_text = array_merge($problem_editor->label_text, array(
		'view blog' => '查看题目',
		'blog visibility' => '题目可见性'
	));
	
	// The page saves by itself a moment after the typing stops, and what it saves is what
	// the problem says from then on: a statement is not lost because nobody pressed a button.
	$problem_editor->autosave = true;
	$problem_editor->draft_key = 'statement-' . $problem['id'];
	
	$problem_editor->save = function($data) {
		global $problem, $problem_tags, $problem_content, $myUser;
		$before = array('title' => $problem['title'], 'is_hidden' => (int)$problem['is_hidden'], 'tags' => $problem_tags, 'statement_sha256' => hash('sha256', (string)$problem_content['statement_md']));
		$after = array('title' => $data['title'], 'is_hidden' => (int)$data['is_hidden'], 'tags' => $data['tags'], 'statement_sha256' => hash('sha256', $data['content_md']));
		if ($problem_content !== null && $before === $after) {
			// nothing to write, and nothing to write down
			return array();
		}
		DB::update("update problems set title = '".DB::escape($data['title'])."' where id = {$problem['id']}");
		if (!problemSaveStatement($problem['id'], $data['content'], $data['content_md'])) {
			// whoever saved is told, and does not walk away from a statement that is not kept
			return array('extra' => '题面没有保存下来：数据库拒绝了这次写入。请把内容复制到别处留底，然后再试一次。');
		}
		
		if ($data['tags'] !== $problem_tags) {
			DB::delete("delete from problems_tags where problem_id = {$problem['id']}");
			foreach ($data['tags'] as $tag) {
				DB::insert("insert into problems_tags (problem_id, tag) values ({$problem['id']}, '".DB::escape($tag)."')");
			}
		}
		if ($data['is_hidden'] != $problem['is_hidden'] ) {
			problemSetHidden($problem['id'], $data['is_hidden']);
		}
		
		// What the page saved by itself while somebody was writing is one change, not one for
		// every pause in the typing: it is written down once, with where the writing has got to.
		$written = false;
		if (isset($_POST['autosave'])) {
			$after['autosave'] = true;
			$last = DB::selectFirst("select id, after_json from audit_logs where action = 'problem.edit_statement' and resource_type = 'problem' and resource_id = '{$problem['id']}' and actor = '".DB::escape($myUser['username'])."' and created_at > date_sub(now(), interval 10 minute) order by id desc limit 1");
			$last_after = $last ? json_decode($last['after_json'], true) : null;
			if (is_array($last_after) && !empty($last_after['autosave']) && $last_after['is_hidden'] === $after['is_hidden']) {
				$written = (bool)DB::update("update audit_logs set after_json = '".DB::escape(json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))."' where id = {$last['id']}");
			}
		}
		if (!$written) {
			auditLog('problem.edit_statement', 'problem', $problem['id'], $before, $after);
		}
		return array();
	};
	
	$problem_editor->runAtServer();
?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - 编辑 - 题目管理') ?>
<h1 class="page-header" align="center">#<?= problemNumber($problem) ?> : <?=$problem['title']?> 管理</h1>
<?php echoProblemManageTabs($problem, 'statement') ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?> text-left" role="alert" id="statement-flash"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<?php echoDomainError($refresh_error) ?>
<?php if (!empty($problem['source_problem_id'])): ?>
<div class="card mb-3 text-left" id="statement-source">
	<div class="card-body py-2 d-flex flex-wrap align-items-center">
		<span class="mr-auto py-1">
			这道题<?= HTML::escape(problemSourceNote($problem['source_problem_id'], $problem['source_data_version'])) ?>。副本是一道独立的题：来源题目之后的修改不会自动同步到这里。
		</span>
		<?php if ($may_refresh): ?>
		<form method="post" class="py-1" onsubmit="return confirm('会用来源题目现在的题面和附件，替换这道题现在的题面。这里对题面做过的修改会丢失，确定吗？');">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="refresh_statement" />
			<button type="submit" class="btn btn-outline-secondary btn-sm" id="button-refresh-statement"><span class="glyphicon glyphicon-refresh"></span> 从来源更新题面</button>
		</form>
		<?php endif ?>
	</div>
</div>
<?php endif ?>
<?php $pdf_statements = attachmentPdfStatements($problem['id']); ?>
<div class="card mb-3 text-left" id="pdf-statements">
	<div class="card-header py-2">PDF 题面 <small class="text-muted">可以不传；传了就显示在题目页上，每种语言一份</small></div>
	<div class="card-body py-3">
		<?php if ($pdf_statements): ?>
		<table class="table table-sm mb-3" id="table-pdf-statements" style="max-width:44em">
			<thead><tr><th style="width:9em">语言</th><th>文件</th><th style="width:7em">大小</th><th style="width:5em"></th></tr></thead>
			<tbody>
				<?php foreach ($pdf_statements as $attachment): ?>
				<tr data-lang="<?= $attachment['statement_lang'] ?>">
					<td><?= HTML::escape(attachmentStatementLangName($attachment['statement_lang'])) ?> <small class="text-muted"><?= $attachment['statement_lang'] ?></small></td>
					<td><a href="<?= attachmentUrl($attachment) ?>" target="_blank" rel="noopener"><?= HTML::escape($attachment['name']) ?></a></td>
					<td><?= attachmentSizeText((int)$attachment['size']) ?></td>
					<td class="text-right">
						<form method="post" class="d-inline" onsubmit="return confirm('删除这份 PDF 题面？');">
							<?= HTML::hiddenToken() ?>
							<input type="hidden" name="form" value="delete_pdf_statement" />
							<input type="hidden" name="attachment_id" value="<?= (int)$attachment['id'] ?>" />
							<button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
						</form>
					</td>
				</tr>
				<?php endforeach ?>
			</tbody>
		</table>
		<?php endif ?>
		<form method="post" enctype="multipart/form-data" class="form-inline" id="form-pdf-statement">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_pdf_statement" />
			<label class="mr-2 mb-2" for="input-pdf-lang">语言</label>
			<select class="form-control mr-2 mb-2" id="input-pdf-lang" name="lang">
				<?php foreach (attachmentStatementLanguages() as $lang => $name): ?>
				<option value="<?= $lang ?>"><?= HTML::escape($name) ?>（<?= $lang ?>）</option>
				<?php endforeach ?>
				<option value="other">其他…</option>
			</select>
			<input type="text" class="form-control mr-2 mb-2" id="input-pdf-other-lang" name="other_lang" placeholder="语言标签，如 de" maxlength="10" style="width:9em;display:none" />
			<input type="file" class="form-control-file mr-2 mb-2" id="input-pdf" name="pdf" accept="application/pdf,.pdf" required="required" style="width:auto" />
			<button type="submit" class="btn btn-primary mb-2" id="button-upload-pdf-statement">上传</button>
		</form>
		<small class="form-text text-muted">同一种语言再传一次就是替换。PDF 显示在下面 Markdown 题面的下方；只用 PDF 时，Markdown 题面可以空着，或者只写几句说明。题目包里的 <code>zh_cn.pdf</code>、<code>en.pdf</code>、<code>statement.pdf</code> 导入时会自动成为 PDF 题面。</small>
	</div>
</div>
<script type="text/javascript">
$('#input-pdf-lang').on('change', function() {
	$('#input-pdf-other-lang').toggle($(this).val() === 'other');
});
</script>
<?php $problem_editor->printHTML() ?>
<?php echoUOJPageFooter() ?>
