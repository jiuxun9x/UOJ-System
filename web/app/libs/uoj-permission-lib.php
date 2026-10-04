<?php

// Who may do what. Every permission check of the web application goes through can(), so that
// the rules can be read, changed and tested in one place.
//
// An ability is named "<resource>.<action>", and the resource is the database row it is about:
// a problem, a contest (after genMoreContestInfo), a submission, a hack, the owner of a blog.
// Abilities about the whole site take no resource.
//
// Roles of the whole site:
//   system_admin  everything, including roles, judgers and programs that run unrestricted
//                 on the judgers. These are the users with usergroup 'S', as before.
//   oj_admin      every problem, contest, submission and blog, but not the above
//   teacher       may create problems, contests and domains, and manages the ones they created
//   domain_creator  may create domains, and nothing else
// Roles of one problem or contest:
//   manager of a problem (problems_permissions)
//   owner of a contest: settings, problems, staff, final test, results
//   assistant of a contest: sees everything behind the scenes and answers questions
// Roles in one domain (see uoj-domain-lib.php): its owner, and the members who are admin,
// teacher, ta or member there. What happens inside a domain is decided by these alone; the
// administrators of the site can do in every domain what its owner can.
// Everybody else is a student.

define('UOJ_ROLE_SYSTEM_ADMIN', 'system_admin');
define('UOJ_ROLE_OJ_ADMIN', 'oj_admin');
define('UOJ_ROLE_TEACHER', 'teacher');
define('UOJ_ROLE_DOMAIN_CREATOR', 'domain_creator');

// the roles that are stored in user_roles, with their names for the pages
function grantableRoles() {
	return array(
		UOJ_ROLE_OJ_ADMIN => 'OJ 管理员',
		UOJ_ROLE_TEACHER => '教师',
		UOJ_ROLE_DOMAIN_CREATOR => '可创建域'
	);
}

// What the rules need to know from the database. The tests replace it.
class UOJPermissionFacts {
	private $problems = array();
	private $contests = array();

	private $roles = array();
	private $running = array();

	public function grantedRoles($username) {
		if (!isset($this->roles[$username])) {
			$this->roles[$username] = array();
			foreach (DB::selectAll("select role from user_roles where username = '".DB::escape($username)."'") as $row) {
				$this->roles[$username][] = $row['role'];
			}
		}
		return $this->roles[$username];
	}
	private $site_settings = null;

	// what a setting of the site was set to, null when nobody has set it
	public function siteSetting($name) {
		if ($this->site_settings === null) {
			$this->site_settings = array();
			foreach (DB::selectAll("select name, value from site_settings") as $row) {
				$this->site_settings[$row['name']] = $row['value'];
			}
		}
		return isset($this->site_settings[$name]) ? $this->site_settings[$name] : null;
	}
	public function forgetSiteSettings() {
		$this->site_settings = null;
	}
	private $domains = array();
	private $domain_roles = array();

	// the role a user was given in a domain, null for the owner and for strangers
	public function domainMemberRole($username, $domain_id) {
		$key = $username . '/' . (int)$domain_id;
		if (!array_key_exists($key, $this->domain_roles)) {
			$row = DB::selectFirst("select role from domain_members where domain_id = ".(int)$domain_id." and username = '".DB::escape($username)."'");
			$this->domain_roles[$key] = $row ? $row['role'] : null;
		}
		return $this->domain_roles[$key];
	}
	public function domain($domain_id) {
		$domain_id = (int)$domain_id;
		if (!$domain_id) {
			return null;
		}
		if (!array_key_exists($domain_id, $this->domains)) {
			$this->domains[$domain_id] = queryDomain($domain_id);
		}
		return $this->domains[$domain_id];
	}
	private $homeworks = array();

	// the moment the rules about contests and homework are applied to: the clock of the web server
	public function now() {
		return UOJTime::$time_now->getTimestamp();
	}
	public function homework($homework_id) {
		$homework_id = (int)$homework_id;
		if (!$homework_id) {
			return null;
		}
		if (!array_key_exists($homework_id, $this->homeworks)) {
			$this->homeworks[$homework_id] = queryHomework($homework_id);
		}
		return $this->homeworks[$homework_id];
	}
	// 'active', 'withdrawn' or null
	public function homeworkParticipantStatus($username, $homework_id) {
		$row = homeworkParticipation($homework_id, $username);
		return $row ? $row['status'] : null;
	}
	public function isHomeworkMaintainer($username, $homework_id) {
		return DB::selectFirst("select 1 from homework_maintainers where homework_id = ".(int)$homework_id." and username = '".DB::escape($username)."'") != null;
	}
	// whether a problem is one of the problems of a homework that is running
	public function problemIsInRunningHomework($problem_id) {
		return DB::selectFirst("select 1 from homework_problems, homeworks where homework_problems.problem_id = ".(int)$problem_id." and homeworks.id = homework_problems.homework_id and ".runningHomeworksCond()." limit 1") != null;
	}
	// whether a user takes part in a running homework that has a copy of this problem
	public function userRunsHomeworkFromSource($username, $problem_id) {
		return DB::selectFirst("select 1 from homework_problems, homeworks, homework_participants where homework_problems.source_problem_id = ".(int)$problem_id." and homeworks.id = homework_problems.homework_id and ".runningHomeworksCond()." and homework_participants.homework_id = homeworks.id and homework_participants.username = '".DB::escape($username)."' and homework_participants.status = 'active' limit 1") != null;
	}
	public function managesProblem($username, $problem_id) {
		return DB::selectFirst("select 1 from problems_permissions where username = '".DB::escape($username)."' and problem_id = ".(int)$problem_id) != null;
	}
	// 'owner', 'assistant' or null
	public function contestRole($username, $contest_id) {
		$row = DB::selectFirst("select role from contests_permissions where username = '".DB::escape($username)."' and contest_id = ".(int)$contest_id);
		return $row ? $row['role'] : null;
	}
	public function hasRegistered($username, $contest_id) {
		return DB::selectFirst("select 1 from contests_registrants where username = '".DB::escape($username)."' and contest_id = ".(int)$contest_id) != null;
	}
	public function hasAccepted($username, $problem_id) {
		return DB::selectFirst("select 1 from best_ac_submissions where submitter = '".DB::escape($username)."' and problem_id = ".(int)$problem_id) != null;
	}
	// A problem is closed while a contest it is part of runs. The clock is the one of the web
	// server, the same that decides whether a contest has started.
	public function problemIsInRunningContest($problem_id) {
		$problem_id = (int)$problem_id;
		if (!isset($this->running[$problem_id])) {
			$this->running[$problem_id] = DB::selectFirst("select 1 from contests_problems, contests where contests_problems.problem_id = $problem_id and contests.id = contests_problems.contest_id and ".runningContestsCond()." limit 1") != null;
		}
		return $this->running[$problem_id];
	}
	public function problem($problem_id) {
		$problem_id = (int)$problem_id;
		if (!array_key_exists($problem_id, $this->problems)) {
			$this->problems[$problem_id] = queryProblemBrief($problem_id);
		}
		return $this->problems[$problem_id];
	}
	// null for a submission that was not made in a contest
	public function contest($contest_id) {
		$contest_id = (int)$contest_id;
		if (!$contest_id) {
			return null;
		}
		if (!array_key_exists($contest_id, $this->contests)) {
			$contest = queryContest($contest_id);
			if ($contest) {
				genMoreContestInfo($contest);
			}
			$this->contests[$contest_id] = $contest;
		}
		return $this->contests[$contest_id];
	}
}

function permissionFacts($replacement = null) {
	static $facts = null;
	if ($replacement !== null) {
		$facts = $replacement;
	} elseif ($facts === null) {
		$facts = new UOJPermissionFacts();
	}
	return $facts;
}

// The settings of the site that the system administrators change while it runs:
// 'name' => array('type', 'group', 'label', 'help', 'default', ...). The types are
//   switch  on or off
//   text    a line of text, at most 'max' characters long
//   number  a whole number from 'min' to 'max'
//   choice  one of 'choices'
//   secret  a line of text that is never shown again once it is saved
function siteSettings() {
	return array(
		'domain.open_creation' => array(
			'type' => 'switch',
			'group' => '域',
			'label' => '允许所有登录用户创建域',
			'help' => '关闭时，只有管理员、教师和被授予“可创建域”角色的用户能创建域。开启后，任何登录用户都能创建域，并在自己的域里新建题目、上传数据、布置作业和举办比赛。',
			'default' => false
		),
		'mail.host' => array(
			'type' => 'text',
			'group' => '发信邮箱',
			'label' => 'SMTP 服务器',
			'help' => '例如 smtp.exmail.qq.com。留空表示使用配置文件里的 mail.noreply。找回密码和告警邮件都从这个邮箱发出。',
			'default' => '',
			'max' => 200
		),
		'mail.port' => array(
			'type' => 'number',
			'group' => '发信邮箱',
			'label' => '端口',
			'help' => 'SSL 一般是 465，STARTTLS 一般是 587。',
			'default' => 465,
			'min' => 1,
			'max' => 65535
		),
		'mail.secure' => array(
			'type' => 'choice',
			'group' => '发信邮箱',
			'label' => '加密方式',
			'help' => '',
			'default' => 'ssl',
			'choices' => array('ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => '不加密（仅限内网的邮件服务器）')
		),
		'mail.username' => array(
			'type' => 'text',
			'group' => '发信邮箱',
			'label' => '邮箱地址',
			'help' => '发件人地址，同时是登录 SMTP 服务器的用户名。',
			'default' => '',
			'max' => 200
		),
		'mail.password' => array(
			'type' => 'secret',
			'group' => '发信邮箱',
			'label' => '密码或授权码',
			'help' => '保存后不再显示。留空表示不修改。',
			'default' => '',
			'max' => 200
		),
		'mail.from_name' => array(
			'type' => 'text',
			'group' => '发信邮箱',
			'label' => '发件人名称',
			'help' => '留空则用站点的简称。',
			'default' => '',
			'max' => 60
		)
	);
}
// What a value that was stored or posted is worth for a setting: true or false for a switch,
// a whole number for a number, a string for the rest. null when it is not a value the
// setting can have.
function siteSettingParse($setting, $raw) {
	if (!is_string($raw)) {
		return null;
	}
	switch ($setting['type']) {
		case 'switch':
			return $raw === '1' ? true : ($raw === '0' ? false : null);
		case 'number':
			if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $raw) || (int)$raw < $setting['min'] || (int)$raw > $setting['max']) {
				return null;
			}
			return (int)$raw;
		case 'choice':
			return isset($setting['choices'][$raw]) ? $raw : null;
		case 'text':
		case 'secret':
			// one line, without what does not belong in one
			if (preg_match('/[\x00-\x1f\x7f]/', $raw) || mb_strlen($raw, 'UTF-8') > $setting['max']) {
				return null;
			}
			return $raw;
	}
	return null;
}
// Sets several settings at once, 'name' => what was posted for it: all of them, or none
// when one of the values is refused. Returns '' or why.
function setSiteSettings($values, $actor) {
	foreach ($values as $name => $raw) {
		$err = setSiteSetting($name, $raw, null);
		if ($err !== '') {
			return $err;
		}
	}
	foreach ($values as $name => $raw) {
		$err = setSiteSetting($name, $raw, $actor);
		if ($err !== '') {
			return $err;
		}
	}
	return '';
}
// what a setting of the site is: what it was set to, or what it is when nobody has touched it
function siteSetting($name) {
	$settings = siteSettings();
	if (!isset($settings[$name])) {
		return null;
	}
	$stored = permissionFacts()->siteSetting($name);
	$value = $stored === null ? null : siteSettingParse($settings[$name], $stored);
	return $value === null ? $settings[$name]['default'] : $value;
}
function siteSettingIsOn($name) {
	return siteSetting($name) === true;
}
// Sets a setting of the site to what was posted for it: a string, or true or false for a
// switch. Returns '' or why it was refused. What a secret was or becomes is never written
// to the audit log.
function setSiteSetting($name, $raw, $actor) {
	$settings = siteSettings();
	if (!isset($settings[$name])) {
		return '没有这个设置';
	}
	$setting = $settings[$name];
	if (is_bool($raw)) {
		$raw = $raw ? '1' : '0';
	}
	if ($setting['type'] !== 'secret' && is_string($raw)) {
		$raw = trim($raw);
	}
	$value = siteSettingParse($setting, $raw);
	if ($value === null) {
		return "“{$setting['label']}”的值无效";
	}
	if ($actor === null) {
		// only asked whether the value would do
		return '';
	}
	$before = siteSetting($name);
	if ($before === $value) {
		return '';
	}
	DB::insert("insert into site_settings (name, value, updated_by, updated_at) values ('".DB::escape($name)."', '".DB::escape($raw)."', '".DB::escape($actor['username'])."', now()) on duplicate key update value = values(value), updated_by = values(updated_by), updated_at = values(updated_at)");
	permissionFacts()->forgetSiteSettings();
	if ($setting['type'] === 'secret') {
		auditLog('site.edit_setting', 'site_setting', $name, null, array('changed' => true), $actor);
	} elseif ($setting['type'] === 'switch') {
		auditLog('site.edit_setting', 'site_setting', $name, array('on' => $before), array('on' => $value), $actor);
	} else {
		auditLog('site.edit_setting', 'site_setting', $name, array('value' => $before), array('value' => $value), $actor);
	}
	return '';
}

function userRoles($user) {
	if ($user == null) {
		return array();
	}
	$roles = permissionFacts()->grantedRoles($user['username']);
	if ($user['usergroup'] == 'S') {
		$roles[] = UOJ_ROLE_SYSTEM_ADMIN;
	}
	return $roles;
}
function userHasRole($user, $role) {
	return in_array($role, userRoles($user), true);
}
// the two kinds of administrators
function isSiteAdmin($user) {
	return userHasRole($user, UOJ_ROLE_SYSTEM_ADMIN) || userHasRole($user, UOJ_ROLE_OJ_ADMIN);
}

// The role of a user in a domain: 'owner' for the user the domain names as its owner, the role
// they were given as a member, null for everybody else.
function permissionDomainRole($user, $domain) {
	if ($user == null || $domain == null) {
		return null;
	}
	if ($domain['owner_username'] === $user['username']) {
		return 'owner';
	}
	return permissionFacts()->domainMemberRole($user['username'], $domain['id']);
}
// whether a user has at least this role in a domain; the administrators of the site have every role
function permissionDomainRoleAtLeast($user, $domain, $role) {
	if ($domain == null) {
		return false;
	}
	return isSiteAdmin($user) || domainRoleRank(permissionDomainRole($user, $domain)) >= domainRoleRank($role);
}

function can($user, $ability, $resource = null) {
	$facts = permissionFacts();
	$name = $user != null ? $user['username'] : null;
	$is_admin = isSiteAdmin($user);

	switch ($ability) {
		// ---- domains, the resource is the domain
		// Whether everybody may create domains is a switch of the system administrators.
		// While it is off, the people who may are the ones who were given a role for it.
		case 'domain.create':
			if ($is_admin || userHasRole($user, UOJ_ROLE_TEACHER) || userHasRole($user, UOJ_ROLE_DOMAIN_CREATOR)) {
				return true;
			}
			return $name !== null && $user['usergroup'] != 'B' && siteSettingIsOn('domain.open_creation');
		case 'domain.manage_all':
			return $is_admin;
		// A domain is seen by the people in it and by the administrators of the site. To
		// everybody else it does not exist: no page, no list, no way to ask to be let in.
		case 'domain.view':
			return permissionDomainRoleAtLeast($user, $resource, 'member');
		// reading the grades and every submission
		case 'domain.assist':
			return permissionDomainRoleAtLeast($user, $resource, 'ta');
		// An archived domain is read only, until its owner brings it back.
		// problems, homework, trainings, contests, announcements and grades
		case 'domain.teach':
			return $resource['archived_at'] === null && permissionDomainRoleAtLeast($user, $resource, 'teacher');
		// its settings, how to join it, and who is in it
		case 'domain.manage':
		case 'member.manage':
			return $resource['archived_at'] === null && permissionDomainRoleAtLeast($user, $resource, 'admin');
		// handing it over, archiving it and bringing it back
		case 'domain.own':
			return permissionDomainRoleAtLeast($user, $resource, 'owner');

		// ---- the site
		case 'user.manage_roles':
		case 'user.rename':
		case 'site.manage_settings':
		case 'audit.view':
		case 'judger.manage':
		case 'problem.edit_raw_config':
		// a judger of a problem runs unrestricted on the judgers
		case 'problem.approve_judger':
			return userHasRole($user, UOJ_ROLE_SYSTEM_ADMIN);
		case 'site.manage':
		case 'user.ban':
		case 'user.view_private':
		// the ratings of the whole site depend on it
		case 'contest.rate':
		case 'submission.view_all':
		case 'submission.delete':
		case 'hack.delete':
			return $is_admin;
		case 'problem.create':
		case 'contest.create':
			return $is_admin || userHasRole($user, UOJ_ROLE_TEACHER);
		// The student number and the real name of a user, the resource. Without a resource:
		// of everybody.
		case 'user.view_identity':
			if ($is_admin || userHasRole($user, UOJ_ROLE_TEACHER)) {
				return true;
			}
			return $name !== null && $resource != null && $resource['username'] === $name;

		// ---- trainings, the resource is the training
		case 'training.manage':
			return can($user, 'domain.teach', $facts->domain($resource['domain_id']));
		// a draft is for the people who write it
		case 'training.view':
			$domain = $facts->domain($resource['domain_id']);
			return can($user, 'domain.teach', $domain) || ($resource['status'] === 'published' && can($user, 'domain.view', $domain));
		// what every member has done of it
		case 'training.view_progress':
			return can($user, 'domain.assist', $facts->domain($resource['domain_id']));

		// ---- homework, the resource is the homework
		// changing it, publishing it, deciding who takes part and what the scores are; and
		// reading the scores and everything that was submitted
		case 'homework.manage':
		case 'homework.view_scores':
			$domain = $facts->domain($resource['domain_id']);
			if (can($user, $ability == 'homework.manage' ? 'domain.teach' : 'domain.assist', $domain)) {
				return true;
			}
			// a member of the domain who was asked to look after this homework
			if ($name === null || $domain == null || ($ability == 'homework.manage' && $domain['archived_at'] !== null)) {
				return false;
			}
			return permissionDomainRole($user, $domain) !== null && $facts->isHomeworkMaintainer($name, $resource['id']);
		// that it exists, what it is about and when
		case 'homework.view':
			if (can($user, 'homework.view_scores', $resource)) {
				return true;
			}
			return $resource['status'] === 'published' && can($user, 'domain.view', $facts->domain($resource['domain_id']));
		// taking part: the students of the domain, until claiming ends
		case 'homework.claim':
			$domain = $facts->domain($resource['domain_id']);
			if ($name === null || $domain == null || $domain['archived_at'] !== null || $resource['status'] !== 'published') {
				return false;
			}
			if (permissionDomainRole($user, $domain) !== 'member') {
				return false;
			}
			$until = $resource['claim_end_at'] !== null ? $resource['claim_end_at'] : $resource['end_at'];
			return $facts->now() < strtotime($until) && $facts->homeworkParticipantStatus($name, $resource['id']) !== 'active';
		// its problems: whoever claimed it, once it has begun
		case 'homework.solve':
			if (can($user, 'homework.view_scores', $resource)) {
				return true;
			}
			return $name !== null && $resource['status'] === 'published' && $facts->now() >= strtotime($resource['begin_at'])
				&& can($user, 'domain.view', $facts->domain($resource['domain_id']))
				&& $facts->homeworkParticipantStatus($name, $resource['id']) === 'active';

		// ---- problems
		// A problem that belongs to a domain is managed by the people who teach there, and seen
		// by nobody outside of the domain.
		case 'problem.manage':
			if ($is_admin) {
				return true;
			}
			if ($name === null) {
				return false;
			}
			if (!empty($resource['owner_domain_id']) && can($user, 'domain.teach', $facts->domain($resource['owner_domain_id']))) {
				return true;
			}
			return $facts->managesProblem($name, $resource['id']);
		case 'problem.view':
			if (can($user, 'problem.manage', $resource)) {
				return true;
			}
			if (!empty($resource['owner_domain_id']) && !can($user, 'domain.view', $facts->domain($resource['owner_domain_id']))) {
				return false;
			}
			return !$resource['is_hidden'];
		// Taking a copy of a problem into a domain: a problem of the site that the user can
		// see, or a problem of another domain where the user teaches.
		case 'problem.copy':
			if ($name === null) {
				return false;
			}
			if (!empty($resource['owner_domain_id'])) {
				return can($user, 'domain.teach', $facts->domain($resource['owner_domain_id']));
			}
			return can($user, 'problem.view', $resource);

		// A problem that may be put into a training or a contest of a domain: one the user
		// manages, or a problem of the site that everybody can see.
		case 'problem.use':
			return can($user, 'problem.manage', $resource) || (empty($resource['owner_domain_id']) && !$resource['is_hidden']);

		// ---- contests
		// A contest of a domain exists for the members of the domain only, and is run by the
		// people who teach there.
		case 'contest.view':
			return empty($resource['domain_id']) || can($user, 'domain.view', $facts->domain($resource['domain_id']));
		case 'contest.manage':
			if ($is_admin || ($name !== null && $facts->contestRole($name, $resource['id']) === 'owner')) {
				return true;
			}
			return !empty($resource['domain_id']) && can($user, 'domain.teach', $facts->domain($resource['domain_id']));
		// the people behind the scenes of a contest: they see everything and answer questions
		case 'contest.assist':
			if ($is_admin || ($name !== null && $facts->contestRole($name, $resource['id']) !== null)) {
				return true;
			}
			return !empty($resource['domain_id']) && can($user, 'domain.assist', $facts->domain($resource['domain_id']));

		// ---- blogs, the resource is the name of the user the blog belongs to
		case 'blog.manage':
			return $is_admin || ($name !== null && $name === $resource);

		// ---- submissions
		case 'submission.view':
			if (permissionIsStaffOf($user, $resource)) {
				return true;
			}
			if ($resource['is_hidden']) {
				return false;
			}
			// what is submitted in a domain stays in the domain
			if (!empty($resource['domain_id']) && !can($user, 'domain.view', $facts->domain($resource['domain_id']))) {
				return false;
			}
			// nor is what is submitted to a homework anybody else's business before the homework is over
			if (!empty($resource['homework_id'])) {
				$homework = $facts->homework($resource['homework_id']);
				if ($homework != null && $facts->now() < strtotime($homework['end_at'])) {
					return $name !== null && $resource['submitter'] === $name;
				}
			}
			// what is submitted in a contest is nobody else's business while the contest runs
			$contest = $facts->contest($resource['contest_id']);
			if ($contest != null && $contest['cur_progress'] <= CONTEST_IN_PROGRESS) {
				return $name !== null && $resource['submitter'] === $name;
			}
			return true;
		case 'submission.rejudge':
			return can($user, 'problem.manage', $facts->problem($resource['problem_id']));
		case 'submission.view_source':
			if (permissionIsStaffOf($user, $resource)) {
				return true;
			}
			if (!can($user, 'submission.view', $resource)) {
				return false;
			}
			if ($name !== null && $resource['submitter'] === $name) {
				return true;
			}
			return !permissionIsClosed($resource, $user) && permissionViewTypeAllows('view_content_type', $user, $resource);
		// the verdict of every test
		case 'submission.view_details':
			if (permissionIsStaffOf($user, $resource)) {
				return true;
			}
			if (!can($user, 'submission.view', $resource)) {
				return false;
			}
			if (permissionIsClosed($resource, $user) && !($name !== null && $resource['submitter'] === $name)) {
				return false;
			}
			return permissionViewTypeAllows('view_all_details_type', $user, $resource);
		// what every test did, not only its verdict
		case 'submission.view_test_details':
			if (permissionIsStaffOf($user, $resource)) {
				return true;
			}
			$contest = $facts->contest($resource['contest_id']);
			if ($contest != null && $contest['cur_progress'] == CONTEST_IN_PROGRESS) {
				$contest_config = $contest['extra_config'];
				if (isset($contest_config['contest_type']) && $contest_config['contest_type'] == 'IOI') {
					return false;
				}
				if (isset($contest_config["problem_{$resource['problem_id']}"]) && $contest_config["problem_{$resource['problem_id']}"] === 'no-details') {
					return false;
				}
			}
			return permissionViewTypeAllows('view_details_type', $user, $resource);
		// everything the judgers reported, whatever the contest shows to its participants
		case 'submission.view_final_details':
			return permissionIsStaffOf($user, $resource);
		// Hacking needs the source, and a successful hack changes the data of the problem and
		// judges everything again: not while a contest with the problem runs.
		case 'submission.hack':
			if ($name === null) {
				return false;
			}
			if (permissionIsStaffOf($user, $resource)) {
				return true;
			}
			return !permissionIsClosed($resource, $user) && can($user, 'submission.view_source', $resource);

		// ---- hacks, the resource is the hack with the submission it hacks in 'submission'
		case 'hack.view':
			$problem = $facts->problem($resource['problem_id']);
			if ($is_admin || can($user, 'problem.manage', $problem)) {
				return true;
			}
			if (!empty($problem['owner_domain_id']) && !can($user, 'domain.view', $facts->domain($problem['owner_domain_id']))) {
				return false;
			}
			return !$resource['is_hidden'];
		case 'hack.view_source':
			return can($user, 'submission.view_source', $resource['submission']);
		case 'hack.view_details':
			if (permissionIsStaffOf($user, $resource['submission'])) {
				return true;
			}
			$involved = $name !== null && ($name === $resource['hacker'] || $name === $resource['submission']['submitter']);
			if (permissionIsClosed($resource['submission'], $user) && !$involved) {
				return false;
			}
			return permissionViewTypeAllows('view_all_details_type', $user, $resource['submission']);
		case 'hack.view_test_details':
			return permissionIsStaffOf($user, $resource['submission']) || permissionViewTypeAllows('view_details_type', $user, $resource['submission']);
		case 'hack.view_final_details':
			return permissionIsStaffOf($user, $resource['submission']);
	}

	// a typo in the name of an ability must not open a door
	trigger_error("unknown ability: $ability", E_USER_WARNING);
	return false;
}

// What the problem of a submission lets everybody see: its settings view_content_type,
// view_all_details_type and view_details_type are ALL, ALL_AFTER_AC or SELF.
function permissionViewTypeAllows($setting, $user, $submission) {
	$facts = permissionFacts();
	$problem = $facts->problem($submission['problem_id']);
	$type = getProblemExtraConfig($problem)[$setting];
	if ($type == 'ALL') {
		return true;
	}
	if ($type == 'ALL_AFTER_AC') {
		return $user != null && $facts->hasAccepted($user['username'], $problem['id']);
	}
	// Names are compared as the strings they are: to PHP '0123' == '123' and '21E01' == '210',
	// and a student number may well look like a number.
	if ($type == 'SELF') {
		return $user != null && $submission['submitter'] === $user['username'];
	}
	return false;
}

// The people who are responsible for a submission: the administrators, whoever manages its
// problem, and the staff of the contest it was made in. They see all of it, always.
function permissionIsStaffOf($user, $submission) {
	if ($user == null) {
		return false;
	}
	$facts = permissionFacts();
	if (isSiteAdmin($user) || can($user, 'problem.manage', $facts->problem($submission['problem_id']))) {
		return true;
	}
	// the people who assist in the domain a submission was made in
	if (!empty($submission['domain_id']) && can($user, 'domain.assist', $facts->domain($submission['domain_id']))) {
		return true;
	}
	// and the people who look after the homework it was submitted to
	if (!empty($submission['homework_id'])) {
		$homework = $facts->homework($submission['homework_id']);
		if ($homework != null && can($user, 'homework.view_scores', $homework)) {
			return true;
		}
	}
	$contest = $facts->contest($submission['contest_id']);
	return $contest != null && can($user, 'contest.assist', $contest);
}

// While a contest runs, the submissions to its problems are closed: nobody but their owners
// and the staff reads their source or their details, whenever and wherever they were
// submitted. Outside of that, the settings of the problem decide.
//
// The same holds for homework: what is submitted to a homework is closed until the homework is
// over, and so is everything else that was ever submitted to one of its problems. The problems
// of a homework are copies that belong to its domain; the problem of the site a copy was made
// from stays open to the site, but not to the people who take part in the homework.
function permissionIsClosed($submission, $user = null) {
	$facts = permissionFacts();
	$contest = $facts->contest($submission['contest_id']);
	if ($contest != null && $contest['cur_progress'] <= CONTEST_IN_PROGRESS) {
		return true;
	}
	if ($facts->problemIsInRunningContest($submission['problem_id'])) {
		return true;
	}
	if (!empty($submission['homework_id'])) {
		$homework = $facts->homework($submission['homework_id']);
		if ($homework != null && $facts->now() < strtotime($homework['end_at'])) {
			return true;
		}
	}
	if ($facts->problemIsInRunningHomework($submission['problem_id'])) {
		return true;
	}
	return $user != null && $facts->userRunsHomeworkFromSource($user['username'], $submission['problem_id']);
}
// the homeworks that are running, as a condition on the table homeworks
function runningHomeworksCond() {
	$now = UOJTime::$time_now_str;
	return "homeworks.status = 'published' and homeworks.begin_at <= '$now' and homeworks.end_at > '$now'";
}

// the contests that are running, as a condition on the table contests
function runningContestsCond() {
	$now = UOJTime::$time_now_str;
	return "contests.status = 'unfinished' and contests.start_time <= '$now' and date_add(contests.start_time, interval contests.last_min minute) > '$now'";
}

// The conditions that keep what a user may not see out of the lists of submissions and hacks.
function visibleSubmissionsCond($user) {
	if (can($user, 'submission.view_all')) {
		return '1';
	}
	// the same rules as 'submission.view'
	$in_running_contest = "submissions.contest_id in (select id from contests where ".runningContestsCond().")";
	if ($user == null) {
		return "submissions.is_hidden = false and submissions.domain_id is null and (submissions.contest_id is null or not $in_running_contest)";
	}
	$esc_username = DB::escape($user['username']);
	$manages_problem = "submissions.problem_id in (select problem_id from problems_permissions where username = '$esc_username')";
	$assists_contest = "submissions.contest_id in (select contest_id from contests_permissions where username = '$esc_username')";
	// the domains of the user, and the ones where they see everything
	$in_my_domain = "submissions.domain_id in (".domainIdsOfUserSql($esc_username, 'member').")";
	$assists_domain = "submissions.domain_id in (".domainIdsOfUserSql($esc_username, 'ta').")";
	// a homework keeps what is submitted to it to itself until it is over
	$maintains_homework = "submissions.homework_id in (select homework_id from homework_maintainers where username = '$esc_username')";
	$in_open_homework = "submissions.homework_id in (select id from homeworks where end_at > '".UOJTime::$time_now_str."')";
	return "$manages_problem or $assists_contest or $assists_domain or $maintains_homework or (submissions.is_hidden = false and (submissions.domain_id is null or $in_my_domain) and (submissions.contest_id is null or not $in_running_contest or submissions.submitter = '$esc_username') and (submissions.homework_id is null or not $in_open_homework or submissions.submitter = '$esc_username'))";
}
// a query for the ids of the domains in which a user has at least a role
function domainIdsOfUserSql($esc_username, $role) {
	$roles = array();
	foreach (domainMemberRoles() as $member_role => $name) {
		if (domainRoleRank($member_role) >= domainRoleRank($role)) {
			$roles[] = "'$member_role'";
		}
	}
	return "select id from domains where owner_username = '$esc_username' union select domain_id from domain_members where username = '$esc_username' and role in (".join(', ', $roles).")";
}
function visibleHacksCond($user) {
	if (can($user, 'submission.view_all')) {
		return '1';
	}
	// a hack of a problem of a domain is shown in the domain only
	$of_the_site = "problem_id in (select id from problems where owner_domain_id is null)";
	if ($user == null) {
		return "is_hidden = false and $of_the_site";
	}
	$esc_username = DB::escape($user['username']);
	$of_my_domain = "problem_id in (select id from problems where owner_domain_id in (".domainIdsOfUserSql($esc_username, 'member')."))";
	$i_teach = "problem_id in (select id from problems where owner_domain_id in (".domainIdsOfUserSql($esc_username, 'teacher')."))";
	return "$i_teach or problem_id in (select problem_id from problems_permissions where username = '$esc_username') or (is_hidden = false and ($of_the_site or $of_my_domain))";
}

// A problem of a contest is shown to the people who registered once the contest has started,
// and to everybody once it is over, even while the problem itself is still hidden.
function canViewContestProblem($user, $problem, $contest) {
	if (can($user, 'problem.view', $problem)) {
		return true;
	}
	if ($contest['cur_progress'] >= CONTEST_PENDING_FINAL_TEST) {
		return true;
	}
	if ($contest['cur_progress'] == CONTEST_NOT_STARTED) {
		return false;
	}
	return $user != null && permissionFacts()->hasRegistered($user['username'], $contest['id']);
}

// ---- changing roles

function grantRole($username, $role, $granted_by) {
	if (!isset(grantableRoles()[$role])) {
		return false;
	}
	return DB::insert("insert ignore into user_roles (username, role, granted_by, granted_at) values ('".DB::escape($username)."', '".DB::escape($role)."', '".DB::escape($granted_by)."', now())");
}
function revokeRole($username, $role) {
	return DB::delete("delete from user_roles where username = '".DB::escape($username)."' and role = '".DB::escape($role)."'");
}
// the site must never be left without somebody who can repair it
function isLastSystemAdmin($user) {
	return $user['usergroup'] == 'S' && DB::selectCount("select count(*) from user_info where usergroup = 'S'") <= 1;
}

// Applies one operation of the user form of the administrators, returns '' or why it is refused.
// The operations are 'banneduser', 'normaluser', 'superuser', 'grant:<role>' and 'revoke:<role>'.
function changeUserStanding($actor, $target, $op) {
	$may_manage_roles = can($actor, 'user.manage_roles');
	$target_is_admin = isSiteAdmin($target);
	$esc_username = DB::escape($target['username']);

	if ($op == 'banneduser' || $op == 'normaluser') {
		if (!can($actor, 'user.ban')) {
			return '没有权限';
		}
		// taking the administration away from somebody is a change of roles
		if ($target_is_admin && !$may_manage_roles) {
			return '只有系统管理员可以封禁管理员或修改其用户组';
		}
		if ($op == 'banneduser' && $target['username'] === $actor['username']) {
			return '不能封禁自己';
		}
		if (isLastSystemAdmin($target)) {
			return '不能取消最后一位系统管理员';
		}
		$usergroup = $op == 'banneduser' ? 'B' : 'U';
		$before = array('usergroup' => $target['usergroup'], 'roles' => permissionFacts()->grantedRoles($target['username']));
		DB::update("update user_info set usergroup = '$usergroup' where username = '$esc_username'");
		if ($op == 'banneduser') {
			// a banned user keeps nothing that would work again by accident later
			DB::delete("delete from user_roles where username = '$esc_username'");
			DB::update("update user_info set remember_token = '' where username = '$esc_username'");
		}
		auditLog('user.set_usergroup', 'user', $target['username'], $before, array('usergroup' => $usergroup), $actor);
		return '';
	}

	if (!$may_manage_roles) {
		return '只有系统管理员可以修改角色';
	}
	if ($op == 'superuser') {
		DB::update("update user_info set usergroup = 'S' where username = '$esc_username'");
		auditLog('user.set_usergroup', 'user', $target['username'], array('usergroup' => $target['usergroup']), array('usergroup' => 'S'), $actor);
		return '';
	}
	$parts = explode(':', $op, 2);
	if (count($parts) == 2 && isset(grantableRoles()[$parts[1]])) {
		if ($target['usergroup'] == 'B') {
			return '该用户已被封禁';
		}
		if ($parts[0] == 'grant') {
			grantRole($target['username'], $parts[1], $actor['username']);
			auditLog('user.grant_role', 'user', $target['username'], null, array('role' => $parts[1]), $actor);
			return '';
		} elseif ($parts[0] == 'revoke') {
			revokeRole($target['username'], $parts[1]);
			auditLog('user.revoke_role', 'user', $target['username'], array('role' => $parts[1]), null, $actor);
			return '';
		}
	}
	return '无效操作';
}
