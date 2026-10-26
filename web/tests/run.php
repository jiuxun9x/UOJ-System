<?php

// Dependency-free unit tests for the helpers of the web application that need no database.
// Run with: php web/tests/run.php

error_reporting(E_ALL);

$n_checks = 0;
$failures = [];

// What PHP says about the code of the site while it is tested is a failure: a warning of today
// is the error of the next version of PHP. The libraries of others are theirs to keep up with.
set_error_handler(function($level, $message, $file, $line) {
	global $failures;
	// what the code silences with @ on purpose is not counted
	if ((error_reporting() & $level) && strpos($file, '/vendor/') === false) {
		$failures[] = "PHP says \"$message\" in $file on line $line";
	}
	return true;
});

function check_same($expected, $actual, $what) {
	global $n_checks, $failures;
	$n_checks++;
	if ($expected !== $actual) {
		$failures[] = "$what: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
	}
}

foreach (glob(__DIR__ . '/*_test.php') as $file) {
	require $file;
}

foreach ($failures as $failure) {
	fwrite(STDERR, "FAILED $failure\n");
}
if ($failures) {
	fwrite(STDERR, count($failures) . " of $n_checks checks failed\n");
	exit(1);
}
echo "all $n_checks checks passed\n";
