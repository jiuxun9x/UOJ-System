<?php

// Where a contestant sits during a contest that is held in a room: the people who run the
// contest write it down, and whoever brings a balloon reads it.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('contests_registrants', 'seat', "varchar(40) NOT NULL DEFAULT ''");
	} else {
		Upgrader::dropColumn('contests_registrants', 'seat');
	}
};
