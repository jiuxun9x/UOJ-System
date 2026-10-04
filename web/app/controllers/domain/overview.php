<?php
	// the front page of a domain: for its members what is going on in it, for everybody else,
	// if the domain shows itself at all, what it is and how to join it
	$domain = isset($_GET['slug']) ? queryDomainBySlug($_GET['slug']) : null;
	if (!$domain || !can($myUser, 'domain.view_landing', $domain)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}
	$is_inside = can($myUser, 'domain.view', $domain);
	
	$error = domainHandleForms(array(
		'join' => function() use ($domain) {
			global $myUser;
			if (!can($myUser, 'domain.join', $domain)) {
				return '不能加入这个域';
			}
			DB::insert("insert ignore into domain_members (domain_id, username, role, joined_at, added_by) values ({$domain['id']}, '".DB::escape($myUser['username'])."', 'member', now(), '')");
			auditLog('domain.join', 'domain', $domain['id'], null, array('username' => $myUser['username'], 'by' => 'self'));
			domainFlash('你已加入这个域。');
			return '';
		}
	));
?>
<?php if ($is_inside): ?>
<?php echoDomainPageHeader($domain, 'overview', '概览') ?>
<?php echoDomainError($error) ?>
<div class="row">
	<div class="col-lg-8">
		<?php if (trim($domain['description']) !== ''): ?>
		<div class="card mb-3">
			<div class="card-body uoj-domain-description"><?= HTML::escape($domain['description']) ?></div>
		</div>
		<?php endif ?>
		<div class="uoj-domain-empty" id="domain-overview-empty">这里会显示进行中的作业、训练和比赛。</div>
	</div>
	<div class="col-lg-4">
		<div class="card mb-3">
			<div class="card-header">关于</div>
			<ul class="list-group list-group-flush">
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">类型</span> <?= domainTypes()[$domain['type']] ?></li>
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">所有者</span> <?= getUserLink($domain['owner_username']) ?></li>
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">成员</span> <a href="<?= domainUrl($domain, '/members') ?>"><?= domainMemberCount($domain) ?> 人</a></li>
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">创建于</span> <?= substr($domain['created_at'], 0, 10) ?></li>
			</ul>
		</div>
	</div>
</div>
<?php else: ?>
<?php echoUOJPageHeader(HTML::escape($domain['name'])) ?>
<?php echoDomainError($error) ?>
<div class="row justify-content-center">
	<div class="col-lg-8">
		<div class="card" id="domain-landing">
			<div class="card-body">
				<h2 class="card-title"><?= HTML::escape($domain['name']) ?> <span class="badge badge-light border" style="font-size:0.8rem"><?= domainTypes()[$domain['type']] ?></span></h2>
				<p class="text-muted"><span class="glyphicon glyphicon-user"></span> <?= domainMemberCount($domain) ?> 人 <span class="mx-1">·</span> <?= getUserLink($domain['owner_username']) ?></p>
				<?php if (trim($domain['description']) !== ''): ?>
				<div class="uoj-domain-description mb-3"><?= HTML::escape($domain['description']) ?></div>
				<?php endif ?>
				<hr />
				<?php if ($domain['archived_at'] !== null): ?>
				<p class="mb-0 text-muted">这个域已归档。</p>
				<?php elseif (!Auth::check()): ?>
				<p class="mb-0">域里的内容只有成员能看到。请先 <a href="/login">登录</a>。</p>
				<?php elseif ($domain['join_method'] === 'all'): ?>
				<form method="post">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="join" />
					<button type="submit" class="btn btn-primary" id="button-join-domain">加入这个域</button>
				</form>
				<?php elseif ($domain['join_method'] === 'code'): ?>
				<p class="mb-0">这个域凭邀请加入。拿到邀请后，请到 <a href="/domains/join">凭邀请加入</a> 页面输入。</p>
				<?php else: ?>
				<p class="mb-0">这个域只能由管理者添加成员，请联系老师。</p>
				<?php endif ?>
			</div>
		</div>
	</div>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
