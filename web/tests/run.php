<?php

// Dependency-free unit tests for the helpers of the web application that need no database.
// Run with: php web/tests/run.php

error_reporting(E_ALL);

$n_checks = 0;
$failures = [];

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
