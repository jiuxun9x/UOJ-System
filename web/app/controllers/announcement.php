<?php
	// An announcement of the site, for everybody: what it says, who posted it and when.
	$announcement = isset($_GET['id']) && validateUInt($_GET['id']) ? queryAnnouncement($_GET['id']) : null;
	$can_manage = can($myUser, 'announcement.manage');
	if (!$announcement || ($announcement['is_hidden'] && !$can_manage)) {
		become404Page();
	}
	$error = domainHandleForms(array(
		'delete' => function() use ($announcement, $can_manage) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			$err = announcementDelete($announcement['id'], $myUser);
			if ($err === '') {
				domainFlash('公告已删除。');
				redirectTo('/announcements');
			}
			return $err;
		}
	));
	$loose_files = announcementLooseFiles($announcement);
	$REQUIRE_LIB['mathjax'] = '';
	$REQUIRE_LIB['shjs'] = '';
?>
<?php echoUOJPageHeader(HTML::stripTags($announcement['title']) . ' - ' . UOJLocale::get('announcements')) ?>
<p class="uoj-domain-back text-left"><a href="/announcements"><span class="glyphicon glyphicon-chevron-left"></span> 公告</a></p>
<?php echoDomainError($error) ?>
<?php $flash = domainTakeFlash(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?> text-left" role="alert" id="announcement-flash"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<div class="text-left" id="announcement">
	<div class="d-flex flex-wrap align-items-start">
		<h2 class="mr-auto mb-2" id="announcement-title"><?= $announcement['title'] ?></h2>
		<?php if ($can_manage): ?>
		<div class="mb-2">
			<a class="btn btn-sm btn-outline-secondary" href="<?= announcementUrl($announcement['id'], '/edit') ?>" id="link-edit-announcement">编辑</a>
			<form method="post" class="d-inline" onsubmit="return confirm('删除这条公告和它带的文件吗？');">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="delete" />
				<button type="submit" class="btn btn-sm btn-outline-danger" id="button-delete-announcement">删除</button>
			</form>
		</div>
		<?php endif ?>
	</div>
	<p class="text-muted" id="announcement-meta">
		<?php if ($announcement['level'] > 0): ?><span class="badge badge-danger">置顶</span><?php endif ?>
		发布者：<?= getUserLink($announcement['poster']) ?> · <?= $announcement['post_time'] ?>
	</p>
	<hr />
	<article class="uoj-article uoj-announcement-text" id="announcement-text"><?= $announcement['content'] ?></article>
	<?php echoAttachments($loose_files, '附件') ?>
</div>
<?php echoUOJPageFooter() ?>
