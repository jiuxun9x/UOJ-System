<?php

function hasRegistered($user, $contest) {
	return DB::selectFirst("select * from contests_registrants where username = '${user['username']}' and contest_id = ${contest['id']}") != null;
}
function hasAC($user, $problem) {
	return DB::selectFirst("select * from best_ac_submissions where submitter = '${user['username']}' and problem_id = ${problem['id']}") != null;
}

function queryUser($username) {
	if (!validateUsername($username)) {
		return null;
	}
	return DB::selectFirst("select * from user_info where username='$username'", MYSQLI_ASSOC);
}
function queryProblemContent($id) {
	return DB::selectFirst("select * from problems_contents where id = $id", MYSQLI_ASSOC);
}
function queryProblemBrief($id) {
	return DB::selectFirst("select * from problems where id = $id", MYSQLI_ASSOC);
}

// ---- the number of a problem
//
// A problem of the site is known by its id. A problem of a domain is known, to the people of
// its domain, by the number it has there, which counts from 1 in every domain. Its id is what
// the rest of the system refers to, and comes from a range of its own: the ids of the
// problems of the site, which are their numbers, go on without holes whatever domains do.
define('UOJ_DOMAIN_PROBLEM_ID_BASE', 1000000);

// the number people know a problem by
function problemNumber($problem) {
	return !empty($problem['owner_domain_id']) && !empty($problem['domain_pid']) ? (int)$problem['domain_pid'] : (int)$problem['id'];
}
function queryDomainProblem($domain_id, $number) {
	return DB::selectFirst("select * from problems where owner_domain_id = ".(int)$domain_id." and domain_pid = ".(int)$number, MYSQLI_ASSOC);
}
// the address of the page of a problem, or of a page under it
function problemUrl($problem, $path = '') {
	static $slugs = array();
	if (!empty($problem['owner_domain_id'])) {
		$domain_id = (int)$problem['owner_domain_id'];
		if (!array_key_exists($domain_id, $slugs)) {
			$domain = queryDomain($domain_id);
			$slugs[$domain_id] = $domain ? $domain['slug'] : null;
		}
		if ($slugs[$domain_id] !== null) {
			return "/d/{$slugs[$domain_id]}/problem/" . problemNumber($problem) . $path;
		}
	}
	return "/problem/{$problem['id']}$path";
}
// What a problem is called where problems of the site and of domains are listed together: its
// number, with the name of the address of its domain in front of it.
function problemLabel($problem) {
	if (!empty($problem['owner_domain_id']) && preg_match('#^/d/([a-z0-9-]+)/#', problemUrl($problem), $matches)) {
		return $matches[1] . '#' . problemNumber($problem);
	}
	return '#' . problemNumber($problem);
}

// where a copy came from, for the people who manage it: '' for a problem that is no copy
function problemSourceNote($source_problem_id, $source_data_version) {
	if (!$source_problem_id) {
		return '';
	}
	$source = queryProblemBrief((int)$source_problem_id);
	return '复制自 ' . ($source ? (empty($source['owner_domain_id']) ? '主站 ' : '') . problemLabel($source) : '一道已不存在的题') . ' v' . (int)$source_data_version;
}

// The problem that the address of a page names. In the pages of a domain the number in the
// address is the number the problem has in the domain, and so it is in a contest of a
// domain. Returns null when there is no such problem; a stranger to the domain is shown the
// door by domainOfPage().
function problemOfPage() {
	if (!isset($_GET['id']) || !validateUInt($_GET['id'])) {
		return null;
	}
	if (isset($_GET['slug'])) {
		$domain = domainOfPage();
		return queryDomainProblem($domain['id'], $_GET['id']);
	}
	if (isset($_GET['contest_id']) && validateUInt($_GET['contest_id'])) {
		$contest = queryContest($_GET['contest_id']);
		if ($contest && $contest['domain_id']) {
			$problem = queryDomainProblem($contest['domain_id'], $_GET['id']);
			if ($problem && DB::selectFirst("select 1 from contests_problems where contest_id = {$contest['id']} and problem_id = {$problem['id']}")) {
				return $problem;
			}
		}
	}
	return queryProblemBrief($_GET['id']);
}

// Makes a problem and returns its id, or null. $fields maps the columns that say what the
// problem is to SQL values; where it belongs and which numbers it gets is decided here, one
// problem at a time.
function problemCreate($fields, $domain_id = null) {
	$locked = DB::selectFirst("select get_lock('uoj_new_problem', 10)", MYSQLI_NUM);
	if (!$locked || !$locked[0]) {
		return null;
	}
	try {
		if ($domain_id === null) {
			$id = 1 + (int)DB::selectFirst("select ifnull(max(id), 0) from problems where owner_domain_id is null", MYSQLI_NUM)[0];
			// the ids that problems of domains were given before they had a range of their own
			while (DB::selectFirst("select 1 from problems where id = $id")) {
				$id++;
			}
			if ($id >= UOJ_DOMAIN_PROBLEM_ID_BASE) {
				return null;
			}
		} else {
			$domain_id = (int)$domain_id;
			$id = 1 + (int)DB::selectFirst("select ifnull(max(id), ".UOJ_DOMAIN_PROBLEM_ID_BASE.") from problems where id > ".UOJ_DOMAIN_PROBLEM_ID_BASE, MYSQLI_NUM)[0];
			$fields['owner_domain_id'] = $domain_id;
			$fields['domain_pid'] = 1 + (int)DB::selectFirst("select ifnull(max(domain_pid), 0) from problems where owner_domain_id = $domain_id", MYSQLI_NUM)[0];
		}
		$fields['id'] = $id;
		if (!DB::insert("insert into problems (".join(', ', array_keys($fields)).") values (".join(', ', $fields).")")) {
			return null;
		}
		return $id;
	} finally {
		DB::query("select release_lock('uoj_new_problem')");
	}
}

function queryProblemTags($id) {
	$tags = array();
	$result = DB::query("select tag from problems_tags where problem_id = $id order by id");
	while ($row = DB::fetch($result, MYSQLI_NUM)) {
		$tags[] = $row[0];
	}
	return $tags;
}
// which problem of the contest a problem is, counted from 1 in the order they are lettered
function queryContestProblemRank($contest, $problem) {
	$index = array_search((int)$problem['id'], contestProblemIds($contest['id']), true);
	return $index === false ? null : $index + 1;
}
function querySubmission($id) {
	return DB::selectFirst("select * from submissions where id = $id", MYSQLI_ASSOC);
}
function queryHack($id) {
	return DB::selectFirst("select * from hacks where id = $id", MYSQLI_ASSOC);
}
function queryContest($id) {
	return DB::selectFirst("select * from contests where id = $id", MYSQLI_ASSOC);
}
function queryContestProblem($id) {
	return DB::selectFirst("select * from contest_problems where contest_id = $id", MYSQLI_ASSOC);
}

function queryZanVal($id, $type, $user) {
	if ($user == null) {
		return 0;
	}
	$esc_type = DB::escape($type);
	$row = DB::selectFirst("select val from click_zans where username='{$user['username']}' and type='$esc_type' and target_id='$id'");
	if ($row == null) {
		return 0;
	}
	return $row['val'];
}

function queryBlog($id) {
	return DB::selectFirst("select * from blogs where id='$id'", MYSQLI_ASSOC);
}
function queryBlogTags($id) {
	$tags = array();
	$result = DB::select("select tag from blogs_tags where blog_id = $id order by id");
	while ($row = DB::fetch($result, MYSQLI_NUM)) {
		$tags[] = $row[0];
	}
	return $tags;
}
function queryBlogComment($id) {
	return DB::selectFirst("select * from blogs_comments where id='$id'", MYSQLI_ASSOC);
}

function deleteBlog($id) {
	if (!validateUInt($id)) {
		return;
	}
	DB::delete("delete from click_zans where type = 'B' and target_id = $id");
	DB::delete("delete from click_zans where type = 'BC' and target_id in (select id from blogs_comments where blog_id = $id)");
	DB::delete("delete from blogs where id = $id");
	DB::delete("delete from blogs_comments where blog_id = $id");
	DB::delete("delete from important_blogs where blog_id = $id");
	DB::delete("delete from blogs_tags where blog_id = $id");
}