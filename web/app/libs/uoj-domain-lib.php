<?php

// Domains: the space of a class, a course or a team, with its own members and roles, problems,
// homework, trainings, contests and announcements. Nobody but its members sees what is inside.
//
// The owner of a domain is the user named in domains.owner_username, which makes it exactly
// one. The owner is not listed in domain_members; everybody else has one of the roles
// admin, teacher, ta or member there.

function validateDomainSlug($slug) {
	return is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/D', $slug) && substr($slug, -1) !== '-';
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
// Every domain there is, for the administrators of the site: the newest first, the archived
// ones last. $search narrows them down by name, address or owner.
function domainsOfSite($search = '', $limit = 300) {
	$cond = '1';
	if (is_string($search) && trim($search) !== '') {
		$like = "'%".DB::escape(addcslashes(trim($search), '%_\\'))."%'";
		$cond = "(domains.name like $like or domains.slug like $like or domains.owner_username like $like)";
	}
	return DB::selectAll("select domains.*, (select count(*) from domain_members where domain_members.domain_id = domains.id and domain_members.username != domains.owner_username) + 1 as member_count from domains where $cond order by domains.archived_at is not null, domains.id desc limit ".(int)$limit);
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
	$ok = DB::insert("insert into domains (slug, name, description, type, owner_username, created_by, created_at, updated_at) values ('$esc_slug', '".DB::escape(trim($settings['name']))."', '".DB::escape($settings['description'])."', '{$settings['type']}', '$esc_actor', '$esc_actor', now(), now())");
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
	$keys = array('name' => 0, 'description' => 0, 'type' => 0);
	$settings['name'] = trim($settings['name']);
	DB::update("update domains set name = '".DB::escape($settings['name'])."', description = '".DB::escape($settings['description'])."', type = '{$settings['type']}', updated_at = now() where id = {$domain['id']}");
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

// ---- pages

function domainUrl($domain, $path = '') {
	return "/d/{$domain['slug']}$path";
}

// What every page inside a domain starts with: the domain of the address, for somebody who may
// be inside. Everybody else is sent away without learning whether the domain exists.
function domainOfPage() {
	global $myUser;
	$domain = isset($_GET['slug']) ? queryDomainBySlug($_GET['slug']) : null;
	if ($domain && can($myUser, 'domain.view', $domain)) {
		return $domain;
	}
	if ($myUser == null) {
		redirectToLogin();
	}
	become404Page();
}

// a message for the next page the user sees
function domainFlash($message, $type = 'success') {
	$_SESSION['domain_flash'] = array($type, $message);
}
function domainTakeFlash() {
	if (!isset($_SESSION['domain_flash'])) {
		return null;
	}
	$flash = $_SESSION['domain_flash'];
	unset($_SESSION['domain_flash']);
	return $flash;
}

// Runs the handler of the form that was posted, if any. $forms maps the value of the field
// "form" to a function that returns '' or why the form was refused. After a form that went
// through the page is loaded again, so that reloading it does not post the form twice.
// Returns the reason a form was refused, for the page to show.
function domainHandleForms($forms, $redirect = null) {
	if (!isset($_POST['form']) || !is_string($_POST['form']) || !isset($forms[$_POST['form']])) {
		return '';
	}
	crsf_defend();
	$err = $forms[$_POST['form']]();
	if ($err === '') {
		redirectTo($redirect !== null ? $redirect : UOJContext::requestPath());
	}
	return $err;
}

// the tabs of a domain for a user: 'tab' => array(label, address)
function domainTabs($domain, $user) {
	$tabs = array(
		'overview' => array('概览', domainUrl($domain)),
		'homeworks' => array('作业', domainUrl($domain, '/homeworks')),
		'trainings' => array('训练', domainUrl($domain, '/trainings')),
		'problems' => array('题目', domainUrl($domain, '/problems')),
		'contests' => array('比赛', domainUrl($domain, '/contests')),
		'members' => array('成员', domainUrl($domain, '/members')),
		'announcements' => array('公告', domainUrl($domain, '/announcements'))
	);
	// the grades are for the people who look after the domain
	if (can($user, 'domain.assist', $domain)) {
		$tabs['grades'] = array('成绩', domainUrl($domain, '/grades'));
	}
	return $tabs;
}

function echoDomainPageHeader($domain, $tab, $title) {
	echoUOJPageHeader(HTML::escape($title) . ' - ' . HTML::escape($domain['name']));
	uojIncludeView('domain-header', array('domain' => $domain, 'tab' => $tab));
}
function echoDomainError($err) {
	if ($err !== '') {
		echo '<div class="alert alert-danger" role="alert">', HTML::escape($err), '</div>';
	}
}

// ---- invitations
//
// An invitation is a token that whoever holds may join the domain with, as a member and as
// nothing more. It is a bearer secret: 144 random bits from the generator of the operating
// system, shown once when it is made. Only its SHA256 is kept, so reading the database does
// not let anybody in.

function domainInviteTokenHash($token) {
	return hash('sha256', $token);
}
function validateDomainInviteToken($token) {
	return is_string($token) && preg_match('/^[A-Za-z0-9_-]{22,64}$/', $token);
}
function domainNewInviteToken() {
	return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}
// how long an invitation may be good for: hours => label, 0 for no end
function domainInviteLifetimes() {
	return array(24 => '1 天', 168 => '7 天', 720 => '30 天', 0 => '不过期');
}

// Makes an invitation and returns its token, which is not stored and can not be shown again.
function domainCreateInvite($domain, $actor, $label, $hours, $max_uses) {
	$token = domainNewInviteToken();
	$expires = $hours > 0 ? "date_add(now(), interval ".(int)$hours." hour)" : 'null';
	$max_uses = $max_uses > 0 ? (int)$max_uses : 'null';
	$esc_label = DB::escape(mb_substr(trim($label), 0, 50, 'UTF-8'));
	if (!DB::insert("insert into domain_invites (domain_id, token_hash, label, expires_at, max_uses, created_by, created_at) values ({$domain['id']}, '".domainInviteTokenHash($token)."', '$esc_label', $expires, $max_uses, '".DB::escape($actor['username'])."', now())")) {
		return null;
	}
	auditLog('domain.create_invite', 'domain', $domain['id'], null, array('invite_id' => DB::insert_id(), 'label' => trim($label), 'hours' => (int)$hours, 'max_uses' => $max_uses === 'null' ? null : $max_uses), $actor);
	return $token;
}
function domainRevokeInvite($domain, $invite_id, $actor) {
	DB::update("update domain_invites set revoked_at = now() where id = ".(int)$invite_id." and domain_id = {$domain['id']} and revoked_at is null");
	if (DB::affected_rows() == 1) {
		auditLog('domain.revoke_invite', 'domain', $domain['id'], array('invite_id' => (int)$invite_id), null, $actor);
	}
}
// the invitations of a domain with 'state': valid, expired, used up or revoked
function domainInvites($domain) {
	return DB::selectAll("select *, case when revoked_at is not null then 'revoked' when expires_at is not null and expires_at <= now() then 'expired' when max_uses is not null and uses >= max_uses then 'used_up' else 'valid' end as state from domain_invites where domain_id = {$domain['id']} order by id desc");
}

// Lets a user join the domain of an invitation. Returns the domain, or null when the token
// is not good for anything. Why it is not, whether it ever existed, and which domain it
// belonged to is nobody's business.
function domainRedeemInvite($token, $user) {
	if (!validateDomainInviteToken($token)) {
		return null;
	}
	$hash = domainInviteTokenHash($token);
	$esc_username = DB::escape($user['username']);
	$domain = null;
	$joined = DB::transaction(function() use ($hash, $user, $esc_username, &$domain) {
		$invite = DB::selectFirst("select * from domain_invites where token_hash = '$hash' for update");
		if (!$invite) {
			return false;
		}
		$domain = queryDomain($invite['domain_id']);
		if (!$domain || $domain['archived_at'] !== null) {
			$domain = null;
			return false;
		}
		// somebody who is in already does not use the invitation up
		if (domainRoleOf($user['username'], $domain) !== null) {
			return false;
		}
		// One statement decides whether there is a use left and takes it: of two requests
		// that arrive together, only one finds the row unchanged.
		DB::update("update domain_invites set uses = uses + 1 where id = {$invite['id']} and revoked_at is null and (expires_at is null or expires_at > now()) and (max_uses is null or uses < max_uses)");
		if (DB::affected_rows() != 1) {
			$domain = null;
			return false;
		}
		// if the user can not be added after all, the use is given back with the transaction
		if (!DB::insert("insert into domain_members (domain_id, username, role, joined_at, added_by) values ({$domain['id']}, '$esc_username', 'member', now(), '')")) {
			$domain = null;
			return false;
		}
		return true;
	});
	if ($joined) {
		auditLog('domain.join', 'domain', $domain['id'], null, array('username' => $user['username'], 'by' => 'invite'), $user);
	}
	return $domain;
}

// ---- rosters

// Adds the users of a roster, one username or student number a line, to a domain. A student
// number nobody has logged in with yet waits in domain_pending_members until its student
// comes through the single sign-on. Returns what became of every line:
// array('added' => names, 'present' => names, 'pending' => student numbers, 'refused' => array(line => reason))
function domainImportRoster($domain, $text, $role, $actor) {
	$report = array('added' => array(), 'present' => array(), 'pending' => array(), 'refused' => array());
	$actor_role = domainRoleOf($actor['username'], $domain);
	$is_site_manager = can($actor, 'domain.manage_all');
	$err = domainMemberChangeRefusedReason($actor_role, $is_site_manager, null, $role);
	if ($err !== '') {
		$report['refused']['*'] = $err;
		return $report;
	}
	$lines = array_slice(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $text)), 'strlen')), 0, 2000);
	foreach ($lines as $line) {
		// The student number the school vouches for comes before a username: a username is
		// whatever somebody chose to call themselves.
		$identity = DB::selectFirst("select username from external_identities where student_id = '".DB::escape($line)."' order by id limit 1");
		$user = $identity ? queryUser($identity['username']) : null;
		if (!$user) {
			$user = validateUsername($line) ? queryUser($line) : null;
		}
		if ($user) {
			if (domainRoleOf($user['username'], $domain) !== null) {
				$report['present'][] = $user['username'];
				continue;
			}
			$err = domainSetMember($domain, $user, $role, $actor);
			if ($err === '') {
				$report['added'][] = $user['username'];
			} else {
				$report['refused'][$line] = $err;
			}
		} elseif (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $line)) {
			DB::insert("insert into domain_pending_members (domain_id, student_id, role, created_by, created_at) values ({$domain['id']}, '".DB::escape($line)."', '$role', '".DB::escape($actor['username'])."', now()) on duplicate key update role = '$role'");
			$report['pending'][] = $line;
		} else {
			$report['refused'][$line] = '不是用户名，也不是学号';
		}
	}
	auditLog('domain.import_roster', 'domain', $domain['id'], null, array('role' => $role, 'added' => count($report['added']), 'pending' => count($report['pending']), 'refused' => count($report['refused'])), $actor);
	return $report;
}

// Called when somebody came through the single sign-on: they join the domains whose rosters
// their student number waits in. Only the school says whose student number it is, so nobody
// gets into a class by registering a name that looks like one.
function domainApplyPendingMemberships($user, $student_id) {
	if (!is_string($student_id) || $student_id === '') {
		return;
	}
	foreach (DB::selectAll("select * from domain_pending_members where student_id = '".DB::escape($student_id)."'") as $pending) {
		$domain = queryDomain($pending['domain_id']);
		if ($domain && $domain['archived_at'] === null && domainRoleOf($user['username'], $domain) === null && $user['usergroup'] != 'B') {
			DB::insert("insert ignore into domain_members (domain_id, username, role, joined_at, added_by) values ({$domain['id']}, '".DB::escape($user['username'])."', '{$pending['role']}', now(), '".DB::escape($pending['created_by'])."')");
			auditLog('domain.join', 'domain', $domain['id'], null, array('username' => $user['username'], 'by' => 'roster', 'role' => $pending['role']), $user);
		}
		DB::delete("delete from domain_pending_members where id = {$pending['id']}");
	}
}

// ---- announcements

// what the people who teach in a domain write is Markdown; what is shown is its purified HTML
function domainRenderMarkdown($markdown) {
	return HTML::pruifier()->purify(HTML::parsedown()->text($markdown));
}
// the announcements of a domain: the pinned ones first, then the newest
function domainAnnouncements($domain, $limit = 200) {
	return DB::selectAll("select * from domain_announcements where domain_id = {$domain['id']} order by pinned desc, id desc limit ".(int)$limit);
}
// Stores an announcement, a new one when $id is null. Returns '' or what is wrong with it.
function domainSaveAnnouncement($domain, $id, $title, $content_md, $pinned, $actor) {
	$title = trim($title);
	if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
		return '标题不能为空，且不超过 200 个字符';
	}
	if (trim($content_md) === '' || strlen($content_md) > 200000) {
		return '正文不能为空，也不能太长';
	}
	$set = "title = '".DB::escape($title)."', content_md = '".DB::escape($content_md)."', content = '".DB::escape(domainRenderMarkdown($content_md))."', pinned = ".($pinned ? 1 : 0).", updated_at = now()";
	if ($id === null) {
		DB::insert("insert into domain_announcements set domain_id = {$domain['id']}, created_by = '".DB::escape($actor['username'])."', created_at = now(), $set");
		auditLog('domain.post_announcement', 'domain', $domain['id'], null, array('announcement_id' => DB::insert_id(), 'title' => $title), $actor);
	} else {
		DB::update("update domain_announcements set $set where id = ".(int)$id." and domain_id = {$domain['id']}");
		auditLog('domain.edit_announcement', 'domain', $domain['id'], null, array('announcement_id' => (int)$id, 'title' => $title), $actor);
	}
	return '';
}

// ---- problems
//
// A problem with owner_domain_id belongs to that domain: the people who teach there manage it,
// and nobody outside of the domain sees it. A problem of the site, or of another domain, gets
// into a domain as a copy, which is a problem of its own from then on: where it came from is
// remembered, and nothing follows from it.

function domainProblemUrl($domain, $problem_id) {
	return domainUrl($domain, "/problem/$problem_id");
}

// Creates an empty problem in a domain and returns its id.
function domainNewProblem($domain, $actor) {
	requirePHPLib('judger');
	requirePHPLib('data');
	DB::insert("insert into problems (title, is_hidden, submission_requirement, owner_domain_id) values ('New Problem', 1, '{}', {$domain['id']})");
	$id = DB::insert_id();
	DB::insert("insert into problems_contents (id, statement, statement_md) values ($id, '', '')");
	dataNewProblem($id);
	auditLog('problem.create', 'problem', $id, null, array('domain_id' => (int)$domain['id']), $actor);
	return $id;
}

// Copies a problem into a domain: its statement, tags and settings, and the files that were
// uploaded for it, from which the data of the copy is built like after any upload. The copy
// starts hidden. Returns array(id of the copy, '') or array(null, why not).
//
// What is not copied: submissions, hacks, statistics, who manages the problem, and whether
// its custom judger was approved. The files of the copy are hashed when they are synced, and
// have to be approved by what they are.
function domainCopyProblem($source, $domain, $actor) {
	requirePHPLib('judger');
	requirePHPLib('data');
	$source_version = dataCurrentVersion($source);
	if (!$source_version) {
		return array(null, "题目 #{$source['id']} 还没有数据，不能复制");
	}
	$extra_config = json_decode($source['extra_config'], true);
	$extra_config = is_array($extra_config) ? $extra_config : array();
	unset($extra_config['custom_judger_fingerprint']);
	
	if (!DB::insert("insert into problems (title, is_hidden, submission_requirement, hackable, extra_config, owner_domain_id, source_problem_id, source_data_version, imported_at, imported_by) values ('".DB::escape($source['title'])."', 1, '".DB::escape($source['submission_requirement'])."', ".(int)$source['hackable'].", '".DB::escape(json_encode($extra_config))."', {$domain['id']}, {$source['id']}, {$source_version['version']}, now(), '".DB::escape($actor['username'])."')")) {
		return array(null, '复制失败');
	}
	$id = DB::insert_id();
	$content = queryProblemContent($source['id']);
	DB::insert("insert into problems_contents (id, statement, statement_md) values ($id, '".DB::escape($content['statement'])."', '".DB::escape($content['statement_md'])."')");
	foreach (queryProblemTags($source['id']) as $tag) {
		DB::insert("insert into problems_tags (problem_id, tag) values ($id, '".DB::escape($tag)."')");
	}
	dataNewProblem($id);
	auditLog('problem.copy', 'problem', $id, null, array('domain_id' => (int)$domain['id'], 'source_problem_id' => (int)$source['id'], 'source_data_version' => (int)$source_version['version']), $actor);
	exec("cp -a ".escapeshellarg("/var/uoj_data/upload/{$source['id']}/.")." ".escapeshellarg("/var/uoj_data/upload/$id/"), $output, $status);
	$err = $status === 0 ? dataSyncProblemData(queryProblemBrief($id), $actor, array('reason' => 'copy')) : '复制数据文件失败';
	if ($err) {
		// a copy without data is of no use to anybody
		DB::delete("delete from problems where id = $id");
		DB::delete("delete from problems_contents where id = $id");
		DB::delete("delete from problems_tags where problem_id = $id");
		exec("rm -rf ".escapeshellarg("/var/uoj_data/upload/$id")." ".escapeshellarg("/var/uoj_data/$id")." ".escapeshellarg("/var/uoj_data/$id.zip"));
		auditLog('problem.copy_failed', 'problem', $id, null, array('reason' => strip_tags($err)), $actor);
		return array(null, "复制题目 #{$source['id']} 失败：" . strip_tags($err));
	}
	return array($id, '');
}

// A copy of a problem that a domain has already and that nobody has touched since: the same
// source at the same version of its data, and the data of the copy still the first it was given.
function domainUntouchedCopy($domain, $source) {
	requirePHPLib('judger');
	requirePHPLib('data');
	$source_version = dataCurrentVersion($source);
	if (!$source_version) {
		return null;
	}
	return DB::selectFirst("select problems.* from problems where owner_domain_id = {$domain['id']} and source_problem_id = {$source['id']} and source_data_version = {$source_version['version']} and not exists (select 1 from problem_data_versions where problem_id = problems.id and version > 1) order by id limit 1", MYSQLI_ASSOC);
}
// whether the data of a problem can be judged with: its newest version is published
function domainProblemDataState($problem_id) {
	$row = DB::selectFirst("select status, message from problem_data_versions where problem_id = ".(int)$problem_id." order by version desc limit 1");
	if (!$row) {
		return array('none', '');
	}
	return array($row['status'], (string)$row['message']);
}

// ---- contests

// on the pages of a contest of a domain: the way back to the domain
function echoContestDomainLink($contest) {
	if (empty($contest['domain_id'])) {
		return;
	}
	$domain = queryDomain($contest['domain_id']);
	if ($domain) {
		echo '<p class="uoj-domain-back"><a href="', domainUrl($domain, '/contests'), '"><span class="glyphicon glyphicon-chevron-left"></span> ', HTML::escape($domain['name']), '</a></p>';
	}
}
