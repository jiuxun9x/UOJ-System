<?php

return function ($type) {
	if ($type == 'up') {
		// The people in contests_permissions could look behind the scenes of their contest, but
		// only a super user could manage it. They keep exactly that: they become assistants.
		Upgrader::addColumn('contests_permissions', 'role', "varchar(20) NOT NULL DEFAULT 'assistant'");
	} else {
		Upgrader::dropColumn('contests_permissions', 'role');
	}
};
