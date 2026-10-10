<?php

// Problems that come from somewhere else, whole.
//
// A template: one Markdown file that somebody fills in. It begins with a few lines that say
// what the problem is called and what its limits are, between two lines of "---", and the
// rest is the statement. importTemplateText() is the one that is handed out.
//
// A package: a zip with a folder for every problem, laid out the way Hydro exports problems:
//
//     <any name>/problem.yaml            title, tag
//     <any name>/problem_zh.md           the statement (or problem.md, with or without the
//                                        lines of a template at its top)
//     <any name>/testdata/               the tests and the programs that judge them, with
//                                        Hydro's config.yaml or a problem.conf of this site
//     <any name>/additional_file/        files for the people who solve it
//
// A zip that is one such folder is one problem, and so is a zip of filled-in templates a
// problem for each. What Hydro says about a problem in config.yaml is turned into the
// settings of this site as far as they say the same thing; where they do not, the problem is
// imported with what could be kept, and it is said what could not.

define('UOJ_IMPORT_MAX_PROBLEMS', 50);

// the template that is handed out for people to fill in
function importTemplateText() {
	return <<<'EOD'
---
# 题目模板：把下面的内容改成你的题目，然后在“导入题目”里选这个文件。
# 以 # 开头的是说明，可以删掉。这几行写在两条 --- 之间，后面的全部是题面。
title: 这里写题目名称
time_limit: 1          # 时间限制，单位是秒，可以写小数（如 2.5）
memory_limit: 256      # 内存限制，单位是 MB
tags: [标签一, 标签二]   # 可以不写
public: false          # 写 true 表示导入后直接公开；不写就是隐藏
---

## 题目描述

在这里写题目描述。公式写在两个美元符号之间，例如 $1 \le n \le 10^5$。

## 输入格式

第一行一个整数 $n$。

## 输出格式

一行一个整数，表示答案。

## 样例

```input1
3
```

```output1
6
```

## 样例说明

这里解释样例（没有可以删掉这一节）。

## 数据范围

对于 $100\%$ 的数据，$1 \le n \le 10^5$。

EOD;
}

// ---- reading what a file says

// A value as it is written in a line of YAML: without the comment behind it and without its
// quotes; a list written in brackets is a list.
function importYamlScalar($value) {
	$value = trim($value);
	if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
		$end = strpos($value, $value[0], 1);
		return $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
	}
	// a comment begins with a "#" that has a blank before it
	$value = trim(preg_replace('/(^|\s)#.*$/u', '', $value));
	if ($value !== '' && $value[0] === '[' && substr($value, -1) === ']') {
		$list = array();
		foreach (preg_split('/[,，]/u', substr($value, 1, -1)) as $item) {
			$item = importYamlScalar($item);
			if ($item !== '') {
				$list[] = $item;
			}
		}
		return $list;
	}
	return $value;
}
// The little of YAML that a template needs: "key: value" lines, a list in brackets or as
// lines that begin with "- ", and comments. It is what reads a template where the extension
// that reads all of YAML is not installed.
function importSimpleYaml($text) {
	$data = array();
	$list_of = null;
	foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
		if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
			continue;
		}
		if ($list_of !== null && preg_match('/^\s*-\s+(.*)$/u', $line, $matches)) {
			$item = importYamlScalar($matches[1]);
			if (is_string($item) && $item !== '') {
				$data[$list_of][] = $item;
			}
			continue;
		}
		$list_of = null;
		if (!preg_match('/^([^\s:#][^:]*?)\s*[:：]\s*(.*)$/u', $line, $matches)) {
			continue;
		}
		$value = importYamlScalar($matches[2]);
		if ($value === '') {
			// the items of a list may follow
			$list_of = $matches[1];
			$value = array();
		}
		$data[$matches[1]] = $value;
	}
	return $data;
}
// what a YAML text says, as an array; null when it says nothing that can be read
function importYaml($text) {
	if (function_exists('yaml_parse')) {
		// nothing in a file that was uploaded is an object of this program
		ini_set('yaml.decode_php', '0');
		$data = @yaml_parse((string)$text);
		return is_array($data) ? $data : null;
	}
	$data = importSimpleYaml($text);
	return $data ? $data : null;
}
// whether all of YAML can be read here: a config.yaml of Hydro needs it
function importReadsAllYaml() {
	return function_exists('yaml_parse');
}
// The lines at the top of a Markdown file, between two lines of "---", and the text after
// them: array(what the lines say or null, the text).
function importFrontMatter($text) {
	$text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(array("\r\n", "\r"), "\n", (string)$text));
	if (!preg_match('/\A---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)/s', $text, $matches)) {
		return array(null, $text);
	}
	$meta = importYaml($matches[1]);
	return array(is_array($meta) ? $meta : array(), ltrim(substr($text, strlen($matches[0])), "\n"));
}

// "1s", "1500ms", "2.5": the seconds a program may run, the way the settings keep them; null
// for what is no time
function importSeconds($value) {
	if (!is_scalar($value) || !preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(ms|s|秒|毫秒)?\s*$/iu', (string)$value, $matches)) {
		return null;
	}
	$unit = isset($matches[2]) ? strtolower($matches[2]) : 's';
	$seconds = (float)$matches[1] / ($unit === 'ms' || $unit === '毫秒' ? 1000 : 1);
	if ($seconds <= 0 || $seconds > 600) {
		return null;
	}
	return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
}
// "256m", "256mb", "1g", "262144k", "256": megabytes; null for what is no amount of memory
function importMegabytes($value) {
	if (!is_scalar($value) || !preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(k|kb|kib|m|mb|mib|g|gb|gib)?\s*$/i', (string)$value, $matches)) {
		return null;
	}
	$unit = isset($matches[2]) ? strtolower($matches[2][0]) : 'm';
	$megabytes = (float)$matches[1] * ($unit === 'k' ? 1 / 1024 : ($unit === 'g' ? 1024 : 1));
	$megabytes = (int)max(1, round($megabytes));
	return $megabytes > 16384 ? null : $megabytes;
}
// a list of tags, written as a list or as words with commas between
function importTags($value) {
	$tags = array();
	foreach (is_array($value) ? $value : preg_split('/[,，]/u', (string)$value) as $tag) {
		$tag = is_scalar($tag) ? trim((string)$tag) : '';
		if ($tag !== '' && strlen($tag) <= 30 && !in_array($tag, $tags, true) && count($tags) < 10) {
			$tags[] = $tag;
		}
	}
	return $tags;
}
function importIsYes($value) {
	return $value === true || in_array(strtolower(trim((string)(is_scalar($value) ? $value : ''))), array('true', 'yes', 'on', '1', '是', '公开'), true);
}

// What the lines at the top of a template say: array(what of the problem itself they say,
// what of its settings, lines for people about what was not understood). A key may be
// written in English or in Chinese.
function importTemplateMeta($meta) {
	$names = array(
		'title' => array('title', '标题', '题目', '题目名称'),
		'time_limit' => array('time_limit', 'time', '时间限制', '时限'),
		'memory_limit' => array('memory_limit', 'memory', '内存限制', '空间限制'),
		'tags' => array('tags', 'tag', '标签'),
		'public' => array('public', '公开'),
		'type' => array('type', '类型', '题目类型'),
		'checker' => array('checker', '比较方式', '校验器')
	);
	$said = array();
	foreach ($names as $key => $aliases) {
		foreach ($aliases as $alias) {
			if (is_array($meta) && array_key_exists($alias, $meta)) {
				$said[$key] = $meta[$alias];
				break;
			}
		}
	}
	$basics = array();
	$settings = array();
	$notes = array();
	if (isset($said['title']) && is_scalar($said['title'])) {
		$basics['title'] = trim((string)$said['title']);
	}
	if (isset($said['tags'])) {
		$basics['tags'] = importTags($said['tags']);
	}
	if (isset($said['public'])) {
		$basics['public'] = importIsYes($said['public']);
	}
	if (isset($said['time_limit'])) {
		$seconds = importSeconds($said['time_limit']);
		if ($seconds === null) {
			$notes[] = '时间限制“' . (is_scalar($said['time_limit']) ? $said['time_limit'] : '') . '”看不懂，用了 1 秒';
		} else {
			$settings['time_limit'] = $seconds;
		}
	}
	if (isset($said['memory_limit'])) {
		$megabytes = importMegabytes($said['memory_limit']);
		if ($megabytes === null) {
			$notes[] = '内存限制“' . (is_scalar($said['memory_limit']) ? $said['memory_limit'] : '') . '”看不懂，用了 256 MB';
		} else {
			$settings['memory_limit'] = $megabytes;
		}
	}
	if (isset($said['type']) && is_scalar($said['type'])) {
		if (isset(problemTypes()[(string)$said['type']])) {
			$settings['type'] = (string)$said['type'];
		} else {
			$notes[] = "题目类型“{$said['type']}”不认识，按传统题导入";
		}
	}
	if (isset($said['checker']) && is_scalar($said['checker'])) {
		if (isset(problemCheckers()[(string)$said['checker']])) {
			$settings['checker'] = (string)$said['checker'];
		} else {
			$notes[] = "比较方式“{$said['checker']}”不认识，用了默认的";
		}
	}
	return array($basics, $settings, $notes);
}

// What a config.yaml of Hydro says, as far as this site can say the same.
//   $config   the config.yaml, parsed
//   $names    the files that came with it, among which its tests and programs are
// Returns array('settings' => what of the settings it decides, 'cases' => the tests in their
// order as rows of array(input, output or null), or null when it does not name them,
// 'notes' => lines for people about what could not be kept).
function importHydroConfig($config, $names) {
	$settings = array();
	$notes = array();
	$cases = null;
	if (!is_array($config)) {
		return array('settings' => $settings, 'cases' => $cases, 'notes' => $notes);
	}
	$has = function($name) use ($names) {
		return is_string($name) && $name !== '' && in_array($name, $names, true);
	};
	$type = isset($config['type']) && is_scalar($config['type']) ? strtolower((string)$config['type']) : 'default';
	if ($type === 'interactive') {
		$settings['type'] = 'interactive';
	} elseif ($type === 'submit_answer') {
		$settings['type'] = 'submit_answer';
	} elseif ($type !== 'default') {
		$notes[] = "Hydro 的题目类型“{$type}”这里没有对应的，按传统题导入了，请在评测设置里检查";
	}
	if (isset($config['time'])) {
		$seconds = importSeconds($config['time']);
		if ($seconds !== null) {
			$settings['time_limit'] = $seconds;
		} else {
			$notes[] = 'config.yaml 里的时间限制看不懂，用了 1 秒';
		}
	}
	if (isset($config['memory'])) {
		$megabytes = importMegabytes($config['memory']);
		if ($megabytes !== null) {
			$settings['memory_limit'] = $megabytes;
		} else {
			$notes[] = 'config.yaml 里的内存限制看不懂，用了 256 MB';
		}
	}
	// the checker: Hydro's own way of comparing is the usual one here; a checker written
	// with testlib is a checker here as well; the other kinds are programs of other judges
	$checker_type = isset($config['checker_type']) && is_scalar($config['checker_type']) ? strtolower((string)$config['checker_type']) : 'default';
	if (isset($config['checker']) && is_string($config['checker']) && $config['checker'] !== '') {
		if ($has($config['checker'])) {
			$settings['checker'] = 'custom';
			$settings['checker_file'] = $config['checker'];
			if ($checker_type !== 'testlib') {
				$notes[] = "校验器 {$config['checker']} 是按 {$checker_type} 的约定写的，这里只支持 testlib 的写法：需要改写后重新上传";
			}
		} else {
			$notes[] = "config.yaml 说校验器是 {$config['checker']}，但数据里没有这个文件";
		}
	} elseif ($checker_type === 'strict') {
		$settings['checker'] = 'fcmp';
	}
	if (isset($config['interactor']) && is_string($config['interactor']) && $has($config['interactor'])) {
		$settings['interactor_file'] = $config['interactor'];
	}
	if (isset($config['filename']) && is_scalar($config['filename']) && (string)$config['filename'] !== '') {
		$notes[] = "这道题在 Hydro 上用文件输入输出（{$config['filename']}.in / .out），这里只支持标准输入输出，请在题面里说明";
	}
	// the tests, where the config names them
	if (isset($config['subtasks']) && is_array($config['subtasks']) && $config['subtasks']) {
		$cases = array();
		$rows = array();
		$plain = true;
		foreach ($config['subtasks'] as $subtask) {
			if (!is_array($subtask) || !isset($subtask['cases']) || !is_array($subtask['cases'])) {
				$cases = null;
				break;
			}
			foreach ($subtask['cases'] as $case) {
				$input = is_array($case) && isset($case['input']) ? (string)$case['input'] : '';
				$output = is_array($case) && isset($case['output']) ? (string)$case['output'] : '';
				if (!$has($input)) {
					$notes[] = "config.yaml 里的测试点 $input 在数据里找不到，跳过了";
					continue;
				}
				$cases[] = array($input, $has($output) ? $output : null);
			}
			$rows[] = array(count($cases), isset($subtask['score']) && is_numeric($subtask['score']) ? (int)round($subtask['score']) : 0);
			$kind = isset($subtask['type']) && is_scalar($subtask['type']) ? strtolower((string)$subtask['type']) : 'min';
			if ($kind !== 'min' || !empty($subtask['if'])) {
				$plain = false;
			}
		}
		if ($cases !== null) {
			$total = array_sum(array_column($rows, 1));
			$ends = array_column($rows, 0);
			if (count($rows) >= 2 && $plain && $total == 100 && count(array_unique($ends)) == count($ends) && !in_array(0, $ends, true)) {
				$settings['scoring'] = 'subtasks';
				$settings['subtasks'] = $rows;
			} elseif (count($rows) >= 2) {
				$notes[] = '子任务没有照原样导入（' . (!$plain ? '有子任务依赖或不是“全部通过才得分”' : ($total != 100 ? "分值加起来是 $total 不是 100" : '有空的子任务')) . '），现在按测试点平均给分，请在评测设置里重新填';
			}
			if (!$cases) {
				$cases = null;
			}
		}
	}
	return array('settings' => $settings, 'cases' => $cases, 'notes' => $notes);
}

// ---- taking a package apart

// Unpacks a package into a folder that is empty, keeping the folders of the package.
// Returns '' or why it was refused. Nothing that could leave the folder is written.
function importUnpack($zip_path, $dir, $limits) {
	$zip = new ZipArchive();
	if ($zip->open($zip_path) !== true) {
		return '这不是一个完好的 zip 文件';
	}
	$entries = uploadZipEntries($zip);
	$total = 0;
	$files = array();
	foreach ($entries as $entry) {
		if (uploadIsJunk($entry['name']) || substr($entry['name'], -1) === '/') {
			continue;
		}
		$err = uploadNameError($entry['name']);
		if ($err !== '') {
			$zip->close();
			return '压缩包里的 ' . json_encode($entry['name'], JSON_UNESCAPED_UNICODE) . " 不能解压：$err";
		}
		if (!empty($entry['is_link'])) {
			$zip->close();
			return "压缩包里的 {$entry['name']} 是符号链接，不能解压";
		}
		if ($entry['size'] > $limits['file_bytes']) {
			$zip->close();
			return "压缩包里的 {$entry['name']} 太大了";
		}
		$total += $entry['size'];
		$files[] = $entry['name'];
	}
	if (!$files) {
		$zip->close();
		return '压缩包里没有文件';
	}
	// a package may hold many problems, each within the limits of one
	if (count($files) > $limits['files'] * 4 || $total > $limits['bytes'] * 4) {
		$zip->close();
		return '压缩包解压后太大了（' . count($files) . ' 个文件，' . uploadMegabytes($total) . ' MB）';
	}
	$budget = $limits['bytes'] * 4;
	foreach ($files as $name) {
		$path = "$dir/$name";
		if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
			$zip->close();
			return "无法创建目录 " . dirname($name);
		}
		$in = $zip->getStream($name);
		$out = $in ? fopen($path, 'wb') : false;
		if (!$in || !$out) {
			$zip->close();
			return "解压 $name 失败";
		}
		while (!feof($in)) {
			$chunk = fread($in, 1048576);
			if ($chunk === false) {
				break;
			}
			$budget -= strlen($chunk);
			if ($budget < 0) {
				fclose($in);
				fclose($out);
				$zip->close();
				return '解压时超过了大小上限，压缩包里记录的大小不真实';
			}
			fwrite($out, $chunk);
		}
		fclose($in);
		fclose($out);
	}
	$zip->close();
	return '';
}
// the files below a folder, as paths from it with "/" between
function importFilesBelow($dir, $prefix = '') {
	$files = array();
	foreach (scandir($dir) as $name) {
		if ($name === '.' || $name === '..') {
			continue;
		}
		if (is_dir("$dir/$name")) {
			$files = array_merge($files, importFilesBelow("$dir/$name", "$prefix$name/"));
		} elseif (is_file("$dir/$name")) {
			$files[] = "$prefix$name";
		}
	}
	return $files;
}
// Where the problems of a package are: array(the folders that are a problem each, '' for the
// package itself; the filled-in templates that lie outside of them).
function importFindProblems($files) {
	$roots = array();
	foreach ($files as $file) {
		$base = basename($file);
		if ($base === 'problem.yaml' || $base === 'problem.yml' || preg_match('/^problem(_[A-Za-z_]+)?\.md$/D', $base)) {
			$root = dirname($file) === '.' ? '' : dirname($file);
			$roots[$root] = true;
		}
	}
	$roots = array_keys($roots);
	sort($roots);
	$templates = array();
	foreach ($files as $file) {
		if (strtolower(substr($file, -3)) !== '.md' || strtolower(basename($file)) === 'readme.md') {
			continue;
		}
		$inside = false;
		foreach ($roots as $root) {
			if ($root === '' || strpos($file, "$root/") === 0) {
				$inside = true;
			}
		}
		if (!$inside) {
			$templates[] = $file;
		}
	}
	sort($templates);
	return array($roots, $templates);
}
// What a filled-in template is: a problem with its statement and nothing else.
function importReadTemplate($text, $fallback_title) {
	list($meta, $statement) = importFrontMatter($text);
	list($basics, $settings, $notes) = importTemplateMeta($meta);
	if ($meta === null) {
		$notes[] = '文件开头没有两条 --- 之间的那几行，题目名称用了文件名，限制用了默认值';
	}
	return array(
		'title' => isset($basics['title']) && $basics['title'] !== '' ? $basics['title'] : $fallback_title,
		'tags' => isset($basics['tags']) ? $basics['tags'] : array(),
		'public' => isset($basics['public']) ? $basics['public'] : null,
		'statement_md' => $statement,
		'settings' => $settings,
		'data' => array(),
		'cases' => null,
		'native' => false,
		'attachments' => array(),
		'notes' => $notes
	);
}
// What a folder of a package is: a problem with everything it comes with.
//   $dir     where the package was unpacked
//   $root    the folder of the problem in it, '' for the package itself
//   $files   the files of the package, as importFilesBelow() gives them
function importReadPackage($dir, $root, $files) {
	$prefix = $root === '' ? '' : "$root/";
	$own = array();
	foreach ($files as $file) {
		if ($prefix === '' || strpos($file, $prefix) === 0) {
			$own[] = substr($file, strlen($prefix));
		}
	}
	$read = function($name) use ($dir, $prefix) {
		return file_get_contents("$dir/$prefix$name");
	};
	// the statement: the Chinese one first, as Hydro names them
	$statement_file = null;
	foreach (array('problem_zh.md', 'problem_zh_CN.md', 'problem.md', 'problem_en.md') as $name) {
		if (in_array($name, $own, true)) {
			$statement_file = $name;
			break;
		}
	}
	if ($statement_file === null) {
		foreach ($own as $name) {
			if (preg_match('/^problem(_[A-Za-z_]+)?\.md$/D', $name)) {
				$statement_file = $name;
				break;
			}
		}
	}
	$package = importReadTemplate($statement_file === null ? '' : $read($statement_file), $root === '' ? '导入的题目' : basename($root));
	// the first note is about the lines of a template, which a statement of Hydro does not have
	$package['notes'] = array_values(array_filter($package['notes'], function($note) {
		return strpos($note, '两条 ---') === false;
	}));
	if ($statement_file === null) {
		$package['notes'][] = '没有找到题面文件（problem_zh.md 或 problem.md），题面是空的';
	}
	foreach (array('problem.yaml', 'problem.yml') as $name) {
		if (in_array($name, $own, true)) {
			$said = importYaml($read($name));
			if (is_array($said)) {
				if (isset($said['title']) && is_scalar($said['title']) && trim((string)$said['title']) !== '') {
					$package['title'] = trim((string)$said['title']);
				}
				if (isset($said['tag'])) {
					$package['tags'] = importTags($said['tag']);
				}
			}
			break;
		}
	}
	// the data, and the files for the people who solve the problem
	$data_names = array();
	foreach ($own as $name) {
		if (preg_match('/^(testdata|data)\/([^\/]+)$/D', $name, $matches)) {
			$package['data'][$matches[2]] = "$dir/$prefix$name";
			$data_names[] = $matches[2];
		} elseif (preg_match('/^(additional_file|attachments)\/([^\/]+)$/D', $name, $matches)) {
			$package['attachments'][$matches[2]] = "$dir/$prefix$name";
		}
	}
	if (isset($package['data']['problem.conf'])) {
		// a problem of this site: its problem.conf says everything
		$package['native'] = true;
	} else {
		foreach (array('config.yaml', 'config.yml') as $name) {
			if (!isset($package['data'][$name])) {
				continue;
			}
			if (!importReadsAllYaml()) {
				$package['notes'][] = '服务器上没有读 YAML 的扩展，config.yaml 没有读取：限制和评测方式用了默认值';
			} else {
				$said = importHydroConfig(importYaml(file_get_contents($package['data'][$name])), $data_names);
				// what the lines at the top of the statement say counts before what Hydro says
				$package['settings'] = $package['settings'] + $said['settings'];
				$package['cases'] = $said['cases'];
				$package['notes'] = array_merge($package['notes'], $said['notes']);
			}
			unset($package['data'][$name]);
		}
	}
	return $package;
}

// ---- making the problems

// Makes a problem of what was read. $public: what whoever imports said about showing it;
// what the problem says about itself counts where it says something.
// Returns array(the problem or null, lines for people about how it went, why not).
function importCreate($package, $actor, $domain, $public) {
	requirePHPLib('judger');
	requirePHPLib('data');
	$notes = $package['notes'];
	$title = $package['title'];
	if (strlen($title) > 100) {
		$title = mb_strcut($title, 0, 100, 'UTF-8');
		$notes[] = '题目名称太长，截短了';
	}
	list($basics, $err) = problemBasicsFromForm(array('title' => $title, 'statement_md' => $package['statement_md'], 'tags' => join(',', $package['tags']))
		+ (($package['public'] === null ? $public : $package['public']) ? array('public' => 'on') : array()));
	if ($err !== '') {
		return array(null, $notes, $err);
	}
	$settings = $package['settings'] + problemDefaultSettings();
	$id = problemCreateWithBasics($basics, $actor, $domain);
	if ($id === null) {
		return array(null, $notes, '创建题目失败');
	}
	$problem = queryProblemBrief($id);
	$upload_dir = "/var/uoj_data/upload/$id";

	// the data: the tests under the names their order gives them where the package says
	// which they are, and everything else as it is called
	$written = 0;
	$skipped = array();
	// what a file is called here, where it is not what it was called in the package
	$renamed = array();
	$put = function($path, $name) use ($upload_dir, &$written, &$skipped, &$renamed) {
		// a C++ source is called .cpp here, which is how the judgers know what it is
		$as = preg_replace('/\.(cc|cxx|c\+\+)$/i', '.cpp', $name);
		if (problemFileNameError($as) !== '' || !@copy($path, "$upload_dir/$as")) {
			$skipped[] = $name;
			return;
		}
		if ($as !== $name) {
			$renamed[$name] = $as;
		}
		$written++;
	};
	$is_case = array();
	if ($package['cases'] !== null && !$package['native']) {
		foreach ($package['cases'] as $index => $case) {
			$put($package['data'][$case[0]], ($index + 1) . '.in');
			$is_case[$case[0]] = true;
			if ($case[1] !== null) {
				$put($package['data'][$case[1]], ($index + 1) . '.out');
				$is_case[$case[1]] = true;
			} else {
				file_put_contents("$upload_dir/" . ($index + 1) . '.out', '');
			}
		}
	}
	foreach ($package['data'] as $name => $path) {
		if (isset($is_case[$name])) {
			continue;
		}
		// where the tests were named, another file that looks like a test is not one
		if ($package['cases'] !== null && !$package['native'] && problemTestFileRole($name) !== null) {
			$skipped[] = $name;
			continue;
		}
		$put($path, $name);
	}
	if ($skipped) {
		$notes[] = '有 ' . count($skipped) . ' 个数据文件没有导入：' . join('、', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? ' 等' : '');
	}
	if ($written > 0) {
		auditLog('problem.upload_data', 'problem', $id, null, array('files' => $written, 'imported' => true), $actor);
	}
	if ($package['native']) {
		$notes[] = '数据里带有 problem.conf，按它来评测';
	} else {
		foreach (array('checker_file', 'interactor_file') as $field) {
			if (isset($settings[$field]) && isset($renamed[$settings[$field]])) {
				$settings[$field] = $renamed[$settings[$field]];
			}
		}
		list($found, $err) = problemApplySettings($problem, $settings, $actor);
		if ($err !== '') {
			$notes[] = '评测设置没有保存：' . $err;
		} elseif ($written > 0) {
			$notes = array_merge($notes, $found);
		}
	}
	if ($written > 0 && problemUploadedTestCount($problem) > 0) {
		list($begun, $note) = problemSync($problem, $actor);
		$notes[] = $note;
	} elseif ($written == 0) {
		$notes[] = '没有测试数据：在“数据与评测”页上传后才能评测';
	}

	// the files that come with it, and the places in the statement that speak of them
	$urls = array();
	foreach ($package['attachments'] as $name => $path) {
		$err = attachmentAdd('problem', $id, $path, $name, $actor, true);
		if ($err !== '') {
			$notes[] = "附件 $name 没有添加：$err";
		}
	}
	foreach (attachmentsOf('problem', $id) as $attachment) {
		$urls[$attachment['name']] = attachmentUrl($attachment);
	}
	if ($urls) {
		// Hydro writes "file://name" for a file of the problem
		$statement_md = preg_replace_callback('/file:\/\/([^\s)"\'<>]+)/', function($matches) use ($urls) {
			$name = rawurldecode($matches[1]);
			return isset($urls[$name]) ? $urls[$name] : $matches[0];
		}, $package['statement_md']);
		if ($statement_md !== $package['statement_md']) {
			problemSaveStatement($id, HTML::pruifier()->purify(HTML::parsedown()->text($statement_md)), $statement_md);
		}
	}
	auditLog('problem.import', 'problem', $id, null, array('title' => $title, 'files' => $written, 'attachments' => count($urls)), $actor);
	return array(queryProblemBrief($id), $notes, '');
}

// What was uploaded to be imported: templates and packages, several at once.
// Returns array(rows of array('from' => the file, 'problem' => the problem or null, 'notes',
// 'error'), why nothing was imported or '').
function importUploaded($field, $actor, $domain, $public) {
	if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
		return array(array(), '请选择要导入的文件');
	}
	$limits = uploadLimits();
	$results = array();
	$sources = array();
	foreach ($_FILES[$field]['name'] as $index => $name) {
		if ($_FILES[$field]['error'][$index] == UPLOAD_ERR_NO_FILE) {
			continue;
		}
		$sources[] = array('name' => (string)$name, 'error' => (int)$_FILES[$field]['error'][$index], 'path' => $_FILES[$field]['tmp_name'][$index]);
	}
	if (!$sources) {
		return array(array(), '请选择要导入的文件');
	}
	set_time_limit(0);
	$made = 0;
	$make = function($package, $from) use (&$results, &$made, $actor, $domain, $public) {
		if ($made >= UOJ_IMPORT_MAX_PROBLEMS) {
			$results[] = array('from' => $from, 'problem' => null, 'notes' => array(), 'error' => '一次最多导入 ' . UOJ_IMPORT_MAX_PROBLEMS . ' 道题，后面的没有导入');
			return;
		}
		list($problem, $notes, $err) = importCreate($package, $actor, $domain, $public);
		$made += $problem ? 1 : 0;
		$results[] = array('from' => $from, 'problem' => $problem, 'notes' => $notes, 'error' => $err);
	};
	foreach ($sources as $source) {
		$base = basename($source['name']);
		$refuse = function($why) use (&$results, $base) {
			$results[] = array('from' => $base, 'problem' => null, 'notes' => array(), 'error' => $why);
		};
		if ($source['error'] > 0 || !is_uploaded_file($source['path'])) {
			$refuse('文件没有传完（错误 ' . $source['error'] . '），可能是太大了');
			continue;
		}
		$ending = strtolower(pathinfo($base, PATHINFO_EXTENSION));
		if ($ending === 'md' || $ending === 'markdown' || $ending === 'txt') {
			if (filesize($source['path']) > 1000000) {
				$refuse('题面文件太大了');
				continue;
			}
			$make(importReadTemplate(file_get_contents($source['path']), pathinfo($base, PATHINFO_FILENAME)), $base);
			continue;
		}
		if ($ending !== 'zip') {
			$refuse('只能导入填好的模板（.md）或题目包（.zip）');
			continue;
		}
		$dir = sys_get_temp_dir() . '/uoj_import_' . uojRandString(16);
		if (!mkdir($dir, 0700)) {
			$refuse('无法创建临时目录');
			continue;
		}
		$err = importUnpack($source['path'], $dir, $limits);
		if ($err === '') {
			$files = importFilesBelow($dir);
			list($roots, $templates) = importFindProblems($files);
			if (!$roots && !$templates) {
				$err = '压缩包里没有找到题目。题目包里每道题一个文件夹，里面有 problem.yaml 或 problem.md；也可以直接放填好的模板（.md）。如果这是一道题的测试数据，请在“新建题目”里上传';
			}
			foreach ($roots as $root) {
				$make(importReadPackage($dir, $root, $files), $base . ($root === '' ? '' : " / $root"));
			}
			foreach ($templates as $template) {
				$make(importReadTemplate(file_get_contents("$dir/$template"), pathinfo($template, PATHINFO_FILENAME)), "$base / $template");
			}
		}
		exec('rm -rf ' . escapeshellarg($dir));
		if ($err !== '') {
			$refuse($err);
		}
	}
	return array($results, '');
}
