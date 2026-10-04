<?php

// The ICPC rule: a contest may freeze its board for its last minutes, and what its standings
// keep of a problem includes how often it was tried in vain before it was solved.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('contests', 'freeze_minutes', 'int(10) unsigned NOT NULL DEFAULT 0');
		Upgrader::addColumn('contests_submissions', 'attempts', 'int(10) unsigned NOT NULL DEFAULT 0');
	} elseif ($type == 'down') {
		Upgrader::dropColumn('contests_submissions', 'attempts');
		Upgrader::dropColumn('contests', 'freeze_minutes');
	}
};
