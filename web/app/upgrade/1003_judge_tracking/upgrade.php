<?php

// These statements copy the tables, so run the upgrade while nobody is submitting.
return function ($type) {
	$tables = array('submissions', 'custom_test_submissions', 'hacks');
	if ($type == 'up') {
		foreach ($tables as $table) {
			// the judger that is judging the row, and how many judgers in a row gave up on it
			Upgrader::addColumn($table, 'judger_name', 'varchar(50) DEFAULT NULL');
			Upgrader::addColumn($table, 'judge_attempts', "tinyint(3) unsigned NOT NULL DEFAULT '0'");
		}
		// judgers look for work every two seconds, which was a full scan of submissions
		Upgrader::addIndex('submissions', 'status', '`status`, `id`');
		Upgrader::addIndex('custom_test_submissions', 'judge_time', '`judge_time`, `id`');
		Upgrader::addIndex('hacks', 'judge_time', '`judge_time`, `id`');

		Upgrader::addColumn('judger_info', 'enabled', "tinyint(1) NOT NULL DEFAULT '1'");
		Upgrader::addColumn('judger_info', 'last_heartbeat_at', 'datetime DEFAULT NULL');
		Upgrader::addColumn('judger_info', 'version', "varchar(64) NOT NULL DEFAULT ''");
		Upgrader::addColumn('judger_info', 'toolchain', 'text');
	} else {
		foreach (array('toolchain', 'version', 'last_heartbeat_at', 'enabled') as $column) {
			Upgrader::dropColumn('judger_info', $column);
		}
		Upgrader::dropIndex('hacks', 'judge_time');
		Upgrader::dropIndex('custom_test_submissions', 'judge_time');
		Upgrader::dropIndex('submissions', 'status');
		foreach ($tables as $table) {
			Upgrader::dropColumn($table, 'judge_attempts');
			Upgrader::dropColumn($table, 'judger_name');
		}
	}
};
