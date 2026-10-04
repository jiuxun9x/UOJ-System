<?php
	// The domains of the user. A domain is seen by the people in it only, so there is no list
	// of the domains of everybody else, except for the administrators of the site, who see
	// and manage them all.
	$my_domains = Auth::check() ? domainsOfUser(Auth::id()) : array();
	$sees_all = can($myUser, 'domain.manage_all');
	$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
	$all_limit = 300;
	$all_domains = $sees_all ? domainsOfSite($search, $all_limit) : array();
	$my_roles = array();
	foreach ($my_domains as $domain) {
		$my_roles[$domain['id']] = $domain['my_role'];
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
<div class="uoj-domain-empty" id="domains-empty">你还没有加入任何域。域只有它的成员能看到：请向老师要邀请链接，或等老师把你加进名单。</div>
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

<?php if (!Auth::check()): ?>
<div class="uoj-domain-empty">域只有它的成员能看到。请先 <a href="/login">登录</a>。</div>
<?php endif ?>

<?php if ($sees_all): ?>
<div class="d-flex flex-wrap align-items-center mt-4">
	<h3 class="uoj-domain-section-title mr-auto my-2">全部域 <small class="text-muted">全站管理员可以查看和管理每一个域</small></h3>
	<form method="get" class="form-inline my-2" id="form-search-domains">
		<input type="text" class="form-control form-control-sm mr-2" name="q" value="<?= HTML::escape($search) ?>" placeholder="名称、地址或所有者" />
		<button type="submit" class="btn btn-outline-secondary btn-sm">搜索</button>
	</form>
</div>
<?php if (!$all_domains): ?>
<div class="uoj-domain-empty"><?= $search !== '' ? '没有符合条件的域。' : '还没有域。' ?></div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-hover" id="table-all-domains">
		<thead>
			<tr>
				<th>名称</th>
				<th style="width:12em">地址</th>
				<th style="width:6em">类型</th>
				<th style="width:12em">所有者</th>
				<th style="width:6em">人数</th>
				<th style="width:8em">我的角色</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($all_domains as $domain): ?>
			<tr>
				<td>
					<a href="<?= domainUrl($domain) ?>"><?= HTML::escape($domain['name']) ?></a>
					<?php if ($domain['archived_at'] !== null): ?>
					<span class="badge badge-warning">已归档</span>
					<?php endif ?>
				</td>
				<td class="text-nowrap text-muted">/d/<?= $domain['slug'] ?></td>
				<td><?= domainTypes()[$domain['type']] ?></td>
				<td><?= getUserLink($domain['owner_username']) ?></td>
				<td><?= $domain['member_count'] ?></td>
				<td><?= isset($my_roles[$domain['id']]) ? domainRoleName($my_roles[$domain['id']]) : '<span class="text-muted">全站管理员</span>' ?></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php if (count($all_domains) >= $all_limit): ?>
<p class="text-muted small">只列出了最新的 <?= $all_limit ?> 个，其余的请用搜索查找。</p>
<?php endif ?>
<?php endif ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
