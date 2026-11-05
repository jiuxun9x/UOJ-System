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
	return true;
}

// What site:tick does for the logins: forgets the tokens of devices not seen for half a year,
// and the links to reset a password of more than a week ago. Returns how many it forgot.
function authTick() {
	DB::delete("delete from user_remember_tokens where last_used_at < now() - interval 180 day");
	$forgot = DB::affected_rows();
	DB::delete("delete from password_resets where created_at < now() - interval 7 day");
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
