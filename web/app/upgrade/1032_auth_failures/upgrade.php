<?php

// The failures of whoever tries passwords: of a name from an address, of a name, of an address,
// and of a user at the password of a contest. Past a number of them in a quarter of an hour, a
// password is not even looked at until the quarter is over (see authThrottled()).
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("create table if not exists `auth_failures` (
			`scope` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			`failures` int(10) unsigned NOT NULL,
			`window_start` datetime NOT NULL,
			PRIMARY KEY (`scope`),
			KEY `window_start` (`window_start`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	} else {
		Upgrader::exec("drop table if exists `auth_failures`");
	}
};
