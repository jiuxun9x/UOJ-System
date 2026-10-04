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
//   teacher       may create problems and contests, and manages the ones they created
// Roles of one problem or contest:
//   manager of a problem (problems_permissions)
//   owner of a contest: settings, problems, staff, final test, results
//   assistant of a contest: sees everything behind the scenes and answers questions
// Everybody else is a student.

define('UOJ_ROLE_SYSTEM_ADMIN', 'system_admin');
define('UOJ_ROLE_OJ_ADMIN', 'oj_admin');
define('UOJ_ROLE_TEACHER', 'teacher');

// the roles that are stored in user_roles, with their names for the pages
function grantableRoles() {
	return array(
		UOJ_ROLE_OJ_ADMIN => 'OJ 管理员',
		UOJ_ROLE_TEACHER => '教师'
	);
}

// What the rules need to know from the database. The tests replace it.
class UOJPermissionFacts {
	private $problems = array();
	private $contests = array();

	private $roles = array();

	public function grantedRoles($username) {
		if (!isset($this->roles[$username])) {
			$this->roles[$username] = array();
			foreach (DB::selectAll("select role from user_roles where username = '".DB::escape($username)."'") as $row) {
				$this->roles[$username][] = $row['role'];
			}
		}
		return $this->roles[$username];
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

function can($user, $ability, $resource = null) {
	$facts = permissionFacts();
	$name = $user != null ? $user['username'] : null;
	$is_admin = isSiteAdmin($user);

	switch ($ability) {
		// ---- the site
		case 'user.manage_roles':
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

		// ---- problems
		case 'problem.manage':
			return $is_admin || ($name !== null && $facts->managesProblem($name, $resource['id']));
		case 'problem.view':
			return !$resource['is_hidden'] || can($user, 'problem.manage', $resource);

		// ---- contests
		case 'contest.manage':
			return $is_admin || ($name !== null && $facts->contestRole($name, $resource['id']) === 'owner');
		// the people behind the scenes of a contest: they see everything and answer questions
		case 'contest.assist':
			return $is_admin || ($name !== null && $facts->contestRole($name, $resource['id']) !== null);

		// ---- blogs, the resource is the name of the user the blog belongs to
		case 'blog.manage':
			return $is_admin || ($name !== null && $name === $resource);

		// ---- submissions
		case 'submission.view':
		case 'hack.view':
			return $is_admin || !$resource['is_hidden'] || can($user, 'problem.manage', $facts->problem($resource['problem_id']));
		case 'submission.rejudge':
			return can($user, 'problem.manage', $facts->problem($resource['problem_id']));
		case 'submission.view_source':
		case 'submission.view_details':
			$contest = $facts->contest($resource['contest_id']);
			if ($contest != null && can($user, 'contest.assist', $contest)) {
				return true;
			}
			$setting = $ability == 'submission.view_source' ? 'view_content_type' : 'view_all_details_type';
			return permissionViewTypeAllows($setting, $user, $resource) && permissionIsOpen($user, $resource, $resource['submitter']);
		// what every test did, not only its verdict
		case 'submission.view_test_details':
			$contest = $facts->contest($resource['contest_id']);
			if ($is_admin || ($contest != null && can($user, 'contest.assist', $contest))) {
				return true;
			}
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
			$contest = $facts->contest($resource['contest_id']);
			return $is_admin || ($contest != null && can($user, 'contest.assist', $contest));

		// ---- hacks, the resource is the hack with its submission in 'submission'
		case 'hack.view_source':
		case 'hack.view_details':
			$setting = $ability == 'hack.view_source' ? 'view_content_type' : 'view_all_details_type';
			return permissionViewTypeAllows($setting, $user, $resource['submission'])
				&& permissionIsOpen($user, $resource['submission'], $resource['submission']['submitter'])
				&& permissionIsOpen($user, $resource['submission'], $resource['hacker']);
		case 'hack.view_test_details':
			return permissionViewTypeAllows('view_details_type', $user, $resource['submission']);
		case 'hack.view_final_details':
			return $is_admin;
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
	if ($type == 'SELF') {
		return $user != null && $submission['submitter'] == $user['username'];
	}
	return false;
}

// While its contest runs, a submission is closed to everybody but the user it belongs to and
// the people who manage its problem.
function permissionIsOpen($user, $submission, $owner) {
	$facts = permissionFacts();
	if (isSiteAdmin($user)) {
		return true;
	}
	$contest = $facts->contest($submission['contest_id']);
	if ($contest == null || $contest['cur_progress'] > CONTEST_IN_PROGRESS) {
		return true;
	}
	if ($user != null && $owner == $user['username']) {
		return true;
	}
	return can($user, 'problem.manage', $facts->problem($submission['problem_id']));
}

// The conditions that keep what a user may not see out of the lists of submissions and hacks.
function visibleSubmissionsCond($user) {
	if (can($user, 'submission.view_all')) {
		return '1';
	}
	if ($user != null) {
		return "submissions.is_hidden = false or (submissions.is_hidden = true and submissions.problem_id in (select problem_id from problems_permissions where username = '{$user['username']}'))";
	}
	return "submissions.is_hidden = false";
}
function visibleHacksCond($user) {
	if (can($user, 'submission.view_all')) {
		return '1';
	}
	if ($user != null) {
		return "is_hidden = false or (is_hidden = true and problem_id in (select problem_id from problems_permissions where username = '{$user['username']}'))";
	}
	return "is_hidden = false";
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
		if ($op == 'banneduser' && $target['username'] == $actor['username']) {
			return '不能封禁自己';
		}
		if (isLastSystemAdmin($target)) {
			return '不能取消最后一位系统管理员';
		}
		$usergroup = $op == 'banneduser' ? 'B' : 'U';
		DB::update("update user_info set usergroup = '$usergroup' where username = '$esc_username'");
		if ($op == 'banneduser') {
			// a banned user keeps nothing that would work again by accident later
			DB::delete("delete from user_roles where username = '$esc_username'");
			DB::update("update user_info set remember_token = '' where username = '$esc_username'");
		}
		return '';
	}

	if (!$may_manage_roles) {
		return '只有系统管理员可以修改角色';
	}
	if ($op == 'superuser') {
		DB::update("update user_info set usergroup = 'S' where username = '$esc_username'");
		return '';
	}
	$parts = explode(':', $op, 2);
	if (count($parts) == 2 && isset(grantableRoles()[$parts[1]])) {
		if ($target['usergroup'] == 'B') {
			return '该用户已被封禁';
		}
		if ($parts[0] == 'grant') {
			grantRole($target['username'], $parts[1], $actor['username']);
			return '';
		} elseif ($parts[0] == 'revoke') {
			revokeRole($target['username'], $parts[1]);
			return '';
		}
	}
	return '无效操作';
}
