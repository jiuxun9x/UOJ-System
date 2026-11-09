<?php

// The answers of the tests of a problem that were not given, made by a judger with the standard
// program of the problem (a package of Polygon comes with the inputs and its main solution, and
// Polygon makes the answers with that). A job is given out to a judger like any task, under a
// claim with a lease; the judger runs the program on the inputs it is told of, and sends what it
// wrote back. Nothing of it is written unless it ran well on every one of them.
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("create table if not exists `problem_answer_jobs` (
			`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`problem_id` int(10) unsigned NOT NULL,
			`status` enum('pending','running','done','failed') NOT NULL DEFAULT 'pending',
			`overwrite` tinyint(1) NOT NULL DEFAULT '0',
			`tests` mediumtext NOT NULL,
			`limits` varchar(255) NOT NULL,
			`sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`message` mediumtext,
			`created_at` datetime NOT NULL,
			`created_by` varchar(20) NOT NULL,
			`finished_at` datetime DEFAULT NULL,
			`judger_name` varchar(50) DEFAULT NULL,
			`claimed_at` datetime DEFAULT NULL,
			`attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
			`judge_claim` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
			`judge_worker` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
			`judge_lease_until` datetime DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `problem_id` (`problem_id`, `id`),
			KEY `status` (`status`, `id`),
			KEY `judge_claim` (`judge_claim`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	} else {
		Upgrader::exec("drop table if exists `problem_answer_jobs`");
	}
};
