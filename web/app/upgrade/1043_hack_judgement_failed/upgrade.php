<?php

// A hack whose judgement failed - its checker, interactor, validator or standard program went
// wrong, or no judger could judge it - neither succeeded nor failed: it says why (result_error)
// and keeps its input, to be judged again once the problem is put right.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('hacks', 'result_error', "varchar(100) DEFAULT NULL");
	} else {
		Upgrader::dropColumn('hacks', 'result_error');
	}
};
