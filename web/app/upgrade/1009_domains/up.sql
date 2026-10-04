-- A domain is the space of a class, a course or a team: its own members, problems, homework,
-- trainings, contests and announcements.
--
-- The owner is a column of the domain, so that there is exactly one. The owner is not listed
-- in domain_members.
--
-- A domain is seen by the people in it and by the administrators of the site, and by nobody
-- else: there is nothing like a public domain. The ways in are being added by somebody who
-- manages it, a roster, and an invitation.
CREATE TABLE IF NOT EXISTS `domains` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'course',
  `owner_username` varchar(20) NOT NULL,
  `created_by` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `owner_username` (`owner_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `domain_members` (
  `domain_id` int(10) unsigned NOT NULL,
  `username` varchar(20) NOT NULL,
  `role` enum('admin','teacher','ta','member') NOT NULL DEFAULT 'member',
  `joined_at` datetime NOT NULL,
  `added_by` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`domain_id`,`username`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Students of a roster who have never logged in have no user yet. They wait here by student
-- number and become members the first time they come through the single sign-on.
CREATE TABLE IF NOT EXISTS `domain_pending_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL,
  `student_id` varchar(64) NOT NULL,
  `role` enum('admin','teacher','ta','member') NOT NULL DEFAULT 'member',
  `created_by` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_student` (`domain_id`,`student_id`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An invitation is a secret whoever holds may join with. Only the SHA256 of the token is kept.
CREATE TABLE IF NOT EXISTS `domain_invites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `label` varchar(50) NOT NULL DEFAULT '',
  `expires_at` datetime DEFAULT NULL,
  `max_uses` int(10) unsigned DEFAULT NULL,
  `uses` int(10) unsigned NOT NULL DEFAULT '0',
  `created_by` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `domain_announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `content_md` mediumtext NOT NULL,
  `content` mediumtext NOT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_id` (`domain_id`,`pinned`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
