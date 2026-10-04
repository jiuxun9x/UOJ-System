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
