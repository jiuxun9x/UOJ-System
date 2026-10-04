-- Homework of a domain. Its members claim it to take part; what they submit to its problems
-- between begin_at and end_at counts, after penalty_since for less. At end_at the scores are
-- settled into a snapshot that nothing changes afterwards.
CREATE TABLE IF NOT EXISTS `homeworks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description_md` mediumtext NOT NULL,
  `description` mediumtext NOT NULL,
  -- 'publishing': the public problems it uses are being copied into the domain
  `status` enum('draft','publishing','published') NOT NULL DEFAULT 'draft',
  `publish_error` text,
  `publish_requested_by` varchar(20) DEFAULT NULL,
  `begin_at` datetime NOT NULL,
  `penalty_since` datetime DEFAULT NULL,
  `end_at` datetime NOT NULL,
  `penalty_rules` text NOT NULL,
  `claim_end_at` datetime DEFAULT NULL,
  `allow_withdraw` tinyint(1) NOT NULL DEFAULT '1',
  -- 'waiting_judgements': end_at has passed, submissions from before it are still being judged
  `settle_state` enum('open','waiting_judgements','settled') NOT NULL DEFAULT 'open',
  `settle_waiting_since` datetime DEFAULT NULL,
  -- the snapshot whose scores are the official ones
  `current_official_snapshot_id` int(10) unsigned DEFAULT NULL,
  `created_by` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_id` (`domain_id`,`begin_at`),
  KEY `settle` (`status`,`settle_state`,`end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Once a homework is published, problem_id is a problem of its domain: a public problem of
-- the site is copied into the domain then, and source_problem_id says which one it was.
CREATE TABLE IF NOT EXISTS `homework_problems` (
  `homework_id` int(10) unsigned NOT NULL,
  `problem_id` int(10) unsigned NOT NULL,
  `source_problem_id` int(10) unsigned DEFAULT NULL,
  `source_data_version` int(10) unsigned DEFAULT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT '0',
  `score` int(10) unsigned NOT NULL DEFAULT '100',
  `required` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`homework_id`,`problem_id`),
  KEY `problem_id` (`problem_id`),
  KEY `source_problem_id` (`source_problem_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `homework_participants` (
  `homework_id` int(10) unsigned NOT NULL,
  `username` varchar(20) NOT NULL,
  `status` enum('active','withdrawn') NOT NULL DEFAULT 'active',
  `claimed_at` datetime NOT NULL,
  `added_by` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`homework_id`,`username`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- members of the domain who look after one homework without teaching in the whole domain
CREATE TABLE IF NOT EXISTS `homework_maintainers` (
  `homework_id` int(10) unsigned NOT NULL,
  `username` varchar(20) NOT NULL,
  PRIMARY KEY (`homework_id`,`username`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The official scores of a homework, settled at a moment and never changed afterwards. A
-- homework that is settled again gets another snapshot; homeworks.current_official_snapshot_id
-- says which one counts. rules_json keeps what the snapshot was computed with: the times, the
-- penalty rules, and every problem with its points and the version of its data.
--
-- active_slot is 1 while a snapshot is being made ('rejudging', 'candidate') and NULL
-- otherwise, so that the unique key allows one at a time for a homework.
CREATE TABLE IF NOT EXISTS `homework_snapshots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `homework_id` int(10) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `status` enum('rejudging','candidate','official','discarded') NOT NULL,
  `active_slot` tinyint(1) DEFAULT NULL,
  `rules_json` mediumtext NOT NULL,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `created_by` varchar(20) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `homework_version` (`homework_id`,`version`),
  UNIQUE KEY `one_active` (`homework_id`,`active_slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- one row for every participant and problem, also where nothing was submitted
CREATE TABLE IF NOT EXISTS `homework_snapshot_scores` (
  `snapshot_id` int(10) unsigned NOT NULL,
  `username` varchar(20) NOT NULL,
  `problem_id` int(10) unsigned NOT NULL,
  `submission_id` int(10) unsigned DEFAULT NULL,
  `raw_score` int(11) NOT NULL DEFAULT '0',
  `multiplier` decimal(5,3) NOT NULL DEFAULT '1.000',
  `score` decimal(8,2) NOT NULL DEFAULT '0.00',
  `data_version` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`snapshot_id`,`username`,`problem_id`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
