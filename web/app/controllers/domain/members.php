<?php
	$domain = domainOfPage();
	$can_manage = can($myUser, 'member.manage', $domain);
	$my_role = permissionDomainRole($myUser, $domain);
	// Whoever came in with an invitation may leave again. Whoever was put on the list by the
	// people who manage the domain, as the students of a class are, is taken off it by them.
	$can_leave = $my_role !== null && $my_role !== 'owner' && $domain['archived_at'] === null
		&& DB::selectFirst("select 1 from domain_members where domain_id = {$domain['id']} and username = '".DB::escape($myUser['username'])."' and added_by = ''") != null;
	
	$error = domainHandleForms(array(
		'add' => function() use ($domain, $can_manage) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			$target = isset($_POST['username']) ? queryUser($_POST['username']) : null;
			if (!$target) {
				return '用户不存在';
			}
			if (permissionDomainRole($target, $domain) !== null) {
				return "{$target['username']} 已经是成员";
			}
			$err = domainSetMember($domain, $target, isset($_POST['role']) ? $_POST['role'] : 'member', $myUser);
			if ($err === '') {
				domainFlash("已添加 {$target['username']}。");
			}
			return $err;
		},
		'role' => function() use ($domain, $can_manage) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			$target = isset($_POST['username']) ? queryUser($_POST['username']) : null;
			if (!$target || permissionDomainRole($target, $domain) === null) {
				return '该用户不是成员';
			}
			$err = domainSetMember($domain, $target, isset($_POST['role']) ? $_POST['role'] : '', $myUser);
			if ($err === '') {
				domainFlash("已修改 {$target['username']} 的角色。");
			}
			return $err;
		},
		'remove' => function() use ($domain, $can_manage) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			$target = isset($_POST['username']) ? queryUser($_POST['username']) : null;
			if (!$target || permissionDomainRole($target, $domain) === null) {
				return '该用户不是成员';
			}
			$err = domainSetMember($domain, $target, null, $myUser);
			if ($err === '') {
				domainFlash("已移除 {$target['username']}。");
			}
			return $err;
		},
		'import' => function() use ($domain, $can_manage) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			$role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : 'member';
			$report = domainImportRoster($domain, isset($_POST['roster']) && is_string($_POST['roster']) ? $_POST['roster'] : '', $role, $myUser);
			if (isset($report['refused']['*'])) {
				return $report['refused']['*'];
			}
			$_SESSION['domain_import_report'] = array($domain['id'], $report);
			return '';
		},
		'unpend' => function() use ($domain, $can_manage) {
			if (!$can_manage) {
				return '没有权限';
			}
			DB::delete("delete from domain_pending_members where domain_id = {$domain['id']} and id = ".(int)$_POST['id']);
			return '';
		},
		'invite' => function() use ($domain, $can_manage) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			$hours = isset($_POST['hours']) ? (int)$_POST['hours'] : 0;
			if (!isset(domainInviteLifetimes()[$hours])) {
				return '无效的有效期';
			}
			$max_uses = isset($_POST['max_uses']) && $_POST['max_uses'] !== '' ? (int)$_POST['max_uses'] : 0;
			if ($max_uses < 0 || $max_uses > 100000) {
				return '无效的次数上限';
			}
			$token = domainCreateInvite($domain, $myUser, isset($_POST['label']) && is_string($_POST['label']) ? $_POST['label'] : '', $hours, $max_uses);
			if ($token === null) {
				return '创建邀请失败';
			}
			// shown on the next page, once
			$_SESSION['domain_invite_token'] = array($domain['id'], $token);
			return '';
		},
		'revoke' => function() use ($domain, $can_manage) {
			global $myUser;
			if (!$can_manage) {
				return '没有权限';
			}
			domainRevokeInvite($domain, isset($_POST['id']) ? $_POST['id'] : 0, $myUser);
			domainFlash('邀请已撤销。');
			return '';
		},
		'leave' => function() use ($domain, $can_leave) {
			global $myUser;
			if (!$can_leave) {
				return '不能退出这个域，请联系管理者';
			}
			DB::delete("delete from domain_members where domain_id = {$domain['id']} and username = '".DB::escape($myUser['username'])."'");
			auditLog('domain.leave', 'domain', $domain['id'], array('username' => $myUser['username']), null);
			redirectTo('/domains');
		}
	));
	
	$members = domainMembers($domain);
	// the people who teach here know who their students are
	$identities = array();
	if (can($myUser, 'domain.assist', $domain)) {
		foreach (DB::selectAll("select external_identities.username, student_id, real_name from external_identities, domain_members where domain_members.domain_id = {$domain['id']} and domain_members.username = external_identities.username order by external_identities.id desc") as $row) {
			$identities[$row['username']] = $row;
		}
	}
	// what the forms above left for this page to show once
	$import_report = null;
	if (isset($_SESSION['domain_import_report'])) {
		if ($_SESSION['domain_import_report'][0] == $domain['id']) {
			$import_report = $_SESSION['domain_import_report'][1];
		}
		unset($_SESSION['domain_import_report']);
	}
	$new_invite_token = null;
	if (isset($_SESSION['domain_invite_token'])) {
		if ($_SESSION['domain_invite_token'][0] == $domain['id']) {
			$new_invite_token = $_SESSION['domain_invite_token'][1];
		}
		unset($_SESSION['domain_invite_token']);
	}
	$pending_members = $can_manage ? DB::selectAll("select * from domain_pending_members where domain_id = {$domain['id']} order by student_id") : array();
	$invites = $can_manage ? domainInvites($domain) : array();
	// the roles the user may hand out
	$assignable = array();
	foreach (domainMemberRoles() as $role => $role_name) {
		if (domainMemberChangeRefusedReason($my_role, can($myUser, 'domain.manage_all'), null, $role) === '') {
			$assignable[$role] = $role_name;
		}
	}
?>
<?php echoDomainPageHeader($domain, 'members', '成员') ?>
<?php echoDomainError($error) ?>

<?php if ($import_report !== null): ?>
<div class="alert alert-info" id="import-report">
	<strong>导入结果</strong>
	<ul class="mb-0">
		<li>已加入 <?= count($import_report['added']) ?> 人<?= $import_report['added'] ? '：' . HTML::escape(join('、', $import_report['added'])) : '' ?></li>
		<?php if ($import_report['present']): ?>
		<li>本来就是成员 <?= count($import_report['present']) ?> 人：<?= HTML::escape(join('、', $import_report['present'])) ?></li>
		<?php endif ?>
		<?php if ($import_report['pending']): ?>
		<li>还没有账号、已挂起 <?= count($import_report['pending']) ?> 人：<?= HTML::escape(join('、', $import_report['pending'])) ?>。他们首次通过统一身份认证登录时会自动加入。</li>
		<?php endif ?>
		<?php foreach ($import_report['refused'] as $line => $reason): ?>
		<li class="text-danger">未处理：<?= HTML::escape($line) ?>（<?= HTML::escape($reason) ?>）</li>
		<?php endforeach ?>
	</ul>
</div>
<?php endif ?>

<?php if ($new_invite_token !== null): ?>
<div class="alert alert-success" id="new-invite">
	<strong>邀请已创建。</strong>下面的链接只显示这一次，请现在复制给学生；系统不保存它，之后无法再次查看。
	<div class="input-group mt-2">
		<input type="text" class="form-control uoj-domain-secret" id="input-new-invite-link" readonly="readonly" value="<?= HTML::escape(UOJSSO::siteUrl('/domains/join') . '#' . $new_invite_token) ?>" />
		<div class="input-group-append">
			<button type="button" class="btn btn-outline-secondary" onclick="var i = document.getElementById('input-new-invite-link'); i.select(); document.execCommand('copy'); this.textContent = '已复制';">复制</button>
		</div>
	</div>
	<small class="text-muted">也可以只把“#”后面的部分发给学生，让他们在“域 → 凭邀请加入”里粘贴：<span class="uoj-domain-secret" id="new-invite-token"><?= $new_invite_token ?></span></small>
</div>
<?php endif ?>

<?php if ($can_manage): ?>
<div class="card mb-3">
	<div class="card-body">
		<form method="post" class="form-inline" id="form-add-member">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add" />
			<label class="mr-2 mb-2" for="input-add-username">添加成员</label>
			<input type="text" class="form-control mr-2 mb-2" id="input-add-username" name="username" maxlength="20" required="required" placeholder="用户名" />
			<select class="form-control mr-2 mb-2" name="role">
				<?php foreach (array_reverse($assignable, true) as $role => $role_name): ?>
				<option value="<?= $role ?>"><?= $role_name ?></option>
				<?php endforeach ?>
			</select>
			<button type="submit" class="btn btn-primary mb-2">添加</button>
		</form>
	</div>
</div>
<?php endif ?>

<div class="table-responsive">
	<table class="table table-bordered table-hover uoj-domain-members uoj-roster" id="table-members">
		<thead>
			<tr>
				<th style="width:3em">#</th>
				<th>用户</th>
				<?php if ($identities): ?>
				<th>学号</th>
				<th>姓名</th>
				<?php endif ?>
				<th style="width:10em">角色</th>
				<th style="width:10em">加入时间</th>
				<?php if ($can_manage): ?>
				<th style="width:6em"></th>
				<?php endif ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($members as $index => $member): ?>
			<?php
				$may_change = $can_manage && domainMemberChangeRefusedReason($my_role, can($myUser, 'domain.manage_all'), $member['role'], 'member') === '';
			?>
			<tr data-username="<?= $member['username'] ?>" data-role="<?= $member['role'] ?>">
				<td><?= $index + 1 ?></td>
				<td><?= getUserLink($member['username']) ?></td>
				<?php if ($identities): ?>
				<td><?= isset($identities[$member['username']]) ? HTML::escape($identities[$member['username']]['student_id']) : '' ?></td>
				<td><?= isset($identities[$member['username']]) ? HTML::escape($identities[$member['username']]['real_name']) : '' ?></td>
				<?php endif ?>
				<td>
					<?php if ($may_change): ?>
					<form method="post">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="role" />
						<input type="hidden" name="username" value="<?= $member['username'] ?>" />
						<select class="form-control form-control-sm" name="role" onchange="this.form.submit()">
							<?php foreach (domainMemberRoles() as $role => $role_name): ?>
							<?php if (isset($assignable[$role]) || $role === $member['role']): ?>
							<option value="<?= $role ?>"<?= $role === $member['role'] ? ' selected="selected"' : '' ?>><?= $role_name ?></option>
							<?php endif ?>
							<?php endforeach ?>
						</select>
					</form>
					<?php else: ?>
					<span class="badge <?= $member['role'] === 'owner' ? 'badge-dark' : ($member['role'] === 'member' ? 'badge-light border' : 'badge-info') ?>"><?= domainRoleName($member['role']) ?></span>
					<?php endif ?>
				</td>
				<td><small class="text-muted"><?= substr($member['joined_at'], 0, 10) ?></small></td>
				<?php if ($can_manage): ?>
				<td>
					<?php if ($may_change): ?>
					<form method="post" onsubmit="return confirm('确定要把 <?= $member['username'] ?> 移出这个域吗？');">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="remove" />
						<input type="hidden" name="username" value="<?= $member['username'] ?>" />
						<button type="submit" class="btn btn-outline-danger btn-sm">移除</button>
					</form>
					<?php endif ?>
				</td>
				<?php endif ?>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>

<?php if ($can_manage): ?>
<?php if ($pending_members): ?>
<h3 class="uoj-domain-section-title">等待首次登录的学生 <small class="text-muted">(<?= count($pending_members) ?>)</small></h3>
<p class="text-muted small">名单里这些学号还没有账号。学生首次通过统一身份认证登录后会自动成为成员。</p>
<div class="table-responsive">
	<table class="table table-bordered table-sm uoj-roster" id="table-pending-members">
		<thead><tr><th>学号</th><th style="width:8em">角色</th><th style="width:12em">导入时间</th><th style="width:6em"></th></tr></thead>
		<tbody>
			<?php foreach ($pending_members as $pending): ?>
			<tr>
				<td><?= HTML::escape($pending['student_id']) ?></td>
				<td><?= domainRoleName($pending['role']) ?></td>
				<td><small class="text-muted"><?= $pending['created_at'] ?></small></td>
				<td>
					<form method="post">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="unpend" />
						<input type="hidden" name="id" value="<?= $pending['id'] ?>" />
						<button type="submit" class="btn btn-outline-secondary btn-sm">移除</button>
					</form>
				</td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>

<div class="row">
	<div class="col-lg-6">
		<h3 class="uoj-domain-section-title">按名单导入</h3>
		<form method="post" id="form-import-roster">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="import" />
			<div class="form-group">
				<textarea class="form-control" name="roster" rows="6" required="required" placeholder="每行一个用户名或学号"></textarea>
				<small class="form-text text-muted">已有账号的直接加入。还没有登录过的学号会先挂起，等学生首次通过统一身份认证登录时自动加入。</small>
			</div>
			<div class="form-inline">
				<select class="form-control mr-2 mb-2" name="role">
					<?php foreach (array_reverse($assignable, true) as $role => $role_name): ?>
					<option value="<?= $role ?>"><?= $role_name ?></option>
					<?php endforeach ?>
				</select>
				<button type="submit" class="btn btn-primary mb-2">导入</button>
			</div>
		</form>
	</div>
	<div class="col-lg-6">
		<h3 class="uoj-domain-section-title">邀请</h3>
		<form method="post" id="form-create-invite">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="invite" />
			<div class="form-row">
				<div class="form-group col-sm-5">
					<input type="text" class="form-control" name="label" maxlength="50" placeholder="备注，如：周二班" />
				</div>
				<div class="form-group col-sm-3">
					<select class="form-control" name="hours" title="有效期">
						<?php foreach (domainInviteLifetimes() as $hours => $label): ?>
						<option value="<?= $hours ?>"<?= $hours == 168 ? ' selected="selected"' : '' ?>><?= $label ?></option>
						<?php endforeach ?>
					</select>
				</div>
				<div class="form-group col-sm-2">
					<input type="number" class="form-control" name="max_uses" min="1" max="100000" placeholder="次数" title="最多可用次数，留空为不限" />
				</div>
				<div class="form-group col-sm-2">
					<button type="submit" class="btn btn-primary btn-block">创建</button>
				</div>
			</div>
			<small class="form-text text-muted mt-0 mb-2">凭邀请加入的人都是学生。邀请链接只在创建时显示一次。</small>
		</form>
		<?php if ($invites): ?>
		<div class="table-responsive">
			<table class="table table-sm" id="table-invites">
				<thead><tr><th>备注</th><th>到期</th><th>已用</th><th>状态</th><th></th></tr></thead>
				<tbody>
					<?php foreach ($invites as $invite): ?>
					<tr>
						<td><?= $invite['label'] !== '' ? HTML::escape($invite['label']) : '<span class="text-muted">#' . $invite['id'] . '</span>' ?></td>
						<td><small><?= $invite['expires_at'] !== null ? substr($invite['expires_at'], 0, 16) : '不过期' ?></small></td>
						<td><?= $invite['uses'] ?><?= $invite['max_uses'] !== null ? ' / ' . $invite['max_uses'] : '' ?></td>
						<td><span class="badge <?= $invite['state'] === 'valid' ? 'badge-success' : 'badge-secondary' ?>"><?= array('valid' => '有效', 'expired' => '已过期', 'used_up' => '已用完', 'revoked' => '已撤销')[$invite['state']] ?></span></td>
						<td class="text-right">
							<?php if ($invite['state'] === 'valid'): ?>
							<form method="post" onsubmit="return confirm('撤销后这个邀请立即失效，确定吗？');">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="revoke" />
								<input type="hidden" name="id" value="<?= $invite['id'] ?>" />
								<button type="submit" class="btn btn-outline-danger btn-sm">撤销</button>
							</form>
							<?php endif ?>
						</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>
	</div>
</div>
<?php endif ?>

<?php if ($can_leave): ?>
<form method="post" class="mt-3" onsubmit="return confirm('确定要退出这个域吗？');">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="leave" />
	<button type="submit" class="btn btn-outline-secondary btn-sm" id="button-leave-domain">退出这个域</button>
</form>
<?php endif ?>
<?php echoUOJPageFooter() ?>
