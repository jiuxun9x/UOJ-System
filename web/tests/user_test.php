<?php

require_once __DIR__ . '/../app/libs/uoj-rand-lib.php';
require_once __DIR__ . '/../app/libs/uoj-security-lib.php';
require_once __DIR__ . '/../app/libs/uoj-user-lib.php';

// ---- nicknames
foreach (array('', '小明', 'Alice Liddell', '张三_2024', str_repeat('好', 20), '007') as $nickname) {
	check_same(true, validateNickname($nickname), "the nickname '$nickname' is accepted");
}
$refused = array(
	'too long' => str_repeat('好', 21),
	'markup' => '<b>bold</b>',
	'an entity' => 'a&amp;b',
	'a quote' => 'say "hi"',
	'an apostrophe' => "it's",
	'a mention' => '@root',
	'something that passes for a username behind it' => '张三（20240001）',
	'ASCII parentheses' => 'root (admin)',
	'a backslash' => 'a\\b',
	'a line break' => "a\nb",
	'spaces around it' => ' padded ',
	'broken UTF-8' => "\xff\xfe",
	'not a string' => array('x'),
);
foreach ($refused as $what => $nickname) {
	check_same(false, validateNickname($nickname), "a nickname with $what is refused");
}

// ---- a nickname never stands alone
check_same('20240001', userDisplayName(array('username' => '20240001', 'nickname' => '')), 'a user without a nickname');
check_same('小明（20240001）', userDisplayName(array('username' => '20240001', 'nickname' => '小明')), 'a user with a nickname');

// ---- the password keeps working when the username changes
$client_hash = md5('what the browser sends');
$user = array('username' => 'alice', 'password' => getPasswordToStore($client_hash, 'alice'), 'password_salt' => null);
check_same(true, checkPassword($user, $client_hash), 'the password of a user');
check_same(false, checkPassword($user, md5('something else')), 'a wrong password');
$renamed = array('username' => 'alice2', 'password_salt' => 'alice') + $user;
check_same(true, checkPassword($renamed, $client_hash), 'the password after the username changed');
check_same(false, checkPassword(array('password_salt' => null) + $renamed, $client_hash), 'the password without the name it was hashed with');
check_same(false, checkPassword($user, array('x')), 'a password that is not a string');

// ---- a user without a password can not log in with one
$sso_user = array('username' => '20240001', 'password' => unusablePassword(), 'password_salt' => null);
check_same(32, strlen($sso_user['password']), 'the placeholder fits the column');
check_same(false, hasUsablePassword($sso_user), 'a user of the single sign-on has no password');
check_same(true, hasUsablePassword($user), 'a local user has one');
check_same(false, hasUsablePassword(array('password' => '')), 'an empty password is none');
check_same(false, checkPassword($sso_user, $sso_user['password']), 'the placeholder is not a password');
check_same(false, $sso_user['password'] === unusablePassword(), 'the placeholder is random');

// A username that is all digits, as a student number is, becomes an integer when it is the
// key of an array. It has to be taken for the name it is.
$by_name = array('20260101' => 1, 'alice' => 2);
foreach ($by_name as $name => $ignored) {
	check_same(true, validateUsername($name), 'a username that was the key of an array: ' . json_encode($name));
}
check_same(true, is_int(array_keys($by_name)[0]), 'which PHP did turn into a number');
foreach (array('', 'a b', str_repeat('1', 21), -5, 1.5, null, array('x')) as $bad) {
	check_same(false, validateUsername($bad), 'refused as a username: ' . json_encode($bad));
}

