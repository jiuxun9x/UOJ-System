<?php

// Who may do what. Every permission check of the web application goes through can(), so that
// the rules can be read, changed and tested in one place.
//
// An ability is named "<resource>.<action>", and the resource is the database row it is about:
// a problem, a contest (after genMoreContestInfo), a submission, a hack, the owner of a blog.
// Abilities about the whole site take no resource.

// What the rules need to know from the database. The tests replace it.
class UOJPermissionFacts {
	private $problems = array();
	private $contests = array();

	public function managesProblem($username, $problem_id) {
		return DB::selectFirst("select 1 from problems_permissions where username = '".DB::escape($username)."' and problem_id = ".(int)$problem_id) != null;
	}
	public function assistsContest($username, $contest_id) {
		return DB::selectFirst("select 1 from contests_permissions where username = '".DB::escape($username)."' and contest_id = ".(int)$contest_id) != null;
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

function can($user, $ability, $resource = null) {
	$facts = permissionFacts();
	$name = $user != null ? $user['username'] : null;
	$is_super = $user != null && $user['usergroup'] == 'S';

	switch ($ability) {
		// ---- the site
		case 'site.manage':
		case 'user.view_private':
		case 'problem.create':
		case 'problem.edit_raw_config':
		case 'problem.approve_judger':
		case 'contest.create':
		case 'contest.manage':
		case 'contest.rate':
		case 'submission.view_all':
		case 'submission.delete':
		case 'hack.delete':
			return $is_super;

		// ---- problems
		case 'problem.manage':
			return $is_super || ($name !== null && $facts->managesProblem($name, $resource['id']));
		case 'problem.view':
			return !$resource['is_hidden'] || can($user, 'problem.manage', $resource);

		// ---- contests
		// the people behind the scenes of a contest: they see everything and answer questions
		case 'contest.assist':
			return $is_super || ($name !== null && $facts->assistsContest($name, $resource['id']));

		// ---- blogs, the resource is the name of the user the blog belongs to
		case 'blog.manage':
			return $is_super || ($name !== null && $name === $resource);

		// ---- submissions
		case 'submission.view':
		case 'hack.view':
			return $is_super || !$resource['is_hidden'] || can($user, 'problem.manage', $facts->problem($resource['problem_id']));
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
			if ($is_super || ($contest != null && can($user, 'contest.assist', $contest))) {
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
			return $is_super || ($contest != null && can($user, 'contest.assist', $contest));

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
			return $is_super;
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
	if ($user != null && $user['usergroup'] == 'S') {
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
