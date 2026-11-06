<?php

// A judger that has a memory limit says with every asking for work how much memory the
// programs of a problem may need for it to judge them (judger_workers.memory_mb, null for no
// limit). A problem it was given and could not judge for want of memory it gives back saying
// how much the problem needs: the problem keeps that for the version of its data it was told
// for (judge_memory_mb, judge_memory_version), and is not given to judgers with less while one
// with enough is around. Published data makes it count no more.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('judger_workers', 'memory_mb', "int(10) unsigned DEFAULT NULL");
		Upgrader::addColumn('problems', 'judge_memory_mb', "int(10) unsigned DEFAULT NULL");
		Upgrader::addColumn('problems', 'judge_memory_version', "int(10) unsigned DEFAULT NULL");
	} else {
		Upgrader::dropColumn('problems', 'judge_memory_version');
		Upgrader::dropColumn('problems', 'judge_memory_mb');
		Upgrader::dropColumn('judger_workers', 'memory_mb');
	}
};
