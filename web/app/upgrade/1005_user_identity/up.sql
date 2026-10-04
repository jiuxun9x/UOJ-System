-- Every change of a username. A change is journaled before it starts, so that one that was
-- interrupted is finished later, and the names users gave up are not handed to somebody else.
CREATE TABLE IF NOT EXISTS `user_renames` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `old_username` varchar(20) NOT NULL,
  `new_username` varchar(20) NOT NULL,
  `renamed_by` varchar(20) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `old_username` (`old_username`),
  KEY `new_username` (`new_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
