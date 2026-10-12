<?php

// Problems that come from somewhere else: what a template and a package of Hydro say, as the
// settings of this site.

require_once __DIR__ . '/../app/libs/uoj-problem-lib.php';
require_once __DIR__ . '/../app/libs/uoj-utility-lib.php';
require_once __DIR__ . '/../app/libs/uoj-import-lib.php';

// ---- the template that is handed out reads as what it is
list($meta, $statement) = importFrontMatter(importTemplateText());
// (the little reader of YAML is what reads it here and where the extension is not installed)
$meta = importSimpleYaml(preg_replace('/\A---\n(.*?)\n---.*\z/s', '$1', importTemplateText()));
check_same(array('title' => '这里写题目名称', 'time_limit' => '1', 'memory_limit' => '256', 'tags' => array('标签一', '标签二'), 'public' => 'false'), $meta, 'the lines at the top of the template');
check_same(true, strpos($statement, "## 题目描述\n") === 0, 'the statement of the template begins after them');
check_same(true, strpos($statement, '```input1') !== false && strpos($statement, '---') === false, 'and has the samples, and none of the lines');
list($basics, $settings, $notes) = importTemplateMeta($meta);
check_same(array(array('title' => '这里写题目名称', 'tags' => array('标签一', '标签二'), 'public' => false), array('time_limit' => '1', 'memory_limit' => 256), array()), array($basics, $settings, $notes), 'what the template says about its problem');

// ---- the lines at the top, as people write them
$filled = "---\ntitle: \"A + B：加法\"   # 名称\n时间限制: 2.5s\n内存限制: 512MB\n标签:\n  - 入门\n  - 模拟\n公开: 是\ntype: interactive\nchecker: rcmp6\n---\n\n## 题目描述\n\n求和。\n";
list($meta, $statement) = array(importSimpleYaml("title: \"A + B：加法\"   # 名称\n时间限制: 2.5s\n内存限制: 512MB\n标签:\n  - 入门\n  - 模拟\n公开: 是\ntype: interactive\nchecker: rcmp6"), importFrontMatter($filled)[1]);
check_same("## 题目描述\n\n求和。\n", $statement, 'the statement of a filled-in template');
list($basics, $settings, $notes) = importTemplateMeta($meta);
check_same(array('title' => 'A + B：加法', 'tags' => array('入门', '模拟'), 'public' => true), $basics, 'keys in Chinese, a list on lines of its own, a title in quotes');
check_same(array('time_limit' => '2.5', 'memory_limit' => 512, 'type' => 'interactive', 'checker' => 'rcmp6'), $settings, 'limits with their units, a type and a way to compare');
list($basics, $settings, $notes) = importTemplateMeta(array('title' => 'x', 'time_limit' => 'soon', 'memory_limit' => '-1', 'type' => 'quantum', 'checker' => 'magic'));
check_same(array(array(), 4), array($settings, count($notes)), 'what is not understood is said, and the usual is taken');
check_same(array(null, "no lines here\n"), importFrontMatter("no lines here\n"), 'a file without the lines is all statement');
check_same("x\n", importFrontMatter("\xEF\xBB\xBF---\r\ntitle: t\r\n---\r\nx\r\n")[1], 'a file as Windows writes it');
$read = importReadTemplate("## 题目描述\n\n没有开头那几行。\n", '我的文件名');
check_same(array('我的文件名', 1), array($read['title'], count($read['notes'])), 'a template without its lines is called what its file is called');

// ---- limits
check_same(array('1', '1.5', '0.5', '2', '10', null, null, null, null), array(importSeconds('1s'), importSeconds('1500ms'), importSeconds('500 ms'), importSeconds(2), importSeconds('10秒'), importSeconds('fast'), importSeconds('0'), importSeconds('601'), importSeconds(array(1))), 'times');
check_same(array(256, 256, 1024, 64, 512, 1, null, null), array(importMegabytes('256m'), importMegabytes('256MB'), importMegabytes('1g'), importMegabytes('65536k'), importMegabytes(512), importMegabytes('100k'), importMegabytes('lots'), importMegabytes('64g')), 'amounts of memory');
check_same(array('a', 'b'), importTags(' a ,, b，a '), 'tags, each once');

// ---- what a config.yaml of Hydro says
$names = array('a1.in', 'a1.out', 'a2.in', 'a2.out', 'b1.in', 'b1.ans', 'chk.cc', 'interactor.cc', 'config.yaml');
$said = importHydroConfig(array('type' => 'default', 'time' => '2s', 'memory' => '128m', 'checker_type' => 'testlib', 'checker' => 'chk.cc',
	'subtasks' => array(
		array('score' => 40, 'cases' => array(array('input' => 'a1.in', 'output' => 'a1.out'), array('input' => 'a2.in', 'output' => 'a2.out'))),
		array('score' => 60, 'type' => 'min', 'cases' => array(array('input' => 'b1.in', 'output' => 'b1.ans')))
	)), $names);
check_same(array('time_limit' => '2', 'memory_limit' => 128, 'checker' => 'custom', 'checker_file' => 'chk.cc', 'scoring' => 'subtasks', 'subtasks' => array(array(2, 40), array(3, 60))), $said['settings'], 'Hydro: limits, a checker written with testlib, subtasks');
check_same(array(array(array('a1.in', 'a1.out'), array('a2.in', 'a2.out'), array('b1.in', 'b1.ans')), array()), array($said['cases'], $said['notes']), 'and its tests in their order');
// what this site has no word for is said, and the rest is kept
$said = importHydroConfig(array('type' => 'communication', 'time' => '1000ms', 'checker_type' => 'syzoj', 'checker' => 'chk.cc', 'filename' => 'sum',
	'subtasks' => array(
		array('score' => 50, 'type' => 'sum', 'cases' => array(array('input' => 'a1.in', 'output' => 'a1.out'), array('input' => 'gone.in', 'output' => 'gone.out'))),
		array('score' => 50, 'if' => array(0), 'cases' => array(array('input' => 'a2.in')))
	)), $names);
check_same(array('time_limit' => '1', 'checker' => 'custom', 'checker_file' => 'chk.cc'), $said['settings'], 'Hydro: what could be kept');
check_same(array(array('a1.in', 'a1.out'), array('a2.in', null)), $said['cases'], 'the tests that are there, one of them without an answer');
check_same(5, count($said['notes']), 'and a line for each thing that could not: the type, the checker, the files it reads, a test that is not there, the subtasks');
$said = importHydroConfig(array('type' => 'interactive', 'interactor' => 'interactor.cc', 'checker_type' => 'strict'), $names);
check_same(array(array('type' => 'interactive', 'checker' => 'fcmp', 'interactor_file' => 'interactor.cc'), null), array($said['settings'], $said['cases']), 'Hydro: an interactive problem, whose tests are found by their names');
check_same(array('settings' => array(), 'cases' => null, 'notes' => array()), importHydroConfig(null, $names), 'no config.yaml says nothing');
$scores = importHydroConfig(array('subtasks' => array(array('score' => 30, 'cases' => array(array('input' => 'a1.in'))), array('score' => 30, 'cases' => array(array('input' => 'a2.in'))))), $names);
check_same(array(false, 1), array(isset($scores['settings']['scoring']), count($scores['notes'])), 'subtasks whose scores do not come to a hundred are not kept as subtasks');

// ---- where the problems of a package are
check_same(array(array('A', 'B/inner'), array('C.md')), importFindProblems(array('A/problem.yaml', 'A/problem_zh.md', 'A/testdata/1.in', 'B/inner/problem.md', 'B/inner/data/1.in', 'C.md', 'README.md', 'A/solution.md')),
	'a folder with problem.yaml or problem.md is a problem; a Markdown file outside of them is a template');
check_same(array(array(''), array()), importFindProblems(array('problem.yaml', 'problem_zh.md', 'testdata/config.yaml', 'notes.md')), 'a package that is one problem');
check_same(array(array(), array('a.md', 'sub/b.md')), importFindProblems(array('sub/b.md', 'a.md', 'pic.png')), 'a package of templates');
check_same(array(array(), array()), importFindProblems(array('1.in', '1.out', 'problem.conf')), 'the data of one problem is no package of problems');

// ---- the names of files are taken apart the same under every locale of the server
$locale = setlocale(LC_CTYPE, '0');
setlocale(LC_CTYPE, 'C');
check_same(array('没有开头', 'md'), array(importStem('没有开头.md'), importEnding('没有开头.md')), 'a name that is not ASCII, under the locale servers have');
check_same(array('第一题', '题面'), array(importFolder('包/第一题/题面.MD') === '包/第一题' ? '第一题' : importFolder('包/第一题/题面.MD'), importStem('包/第一题/题面.MD')), 'a file in folders');
check_same(array('md', 'zip', '', 'gz'), array(importEnding('a/题面.MD'), importEnding('data.ZIP'), importEnding('README'), importEnding('a.tar.gz')), 'endings');
check_same(array('', 'a'), array(importFolder('problem.yaml'), importFolder('a/problem.yaml')), 'the folder of a file');
check_same(array(array('第一题', '第二题/内层'), array('说明.md')),
	importFindProblems(array('第一题/problem.yaml', '第一题/testdata/1.in', '第二题/内层/problem_zh.md', '说明.md', 'README.md')), 'a package whose folders are named in Chinese');
check_same('没有开头', importReadTemplate("## 题目描述\n\n只有题面。\n", importStem('没有开头.md'))['title'], 'a template without a title is named by its file');
setlocale(LC_CTYPE, $locale);

// ---- a config.yaml of Hydro: what used to be dropped without a word
$one = importHydroConfig(array('subtasks' => array(array('score' => 100, 'cases' => array(array('input' => 'a1.in', 'output' => 'a1.out'), array('input' => 'a2.in', 'output' => 'a2.out'))))), $names);
check_same(array('all', array()), array($one['settings']['scoring'], $one['notes']), 'Hydro: one subtask is passed as a whole, which is every test passing');
$one = importHydroConfig(array('subtasks' => array(array('score' => 100, 'type' => 'sum', 'cases' => array(array('input' => 'a1.in', 'output' => 'a1.out'))))), $names);
check_same(array(false, 1), array(isset($one['settings']['scoring']), count($one['notes'])), 'one subtask that adds its tests up is scored test by test, and it is said');
$own = importHydroConfig(array('langs' => array('cc'), 'validator' => 'val.cc', 'user_extra_files' => array(), 'subtasks' => array(
	array('score' => 50, 'time' => '2s', 'cases' => array(array('input' => 'a1.in', 'output' => 'a1.out', 'score' => 10))),
	array('score' => 50, 'cases' => array(array('input' => 'a2.in', 'output' => 'a2.out', 'memory' => '64m'))))), $names);
check_same(array('subtasks', 3), array($own['settings']['scoring'], count($own['notes'])), 'limits and scores of single tests, and settings this site has no word for, are said to be left behind');
check_same(true, strpos(join("\n", $own['notes']), 'langs、validator') !== false && strpos(join("\n", $own['notes']), 'user_extra_files') === false, 'the ones that say something, by their names');

// ---- the problem.conf that is handed out
list($template_conf, $err) = problemConfFromText(importConfTemplateText());
check_same(array(array('use_builtin_judger' => 'on', 'time_limit' => '1', 'memory_limit' => '256', 'use_builtin_checker' => 'wcmp'), ''), array($template_conf, $err),
	'what is in force in it is what a problem has when nobody says anything');
check_same(array('traditional', '1', 256, 'wcmp', 'per_test', false), array_values(array_intersect_key(problemSettingsOfConf($template_conf), array_flip(array('time_limit', 'memory_limit', 'checker', 'scoring', 'type', 'validate_input')))),
	'which are the settings the form starts with');
// every setting it holds back is one that can be put in force by taking its "#" away
$held_back = array();
foreach (explode("\n", importConfTemplateText()) as $line) {
	if (preg_match('/^# ([a-z][a-z0-9_]*) +(\S+)( +#.*)?$/', $line, $matches)) {
		list($conf, $err) = problemConfFromText(substr($line, 2));
		check_same(array(array($matches[1] => $matches[2]), ''), array($conf, $err), "a line of the template without its #: $matches[1]");
		$held_back[$matches[1]] = true;
	}
}
foreach (array('n_tests', 'n_ex_tests', 'n_sample_tests', 'input_pre', 'input_suf', 'output_pre', 'output_suf', 'n_subtasks', 'subtask_end_1', 'subtask_score_1', 'subtask_dependence_3', 'point_score_1',
		'chk_source', 'interaction_mode', 'interactor_source', 'multi_pass', 'submit_answer', 'with_implementer', 'validate_input_before_test', 'val_source', 'std_source', 'output_limit', 'time_limit_3') as $key) {
	check_same(true, isset($held_back[$key]), "the template speaks of $key");
}

// ---- a problem.conf that does not say which its tests are is the settings of the problem
$partial = array('use_builtin_judger' => 'on', 'time_limit' => '2', 'chk_source' => 'chk.cpp', 'n_subtasks' => '2', 'subtask_end_1' => '2', 'subtask_score_1' => '40', 'subtask_score_2' => '60', 'subtask_dependence_2' => '1');
$settings = importSettingsOfConf($partial, array('time_limit' => '9', 'memory_limit' => 64), array('1.in', '1.out', 'chk.cpp'));
check_same(array('2', 64, 'custom', 'chk.cpp', 'subtasks', array(array(2, 40), array(null, 60))),
	array($settings['time_limit'], $settings['memory_limit'], $settings['checker'], $settings['checker_file'], $settings['scoring'], $settings['subtasks']),
	'what it says counts, the statement fills in what it leaves open, and the last subtask ends where the tests end');
check_same(array('wcmp', 'custom', 'custom'), array(
	importSettingsOfConf(array('time_limit' => '1'), array(), array('1.in', '1.out'))['checker'],
	importSettingsOfConf(array('time_limit' => '1'), array(), array('1.in', '1.out', 'checker.cpp'))['checker'],
	importSettingsOfConf(array('time_limit' => '1', 'chk_source' => 'judge.cpp'), array(), array('1.in', 'judge.cpp'))['checker']
), 'no word about comparing: the checker that came along, or the usual way');
check_same(array('all', 'interactive', 'multi_pass', 3), array(
	importSettingsOfConf(array('n_subtasks' => '1'), array(), array())['scoring'],
	importSettingsOfConf(array('interaction_mode' => 'on', 'interactor_source' => 'i.cpp'), array(), array())['type'],
	importSettingsOfConf(array('multi_pass' => '3', 'chk_source' => 'chk.cpp'), array(), array())['type'],
	importSettingsOfConf(array('multi_pass' => '3', 'chk_source' => 'chk.cpp'), array(), array())['passes']
), 'the kinds of problems a problem.conf can say');
// the settings come back as the problem.conf they stand for, with the tests that were found
$found = problemDetectTests(array('1.in', '1.out', '2.in', '2.out', '3.in', '3.out', 'ex_1.in', 'ex_1.out', 'chk.cpp'));
$settings['subtasks'][1][0] = count($found['tests']);
list($built, $err) = problemConfFromSettings($settings + problemDefaultSettings(), $found, $partial, array('data1.in', 'chk.cpp'));
check_same(array('', '3', '1', 'chk.cpp', '2', '2', '3', '1', false), array($err, (string)$built['n_tests'], (string)$built['n_ex_tests'], $built['chk_source'], (string)$built['n_subtasks'],
	(string)$built['subtask_end_1'], (string)$built['subtask_end_2'], $built['subtask_dependence_2'], isset($built['use_builtin_checker'])),
	'and what the form has no word for stays in it');

// ---- the package that is handed out is a package: it is read as the two problems it holds
$template = importPackageTemplate();
check_same(array(array('a-plus-b', 'with-checker'), array()), importFindProblems(array_keys($template)), 'two folders, and a README that is no problem');
$unpacked = sys_get_temp_dir() . '/uoj_import_test_' . getmypid();
foreach ($template as $name => $content) {
	@mkdir(dirname("$unpacked/$name"), 0755, true);
	file_put_contents("$unpacked/$name", $content);
}
$read = importReadPackage($unpacked, 'a-plus-b', array_keys($template));
check_same(array('A + B 问题', array('入门'), false, false, array()), array($read['title'], $read['tags'], $read['public'], $read['native'], $read['notes']), 'the first: what its statement says about it');
check_same(array('1', 256, 'wcmp', 'per_test'), array($read['settings']['time_limit'], $read['settings']['memory_limit'], $read['settings']['checker'], $read['settings']['scoring']), 'and its problem.conf, which is the one that is handed out');
check_same(array('1.in', '1.out', '2.in', '2.out', '3.in', '3.out', 'ex_1.in', 'ex_1.out'), array_keys($read['data']), 'its data, without the problem.conf that is written anew');
check_same(true, strpos($read['statement_md'], "## 题目描述\n") === 0 && strpos($read['statement_md'], 'title:') === false, 'its statement begins after the lines');
$read = importReadPackage($unpacked, 'with-checker', array_keys($template));
check_same(array('拆成两个数', 'custom', 'chk.cpp', 'subtasks', array(array(2, 40), array(null, 60)), array('figure.png'), array()),
	array($read['title'], $read['settings']['checker'], $read['settings']['checker_file'], $read['settings']['scoring'], $read['settings']['subtasks'], array_keys($read['attachments']), $read['notes']),
	'the second: a checker of its own, two subtasks, a picture');
check_same(true, strpos($read['statement_md'], '](file://figure.png)') !== false, 'which its statement shows');
// a problem.conf that says how many tests there are is taken at its word, wherever it lies
file_put_contents("$unpacked/a-plus-b/data/problem.conf", "n_tests 3\ninput_pre x\n");
$read = importReadPackage($unpacked, 'a-plus-b', array_merge(array_keys($template), array('a-plus-b/data/problem.conf')));
check_same(array(false, '1'), array($read['native'], $read['conf']['time_limit']), 'the one beside the statement counts before one among the data');
unlink("$unpacked/a-plus-b/problem.conf");
$read = importReadPackage($unpacked, 'a-plus-b', array_diff(array_merge(array_keys($template), array('a-plus-b/data/problem.conf')), array('a-plus-b/problem.conf')));
check_same(array(true, array('n_tests' => '3', 'input_pre' => 'x'), false), array($read['native'], $read['conf'], isset($read['data']['problem.conf'])), 'a problem.conf with n_tests is taken as it is');
// one that can not be read is said to be, and the problem is still a problem
file_put_contents("$unpacked/a-plus-b/data/problem.conf", "time_limit 1\ntime_limit 2\n");
$read = importReadPackage($unpacked, 'a-plus-b', array_diff(array_merge(array_keys($template), array('a-plus-b/data/problem.conf')), array('a-plus-b/problem.conf')));
check_same(array(null, false, true), array($read['conf'], $read['native'], strpos(join("\n", $read['notes']), 'problem.conf 没有采用') !== false && strpos(join("\n", $read['notes']), '写了两次') !== false), 'a problem.conf that is wrong');
// a config.yaml beside a problem.conf is not read
file_put_contents("$unpacked/with-checker/data/config.yaml", "time: 9s\n");
$read = importReadPackage($unpacked, 'with-checker', array_merge(array_keys($template), array('with-checker/data/config.yaml')));
check_same(array('1', false, 1), array($read['settings']['time_limit'], isset($read['data']['config.yaml']), count($read['notes'])), 'the problem.conf decides, and it is said that the config.yaml was not read');
// what is compiled with the programs of the contestants comes along in its folder
file_put_contents("$unpacked/with-checker/data/require", '');
$read = importReadPackage($unpacked, 'with-checker', array_merge(array_keys($template), array('with-checker/data/require/implementer.cpp', 'with-checker/data/download/lib.h', 'with-checker/data/other/x.txt')));
check_same(array(true, true, false), array(isset($read['data']['require/implementer.cpp']), isset($read['data']['download/lib.h']), isset($read['data']['other/x.txt'])), 'require/ and download/, and no other folder of the data');
exec('rm -rf ' . escapeshellarg($unpacked));
