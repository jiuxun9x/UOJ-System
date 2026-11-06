<?php

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

require $_SERVER['DOCUMENT_ROOT'] . '/app/libs/uoj-lib.php';

// TODO: more beautiful argv parser

$handlers = [
	'upgrade:up' => function ($name) {
		if (func_num_args() != 1) {
			Upgrader::fail("php cli.php upgrade:up <name>\n");
		}
		Upgrader::transaction(function() use ($name) {
			Upgrader::up($name);
		});
		die("finished!\n");
	},
	'upgrade:down' => function ($name) {
		if (func_num_args() != 1) {
			Upgrader::fail("php cli.php upgrade:down <name>\n");
		}
		Upgrader::transaction(function() use ($name) {
			Upgrader::down($name);
		});
		die("finished!\n");
	},
	'upgrade:refresh' => function ($name) {
		if (func_num_args() != 1) {
			Upgrader::fail("php cli.php upgrade:refresh <name>\n");
		}
		Upgrader::transaction(function() use ($name) {
			Upgrader::refresh($name);
		});
		die("finished!\n");
	},
	'upgrade:remove' => function ($name) {
		if (func_num_args() != 1) {
			Upgrader::fail("php cli.php upgrade:remove <name>\n");
		}
		Upgrader::transaction(function() use ($name) {
			Upgrader::remove($name);
		});
		die("finished!\n");
	},
	'upgrade:latest' => function () {
		if (func_num_args() != 0) {
			Upgrader::fail("php cli.php upgrade:latest\n");
		}
		Upgrader::transaction(function() {
			Upgrader::upgradeToLatest();
		});
		die("finished!\n");
	},
	'upgrade:remove-all' => function () {
		if (func_num_args() != 0) {
			Upgrader::fail("php cli.php upgrade:remove-all\n");
		}
		Upgrader::transaction(function() {
			Upgrader::removeAll();
		});
		die("finished!\n");
	},
	// finishes the changes of usernames that were interrupted
	'user:finish-renames' => function () {
		$left = finishUserRenames();
		if ($left > 0) {
			Upgrader::fail("$left changes of usernames could not be finished\n");
		}
		die("finished!\n");
	},
	// What time brings about in the homeworks: publishing them once the data of their problems is
	// built, and settling them when they end. Pages that show a homework do the same for the
	// homework they show; this is for the ones nobody looks at. Run it every minute.
	'homework:tick' => function () {
		$advanced = homeworkAdvanceDue();
		$shown = homeworksRevealDue();
		die("advanced $advanced homeworks, showed $shown problems\n");
	},
	// What the site does by itself every minute: what homework:tick does, and looking after
	// the judgers and the queue. The container of the web server runs it in a loop; without
	// containers it belongs in a crontab.
	// What the site does by itself, every minute: each step is done whatever became of the ones
	// before it, and a step that fails is told (on the standard error, which the loop of the
	// image keeps in /var/log/uoj-site-tick.log) and is an alert on the page of the monitor.
	'site:tick' => function () {
		// a backup is being put back: nothing is done until the site is open again
		if (siteInMaintenance()) {
			die(date('Y-m-d H:i:s') . " the site is closed (maintenance:off opens it): nothing done\n");
		}
		$said = array();
		$steps = array(
			'homeworks' => function () use (&$said) {
				$said[] = 'advanced ' . homeworkAdvanceDue() . ' homeworks';
			},
			// the contests and the homeworks that are over and were told to show their problems
			'reveal' => function () use (&$said) {
				$said[] = 'showed ' . (contestsRevealDue() + homeworksRevealDue()) . ' problems';
			},
			'backup' => function () use (&$said) {
				if (backupTick()) {
					$said[] = 'started a backup';
				}
			},
			'monitor' => function () use (&$said) {
				list($opened, $resolved) = monitorTick();
				$said[] = "opened $opened alerts, resolved $resolved alerts";
			},
			'logins' => function () use (&$said) {
				$said[] = 'forgot ' . authTick() . ' old logins';
			}
		);
		$failed = siteTickRunSteps($steps);
		$failed += siteTickRunSteps(array('alerts' => function () use ($failed, $steps) {
			siteTickAlerts($failed, $steps);
		}));
		echo date('Y-m-d H:i:s'), ' ', implode(', ', $said), "\n";
		foreach ($failed as $name => $message) {
			fwrite(STDERR, date('Y-m-d H:i:s') . " site:tick: $name failed: $message\n");
		}
		exit($failed ? 1 : 0);
	},
	// Makes a backup of the database, the data of the problems and the submissions now.
	'backup:run' => function ($reason = 'manual') {
		list($name, $err) = backupRun($reason);
		if ($err !== '') {
			fwrite(STDERR, "backup failed: $err\n");
			exit(1);
		}
		die("backup $name is complete\n");
	},
	'backup:list' => function () {
		foreach (backupNames() as $name) {
			$manifest = backupManifest($name);
			echo $name, "\t", $manifest ? backupSize($manifest['db_bytes']) . ' database, ' . $manifest['files_count'] . ' files, ' . backupSize($manifest['files_bytes']) : 'no manifest', "\n";
		}
	},
	// The rehearsal of a restore: loads a backup, the newest one unless one is named, into a
	// database of its own and checks that everything the backup says it holds is there.
	'backup:verify' => function ($name = null) {
		$names = backupNames();
		if ($name === null) {
			$name = $names ? end($names) : '';
		}
		$err = backupVerify($name);
		if ($err !== '') {
			fwrite(STDERR, "backup $name can not be restored: $err\n");
			exit(1);
		}
		die("backup $name can be restored\n");
	},
	// Puts a backup back. Everything that happened since it was made is lost, which is why it
	// wants to be told --yes. restore.sh in the root of the repository stops the judgers,
	// calls this and brings the database up to date.
	'backup:restore' => function ($name = '', $confirmation = '', $keep_closed = '') {
		if ($confirmation !== '--yes') {
			fwrite(STDERR, "this replaces the database and the files of the site with the backup; run it again with --yes after the name of the backup\n");
			exit(1);
		}
		// the site is closed while the backup is put back; --keep-closed leaves it closed
		$err = backupRestore($name, $keep_closed === '--keep-closed');
		if ($err !== '') {
			fwrite(STDERR, "restore failed: $err\n");
			exit(1);
		}
		die("backup $name is restored; now run upgrade:latest" . ($keep_closed === '--keep-closed' ? ", then maintenance:off" : '') . "\n");
	},
	// Tells which of the passwords that came with the site are still in use (the image of the
	// site says so when it starts).
	'security:defaults' => function () {
		requirePHPLib('judger');
		foreach (securityDefaultsInUse() as $said) {
			echo "!! $said\n";
		}
	},
	// Closes the site (its pages say it is being looked after, site:tick does nothing), or opens it.
	'maintenance:on' => function () {
		if (!siteSetMaintenance(true)) {
			fwrite(STDERR, "the site could not be closed: " . siteMaintenanceFile() . " can not be written\n");
			exit(1);
		}
		die("the site is closed\n");
	},
	'maintenance:off' => function () {
		if (!siteSetMaintenance(false)) {
			fwrite(STDERR, "the site could not be opened: " . siteMaintenanceFile() . " can not be removed\n");
			exit(1);
		}
		die("the site is open\n");
	},
	'help' => 'showHelp'
];

function showHelp() {
	global $handlers;
	echo "UOJ Command-Line Interface\n";
	echo "php cli.php <task-name> params1 params2 ...\n";
	echo "\n";
	echo "The following tasks are available:\n";
	foreach ($handlers as $cmd => $handler) {
		echo "\t$cmd\n";
	}
}

if (count($argv) <= 1) {
	showHelp();
	die();
}

if (!isset($handlers[$argv[1]])) {
	echo "Invalid parameters.\n";
	showHelp();
	exit(1);
}

call_user_func_array($handlers[$argv[1]], array_slice($argv, 2));
