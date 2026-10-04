<?php

require_once __DIR__ . '/../app/libs/uoj-utility-lib.php';
require_once __DIR__ . '/../app/libs/uoj-contest-lib.php';
require_once __DIR__ . '/../app/libs/uoj-permission-lib.php';

// the facts of a small site, instead of the database
class FakePermissionFacts {
	public $problem_managers = array();
	public $contest_assistants = array();
	public $registered = array();
	public $accepted = array();
	public $problems = array();
	public $contests = array();

	public function managesProblem($username, $problem_id) {
		return in_array(array($username, $problem_id), $this->problem_managers);
	}
	public function assistsContest($username, $contest_id) {
		return in_array(array($username, $contest_id), $this->contest_assistants);
	}
	public function hasRegistered($username, $contest_id) {
		return in_array(array($username, $contest_id), $this->registered);
	}
	public function hasAccepted($username, $problem_id) {
		return in_array(array($username, $problem_id), $this->accepted);
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
// contest 10 is running, contest 11 is over, helper is behind the scenes of both
$facts->contests = array(
	10 => fake_contest(10, CONTEST_IN_PROGRESS),
	11 => fake_contest(11, CONTEST_FINISHED),
	12 => fake_contest(12, CONTEST_IN_PROGRESS, array('contest_type' => 'IOI')),
	13 => fake_contest(13, CONTEST_NOT_STARTED),
);
$facts->contest_assistants = array(array('helper', 10), array('helper', 11), array('helper', 12));
$facts->registered = array(array('alice', 10), array('alice', 13));
$facts->accepted = array(array('bob', 3));

// ---- the site
foreach (array('site.manage', 'user.view_private', 'problem.create', 'contest.create', 'problem.approve_judger', 'problem.edit_raw_config', 'submission.view_all', 'submission.delete', 'hack.delete') as $ability) {
	check_ability($ability, null, array('nobody' => false, 'root' => true, 'setter' => false, 'helper' => false, 'alice' => false), 'site');
}

// ---- problems
check_ability('problem.view', $facts->problems[1], array('nobody' => true, 'root' => true, 'setter' => true, 'alice' => true), 'a public problem');
check_ability('problem.view', $facts->problems[2], array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false), 'a hidden problem');
check_ability('problem.manage', $facts->problems[1], array('nobody' => false, 'root' => true, 'setter' => true, 'helper' => false, 'alice' => false), 'a public problem');
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
	check_ability('contest.assist', $facts->contests[$contest_id], array('nobody' => false, 'root' => true, 'setter' => false, 'helper' => true, 'alice' => false), "contest $contest_id");
	check_ability('contest.manage', $facts->contests[$contest_id], array('nobody' => false, 'root' => true, 'setter' => false, 'helper' => false, 'alice' => false), "contest $contest_id");
	check_ability('contest.rate', $facts->contests[$contest_id], array('nobody' => false, 'root' => true, 'helper' => false), "contest $contest_id");
}

// ---- blogs
check_ability('blog.manage', 'alice', array('nobody' => false, 'root' => true, 'alice' => true, 'bob' => false), 'the blog of alice');

// ---- submissions outside of contests
$open = fake_submission('alice', 1);
foreach (array('submission.view', 'submission.view_source', 'submission.view_details', 'submission.view_test_details') as $ability) {
	check_ability($ability, $open, array('nobody' => true, 'root' => true, 'setter' => true, 'alice' => true, 'bob' => true), 'a submission to a public problem');
}
check_ability('submission.view_final_details', $open, array('nobody' => false, 'root' => true, 'setter' => false, 'alice' => false), 'a submission to a public problem');
check_ability('submission.rejudge', $open, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false, 'bob' => false), 'a submission to a public problem');

$hidden = fake_submission('alice', 2, null, 1);
check_ability('submission.view', $hidden, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false, 'bob' => false), 'a submission to a hidden problem');

// the problem decides who reads what: the source after solving it, the details only the owner
$restricted = fake_submission('alice', 3);
check_ability('submission.view_source', $restricted, array('nobody' => false, 'alice' => false, 'bob' => true), 'ALL_AFTER_AC');
check_ability('submission.view_details', $restricted, array('nobody' => false, 'alice' => true, 'bob' => false), 'SELF');
check_ability('submission.view_test_details', $restricted, array('nobody' => false, 'root' => true, 'alice' => true, 'bob' => false), 'SELF');

// ---- submissions of a contest that is running
$running = fake_submission('alice', 1, 10);
check_ability('submission.view', $running, array('nobody' => true, 'bob' => true), 'during the contest');
foreach (array('submission.view_source', 'submission.view_details') as $ability) {
	check_ability($ability, $running, array('nobody' => false, 'root' => true, 'setter' => true, 'helper' => true, 'alice' => true, 'bob' => false), 'during the contest');
}
check_ability('submission.view_final_details', $running, array('nobody' => false, 'root' => true, 'setter' => false, 'helper' => true, 'alice' => false), 'during the contest');
check_ability('submission.view_test_details', $running, array('root' => true, 'helper' => true, 'alice' => true), 'during the contest');
check_ability('submission.view_test_details', fake_submission('alice', 1, 12), array('root' => true, 'helper' => true, 'alice' => false, 'bob' => false), 'during an IOI contest');
$facts->contests[10]['extra_config']['problem_1'] = 'no-details';
check_ability('submission.view_test_details', $running, array('root' => true, 'helper' => true, 'alice' => false), 'a problem without details, during the contest');
unset($facts->contests[10]['extra_config']['problem_1']);

// ---- and once the contest is over
$over = fake_submission('alice', 1, 11);
foreach (array('submission.view_source', 'submission.view_details', 'submission.view_test_details') as $ability) {
	check_ability($ability, $over, array('nobody' => true, 'helper' => true, 'alice' => true, 'bob' => true), 'after the contest');
}

// ---- hacks
$hack = array('hacker' => 'bob', 'problem_id' => 1, 'is_hidden' => 0, 'submission' => $open);
foreach (array('hack.view', 'hack.view_source', 'hack.view_details', 'hack.view_test_details') as $ability) {
	check_ability($ability, $hack, array('nobody' => true, 'alice' => true, 'bob' => true), 'a hack');
}
check_ability('hack.view_final_details', $hack, array('nobody' => false, 'root' => true, 'setter' => false, 'bob' => false), 'a hack');
check_ability('hack.view', array('is_hidden' => 1, 'problem_id' => 2), array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false), 'a hack of a hidden problem');
// both the hacker and the hacked user have to be allowed to see it while the contest runs
$contest_hack = array('hacker' => 'bob', 'problem_id' => 1, 'is_hidden' => 0, 'submission' => $running);
check_ability('hack.view_source', $contest_hack, array('nobody' => false, 'root' => true, 'setter' => true, 'alice' => false, 'bob' => false), 'a hack during the contest');

// ---- an ability that does not exist is refused
check_same(false, @can($permission_test_users['root'], 'problem.mange', $facts->problems[1]), 'a misspelled ability');
