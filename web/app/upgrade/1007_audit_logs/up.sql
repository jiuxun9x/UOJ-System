-- Who changed what, when and from where. The actor is kept by number as well: a username can
-- change, and the name a user gives up can come to mean somebody else.
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor` varchar(20) NOT NULL DEFAULT '',
  `actor_id` int(10) unsigned DEFAULT NULL,
  `actor_type` varchar(10) NOT NULL,
  `action` varchar(50) NOT NULL,
  `resource_type` varchar(20) NOT NULL,
  `resource_id` varchar(40) NOT NULL,
  `before_json` text,
  `after_json` text,
  `ip` varchar(50) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `resource` (`resource_type`,`resource_id`,`created_at`),
  KEY `actor` (`actor`,`created_at`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
