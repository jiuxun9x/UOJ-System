<?php

// Taking in the archive somebody uploads as the data of a problem: what it may hold, where
// its files go, and what is wrong with the data before it is synced.

// The limits of an upload, from the configuration: how many files, how many bytes unpacked
// in the whole directory of the problem, how many bytes in one file, and how much smaller
// than its contents an archive may be (0: any).
function uploadLimits() {
	$conf = isset(UOJConfig::$data['data']['upload']) ? UOJConfig::$data['data']['upload'] : array();
	$value = function($key, $default) use ($conf) {
		return isset($conf[$key]) ? (int)$conf[$key] : $default;
	};
	return array(
		'files' => $value('max-files', 5000),
		'bytes' => $value('max-unpacked-mb', 1024) * 1048576,
		'file_bytes' => $value('max-file-mb', 512) * 1048576,
		'ratio' => $value('max-ratio', 0)
	);
}

// what computers leave in archives without being asked
function uploadIsJunk($name) {
	foreach (explode('/', $name) as $part) {
		if ($part === '__MACOSX' || $part === '.DS_Store' || $part === 'Thumbs.db' || $part === 'desktop.ini' || strncmp($part, '._', 2) === 0) {
			return true;
		}
	}
	return false;
}
// Returns '' when the name of an entry is one the site unpacks, or what is wrong with it.
function uploadNameError($name) {
	if (!is_string($name) || $name === '' || strlen($name) > 200) {
		return '文件名为空或太长';
	}
	if (preg_match('/[\x00-\x1f\x7f\\\\]/', $name)) {
		return '文件名里有反斜杠或控制字符';
	}
	if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) {
		return '文件名是绝对路径';
	}
	$parts = explode('/', rtrim($name, '/'));
	if (count($parts) > 6) {
		return '目录层数太多';
	}
	foreach ($parts as $part) {
		if ($part === '' || $part === '.' || $part === '..') {
			return '文件名里有 . 或 .. 这样的路径';
		}
	}
	return '';
}

// Where the entries of an archive go: array(name in the archive => path under the directory
// of the problem). Directories and junk are left out. An archive whose files are all inside
// one directory is unpacked without that directory, which is what an archive is when
// somebody packs a folder instead of its contents.
function uploadTargets($names) {
	$files = array();
	foreach ($names as $name) {
		if (substr($name, -1) !== '/' && !uploadIsJunk($name)) {
			$files[] = $name;
		}
	}
	$top = null;
	foreach ($files as $name) {
		$slash = strpos($name, '/');
		if ($slash === false) {
			// a file at the top: nothing to strip
			$top = false;
			break;
		}
		$dir = substr($name, 0, $slash);
		if ($top === null) {
			$top = $dir;
		} elseif ($top !== $dir) {
			$top = false;
			break;
		}
	}
	// "require" and "download" are directories the data of a problem has itself
	if (in_array($top, array('require', 'download'), true)) {
		$top = false;
	}
	$targets = array();
	foreach ($files as $name) {
		$targets[$name] = is_string($top) ? substr($name, strlen($top) + 1) : $name;
	}
	return $targets;
}

// Returns '' when an archive may be unpacked, or why not. $entries are rows of name, size,
// compressed and is_link; $existing_bytes is what the directory of the problem holds already.
function uploadEntriesError($entries, $limits, $existing_bytes = 0) {
	$names = array();
	$sizes = array();
	$compressed = 0;
	foreach ($entries as $entry) {
		if (uploadIsJunk($entry['name'])) {
			continue;
		}
		$err = uploadNameError($entry['name']);
		if ($err !== '') {
			return "压缩包里的 " . json_encode($entry['name'], JSON_UNESCAPED_UNICODE) . " 不能解压：$err";
		}
		if (!empty($entry['is_link'])) {
			return "压缩包里的 {$entry['name']} 是符号链接，不能解压";
		}
		$names[] = $entry['name'];
		$sizes[$entry['name']] = $entry['size'];
		$compressed += $entry['compressed'];
	}
	$targets = uploadTargets($names);
	if (!$targets) {
		return '压缩包里没有文件';
	}
	if (count($targets) > $limits['files']) {
		return "压缩包里有 " . count($targets) . " 个文件，超过了上限 {$limits['files']} 个";
	}
	if (count(array_unique(array_map('strtolower', $targets))) != count($targets)) {
		return '压缩包里有解压后会互相覆盖的文件（名字只差大小写，或者重复）';
	}
	$total = 0;
	foreach ($targets as $name => $target) {
		if ($sizes[$name] > $limits['file_bytes']) {
			return "压缩包里的 $name 解压后有 " . uploadMegabytes($sizes[$name]) . " MB，超过了单个文件的上限 " . uploadMegabytes($limits['file_bytes']) . " MB";
		}
		$total += $sizes[$name];
	}
	if ($existing_bytes + $total > $limits['bytes']) {
		return "解压后这道题的数据共 " . uploadMegabytes($existing_bytes + $total) . " MB，超过了上限 " . uploadMegabytes($limits['bytes']) . " MB";
	}
	if ($limits['ratio'] > 0 && $compressed > 0 && $total / $compressed > $limits['ratio']) {
		return "压缩包解压后是原来的 " . round($total / $compressed) . " 倍，超过了上限 {$limits['ratio']} 倍";
	}
	return '';
}
function uploadMegabytes($bytes) {
	return round($bytes / 1048576, 1);
}

// the entries of an archive that is open
function uploadZipEntries($zip) {
	$entries = array();
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$stat = $zip->statIndex($i);
		$is_link = false;
		if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys == ZipArchive::OPSYS_UNIX) {
			$is_link = (($attr >> 16) & 0170000) == 0120000;
		}
		$entries[] = array('name' => $stat['name'], 'size' => (int)$stat['size'], 'compressed' => (int)$stat['comp_size'], 'is_link' => $is_link);
	}
	return $entries;
}

// Unpacks an uploaded archive into the directory of a problem. Returns array(how many files
// were written, '') or array(0, why the archive was refused). Nothing is written when the
// archive is refused for what it says about itself; an archive that lies about its sizes is
// stopped at the limit.
function uploadUnpack($zip_path, $dir, $limits) {
	$zip = new ZipArchive();
	if ($zip->open($zip_path) !== true) {
		return array(0, '解压失败：这不是一个完好的 zip 文件');
	}
	$entries = uploadZipEntries($zip);
	list($existing_count, $existing_bytes) = backupTreeSize($dir);
	$err = uploadEntriesError($entries, $limits, $existing_bytes);
	if ($err !== '') {
		$zip->close();
		return array(0, $err);
	}
	$names = array();
	foreach ($entries as $entry) {
		$names[] = $entry['name'];
	}
	$written = 0;
	$budget = $limits['bytes'] - $existing_bytes;
	foreach (uploadTargets($names) as $name => $target) {
		$path = "$dir/$target";
		if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
			$zip->close();
			return array($written, "无法创建目录 " . dirname($target));
		}
		$in = $zip->getStream($name);
		$out = $in ? fopen($path, 'wb') : false;
		if (!$in || !$out) {
			$zip->close();
			return array($written, "解压 $name 失败");
		}
		$file_bytes = 0;
		while (!feof($in)) {
			$chunk = fread($in, 1048576);
			if ($chunk === false) {
				break;
			}
			$file_bytes += strlen($chunk);
			$budget -= strlen($chunk);
			// what the archive said about its sizes was not true
			if ($file_bytes > $limits['file_bytes'] || $budget < 0) {
				fclose($in);
				fclose($out);
				unlink($path);
				$zip->close();
				return array($written, "解压 $name 时超过了大小上限，压缩包里记录的大小不真实");
			}
			fwrite($out, $chunk);
		}
		fclose($in);
		fclose($out);
		$written++;
	}
	$zip->close();
	return array($written, '');
}

// ---- the programs that come with the data of a problem
//
// The judgers build a checker from chk.cpp, an interactor from interactor.cpp, and so on.
// The people who set a problem need not call their files that: problem.conf says which of
// the uploaded files is which program ("chk_source checker.cpp"), and the file is given to
// the judgers under the name they look for.

// the programs problem.conf can name a file for, and what people call them
function dataProgramKinds() {
	return array('chk' => '校验器', 'interactor' => '交互器', 'std' => '标准程序', 'val' => '数据校验器');
}
// What a source file ends with that the judgers can build, or null: the language is read off
// the ending.
function dataProgramSourceSuffix($file_name) {
	if (is_string($file_name) && preg_match('/\.(cpp|c|pas|py)$/D', $file_name, $matches)) {
		return '.' . $matches[1];
	}
	return null;
}
// '' when a file can be named in problem.conf as the source of a program, or why not
function dataProgramSourceError($file_name) {
	if (!is_string($file_name) || $file_name === '' || strlen($file_name) > 100) {
		return '文件名为空或太长';
	}
	// problem.conf is words with blanks between them, and the file lies in the folder itself
	if (preg_match('/[\s\/\\\\\x00-\x1f\x7f]/', $file_name) || $file_name[0] === '.') {
		return '文件名里不能有空格和斜杠，请先给文件改个名字';
	}
	if (dataProgramSourceSuffix($file_name) === null) {
		return '不是评测机能编译的源文件（.cpp、.c、.pas、.py）';
	}
	return '';
}

// What is wrong with the data in the directory of a problem, before anybody syncs it:
// array('errors' => what stops a sync, 'warnings' => what is worth a look, 'facts' => what
// there is). $files are the names in the directory, $conf the problem.conf as getUOJConf()
// returns it (-1: none, -2: one that can not be read).
function uploadPreflight($files, $conf, $hackable) {
	$report = array('errors' => array(), 'warnings' => array(), 'facts' => array());
	$has = array_flip($files);
	if (!$files) {
		$report['errors'][] = '还没有上传任何数据';
		return $report;
	}
	if ($conf === -1 || !isset($has['problem.conf'])) {
		$report['errors'][] = '缺少 problem.conf：在“评测设置”里点保存就会生成，也可以自己写好放在压缩包里一起上传';
		return $report;
	}
	if ($conf === -2 || !is_array($conf)) {
		$report['errors'][] = 'problem.conf 有语法错误：每行应当是“键 值”';
		return $report;
	}
	$on = function($key) use ($conf) {
		return isset($conf[$key]) && $conf[$key] === 'on';
	};
	$used = array('problem.conf' => true, 'require' => true, 'download' => true);
	$missing = array();
	$need = function($name) use (&$used, &$missing, $has) {
		$used[$name] = true;
		if (!isset($has[$name])) {
			$missing[] = $name;
		}
	};
	// the source of a program: the file problem.conf names for it, or else one called after it
	$sources = array();
	$need_source = function($name) use (&$used, &$missing, &$sources, &$report, $has, $conf) {
		if (isset($conf["{$name}_source"])) {
			$file = $conf["{$name}_source"];
			$err = dataProgramSourceError($file);
			if ($err !== '') {
				$report['errors'][] = "{$name}_source（" . dataProgramKinds()[$name] . "的文件）：$err";
			} elseif (!isset($has[$file])) {
				$missing[] = $file;
			}
			$used[$file] = true;
			$sources[$name] = $file;
			return;
		}
		foreach (array("$name.cpp", "$name.c", "$name.pas") as $file) {
			if (isset($has[$file])) {
				$used[$file] = true;
				$sources[$name] = $file;
				return;
			}
		}
		$missing[] = "$name.cpp";
		$sources[$name] = "$name.cpp";
	};

	if (!$on('use_builtin_judger')) {
		$report['warnings'][] = '这道题不使用内置的评测程序（use_builtin_judger 不是 on）：自定义评测程序需要系统管理员同步后才能使用';
		return $report;
	}
	$n_tests = getUOJConfVal($conf, 'n_tests', 10);
	if ((string)$n_tests === '0') {
		// what the form writes for a problem that has no data yet
		$report['errors'][] = '还没有测试数据：上传数据包后，测试点会按文件名自动识别';
		return $report;
	}
	if (!validateUInt($n_tests) || $n_tests <= 0) {
		$report['errors'][] = 'n_tests 必须是正整数';
		return $report;
	}
	if ($n_tests > 5000) {
		$report['errors'][] = "n_tests 是 {$n_tests}，测试点太多了";
		return $report;
	}
	for ($num = 1; $num <= $n_tests; $num++) {
		$need(getUOJProblemInputFileName($conf, $num));
		$need(getUOJProblemOutputFileName($conf, $num));
	}
	$report['facts'][] = "$n_tests 个测试点";
	if (!$on('interaction_mode')) {
		if (isset($conf['use_builtin_checker'])) {
			if (!preg_match('/^[a-zA-Z0-9_]{1,20}$/', $conf['use_builtin_checker'])) {
				$report['errors'][] = 'use_builtin_checker 不是一个内置校验器的名字';
			} else {
				$report['facts'][] = "内置校验器 {$conf['use_builtin_checker']}";
			}
		} else {
			$need_source('chk');
			$report['facts'][] = "自己的校验器 {$sources['chk']}，由评测机编译";
		}
	}
	if ($on('submit_answer')) {
		if ($hackable) {
			$report['errors'][] = '提交答案题不能开启 Hack';
		}
		$report['facts'][] = '提交答案题';
	} else {
		$n_ex_tests = getUOJConfVal($conf, 'n_ex_tests', 0);
		if (!validateUInt($n_ex_tests) || $n_ex_tests > 5000) {
			$report['errors'][] = 'n_ex_tests 必须是不太大的非负整数';
			return $report;
		}
		for ($num = 1; $num <= $n_ex_tests; $num++) {
			$need(getUOJProblemExtraInputFileName($conf, $num));
			$need(getUOJProblemExtraOutputFileName($conf, $num));
		}
		$n_sample_tests = getUOJConfVal($conf, 'n_sample_tests', $n_tests);
		if (!validateUInt($n_sample_tests)) {
			$report['errors'][] = 'n_sample_tests 必须是非负整数';
		} elseif ($n_sample_tests > $n_ex_tests) {
			$report['errors'][] = "n_sample_tests（{$n_sample_tests}）不能大于 n_ex_tests（{$n_ex_tests}）。样例是额外测试点里的前几个；没有写 n_sample_tests 时它等于 n_tests";
		}
		if ($n_ex_tests > 0) {
			$report['facts'][] = "$n_ex_tests 个额外测试点";
		}
		if ($hackable) {
			$need_source('std');
			$need_source('val');
			$report['facts'][] = "可以 Hack：标程 {$sources['std']}，数据校验器 {$sources['val']}";
		} elseif ($on('validate_input_before_test')) {
			$need_source('val');
			$report['facts'][] = "评测前校验输入：数据校验器 {$sources['val']}";
		}
		if ($on('interaction_mode')) {
			$need_source('interactor');
			$report['facts'][] = "交互题，交互器 {$sources['interactor']}";
		}
		$n_passes = getUOJConfVal($conf, 'multi_pass', 0);
		if (!validateUInt((string)$n_passes) || $n_passes > 20) {
			$report['errors'][] = 'multi_pass（最多运行几轮）应是 0 到 20 之间的整数';
		} elseif ($n_passes > 1) {
			$report['facts'][] = "通信题：程序最多运行 $n_passes 轮，每一轮之后由校验器决定是否再运行一轮";
			if ($on('interaction_mode')) {
				$report['errors'][] = '多轮运行（multi_pass）不能同时是交互题（interaction_mode）';
			}
			if ($hackable) {
				$report['errors'][] = '多轮运行的题不能开启 Hack';
			}
			if (isset($conf['use_builtin_checker'])) {
				$report['errors'][] = '多轮运行需要自己的校验器：下一轮的输入由它给出，内置的比较方式不会要求再运行一轮';
			}
		}
	}
	if ($on('submit_answer') && getUOJConfVal($conf, 'multi_pass', 0) > 1) {
		$report['errors'][] = '提交答案题不能多轮运行（multi_pass）';
	}
	if ($missing) {
		$report['errors'][] = '缺少文件：' . join('、', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ' 等 ' . count($missing) . ' 个' : '');
	}
	foreach (array('time_limit' => array(1, 60, '秒'), 'memory_limit' => array(1, 4096, 'MB')) as $key => $range) {
		if (isset($conf[$key]) && (!is_numeric($conf[$key]) || $conf[$key] < $range[0] || $conf[$key] > $range[1])) {
			$report['warnings'][] = "$key 是 {$conf[$key]}，一般在 {$range[0]} 到 {$range[1]} {$range[2]} 之间";
		}
	}
	$unused = array();
	foreach ($files as $file) {
		if (!isset($used[$file]) && !preg_match('/^(chk|std|val|interactor)\.(cpp|c|pas)$/', $file) && !preg_match('/\.h$/', $file)) {
			$unused[] = $file;
		}
	}
	if ($unused) {
		$report['warnings'][] = '有 ' . count($unused) . ' 个文件没有被 problem.conf 用到：' . join('、', array_slice($unused, 0, 8)) . (count($unused) > 8 ? ' 等' : '') . '。测试点的个数或文件名的前后缀可能写错了';
	}
	return $report;
}
