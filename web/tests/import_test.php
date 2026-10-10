<?php

// Problems that come from somewhere else: what a template and a package of Hydro say, as the
// settings of this site.

require_once __DIR__ . '/../app/libs/uoj-problem-lib.php';
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
