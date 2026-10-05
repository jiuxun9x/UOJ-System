<?php
	// The announcements of the site: the ones that stand at the top first, then the newest.
	// The administrators of the site post them from here.
	$can_manage = can($myUser, 'announcement.manage');
	$announcements = DB::selectAll("select blogs.id, blogs.poster, blogs.title, blogs.post_time, important_blogs.level from important_blogs, blogs where blogs.is_hidden = 0 and important_blogs.blog_id = blogs.id order by important_blogs.level desc, important_blogs.blog_id desc limit 500", MYSQLI_ASSOC);
?>
<?php echoUOJPageHeader(UOJLocale::get('announcements')) ?>
<div class="d-flex flex-wrap align-items-center mb-2">
	<h3 class="mr-auto mb-2">公告</h3>
	<?php if ($can_manage): ?>
	<a class="btn btn-primary mb-2" href="/announcement/new" id="button-new-announcement"><span class="glyphicon glyphicon-plus"></span> 发布公告</a>
	<?php endif ?>
</div>
<?php $flash = domainTakeFlash(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>" role="alert" id="announcement-flash"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<?php if (!$announcements): ?>
<div class="uoj-domain-empty" id="no-announcements">还没有公告。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-hover" id="table-announcements">
		<thead>
			<tr>
				<th>标题</th>
				<th style="width:16em" class="text-center">发布者</th>
				<th style="width:12em" class="text-center">发布时间</th>
				<?php if ($can_manage): ?>
				<th style="width:5em"></th>
				<?php endif ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($announcements as $announcement): ?>
			<tr data-announcement="<?= $announcement['id'] ?>">
				<td class="text-left"><?php if ($announcement['level'] > 0): ?><span class="badge badge-danger">置顶</span> <?php endif ?><a href="<?= announcementUrl($announcement['id']) ?>"><?= $announcement['title'] ?></a></td>
				<td class="text-center"><?= getUserLink($announcement['poster']) ?></td>
				<td class="text-center"><small><?= $announcement['post_time'] ?></small></td>
				<?php if ($can_manage): ?>
				<td class="text-right"><a class="btn btn-sm btn-outline-secondary" href="<?= announcementUrl($announcement['id'], '/edit') ?>">编辑</a></td>
				<?php endif ?>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
