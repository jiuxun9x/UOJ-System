-- What the system administrators switch on and off for the whole site while it runs. The
-- settings there are, with what they are when nobody has touched them, are listed in
-- siteSettings() in web/app/libs/uoj-permission-lib.php.
CREATE TABLE IF NOT EXISTS `site_settings` (
  `name` varchar(64) NOT NULL,
  `value` varchar(255) NOT NULL,
  `updated_by` varchar(20) NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
