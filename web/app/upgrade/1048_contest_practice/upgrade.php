<?php

// What is submitted through a contest that is over - to practise on its problems, or while
// sitting it virtually - was submitted to the problems and to nothing else: it was not with the
// contest, and to a problem the contest keeps hidden it was nobody's but its owner's. It is listed
// with the contest now, and counted on no board: practice_contest_id names the contest it was
// submitted through (contest_id is still the contest a submission counts in), and is_virtual says
// it was submitted while its submitter sat the contest virtually.
//
// What was submitted in a virtual participation before there were the columns is found by the time
// it was submitted at, as the boards of virtual participations find it. What was submitted to
// practise is not told apart from what was submitted to the problems themselves, and stays as it is.
//
// The table submissions gets an index: run the upgrade while nobody is submitting.
return function ($type) {
	if ($type == 'up') {
		if (!Upgrader::columnExists('submissions', 'practice_contest_id')) {
			Upgrader::exec("alter table `submissions` add column `practice_contest_id` int(10) unsigned DEFAULT NULL, add column `is_virtual` tinyint(1) NOT NULL DEFAULT '0', add key `practice_contest_id` (`practice_contest_id`, `id`)");
		}
		Upgrader::exec("update `submissions`"
			." join `contest_virtuals` on `contest_virtuals`.`username` = `submissions`.`submitter`"
			." join `contests_problems` on `contests_problems`.`contest_id` = `contest_virtuals`.`contest_id` and `contests_problems`.`problem_id` = `submissions`.`problem_id`"
			." join `contests` on `contests`.`id` = `contest_virtuals`.`contest_id`"
			." set `submissions`.`practice_contest_id` = `contest_virtuals`.`contest_id`, `submissions`.`is_virtual` = 1, `submissions`.`is_hidden` = 0,"
			." `submissions`.`domain_id` = ifnull(`submissions`.`domain_id`, `contests`.`domain_id`)"
			." where `submissions`.`contest_id` is null and `submissions`.`practice_contest_id` is null and `submissions`.`homework_id` is null"
			." and `submissions`.`submit_time` >= `contest_virtuals`.`start_time`"
			." and `submissions`.`submit_time` < date_add(`contest_virtuals`.`start_time`, interval `contest_virtuals`.`last_min` minute)");
	} else {
		if (Upgrader::columnExists('submissions', 'practice_contest_id')) {
			Upgrader::exec("alter table `submissions` drop key `practice_contest_id`, drop column `practice_contest_id`, drop column `is_virtual`");
		}
	}
};
