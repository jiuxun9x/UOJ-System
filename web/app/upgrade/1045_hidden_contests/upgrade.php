<?php

// A contest can be hidden while its problems are tested before it is held: only the site's
// administrators, its staff, the testers it invited and the people on its list see it.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('contests', 'is_hidden', "tinyint(1) NOT NULL DEFAULT '0'");
	} else {
		Upgrader::dropColumn('contests', 'is_hidden');
	}
};
