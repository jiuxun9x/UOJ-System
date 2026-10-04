-- A training is a list of problems of a domain to work through, in the order the teacher put
-- them. Nothing is claimed and nothing is frozen: what counts is whether a problem is solved.
CREATE TABLE IF NOT EXISTS `trainings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL,
  `title` varchar(100) NOT NULL,
  `description_md` mediumtext NOT NULL,
  `description` mediumtext NOT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_by` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A problem of a training is a problem of the domain or a public problem of the site, which
-- is not copied: a training has no scores that a change of the problem could spoil.
CREATE TABLE IF NOT EXISTS `training_problems` (
  `training_id` int(10) unsigned NOT NULL,
  `problem_id` int(10) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL,
  `required` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`training_id`,`problem_id`),
  KEY `problem_id` (`problem_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
