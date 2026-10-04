<?php

require_once __DIR__ . '/../app/libs/uoj-validate-lib.php';
if (!class_exists('UOJConfig')) {
	// the configuration, without the file it is read from
	class UOJConfig {
		public static $data = array();
	}
}
require_once __DIR__ . '/../app/models/UOJContext.php';

// ---- addresses and networks
$in_range = array(
	array('10.1.2.3', '10.0.0.0/8'), array('10.1.2.3', '10.1.2.3'), array('172.18.0.1', '172.16.0.0/12'),
	array('192.168.1.130', '192.168.1.128/25'), array('1.2.3.4', '0.0.0.0/0'),
	array('::1', '::1'), array('fd00::1234', 'fd00::/8'), array('2001:db8::1', '2001:db8::/32'),
);
foreach ($in_range as $case) {
	check_same(true, UOJContext::ipInRanges($case[0], array('203.0.113.9', $case[1])), "{$case[0]} is in {$case[1]}");
}
$not_in_range = array(
	array('11.1.2.3', '10.0.0.0/8'), array('10.1.2.4', '10.1.2.3'), array('172.32.0.1', '172.16.0.0/12'),
	array('192.168.1.127', '192.168.1.128/25'), array('::2', '::1'), array('fe00::1', 'fd00::/8'),
	// an IPv4 address is in no IPv6 network, and nonsense is in nothing
	array('10.1.2.3', '::/0'), array('::1', '0.0.0.0/0'), array('10.1.2.3', '10.0.0.0/33'), array('10.1.2.3', '10.0.0.0/x'),
	array('10.1.2.3', 'everything'), array('not an address', '0.0.0.0/0'), array('', '0.0.0.0/0'),
);
foreach ($not_in_range as $case) {
	check_same(false, UOJContext::ipInRanges($case[0], array($case[1])), "{$case[0]} is not in {$case[1]}");
}
check_same(false, UOJContext::ipInRanges('10.1.2.3', array()), 'no proxies are trusted by default');

// ---- a request that says it was forwarded
$saved_server = $_SERVER;
UOJConfig::$data = array('web' => array('main' => array('protocol' => 'http', 'host' => 'oj.example.edu.cn', 'port' => 80)));
$_SERVER = array(
	'REMOTE_ADDR' => '198.51.100.7',
	'HTTP_HOST' => 'oj.example.edu.cn',
	'HTTP_X_FORWARDED_HOST' => 'evil.example',
	'HTTP_X_FORWARDED_PROTO' => 'https',
	'HTTP_X_FORWARDED_FOR' => '203.0.113.5'
);
check_same('oj.example.edu.cn', UOJContext::httpHost(), 'the forwarded host of a stranger is ignored');
check_same('198.51.100.7', UOJContext::remoteAddr(), 'the forwarded address of a stranger is ignored');
check_same(false, UOJContext::isHttps(), 'the forwarded protocol of a stranger is ignored');
check_same(false, UOJContext::isSecureSite(), 'a site visited over http');

// ---- the same headers from our own reverse proxy
UOJConfig::$data['web']['trusted-proxies'] = array('172.18.0.0/16', '10.0.0.1');
$_SERVER['REMOTE_ADDR'] = '172.18.0.2';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'oj.example.edu.cn, internal';
$_SERVER['HTTP_HOST'] = 'uoj-web';
check_same('oj.example.edu.cn', UOJContext::httpHost(), 'the forwarded host of our proxy is used');
check_same(true, UOJContext::isHttps(), 'the forwarded protocol of our proxy is used');
check_same(true, UOJContext::isSecureSite(), 'a site visited over https through our proxy');
check_same('203.0.113.5', UOJContext::remoteAddr(), 'the client behind our proxy');
// the client can write anything in front of what our proxies add
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.5, 10.0.0.1';
check_same('203.0.113.5', UOJContext::remoteAddr(), 'the last address that is not one of our proxies');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not an address';
check_same('172.18.0.2', UOJContext::remoteAddr(), 'a forwarded address that is none');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
check_same(false, UOJContext::isHttps(), 'http through our proxy');
UOJConfig::$data['web']['main']['protocol'] = 'https';
check_same(true, UOJContext::isSecureSite(), 'a site that is configured for https');

$_SERVER = $saved_server;
