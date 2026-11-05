<?php

// Passwords: kept as Argon2id; the md5 of before, wrapped into Argon2id by the upgrade 1029 or
// bare, still lets its user in, once, and is to be kept the new way then.

require_once __DIR__ . '/../app/libs/uoj-rand-lib.php';
require_once __DIR__ . '/../app/libs/uoj-security-lib.php';

if (defined('PASSWORD_ARGON2ID')) {
	$typed = 'c4ca4238a0b923820dcc509a6f75849b';
	$user = array('username' => 'ann', 'password_salt' => null, 'password_legacy' => 0, 'password' => passwordHashToStore($typed));
	check_same(true, strncmp($user['password'], '$argon2id$', 10) === 0, 'a password is kept as Argon2id');
	check_same(array(true, false), passwordMatches($user, $typed), 'the password lets its user in, and is kept as it should be');
	check_same(array(false, false), passwordMatches($user, $typed . 'x'), 'another password does not');
	check_same(array(false, false), passwordMatches($user, null), 'no password does not');

	// the md5 of before, as the upgrade wrapped it: the salt is the name the user had then
	$legacy = array('username' => 'bob', 'password_salt' => null, 'password_legacy' => 1, 'password' => password_hash(md5('bob' . $typed), PASSWORD_ARGON2ID, passwordHashOptions()));
	check_same(array(true, true), passwordMatches($legacy, $typed), 'a wrapped md5 lets its user in, and is kept anew');
	check_same(array(false, false), passwordMatches($legacy, 'x' . $typed), 'a wrapped md5 keeps others out');
	$renamed = array('username' => 'bobby', 'password_salt' => 'bob') + $legacy;
	check_same(array(true, true), passwordMatches($renamed, $typed), 'a wrapped md5 of a user who was renamed has the old name as its salt');

	// an md5 that no upgrade wrapped, put back from an old backup
	$bare = array('username' => 'cat', 'password_salt' => null, 'password_legacy' => 0, 'password' => md5('cat' . $typed));
	check_same(array(true, true), passwordMatches($bare, $typed), 'a bare md5 lets its user in, and is kept anew');
	check_same(array(false, false), passwordMatches($bare, $typed . ' '), 'a bare md5 keeps others out');

	// a hash made at a lower cost is made anew at the cost of now
	$cheap = array('password' => password_hash($typed, PASSWORD_ARGON2ID, array('memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1))) + $user;
	check_same(array(true, true), passwordMatches($cheap, $typed), 'a hash that costs less than it should is kept anew');
}

// a user of the single sign-on has no password that any text is
$sso = array('username' => 'dan', 'password_salt' => null, 'password_legacy' => 0, 'password' => unusablePassword());
check_same(false, hasUsablePassword($sso), 'a user of the single sign-on has no usable password');
check_same(array(false, false), passwordMatches($sso, substr($sso['password'], 1)), 'nothing matches the password of a user of the single sign-on');
check_same(array(false, false), passwordMatches(array('password' => '') + $sso, ''), 'nothing matches an empty password');

// ---- links to reset a password: one a minute and five a day for a user, twenty an hour for an
// address
check_same(false, passwordResetTooMany(null, 0, 0), 'the first link');
check_same(true, passwordResetTooMany(59, 1, 1), 'a link within a minute of the last');
check_same(false, passwordResetTooMany(60, 1, 1), 'a link a minute after the last');
check_same(true, passwordResetTooMany(3600, 5, 5), 'the sixth link of a day');
check_same(false, passwordResetTooMany(3600, 4, 19), 'the fifth link of a day');
check_same(true, passwordResetTooMany(null, 0, 20), 'the twenty-first link from an address in an hour');
