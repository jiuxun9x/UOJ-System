-- Every sync of the data of a problem makes a new version. A version is published once a
-- judger has built the programs it comes with, or at once when there is nothing to build.
CREATE TABLE IF NOT EXISTS `problem_data_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `problem_id` int(10) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `status` enum('pending','preparing','ready','failed') NOT NULL,
  `sha256` char(64) NOT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `manifest` mediumtext NOT NULL,
  `prepare` text NOT NULL,
  `pending` mediumtext,
  `message` text,
  `created_at` datetime NOT NULL,
  `created_by` varchar(20) NOT NULL,
  `reason` varchar(50) NOT NULL,
  `judger_name` varchar(50) DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `problem_version` (`problem_id`,`version`),
  KEY `status` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
