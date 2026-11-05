<?php

// Several judgers may work for one judging account, as the judgers of one machine do when each
// has a CPU of its own (judger/workers.sh). The record of a judgement keeps which of them it was:
// the name the worker gave itself, which starts with its label (such as cpu3) where it has one.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('submission_judgements', 'worker', "varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL");
	} else {
		Upgrader::dropColumn('submission_judgements', 'worker');
	}
};
