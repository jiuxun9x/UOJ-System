<?php

// A contest or a homework can be told to show its problems when it is over. Of each it is
// kept whether it was told to, and when it did: what was shown once is not shown again, so a
// problem that somebody hid afterwards stays hidden.
return function ($type) {
	$columns = array(
		'reveal_problems' => "tinyint(1) NOT NULL DEFAULT '0'",
		'problems_revealed_at' => 'datetime DEFAULT NULL'
	);
	foreach (array('contests', 'homeworks') as $table) {
		foreach ($columns as $column => $definition) {
			if ($type == 'up') {
				Upgrader::addColumn($table, $column, $definition);
			} else {
				Upgrader::dropColumn($table, $column);
			}
		}
	}
};
