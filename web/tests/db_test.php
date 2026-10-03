<?php

require_once __DIR__ . '/../app/models/DB.php';

check_same(['uoj-db', 3306, null], DB::connectParams(['host' => 'uoj-db']), 'remote host with the default port');
check_same(['db.example.com', 3307, null], DB::connectParams(['host' => 'db.example.com', 'port' => '3307']), 'remote host with another port');
check_same(['127.0.0.1', 3306, null], DB::connectParams(['host' => '127.0.0.1', 'port' => 3306]), 'IPv4 address');
check_same(['[::1]', 3306, null], DB::connectParams(['host' => '::1', 'port' => 3306]), 'IPv6 address');
check_same(['[2001:db8::10]', 3307, null], DB::connectParams(['host' => '2001:db8::10', 'port' => 3307]), 'IPv6 address with another port');
check_same(['[::1]', 3306, null], DB::connectParams(['host' => '[::1]']), 'IPv6 address already in brackets');
check_same(['localhost', 3306, '/var/run/mysqld/mysqld.sock'], DB::connectParams(['host' => 'uoj-db', 'socket' => '/var/run/mysqld/mysqld.sock']), 'unix socket');
check_same(['uoj-db', 3306, null], DB::connectParams(['host' => 'uoj-db', 'port' => 3306, 'socket' => '']), 'empty socket');
check_same(['localhost', 3306, null], DB::connectParams([]), 'no host');
