<?php

require_once __DIR__ . '/../app/libs/uoj-utility-lib.php';
require_once __DIR__ . '/../app/libs/uoj-contest-lib.php';
require_once __DIR__ . '/../app/libs/uoj-domain-lib.php';
require_once __DIR__ . '/../app/libs/uoj-permission-lib.php';

// the facts of a small site, instead of the database
class FakePermissionFacts {
	public $problem_managers = array();
	public $contest_staff = array();
	public $roles = array();
	public $registered = array();
	public $accepted = array();
	public $problems = array();
	public $contests = array();
	public $problems_in_running_contests = array();
	public $domain_members = array();
	public $domains = array();

	public function managesProblem($username, $problem_id) {
		return in_array(array($username, $problem_id), $this->problem_managers);
	}
	public function domainMemberRole($username, $domain_id) {
		return isset($this->domain_members["$username/$domain_id"]) ? $this->domain_members["$username/$domain_id"] : null;
	}
	public function domain($domain_id) {
		return $domain_id ? $this->domains[$domain_id] : null;
	}
	public function grantedRoles($username) {
		return isset($this->roles[$username]) ? $this->roles[$username] : array();
	}
	public function contestRole($username, $contest_id) {
		return isset($this->contest_staff["$username/$contest_id"]) ? $this->contest_staff["$username/$contest_id"] : null;
	}
	public function hasRegistered($username, $contest_id) {
		return in_array(array($username, $contest_id), $this->registered);
	}
	public function hasAccepted($username, $problem_id) {
		return in_array(array($username, $problem_id), $this->accepted);
	}
	public function problemIsInRunningContest($problem_id) {
		return in_array($problem_id, $this->problems_in_running_contests);
	}
	public function problem($problem_id) {
		return $this->problems[$problem_id];
	}
	public function contest($contest_id) {
		return $contest_id ? $this->contests[$contest_id] : null;
	}
}

function fake_user($username, $usergroup = 'U') {
	return array('username' => $username, 'usergroup' => $usergroup);
}
function fake_contest($id, $progress, $extra_config = array()) {
	return array('id' => $id, 'cur_progress' => $progress, 'extra_config' => $extra_config);
}
function fake_submission($submitter, $problem_id, $contest_id = null, $is_hidden = 0) {
	return array('submitter' => $submitter, 'problem_id' => $problem_id, 'contest_id' => $contest_id, 'is_hidden' => $is_hidden);
}
// checks an ability for several users at once: 'name' => expected
function check_ability($ability, $resource, $expected, $what) {
	global $permission_test_users;
	foreach ($expected as $who => $allowed) {
		check_same($allowed, can($permission_test_users[$who], $ability, $resource), "$what: $ability for $who");
	}
}

$facts = new FakePermissionFacts();
permissionFacts($facts);

$permission_test_users = array(
	'nobody' => null,
	'root' => fake_user('root', 'S'),
	'ojadmin' => fake_user('ojadmin'),
	'teacher' => fake_user('teacher'),
	'owner' => fake_user('owner'),
	'setter' => fake_user('setter'),
	'helper' => fake_user('helper'),
	'alice' => fake_user('alice'),
	'bob' => fake_user('bob'),
);

// problem 1 is public, problem 2 is hidden, setter manages both
$facts->problems = array(
	1 => array('id' => 1, 'is_hidden' => 0, 'extra_config' => '{}'),
	2 => array('id' => 2, 'is_hidden' => 1, 'extra_config' => '{}'),
	3 => array('id' => 3, 'is_hidden' => 0, 'extra_config' => '{"view_content_type": "ALL_AFTER_AC", "view_all_details_type": "SELF", "view_details_type": "SELF"}'),
);
$facts->problem_managers = array(array('setter', 1), array('setter', 2));
$facts->roles = array('ojadmin' => array('oj_admin'), 'teacher' => array('teacher'));
// contest 10 is running, contest 11 is over, owner runs them and helper assists
$facts->contests = array(
	10 => fake_contest(10, CONTEST_IN_PROGRESS),
	11 => fake_contest(11, CONTEST_FINISHED),
	12 => fake_contest(12, CONTEST_IN_PROGRESS, array('contest_type' => 'IOI')),
	13 => fake_contest(13, CONTEST_NOT_STARTED),
);
$facts->contest_staff = array('helper/10' => 'assistant', 'helper/11' => 'assistant', 'helper/12' => 'assistant', 'owner/10' => 'owner', 'owner/11' => 'owner');
$facts->registered = array(array('alice', 10), array('alice', 13));
$facts->accepted = array(array('bob', 3));

// ---- the roles
check_same(array('system_admin'), userRoles($permission_test_users['root']), 'a user of the group S is a system administrator');
check_same(array('oj_admin'), userRoles($permission_test_users['ojadmin']), 'the roles of an OJ administrator');
check_same(array(), userRoles($permission_test_users['alice']), 'a student has no role');
check_same(array(), userRoles(null), 'a visitor has no role');

// ---- the site
// what could break the site or run programs on the judgers is left to the system administrators
foreach (array('user.manage_roles', 'user.rename', 'judger.manage', 'problem.approve_judger', 'problem.edit_raw_config') as $ability) {
	check_ability($ability, null, array('nobody' => false, 'root' => true, 'ojadmin' => false, 'teacher' => false, 'owner' => false, 'setter' => false, 'alice' => false), 'site');
}
foreach (array('site.manage', 'user.ban', 'user.view_private', 'contest.rate', 'submission.view_all', 'submission.delete', 'hack.delete') as $ability) {
	check_ability($ability, null, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'owner' => false, 'setter' => false, 'helper' => false, 'alice' => false), 'site');
}
foreach (array('problem.create', 'contest.create') as $ability) {
	check_ability($ability, null, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => true, 'owner' => false, 'setter' => false, 'alice' => false), 'site');
}

// ---- problems
check_ability('problem.view', $facts->problems[1], array('nobody' => true, 'root' => true, 'setter' => true, 'alice' => true), 'a public problem');
check_ability('problem.view', $facts->problems[2], array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false), 'a hidden problem');
check_ability('problem.manage', $facts->problems[1], array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'setter' => true, 'helper' => false, 'alice' => false), 'a public problem');
check_ability('problem.manage', $facts->problems[3], array('root' => true, 'setter' => false), 'a problem of somebody else');

// a hidden problem of a contest
check_same(false, canViewContestProblem($permission_test_users['alice'], $facts->problems[2], $facts->contests[13]), 'before the contest');
check_same(true, canViewContestProblem($permission_test_users['alice'], $facts->problems[2], $facts->contests[10]), 'registered, during the contest');
check_same(false, canViewContestProblem($permission_test_users['bob'], $facts->problems[2], $facts->contests[10]), 'not registered, during the contest');
check_same(false, canViewContestProblem(null, $facts->problems[2], $facts->contests[10]), 'not logged in, during the contest');
check_same(true, canViewContestProblem(null, $facts->problems[2], $facts->contests[11]), 'after the contest');
check_same(true, canViewContestProblem($permission_test_users['setter'], $facts->problems[2], $facts->contests[13]), 'the setter, before the contest');

// ---- contests
foreach (array(10, 11) as $contest_id) {
	check_ability('contest.assist', $facts->contests[$contest_id], array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'owner' => true, 'setter' => false, 'helper' => true, 'alice' => false), "contest $contest_id");
	// whoever runs a contest does not need a role of the whole site, and an assistant does not run it
	check_ability('contest.manage', $facts->contests[$contest_id], array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'owner' => true, 'setter' => false, 'helper' => false, 'alice' => false), "contest $contest_id");
	check_ability('contest.rate', $facts->contests[$contest_id], array('nobody' => false, 'root' => true, 'ojadmin' => true, 'owner' => false, 'helper' => false), "contest $contest_id");
}
check_ability('contest.manage', $facts->contests[12], array('root' => true, 'owner' => false, 'helper' => false), 'a contest of somebody else');

// ---- blogs
check_ability('blog.manage', 'alice', array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'alice' => true, 'bob' => false), 'the blog of alice');

// ---- submissions outside of contests: everybody reads everything, solved or not
$open = fake_submission('alice', 1);
foreach (array('submission.view', 'submission.view_source', 'submission.view_details', 'submission.view_test_details') as $ability) {
	check_ability($ability, $open, array('nobody' => true, 'root' => true, 'teacher' => true, 'setter' => true, 'alice' => true, 'bob' => true), 'a submission to a public problem');
}
check_ability('submission.view_final_details', $open, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'setter' => true, 'alice' => false), 'a submission to a public problem');
check_ability('submission.rejudge', $open, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'setter' => true, 'alice' => false, 'bob' => false), 'a submission to a public problem');
check_ability('submission.hack', $open, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => true, 'bob' => true), 'a submission to a public problem');

$hidden = fake_submission('alice', 2, null, 1);
check_ability('submission.view', $hidden, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'setter' => true, 'alice' => false, 'bob' => false), 'a submission to a hidden problem');
check_ability('submission.view_source', $hidden, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false, 'bob' => false), 'a submission to a hidden problem');

// the problem decides who reads what: the source after solving it, the details only the owner
$restricted = fake_submission('alice', 3);
check_ability('submission.view_source', $restricted, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'setter' => false, 'alice' => true, 'bob' => true, 'helper' => false), 'ALL_AFTER_AC');
check_ability('submission.view_details', $restricted, array('nobody' => false, 'root' => true, 'alice' => true, 'bob' => false), 'SELF');
check_ability('submission.view_test_details', $restricted, array('nobody' => false, 'root' => true, 'alice' => true, 'bob' => false), 'SELF');
check_ability('submission.hack', $restricted, array('nobody' => false, 'alice' => true, 'bob' => true, 'helper' => false), 'hacking needs the source');

// ---- submissions of a contest that is running: closed to everybody but the owner and the staff
$facts->problems_in_running_contests = array(1);
$running = fake_submission('alice', 1, 10);
foreach (array('submission.view', 'submission.view_source', 'submission.view_details') as $ability) {
	check_ability($ability, $running, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'owner' => true, 'setter' => true, 'helper' => true, 'alice' => true, 'bob' => false), 'during the contest');
}
check_ability('submission.view_final_details', $running, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'owner' => true, 'setter' => true, 'helper' => true, 'alice' => false, 'bob' => false), 'during the contest');
check_ability('submission.view_test_details', $running, array('root' => true, 'helper' => true, 'alice' => true), 'during the contest');
check_ability('submission.view_test_details', fake_submission('alice', 1, 12), array('root' => true, 'helper' => true, 'alice' => false, 'bob' => false), 'during an IOI contest');
$facts->contests[10]['extra_config']['problem_1'] = 'no-details';
check_ability('submission.view_test_details', $running, array('root' => true, 'helper' => true, 'alice' => false), 'a problem without details, during the contest');
unset($facts->contests[10]['extra_config']['problem_1']);
check_ability('submission.hack', $running, array('nobody' => false, 'root' => true, 'setter' => true, 'helper' => true, 'alice' => false, 'bob' => false), 'during the contest');

// what was submitted to the problem outside of the contest is still listed, but is closed as well
foreach (array($open, fake_submission('alice', 1, 11)) as $earlier) {
	check_ability('submission.view', $earlier, array('nobody' => true, 'alice' => true, 'bob' => true), 'an earlier submission, during the contest');
	foreach (array('submission.view_source', 'submission.view_details') as $ability) {
		check_ability($ability, $earlier, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => true, 'bob' => false), 'an earlier submission, during the contest');
	}
	check_ability('submission.hack', $earlier, array('nobody' => false, 'setter' => true, 'alice' => false, 'bob' => false), 'an earlier submission, during the contest');
}
// the staff of the contest is staff for what was submitted in it, not for the whole problem
check_ability('submission.view_source', $open, array('helper' => false, 'owner' => false), 'an earlier submission, during the contest');
// a problem that is in no running contest is not affected
check_ability('submission.view_source', fake_submission('alice', 3), array('bob' => true), 'another problem, during the contest');
$facts->problems_in_running_contests = array();

// ---- and once the contest is over, everything opens up again
$over = fake_submission('alice', 1, 11);
foreach (array('submission.view', 'submission.view_source', 'submission.view_details', 'submission.view_test_details', ) as $ability) {
	check_ability($ability, $over, array('nobody' => true, 'helper' => true, 'alice' => true, 'bob' => true), 'after the contest');
}
check_ability('submission.hack', $over, array('nobody' => false, 'alice' => true, 'bob' => true), 'after the contest');

// ---- hacks
$hack = array('hacker' => 'bob', 'problem_id' => 1, 'is_hidden' => 0, 'submission' => $open);
foreach (array('hack.view', 'hack.view_source', 'hack.view_details', 'hack.view_test_details') as $ability) {
	check_ability($ability, $hack, array('nobody' => true, 'alice' => true, 'bob' => true), 'a hack');
}
check_ability('hack.view_final_details', $hack, array('nobody' => false, 'root' => true, 'setter' => true, 'bob' => false), 'a hack');
check_ability('hack.view', array('is_hidden' => 1, 'problem_id' => 2), array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false), 'a hack of a hidden problem');
// while a contest with the problem runs, a hack shows the source to nobody new
$facts->problems_in_running_contests = array(1);
check_ability('hack.view_source', $hack, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => true, 'bob' => false), 'a hack during the contest');
check_ability('hack.view_details', $hack, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => true, 'bob' => true, 'helper' => false), 'a hack during the contest');
$facts->problems_in_running_contests = array();

// ---- domains
function fake_domain($id, $owner, $visibility = 'private', $join_method = 'none', $archived_at = null) {
	return array('id' => $id, 'owner_username' => $owner, 'visibility' => $visibility, 'join_method' => $join_method, 'archived_at' => $archived_at);
}
$permission_test_users += array(
	'lead' => fake_user('lead'),
	'co_admin' => fake_user('co_admin'),
	'lecturer' => fake_user('lecturer'),
	'tutor' => fake_user('tutor'),
	'pupil' => fake_user('pupil'),
	'creator' => fake_user('creator'),
);
$facts->roles['creator'] = array('domain_creator');
$facts->domains = array(
	1 => fake_domain(1, 'lead'),
	2 => fake_domain(2, 'setter', 'public', 'all'),
	3 => fake_domain(3, 'lead', 'unlisted', 'code'),
	4 => fake_domain(4, 'lead', 'private', 'none', '2026-01-01 00:00:00'),
);
$facts->domain_members = array('co_admin/1' => 'admin', 'lecturer/1' => 'teacher', 'tutor/1' => 'ta', 'pupil/1' => 'member', 'lecturer/4' => 'teacher', 'pupil/4' => 'member');

check_ability('domain.create', null, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => true, 'creator' => true, 'alice' => false, 'lead' => false), 'site');
check_ability('domain.manage_all', null, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'creator' => false, 'lead' => false), 'site');
foreach (array('problem.create', 'contest.create') as $ability) {
	check_ability($ability, null, array('creator' => false), 'whoever may create domains may create nothing else');
}

// the owner is the user the domain names, and has no role as a member
check_same('owner', permissionDomainRole($permission_test_users['lead'], $facts->domains[1]), 'the owner of a domain');
check_same('ta', permissionDomainRole($permission_test_users['tutor'], $facts->domains[1]), 'a member of a domain');
check_same(null, permissionDomainRole($permission_test_users['alice'], $facts->domains[1]), 'a stranger');
check_same(null, permissionDomainRole($permission_test_users['root'], $facts->domains[1]), 'an administrator of the site is no member');
check_same(null, permissionDomainRole(null, $facts->domains[1]), 'a visitor');

$private = $facts->domains[1];
$everybody = array('nobody' => false, 'alice' => false, 'teacher' => false, 'creator' => false);
check_ability('domain.view', $private, $everybody + array('root' => true, 'ojadmin' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => true), 'a private domain');
check_ability('domain.view_landing', $private, $everybody + array('root' => true, 'lead' => true, 'pupil' => true), 'a private domain');
check_ability('domain.assist', $private, $everybody + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => false), 'a private domain');
check_ability('domain.teach', $private, $everybody + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false), 'a private domain');
foreach (array('domain.manage', 'member.manage') as $ability) {
	check_ability($ability, $private, $everybody + array('root' => true, 'ojadmin' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => false, 'tutor' => false, 'pupil' => false), 'a private domain');
}
check_ability('domain.own', $private, $everybody + array('root' => true, 'ojadmin' => true, 'lead' => true, 'co_admin' => false, 'lecturer' => false, 'pupil' => false), 'a private domain');
check_ability('domain.join', $private, array('nobody' => false, 'alice' => false, 'pupil' => false), 'a private domain');

// what a role is worth ends at the border of its domain
check_ability('domain.view', $facts->domains[2], array('lead' => false, 'co_admin' => false, 'pupil' => false, 'setter' => true, 'root' => true), 'another domain');
check_ability('domain.manage', $facts->domains[2], array('lead' => false, 'co_admin' => false, 'setter' => true), 'another domain');

// everybody may look at a domain that is not private, and join it if it says so
check_ability('domain.view_landing', $facts->domains[2], array('nobody' => true, 'alice' => true, 'pupil' => true), 'a public domain');
check_ability('domain.view', $facts->domains[2], array('nobody' => false, 'alice' => false), 'a public domain');
check_ability('domain.join', $facts->domains[2], array('nobody' => false, 'alice' => true, 'setter' => false), 'a public domain that everybody may join');
check_ability('domain.view_landing', $facts->domains[3], array('nobody' => true, 'alice' => true), 'an unlisted domain');
check_ability('domain.join', $facts->domains[3], array('alice' => false), 'a domain that is joined by invitation');

// an archived domain can be read, and nothing in it changed, until its owner brings it back
$archived = $facts->domains[4];
check_ability('domain.view', $archived, array('lead' => true, 'lecturer' => true, 'pupil' => true, 'alice' => false), 'an archived domain');
check_ability('domain.assist', $archived, array('lead' => true, 'lecturer' => true, 'pupil' => false), 'an archived domain');
foreach (array('domain.teach', 'domain.manage', 'member.manage') as $ability) {
	check_ability($ability, $archived, array('root' => false, 'lead' => false, 'lecturer' => false), 'an archived domain');
}
check_ability('domain.own', $archived, array('root' => true, 'lead' => true, 'lecturer' => false), 'an archived domain');

// ---- who may change what a member of a domain is
$change = function($actor_role, $target_role, $new_role, $is_site_manager = false) {
	return domainMemberChangeRefusedReason($actor_role, $is_site_manager, $target_role, $new_role) === '';
};
foreach (array('admin', 'teacher', 'ta', 'member') as $role) {
	check_same(true, $change('owner', null, $role), "the owner adds a member as $role");
	check_same(true, $change('owner', $role, null), "the owner removes a member who is $role");
	check_same(true, $change(null, 'member', $role, true), "an administrator of the site makes a member $role");
	check_same(false, $change('owner', 'owner', $role), "nobody makes the owner $role");
	check_same(false, $change('teacher', null, $role), "a teacher adds nobody as $role");
	check_same(false, $change('member', 'member', $role), "a member changes nobody to $role");
	check_same(false, $change(null, null, $role), "a stranger adds nobody as $role");
}
foreach (array('teacher', 'ta', 'member') as $role) {
	check_same(true, $change('admin', null, $role), "an administrator of the domain adds a member as $role");
	check_same(true, $change('admin', $role, null), "an administrator of the domain removes a member who is $role");
	check_same(false, $change('admin', 'admin', $role), "an administrator of the domain demotes no other administrator to $role");
	check_same(false, $change('admin', $role, 'admin'), "an administrator of the domain promotes no $role to administrator");
}
check_same(false, $change('admin', 'admin', null), 'an administrator of the domain removes no other administrator');
check_same(false, $change('admin', null, 'admin'), 'an administrator of the domain adds no administrator');
check_same(false, $change('owner', 'owner', null), 'the owner can not be removed');
check_same(false, $change(null, 'owner', null, true), 'not even by an administrator of the site');
check_same(false, $change('owner', 'member', 'owner'), 'owner is not a role a member can be given');
check_same(false, $change('owner', 'member', 'superuser'), 'neither is a role that does not exist');

// ---- the settings of a domain
$settings = array('name' => '数据结构 1 班', 'slug' => 'ds-2026-a', 'description' => '', 'type' => 'course', 'visibility' => 'private', 'join_method' => 'none');
check_same('', domainSettingsError($settings), 'the settings of a course');
check_same('', domainSettingsError(array('visibility' => 'public', 'join_method' => 'all') + $settings), 'a public domain everybody may join');
check_same('', domainSettingsError(array('join_method' => 'code') + $settings), 'a private domain that is joined by invitation');
$wrong = array(
	'a private domain everybody may join' => array('join_method' => 'all'),
	'no name' => array('name' => '  '),
	'a name that is too long' => array('name' => str_repeat('长', 101)),
	'an unknown type' => array('type' => 'guild'),
	'an unknown visibility' => array('visibility' => 'secret'),
	'an unknown way to join' => array('join_method' => 'bribe'),
	'a description that is too long' => array('description' => str_repeat('x', 2001)),
);
foreach ($wrong as $what => $changed) {
	check_same(true, domainSettingsError($changed + $settings) !== '', "$what is refused");
}
foreach (array('ds', 'ds-2026-a', 'a1', '2026', str_repeat('a', 31)) as $slug) {
	check_same(true, validateDomainSlug($slug), "the address $slug is accepted");
}
foreach (array('', 'a', 'DS-2026', 'ds_2026', '-ds', 'ds-', 'ds 2026', 'ds/2026', '数据结构', str_repeat('a', 32), array('ds')) as $slug) {
	check_same(false, validateDomainSlug($slug), 'the address ' . json_encode($slug) . ' is refused');
}

// ---- an ability that does not exist is refused
check_same(false, @can($permission_test_users['root'], 'problem.mange', $facts->problems[1]), 'a misspelled ability');
