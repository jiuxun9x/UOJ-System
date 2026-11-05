<?php

// ---- passwords
//
// What the browser sends is a hash of what was typed already (HMAC-MD5 with the client salt,
// see getPasswordClientSalt()): that is the password here, and it is kept as Argon2id.
// Before Argon2id the site kept md5(username . password), the username being the one the user
// had then (password_salt after a rename). The upgrade 1029 wrapped every such md5 into
// Argon2id as it was (password_legacy = 1), so that none is kept bare; the first login with
// such a password keeps it the new way.

// the cost of a hash: the memory and the time of OWASP's advice, unless the configuration says
function passwordHashOptions() {
	$conf = class_exists('UOJConfig') && isset(UOJConfig::$data['security']['user']) ? UOJConfig::$data['security']['user'] : array();
	return array(
		'memory_cost' => isset($conf['password-memory-kib']) ? (int)$conf['password-memory-kib'] : 19456,
		'time_cost' => isset($conf['password-time']) ? (int)$conf['password-time'] : 2,
		'threads' => 1
	);
}
function passwordHashToStore($password) {
	return password_hash($password, PASSWORD_ARGON2ID, passwordHashOptions());
}
// a password kept the way of before Argon2id, as the upgrade wraps it
function passwordLegacyHash($salt, $password) {
	return md5($salt . $password);
}
// Whether a password is the one of a user: array(it is, what is kept is to be made anew).
function passwordMatches($user, $password) {
	if (!is_string($password) || !hasUsablePassword($user)) {
		return array(false, false);
	}
	$stored = $user['password'];
	$salt = isset($user['password_salt']) ? $user['password_salt'] : $user['username'];
	if (strncmp($stored, '$argon2id$', 10) === 0) {
		$legacy = !empty($user['password_legacy']);
		$ok = password_verify($legacy ? passwordLegacyHash($salt, $password) : $password, $stored);
		return array($ok, $ok && ($legacy || password_needs_rehash($stored, PASSWORD_ARGON2ID, passwordHashOptions())));
	}
	// an md5 that no upgrade wrapped: written by hand, or put back from an old backup
	$ok = preg_match('/^[0-9a-f]{32}$/D', $stored) && hash_equals($stored, passwordLegacyHash($salt, $password));
	return array($ok, $ok);
}
function checkPassword($user, $password) {
	list($ok, $anew) = passwordMatches($user, $password);
	if ($ok && $anew) {
		storeUserPassword($user['username'], $password);
	}
	return $ok;
}
// keeps a password, and nothing of how the password of the user was kept before
function storeUserPassword($username, $password) {
	$stored = DB::escape(passwordHashToStore($password));
	return DB::update("update user_info set password = '$stored', password_legacy = 0, password_salt = null where username = '".DB::escape($username)."'");
}
// A user who has never set a password, because they log in through the single sign-on of the
// school, has a random string in its place that no password hashes to. It still has to be
// secret: the link that resets a password is derived from it.
function unusablePassword() {
	return '!' . uojRandString(31);
}
function hasUsablePassword($user) {
	return $user['password'] !== '' && $user['password'][0] !== '!';
}
function setUserPassword($username, $password) {
	return storeUserPassword($username, $password);
}
// Ends every login of a user: the sessions, and the devices that remember a login. With
// $keep_current, the user who asks stays logged in here, with a new session and a new token.
function revokeUserLogins($username, $keep_current = false) {
	$esc_username = DB::escape($username);
	DB::update("update user_info set auth_version = auth_version + 1 where username = '$esc_username'");
	DB::delete("delete from user_remember_tokens where username = '$esc_username'");
	if ($keep_current && Auth::id() === $username) {
		Auth::login($username);
	}
}
// ---- the links that reset a password
//
// A link carries a random token, of which only the SHA-256 is kept. It works for half an hour
// (security.user.password-reset-minutes), once, and only while it is the newest one of the user.
// A user asks for one a minute and five a day at most, and an address for twenty an hour: more
// asking sends no more mails, and is told the same as any asking is.

function passwordResetMinutes() {
	$conf = class_exists('UOJConfig') && isset(UOJConfig::$data['security']['user']) ? UOJConfig::$data['security']['user'] : array();
	return isset($conf['password-reset-minutes']) ? max(1, (int)$conf['password-reset-minutes']) : 30;
}
// whether one more link is too many: the seconds since the last link of the user (null for
// none), the links of the user in a day, the links asked for from the address in an hour
function passwordResetTooMany($since_last, $user_day, $ip_hour) {
	return ($since_last !== null && $since_last < 60) || $user_day >= 5 || $ip_hour >= 20;
}
// A link for a user, or null when there have been too many. The links before it stop working.
function passwordResetCreate($user, $ip) {
	$esc_username = DB::escape($user['username']);
	$esc_ip = DB::escape($ip);
	$last = DB::selectFirst("select timestampdiff(second, max(created_at), now()) as since_last, count(*) as n from password_resets where username = '$esc_username' and created_at > now() - interval 1 day");
	$ip_hour = DB::selectCount("select count(*) from password_resets where ip = '$esc_ip' and created_at > now() - interval 1 hour");
	if (passwordResetTooMany($last && $last['since_last'] !== null ? (int)$last['since_last'] : null, $last ? (int)$last['n'] : 0, (int)$ip_hour)) {
		return null;
	}
	$token = base64url_encode(random_bytes(32));
	DB::update("update password_resets set used_at = now() where username = '$esc_username' and used_at is null");
	DB::insert("insert into password_resets (username, token_hash, created_at, expires_at, ip) values ('$esc_username', '".hash('sha256', $token)."', now(), now() + interval ".passwordResetMinutes()." minute, '$esc_ip')");
	return $token;
}
// the link of a token while it works, or null
function passwordResetFind($token) {
	if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
		return null;
	}
	return DB::selectFirst("select * from password_resets where token_hash = '".hash('sha256', $token)."' and used_at is null and expires_at > now()");
}
// Sets the password the link is for, once: returns whether this was the time it was used.
function passwordResetUse($reset, $password) {
	DB::update("update password_resets set used_at = now() where id = ".(int)$reset['id']." and used_at is null");
	if (DB::affected_rows() != 1) {
		return false;
	}
	setUserPassword($reset['username'], $password);
	DB::update("update password_resets set used_at = now() where username = '".DB::escape($reset['username'])."' and used_at is null");
	// whoever had the password or a login does not keep them
	revokeUserLogins($reset['username']);
	return true;
}

// ---- passwords that are guessed
//
// Each failure counts for a scope: the name from the address, the name, the address. Past the
// number of failures a scope allows in a quarter of an hour (security.login-limits), no password
// of that scope is looked at until the quarter is over. Nobody is locked out for good, so that
// nobody can lock out somebody else for long; a school is often one address, which allows the
// most.

function authLimits() {
	$conf = class_exists('UOJConfig') && isset(UOJConfig::$data['security']['login-limits']) ? UOJConfig::$data['security']['login-limits'] : array();
	return array(
		'pair' => isset($conf['name-and-address']) ? (int)$conf['name-and-address'] : 5,
		'user' => isset($conf['name']) ? (int)$conf['name'] : 20,
		'ip' => isset($conf['address']) ? (int)$conf['address'] : 100,
		'contest' => isset($conf['contest-password']) ? (int)$conf['contest-password'] : 20,
		'seconds' => 60 * (isset($conf['minutes']) ? max(1, (int)$conf['minutes']) : 15)
	);
}
// the scopes of logging in as a user from an address: scope => how many failures it allows
function authLoginScopes($username, $ip) {
	$limits = authLimits();
	return array(
		"login:pair:$username|$ip" => $limits['pair'],
		"login:user:$username" => $limits['user'],
		"login:ip:$ip" => $limits['ip']
	);
}
function authContestScopes($username, $contest_id) {
	$limits = authLimits();
	return array("contest:" . (int)$contest_id . ":$username" => $limits['contest']);
}
// How many seconds one has to wait: so many failures since the start of a window that began so
// many seconds ago (null for no failure).
function authWaitSeconds($failures, $since_start, $limit, $window) {
	if ($since_start === null || $since_start >= $window || $failures < $limit) {
		return 0;
	}
	return $window - $since_start;
}
// how many seconds whoever tries these scopes has to wait, 0 for none
function authThrottled($scopes) {
	$limits = authLimits();
	$esc = array_map(function($scope) {
		return "'" . DB::escape($scope) . "'";
	}, array_keys($scopes));
	$wait = 0;
	foreach (DB::selectAll("select scope, failures, timestampdiff(second, window_start, now()) as since_start from auth_failures where scope in (".join(',', $esc).")") as $row) {
		$wait = max($wait, authWaitSeconds((int)$row['failures'], (int)$row['since_start'], $scopes[$row['scope']], $limits['seconds']));
	}
	return $wait;
}
function authFailed($scopes) {
	$seconds = authLimits()['seconds'];
	foreach (array_keys($scopes) as $scope) {
		$expired = "window_start < now() - interval $seconds second";
		DB::insert("insert into auth_failures (scope, failures, window_start) values ('".DB::escape($scope)."', 1, now()) on duplicate key update failures = if($expired, 1, failures + 1), window_start = if($expired, now(), window_start)");
	}
}
function authSucceeded($scopes) {
	foreach (array_keys($scopes) as $scope) {
		DB::delete("delete from auth_failures where scope = '".DB::escape($scope)."'");
	}
}
// what whoever has to wait is told
function authWaitMessage($seconds) {
	return '尝试次数过多，请 ' . max(1, (int)ceil($seconds / 60)) . ' 分钟后再试';
}

// What site:tick does for the logins: forgets the tokens of devices not seen for half a year,
// the links to reset a password of more than a week ago, and the failures of more than a day
// ago. Returns how many it forgot.
function authTick() {
	DB::delete("delete from user_remember_tokens where last_used_at < now() - interval 180 day");
	$forgot = DB::affected_rows();
	DB::delete("delete from password_resets where created_at < now() - interval 7 day");
	$forgot += DB::affected_rows();
	DB::delete("delete from auth_failures where window_start < now() - interval 1 day");
	return $forgot + DB::affected_rows();
}
function getPasswordClientSalt() {
	return UOJConfig::$data['security']['user']['client_salt'];
}

function crsf_token() {
	if (!isset($_SESSION['_token'])) {
		$_SESSION['_token'] = uojRandString(60);
	}
	return $_SESSION['_token'];
}
function crsf_check() {
	if (isset($_POST['_token'])) {
		$_token = $_POST['_token'];
	} elseif (isset($_GET['_token'])) {
		$_token = $_GET['_token'];
	} else {
		return false;
	}
	return is_string($_token) && isset($_SESSION['_token']) && hash_equals($_SESSION['_token'], $_token);
}
function crsf_defend() {
	if (!crsf_check()) {
		becomeMsgPage('This page has expired.');
	}
}
