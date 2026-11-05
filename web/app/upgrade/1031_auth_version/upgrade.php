<?php

// The logins of a user end when the password is changed or reset, or the user is banned: the
// session of a login keeps the auth_version the user had when they logged in, and is a login no
// more once the version has gone up. A session of before has none, which is 0, as is everybody.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('user_info', 'auth_version', "int(10) unsigned NOT NULL DEFAULT 0");
	} else {
		Upgrader::dropColumn('user_info', 'auth_version');
	}
};
