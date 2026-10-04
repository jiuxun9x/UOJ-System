<?php
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
	// the announcement that is being edited, if any
	$editing = null;
	if ($can_teach && isset($_GET['edit']) && validateUInt($_GET['edit'])) {
		$editing = DB::selectFirst("select * from domain_announcements where id = {$_GET['edit']} and domain_id = {$domain['id']}");
	}
	$draft = array(
		'title' => isset($_POST['title']) && is_string($_POST['title']) ? $_POST['title'] : ($editing ? $editing['title'] : ''),
		'content_md' => isset($_POST['content_md']) && is_string($_POST['content_md']) ? $_POST['content_md'] : ($editing ? $editing['content_md'] : ''),
		'pinned' => isset($_POST['form']) ? isset($_POST['pinned']) : ($editing ? (bool)$editing['pinned'] : false)
	);
	
	$error = domainHandleForms(array(
		'save' => function() use ($domain, $can_teach, $editing, $draft) {
			global $myUser;
			if (!$can_teach) {
				return '没有权限';
			}
			$err = domainSaveAnnouncement($domain, $editing ? $editing['id'] : null, $draft['title'], $draft['content_md'], $draft['pinned'], $myUser);
			if ($err === '') {
				domainFlash($editing ? '公告已修改。' : '公告已发布。');
			}
			return $err;
		},
		'delete' => function() use ($domain, $can_teach) {
			global $myUser;
			if (!$can_teach) {
				return '没有权限';
			}
			$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
			DB::delete("delete from domain_announcements where id = $id and domain_id = {$domain['id']}");
			if (DB::affected_rows() == 1) {
				auditLog('domain.delete_announcement', 'domain', $domain['id'], array('announcement_id' => $id), null);
				domainFlash('公告已删除。');
			}
			return '';
		}
	));
	
	$announcements = domainAnnouncements($domain);
	$REQUIRE_LIB['mathjax'] = '';
	$REQUIRE_LIB['hljs'] = '';
?>
<?php echoDomainPageHeader($domain, 'announcements', '公告') ?>
<?php echoDomainError($error) ?>

<?php if ($can_teach): ?>
<div class="card mb-4" id="card-announcement-form">
	<div class="card-header"><?= $editing ? '修改公告' : '发布公告' ?></div>
	<div class="card-body">
		<form method="post" id="form-announcement">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="save" />
			<div class="form-group">
				<input type="text" class="form-control" name="title" maxlength="200" required="required" placeholder="标题" value="<?= HTML::escape($draft['title']) ?>" />
			</div>
			<div class="form-group">
				<textarea class="form-control" name="content_md" rows="5" required="required" placeholder="正文，支持 Markdown 和数学公式"><?= HTML::escape($draft['content_md']) ?></textarea>
			</div>
			<div class="d-flex align-items-center">
				<div class="custom-control custom-checkbox mr-auto">
					<input type="checkbox" class="custom-control-input" id="input-pinned" name="pinned"<?= $draft['pinned'] ? ' checked="checked"' : '' ?> />
					<label class="custom-control-label" for="input-pinned">置顶</label>
				</div>
				<?php if ($editing): ?>
				<a class="btn btn-link" href="<?= domainUrl($domain, '/announcements') ?>">取消</a>
				<?php endif ?>
				<button type="submit" class="btn btn-primary"><?= $editing ? '保存' : '发布' ?></button>
			</div>
		</form>
	</div>
</div>
<?php endif ?>

<?php if (!$announcements): ?>
<div class="uoj-domain-empty">暂无公告</div>
<?php endif ?>
<?php foreach ($announcements as $announcement): ?>
<div class="card mb-3 uoj-domain-announcement" id="announcement-<?= $announcement['id'] ?>">
	<div class="card-body">
		<h4 class="card-title">
			<?php if ($announcement['pinned']): ?><span class="badge badge-warning">置顶</span><?php endif ?>
			<?= HTML::escape($announcement['title']) ?>
		</h4>
		<p class="text-muted small">
			<?= getUserLink($announcement['created_by']) ?> 发布于 <?= substr($announcement['created_at'], 0, 16) ?>
			<?php if ($announcement['updated_at'] > $announcement['created_at']): ?>，修改于 <?= substr($announcement['updated_at'], 0, 16) ?><?php endif ?>
		</p>
		<article class="uoj-article"><?= $announcement['content'] ?></article>
	</div>
	<?php if ($can_teach): ?>
	<div class="card-footer bg-white text-right">
		<a class="btn btn-outline-secondary btn-sm" href="<?= domainUrl($domain, '/announcements') ?>?edit=<?= $announcement['id'] ?>">修改</a>
		<form method="post" class="d-inline" onsubmit="return confirm('确定要删除这条公告吗？');">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="delete" />
			<input type="hidden" name="id" value="<?= $announcement['id'] ?>" />
			<button type="submit" class="btn btn-outline-danger btn-sm">删除</button>
		</form>
	</div>
	<?php endif ?>
</div>
<?php endforeach ?>
<?php echoUOJPageFooter() ?>
