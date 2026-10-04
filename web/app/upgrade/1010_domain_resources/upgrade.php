<?php

// What belongs to a domain. Everything that exists stays where it is: a problem or a contest
// without a domain is one of the whole site, as before.
//
// submissions is copied once, by one statement. Run the upgrade while nobody is submitting.
return function ($type) {
	if ($type == 'up') {
		if (!Upgrader::columnExists('problems', 'owner_domain_id')) {
			// where a problem was copied from says where it came from, and nothing more: the
			// copy is a problem of its own
			Upgrader::exec("alter table `problems` add column `owner_domain_id` int(10) unsigned DEFAULT NULL, add column `source_problem_id` int(10) unsigned DEFAULT NULL, add column `source_data_version` int(10) unsigned DEFAULT NULL, add column `imported_at` datetime DEFAULT NULL, add column `imported_by` varchar(20) DEFAULT NULL, add key `owner_domain_id` (`owner_domain_id`, `source_problem_id`)");
		}
		if (!Upgrader::columnExists('contests', 'domain_id')) {
			Upgrader::exec("alter table `contests` add column `domain_id` int(10) unsigned DEFAULT NULL, add key `domain_id` (`domain_id`)");
		}
		if (!Upgrader::columnExists('submissions', 'homework_id')) {
			Upgrader::exec("alter table `submissions` add column `domain_id` int(10) unsigned DEFAULT NULL, add column `homework_id` int(10) unsigned DEFAULT NULL, add key `homework` (`homework_id`, `problem_id`, `submitter`), add key `domain_id` (`domain_id`, `id`)");
		}

		// The custom judgers a system administrator approved, by what they are made of: the
		// fingerprint is a SHA256 over every file the build of the judger can depend on and
		// over problem.conf, and has nothing in it of the problem the files belong to. A copy
		// of a problem may be synced by its teachers exactly when its own files hash to a
		// fingerprint in here.
		Upgrader::exec("create table if not exists `approved_judger_fingerprints` (
			`fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`approved_by` varchar(20) NOT NULL,
			`approved_at` datetime NOT NULL,
			`problem_id` int(10) unsigned NOT NULL,
			PRIMARY KEY (`fingerprint`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		// the judgers that are approved already
		foreach (DB::selectAll("select id, extra_config from problems where extra_config like '%custom_judger_fingerprint%'") as $problem) {
			$extra_config = json_decode($problem['extra_config'], true);
			if (isset($extra_config['custom_judger_fingerprint']) && is_string($extra_config['custom_judger_fingerprint']) && preg_match('/^[0-9a-f]{64}$/', $extra_config['custom_judger_fingerprint'])) {
				Upgrader::exec("insert ignore into `approved_judger_fingerprints` (fingerprint, approved_by, approved_at, problem_id) values ('{$extra_config['custom_judger_fingerprint']}', '', now(), {$problem['id']})");
			}
		}
	} else {
		Upgrader::exec("drop table if exists `approved_judger_fingerprints`");
		if (Upgrader::columnExists('submissions', 'homework_id')) {
			Upgrader::exec("alter table `submissions` drop key `homework`, drop key `domain_id`, drop column `homework_id`, drop column `domain_id`");
		}
		if (Upgrader::columnExists('contests', 'domain_id')) {
			Upgrader::exec("alter table `contests` drop key `domain_id`, drop column `domain_id`");
		}
		if (Upgrader::columnExists('problems', 'owner_domain_id')) {
			Upgrader::exec("alter table `problems` drop key `owner_domain_id`, drop column `imported_by`, drop column `imported_at`, drop column `source_data_version`, drop column `source_problem_id`, drop column `owner_domain_id`");
		}
	}
};
