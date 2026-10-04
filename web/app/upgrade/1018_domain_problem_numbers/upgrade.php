<?php

// A problem of a domain has a number of its own in its domain, counted from 1, which is the
// number its people see and type. The id of the problem stays what everything else refers
// to. New problems of domains are given ids from a range of their own, so that the numbers of
// the problems of the site, which are their ids, go on without holes.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('problems', 'domain_pid', 'int(10) unsigned DEFAULT NULL');
		// the problems that domains have already, in the order they were made
		$last_domain = null;
		$number = 0;
		$result = DB::query("select id, owner_domain_id from problems where owner_domain_id is not null and domain_pid is null order by owner_domain_id, id");
		$rows = array();
		while ($row = DB::fetch($result, MYSQLI_ASSOC)) {
			$rows[] = $row;
		}
		foreach ($rows as $row) {
			if ($row['owner_domain_id'] !== $last_domain) {
				$last_domain = $row['owner_domain_id'];
				$number = (int)DB::selectFirst("select ifnull(max(domain_pid), 0) from problems where owner_domain_id = {$row['owner_domain_id']}", MYSQLI_NUM)[0];
			}
			$number++;
			DB::update("update problems set domain_pid = $number where id = {$row['id']}");
		}
		if (!Upgrader::indexExists('problems', 'domain_pid')) {
			Upgrader::exec("alter table `problems` add unique index `domain_pid` (`owner_domain_id`, `domain_pid`)");
		}
	} elseif ($type == 'down') {
		Upgrader::dropIndex('problems', 'domain_pid');
		Upgrader::dropColumn('problems', 'domain_pid');
	}
};
