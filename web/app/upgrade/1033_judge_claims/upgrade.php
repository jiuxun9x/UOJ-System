<?php

// What a judger is given to do is a claim (judge protocol 3). A task that is given out gets a
// random claim, the worker it was given to (several may work for one judging account), and a
// lease that the worker renews while it works. What the worker says of the task (its progress,
// its result, that it gives it back) counts for the task only while the task has that claim, so
// a claim that was taken back and given out again is not reported on by the worker of before. A
// task is taken back when its lease runs out, or when the worker that has it asks for another.
//
// A worker that asks for work says which asking it is (fetch_id): asked again, because the answer
// was lost on the way, it is given the same task under the same claim, and nothing is taken back.
//
// Tasks given out before this upgrade have no lease, and go back to the queue at once.
return function ($type) {
	$tables = array('submissions', 'custom_test_submissions', 'hacks', 'problem_data_versions');
	if ($type == 'up') {
		foreach ($tables as $table) {
			Upgrader::addColumn($table, 'judge_claim', "char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL");
			Upgrader::addColumn($table, 'judge_worker', "varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL");
			Upgrader::addColumn($table, 'judge_lease_until', "datetime DEFAULT NULL");
			Upgrader::addIndex($table, 'judge_claim', '`judge_claim`');
		}
		Upgrader::addColumn('submission_judgements', 'claim', "char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL");
		Upgrader::exec("create table if not exists `judger_workers` (
			`judger_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
			`worker` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`first_seen_at` datetime NOT NULL,
			`last_seen_at` datetime NOT NULL,
			`last_fetch_id` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
			`last_claim` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
			`last_answer` mediumtext COLLATE utf8mb4_unicode_ci,
			PRIMARY KEY (`judger_name`, `worker`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	} else {
		Upgrader::exec("drop table if exists `judger_workers`");
		Upgrader::dropColumn('submission_judgements', 'claim');
		foreach ($tables as $table) {
			Upgrader::dropIndex($table, 'judge_claim');
			Upgrader::dropColumn($table, 'judge_lease_until');
			Upgrader::dropColumn($table, 'judge_worker');
			Upgrader::dropColumn($table, 'judge_claim');
		}
	}
};
