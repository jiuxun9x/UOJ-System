<?php

// An alert whose trouble is over stays open a while (alert.resolve_after_minutes) before it is
// told to be over: clear_since is when the trouble was last found over, and a trouble that comes
// back meanwhile goes on in the same alert, which counts how often it came (interruptions).
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('site_alerts', 'clear_since', "datetime DEFAULT NULL");
		Upgrader::addColumn('site_alerts', 'interruptions', "int(10) unsigned NOT NULL DEFAULT 1");
	} else {
		Upgrader::dropColumn('site_alerts', 'interruptions');
		Upgrader::dropColumn('site_alerts', 'clear_since');
	}
};
