<?php

// A user is three things:
//   id        a number that never changes
//   username  what they log in with, and what every other table calls them. A user who came
//             through the single sign-on of the school is named by the school (their student
//             number) and can not change it; everybody else can.
//   nickname  what they like to be called (别名). It never stands alone: it is shown as
//             "nickname（username）".

function validateNickname($nickname) {
	if (!is_string($nickname) || !mb_check_encoding($nickname, 'UTF-8')) {
		return false;
	}
	if (mb_strlen($nickname, 'UTF-8') > 20 || $nickname !== trim($nickname)) {
		return false;
	}
	// nothing that could pass for markup, a mention, or the username in parentheses behind it
	return !preg_match('/[\x00-\x1f\x7f<>&"\'@()（）\\\\]/u', $nickname);
}

// the name of a user as plain text
function userDisplayName($user) {
	if (isset($user['nickname']) && $user['nickname'] !== '') {
		return "{$user['nickname']}（{$user['username']}）";
	}
	return $user['username'];
}

function queryUserById($id) {
	return DB::selectFirst("select * from user_info where id = ".(int)$id, MYSQLI_ASSOC);
}

// every column that holds a username, besides user_info.username itself
function usernameColumns() {
	return array(
		'best_ac_submissions' => array('submitter'),
		'blogs' => array('poster'),
		'blogs_comments' => array('poster'),
		'click_zans' => array('username'),
		'contests_asks' => array('username'),
		'contests_permissions' => array('username'),
		'contests_registrants' => array('username'),
		'contests_submissions' => array('submitter'),
		'custom_test_submissions' => array('submitter'),
		'external_identities' => array('username'),
		'hacks' => array('hacker', 'owner'),
		'pastes' => array('creator'),
		'problem_data_versions' => array('created_by'),
		'problems_permissions' => array('username'),
		'submissions' => array('submitter'),
		'user_msg' => array('sender', 'receiver'),
		'user_roles' => array('username', 'granted_by'),
		'user_system_msg' => array('receiver')
	);
}

// Returns '' when a user may take a username, or why not. $user is the user who wants the
// name, null for somebody who registers. $options: 'sso' for a user the single sign-on
// creates, 'admin' for a change an administrator makes.
function usernameUnavailableReason($username, $user = null, $options = array()) {
	if (!validateUsername($username)) {
		return '用户名不合法：只能包含字母、数字和下划线，长度不超过 20';
	}
	// names differ by more than their case, but a user may change the case of their own
	$taken_by = queryUser($username);
	if ($taken_by && ($user == null || $taken_by['id'] != $user['id'])) {
		return '用户名已存在';
	}
	// a name that somebody gave up is theirs to take back, and nobody else's
	$esc_username = DB::escape($username);
	$user_id = $user != null ? (int)$user['id'] : 0;
	if (DB::selectFirst("select 1 from user_renames where (old_username = '$esc_username' or new_username = '$esc_username') and user_id != $user_id limit 1")) {
		return '该用户名已被保留';
	}
	// A name that looks like a student number belongs to the student the school gives that
	// number to, and they arrive through the single sign-on.
	if (!isset($options['sso']) && !isset($options['admin']) && UOJSSO::enabled()) {
		$pattern = UOJConfig::$data['sso']['reserved-username-pattern'];
		if ($pattern !== '' && preg_match($pattern, $username)) {
			return '该用户名保留给统一身份认证的用户';
		}
	}
	return '';
}

// Applies a journaled change of a username to every table. Every statement can be repeated.
function applyUserRename($rename) {
	$old = DB::escape($rename['old_username']);
	$new = DB::escape($rename['new_username']);
	// the password was hashed with the name the user had when they set it
	if (!DB::update("update user_info set password_salt = ifnull(password_salt, '$old'), username = '$new', remember_token = '' where username = '$old'")) {
		return false;
	}
	foreach (usernameColumns() as $table => $columns) {
		foreach ($columns as $column) {
			if (!DB::update("update `$table` set `$column` = '$new' where `$column` = '$old'")) {
				return false;
			}
		}
	}
	return DB::update("update user_renames set finished_at = now() where id = {$rename['id']}");
}

// finishes the changes that were interrupted, returns how many are still not finished
function finishUserRenames() {
	$left = 0;
	foreach (DB::selectAll("select * from user_renames where finished_at is null order by id") as $rename) {
		if (!applyUserRename($rename)) {
			$left++;
		}
	}
	return $left;
}

// Changes the username of a user everywhere. Returns '' or why it was not changed.
// $actor is the user who asks for it: the user themselves, or an administrator.
function renameUser($user, $new_username, $actor, $options = array()) {
	if ($new_username === $user['username']) {
		return '';
	}
	$lock = DB::selectFirst("select get_lock('uoj_rename_user', 10)", MYSQLI_NUM);
	if (!$lock || $lock[0] != 1) {
		return '系统繁忙，请稍后再试';
	}
	$err = call_user_func(function() use ($user, $new_username, $actor, $options) {
		if (finishUserRenames() > 0) {
			return '系统繁忙，请稍后再试';
		}
		$err = usernameUnavailableReason($new_username, $user, $options);
		if ($err !== '') {
			return $err;
		}
		$esc_old = DB::escape($user['username']);
		$esc_new = DB::escape($new_username);
		$esc_actor = DB::escape($actor['username']);
		if (!DB::insert("insert into user_renames (user_id, old_username, new_username, renamed_by, started_at) values ({$user['id']}, '$esc_old', '$esc_new', '$esc_actor', now())")) {
			return '修改失败';
		}
		$rename = DB::selectFirst("select * from user_renames where id = ".DB::insert_id());
		if (!applyUserRename($rename)) {
			// somebody registered the name in between: nothing was changed yet
			if (queryUser($user['username'])) {
				DB::delete("delete from user_renames where id = {$rename['id']}");
			}
			return '修改失败';
		}
		return '';
	});
	DB::query("select release_lock('uoj_rename_user')");
	return $err;
}

// How many days a user who changed their username has to wait before they change it again.
// A change made by the user themselves is journaled under the name they had.
function usernameChangeWaitDays($user) {
	$interval = (int)UOJConfig::$data['user']['username-change-interval'];
	if ($interval <= 0) {
		return 0;
	}
	$row = DB::selectFirst("select datediff(now(), max(started_at)) as days from user_renames where user_id = {$user['id']} and renamed_by = old_username");
	if (!$row || $row['days'] === null) {
		return 0;
	}
	return max(0, $interval - (int)$row['days']);
}

// Returns '' when a user may change their username themselves, or why not.
function usernameChangeRefusedReason($user) {
	if (UOJSSO::isBound($user['username'])) {
		return '通过统一身份认证登录的账号以学号为用户名，不能修改';
	}
	$days = usernameChangeWaitDays($user);
	if ($days > 0) {
		return "距离上次修改用户名还不满规定的时间，请在 {$days} 天后再修改";
	}
	return '';
}
