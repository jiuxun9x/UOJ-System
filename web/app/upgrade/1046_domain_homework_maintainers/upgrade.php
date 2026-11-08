<?php

// Who looks after a homework is a member of its domain: whoever left the domain, or was taken out
// of it, before this was so looks after its homework no more, nor again when they come back.
return function ($type) {
	if ($type == 'up') {
		Upgrader::exec("delete homework_maintainers from homework_maintainers
			join homeworks on homeworks.id = homework_maintainers.homework_id
			join domains on domains.id = homeworks.domain_id
			where homework_maintainers.username != domains.owner_username
			and not exists (select 1 from domain_members where domain_members.domain_id = domains.id and domain_members.username = homework_maintainers.username)");
	}
	// (what was taken back is not given again)
};
