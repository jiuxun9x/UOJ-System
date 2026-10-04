<?php

require_once __DIR__ . '/../app/libs/uoj-domain-lib.php';

// ---- an invitation is a secret: long, random, and kept only as a hash
$tokens = array();
for ($i = 0; $i < 200; $i++) {
	$tokens[] = domainNewInviteToken();
}
check_same(200, count(array_unique($tokens)), 'tokens do not repeat');
check_same(true, preg_match('/^[A-Za-z0-9_-]{24}$/', $tokens[0]) === 1, 'a token is 144 bits in 24 characters that are safe in an address');
check_same(true, validateDomainInviteToken($tokens[0]), 'a token is accepted as one');
check_same(64, strlen(domainInviteTokenHash($tokens[0])), 'what is stored is a SHA256');
check_same(hash('sha256', $tokens[0]), domainInviteTokenHash($tokens[0]), 'the hash of a token');
check_same(false, strpos(domainInviteTokenHash($tokens[0]), $tokens[0]), 'the hash does not contain the token');
foreach (array('', 'short', str_repeat('a', 21), str_repeat('a', 65), "abc def ghi jkl mno pqr st", "aaaaaaaaaaaaaaaaaaaaaaaa'", str_repeat('好', 24), array(str_repeat('a', 24)), null) as $bad) {
	check_same(false, validateDomainInviteToken($bad), 'refused as a token: ' . json_encode($bad));
}
check_same(true, isset(domainInviteLifetimes()[0]) && isset(domainInviteLifetimes()[168]), 'an invitation lasts a week, or for ever');

// ---- names of roles
check_same('所有者', domainRoleName('owner'), 'the owner has a name, though it is no role of a member');
check_same(false, isset(domainMemberRoles()['owner']), 'owner is not among the roles of members');
check_same(array(5, 4, 3, 2, 1, 0), array_map('domainRoleRank', array('owner', 'admin', 'teacher', 'ta', 'member', null)), 'the order of the roles');

// ---- the number of a problem: its id on the site, its own count inside a domain
require_once __DIR__ . '/../app/libs/uoj-query-lib.php';
check_same(12, problemNumber(array('id' => '12', 'owner_domain_id' => null, 'domain_pid' => null)), 'a problem of the site is known by its id');
check_same(3, problemNumber(array('id' => '1000007', 'owner_domain_id' => '4', 'domain_pid' => '3')), 'a problem of a domain by its number there');
check_same(1000007, problemNumber(array('id' => '1000007', 'owner_domain_id' => '4', 'domain_pid' => null)), 'and by its id until it is given one');
check_same(1000007, problemNumber(array('id' => '1000007', 'owner_domain_id' => '4')), 'or where the number was not asked for');
check_same('#12', problemLabel(array('id' => '12', 'owner_domain_id' => null)), 'the label of a problem of the site');
check_same('/problem/12/manage/data', problemUrl(array('id' => '12', 'owner_domain_id' => null), '/manage/data'), 'the address of a page of a problem of the site');
check_same(true, UOJ_DOMAIN_PROBLEM_ID_BASE >= 1000000, 'the ids of the problems of domains start far above those of the site');

