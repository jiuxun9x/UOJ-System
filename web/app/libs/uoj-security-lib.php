<?php

function getPasswordToStore($password, $username) {
	return md5($username . $password);
}
// The password is hashed with the username the user had when they set it.
function checkPassword($user, $password) {
	$salt = isset($user['password_salt']) ? $user['password_salt'] : $user['username'];
	return is_string($password) && hash_equals($user['password'], md5($salt . $password));
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
	$stored = getPasswordToStore($password, $username);
	return DB::update("update user_info set password = '$stored', password_salt = null where username = '".DB::escape($username)."'");
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
	return $_token === $_SESSION['_token'];
}
function crsf_defend() {
	if (!crsf_check()) {
		becomeMsgPage('This page has expired.');
	}
}
