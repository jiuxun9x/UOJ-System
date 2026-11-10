<?php
	// The navigation of the site while one is inside a domain: the bar that leads to the contests
	// and the problems of the site leads to those of the domain, as on Hydro, and nothing else
	// stands between it and the page. Which domain, and which of its pages: domainNavUse().
	$domain = $GLOBALS['uojNavDomain'];
	$tab = isset($GLOBALS['uojNavTab']) ? $GLOBALS['uojNavTab'] : null;
	$me = Auth::user();
	$my_role = permissionDomainRole($me, $domain);
	$icons = array('overview' => 'home', 'homeworks' => 'pencil', 'trainings' => 'road', 'problems' => 'list-alt', 'contests' => 'stats',
		'members' => 'user', 'announcements' => 'bullhorn', 'grades' => 'signal', 'settings' => 'cog');
	$tabs = domainTabs($domain, $me);
	if (can($me, 'domain.manage', $domain) || can($me, 'domain.own', $domain)) {
		$tabs['settings'] = array('设置', domainUrl($domain, '/settings'));
	}
	$others = array();
	foreach ($me ? domainsOfUser($me['username']) : array() as $other) {
		if ($other['id'] != $domain['id'] && $other['archived_at'] === null) {
			$others[] = $other;
		}
	}
?>
<div class="navbar navbar-light navbar-expand-lg bg-light mb-4 uoj-domain-nav" role="navigation" id="domain-nav" data-domain="<?= $domain['slug'] ?>">
	<a class="navbar-brand uoj-domain-brand" href="<?= domainUrl($domain) ?>" title="<?= HTML::escape($domain['name']) ?>"><?= HTML::escape($domain['name']) ?></a>
	<button type="button" class="navbar-toggler collapsed" data-toggle="collapse" data-target=".navbar-collapse">
		<span class="navbar-toggler-icon"></span>
	</button>
	<div class="collapse navbar-collapse">
		<ul class="nav navbar-nav mr-auto">
			<?php foreach ($tabs as $tab_id => $tab_info): ?>
			<?php if ($tab_id !== 'overview'): ?>
			<li class="nav-item<?= $tab_id === $tab ? ' active' : '' ?>"><a class="nav-link" href="<?= $tab_info[1] ?>"><span class="glyphicon glyphicon-<?= $icons[$tab_id] ?>"></span> <?= $tab_info[0] ?></a></li>
			<?php endif ?>
			<?php endforeach ?>
		</ul>
		<ul class="nav navbar-nav">
			<li class="nav-item">
				<span class="navbar-text uoj-domain-role" id="domain-my-role">
					<?php if ($domain['archived_at'] !== null): ?><span class="badge badge-warning">已归档</span><?php endif ?>
					<span class="badge <?= $my_role !== null ? 'badge-primary' : 'badge-dark' ?>"><?= $my_role !== null ? domainRoleName($my_role) : (can($me, 'domain.manage_all') ? '全站管理员' : '') ?></span>
				</span>
			</li>
			<li class="nav-item dropdown">
				<a class="nav-link dropdown-toggle" href="#" id="domainSwitchDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
					<span class="glyphicon glyphicon-education"></span> 切换
				</a>
				<div class="dropdown-menu dropdown-menu-right" aria-labelledby="domainSwitchDropdown">
					<a class="dropdown-item" href="<?= HTML::url('/') ?>" id="domain-nav-site"><span class="glyphicon glyphicon-globe"></span> 回到主站</a>
					<div class="dropdown-divider"></div>
					<?php foreach (array_slice($others, 0, 8) as $other): ?>
					<a class="dropdown-item" href="<?= domainUrl($other) ?>"><?= HTML::escape($other['name']) ?></a>
					<?php endforeach ?>
					<a class="dropdown-item" href="/domains">全部域</a>
				</div>
			</li>
		</ul>
	</div>
</div>
