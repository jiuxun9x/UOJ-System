#!/bin/bash

setLAMPConf(){
    printf "\n\n==> Setting LAMP configs\n"
    #Set MySQL connection config
    cat >/etc/mysql/conf.d/uoj_mysqld.cnf <<UOJEOF
[mysqld]
default-time-zone='+8:00'
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
# MySQL 8.4 has no skip-character-set-client-handshake: the site says utf8mb4 and the
# collation of its tables itself when it connects, and so does any user who is not an
# administrator through this (init_connect is not run for administrators)
init_connect='SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'
max_allowed_packet=64M
sql-mode=ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
# as MySQL 5.7 had it: no binary log (8.4 keeps one for 30 days, and every judgement writes to
# it; the backups of the site are dumps), and no X protocol, which nothing here speaks
disable-log-bin
mysqlx=OFF
UOJEOF
}

setWebConf(){
    printf "\n\n==> Setting web files\n"
    # Import MySQL database. The accounts keep the authentication of MySQL 8.4,
    # caching_sha2_password: mysql_native_password is not even loaded there.
    cp app_uoj233.sql /docker-entrypoint-initdb.d/001-app_uoj233.sql
    cp add_judger.sql /docker-entrypoint-initdb.d/002-add_judger.sql
}

echo 'Preparing UOJ System db environment...'
setLAMPConf;setWebConf
