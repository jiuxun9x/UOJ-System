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
<div class="uoj-page-head">
	<h4>我的域</h4>
	<?php if (Auth::check()): ?>
	<a class="btn btn-outline-primary" href="/domains/join"><span class="glyphicon glyphicon-log-in"></span> 凭邀请加入</a>
	<?php endif ?>
	<?php if (can($myUser, 'domain.create')): ?>
	<a class="btn btn-primary" href="/domain/new" id="button-new-domain"><span class="glyphicon glyphicon-plus"></span> 创建域</a>
	<?php endif ?>
</div>

<?php if (Auth::check()): ?>
<?php if (!$my_domains): ?>
<div class="uoj-domain-empty" id="domains-empty">你还没有加入任何域。一个域是一个班级、一门课程或一支队伍的空间，只有它的成员能看到：请向老师要邀请链接，或等老师把你加进名单。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-bordered table-hover table-striped uoj-list-table" id="table-my-domains">
		<thead>
			<tr>
				<th class="uoj-list-main">名称</th>
				<th style="width:7em">类型</th>
				<th style="width:8em">我的角色</th>
				<th style="width:7em">人数</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($my_domains as $domain): ?>
			<tr<?= $domain['archived_at'] !== null ? ' class="uoj-domain-card-archived"' : '' ?>>
				<td class="uoj-list-main">
					<a href="<?= domainUrl($domain) ?>"><?= HTML::escape($domain['name']) ?></a>
					<?php if ($domain['archived_at'] !== null): ?>
					<sup><span class="badge badge-warning">已归档</span></sup>
					<?php endif ?>
					<?php if (trim($domain['description']) !== ''): ?>
					<small class="text-muted d-block uoj-domain-line"><?= HTML::escape($domain['description']) ?></small>
					<?php endif ?>
				</td>
				<td><?= domainTypes()[$domain['type']] ?></td>
				<td><?= domainRoleName($domain['my_role']) ?></td>
				<td><span class="glyphicon glyphicon-user"></span> &times;<?= domainMemberCount($domain) ?></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php endif ?>

<?php if (!Auth::check()): ?>
<div class="uoj-domain-empty">域只有它的成员能看到。请先 <a href="/login">登录</a>。</div>
<?php endif ?>

<?php if ($sees_all): ?>
<div class="uoj-page-head mt-4">
	<h4>全部域 <small class="text-muted">全站管理员可以查看和管理每一个域</small></h4>
	<form method="get" class="form-inline" id="form-search-domains">
		<input type="text" class="form-control form-control-sm mr-2" name="q" value="<?= HTML::escape($search) ?>" placeholder="名称、地址或所有者" />
		<button type="submit" class="btn btn-outline-secondary btn-sm">搜索</button>
	</form>
</div>
<?php if (!$all_domains): ?>
<div class="uoj-domain-empty"><?= $search !== '' ? '没有符合条件的域。' : '还没有域。' ?></div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-bordered table-hover table-striped uoj-list-table" id="table-all-domains">
		<thead>
			<tr>
				<th class="uoj-list-main">名称</th>
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
				<td class="uoj-list-main">
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
