<?php
	$domain = domainOfPage();
	$can_manage = can($myUser, 'domain.manage', $domain);
	$can_own = can($myUser, 'domain.own', $domain);
	if (!$can_manage && !$can_own) {
		become403Page();
	}
	
	$settings = array_intersect_key($domain, array('name' => 0, 'slug' => 0, 'description' => 0, 'type' => 0, 'visibility' => 0, 'join_method' => 0));
	$error = domainHandleForms(array(
		'settings' => function() use ($domain, $can_manage, &$settings) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			foreach (array('name', 'description', 'type', 'visibility', 'join_method') as $key) {
				$settings[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
			}
			$err = domainUpdateSettings($domain, $settings, $myUser);
			if ($err === '') {
				domainFlash('设置已保存。');
			}
			return $err;
		},
		'transfer' => function() use ($domain, $can_own) {
			global $myUser;
			if (!$can_own) {
				return '只有所有者可以转让';
			}
			$new_owner = isset($_POST['username']) ? queryUser($_POST['username']) : null;
			if (!$new_owner) {
				return '用户不存在';
			}
			$err = domainTransfer($domain, $new_owner, $myUser);
			if ($err === '') {
				domainFlash("域已转让给 {$new_owner['username']}，你现在是管理员。");
				redirectTo(domainUrl($domain));
			}
			return $err;
		},
		'archive' => function() use ($domain, $can_own) {
			global $myUser;
			if (!$can_own) {
				return '只有所有者可以归档';
			}
			domainSetArchived($domain, $domain['archived_at'] === null, $myUser);
			domainFlash($domain['archived_at'] === null ? '域已归档。' : '域已恢复。');
			return '';
		}
	));
?>
<?php echoDomainPageHeader($domain, 'settings', '设置') ?>
<?php echoDomainError($error) ?>
<?php if ($can_manage): ?>
<h3 class="uoj-domain-section-title mt-0">基本设置</h3>
<?php uojIncludeView('domain-settings-form', array('settings' => $settings, 'is_new' => false)) ?>
<?php elseif ($domain['archived_at'] !== null): ?>
<div class="alert alert-warning">这个域已归档，只能查看。恢复之后才能修改设置。</div>
<?php endif ?>

<?php if ($can_own): ?>
<h3 class="uoj-domain-section-title">转让</h3>
<p class="text-muted">把这个域交给它的一位成员。转让后你会成为管理员。</p>
<form method="post" class="form-inline" onsubmit="return confirm('确定要把这个域转让给 ' + this.username.value + ' 吗？');">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="transfer" />
	<input type="text" class="form-control mr-2 mb-2" name="username" maxlength="20" required="required" placeholder="新所有者的用户名" />
	<button type="submit" class="btn btn-outline-danger mb-2" id="button-transfer-domain">转让</button>
</form>

<h3 class="uoj-domain-section-title"><?= $domain['archived_at'] === null ? '归档' : '恢复' ?></h3>
<?php if ($domain['archived_at'] === null): ?>
<p class="text-muted">归档后这个域变为只读，并从列表里消失；里面的提交和成绩都会保留，可以随时恢复。</p>
<?php else: ?>
<p class="text-muted">这个域于 <?= $domain['archived_at'] ?> 归档。恢复后可以继续使用。</p>
<?php endif ?>
<form method="post" onsubmit="return confirm('确定吗？');">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="archive" />
	<button type="submit" class="btn <?= $domain['archived_at'] === null ? 'btn-outline-danger' : 'btn-outline-success' ?>" id="button-archive-domain"><?= $domain['archived_at'] === null ? '归档这个域' : '恢复这个域' ?></button>
</form>
<?php endif ?>
<?php echoUOJPageFooter() ?>
