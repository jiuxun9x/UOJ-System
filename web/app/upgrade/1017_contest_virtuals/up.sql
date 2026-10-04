-- Somebody sitting a contest that is over, by themselves, for as long as the contest lasted:
-- from start_time, which may lie in the future when they reserved a time. What they submit
-- to the problems of the contest in that time is what counts for them. One per user and
-- contest; starting again replaces it.
CREATE TABLE IF NOT EXISTS `contest_virtuals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contest_id` int(10) unsigned NOT NULL,
  `username` varchar(20) NOT NULL,
  `start_time` datetime NOT NULL,
  `last_min` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `one` (`contest_id`,`username`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
