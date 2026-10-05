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
	$need_source = function($name) use (&$used, &$missing, $has) {
		foreach (array("$name.cpp", "$name.c", "$name.pas") as $file) {
			if (isset($has[$file])) {
				$used[$file] = true;
				return;
			}
		}
		$missing[] = "$name.cpp";
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
			$report['facts'][] = '自己的校验器 chk，由评测机编译';
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
			$report['facts'][] = '可以 Hack：需要标程 std 和数据校验器 val';
		}
		if ($on('interaction_mode')) {
			$need_source('interactor');
			$report['facts'][] = '交互题';
		}
		if ($on('run_twice')) {
			$need_source('relay');
			$report['facts'][] = '通信题：程序运行两次，中转程序 relay 由评测机编译';
			if ($on('interaction_mode')) {
				$report['errors'][] = '通信题（run_twice）不能同时是交互题（interaction_mode）';
			}
			if ($hackable) {
				$report['errors'][] = '通信题不能开启 Hack';
			}
		}
	}
	if ($on('submit_answer') && $on('run_twice')) {
		$report['errors'][] = '提交答案题不能同时是通信题（run_twice）';
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
		if (!isset($used[$file]) && !preg_match('/^(chk|std|val|interactor|relay)\.(cpp|c|pas)$/', $file) && !preg_match('/\.h$/', $file)) {
			$unused[] = $file;
		}
	}
	if ($unused) {
		$report['warnings'][] = '有 ' . count($unused) . ' 个文件没有被 problem.conf 用到：' . join('、', array_slice($unused, 0, 8)) . (count($unused) > 8 ? ' 等' : '') . '。测试点的个数或文件名的前后缀可能写错了';
	}
	return $report;
}
