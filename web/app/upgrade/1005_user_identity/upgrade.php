<?php

return function ($type) {
	if ($type == 'up') {
		// The number of a user. The users that exist are numbered in the order they registered.
		if (!Upgrader::columnExists('user_info', 'id')) {
			Upgrader::exec("alter table `user_info` add column `id` int(10) unsigned DEFAULT NULL");
		}
		if (!Upgrader::indexExists('user_info', 'id')) {
			Upgrader::exec("set @uoj_user_id := (select ifnull(max(`id`), 0) from `user_info`)");
			Upgrader::exec("update `user_info` set `id` = (@uoj_user_id := @uoj_user_id + 1) where `id` is null order by `register_time`, `username`");
			Upgrader::exec("alter table `user_info` modify column `id` int(10) unsigned NOT NULL AUTO_INCREMENT, add unique key `id` (`id`)");
		}
		// what a user likes to be called, always shown together with the username
		Upgrader::addColumn('user_info', 'nickname', "varchar(40) NOT NULL DEFAULT ''");
		// The password of a user is hashed with their username. When the username changes, the
		// name the password was hashed with is kept here, so that the password keeps working.
		Upgrader::addColumn('user_info', 'password_salt', 'varchar(20) DEFAULT NULL');
	} else {
		Upgrader::dropColumn('user_info', 'password_salt');
		Upgrader::dropColumn('user_info', 'nickname');
		Upgrader::dropColumn('user_info', 'id');
	}
};
