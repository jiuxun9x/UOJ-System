<?php

// Domains: the space of a class, a course or a team, with its own members and roles, problems,
// homework, trainings, contests and announcements. Nobody but its members sees what is inside.
//
// The owner of a domain is the user named in domains.owner_username, which makes it exactly
// one. The owner is not listed in domain_members; everybody else has one of the roles
// admin, teacher, ta or member there.

function validateDomainSlug($slug) {
	return is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/', $slug) && substr($slug, -1) !== '-';
}

function domainTypes() {
	return array(
		'course' => '课程',
		'class' => '班级',
		'training' => '训练',
		'team' => '队伍',
		'organization' => '组织'
	);
}
function domainVisibilities() {
	return array(
		'private' => '私有：只有成员能看到',
		'unlisted' => '不公开列出：知道地址的人能看到介绍页',
		'public' => '公开：出现在域列表里'
	);
}
function domainJoinMethods() {
	return array(
		'none' => '只能由管理者添加',
		'code' => '凭邀请加入',
		'all' => '登录用户可自行加入'
	);
}
// the roles a member can be given, from the most to the least powerful
function domainMemberRoles() {
	return array(
		'admin' => '管理员',
		'teacher' => '教师',
		'ta' => '助教',
		'member' => '学生'
	);
}
function domainRoleName($role) {
	if ($role === 'owner') {
		return '所有者';
	}
	$roles = domainMemberRoles();
	return isset($roles[$role]) ? $roles[$role] : '';
}
function domainRoleRank($role) {
	$ranks = array('owner' => 5, 'admin' => 4, 'teacher' => 3, 'ta' => 2, 'member' => 1);
	return isset($ranks[$role]) ? $ranks[$role] : 0;
}

// Returns '' when the settings of a domain make sense, or what is wrong with them.
function domainSettingsError($settings) {
	if (!isset($settings['name']) || !is_string($settings['name']) || trim($settings['name']) === '' || mb_strlen($settings['name'], 'UTF-8') > 100) {
		return '名称不能为空，且不超过 100 个字符';
	}
	if (isset($settings['slug']) && !validateDomainSlug($settings['slug'])) {
		return '地址只能由小写字母、数字和连字符组成，以字母或数字开头和结尾，长度 2 到 31';
	}
	if (!isset($settings['description']) || !is_string($settings['description']) || mb_strlen($settings['description'], 'UTF-8') > 2000) {
		return '简介不超过 2000 个字符';
	}
	if (!isset($settings['type']) || !isset(domainTypes()[$settings['type']])) {
		return '无效的类型';
	}
	if (!isset($settings['visibility']) || !isset(domainVisibilities()[$settings['visibility']])) {
		return '无效的可见性';
	}
	if (!isset($settings['join_method']) || !isset(domainJoinMethods()[$settings['join_method']])) {
		return '无效的加入方式';
	}
	// nobody can see a private domain to join it
	if ($settings['visibility'] === 'private' && $settings['join_method'] === 'all') {
		return '私有的域不能设为“登录用户可自行加入”，请改用邀请';
	}
	return '';
}

// Whether somebody may change what a member is. $actor_role is the role of whoever asks,
// $target_role the role the member has (null: not a member yet), $new_role the role they are
// to have (null: removed). Returns '' or why not.
function domainMemberChangeRefusedReason($actor_role, $is_site_manager, $target_role, $new_role) {
	if ($target_role === 'owner') {
		return '所有者只能通过转让来变更';
	}
	if ($new_role !== null && !isset(domainMemberRoles()[$new_role])) {
		return '无效的角色';
	}
	if ($is_site_manager || $actor_role === 'owner') {
		return '';
	}
	if ($actor_role !== 'admin') {
		return '没有管理成员的权限';
	}
	// the administrators of a domain are chosen by its owner
	if ($target_role === 'admin' || $new_role === 'admin') {
		return '只有所有者可以任免管理员';
	}
	return '';
}

// ---- queries

function queryDomain($id) {
	return DB::selectFirst("select * from domains where id = ".(int)$id, MYSQLI_ASSOC);
}
function queryDomainBySlug($slug) {
	if (!validateDomainSlug($slug)) {
		return null;
	}
	return DB::selectFirst("select * from domains where slug = '".DB::escape($slug)."'", MYSQLI_ASSOC);
}
// the role of a user in a domain: 'owner', one of the member roles, or null
function domainRoleOf($username, $domain) {
	if ($username === null || $username === '') {
		return null;
	}
	if ($domain['owner_username'] === $username) {
		return 'owner';
	}
	$row = DB::selectFirst("select role from domain_members where domain_id = {$domain['id']} and username = '".DB::escape($username)."'");
	return $row ? $row['role'] : null;
}
function domainMemberCount($domain) {
	return 1 + (int)DB::selectCount("select count(*) from domain_members where domain_id = {$domain['id']} and username != '".DB::escape($domain['owner_username'])."'");
}
// the domains a user owns or is a member of, with 'my_role', the ones that are archived last
function domainsOfUser($username) {
	$esc_username = DB::escape($username);
	return DB::selectAll("select domains.*, if(domains.owner_username = '$esc_username', 'owner', domain_members.role) as my_role from domains left join domain_members on domain_members.domain_id = domains.id and domain_members.username = '$esc_username' where domains.owner_username = '$esc_username' or domain_members.username is not null order by domains.archived_at is not null, domains.id desc");
}
// everybody in a domain: the owner first, then by role and name
function domainMembers($domain) {
	$esc_owner = DB::escape($domain['owner_username']);
	$members = array(array('username' => $domain['owner_username'], 'role' => 'owner', 'joined_at' => $domain['created_at'], 'added_by' => ''));
	foreach (DB::selectAll("select username, role, joined_at, added_by from domain_members where domain_id = {$domain['id']} and username != '$esc_owner' order by field(role, 'admin', 'teacher', 'ta', 'member'), username") as $row) {
		$members[] = $row;
	}
	return $members;
}

// ---- changes; each returns '' or why it was refused

function domainCreate($actor, $settings) {
	$err = domainSettingsError($settings);
	if ($err !== '') {
		return $err;
	}
	$esc_slug = DB::escape($settings['slug']);
	$esc_actor = DB::escape($actor['username']);
	$ok = DB::insert("insert into domains (slug, name, description, type, visibility, join_method, owner_username, created_by, created_at, updated_at) values ('$esc_slug', '".DB::escape(trim($settings['name']))."', '".DB::escape($settings['description'])."', '{$settings['type']}', '{$settings['visibility']}', '{$settings['join_method']}', '$esc_actor', '$esc_actor', now(), now())");
	if (!$ok) {
		return '这个地址已经被使用';
	}
	$domain = queryDomainBySlug($settings['slug']);
	auditLog('domain.create', 'domain', $domain['id'], null, array('slug' => $domain['slug'], 'name' => $domain['name']), $actor);
	return '';
}

function domainUpdateSettings($domain, $settings, $actor) {
	$settings['slug'] = $domain['slug'];
	$err = domainSettingsError($settings);
	if ($err !== '') {
		return $err;
	}
	$keys = array('name' => 0, 'description' => 0, 'type' => 0, 'visibility' => 0, 'join_method' => 0);
	$settings['name'] = trim($settings['name']);
	DB::update("update domains set name = '".DB::escape($settings['name'])."', description = '".DB::escape($settings['description'])."', type = '{$settings['type']}', visibility = '{$settings['visibility']}', join_method = '{$settings['join_method']}', updated_at = now() where id = {$domain['id']}");
	auditLog('domain.edit', 'domain', $domain['id'], array_intersect_key($domain, $keys), array_intersect_key($settings, $keys), $actor);
	return '';
}

// Hands a domain to one of its members. The owner before becomes an administrator.
function domainTransfer($domain, $new_owner, $actor) {
	if ($new_owner['username'] === $domain['owner_username']) {
		return '该用户已经是所有者';
	}
	$esc_new = DB::escape($new_owner['username']);
	$esc_old = DB::escape($domain['owner_username']);
	$done = DB::transaction(function() use ($domain, $esc_new, $esc_old) {
		// whoever takes the domain has to be in it, and it has to be in the hands it is taken from
		if (!DB::selectFirst("select 1 from domain_members where domain_id = {$domain['id']} and username = '$esc_new' for update")) {
			return false;
		}
		if (!DB::update("update domains set owner_username = '$esc_new', updated_at = now() where id = {$domain['id']} and owner_username = '$esc_old'") || DB::affected_rows() != 1) {
			return false;
		}
		DB::delete("delete from domain_members where domain_id = {$domain['id']} and username = '$esc_new'");
		return DB::insert("insert into domain_members (domain_id, username, role, joined_at, added_by) values ({$domain['id']}, '$esc_old', 'admin', now(), '$esc_new') on duplicate key update role = 'admin'");
	});
	if (!$done) {
		return '转让失败：新的所有者必须是该域的成员';
	}
	auditLog('domain.transfer', 'domain', $domain['id'], array('owner' => $domain['owner_username']), array('owner' => $new_owner['username']), $actor);
	return '';
}

function domainSetArchived($domain, $archived, $actor) {
	DB::update("update domains set archived_at = ".($archived ? 'now()' : 'null').", updated_at = now() where id = {$domain['id']}");
	auditLog($archived ? 'domain.archive' : 'domain.restore', 'domain', $domain['id'], null, null, $actor);
	return '';
}

// Adds a user to a domain or changes their role; $new_role null removes them.
function domainSetMember($domain, $target, $new_role, $actor) {
	$target_role = domainRoleOf($target['username'], $domain);
	$err = domainMemberChangeRefusedReason(domainRoleOf($actor['username'], $domain), can($actor, 'domain.manage_all'), $target_role, $new_role);
	if ($err !== '') {
		return $err;
	}
	if ($target_role === $new_role) {
		return '';
	}
	$esc_target = DB::escape($target['username']);
	if ($new_role === null) {
		DB::delete("delete from domain_members where domain_id = {$domain['id']} and username = '$esc_target'");
		auditLog('domain.remove_member', 'domain', $domain['id'], array('username' => $target['username'], 'role' => $target_role), null, $actor);
	} else {
		if ($target['usergroup'] == 'B') {
			return '该用户已被封禁';
		}
		DB::insert("insert into domain_members (domain_id, username, role, joined_at, added_by) values ({$domain['id']}, '$esc_target', '$new_role', now(), '".DB::escape($actor['username'])."') on duplicate key update role = '$new_role'");
		auditLog($target_role === null ? 'domain.add_member' : 'domain.set_role', 'domain', $domain['id'], $target_role === null ? null : array('username' => $target['username'], 'role' => $target_role), array('username' => $target['username'], 'role' => $new_role), $actor);
	}
	return '';
}
