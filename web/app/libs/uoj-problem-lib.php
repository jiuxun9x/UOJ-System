<?php

// Setting up a problem: what kind of problem it is, how it is judged, and which of the files
// that were uploaded are its tests. The people who set problems say these things in a form;
// problem.conf, which the judgers read, is written from what they said and from the names of
// the files. Somebody who would rather write problem.conf does: a file that comes with the
// data is used as it is.

// ---- what a problem can be

function problemTypes() {
	return array(
		'traditional' => array(
			'name' => '传统题',
			'description' => '程序读入数据、输出答案，输出和标准答案比较。',
			'needs' => ''
		),
		'interactive' => array(
			'name' => '交互题',
			'description' => '程序通过标准输入输出和交互器对话。',
			'needs' => '数据包里要有交互器 interactor.cpp（用 testlib.h 写）。测试点可以只有输入文件。'
		),
		'multi_pass' => array(
			'name' => '通信题（多轮运行）',
			'description' => '同一个程序运行两轮或更多轮：每一轮结束后校验器检查它的输出，并给出下一轮的输入，最后由校验器判定结果。',
			'needs' => '数据包里要有自己的校验器 chk.cpp（用 testlib.h 写）：要再运行一轮，就把下一轮的输入写到 nextpass.in 并以 ok 结束，要记住的东西写到 state.txt。写法和 Hydro 的 Multi Pass 相同。测试点可以只有输入文件。'
		),
		'submit_answer' => array(
			'name' => '提交答案题',
			'description' => '选手下载输入文件，提交每个测试点的答案，不提交程序。',
			'needs' => ''
		),
		'grader' => array(
			'name' => '函数交互题',
			'description' => '选手实现指定的函数，和出题人的交互库一起编译运行。',
			'needs' => '数据包的 require 文件夹里要有交互库 implementer.cpp。'
		)
	);
}
// how what a program wrote is compared with the answer
function problemCheckers() {
	return array(
		'wcmp' => '逐个单词比较，忽略空格和换行的多少（最常用）',
		'lcmp' => '逐行比较，忽略行内多余的空格',
		'fcmp' => '逐行严格比较，只忽略文末的换行',
		'ncmp' => '整数序列',
		'rcmp4' => '实数，绝对或相对误差不超过 1e-4',
		'rcmp6' => '实数，绝对或相对误差不超过 1e-6',
		'rcmp9' => '实数，绝对或相对误差不超过 1e-9',
		'yesno' => '只有 YES 或 NO（不分大小写）',
		'custom' => '自己的校验器：数据包里的 chk.cpp（用 testlib.h 写）'
	);
}
function problemScorings() {
	return array(
		'per_test' => '每个测试点分值相同，按通过的测试点给分（OI 常用）',
		'all' => '全部测试点通过才得分，遇到第一个不通过的就停（ICPC 常用）',
		'subtasks' => '子任务：一个子任务里的测试点全部通过才得到它的分'
	);
}
function problemDefaultSettings() {
	return array(
		'type' => 'traditional',
		'time_limit' => '1',
		'memory_limit' => 256,
		'checker' => 'wcmp',
		'scoring' => 'per_test',
		// rows of array(the last test of the subtask, its score)
		'subtasks' => array(),
		// how many of the extra tests are samples; null for all of them
		'n_samples' => null,
		// how many times at most the program of a multi-pass problem is run on a test
		'passes' => 2
	);
}
function problemSubtasksText($subtasks) {
	$lines = array();
	foreach ($subtasks as $row) {
		$lines[] = $row[0] . ' ' . $row[1];
	}
	return join("\n", $lines);
}

// What the form of the settings says, checked: array(the settings, '') or array(null, why not).
function problemSettingsFromForm($input) {
	$get = function($name) use ($input) {
		return isset($input[$name]) && is_string($input[$name]) ? trim($input[$name]) : '';
	};
	$settings = problemDefaultSettings();

	if (!isset(problemTypes()[$get('type')])) {
		return array(null, '请选择题目类型');
	}
	$settings['type'] = $get('type');

	if (!preg_match('/^[0-9]{1,3}(\.[0-9]{1,3})?$/D', $get('time_limit')) || $get('time_limit') <= 0 || $get('time_limit') > 600) {
		return array(null, '时间限制应是 0 到 600 之间的秒数，可以有小数，例如 1 或 2.5');
	}
	$settings['time_limit'] = $get('time_limit');
	if (!validateUInt($get('memory_limit')) || $get('memory_limit') < 1 || $get('memory_limit') > 16384) {
		return array(null, '内存限制应是 1 到 16384 之间的整数，单位是 MB');
	}
	$settings['memory_limit'] = (int)$get('memory_limit');

	if ($settings['type'] === 'multi_pass' && $get('passes') !== '') {
		if (!validateUInt($get('passes')) || $get('passes') < 2 || $get('passes') > 20) {
			return array(null, '多轮运行的轮数应是 2 到 20 之间的整数');
		}
		$settings['passes'] = (int)$get('passes');
	}

	// the name of another builtin checker is taken as it is: a problem that has one keeps it
	if (!isset(problemCheckers()[$get('checker')]) && !preg_match('/^[a-z0-9]{1,20}$/D', $get('checker'))) {
		return array(null, '请选择答案的比较方式');
	}
	$settings['checker'] = $get('checker');

	if (!isset(problemScorings()[$get('scoring')])) {
		return array(null, '请选择计分方式');
	}
	$settings['scoring'] = $get('scoring');
	if ($settings['scoring'] === 'subtasks') {
		$last_end = 0;
		$total = 0;
		$raw = isset($input['subtasks']) && is_string($input['subtasks']) ? $input['subtasks'] : '';
		foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $number => $line) {
			if (trim($line) === '') {
				continue;
			}
			if (!preg_match('/^\s*(\d{1,5})[\s,，:：]+(\d{1,3})\s*$/Du', $line, $matches)) {
				return array(null, '子任务第 ' . ($number + 1) . ' 行应是两个整数：这个子任务的最后一个测试点的编号，和它的分值');
			}
			if ($matches[1] <= $last_end) {
				return array(null, '子任务第 ' . ($number + 1) . ' 行：测试点编号应比上一行的大');
			}
			$last_end = (int)$matches[1];
			$total += (int)$matches[2];
			$settings['subtasks'][] = array($last_end, (int)$matches[2]);
		}
		if (count($settings['subtasks']) < 2 || count($settings['subtasks']) > 100) {
			return array(null, '子任务应有 2 到 100 个，一行一个');
		}
		if ($total != 100) {
			return array(null, "各子任务的分值加起来应是 100，现在是 $total");
		}
	}

	if ($get('n_samples') !== '') {
		if (!validateUInt($get('n_samples')) || $get('n_samples') > 5000) {
			return array(null, '样例的个数应是非负整数');
		}
		$settings['n_samples'] = (int)$get('n_samples');
	}
	return array($settings, '');
}

// what a problem.conf says, as the settings of the form
function problemSettingsOfConf($conf) {
	$settings = problemDefaultSettings();
	if (!is_array($conf)) {
		return $settings;
	}
	$on = function($key) use ($conf) {
		return isset($conf[$key]) && $conf[$key] === 'on';
	};
	if ($on('submit_answer')) {
		$settings['type'] = 'submit_answer';
	} elseif ($on('interaction_mode')) {
		$settings['type'] = 'interactive';
	} elseif (isset($conf['multi_pass']) && validateUInt((string)$conf['multi_pass']) && $conf['multi_pass'] > 1) {
		$settings['type'] = 'multi_pass';
		$settings['passes'] = (int)$conf['multi_pass'];
	} elseif ($on('with_implementer')) {
		$settings['type'] = 'grader';
	}
	if (isset($conf['time_limit']) && is_numeric($conf['time_limit'])) {
		$settings['time_limit'] = (string)$conf['time_limit'];
	}
	if (isset($conf['memory_limit']) && validateUInt((string)$conf['memory_limit'])) {
		$settings['memory_limit'] = (int)$conf['memory_limit'];
	}
	$settings['checker'] = isset($conf['use_builtin_checker']) ? (string)$conf['use_builtin_checker'] : 'custom';
	$n_tests = isset($conf['n_tests']) && validateUInt((string)$conf['n_tests']) ? (int)$conf['n_tests'] : 0;
	$n_subtasks = isset($conf['n_subtasks']) && validateUInt((string)$conf['n_subtasks']) ? (int)$conf['n_subtasks'] : 0;
	if ($n_subtasks == 1) {
		$settings['scoring'] = 'all';
	} elseif ($n_subtasks > 1) {
		$settings['scoring'] = 'subtasks';
		for ($i = 1; $i <= $n_subtasks; $i++) {
			$end = $i < $n_subtasks && isset($conf["subtask_end_$i"]) ? (int)$conf["subtask_end_$i"] : $n_tests;
			$score = isset($conf["subtask_score_$i"]) ? (int)round($conf["subtask_score_$i"]) : (int)floor(100 / $n_subtasks);
			$settings['subtasks'][] = array($end, $score);
		}
	}
	if (isset($conf['n_sample_tests']) && validateUInt((string)$conf['n_sample_tests'])) {
		$settings['n_samples'] = (int)$conf['n_sample_tests'];
	}
	return $settings;
}

// ---- which files are the tests

// What a file is among the data of a problem, by its name: array('in' or 'out', what the two
// files of a test have in common), or null for a file that is no test data.
//   1.in / 1.out / 1.ans          the ending says which is which
//   input1.txt / output1.txt      or a word at the beginning of the name, or after a separator
//   ex_in3.dat / ex_out3.dat
function problemTestFileRole($name) {
	list($stem, $ext) = uojFileNameParts($name);
	$ext = strtolower($ext);
	if ($ext === '' || $stem === '') {
		return null;
	}
	if ($ext === 'in' || $ext === 'inp') {
		return array('in', $stem);
	}
	if ($ext === 'out' || $ext === 'ans') {
		return array('out', $stem);
	}
	if (!in_array($ext, array('txt', 'dat', 'data'), true)) {
		return null;
	}
	if (preg_match('/^(|.*?[_.\-])(?:input|in)(|[0-9_.\-].*)$/Di', $stem, $matches)) {
		return array('in', $matches[1] . $matches[2] . '.' . $ext);
	}
	if (preg_match('/^(|.*?[_.\-])(?:output|out|answer|ans)(|[0-9_.\-].*)$/Di', $stem, $matches)) {
		return array('out', $matches[1] . $matches[2] . '.' . $ext);
	}
	return null;
}
// whether a test is one of the extra tests, which are where the samples are, by what its
// files have in common
function problemTestIsExtra($key) {
	return preg_match('/^(ex_|sample|example)/i', $key) === 1;
}
// Whether tests are named the way the judgers read them without being told every name:
// <pre><number>.<suf>, numbered from 1. Returns array(pre, suf) or null.
function problemNamesPattern($names, $lead = '') {
	$pattern = null;
	$numbers = array();
	foreach ($names as $name) {
		if (!preg_match('/^' . preg_quote($lead, '/') . '([A-Za-z0-9_.\-]*?[A-Za-z_.\-])([1-9][0-9]*)\.([A-Za-z0-9_\-]{1,50})$/D', $name, $matches) || strlen($matches[1]) > 50) {
			return null;
		}
		if ($pattern === null) {
			$pattern = array($matches[1], $matches[3]);
		} elseif ($pattern !== array($matches[1], $matches[3])) {
			return null;
		}
		$numbers[(int)$matches[2]] = true;
	}
	if ($pattern === null || count($numbers) != count($names)) {
		return null;
	}
	for ($i = 1; $i <= count($names); $i++) {
		if (!isset($numbers[$i])) {
			return null;
		}
	}
	return $pattern;
}

// Finds the tests among the files of a problem by their names.
//   $names            the files that lie in the folder of the problem itself
//   $inputs_suffice   whether a test may be an input file alone, as in a problem where the
//                     answer is not a file
// Returns
//   tests, extra      the tests in their order: rows of array(input file, output file or null)
//   pattern           array(input_pre, input_suf, output_pre, output_suf) that the judgers
//                     are told; the files are called that already when renames is empty
//   renames           old name => new name for the files that have to be called otherwise
//   create            the output files that have to be made, empty, for tests without one
//   unpaired          files that look like half a test
function problemDetectTests($names, $inputs_suffice = false) {
	$inputs = array();
	$outputs = array();
	foreach ($names as $name) {
		$role = problemTestFileRole($name);
		if ($role === null) {
			continue;
		}
		if ($role[0] === 'in') {
			$inputs[$role[1]] = $name;
		} else {
			$outputs[$role[1]] = $name;
		}
	}
	$keys = array_keys($inputs);
	usort($keys, 'strnatcasecmp');
	$found = array('tests' => array(), 'extra' => array(), 'pattern' => array('data', 'in', 'data', 'out'), 'renames' => array(), 'create' => array(), 'unpaired' => array());
	foreach ($keys as $key) {
		$key = (string)$key;
		if (!isset($outputs[$key]) && !$inputs_suffice) {
			$found['unpaired'][] = $inputs[$key];
			continue;
		}
		$found[problemTestIsExtra($key) ? 'extra' : 'tests'][] = array($inputs[$key], isset($outputs[$key]) ? $outputs[$key] : null);
		unset($outputs[$key]);
	}
	foreach ($outputs as $name) {
		$found['unpaired'][] = $name;
	}

	// are the files called what the judgers would call them already?
	$column = function($rows, $index) {
		$column = array();
		foreach ($rows as $row) {
			if ($row[$index] === null) {
				return null;
			}
			$column[] = $row[$index];
		}
		return $column;
	};
	$fits = false;
	if ($found['tests'] && $column($found['tests'], 1) !== null && $column($found['extra'], 1) !== null) {
		$in = problemNamesPattern($column($found['tests'], 0));
		$out = problemNamesPattern($column($found['tests'], 1));
		if ($in !== null && $out !== null) {
			$fits = !$found['extra'] || (problemNamesPattern($column($found['extra'], 0), 'ex_') === $in && problemNamesPattern($column($found['extra'], 1), 'ex_') === $out);
			if ($fits) {
				$found['pattern'] = array($in[0], $in[1], $out[0], $out[1]);
				// the rows in the order of their numbers
				foreach (array('tests' => '', 'extra' => 'ex_') as $kind => $lead) {
					$ordered = array();
					foreach ($found[$kind] as $row) {
						preg_match('/([0-9]+)\.[^.]*$/D', $row[0], $matches);
						$ordered[(int)$matches[1]] = $row;
					}
					ksort($ordered);
					$found[$kind] = array_values($ordered);
				}
			}
		}
	}
	if (!$fits) {
		foreach (array('tests' => '', 'extra' => 'ex_') as $kind => $lead) {
			foreach ($found[$kind] as $index => $row) {
				$number = $index + 1;
				foreach (array(0 => 'in', 1 => 'out') as $part => $suf) {
					$target = "{$lead}data$number.$suf";
					if ($row[$part] === null) {
						$found['create'][] = $target;
					} elseif ($row[$part] !== $target) {
						$found['renames'][$row[$part]] = $target;
					}
					$found[$kind][$index][$part] = $target;
				}
			}
		}
	}
	return $found;
}

// ---- problem.conf

// The keys of problem.conf that the form decides. Whatever else a problem.conf says is kept.
function problemConfIsManagedKey($key) {
	static $managed = array(
		'use_builtin_judger', 'use_builtin_checker', 'n_tests', 'n_ex_tests', 'n_sample_tests', 'input_pre', 'input_suf',
		'output_pre', 'output_suf', 'time_limit', 'memory_limit', 'interaction_mode', 'multi_pass', 'submit_answer',
		'with_implementer', 'n_subtasks'
	);
	return in_array($key, $managed, true) || preg_match('/^subtask_(end|score)_[0-9]+$/D', $key) === 1;
}
// Builds problem.conf: array(the conf, '') or array(null, why not).
//   $settings   what the form said
//   $found      what problemDetectTests() found
//   $old_conf   the problem.conf there is, for what the form does not decide
function problemConfFromSettings($settings, $found, $old_conf = array()) {
	$conf = array('use_builtin_judger' => 'on');
	$type = $settings['type'];
	// an interactive problem is judged by its interactor, a multi-pass problem by a checker of its own
	if ($type !== 'interactive' && $type !== 'multi_pass') {
		if ($settings['checker'] !== 'custom') {
			$conf['use_builtin_checker'] = $settings['checker'];
		}
	}
	$n_tests = count($found['tests']);
	$n_extra = $type === 'submit_answer' ? 0 : count($found['extra']);
	$conf['n_tests'] = $n_tests;
	$conf['n_ex_tests'] = $n_extra;
	$conf['n_sample_tests'] = $settings['n_samples'] === null ? $n_extra : min($settings['n_samples'], $n_extra);
	list($conf['input_pre'], $conf['input_suf'], $conf['output_pre'], $conf['output_suf']) = $found['pattern'];
	if ($type !== 'submit_answer') {
		$conf['time_limit'] = $settings['time_limit'];
		$conf['memory_limit'] = $settings['memory_limit'];
	}
	$flags = array('interactive' => 'interaction_mode', 'submit_answer' => 'submit_answer', 'grader' => 'with_implementer');
	if (isset($flags[$type])) {
		$conf[$flags[$type]] = 'on';
	}
	if ($type === 'multi_pass') {
		$conf['multi_pass'] = $settings['passes'];
	}
	if ($settings['scoring'] === 'all') {
		$conf['n_subtasks'] = 1;
	} elseif ($settings['scoring'] === 'subtasks') {
		$subtasks = $settings['subtasks'];
		if ($n_tests > 0 && $subtasks[count($subtasks) - 1][0] != $n_tests) {
			return array(null, "最后一个子任务应以最后一个测试点结束：数据里有 $n_tests 个测试点，子任务写到了第 {$subtasks[count($subtasks) - 1][0]} 个");
		}
		$conf['n_subtasks'] = count($subtasks);
		foreach ($subtasks as $index => $row) {
			$conf['subtask_end_' . ($index + 1)] = $row[0];
			$conf['subtask_score_' . ($index + 1)] = $row[1];
		}
	}
	if (is_array($old_conf)) {
		foreach ($old_conf as $key => $value) {
			// the score of single tests means something only where tests are scored one by one
			if (!problemConfIsManagedKey($key) && !($settings['scoring'] !== 'per_test' && preg_match('/^point_score_[0-9]+$/D', $key))) {
				$conf[$key] = $value;
			}
		}
	}
	return array($conf, '');
}

// ---- doing it

// the files that lie in the folder of the uploaded data of a problem itself
function problemUploadedNames($upload_dir) {
	$names = array();
	if (is_dir($upload_dir)) {
		foreach (scandir($upload_dir) as $name) {
			if ($name !== '.' && $name !== '..' && is_file("$upload_dir/$name")) {
				$names[] = $name;
			}
		}
	}
	return $names;
}
// Calls the files of a problem what problemDetectTests() says they are to be called, and
// makes the output files that are missing. Returns '' or what went wrong.
function problemArrangeFiles($upload_dir, $found) {
	// by way of names nothing else has: a file may be to take the name of another
	$staged = array();
	foreach ($found['renames'] as $old => $new) {
		$temp = ".arrange_" . count($staged) . "_" . getmypid();
		if (!@rename("$upload_dir/$old", "$upload_dir/$temp")) {
			return "无法重命名 $old";
		}
		$staged[$temp] = $new;
	}
	foreach ($staged as $temp => $new) {
		if (!@rename("$upload_dir/$temp", "$upload_dir/$new")) {
			return "无法重命名为 $new";
		}
	}
	foreach ($found['create'] as $name) {
		if (@file_put_contents("$upload_dir/$name", '') === false) {
			return "无法创建 $name";
		}
	}
	return '';
}
// Sets a problem up from its settings and the files that were uploaded for it: finds the
// tests, calls them what the judgers call them, and writes problem.conf.
// Returns array(what was found and done, as lines for people, '') or array(null, why not).
function problemApplySettings($problem, $settings, $actor) {
	requirePHPLib('judger');
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	$conf_path = "$upload_dir/problem.conf";
	$old_conf = is_file($conf_path) ? getUOJConf($conf_path) : array();
	$old_conf = is_array($old_conf) ? $old_conf : array();
	$inputs_suffice = $settings['type'] === 'interactive' || $settings['type'] === 'multi_pass';
	$found = problemDetectTests(problemUploadedNames($upload_dir), $inputs_suffice);
	list($conf, $err) = problemConfFromSettings($settings, $found, $old_conf);
	if ($err !== '') {
		return array(null, $err);
	}
	$err = problemArrangeFiles($upload_dir, $found);
	if ($err !== '') {
		return array(null, $err);
	}
	if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
		return array(null, '无法创建保存数据的目录');
	}
	if (is_file($conf_path)) {
		unlink($conf_path);
	}
	putUOJConf($conf_path, $conf);
	auditLog('problem.edit_conf', 'problem', $problem['id'], $old_conf ? $old_conf : null, $conf, $actor);

	$notes = array();
	if ($conf['n_tests'] == 0) {
		$notes[] = '还没有找到测试数据：上传数据包后再保存一次评测设置，测试点会自动识别';
	} else {
		$notes[] = "识别到 {$conf['n_tests']} 个测试点" . ($conf['n_ex_tests'] > 0 ? "、{$conf['n_ex_tests']} 个额外测试点（其中 {$conf['n_sample_tests']} 个是样例）" : '');
	}
	if ($found['renames']) {
		$notes[] = '为了让评测机认得，重命名了 ' . count($found['renames']) . " 个文件：测试点叫 data1.in、data1.out……，额外测试点叫 ex_data1.in……";
	}
	if ($found['create']) {
		$notes[] = '给 ' . count($found['create']) . ' 个只有输入的测试点补了空的答案文件';
	}
	if ($found['unpaired']) {
		$notes[] = '有 ' . count($found['unpaired']) . ' 个文件像是测试数据，却配不成对：' . join('、', array_slice($found['unpaired'], 0, 6)) . (count($found['unpaired']) > 6 ? ' 等' : '');
	}
	return array($notes, '');
}

// ---- making a problem

// what the form that makes a problem says about the problem itself: array(values, '') or array(null, why not)
function problemBasicsFromForm($input) {
	$title = isset($input['title']) && is_string($input['title']) ? trim($input['title']) : '';
	if ($title === '' || strlen($title) > 100 || !mb_check_encoding($title, 'UTF-8')) {
		return array(null, '标题不能为空，且不超过 100 个字节');
	}
	$statement_md = isset($input['statement_md']) && is_string($input['statement_md']) ? $input['statement_md'] : '';
	if (strlen($statement_md) > 1000000) {
		return array(null, '题面太长了');
	}
	$tags = array();
	$raw = isset($input['tags']) && is_string($input['tags']) ? str_replace('，', ',', $input['tags']) : '';
	foreach (explode(',', $raw) as $tag) {
		$tag = trim($tag);
		if ($tag === '') {
			continue;
		}
		if (strlen($tag) > 30) {
			return array(null, "标签“{$tag}”太长了");
		}
		if (!in_array($tag, $tags, true)) {
			$tags[] = $tag;
		}
	}
	if (count($tags) > 10) {
		return array(null, '标签不能超过 10 个');
	}
	return array(array('title' => $title, 'statement_md' => $statement_md, 'tags' => $tags, 'is_hidden' => isset($input['public']) ? 0 : 1), '');
}
// Makes a problem with its statement, on the site or in a domain, and returns its id or null.
// On the site it is managed by who makes it; in a domain by the people who teach there.
function problemCreateWithBasics($basics, $actor, $domain = null) {
	requirePHPLib('judger');
	requirePHPLib('data');
	// a title is kept the way the pages print it
	$title = HTML::escape($basics['title']);
	$id = problemCreate(array(
		'title' => "'".DB::escape($title)."'",
		'is_hidden' => (int)$basics['is_hidden'],
		'submission_requirement' => "'{}'"
	), $domain === null ? null : $domain['id']);
	if ($id === null) {
		return null;
	}
	$statement = HTML::pruifier()->purify(HTML::parsedown()->text($basics['statement_md']));
	DB::insert("insert into problems_contents (id, statement, statement_md) values ($id, '".DB::escape($statement)."', '".DB::escape($basics['statement_md'])."')");
	foreach ($basics['tags'] as $tag) {
		DB::insert("insert into problems_tags (problem_id, tag) values ($id, '".DB::escape($tag)."')");
	}
	if ($domain === null) {
		DB::insert("insert ignore into problems_permissions (username, problem_id) values ('".DB::escape($actor['username'])."', $id)");
	}
	dataNewProblem($id);
	auditLog('problem.create', 'problem', $id, null, array('title' => $basics['title'], 'is_hidden' => (int)$basics['is_hidden']) + ($domain === null ? array() : array('domain_id' => (int)$domain['id'])), $actor);
	return $id;
}

// ---- the data that is uploaded

// Takes the archive a form sent as the data of a problem and unpacks it into the folder of
// the problem. Returns array('none', '') when no file was sent, array('ok', how many files
// were written) or array('refused', why).
function problemTakeUploadedData($problem, $field, $actor) {
	if (!isset($_FILES[$field]) || is_array($_FILES[$field]['name']) || $_FILES[$field]['error'] == UPLOAD_ERR_NO_FILE) {
		return array('none', '');
	}
	if ($_FILES[$field]['error'] > 0) {
		return array('refused', '数据包没有传完（错误 ' . (int)$_FILES[$field]['error'] . '），可能是文件太大');
	}
	$zip_mime_types = array('application/zip', 'application/x-zip', 'application/x-zip-compressed');
	$is_zip = in_array($_FILES[$field]['type'], $zip_mime_types, true)
		|| ($_FILES[$field]['type'] == 'application/octet-stream' && substr($_FILES[$field]['name'], -4) == '.zip');
	if (!$is_zip) {
		return array('refused', '请上传zip格式！');
	}
	$up_filename = tempnam(sys_get_temp_dir(), 'uoj_data_');
	move_uploaded_file($_FILES[$field]['tmp_name'], $up_filename);
	// The archive is looked at before anything of it is written: how much it holds, and
	// where its files would go. Its files are then written one by one.
	list($unpacked, $errmsg) = uploadUnpack($up_filename, "/var/uoj_data/upload/{$problem['id']}", uploadLimits());
	$upload_facts = array('size' => filesize($up_filename), 'sha256' => hash_file('sha256', $up_filename));
	unlink($up_filename);
	if ($errmsg !== '') {
		auditLog('problem.upload_refused', 'problem', $problem['id'], null, $upload_facts + array('error' => $errmsg), $actor);
		return array('refused', $errmsg);
	}
	auditLog('problem.upload_data', 'problem', $problem['id'], null, $upload_facts + array('files' => $unpacked), $actor);
	return array('ok', $unpacked);
}
// whether the data that was uploaded for a problem says how it is to be judged
function problemHasConf($problem) {
	return is_file("/var/uoj_data/upload/{$problem['id']}/problem.conf");
}
// the problem.conf of the uploaded data of a problem: an array, null when there is none, or
// a negative number when it can not be read
function problemUploadedConf($problem) {
	requirePHPLib('judger');
	return problemHasConf($problem) ? getUOJConf("/var/uoj_data/upload/{$problem['id']}/problem.conf") : null;
}
// How many tests that problem.conf speaks of. One that does not say has ten, as the judgers
// read it; the form writes 0 for a problem that has no data yet.
function problemUploadedTestCount($problem) {
	$conf = problemUploadedConf($problem);
	if (!is_array($conf)) {
		return 0;
	}
	return isset($conf['n_tests']) ? (validateUInt((string)$conf['n_tests']) ? (int)$conf['n_tests'] : 0) : 10;
}
// Whether the data of a problem still waits to be set up from the names of its files: there
// is no problem.conf, or the one there is was written by the form before there was any data.
// A problem.conf that somebody wrote is never written over because data was uploaded.
function problemAwaitsSetup($problem) {
	$conf = problemUploadedConf($problem);
	return $conf === null || (is_array($conf) && isset($conf['n_tests']) && (string)$conf['n_tests'] === '0'
		&& (!isset($conf['use_builtin_judger']) || $conf['use_builtin_judger'] === 'on'));
}
// Has the data of a problem checked and published. Returns array(whether that was begun, a
// line about it for people).
function problemSync($problem, $actor) {
	requirePHPLib('judger');
	requirePHPLib('data');
	$err = dataSyncProblemData(queryProblemBrief($problem['id']), $actor);
	if ($err) {
		return array(false, '数据没有通过检查，还不能评测：' . trim(html_entity_decode(strip_tags($err))));
	}
	$waiting = dataWaitingVersion($problem['id']);
	return array(true, $waiting ? '数据已提交，评测机编译好题目带的程序后自动发布' : '数据已发布，可以评测了');
}
