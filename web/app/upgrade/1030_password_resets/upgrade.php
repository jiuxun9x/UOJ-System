<?php

// The links that reset a password. A link was the md5 of the username and the hash of the
// password: it worked until the password changed, however long that was. A link is a random
// token now, of which only the SHA-256 is kept, which works for half an hour and once.
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("create table if not exists `password_resets` (
			`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`username` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
			`token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`created_at` datetime NOT NULL,
			`expires_at` datetime NOT NULL,
			`used_at` datetime DEFAULT NULL,
			`ip` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			PRIMARY KEY (`id`),
			UNIQUE KEY `token_hash` (`token_hash`),
			KEY `username` (`username`, `created_at`),
			KEY `ip` (`ip`, `created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	} else {
		Upgrader::exec("drop table if exists `password_resets`");
	}
};
