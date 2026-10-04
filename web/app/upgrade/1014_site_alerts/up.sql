-- What is wrong with the site at the moment, and what was: a judger that does not answer,
-- submissions nobody judges, a backup that failed. An alert is open until what it is about is
-- well again. active_slot is 1 while it is open and NULL afterwards, so that the unique key
-- lets one thing have one open alert at a time and any number of closed ones.
CREATE TABLE IF NOT EXISTS `site_alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(40) NOT NULL,
  `subject` varchar(100) NOT NULL DEFAULT '',
  `message` varchar(500) NOT NULL,
  `started_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `active_slot` tinyint(1) DEFAULT NULL,
  `mailed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `active` (`kind`,`subject`,`active_slot`),
  KEY `started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
