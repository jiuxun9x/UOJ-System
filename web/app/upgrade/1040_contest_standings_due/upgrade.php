<?php

// A contest whose results are published, one of whose submissions was deleted while something of
// it still waited to be judged: its board is made again once nothing waits any more (see
// contestStandingsAfterDeletion()).
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('contests', 'standings_update_due', "tinyint(1) NOT NULL DEFAULT 0");
	} else {
		Upgrader::dropColumn('contests', 'standings_update_due');
	}
};
