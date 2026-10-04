-- The roles of the whole site besides the system administrators, who are the users with
-- usergroup 'S' as before: 'oj_admin' and 'teacher'.
CREATE TABLE IF NOT EXISTS `user_roles` (
  `username` varchar(20) NOT NULL,
  `role` varchar(20) NOT NULL,
  `granted_by` varchar(20) NOT NULL,
  `granted_at` datetime NOT NULL,
  PRIMARY KEY (`username`,`role`),
  KEY `role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
