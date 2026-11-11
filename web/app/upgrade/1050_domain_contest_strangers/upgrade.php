<?php

// The staff and the testers of a contest of a domain are people of the domain: whoever leaves it
// is staff no more (A01), and nobody who is not in it is made staff. Somebody who was not was
// made staff all the same, and became it on joining the domain, as a student even. What was
// given so is taken back. (The site does not count it as long as they are not in the domain.)
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("delete contests_permissions from contests_permissions join contests on contests.id = contests_permissions.contest_id"
			." join domains on domains.id = contests.domain_id"
			." where contests_permissions.username <> domains.owner_username"
			." and contests_permissions.username not in (select username from domain_members where domain_members.domain_id = contests.domain_id)");
	}
};
