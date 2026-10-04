-- The list of a contest that only the people on it may take part in. A line of it is a
-- username, or a student number, which lets its student in whatever they are called and
-- whether or not they have logged in yet.
CREATE TABLE IF NOT EXISTS `contest_allowed_users` (
  `contest_id` int(10) unsigned NOT NULL,
  `username` varchar(64) NOT NULL,
  `added_by` varchar(20) NOT NULL,
  `added_at` datetime NOT NULL,
  PRIMARY KEY (`contest_id`,`username`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
