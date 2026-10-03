-- One row for every time a judger takes a submission, a custom test or a hack: who judged it,
-- with which data and which tools. A row that never finished was taken back from its judger.
CREATE TABLE IF NOT EXISTS `submission_judgements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('submission','custom_test','hack') NOT NULL,
  `target_id` int(10) unsigned NOT NULL,
  `problem_id` int(10) unsigned NOT NULL,
  `judger_name` varchar(50) NOT NULL,
  `problem_data_version` int(10) unsigned DEFAULT NULL,
  `problem_data_sha256` char(64) DEFAULT NULL,
  `judger_version` varchar(64) DEFAULT NULL,
  `toolchain` text,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `outcome` varchar(20) DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `target` (`kind`,`target_id`),
  KEY `judger` (`judger_name`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
