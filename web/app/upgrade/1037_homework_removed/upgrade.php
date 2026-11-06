<?php

// Somebody the people of a homework took out of it is kept apart from somebody who stepped back
// from it: who stepped back may claim the homework again, who was taken out may not, but is put
// back by those people. (Who was taken out before is kept as having stepped back: nothing told
// the two apart.)
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("alter table `homework_participants` modify `status` enum('active','withdrawn','removed') NOT NULL DEFAULT 'active'");
	} else {
		Upgrader::exec("update `homework_participants` set `status` = 'withdrawn' where `status` = 'removed'");
		Upgrader::exec("alter table `homework_participants` modify `status` enum('active','withdrawn') NOT NULL DEFAULT 'active'");
	}
};
