<?php

// The numbers of the problems that were deleted. A number that was a problem's once is not
// given to another problem: links, notes and what people remember of "problem 12" must not
// come to mean something else.
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("CREATE TABLE IF NOT EXISTS `problems_deleted` (
			`id` int(10) unsigned NOT NULL,
			`owner_domain_id` int(10) unsigned DEFAULT NULL,
			`domain_pid` int(10) unsigned DEFAULT NULL,
			`deleted_at` datetime NOT NULL,
			PRIMARY KEY (`id`),
			KEY `domain` (`owner_domain_id`, `domain_pid`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	} else {
		Upgrader::exec("DROP TABLE IF EXISTS `problems_deleted`");
	}
};
