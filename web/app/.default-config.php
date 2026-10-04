<?php
return [
	'profile' => [
		'oj-name' => 'Universal Online Judge',
		'oj-name-short' => 'UOJ',
		'administrator' => 'root',
		'admin-email' => 'admin@local_uoj.ac',
		'QQ-group' => '',
		'ICP-license' => ''
	],
	'database' => [
		'database' => 'app_uoj233',
		'username' => 'root',
		'password' => 'root',
		// write an IPv6 address without brackets
		'host' => 'uoj-db',
		'port' => 3306,
		// set the path of a unix socket to connect through it instead of host and port
		'socket' => ''
	],
	'web' => [
		'domain' => null,
		// The addresses or networks of the reverse proxies in front of this server, like
		// ['172.18.0.0/16']. X-Forwarded-Host, -Proto and -For are believed from them only.
		'trusted-proxies' => [],
		'main' => [
			'protocol' => 'http',
			'host' => '_httpHost_',
			'port' => 80
		],
		'blog' => [
			'protocol' => 'http',
			'host' => '_httpHost_',
			'port' => 80
		]
	],
	'security' => [
		'user' => [
			'client_salt' => 'salt0'
		],
		'cookie' => [
			'checksum_salt' => ['salt1', 'salt2', 'salt3']
		],
	],
	'mail' => [
		'noreply' => [
			'username' => 'noreply@local_uoj.ac',
			'password' => '_mail_noreply_password_',
			'host' => 'smtp.local_uoj.ac',
			'secure' => 'tls',
			'port' => 587
		]
	],
	'judger' => [
		'socket' => [
			'port' => '233',
			'password' => '_judger_socket_password_'
		],
		// seconds without a sign of life after which the tasks of a judger are given to others
		'task-timeout' => 300
	],
	'user' => [
		// days a user has to wait before they change their username again, 0 for no limit
		'username-change-interval' => 30
	],
	'sso' => [
		// the providers of the single sign-on of the school, see docs/sso.md
		'providers' => [],
		// Usernames that only the single sign-on hands out, so that nobody registers a name
		// that looks like a student number before its student arrives. It applies once a
		// provider is configured; '' turns it off. The student numbers of the school are two
		// capital letters and eight digits, as CS26010001 is. Small letters are kept as well:
		// usernames do not differ by their case.
		'reserved-username-pattern' => '/^[A-Za-z]{2}[0-9]{8}$/'
	],
	'homework' => [
		// Seconds the settlement of a homework waits for submissions from before its end that
		// are not judged yet. After that it goes on without them, and says so.
		'settle-grace' => 1800
	],
	'data' => [
		// how many versions of the data of a problem keep their archive, the current one included
		'kept-versions' => 5
	],
	'switch' => [
		// 请在 page-header.php 中修改统计代码后再启用
		'web-analytics' => false,
		'blog-domain-mode' => 3
	],
	'tools' => [
		// 请仅在https下启用以下功能.
		// 非https下, chrome无法进行复制.
		'map-copy-enabled' => false,
	]
];
