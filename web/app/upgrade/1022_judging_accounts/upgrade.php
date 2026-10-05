<?php

// A judger works for the site with an account the site gave it. Of an account it is kept who
// made it and what it is for, and how much of the data of the problems its judger holds: a
// judger fetches the data ahead of the submissions and says how far it has got.
return function ($type) {
	$columns = array(
		'note' => "varchar(100) NOT NULL DEFAULT ''",
		'created_by' => "varchar(20) NOT NULL DEFAULT ''",
		'created_at' => 'datetime DEFAULT NULL',
		'data_have' => 'int(10) unsigned DEFAULT NULL',
		'data_total' => 'int(10) unsigned DEFAULT NULL',
		'data_checked_at' => 'datetime DEFAULT NULL'
	);
	if ($type == 'up') {
		foreach ($columns as $column => $definition) {
			Upgrader::addColumn('judger_info', $column, $definition);
		}
		// an account is made without anybody knowing where its judger will run
		Upgrader::exec("alter table `judger_info` modify column `ip` char(20) NOT NULL DEFAULT ''");
		// The accounts are numbered, with numbers of their own that have nothing to do with
		// the numbers of the users: the accounts there are get theirs in the order of their names.
		Upgrader::addColumn('judger_info', 'id', 'int(10) unsigned NOT NULL AUTO_INCREMENT, add unique key `id` (`id`)');
	} else {
		Upgrader::dropColumn('judger_info', 'id');
		foreach (array_reverse(array_keys($columns)) as $column) {
			Upgrader::dropColumn('judger_info', $column);
		}
	}
};
