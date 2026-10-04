<?php

// The problems of a contest are lettered in the order their contest puts them in. They used
// to be lettered in the order of their ids; a contest that says nothing still is.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('contests_problems', 'position', 'int(10) unsigned NOT NULL DEFAULT 0');
	} elseif ($type == 'down') {
		Upgrader::dropColumn('contests_problems', 'position');
	}
};
