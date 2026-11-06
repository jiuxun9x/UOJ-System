<?php

// Where the contestants of a contest came from while they took part in it: the addresses of the
// pages of the contest they opened, and of what they submitted to it, while it ran (or while they
// sat it virtually). The page of its members shows the people who run it these, and not where
// each contestant is now, which is nobody's business but the administrators'.
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("create table if not exists `contest_ips` (
			`contest_id` int(10) unsigned NOT NULL,
			`username` varchar(20) NOT NULL,
			`ip` varchar(64) NOT NULL,
			`first_seen_at` datetime NOT NULL,
			`last_seen_at` datetime NOT NULL,
			PRIMARY KEY (`contest_id`, `username`, `ip`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	} else {
		Upgrader::exec("drop table if exists `contest_ips`");
	}
};
