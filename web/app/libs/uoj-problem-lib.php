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
			'needs' => '需要一个交互器（用 testlib.h 写）：上传后在下面选它是哪个文件。测试点可以只有输入文件。'
		),
		'multi_pass' => array(
			'name' => '通信题',
			'description' => '同一个程序运行两轮或更多轮：每一轮结束后校验器检查它的输出，并给出下一轮的输入，最后由校验器判定结果。',
			'needs' => '需要自己的校验器（用 testlib.h 写）：上传后在下面选它是哪个文件。要再运行一轮，校验器就把下一轮的输入写到 nextpass.in 并以 ok 结束，要记住的东西写到 state.txt，写法和 Hydro 的 Multi Pass 相同。测试点可以只有输入文件。'
		),
		'submit_answer' => array(
			'name' => '提交答案题',
			'description' => '选手下载输入文件，提交每个测试点的答案，不提交程序。',
			'needs' => ''
		),
		'grader' => array(
			'name' => '函数交互题',
			'description' => '选手实现指定的函数，和出题人的交互库一起编译运行。',
			'needs' => '交互库要放在 require 文件夹里，叫 implementer.cpp（上传后可以在文件列表里改名、移进去）。'
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
		'custom' => '自己的校验器（用 testlib.h 写的程序，在下面选它的文件）'
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
		'passes' => 2,
		// which of the uploaded files are the programs of the problem; '' for one that was
		// not chosen, which is then looked for by its name
		'checker_file' => '',
		'interactor_file' => '',
		'std_file' => '',
		'val_file' => ''
	);
}
// the programs of a problem that the form chooses a file for: field of the form => the
// program as problem.conf and the judgers call it
function problemProgramFields() {
	return array('checker_file' => 'chk', 'interactor_file' => 'interactor', 'std_file' => 'std', 'val_file' => 'val');
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
	foreach (problemProgramFields() as $field => $kind) {
		if ($get($field) !== '') {
			$err = dataProgramSourceError($get($field));
			if ($err !== '') {
				return array(null, dataProgramKinds()[$kind] . '文件“' . $get($field) . '”：' . $err);
			}
			$settings[$field] = $get($field);
		}
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
	foreach (problemProgramFields() as $field => $kind) {
		if (isset($conf["{$kind}_source"]) && is_string($conf["{$kind}_source"])) {
			$settings[$field] = $conf["{$kind}_source"];
		}
	}
	return $settings;
}

// ---- which files are the programs

// the files that can be the source of a program, among the files of a problem
function problemSourceFiles($names) {
	$sources = array();
	foreach ($names as $name) {
		if (dataProgramSourceError($name) === '') {
			$sources[] = $name;
		}
	}
	usort($sources, 'strnatcasecmp');
	return $sources;
}
// The file that is most likely the source of a program when nobody said which it is, by
// what people call such files; '' when no file looks like it.
function problemGuessProgram($names, $kind) {
	static $patterns = array(
		'chk' => array('/^chk[0-9]*\\./i', '/^checker[^.]*\\./i', '/^(spj|check|chk)/i', '/check|chk|spj/i'),
		'interactor' => array('/^interactor[0-9]*\\./i', '/^interact/i', '/interact/i'),
		'std' => array('/^std[0-9]*\\./i', '/^(std|sol|solution)/i'),
		'val' => array('/^val[0-9]*\\./i', '/^(val|validator)/i', '/valid/i')
	);
	$sources = problemSourceFiles($names);
	foreach ($patterns[$kind] as $pattern) {
		foreach ($sources as $name) {
			if (preg_match($pattern, $name)) {
				return $name;
			}
		}
	}
	return '';
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
		'with_implementer', 'n_subtasks', 'chk_source', 'interactor_source', 'std_source', 'val_source'
	);
	return in_array($key, $managed, true) || preg_match('/^subtask_(end|score)_[0-9]+$/D', $key) === 1;
}
// Builds problem.conf: array(the conf, '') or array(null, why not).
//   $settings   what the form said
//   $found      what problemDetectTests() found
//   $old_conf   the problem.conf there is, for what the form does not decide
//   $names      the files that were uploaded, among which the programs of the problem are;
//               null when it is not known what was uploaded
function problemConfFromSettings($settings, $found, $old_conf = array(), $names = null) {
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
	// Which file is which program. One that was chosen is named; one that was not is looked
	// for among the files by what such files are called. The solution and the validator of a
	// problem that can be hacked are named only when they were chosen.
	$programs = array();
	if ($type === 'interactive') {
		$programs['interactor_file'] = true;
	} elseif ($type === 'multi_pass' || $settings['checker'] === 'custom') {
		$programs['checker_file'] = true;
	}
	if ($type !== 'submit_answer') {
		$programs += array('std_file' => false, 'val_file' => false);
	}
	foreach ($programs as $field => $guessed) {
		$kind = problemProgramFields()[$field];
		$file = isset($settings[$field]) ? (string)$settings[$field] : '';
		if ($file === '' && $guessed && $names !== null) {
			$file = problemGuessProgram($names, $kind);
		}
		if ($file === '') {
			continue;
		}
		if ($names !== null && !in_array($file, $names, true)) {
			return array(null, dataProgramKinds()[$kind] . "文件 $file 不在已上传的文件里");
		}
		$conf["{$kind}_source"] = $file;
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
// What setting a problem up from its settings would come to, without doing any of it: the
// tests that are found among the uploaded files, and the problem.conf that would be written.
// Returns array(array('conf', 'found', 'old_conf', 'notes'), '') or array(null, why not).
function problemPlanSettings($problem, $settings) {
	requirePHPLib('judger');
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	$conf_path = "$upload_dir/problem.conf";
	$old_conf = is_file($conf_path) ? getUOJConf($conf_path) : array();
	$old_conf = is_array($old_conf) ? $old_conf : array();
	$inputs_suffice = $settings['type'] === 'interactive' || $settings['type'] === 'multi_pass';
	$names = problemUploadedNames($upload_dir);
	$found = problemDetectTests($names, $inputs_suffice);
	// the programs are chosen among the files as they will be called
	$renamed = array();
	foreach ($names as $name) {
		$renamed[] = isset($found['renames'][$name]) ? $found['renames'][$name] : $name;
	}
	list($conf, $err) = problemConfFromSettings($settings, $found, $old_conf, $renamed);
	if ($err !== '') {
		return array(null, $err);
	}
	$notes = array();
	if ($conf['n_tests'] == 0) {
		$notes[] = '还没有找到测试数据：上传测试数据后再保存一次，测试点会自动识别';
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
	foreach (array('chk' => array('interactive' => false, 'needed' => $settings['type'] === 'multi_pass' || $settings['checker'] === 'custom'),
			'interactor' => array('interactive' => true, 'needed' => true)) as $kind => $when) {
		if (($settings['type'] === 'interactive') === $when['interactive'] && $when['needed']) {
			$notes[] = isset($conf["{$kind}_source"])
				? dataProgramKinds()[$kind] . '是 ' . $conf["{$kind}_source"]
				: '还没有' . dataProgramKinds()[$kind] . '：上传它的源文件后在评测设置里选上';
		}
	}
	return array(array('conf' => $conf, 'found' => $found, 'old_conf' => $old_conf, 'notes' => $notes), '');
}
// Sets a problem up from its settings and the files that were uploaded for it: finds the
// tests, calls them what the judgers call them, and writes problem.conf.
// Returns array(what was found and done, as lines for people, '') or array(null, why not).
function problemApplySettings($problem, $settings, $actor) {
	list($plan, $err) = problemPlanSettings($problem, $settings);
	if ($err !== '') {
		return array(null, $err);
	}
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	$conf_path = "$upload_dir/problem.conf";
	$err = problemArrangeFiles($upload_dir, $plan['found']);
	if ($err !== '') {
		return array(null, $err);
	}
	if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
		return array(null, '无法创建保存数据的目录');
	}
	if (is_file($conf_path)) {
		unlink($conf_path);
	}
	putUOJConf($conf_path, $plan['conf']);
	auditLog('problem.edit_conf', 'problem', $problem['id'], $plan['old_conf'] ? $plan['old_conf'] : null, $plan['conf'], $actor);
	return array($plan['notes'], '');
}

// ---- problem.conf as its text: for the people who would rather write it

function problemConfText($conf) {
	$text = '';
	if (is_array($conf)) {
		foreach ($conf as $key => $value) {
			$text .= "$key $value\n";
		}
	}
	return $text;
}
// What a text says as a problem.conf: array(the conf, '') or array(null, what is wrong with
// it). Every line is a key and a value with blanks between them; empty lines say nothing.
function problemConfFromText($text) {
	if (!is_string($text) || strlen($text) > 200000 || !mb_check_encoding($text, 'UTF-8')) {
		return array(null, 'problem.conf 太长，或者不是 UTF-8 的文本');
	}
	$conf = array();
	foreach (preg_split('/\r\n|\r|\n/', $text) as $index => $line) {
		if (trim($line) === '') {
			continue;
		}
		$number = $index + 1;
		// the judgers read words with blanks between them, and nothing else
		if (!preg_match('/^ *([^ \t]+) +([^ \t]+) *$/D', $line, $matches)) {
			return array(null, "第 $number 行应是“键 值”：一个键、一个值，中间用空格分开，值里不能有空格");
		}
		if (!preg_match('/^[A-Za-z0-9_]{1,60}$/D', $matches[1])) {
			return array(null, "第 $number 行的键 {$matches[1]} 应由字母、数字和下划线组成");
		}
		if (strlen($matches[2]) > 200 || preg_match('/[\x00-\x1f\x7f]/', $matches[2])) {
			return array(null, "第 $number 行的值太长，或者有不能显示的字符");
		}
		if (isset($conf[$matches[1]])) {
			return array(null, "第 $number 行：{$matches[1]} 写了两次");
		}
		$conf[$matches[1]] = $matches[2];
	}
	if (!$conf) {
		return array(null, 'problem.conf 是空的');
	}
	if (count($conf) > 5000) {
		return array(null, 'problem.conf 的行数太多');
	}
	return array($conf, '');
}
// Writes a problem.conf somebody wrote as it is: no file is renamed for it, and nothing in
// it is second-guessed. Whether it can be judged with is said by the sync that follows.
// Returns '' or what is wrong with the text.
function problemSaveConfText($problem, $text, $actor) {
	requirePHPLib('judger');
	list($conf, $err) = problemConfFromText($text);
	if ($err !== '') {
		return $err;
	}
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
		return '无法创建保存数据的目录';
	}
	$old_conf = is_file("$upload_dir/problem.conf") ? getUOJConf("$upload_dir/problem.conf") : null;
	putUOJConf("$upload_dir/problem.conf", $conf);
	auditLog('problem.edit_conf', 'problem', $problem['id'], is_array($old_conf) ? $old_conf : null, $conf + array('written_as_text' => 1), $actor);
	return '';
}

// ---- the files of a problem, one by one

// The files that were uploaded for a problem, the ones in its folders as well: rows of name
// (with the folder it is in) and size, in the order people read names in.
function problemDataFiles($problem) {
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	$rows = array();
	$dirs = array('');
	while ($dirs && count($rows) < 20000) {
		$dir = array_shift($dirs);
		$names = @scandir("$upload_dir/$dir");
		foreach ($names ? $names : array() as $name) {
			if ($name === '.' || $name === '..' || is_link("$upload_dir/$dir$name")) {
				continue;
			}
			if (is_dir("$upload_dir/$dir$name")) {
				if (substr_count($dir, '/') < 5) {
					$dirs[] = "$dir$name/";
				}
			} elseif (is_file("$upload_dir/$dir$name")) {
				$rows[] = array('name' => "$dir$name", 'size' => filesize("$upload_dir/$dir$name"));
			}
		}
	}
	usort($rows, function($a, $b) {
		// what lies in the folder of the problem itself before what lies in its folders
		$deep = array(strpos($a['name'], '/') !== false, strpos($b['name'], '/') !== false);
		return $deep[0] !== $deep[1] ? ($deep[0] ? 1 : -1) : strnatcasecmp($a['name'], $b['name']);
	});
	return $rows;
}
// What each of the files of a problem is to its problem.conf: name => a few words. A file
// problem.conf does not use is not in it.
function problemFileRoles($names, $conf) {
	$roles = array('problem.conf' => '评测设置');
	if (!is_array($conf)) {
		return $roles;
	}
	$on = function($key) use ($conf) {
		return isset($conf[$key]) && $conf[$key] === 'on';
	};
	if (isset($conf['use_builtin_judger']) && $conf['use_builtin_judger'] !== 'on') {
		return $roles;
	}
	$count = function($key, $default) use ($conf) {
		$n = isset($conf[$key]) ? (string)$conf[$key] : (string)$default;
		return validateUInt($n) ? min((int)$n, 5000) : 0;
	};
	for ($num = 1; $num <= $count('n_tests', 10); $num++) {
		$roles[getUOJProblemInputFileName($conf, $num)] = "测试点 $num 输入";
		$roles[getUOJProblemOutputFileName($conf, $num)] = "测试点 $num 答案";
	}
	if (!$on('submit_answer')) {
		$n_samples = $count('n_sample_tests', $count('n_tests', 10));
		for ($num = 1; $num <= $count('n_ex_tests', 0); $num++) {
			$what = $num <= $n_samples ? "样例 $num" : "额外测试点 $num";
			$roles[getUOJProblemExtraInputFileName($conf, $num)] = "$what 输入";
			$roles[getUOJProblemExtraOutputFileName($conf, $num)] = "$what 答案";
		}
	}
	$has = array_flip($names);
	foreach (dataProgramKinds() as $kind => $label) {
		if (isset($conf["{$kind}_source"])) {
			$roles[$conf["{$kind}_source"]] = $label;
			continue;
		}
		// one that is not named is the file the judgers find by its name
		$wanted = $kind === 'interactor' ? $on('interaction_mode') : ($kind === 'chk' ? !$on('interaction_mode') && !isset($conf['use_builtin_checker']) : true);
		foreach (array("$kind.cpp", "$kind.c", "$kind.pas") as $file) {
			if ($wanted && isset($has[$file])) {
				$roles[$file] = $label;
				break;
			}
		}
	}
	foreach ($names as $name) {
		if (strncmp($name, 'require/', 8) === 0) {
			$roles[$name] = '和选手的程序一起编译';
		} elseif (strncmp($name, 'download/', 9) === 0) {
			$roles[$name] = '给选手下载';
		}
	}
	return $roles;
}
// '' when a name is one a file of a problem can have, or what is wrong with it
function problemFileNameError($name) {
	$err = uploadNameError($name);
	if ($err !== '') {
		return $err;
	}
	if (uploadIsJunk($name) || substr($name, -1) === '/') {
		return '这不是数据文件的名字';
	}
	return '';
}
// the path of a file of a problem that is there, or null: never a link, never outside
function problemFilePath($problem, $name) {
	if (problemFileNameError($name) !== '') {
		return null;
	}
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	$path = "$upload_dir/$name";
	$dir = $upload_dir;
	foreach (explode('/', $name) as $part) {
		$dir .= "/$part";
		if (is_link($dir)) {
			return null;
		}
	}
	return is_file($path) ? $path : null;
}
// each of these returns '' or why it was refused
function problemDeleteFile($problem, $name, $actor) {
	$path = problemFilePath($problem, $name);
	if ($path === null) {
		return '没有这个文件';
	}
	$size = filesize($path);
	if (!@unlink($path)) {
		return '无法删除这个文件';
	}
	// a folder that holds nothing any more goes with its last file
	$dir = dirname($path);
	while ($dir !== "/var/uoj_data/upload/{$problem['id']}" && @rmdir($dir)) {
		$dir = dirname($dir);
	}
	auditLog('problem.delete_file', 'problem', $problem['id'], array('name' => $name, 'size' => $size), null, $actor);
	return '';
}
function problemRenameFile($problem, $name, $new_name, $actor) {
	requirePHPLib('judger');
	$path = problemFilePath($problem, $name);
	if ($path === null) {
		return '没有这个文件';
	}
	$new_name = is_string($new_name) ? trim($new_name) : '';
	$err = problemFileNameError($new_name);
	if ($err !== '') {
		return "新的名字不能用：$err";
	}
	if ($new_name === $name) {
		return '';
	}
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	$target = "$upload_dir/$new_name";
	if (file_exists($target) || is_link($target)) {
		return "已经有一个叫 $new_name 的文件了";
	}
	$dir = $upload_dir;
	foreach (array_slice(explode('/', $new_name), 0, -1) as $part) {
		$dir .= "/$part";
		if (is_link($dir) || (file_exists($dir) && !is_dir($dir)) || (!is_dir($dir) && !@mkdir($dir, 0755))) {
			return "无法把文件放进 $part";
		}
	}
	if (!@rename($path, $target)) {
		return '无法给这个文件改名';
	}
	// a program that problem.conf names is still that program under its new name
	$conf = is_file("$upload_dir/problem.conf") ? getUOJConf("$upload_dir/problem.conf") : null;
	if (is_array($conf)) {
		$changed = false;
		foreach (dataProgramKinds() as $kind => $label) {
			if (isset($conf["{$kind}_source"]) && $conf["{$kind}_source"] === $name && dataProgramSourceError($new_name) === '') {
				$conf["{$kind}_source"] = $new_name;
				$changed = true;
			}
		}
		if ($changed) {
			putUOJConf("$upload_dir/problem.conf", $conf);
		}
	}
	auditLog('problem.rename_file', 'problem', $problem['id'], array('name' => $name), array('name' => $new_name), $actor);
	return '';
}
// Takes the files a form sent as data of a problem, several at once. An archive is unpacked
// into the folder of the problem; any other file is put there under its own name, in the
// place of a file of that name. Returns array(how many files were written, what was refused
// and why, as lines for people).
function problemTakeUploadedFiles($problem, $field, $actor) {
	if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
		return array(0, array());
	}
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
		return array(0, array('无法创建保存数据的目录'));
	}
	$limits = uploadLimits();
	$written = 0;
	$refused = array();
	$taken = array();
	// how much the folder of the problem holds: counted once, and kept up with what is written
	list($existing_count, $existing_bytes) = backupTreeSize($upload_dir);
	foreach ($_FILES[$field]['name'] as $index => $sent_name) {
		if ($_FILES[$field]['error'][$index] == UPLOAD_ERR_NO_FILE) {
			continue;
		}
		$name = uojFileBaseName(str_replace('\\', '/', (string)$sent_name));
		if ($_FILES[$field]['error'][$index] > 0) {
			$refused[] = "$name 没有传完（错误 " . (int)$_FILES[$field]['error'][$index] . "），可能是文件太大";
			continue;
		}
		$tmp_name = $_FILES[$field]['tmp_name'][$index];
		if (strtolower(substr($name, -4)) === '.zip') {
			list($unpacked, $err) = uploadUnpack($tmp_name, $upload_dir, $limits);
			$facts = array('archive' => $name, 'size' => filesize($tmp_name), 'sha256' => hash_file('sha256', $tmp_name));
			if ($err !== '') {
				$refused[] = "{$name}：$err";
				auditLog('problem.upload_refused', 'problem', $problem['id'], null, $facts + array('error' => $err), $actor);
			} else {
				$written += $unpacked;
				auditLog('problem.upload_data', 'problem', $problem['id'], null, $facts + array('files' => $unpacked), $actor);
			}
			list($existing_count, $existing_bytes) = backupTreeSize($upload_dir);
			continue;
		}
		$err = problemFileNameError($name);
		if ($err !== '') {
			$refused[] = "{$name}：$err";
			continue;
		}
		$size = filesize($tmp_name);
		$is_new = !is_file("$upload_dir/$name");
		$replaced = $is_new ? 0 : filesize("$upload_dir/$name");
		if ($size > $limits['file_bytes']) {
			$refused[] = "$name 有 " . uploadMegabytes($size) . " MB，超过了单个文件的上限 " . uploadMegabytes($limits['file_bytes']) . " MB";
			continue;
		}
		if ($existing_bytes - $replaced + $size > $limits['bytes'] || ($is_new && $existing_count >= $limits['files'])) {
			$refused[] = "{$name}：这道题的数据已经到了上限（" . uploadMegabytes($limits['bytes']) . " MB、{$limits['files']} 个文件）";
			continue;
		}
		if (is_dir("$upload_dir/$name") || is_link("$upload_dir/$name") || !move_uploaded_file($tmp_name, "$upload_dir/$name")) {
			$refused[] = "{$name}：无法保存";
			continue;
		}
		chmod("$upload_dir/$name", 0644);
		$existing_bytes += $size - $replaced;
		$existing_count += $is_new ? 1 : 0;
		$written++;
		$taken[$name] = $size;
	}
	if ($taken) {
		auditLog('problem.upload_data', 'problem', $problem['id'], null, array('files' => count($taken), 'names' => array_slice(array_keys($taken), 0, 50), 'size' => array_sum($taken)), $actor);
	}
	return array($written, $refused);
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

// ---- finding a problem by what one remembers of it: its number, or a piece of its title

// what the title of a problem reads as: titles are kept the way the pages print them
function problemPlainTitle($title) {
	return html_entity_decode(strip_tags((string)$title), ENT_QUOTES, 'UTF-8');
}
// How well a problem answers what somebody typed. 0 is the problem with that number, 1 a
// number that begins so, 2 a title with these words in it, 3 a title with these letters in
// this order; null is a problem that does not answer it at all. Nothing typed asks for nothing.
function problemPickRank($query, $number, $title) {
	$query = trim((string)$query);
	if ($query === '') {
		return 3;
	}
	$digits = ltrim($query, '#');
	if (preg_match('/^[0-9]{1,10}$/D', $digits)) {
		$digits = ltrim($digits, '0');
		if ($digits !== '' && (string)$number === $digits) {
			return 0;
		}
		if ($digits !== '' && strpos((string)$number, $digits) === 0) {
			return 1;
		}
	}
	$title = mb_strtolower(problemPlainTitle($title), 'UTF-8');
	$query = mb_strtolower($query, 'UTF-8');
	if (mb_strpos($title, $query, 0, 'UTF-8') !== false) {
		return 2;
	}
	$from = 0;
	foreach (preg_split('//u', preg_replace('/\s+/u', '', $query), -1, PREG_SPLIT_NO_EMPTY) as $letter) {
		$at = mb_strpos($title, $letter, $from, 'UTF-8');
		if ($at === false) {
			return null;
		}
		$from = $at + 1;
	}
	return 3;
}
// Where somebody may look for problems: array(the condition in SQL, the column that holds
// the numbers people call the problems by), or null when they may not look there at all.
//   $scope     'site', or the address name of a domain
//   $purpose   'manage' for the problems one may put into a contest, anything else for the
//              problems one may read
function problemPickScope($actor, $scope, $purpose) {
	if ($actor == null) {
		return null;
	}
	$mine = "problems.id in (select problem_id from problems_permissions where username = '".DB::escape($actor['username'])."')";
	if ($scope === 'site') {
		if (isSiteAdmin($actor)) {
			return array('problems.owner_domain_id is null', 'id');
		}
		return array('problems.owner_domain_id is null and ' . ($purpose === 'manage' ? $mine : "(problems.is_hidden = 0 or $mine)"), 'id');
	}
	$domain = is_string($scope) && validateDomainSlug($scope) ? queryDomainBySlug($scope) : null;
	if (!$domain || !can($actor, 'domain.view', $domain)) {
		return null;
	}
	// what is hidden in a domain is for the people who teach there
	$cond = "problems.owner_domain_id = {$domain['id']} and problems.domain_pid is not null";
	if (!can($actor, 'domain.teach', $domain)) {
		$cond .= ' and problems.is_hidden = 0';
	}
	return array($cond, 'domain_pid');
}
// The problems that answer what somebody typed, the best answers first: rows of number,
// title and hidden. Null when the user may not look there.
function problemPick($actor, $scope, $purpose, $query, $limit = 20) {
	$where = problemPickScope($actor, $scope, $purpose);
	if ($where === null) {
		return null;
	}
	list($cond, $column) = $where;
	$query = mb_substr(trim((string)$query), 0, 50, 'UTF-8');
	if ($query !== '') {
		// the letters in their order, as the titles are kept
		$like = '%';
		foreach (preg_split('//u', preg_replace('/\s+/u', '', $query), -1, PREG_SPLIT_NO_EMPTY) as $letter) {
			$like .= DB::escape(addcslashes(HTML::escape($letter), '\\%_')) . '%';
		}
		$match = "problems.title like '$like'";
		$digits = ltrim(ltrim($query, '#'), '0');
		if ($digits !== '' && preg_match('/^[0-9]{1,10}$/D', $digits)) {
			$match = "($match or problems.$column like '$digits%')";
		}
		$cond .= " and $match";
	}
	$found = array();
	foreach (DB::selectAll("select problems.id, problems.$column as number, problems.title, problems.is_hidden from problems where $cond order by problems.$column desc limit 500") as $row) {
		$rank = problemPickRank($query, $row['number'], $row['title']);
		if ($rank !== null) {
			$found[] = array('rank' => $rank, 'number' => (int)$row['number'], 'title' => problemPlainTitle($row['title']), 'hidden' => (bool)$row['is_hidden']);
		}
	}
	// the newest first among answers that are as good as each other, as in the list that
	// nothing was typed for; among numbers that begin the same, the smallest
	usort($found, function($a, $b) {
		if ($a['rank'] != $b['rank']) {
			return $a['rank'] < $b['rank'] ? -1 : 1;
		}
		return $a['rank'] == 1 ? $a['number'] - $b['number'] : $b['number'] - $a['number'];
	});
	$rows = array();
	foreach (array_slice($found, 0, $limit) as $row) {
		unset($row['rank']);
		$rows[] = $row;
	}
	return $rows;
}
// the problems with these numbers, in the order of the numbers; one that is not there, or
// not to be seen, is left out
function problemPickByNumbers($actor, $scope, $purpose, $numbers) {
	$where = problemPickScope($actor, $scope, $purpose);
	if ($where === null) {
		return null;
	}
	list($cond, $column) = $where;
	$wanted = array();
	foreach ($numbers as $number) {
		if (validateUInt((string)$number) && $number > 0 && count($wanted) < 100) {
			$wanted[(int)$number] = true;
		}
	}
	if (!$wanted) {
		return array();
	}
	$known = array();
	foreach (DB::selectAll("select problems.$column as number, problems.title, problems.is_hidden from problems where $cond and problems.$column in (".join(',', array_keys($wanted)).")") as $row) {
		$known[(int)$row['number']] = array('number' => (int)$row['number'], 'title' => problemPlainTitle($row['title']), 'hidden' => (bool)$row['is_hidden']);
	}
	$rows = array();
	foreach (array_keys($wanted) as $number) {
		if (isset($known[$number])) {
			$rows[] = $known[$number];
		}
	}
	return $rows;
}
