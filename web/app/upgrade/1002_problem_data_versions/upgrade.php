<?php

return function ($type) {
	if ($type == 'up') {
		// 0 stands for data that was published before versions existed
		Upgrader::addColumn('problems', 'data_version', "int(10) unsigned NOT NULL DEFAULT '0'");
	} else {
		Upgrader::dropColumn('problems', 'data_version');
	}
};
