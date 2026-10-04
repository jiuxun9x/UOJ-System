-- Who a user is for a provider of the single sign-on. The identity a provider reports is kept
-- apart from the user: it is neither their number nor, by itself, their username.
CREATE TABLE IF NOT EXISTS `external_identities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(20) NOT NULL,
  `external_id` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `username` varchar(20) NOT NULL,
  `student_id` varchar(64) NOT NULL DEFAULT '',
  `real_name` varchar(100) NOT NULL DEFAULT '',
  `email` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `identity` (`provider`,`external_id`),
  UNIQUE KEY `user` (`provider`,`username`),
  KEY `username` (`username`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
