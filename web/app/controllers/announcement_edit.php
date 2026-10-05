<?php
	// The form an announcement is written in, and changed in: its title, whether it stands
	// at the top, its text, and the pictures, films and other files that go with it.
	if (!can($myUser, 'announcement.manage')) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become403Page();
	}
	$announcement = null;
	if (isset($_GET['id'])) {
		$announcement = validateUInt($_GET['id']) ? queryAnnouncement($_GET['id']) : null;
		if (!$announcement) {
			become404Page();
		}
	}
	$here = $announcement ? announcementUrl($announcement['id'], '/edit') : '/announcement/new';

	$error = domainHandleForms(array(
		'save' => function() use ($announcement) {
			global $myUser;
			list($values, $err) = announcementFromForm($_POST);
			if ($err !== '') {
				return $err;
			}
			list($id, $err) = announcementSave($announcement ? $announcement['id'] : null, $values, $myUser);
			if ($err !== '') {
				return $err;
			}
			// From here on the announcement is there. A file that was not taken is told on
			// the page where it can be chosen again.
			list($added, $refused) = announcementAddMedia($id, 'media', $myUser);
			if ($refused) {
				domainFlash(($announcement ? '公告已保存。' : '公告已发布。') . '有文件没有加上：' . join('；', $refused), 'warning');
				redirectTo(announcementUrl($id, '/edit'));
			}
			domainFlash($announcement ? '公告已保存。' : '公告已发布。');
			redirectTo(announcementUrl($id));
		},
		'delete_media' => function() use ($announcement) {
			global $myUser;
			$attachment = $announcement && isset($_POST['attachment_id']) && validateUInt($_POST['attachment_id']) ? queryAttachment($_POST['attachment_id']) : null;
			if (!$attachment || $attachment['owner_type'] !== 'notice' || $attachment['owner_id'] != $announcement['id']) {
				return '没有这个文件';
			}
			attachmentDelete($attachment, $myUser);
			domainFlash('已删除 ' . $attachment['name'] . '。正文里用到它的那一行要自己删掉。');
			redirectTo(announcementUrl($announcement['id'], '/edit'));
		}
	));
	// what the form shows: what was typed when it was refused, else what the announcement has
	$typed = function($name, $default) {
		return isset($_POST['form']) && $_POST['form'] === 'save' && isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : $default;
	};
	$form = array(
		'title' => $typed('title', $announcement ? html_entity_decode($announcement['title'], ENT_QUOTES, 'UTF-8') : ''),
		'content_md' => $typed('content_md', $announcement ? $announcement['content_md'] : ''),
		'level' => (int)$typed('level', $announcement ? $announcement['level'] : 0)
	);
	$media = $announcement ? attachmentsOf('notice', $announcement['id']) : array();
	$limits = attachmentLimits();
?>
<?php echoUOJPageHeader($announcement ? '编辑公告' : '发布公告') ?>
<p class="uoj-domain-back text-left"><a href="<?= $announcement ? announcementUrl($announcement['id']) : '/announcements' ?>"><span class="glyphicon glyphicon-chevron-left"></span> <?= $announcement ? '回到公告' : '公告' ?></a></p>
<h2 class="mb-3 text-left"><?= $announcement ? '编辑公告' : '发布公告' ?></h2>
<?php echoDomainError($error) ?>
<?php $flash = domainTakeFlash(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?> text-left" role="alert" id="announcement-flash"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<form method="post" enctype="multipart/form-data" id="form-announcement" class="text-left" style="max-width:60em">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="save" />
	<div class="form-row">
		<div class="form-group col-md-8">
			<label for="input-announcement-title">标题</label>
			<input type="text" class="form-control" id="input-announcement-title" name="title" maxlength="200" required="required" value="<?= HTML::escape($form['title']) ?>" />
		</div>
		<div class="form-group col-md-4">
			<label for="input-announcement-level">置顶</label>
			<select class="form-control" id="input-announcement-level" name="level">
				<?php foreach (announcementLevels() as $level => $label): ?>
				<option value="<?= $level ?>"<?= $form['level'] === $level ? ' selected="selected"' : '' ?>><?= $label ?></option>
				<?php endforeach ?>
			</select>
		</div>
	</div>
	<div class="form-group">
		<label for="input-announcement-content">正文 <small class="text-muted">（Markdown，公式用 $…$）</small></label>
		<textarea class="form-control" id="input-announcement-content" name="content_md" rows="14" style="font-family:monospace"><?= HTML::escape($form['content_md']) ?></textarea>
	</div>
	<div class="form-group">
		<label for="input-announcement-media">图片、视频和其他文件 <small class="text-muted">（可选，可以一次选几个）</small></label>
		<input type="file" class="form-control-file" id="input-announcement-media" name="media[]" multiple="multiple" />
		<small class="form-text text-muted">
			选好之后点下面的按钮：图片直接显示在正文里，视频和音频可以在页面上播放，其他文件是下载链接，都先加在正文的末尾；
			想放到别的位置，保存后再来编辑，把正文里对应的那一行移过去。单个文件不超过 <?= attachmentSizeText($limits['file_bytes']) ?>，一条公告最多 <?= $limits['files'] ?> 个文件。
		</small>
	</div>
	<button type="submit" class="btn btn-primary" id="button-save-announcement"><?= $announcement ? '保存' : '发布公告' ?></button>
	<a class="btn btn-link" href="<?= $announcement ? announcementUrl($announcement['id']) : '/announcements' ?>">取消</a>
	<small class="form-text text-muted">公告对所有人可见，包括没有登录的人；页面上会写明是谁发布的。</small>
</form>
<?php if ($media): ?>
<h4 class="text-left mt-4">这条公告的文件</h4>
<div class="table-responsive text-left">
	<table class="table table-sm table-hover" id="table-announcement-media" style="max-width:60em">
		<thead><tr><th>文件</th><th style="width:7em">大小</th><th>写在正文里的样子</th><th style="width:5em"></th></tr></thead>
		<tbody>
			<?php foreach ($media as $attachment): ?>
			<tr data-attachment="<?= (int)$attachment['id'] ?>">
				<td><a href="<?= attachmentUrl($attachment) ?>" target="_blank" rel="noopener"><?= HTML::escape($attachment['name']) ?></a></td>
				<td><?= attachmentSizeText((int)$attachment['size']) ?></td>
				<td><input type="text" class="form-control form-control-sm uoj-domain-secret" readonly="readonly" value="<?= HTML::escape(announcementMediaSnippet($attachment)) ?>" onclick="this.select()" /></td>
				<td class="text-right">
					<form method="post" onsubmit="return confirm('删除这个文件？正文里用到它的那一行要自己删掉。');">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="delete_media" />
						<input type="hidden" name="attachment_id" value="<?= (int)$attachment['id'] ?>" />
						<button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
					</form>
				</td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
