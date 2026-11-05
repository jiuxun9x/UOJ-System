<?php

// The passwords of the users were md5(username . password): a fast hash, which whoever has a
// copy of the database undoes for most passwords in no time. They are kept as Argon2id from now
// on. Every md5 there is is wrapped into Argon2id as it is, so that no password stays bare until
// its user logs in again; the first login keeps it the new way (see passwordMatches()).
//
// A remembered login was a token kept as it was in user_info, one for every device of the user.
// Each device has a token of its own now, of which only a hash is kept: the tokens there are
// are moved over hashed, so that nobody is logged out by the upgrade.
//
// This can not be undone: the code before it accepts neither the hashes nor the tokens.
return function ($type) {
	if ($type != 'up') {
		return;
	}
	Upgrader::exec("alter table `user_info` modify column `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
	Upgrader::addColumn('user_info', 'password_legacy', "tinyint(1) NOT NULL DEFAULT 0 AFTER `password`");

	Upgrader::exec("create table if not exists `user_remember_tokens` (
		`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`username` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
		`token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
		`created_at` datetime NOT NULL,
		`last_used_at` datetime NOT NULL,
		PRIMARY KEY (`id`),
		UNIQUE KEY `token_hash` (`token_hash`),
		KEY `username` (`username`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	if (Upgrader::columnExists('user_info', 'remember_token')) {
		Upgrader::exec("insert ignore into `user_remember_tokens` (`username`, `token_hash`, `created_at`, `last_used_at`) select `username`, sha2(`remember_token`, 256), now(), now() from `user_info` where `remember_token` != ''");
		Upgrader::dropColumn('user_info', 'remember_token');
	}

	$wrapped = 0;
	foreach (DB::selectAll("select `username`, `password` from `user_info` where `password` regexp '^[0-9a-f]{32}$'") as $row) {
		$stored = DB::escape(password_hash($row['password'], PASSWORD_ARGON2ID, passwordHashOptions()));
		Upgrader::exec("update `user_info` set `password` = '$stored', `password_legacy` = 1 where `username` = '".DB::escape($row['username'])."' and `password` = '{$row['password']}'");
		$wrapped++;
	}
	echo "$wrapped password(s) wrapped into Argon2id; ";
};
