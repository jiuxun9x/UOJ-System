<?php
	// the domains of the user, and the domains everybody may see
	$my_domains = Auth::check() ? domainsOfUser(Auth::id()) : array();
	$public_domains = DB::selectAll("select domains.*, (select count(*) from domain_members where domain_members.domain_id = domains.id) + 1 as member_count from domains where visibility = 'public' and archived_at is null order by id desc limit 200");
	$my_ids = array();
	foreach ($my_domains as $domain) {
		$my_ids[$domain['id']] = true;
	}
?>
<?php echoUOJPageHeader('域') ?>
<div class="d-flex flex-wrap align-items-center mb-3">
	<h2 class="mr-auto mb-2">域</h2>
	<div class="mb-2">
		<?php if (Auth::check()): ?>
		<a class="btn btn-outline-primary" href="/domains/join"><span class="glyphicon glyphicon-log-in"></span> 凭邀请加入</a>
		<?php endif ?>
		<?php if (can($myUser, 'domain.create')): ?>
		<a class="btn btn-primary" href="/domain/new" id="button-new-domain"><span class="glyphicon glyphicon-plus"></span> 创建域</a>
		<?php endif ?>
	</div>
</div>
<p class="text-muted">一个域是一个班级、一门课程或一支队伍的空间，有自己的成员、题目、作业、训练和比赛。</p>

<?php if (Auth::check()): ?>
<h3 class="uoj-domain-section-title">我的域</h3>
<?php if (!$my_domains): ?>
<div class="uoj-domain-empty">你还没有加入任何域。可以向老师要邀请链接，或在下面的公开域里看看。</div>
<?php else: ?>
<div class="row">
	<?php foreach ($my_domains as $domain): ?>
	<div class="col-md-6 col-lg-4 mb-3">
		<div class="card uoj-domain-card<?= $domain['archived_at'] !== null ? ' uoj-domain-card-archived' : '' ?>">
			<div class="card-body">
				<h5 class="card-title">
					<a href="<?= domainUrl($domain) ?>" class="stretched-link"><?= HTML::escape($domain['name']) ?></a>
				</h5>
				<p class="card-text"><?= HTML::escape($domain['description']) ?></p>
			</div>
			<div class="card-footer bg-white d-flex align-items-center">
				<span class="badge badge-light border mr-2"><?= domainTypes()[$domain['type']] ?></span>
				<span class="badge badge-primary mr-auto"><?= domainRoleName($domain['my_role']) ?></span>
				<?php if ($domain['archived_at'] !== null): ?>
				<span class="badge badge-warning mr-2">已归档</span>
				<?php endif ?>
				<small class="text-muted"><span class="glyphicon glyphicon-user"></span> <?= domainMemberCount($domain) ?></small>
			</div>
		</div>
	</div>
	<?php endforeach ?>
</div>
<?php endif ?>
<?php endif ?>

<h3 class="uoj-domain-section-title">公开的域</h3>
<?php if (!$public_domains): ?>
<div class="uoj-domain-empty">目前没有公开的域。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-hover">
		<thead>
			<tr>
				<th>名称</th>
				<th style="width:6em">类型</th>
				<th style="width:12em">所有者</th>
				<th style="width:6em">人数</th>
				<th style="width:10em">加入方式</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($public_domains as $domain): ?>
			<tr>
				<td>
					<a href="<?= domainUrl($domain) ?>"><?= HTML::escape($domain['name']) ?></a>
					<?php if (isset($my_ids[$domain['id']])): ?>
					<span class="badge badge-primary">已加入</span>
					<?php endif ?>
				</td>
				<td><?= domainTypes()[$domain['type']] ?></td>
				<td><?= getUserLink($domain['owner_username']) ?></td>
				<td><?= $domain['member_count'] ?></td>
				<td><?= array('none' => '由管理者添加', 'code' => '凭邀请', 'all' => '自行加入')[$domain['join_method']] ?></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
