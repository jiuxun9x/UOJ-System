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
// What site:tick does for the logins: forgets the tokens of devices not seen for half a year.
// Returns how many things it forgot.
function authTick() {
	DB::delete("delete from user_remember_tokens where last_used_at < now() - interval 180 day");
	return DB::affected_rows();
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
