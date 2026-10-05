-- The files that come with a problem or with a contest: a tool to try a solution with, the
-- statements as one document, a larger sample. The file of an attachment is kept under
-- /var/uoj_data/attachments by the id of its row; what it is called is in the row.
CREATE TABLE IF NOT EXISTS `attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(10) NOT NULL,
  `owner_id` int(10) unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by` varchar(20) NOT NULL,
  `uploaded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner` (`owner_type`,`owner_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
