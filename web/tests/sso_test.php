<?php

require_once __DIR__ . '/../app/libs/uoj-validate-lib.php';
require_once __DIR__ . '/../app/libs/uoj-rand-lib.php';
require_once __DIR__ . '/../app/models/UOJHttp.php';
require_once __DIR__ . '/../app/models/UOJSSO.php';
require_once __DIR__ . '/../app/models/UOJSSOCas.php';
require_once __DIR__ . '/../app/models/UOJSSOOAuth2.php';

// returns what $fun throws, or null
function sso_error_of($fun) {
	try {
		$fun();
	} catch (RuntimeException $e) {
		return $e->getMessage();
	}
	return null;
}
function cas_response($inner) {
	return '<cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas">' . $inner . '</cas:serviceResponse>';
}

// ---- what a CAS server answers to a validation
check_same(
	array('user' => 'zhangsan'),
	UOJSSOCas::parseValidation(cas_response('<cas:authenticationSuccess><cas:user> zhangsan </cas:user></cas:authenticationSuccess>')),
	'a CAS 2.0 validation'
);
check_same(
	array('user' => 'zhangsan', 'employeeNumber' => '20240001', 'cn' => '张三 & <李四>', 'memberOf' => 'students'),
	UOJSSOCas::parseValidation(cas_response(
		"<cas:authenticationSuccess>\n<cas:user>zhangsan</cas:user>\n<cas:attributes>"
		. '<cas:employeeNumber>20240001</cas:employeeNumber><cas:cn>张三 &amp; &lt;李四&gt;</cas:cn>'
		. '<cas:memberOf>students</cas:memberOf><cas:memberOf>staff</cas:memberOf>'
		. "</cas:attributes>\n</cas:authenticationSuccess>"
	)),
	'a CAS 3.0 validation with attributes'
);
$refused = array(
	'a failure' => cas_response('<cas:authenticationFailure code="INVALID_TICKET">Ticket not recognized</cas:authenticationFailure>'),
	'a success without a user' => cas_response('<cas:authenticationSuccess><cas:user></cas:user></cas:authenticationSuccess>'),
	'neither success nor failure' => cas_response(''),
	'a success in another namespace' => '<serviceResponse><authenticationSuccess><user>root</user></authenticationSuccess></serviceResponse>',
	'another document' => '<html><body>login</body></html>',
	'no XML at all' => 'yes' . "\n" . 'root',
	'an empty answer' => '',
	'a document type' => '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' . cas_response('<cas:authenticationSuccess><cas:user>&e;</cas:user></cas:authenticationSuccess>'),
);
foreach ($refused as $what => $xml) {
	check_same(true, sso_error_of(function() use ($xml) {
		UOJSSOCas::parseValidation($xml);
	}) !== null, "$what is refused");
}
check_same(true, strpos(sso_error_of(function() use ($refused) {
	UOJSSOCas::parseValidation($refused['a failure']);
}), 'INVALID_TICKET') !== false, 'the code of a failure is reported');

// ---- the browser is sent to the school with a secret of its session
$cas = new UOJSSOCas('cas', array('type' => 'cas', 'server' => 'https://cas.example.edu.cn/cas/'));
$session = array();
$url = $cas->loginUrl('https://oj.example.edu.cn/login/sso/cas/callback', $session);
check_same(32, strlen($session['state']), 'the state of a CAS login');
check_same('https://cas.example.edu.cn/cas/login?service=' . rawurlencode('https://oj.example.edu.cn/login/sso/cas/callback?state=' . $session['state']), $url, 'the address of the CAS login');
$strict = new UOJSSOCas('cas', array('type' => 'cas', 'server' => 'https://cas.example.edu.cn/cas', 'service_state' => false));
$strict_session = array();
check_same('https://cas.example.edu.cn/cas/login?service=' . rawurlencode('https://oj.example.edu.cn/cb'), $strict->loginUrl('https://oj.example.edu.cn/cb', $strict_session), 'a school that only accepts the exact address');

// what comes back is refused before the school is asked, unless it belongs to this session
$cas_refuses = function($query, $session) use ($cas) {
	return sso_error_of(function() use ($cas, $query, $session) {
		$cas->identify('https://oj.example.edu.cn/login/sso/cas/callback', $query, $session);
	}) !== null;
};
check_same(true, $cas_refuses(array('ticket' => 'ST-1', 'state' => $session['state']), array()), 'a ticket without a login that was started');
check_same(true, $cas_refuses(array('ticket' => 'ST-1', 'state' => str_repeat('x', 32)), $session), 'a ticket with the state of another session');
check_same(true, $cas_refuses(array('ticket' => 'ST-1'), $session), 'a ticket without a state');
check_same(true, $cas_refuses(array('ticket' => 'ST-1', 'state' => array($session['state'])), $session), 'a state that is not a string');
check_same(true, $cas_refuses(array('state' => $session['state']), $session), 'no ticket');
check_same(true, $cas_refuses(array('ticket' => "ST-1\n", 'state' => $session['state']), $session), 'a ticket with a line break');
check_same(true, $cas_refuses(array('ticket' => array('ST-1'), 'state' => $session['state']), $session), 'a ticket that is not a string');

// ---- OAuth 2.0
// the example of RFC 7636
check_same('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', UOJSSOOAuth2::pkceChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'), 'the PKCE challenge');

$oauth = new UOJSSOOAuth2('school', array(
	'type' => 'oauth2',
	'client_id' => 'uoj',
	'client_secret' => 'never-shown-to-the-browser',
	'authorize_url' => 'https://id.example.edu.cn/authorize?tenant=1',
	'token_url' => 'https://id.example.edu.cn/token',
	'userinfo_url' => 'https://id.example.edu.cn/userinfo',
	'scope' => 'profile'
));
$session = array();
$url = $oauth->loginUrl('https://oj.example.edu.cn/login/sso/school/callback', $session);
parse_str(parse_url($url, PHP_URL_QUERY), $params);
check_same('https://id.example.edu.cn/authorize?tenant=1&', substr($url, 0, 45), 'the address of the authorization');
check_same(array(
	'tenant' => '1',
	'response_type' => 'code',
	'client_id' => 'uoj',
	'redirect_uri' => 'https://oj.example.edu.cn/login/sso/school/callback',
	'state' => $session['state'],
	'scope' => 'profile',
	'code_challenge' => UOJSSOOAuth2::pkceChallenge($session['verifier']),
	'code_challenge_method' => 'S256'
), $params, 'the parameters of the authorization');
check_same(false, strpos($url, 'never-shown-to-the-browser'), 'the secret of the client stays on the server');
check_same(64, strlen($session['verifier']), 'the PKCE verifier');

$oauth_error = function($query, $session) use ($oauth) {
	return sso_error_of(function() use ($oauth, $query, $session) {
		$oauth->identify('https://oj.example.edu.cn/login/sso/school/callback', $query, $session);
	});
};
check_same(true, $oauth_error(array('code' => 'c', 'state' => $session['state']), array()) !== null, 'a code without a login that was started');
check_same(true, $oauth_error(array('code' => 'c', 'state' => 'wrong'), $session) !== null, 'a code with the state of another session');
check_same(true, $oauth_error(array('code' => 'c'), $session) !== null, 'a code without a state');
check_same(true, strpos($oauth_error(array('error' => 'access_denied', 'state' => $session['state']), $session), 'access_denied') !== false, 'a login the school refused');
check_same(true, $oauth_error(array('state' => $session['state']), $session) !== null, 'no code');
check_same(true, $oauth_error(array('code' => array('c'), 'state' => $session['state']), $session) !== null, 'a code that is not a string');
// a school that refuses parameters it does not know
$plain = new UOJSSOOAuth2('school', array('type' => 'oauth2', 'client_id' => 'uoj', 'client_secret' => 's', 'authorize_url' => 'https://id.example.edu.cn/authorize', 'pkce' => false));
$plain_session = array();
check_same(false, strpos($plain->loginUrl('https://oj.example.edu.cn/cb', $plain_session), 'code_challenge'), 'a login without PKCE');
check_same(false, isset($plain_session['verifier']), 'a login without PKCE has no verifier');

// ---- from what the school reports to an identity
$mapping = array('external_id' => 'user', 'student_id' => 'employeeNumber', 'real_name' => 'cn', 'email' => 'mail');
check_same(
	array('external_id' => 'zhangsan', 'student_id' => '20240001', 'real_name' => '张三', 'email' => 'zhangsan@example.edu.cn', 'username' => '20240001'),
	UOJSSO::identityFromAttributes(array('user' => 'zhangsan', 'employeeNumber' => '20240001', 'cn' => '张三', 'mail' => 'zhangsan@example.edu.cn'), $mapping),
	'the student number becomes the username'
);
check_same(
	array('external_id' => '20240001', 'student_id' => '20240001', 'real_name' => '', 'email' => '', 'username' => '20240001'),
	UOJSSO::identityFromAttributes(array('user' => '20240001', 'mail' => 'not an address'), $mapping),
	'a school that only reports the student number'
);
$nested = array('code' => 0, 'data' => array('uid' => 12345, 'number' => ' 20240002 ', 'name' => array('李四', 'Li Si'), 'login' => 'lisi'));
check_same(
	array('external_id' => '12345', 'student_id' => '20240002', 'real_name' => '李四', 'email' => '', 'username' => 'lisi'),
	UOJSSO::identityFromAttributes($nested, array('external_id' => 'data.uid', 'student_id' => 'data.number', 'real_name' => 'data.name', 'email' => 'data.email', 'username' => 'data.login')),
	'a nested profile, and a username that is not the student number'
);
foreach (array('no identifier' => array('cn' => '张三'), 'an empty identifier' => array('user' => ' '), 'an identifier that is too long' => array('user' => str_repeat('x', 191)), 'an identifier that is no text' => array('user' => array(array('x')))) as $what => $attributes) {
	check_same(true, sso_error_of(function() use ($attributes, $mapping) {
		UOJSSO::identityFromAttributes($attributes, $mapping);
	}) !== null, "$what is refused");
}

// ---- addresses
check_same('https://a/b?x=1&y=a%20b', UOJHttp::addQuery('https://a/b', array('x' => 1, 'y' => 'a b')), 'parameters are added to an address');
check_same('https://a/b?z=0&x=%26', UOJHttp::addQuery('https://a/b?z=0', array('x' => '&')), 'parameters are added to an address that has some');
check_same(true, sso_error_of(function() {
	UOJHttp::get('file:///etc/passwd');
}) !== null, 'only http and https are requested');
