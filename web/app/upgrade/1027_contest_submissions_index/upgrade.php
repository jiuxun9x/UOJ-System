<?php

// The submissions of a contest are asked for by the contest they were made in: by its board,
// and every few seconds by its outer board, for everybody who watches. Without an index
// each of those times reads every submission the site has.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addIndex('submissions', 'contest', '`contest_id`, `id`');
	} else {
		Upgrader::dropIndex('submissions', 'contest');
	}
};
