<?php

// A problem of a domain is managed by members of the domain only: whoever left the domain, or was
// taken out of it, before this was so is no manager of its problems any more.
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("delete problems_permissions from problems_permissions
			join problems on problems.id = problems_permissions.problem_id
			join domains on domains.id = problems.owner_domain_id
			where problems_permissions.username != domains.owner_username
			and not exists (select 1 from domain_members where domain_members.domain_id = domains.id and domain_members.username = problems_permissions.username)");
	}
	// (what was taken back is not given again)
};
