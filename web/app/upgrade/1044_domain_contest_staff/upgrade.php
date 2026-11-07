<?php

// The staff of a contest of a domain are members of the domain: whoever left the domain, or was
// taken out of it, before this was so is staff of its contests no more (A01).
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("delete contests_permissions from contests_permissions
			join contests on contests.id = contests_permissions.contest_id
			join domains on domains.id = contests.domain_id
			where contests_permissions.username != domains.owner_username
			and not exists (select 1 from domain_members where domain_members.domain_id = domains.id and domain_members.username = contests_permissions.username)");
	}
	// (what was taken back is not given again)
};
