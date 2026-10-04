-- Every backup the site made or tried to make of itself. A row that says 'running' while no
-- backup runs is one that was interrupted. A row that says 'requested' is an administrator
-- asking for a backup on a page: the web server may not write where the backups are, so the
-- process that runs every minute takes the request up.
CREATE TABLE IF NOT EXISTS `backup_runs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `reason` varchar(20) NOT NULL,
  `status` enum('requested','running','ok','failed') NOT NULL DEFAULT 'running',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `db_bytes` bigint(20) unsigned DEFAULT NULL,
  `files_count` int(10) unsigned DEFAULT NULL,
  `files_bytes` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `message` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
