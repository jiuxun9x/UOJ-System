<?php

require_once __DIR__ . '/../app/libs/uoj-utility-lib.php';
require_once __DIR__ . '/../app/libs/uoj-contest-lib.php';
require_once __DIR__ . '/../app/libs/uoj-domain-lib.php';
require_once __DIR__ . '/../app/libs/uoj-homework-lib.php';
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
	public $site_settings = array();
	public $now = 0;
	public $homeworks = array();
	public $homework_participants = array();
	public $homework_maintainers = array();
	public $problems_in_running_homeworks = array();
	public $running_homework_sources = array();

	public function siteSetting($name) {
		return isset($this->site_settings[$name]) ? $this->site_settings[$name] : null;
	}
	public function managesProblem($username, $problem_id) {
		return in_array(array($username, $problem_id), $this->problem_managers);
	}
	public function now() {
		return $this->now;
	}
	public function homework($homework_id) {
		return $homework_id ? $this->homeworks[$homework_id] : null;
	}
	public function homeworkParticipantStatus($username, $homework_id) {
		return isset($this->homework_participants["$username/$homework_id"]) ? $this->homework_participants["$username/$homework_id"] : null;
	}
	public function isHomeworkMaintainer($username, $homework_id) {
		return in_array("$username/$homework_id", $this->homework_maintainers);
	}
	public function problemIsInRunningHomework($problem_id) {
		return in_array($problem_id, $this->problems_in_running_homeworks);
	}
	public function userRunsHomeworkFromSource($username, $problem_id) {
		return in_array("$username/$problem_id", $this->running_homework_sources);
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
// A student number may look like a number, and to PHP '0123' == '123' and '21E01' == '210'.
// They are four users.
foreach (array('0123', '123', '21E01', '210') as $number) {
	$permission_test_users[$number] = fake_user($number);
}
foreach (array('submission.view_details', 'submission.view_test_details') as $ability) {
	check_ability($ability, fake_submission('0123', 3), array('0123' => true, '123' => false), 'SELF, of a name with a zero in front');
	check_ability($ability, fake_submission('123', 3), array('123' => true, '0123' => false), 'SELF, of a name that is a number');
	check_ability($ability, fake_submission('21E01', 3), array('21E01' => true, '210' => false), 'SELF, of a name that reads as a power of ten');
}
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
function fake_domain($id, $owner, $archived_at = null) {
	return array('id' => $id, 'owner_username' => $owner, 'archived_at' => $archived_at);
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
	2 => fake_domain(2, 'setter'),
	3 => fake_domain(3, 'lead'),
	4 => fake_domain(4, 'lead', '2026-01-01 00:00:00'),
);
$facts->domain_members = array('co_admin/1' => 'admin', 'lecturer/1' => 'teacher', 'tutor/1' => 'ta', 'pupil/1' => 'member', 'lecturer/4' => 'teacher', 'pupil/4' => 'member');

check_ability('domain.create', null, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => true, 'creator' => true, 'alice' => false, 'lead' => false), 'site');
check_ability('domain.manage_all', null, array('nobody' => false, 'root' => true, 'ojadmin' => true, 'teacher' => false, 'creator' => false, 'lead' => false), 'site');
foreach (array('problem.create', 'contest.create') as $ability) {
	check_ability($ability, null, array('creator' => false), 'whoever may create domains may create nothing else');
}
// The system administrators may let everybody create domains. It is a switch of theirs alone.
check_ability('site.manage_settings', null, array('nobody' => false, 'root' => true, 'ojadmin' => false, 'teacher' => false, 'lead' => false), 'site');
check_same(false, siteSettingIsOn('domain.open_creation'), 'nobody has touched the switch');
$facts->site_settings['domain.open_creation'] = '1';
check_same(true, siteSettingIsOn('domain.open_creation'), 'the switch is on');
$permission_test_users['banned'] = fake_user('banned', 'B');
check_ability('domain.create', null, array('nobody' => false, 'banned' => false, 'alice' => true, 'lead' => true, 'teacher' => true, 'creator' => true, 'root' => true), 'a site where everybody may create domains');
foreach (array('problem.create', 'contest.create', 'domain.manage_all') as $ability) {
	check_ability($ability, null, array('alice' => false), 'the switch opens nothing else');
}
check_ability('domain.view', $facts->domains[1], array('alice' => false), 'nor the domains of other people');
$facts->site_settings['domain.open_creation'] = '0';
check_ability('domain.create', null, array('alice' => false, 'lead' => false, 'teacher' => true, 'creator' => true), 'a site where the switch was turned off again');
$facts->site_settings = array();
check_same(false, siteSettingIsOn('no.such.setting'), 'a setting that does not exist');

// the owner is the user the domain names, and has no role as a member
check_same('owner', permissionDomainRole($permission_test_users['lead'], $facts->domains[1]), 'the owner of a domain');
check_same('ta', permissionDomainRole($permission_test_users['tutor'], $facts->domains[1]), 'a member of a domain');
check_same(null, permissionDomainRole($permission_test_users['alice'], $facts->domains[1]), 'a stranger');
check_same(null, permissionDomainRole($permission_test_users['root'], $facts->domains[1]), 'an administrator of the site is no member');
check_same(null, permissionDomainRole(null, $facts->domains[1]), 'a visitor');

// A domain is seen by the administrators of the site, by its owner and by its members, and
// by nobody else: there is no domain that shows itself to the people outside.
$private = $facts->domains[1];
$everybody = array('nobody' => false, 'alice' => false, 'teacher' => false, 'creator' => false);
check_ability('domain.view', $private, $everybody + array('root' => true, 'ojadmin' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => true), 'a private domain');
check_ability('domain.assist', $private, $everybody + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => false), 'a private domain');
check_ability('domain.teach', $private, $everybody + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false), 'a private domain');
foreach (array('domain.manage', 'member.manage') as $ability) {
	check_ability($ability, $private, $everybody + array('root' => true, 'ojadmin' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => false, 'tutor' => false, 'pupil' => false), 'a private domain');
}
check_ability('domain.own', $private, $everybody + array('root' => true, 'ojadmin' => true, 'lead' => true, 'co_admin' => false, 'lecturer' => false, 'pupil' => false), 'a private domain');

// what a role is worth ends at the border of its domain
check_ability('domain.view', $facts->domains[2], array('nobody' => false, 'alice' => false, 'lead' => false, 'co_admin' => false, 'pupil' => false, 'setter' => true, 'root' => true, 'ojadmin' => true), 'another domain');
foreach (array('domain.manage', 'domain.teach', 'domain.own') as $ability) {
	check_ability($ability, $facts->domains[2], array('lead' => false, 'co_admin' => false, 'pupil' => false, 'setter' => true, 'root' => true, 'ojadmin' => true), 'another domain');
}

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
$settings = array('name' => '数据结构 1 班', 'slug' => 'ds-2026-a', 'description' => '', 'type' => 'course');
check_same('', domainSettingsError($settings), 'the settings of a course');
$wrong = array(
	'no name' => array('name' => '  '),
	'a name that is too long' => array('name' => str_repeat('长', 101)),
	'an unknown type' => array('type' => 'guild'),
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

// ---- what belongs to a domain
// problem 20 is a visible problem of domain 1, problem 21 a hidden one, problem 22 belongs to domain 2
$facts->problems += array(
	20 => array('id' => 20, 'is_hidden' => 0, 'extra_config' => '{}', 'owner_domain_id' => 1),
	21 => array('id' => 21, 'is_hidden' => 1, 'extra_config' => '{}', 'owner_domain_id' => 1),
	22 => array('id' => 22, 'is_hidden' => 0, 'extra_config' => '{}', 'owner_domain_id' => 2),
	23 => array('id' => 23, 'is_hidden' => 0, 'extra_config' => '{}', 'owner_domain_id' => 4),
);
$outsiders = array('nobody' => false, 'alice' => false, 'teacher' => false, 'creator' => false);
check_ability('problem.view', $facts->problems[20], $outsiders + array('root' => true, 'ojadmin' => true, 'lead' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => true), 'a problem of a domain');
check_ability('problem.view', $facts->problems[21], $outsiders + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false), 'a hidden problem of a domain');
check_ability('problem.manage', $facts->problems[20], $outsiders + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false, 'setter' => false), 'a problem of a domain');
check_ability('problem.view', $facts->problems[22], array('lead' => false, 'lecturer' => false, 'pupil' => false, 'setter' => true), 'a problem of another domain');
check_ability('problem.manage', $facts->problems[22], array('lead' => false, 'lecturer' => false, 'setter' => true), 'a problem of another domain');
// in an archived domain the problems can be read, and no longer changed
check_ability('problem.view', $facts->problems[23], array('lecturer' => true, 'pupil' => true, 'alice' => false), 'a problem of an archived domain');
check_ability('problem.manage', $facts->problems[23], array('lead' => false, 'lecturer' => false, 'root' => true), 'a problem of an archived domain');

// a copy is taken of a problem of the site one can see, or of a problem one teaches
check_ability('problem.copy', $facts->problems[1], array('nobody' => false, 'alice' => true, 'lecturer' => true), 'a public problem');
check_ability('problem.copy', $facts->problems[2], array('alice' => false, 'lecturer' => false, 'setter' => true, 'root' => true), 'a hidden problem');
check_ability('problem.copy', $facts->problems[20], array('nobody' => false, 'lead' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false, 'alice' => false, 'setter' => false), 'a problem of a domain');

// what is submitted in a domain stays in the domain
$in_domain = array('domain_id' => 1) + fake_submission('pupil', 20);
check_ability('submission.view', $in_domain, $outsiders + array('root' => true, 'lead' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => true, 'setter' => false), 'a submission in a domain');
check_ability('submission.view_source', $in_domain, $outsiders + array('lead' => true, 'tutor' => true, 'pupil' => true, 'co_admin' => true), 'a submission in a domain');
// the people who assist in a domain see all of every submission there, the students what the problem lets them
$facts->problems[24] = array('id' => 24, 'is_hidden' => 0, 'extra_config' => '{"view_content_type": "SELF", "view_all_details_type": "SELF", "view_details_type": "SELF"}', 'owner_domain_id' => 1);
$own_only = array('domain_id' => 1) + fake_submission('pupil', 24);
$facts->domain_members['classmate/1'] = 'member';
$permission_test_users['classmate'] = fake_user('classmate');
foreach (array('submission.view_source', 'submission.view_details', 'submission.view_final_details') as $ability) {
	check_ability($ability, $own_only, array('lead' => true, 'lecturer' => true, 'tutor' => true, 'classmate' => false, 'alice' => false), 'a submission in a domain to a problem that shows nothing');
}
check_ability('submission.view', $own_only, array('classmate' => true, 'alice' => false), 'a submission in a domain to a problem that shows nothing');
check_ability('submission.rejudge', $in_domain, array('lead' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false, 'alice' => false), 'a submission in a domain');
check_ability('hack.view', array('is_hidden' => 0, 'problem_id' => 20), $outsiders + array('pupil' => true, 'lead' => true, 'root' => true), 'a hack in a domain');

// a contest of a domain exists for its members, and is run by the people who teach there
$domain_contest = array('domain_id' => 1) + fake_contest(30, CONTEST_IN_PROGRESS);
check_ability('contest.view', $domain_contest, $outsiders + array('root' => true, 'lead' => true, 'tutor' => true, 'pupil' => true, 'setter' => false), 'a contest of a domain');
check_ability('contest.view', $facts->contests[10], array('nobody' => true, 'alice' => true, 'pupil' => true), 'a contest of the site');
check_ability('contest.manage', $domain_contest, $outsiders + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false, 'owner' => false), 'a contest of a domain');
check_ability('contest.assist', $domain_contest, $outsiders + array('root' => true, 'lead' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => false), 'a contest of a domain');
check_ability('contest.rate', $domain_contest, array('lead' => false, 'lecturer' => false, 'root' => true), 'a contest of a domain');
// a contest or a training of a domain may use the problems of the domain and the public ones of the site
check_ability('problem.use', $facts->problems[1], array('nobody' => true, 'alice' => true, 'lecturer' => true), 'a public problem');
check_ability('problem.use', $facts->problems[2], array('alice' => false, 'lecturer' => false, 'setter' => true), 'a hidden problem');
check_ability('problem.use', $facts->problems[21], array('lecturer' => true, 'tutor' => false, 'pupil' => false, 'alice' => false, 'setter' => false), 'a hidden problem of a domain');
check_ability('problem.use', $facts->problems[22], array('lecturer' => false, 'setter' => true), 'a problem of another domain');

// ---- homework
$facts->now = strtotime('2026-10-07 12:00:00');
$fake_homework = function($id, $status, $begin_at, $end_at, $claim_end_at = null) {
	return array('id' => $id, 'domain_id' => 1, 'status' => $status, 'begin_at' => $begin_at, 'end_at' => $end_at, 'claim_end_at' => $claim_end_at, 'penalty_since' => null);
};
$facts->homeworks = array(
	1 => $fake_homework(1, 'published', '2026-10-05 08:00:00', '2026-10-12 00:00:00'),
	2 => $fake_homework(2, 'published', '2026-10-10 08:00:00', '2026-10-20 00:00:00'),
	3 => $fake_homework(3, 'draft', '2026-10-05 08:00:00', '2026-10-12 00:00:00'),
	4 => $fake_homework(4, 'published', '2026-09-01 08:00:00', '2026-09-10 00:00:00'),
	5 => $fake_homework(5, 'published', '2026-10-05 08:00:00', '2026-10-12 00:00:00', '2026-10-06 00:00:00'),
);
$facts->domain_members['mate'] = 'member';
$facts->domain_members['mate/1'] = 'member';
$permission_test_users['mate'] = fake_user('mate');
$facts->homework_participants = array('pupil/1' => 'active', 'classmate/1' => 'active', 'pupil/2' => 'active', 'pupil/4' => 'active', 'mate/4' => 'active', 'mate/1' => 'withdrawn');
// classmate is a student who was asked to look after homework 1; alice was too, but is no member of the domain
$facts->homework_maintainers = array('classmate/1', 'alice/1');

$running = $facts->homeworks[1];
check_ability('homework.manage', $running, $outsiders + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false, 'classmate' => true, 'mate' => false), 'a homework');
check_ability('homework.view_scores', $running, $outsiders + array('root' => true, 'lead' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => false, 'classmate' => true, 'mate' => false), 'a homework');
check_ability('homework.manage', $facts->homeworks[2], array('classmate' => false, 'lecturer' => true), 'another homework than the one somebody looks after');
check_ability('homework.view', $running, $outsiders + array('pupil' => true, 'mate' => true, 'tutor' => true), 'a published homework');
check_ability('homework.view', $facts->homeworks[3], $outsiders + array('lead' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => false, 'mate' => false), 'a draft');

// students claim a homework to take part, until claiming ends
check_ability('homework.claim', $facts->homeworks[2], $outsiders + array('mate' => true, 'classmate' => true, 'pupil' => false, 'tutor' => false, 'lecturer' => false, 'lead' => false, 'root' => false), 'a homework that has not begun');
check_ability('homework.claim', $running, array('mate' => true, 'pupil' => false), 'a homework that is running: somebody who stepped back may claim it again');
check_ability('homework.claim', $facts->homeworks[3], array('mate' => false), 'a draft');
check_ability('homework.claim', $facts->homeworks[4], array('classmate' => false), 'a homework that is over');
check_ability('homework.claim', $facts->homeworks[5], array('mate' => false, 'classmate' => false), 'a homework whose claiming has ended');

// its problems open to whoever claimed it when it begins, and stay open afterwards
check_ability('homework.solve', $running, $outsiders + array('pupil' => true, 'classmate' => true, 'mate' => false, 'tutor' => true, 'lecturer' => true), 'a homework that is running');
check_ability('homework.solve', $facts->homeworks[2], array('pupil' => false, 'lecturer' => true, 'tutor' => true), 'a homework that has not begun');
check_ability('homework.solve', $facts->homeworks[4], array('pupil' => true, 'mate' => true, 'classmate' => false), 'a homework that is over');
check_ability('homework.solve', $facts->homeworks[3], array('pupil' => false, 'lecturer' => true), 'a draft');

// what is submitted to a homework is nobody else's business until the homework is over
$handed_in = array('domain_id' => 1, 'homework_id' => 1) + fake_submission('pupil', 21);
foreach (array('submission.view', 'submission.view_source', 'submission.view_details') as $ability) {
	check_ability($ability, $handed_in, $outsiders + array('pupil' => true, 'mate' => false, 'classmate' => true, 'tutor' => true, 'lecturer' => true, 'lead' => true, 'root' => true), 'a submission to a running homework');
}
check_ability('submission.view_final_details', $handed_in, array('classmate' => true, 'tutor' => true, 'pupil' => false, 'mate' => false), 'a submission to a running homework');
check_ability('submission.hack', $handed_in, array('pupil' => false, 'mate' => false, 'lecturer' => true), 'a submission to a running homework');
$after_the_end = array('domain_id' => 1, 'homework_id' => 4) + fake_submission('pupil', 20);
foreach (array('submission.view', 'submission.view_source', 'submission.view_details') as $ability) {
	check_ability($ability, $after_the_end, $outsiders + array('pupil' => true, 'mate' => true, 'tutor' => true), 'a submission to a homework that is over');
}
// and everything else that was ever submitted to one of its problems is closed with it
$facts->problems_in_running_homeworks = array(20);
$earlier_in_domain = array('domain_id' => 1) + fake_submission('pupil', 20);
check_ability('submission.view', $earlier_in_domain, array('mate' => true, 'alice' => false), 'an earlier submission to a problem of a running homework');
check_ability('submission.view_source', $earlier_in_domain, array('mate' => false, 'pupil' => true, 'tutor' => true, 'lecturer' => true), 'an earlier submission to a problem of a running homework');
check_ability('submission.hack', $earlier_in_domain, array('mate' => false, 'pupil' => false, 'lecturer' => true), 'an earlier submission to a problem of a running homework');
$facts->problems_in_running_homeworks = array();
check_ability('submission.view_source', $earlier_in_domain, array('mate' => true), 'the same submission when no homework runs');
// the problem of the site a homework copied stays open to the site, but not to whoever takes part
$facts->running_homework_sources = array('pupil/1');
check_ability('submission.view_source', $open, array('pupil' => false, 'mate' => true, 'bob' => true, 'nobody' => true, 'alice' => true), 'a submission to the public problem a running homework was copied from');
check_ability('submission.view_details', $open, array('pupil' => false, 'bob' => true), 'a submission to the public problem a running homework was copied from');
check_ability('submission.view', $open, array('pupil' => true), 'a submission to the public problem a running homework was copied from');
$facts->running_homework_sources = array();

// ---- trainings: the people of the domain see them once they are published
$published_training = array('id' => 1, 'domain_id' => 1, 'status' => 'published');
$draft_training = array('id' => 2, 'domain_id' => 1, 'status' => 'draft');
$strangers = array('nobody' => false, 'alice' => false, 'teacher' => false);
check_ability('training.view', $published_training, $strangers + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => true), 'a published training');
check_ability('training.view', $draft_training, $strangers + array('root' => true, 'lead' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false), 'a draft of a training');
check_ability('training.manage', $published_training, $strangers + array('root' => true, 'lead' => true, 'co_admin' => true, 'lecturer' => true, 'tutor' => false, 'pupil' => false), 'a training');
check_ability('training.view_progress', $published_training, $strangers + array('root' => true, 'lead' => true, 'lecturer' => true, 'tutor' => true, 'pupil' => false), 'a training');
$archived_training = array('id' => 3, 'domain_id' => 4, 'status' => 'published');
check_ability('training.view', $archived_training, array('pupil' => true, 'lecturer' => true, 'alice' => false), 'a training of an archived domain');
check_ability('training.manage', $archived_training, array('lead' => false, 'lecturer' => false), 'a training of an archived domain');

// ---- an ability that does not exist is refused
check_same(false, @can($permission_test_users['root'], 'problem.mange', $facts->problems[1]), 'a misspelled ability');
