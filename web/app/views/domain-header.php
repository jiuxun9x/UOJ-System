<?php
	// the head of every page of a domain: what the domain is, who the user is in it, and its tabs
	$my_role = permissionDomainRole(Auth::user(), $domain);
	$flash = domainTakeFlash();
?>
<div class="uoj-domain-header">
	<div class="d-flex flex-wrap align-items-start">
		<div class="mr-auto">
			<h2 class="uoj-domain-title">
				<a href="<?= domainUrl($domain) ?>"><?= HTML::escape($domain['name']) ?></a>
				<span class="badge badge-light border uoj-domain-type"><?= domainTypes()[$domain['type']] ?></span>
				<?php if ($domain['archived_at'] !== null): ?>
				<span class="badge badge-warning">已归档</span>
				<?php endif ?>
			</h2>
			<p class="text-muted uoj-domain-meta">
				<span class="glyphicon glyphicon-user"></span> <?= domainMemberCount($domain) ?> 人
				<span class="mx-1">·</span>
				<?= getUserLink($domain['owner_username']) ?>
			</p>
		</div>
		<div class="uoj-domain-actions">
			<?php if ($my_role !== null): ?>
			<span class="badge badge-primary uoj-domain-role"><?= domainRoleName($my_role) ?></span>
			<?php elseif (can(Auth::user(), 'domain.manage_all')): ?>
			<span class="badge badge-dark uoj-domain-role">全站管理员</span>
			<?php endif ?>
			<?php if (can(Auth::user(), 'domain.manage', $domain) || can(Auth::user(), 'domain.own', $domain)): ?>
			<a class="btn btn-outline-secondary btn-sm" href="<?= domainUrl($domain, '/settings') ?>"><span class="glyphicon glyphicon-cog"></span> 设置</a>
			<?php endif ?>
		</div>
	</div>
	<ul class="nav nav-tabs uoj-domain-tabs" role="tablist">
		<?php foreach (domainTabs($domain, Auth::user()) as $tab_id => $tab_info): ?>
		<li class="nav-item"><a class="nav-link<?= $tab_id === $tab ? ' active' : '' ?>" href="<?= $tab_info[1] ?>"><?= $tab_info[0] ?></a></li>
		<?php endforeach ?>
	</ul>
</div>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?> alert-dismissible" role="alert">
	<?= HTML::escape($flash[1]) ?>
	<button type="button" class="close" data-dismiss="alert" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
</div>
<?php endif ?>
