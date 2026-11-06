<?php

// A judger that waits for work (wait=<seconds>) holds a process of the web server and a
// connection to the database while it waits. Until when each worker waits is kept, so that no
// more than judger.long-poll-max-waiting of them wait at the same time.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('judger_workers', 'waiting_until', "datetime DEFAULT NULL");
	} else {
		Upgrader::dropColumn('judger_workers', 'waiting_until');
	}
};
