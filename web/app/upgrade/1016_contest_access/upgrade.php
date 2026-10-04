<?php

// Who may take part in a contest: everybody, the people on a list, or whoever knows a
// password. The password is kept as a hash.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('contests', 'join_mode', "enum('open','list','password') NOT NULL DEFAULT 'open'");
		Upgrader::addColumn('contests', 'join_password', "varchar(100) NOT NULL DEFAULT ''");
	} elseif ($type == 'down') {
		foreach (array('join_mode', 'join_password') as $column) {
			Upgrader::dropColumn('contests', $column);
		}
	}
};
