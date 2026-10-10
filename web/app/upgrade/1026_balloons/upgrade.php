<?php

// Balloons: in a contest that is held in a room, whoever solves a problem is brought a
// balloon in the colour of the problem. A contest says whether it gives balloons, and
// whether it goes on giving them once its board is frozen; a problem of it has a colour;
// and of every balloon it is written down that it was brought, by whom and when.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('contests', 'balloons', "tinyint(1) NOT NULL DEFAULT 0");
		Upgrader::addColumn('contests', 'balloons_after_freeze', "tinyint(1) NOT NULL DEFAULT 0");
		Upgrader::exec("CREATE TABLE IF NOT EXISTS `contest_balloon_colors` (
			`contest_id` int(10) unsigned NOT NULL,
			`problem_id` int(10) unsigned NOT NULL,
			`color` char(7) NOT NULL,
			`name` varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY (`contest_id`, `problem_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		Upgrader::exec("CREATE TABLE IF NOT EXISTS `contest_balloons` (
			`contest_id` int(10) unsigned NOT NULL,
			`username` varchar(20) NOT NULL,
			`problem_id` int(10) unsigned NOT NULL,
			`done_at` datetime NOT NULL,
			`done_by` varchar(20) NOT NULL,
			PRIMARY KEY (`contest_id`, `username`, `problem_id`),
			KEY `username` (`username`),
			KEY `done_by` (`done_by`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	} else {
		Upgrader::exec("DROP TABLE IF EXISTS `contest_balloons`");
		Upgrader::exec("DROP TABLE IF EXISTS `contest_balloon_colors`");
		Upgrader::dropColumn('contests', 'balloons_after_freeze');
		Upgrader::dropColumn('contests', 'balloons');
	}
};
