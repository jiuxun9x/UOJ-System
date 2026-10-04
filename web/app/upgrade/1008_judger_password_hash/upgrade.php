<?php

// The passwords of the judgers were readable in the database and on the page that lists the
// judgers. Only their hashes are kept from now on. This can not be undone: going down keeps
// the hashes, which the code before this upgrade does not accept, so the judgers would have
// to be given new passwords.
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("alter table `judger_info` modify column `password` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
		Upgrader::exec("update `judger_info` set `password` = concat('sha256:', sha2(`password`, 256)) where `password` not like 'sha256:%'");
	}
};
