"""Phase 12: the site runs on the PHP 8.3 of Ubuntu and on MySQL 8.4 LTS, as it ran before."""

import unittest

import uoj
from uoj import db_value, docker_exec

# the first PHP 8.3 of Ubuntu 24.04 that fixes CVE-2026-109618 and CVE-2026-109619 (USN-8948-1)
PHP_FIXED = "8.3.6-0ubuntu0.24.04.13"


class RuntimeTest(unittest.TestCase):
    """what the images are made of"""

    def test_web_server_runs_the_php_of_ubuntu_that_has_the_fix(self):
        self.assertTrue(docker_exec(uoj.WEB, "php -r 'echo PHP_VERSION;'").startswith("8.3."))
        version = docker_exec(uoj.WEB, "dpkg-query -W -f='${Version}' php8.3-common libapache2-mod-php8.3 | head -c 200")
        self.assertIn("ubuntu", version)
        self.assertEqual(docker_exec(uoj.WEB, "dpkg --compare-versions \"$(dpkg-query -W -f='${Version}' php8.3-common)\" ge %s && echo fixed" % PHP_FIXED).strip(), "fixed")
        # nothing of the PPA of PHP 7.4 is left
        self.assertEqual(docker_exec(uoj.WEB, "dpkg -l | grep -c ' php7' || true").strip(), "0")
        self.assertEqual(docker_exec(uoj.WEB, "ls /etc/apt/sources.list.d | grep -c ondrej || true").strip(), "0")
        # the extensions the site uses are there for the pages and for the command line
        for extension in ("yaml", "mysqli", "mbstring", "gd", "intl", "zip", "xsl", "dom"):
            self.assertEqual(docker_exec(uoj.WEB, "php -r 'echo extension_loaded(\"%s\") ? 1 : 0;'" % extension), "1", extension)

    def test_command_line_keeps_the_time_of_the_site(self):
        # PHP reads its time zone from date.timezone only: the command line, which publishes the
        # homeworks and starts the backups, is in the time of the pages and of the database
        self.assertEqual(docker_exec(uoj.WEB, "php -r 'echo date_default_timezone_get();'"), "Asia/Shanghai")
        cli = docker_exec(uoj.WEB, "php -r 'echo date(\"Y-m-d H:i\");'").strip()
        database = db_value("select date_format(now(), '%Y-%m-%d %H:%i')")
        self.assertEqual(cli[:13], database[:13], (cli, database))

    def test_database_is_mysql_84_as_the_site_had_5_7(self):
        self.assertTrue(db_value("select version()").startswith("8.4."))
        self.assertEqual(db_value("select @@character_set_server"), "utf8mb4")
        self.assertEqual(db_value("select @@collation_server"), "utf8mb4_unicode_ci")
        self.assertEqual(db_value("select @@time_zone"), "+08:00")
        # no binary log and no X protocol, as with 5.7
        self.assertEqual(db_value("select @@log_bin"), "0")
        self.assertEqual(db_value("select count(*) from information_schema.plugins where plugin_name = 'mysqlx' and plugin_status = 'ACTIVE'"), "0")
        # the accounts log in the way of 8.4, and the tables keep their collation
        self.assertEqual(db_value("select group_concat(distinct plugin) from mysql.user where user = 'root'"), "caching_sha2_password")
        self.assertEqual(db_value("select count(*) from information_schema.tables where table_schema = 'app_uoj233' and table_collation not like 'utf8mb4%'"), "0")

    def test_text_of_four_bytes_goes_in_and_comes_out(self):
        # the site says utf8mb4 when it connects, now that MySQL lets a client choose
        student = uoj.client(("p12_emoji", "p12-password"))
        motto = "四个字节的字 😀𠀀"
        self.assertEqual(student.update_profile(motto=motto), "ok")
        self.assertEqual(db_value("select motto from user_info where username = 'p12_emoji'"), motto)
        self.assertIn(motto, student.get("/user/profile/p12_emoji").text)
