<?php

// Problems that come from somewhere else, whole.
//
// A template: one Markdown file that somebody fills in. It begins with a few lines that say
// what the problem is called and what its limits are, between two lines of "---", and the
// rest is the statement. importTemplateText() is the one that is handed out.
//
// A package: a zip with a folder for every problem.
//
//     <any name>/problem.md              the statement, with the lines of a template at its top
//                                        (also problem_zh.md; or title and tag in a problem.yaml)
//     <any name>/problem.conf            how it is judged, as this site says it; comments allowed
//     <any name>/data/                   the tests and the programs that judge them (also
//                                        testdata/; the problem.conf may lie here as well)
//     <any name>/additional_file/        files for the people who solve it
//
// A zip that is one such folder is one problem, and so is a zip of filled-in templates a
// problem for each. importPackageTemplate() is the package that is handed out as an example.
//
// A problem.conf that says how many tests there are is taken at its word. One that does not
// is the settings of the problem, and the tests are found by the names of their files: that
// is what the one that is handed out is, so that nobody has to count files to import one.
//
// A package that Hydro exported is laid out the same way, and has a config.yaml where this
// site has a problem.conf. It is read where there is no problem.conf, and turned into the
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

// The problem.conf that is handed out: every setting people use, with what it does. What is
// in force is what a problem has when nobody says anything; the rest is commented out, to be
// put in force by taking the "#" at the beginning of its line away.
function importConfTemplateText() {
	return <<<'EOD'
# ===== 评测设置（problem.conf）=====
# 每行一个“键 值”，中间用空格分开，值里不能有空格。
# 以 # 开头的行是说明，或者是没有启用的设置：要启用哪一项，就去掉那一行开头的 # 。
# 导入时注释会自动去掉，不用手工清理。

use_builtin_judger on           # 用本站自带的评测程序，不用改

# ---- 限制
time_limit 1                    # 时间限制，单位是秒，可以写小数（如 2.5）
memory_limit 256                # 内存限制，单位是 MB
# output_limit 64               # 输出上限，单位是 MB，不写是 64
# time_limit_3 5                # 只给第 3 个测试点改时间限制；memory_limit_3 同理

# ---- 怎么比较选手的输出和答案：下面两种写法选一种
use_builtin_checker wcmp        # 用内置的比较方式，可以写：
                                #   wcmp    逐个单词比较，忽略空格和换行的多少（最常用）
                                #   lcmp    逐行比较，忽略行内多余的空格
                                #   fcmp    逐行严格比较，只忽略文末的换行
                                #   ncmp    整数序列
                                #   rcmp4 / rcmp6 / rcmp9   实数，绝对或相对误差不超过 1e-4 / 1e-6 / 1e-9
                                #   yesno   只有 YES 或 NO（不分大小写）
# chk_source chk.cpp            # 用自己的校验器（testlib.h 写的 C++ 程序，放在 data/ 里）：
                                # 启用这一行，并删掉上面 use_builtin_checker 那一行

# ---- 测试点：不用写。导入时按文件名自动识别：1.in 和 1.out（或 1.ans）是一个测试点，
#      input1.txt 和 output1.txt 也认；文件名以 ex_ 或 sample 开头的是额外测试点（放样例）。
#      只有文件名很特别、识别不出来时才需要写，而且要把下面七行全部写对：
# n_tests 10                    # 正式测试点的个数
# n_ex_tests 2                  # 额外测试点的个数
# n_sample_tests 2              # 额外测试点里前几个是样例
# input_pre data                # 第 3 个测试点的输入文件叫 data3.in，额外测试点叫 ex_data3.in
# input_suf in
# output_pre data               # 第 3 个测试点的答案文件叫 data3.out
# output_suf out

# ---- 计分：什么都不写时，每个测试点分值相同，通过几个得几个的分（OI 的做法）
# n_subtasks 1                  # 全部测试点通过才得分（ICPC 的做法）：只启用这一行
#
# 子任务：一个子任务里的测试点全部通过才得到它的分。例如 10 个测试点分成三个子任务：
# n_subtasks 3
# subtask_end_1 3               # 子任务 1 是测试点 1～3
# subtask_score_1 20
# subtask_end_2 6               # 子任务 2 是测试点 4～6
# subtask_score_2 30
# subtask_score_3 50            # 最后一个子任务到最后一个测试点为止，不用写 subtask_end
# subtask_dependence_3 2        # 子任务 3 依赖子任务 2：2 没通过，3 也不得分
#
# point_score_1 20              # 不分子任务时，单独指定第 1 个测试点的分值

# ---- 题目类型：什么都不写是传统题（读标准输入，写标准输出）。其他类型启用对应的行：
# interaction_mode on               # 交互题：选手的程序通过标准输入输出和交互器对话
# interactor_source interactor.cpp  # 交互器（testlib.h 写的），放在 data/ 里
#
# multi_pass 2                  # 通信题：同一个程序运行 2 轮，需要自己的校验器（chk_source）
#
# submit_answer on              # 提交答案题：选手提交每个测试点的答案，不提交程序
#
# with_implementer on           # 函数交互题：交互库放在 data/require/implementer.cpp

# ---- 其他
# validate_input_before_test on # 评测前先用数据校验器检查每个测试点的输入
# val_source val.cpp            # 数据校验器（testlib.h 写的）
# std_source std.cpp            # 标程：题目允许 Hack 时用它算答案

EOD;
}

// The package that is handed out: a zip as it is imported, with two problems in it and the
// rules of the format written out, for people and for the programs people have do such work.
// Returns its files: path => content.
function importPackageTemplate() {
	$readme = <<<'EOD'
# 题目包格式说明

这个压缩包本身就是一个可以直接导入的例子：在“导入题目”页面选上它，会建出两道题。
照着它的样子准备题目即可。一个 zip 里可以放很多道题（一次最多 50 道），每道题一个文件夹。

也可以把这份说明连同手头的题目一起交给 AI，让它“按这份说明把这些题整理成题目包”：
下面的规则是完整的，照做就能导入。

## 目录结构

```
题目包.zip
├── README.md               这份说明（导入时忽略）
├── a-plus-b/               一道题一个文件夹，文件夹叫什么都行（建议用英文和数字）
│   ├── problem.md          题面（必须有）
│   ├── problem.conf        评测设置（没有就用默认设置：1 秒、256 MB、逐词比较）
│   ├── data/               测试数据和评测用的程序（没有也行，之后在网页上传）
│   │   ├── 1.in  1.out
│   │   ├── 2.in  2.out
│   │   └── ex_1.in  ex_1.out
│   └── additional_file/    给做题的人下载的文件、题面里的图片（可以没有）
└── with-checker/
    └── ……
```

整个 zip 只有一道题时，可以不套文件夹，直接把 problem.md 等放在 zip 的最外层。

## problem.md：题面

开头是夹在两行 `---` 之间的几行，写题目名称等；之后全部是题面，用 Markdown 写。

```
---
title: A + B 问题
tags: [入门, 模拟]
public: false
---

## 题目描述

给出两个整数 $a$ 和 $b$，求它们的和。
```

- `title`：题目名称，必须有，不超过 100 个字节。
- `tags`：标签，可以不写；最多 10 个。
- `public`：`true` 表示导入后直接公开；不写或写 `false` 是隐藏，检查无误后再公开。
- 文件用 UTF-8 编码。
- 公式写在美元符号之间：行内 `$a+b$`，独占一行 `$$a+b$$`。真正的美元符号写成 `\$`。
- 样例的输入和输出各用一个代码块，代码块的“语言”写 `input1`、`output1`；第二组写 `input2`、`output2`。
  这样网页上会显示成并排的样例，并带复制按钮。
- 建议的小节（用 `##` 开头）：题目描述、输入格式、输出格式、样例、样例说明、数据范围。
- 图片：把图片文件放进 `additional_file/`，题面里写 `![说明文字](file://图片文件名)`。
- 时间和内存限制不用写进题面，网页会根据 problem.conf 自动显示。

## problem.conf：评测设置

每行一个“键 值”，中间用空格分开，值里不能有空格，同一个键只能写一次。`#` 开头的行和行尾 `#` 之后是注释。

`a-plus-b/problem.conf` 列出了全部常用设置并逐项说明，没启用的都用 `#` 注释掉了：
要用哪一项，去掉那一行开头的 `#` 即可。一道普通的题只需要四行：

```
use_builtin_judger on
time_limit 1
memory_limit 256
use_builtin_checker wcmp
```

- **不要写** `n_tests`、`input_pre` 这些说明测试点文件名的行：不写时测试点按文件名自动识别，这是推荐的做法。
- 答案不唯一的题需要自己的校验器：删掉 `use_builtin_checker` 那一行，写 `chk_source chk.cpp`，
  并把 `chk.cpp` 放进 `data/`。见 `with-checker/` 这个例子。
- ICPC 式的“全部通过才得分”：加一行 `n_subtasks 1`。
- 子任务的写法见 `with-checker/problem.conf`。

## data/：测试数据

- 一个测试点是一对文件：输入和答案，文件名除后缀外相同。`1.in` + `1.out`、`1.in` + `1.ans`、
  `input1.txt` + `output1.txt` 都可以。导入时按文件名里的数字从小到大排成第 1、2、3……个测试点。
- 文件名以 `ex_` 或 `sample` 开头的是**额外测试点**，样例放在这里（`ex_1.in`、`ex_1.out`）。
  正式测试点全部通过后还会再测额外测试点。
- 每个测试点都要有答案文件，即使用了自己的校验器（交互题除外）。
- 自己的校验器、交互器等程序也放在 `data/` 里，文件名写在 problem.conf 里。它们必须是用
  [testlib.h](https://github.com/MikeMirzayanov/testlib) 写的 C++ 程序，后缀 `.cpp`。
- 除了 `data/require/`（和选手程序一起编译的文件）与 `data/download/`（给选手下载的文件）之外，
  `data/` 里不要再放文件夹。标程、数据生成器等用不到的文件不要放进来。
- 换行符用 `\n`（不要 `\r\n`），文件末尾留一个换行。

## additional_file/：附件

放在这里的文件会成为题目的附件，显示在题面下方供下载。题面里用 `file://文件名` 引用的图片也放在这里。

## 整理好之后自查

1. 每道题一个文件夹，文件夹里有 `problem.md`，它的开头两行 `---` 之间有 `title`。
2. `problem.conf` 里每一行都是“键 值”两个词（注释除外），没有重复的键，没有 `n_tests`。
3. `data/` 里输入和答案成对，文件名里的编号从 1 开始连续。
4. 用了 `chk_source`、`interactor_source` 时，对应的 `.cpp` 文件在 `data/` 里，而且没有 `use_builtin_checker` 那一行。
5. 子任务的分值加起来是 100，`subtask_end_i` 从小到大。
6. 题面里的样例和 `data/` 里的额外测试点内容一致。
7. 打包时 zip 里直接是各题的文件夹（外面再套一层文件夹也可以）。

导入后页面会逐题说明识别到了多少测试点、有什么没有采用；数据同步完成后就可以提交测试。

EOD;
	$ab_statement = <<<'EOD'
---
title: A + B 问题
tags: [入门]
public: false
---

## 题目描述

给出两个整数 $a$ 和 $b$，求它们的和。

## 输入格式

一行两个整数 $a$ 和 $b$。

## 输出格式

一行一个整数，表示 $a + b$。

## 样例

```input1
1 2
```

```output1
3
```

## 数据范围

对于 $100\%$ 的数据，$|a|, |b| \le 10^9$。

EOD;
	$split_statement = <<<'EOD'
---
title: 拆成两个数
tags: [构造, 特殊评测]
public: false
---

## 题目描述

给出一个整数 $n$，请写出两个正整数 $a$ 和 $b$，使得 $a + b = n$。答案不唯一，输出任意一组即可。

![示意图](file://figure.png)

## 输入格式

一行一个整数 $n$。

## 输出格式

一行两个正整数 $a$ 和 $b$。

## 样例

```input1
5
```

```output1
2 3
```

## 样例说明

输出 `1 4`、`4 1`、`3 2` 也都算对。

## 数据范围

| 子任务 | 分值 | $n$ 的范围 |
| :-: | :-: | :-: |
| 1 | 40 | $2 \le n \le 100$ |
| 2 | 60 | $2 \le n \le 10^{18}$ |

EOD;
	$split_conf = <<<'EOD'
# 这道题的答案不唯一，用自己的校验器；测试点分成两个子任务。
# 没有写 n_tests：测试点按 data/ 里的文件名自动识别。

use_builtin_judger on
time_limit 1                    # 秒
memory_limit 256                # MB
chk_source chk.cpp              # 自己的校验器，文件在 data/ 里；所以没有 use_builtin_checker 这一行

n_subtasks 2
subtask_end_1 2                 # 子任务 1 是测试点 1～2
subtask_score_1 40
subtask_score_2 60              # 子任务 2 是测试点 3～4：到最后一个测试点为止

EOD;
	$split_checker = <<<'EOD'
// 校验器：读入题目的输入（inf）和选手的输出（ouf），判断选手的输出对不对。
// 用 testlib.h 写；标准答案在 ans 里，这道题用不到。
#include "testlib.h"

int main(int argc, char *argv[]) {
	registerTestlibCmd(argc, argv);
	long long n = inf.readLong();
	long long a = ouf.readLong(1, n - 1, "a");
	long long b = ouf.readLong(1, n - 1, "b");
	if (a + b != n) {
		quitf(_wa, "a + b = %lld, expected %lld", a + b, n);
	}
	quitf(_ok, "%lld = %lld + %lld", n, a, b);
}

EOD;
	$files = array(
		'README.md' => $readme,
		'a-plus-b/problem.md' => $ab_statement,
		'a-plus-b/problem.conf' => importConfTemplateText(),
		'with-checker/problem.md' => $split_statement,
		'with-checker/problem.conf' => $split_conf,
		'with-checker/data/chk.cpp' => $split_checker,
		'with-checker/additional_file/figure.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAGAAAAAwCAIAAABhdOiYAAAAf0lEQVR42u3bsQnAIBRFUcfJEE6SSTKOUzmBZAN7C8EyhBDU4sAd4HOK1/1wnEkPBQSvgHKpO3RfsbfDMYAAAQIECBAgQIAAAQIECBAgQIAAAQIECNASoHGZRoAA/Qhkg4w0IECAAAECBAgQIECAAAECBAgQIECAAM0Ckm+fjzXoiDxbDSiqDAAAAABJRU5ErkJggg==')
	);
	foreach (array(array('1 2', '3'), array('-7 7', '0'), array('1000000000 1000000000', '2000000000')) as $index => $test) {
		$files['a-plus-b/data/' . ($index + 1) . '.in'] = $test[0] . "\n";
		$files['a-plus-b/data/' . ($index + 1) . '.out'] = $test[1] . "\n";
	}
	$files['a-plus-b/data/ex_1.in'] = "1 2\n";
	$files['a-plus-b/data/ex_1.out'] = "3\n";
	foreach (array(array('2', '1 1'), array('100', '1 99'), array('1000000007', '1 1000000006'), array('1000000000000000000', '1 999999999999999999')) as $index => $test) {
		$files['with-checker/data/' . ($index + 1) . '.in'] = $test[0] . "\n";
		$files['with-checker/data/' . ($index + 1) . '.out'] = $test[1] . "\n";
	}
	$files['with-checker/data/ex_1.in'] = "5\n";
	$files['with-checker/data/ex_1.out'] = "2 3\n";
	return $files;
}
// the package that is handed out, as the zip it is: the path of a file that whoever asked for it removes
function importPackageTemplateZip() {
	$path = tempnam(sys_get_temp_dir(), 'uoj_template_');
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
		return null;
	}
	foreach (importPackageTemplate() as $name => $content) {
		$zip->addFromString($name, $content);
	}
	$zip->close();
	return $path;
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
	// A problem whose program runs several times (multi_pass), and a checker written the way
	// the problem package format of ICPC has it (kattis): the ways of Hydro and of DOMjudge.
	$passes = isset($config['multi_pass']) && is_scalar($config['multi_pass']) && validateUInt((string)$config['multi_pass']) ? (int)$config['multi_pass'] : 0;
	if ($checker_type === 'kattis' || $passes > 1) {
		$settings['type'] = 'multi_pass';
		$settings['comm_style'] = $checker_type === 'kattis' ? 'domjudge' : 'hydro';
		$settings['passes'] = max(2, min(20, $passes));
		if ($passes > 20) {
			$notes[] = "config.yaml 里允许运行 {$passes} 轮，这里最多 20 轮";
		}
	}
	if (isset($config['checker']) && is_string($config['checker']) && $config['checker'] !== '') {
		if ($has($config['checker'])) {
			$settings['checker'] = 'custom';
			$settings['checker_file'] = $config['checker'];
			if ($checker_type !== 'testlib' && $checker_type !== 'kattis') {
				$notes[] = "校验器 {$config['checker']} 是按 {$checker_type} 的约定写的，这里只支持 testlib 和 kattis 的写法：需要改写后重新上传";
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
		$own_limits = false;
		$own_scores = false;
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
			// limits and scores of single subtasks and tests are not carried over
			foreach (array_merge(array($subtask), array_filter($subtask['cases'], 'is_array')) as $part) {
				$own_limits = $own_limits || isset($part['time']) || isset($part['memory']);
			}
			foreach (array_filter($subtask['cases'], 'is_array') as $case) {
				$own_scores = $own_scores || isset($case['score']);
			}
		}
		if ($cases !== null) {
			$total = array_sum(array_column($rows, 1));
			$ends = array_column($rows, 0);
			if (count($rows) >= 2 && $plain && $total == 100 && count(array_unique($ends)) == count($ends) && !in_array(0, $ends, true)) {
				$settings['scoring'] = 'subtasks';
				$settings['subtasks'] = $rows;
			} elseif (count($rows) == 1 && $plain) {
				// one subtask that is passed as a whole: every test has to pass
				$settings['scoring'] = 'all';
			} elseif (count($rows) == 1) {
				$notes[] = '只有一个子任务，而且不是“全部通过才得分”的：现在按测试点平均给分，请在评测设置里检查';
			} elseif (count($rows) >= 2) {
				$notes[] = '子任务没有照原样导入（' . (!$plain ? '有子任务依赖或不是“全部通过才得分”' : ($total != 100 ? "分值加起来是 $total 不是 100" : '有空的子任务')) . '），现在按测试点平均给分，请在评测设置里重新填';
			}
			if (!$cases) {
				$cases = null;
			}
			if ($own_limits) {
				$notes[] = 'config.yaml 给个别子任务或测试点单独写了时间、内存限制，这里没有照搬：需要的话在 problem.conf 里写 time_limit_3 5 这样的行';
			}
			if ($own_scores) {
				$notes[] = 'config.yaml 给个别测试点单独写了分值，这里没有照搬：需要的话在 problem.conf 里写 point_score_3 20 这样的行';
			}
		}
	}
	// what Hydro can be told and this site is not told this way
	$dropped = array();
	foreach (array('langs', 'user_extra_files', 'judge_extra_files', 'validator', 'template', 'time_limit_rate', 'memory_limit_rate') as $key) {
		if (isset($config[$key]) && $config[$key] !== '' && $config[$key] !== array()) {
			$dropped[] = $key;
		}
	}
	if ($dropped) {
		$notes[] = 'config.yaml 里这些设置没有导入：' . join('、', $dropped);
	}
	return array('settings' => $settings, 'cases' => $cases, 'notes' => $notes);
}

// The settings of a problem whose problem.conf does not say which its tests are: what the
// conf says, and for what it leaves open what the lines at the top of the statement say.
//   $conf       the problem.conf, read
//   $said       the settings the statement speaks of
//   $names      the files that come as data, among which a checker may be
function importSettingsOfConf($conf, $said, $names) {
	$settings = problemSettingsOfConf($conf);
	foreach (array('time_limit', 'memory_limit') as $key) {
		if (!isset($conf[$key]) && isset($said[$key])) {
			$settings[$key] = $said[$key];
		}
	}
	// no word about how answers are compared: by the checker that came along, or the usual way
	if (!isset($conf['use_builtin_checker']) && !isset($conf['chk_source'])) {
		$settings['checker'] = problemGuessProgram($names, 'chk') !== '' ? 'custom' : problemDefaultSettings()['checker'];
	}
	// the last subtask ends with the last test, which is not known before the tests are found
	if ($settings['scoring'] === 'subtasks' && $settings['subtasks']) {
		$last = count($settings['subtasks']);
		$settings['subtasks'][$last - 1][0] = isset($conf["subtask_end_$last"]) ? (int)$conf["subtask_end_$last"] : null;
	}
	return $settings;
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
// The folder a file of a package is in ('' for none), the name of a file without its
// ending, and its ending in small letters. Names are taken apart with uojFileBaseName():
// basename() and pathinfo() ask the locale of the server what a character is, and under the
// locale that servers have they drop the characters of a name that are not ASCII.
// "没有开头.md" came out as ".md", and a problem named by its file had no name.
function importFolder($path) {
	$slash = strrpos((string)$path, '/');
	return $slash === false ? '' : substr($path, 0, $slash);
}
function importStem($path) {
	return uojFileNameParts(uojFileBaseName($path))[0];
}
function importEnding($path) {
	return strtolower(uojFileNameParts(uojFileBaseName($path))[1]);
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
		$base = uojFileBaseName($file);
		if ($base === 'problem.yaml' || $base === 'problem.yml' || preg_match('/^problem(_[A-Za-z_]+)?\.md$/D', $base)) {
			$roots[importFolder($file)] = true;
		}
	}
	$roots = array_keys($roots);
	sort($roots);
	$templates = array();
	foreach ($files as $file) {
		if (strtolower(substr($file, -3)) !== '.md' || strtolower(uojFileBaseName($file)) === 'readme.md') {
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
		// the problem.conf that came with it, read; null when there is none
		'conf' => null,
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
	$package = importReadTemplate($statement_file === null ? '' : $read($statement_file), $root === '' ? '导入的题目' : uojFileBaseName($root));
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
		} elseif (preg_match('/^(testdata|data)\/((require|download)\/[^\/]+)$/D', $name, $matches)) {
			// what is compiled with the programs of the contestants, and what they may download
			$package['data'][$matches[2]] = "$dir/$prefix$name";
		} elseif (preg_match('/^(additional_file|attachments)\/([^\/]+)$/D', $name, $matches)) {
			$package['attachments'][$matches[2]] = "$dir/$prefix$name";
		}
	}
	// How it is judged: a problem.conf says so, beside the statement or among the data. Only
	// where there is none is a config.yaml of Hydro read.
	if (in_array('problem.conf', $own, true)) {
		$package['data']['problem.conf'] = "$dir/{$prefix}problem.conf";
	}
	if (isset($package['data']['problem.conf'])) {
		list($conf, $err) = problemConfFromText(file_get_contents($package['data']['problem.conf']));
		// it is written anew from what it says, without its comments
		unset($package['data']['problem.conf']);
		if ($err !== '') {
			$package['notes'][] = "problem.conf 没有采用（{$err}）：限制和评测方式用了默认值，请到“数据与评测”里检查";
		} else {
			$package['conf'] = $conf;
			// One that says how many tests there are says everything, and is taken at its word.
			// One that does not is the settings of the problem: its tests are found by their names.
			$package['native'] = isset($conf['n_tests']) || (isset($conf['use_builtin_judger']) && $conf['use_builtin_judger'] !== 'on');
			if (!$package['native']) {
				$package['settings'] = importSettingsOfConf($conf, $package['settings'], $data_names);
			}
		}
		foreach (array('config.yaml', 'config.yml') as $name) {
			if (isset($package['data'][$name])) {
				unset($package['data'][$name]);
				$package['notes'][] = "数据里同时有 problem.conf 和 {$name}：按 problem.conf 导入，{$name} 没有读";
			}
		}
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
				$package['notes'][] = "评测设置是从 Hydro 的 {$name} 翻译来的，请在“数据与评测”里核对；建议以后改用 problem.conf";
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
		if (strpos($as, '/') !== false && !is_dir(dirname("$upload_dir/$as"))) {
			@mkdir(dirname("$upload_dir/$as"), 0755, true);
		}
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
	// a program that the package names is that program under the name it has here
	$here = function($name) use ($renamed) {
		return isset($renamed[$name]) ? $renamed[$name] : $name;
	};
	if ($package['conf'] !== null) {
		// the problem.conf of the package, written anew from what it says
		$conf = $package['conf'];
		foreach (problemProgramFields() as $field => $kind) {
			if (isset($conf["{$kind}_source"])) {
				$conf["{$kind}_source"] = $here($conf["{$kind}_source"]);
			}
		}
		putUOJConf("$upload_dir/problem.conf", $conf);
	}
	if ($package['native']) {
		$notes[] = '按题目包里的 problem.conf 评测：它写明了测试点，文件名没有改动';
		auditLog('problem.edit_conf', 'problem', $id, null, $conf + array('imported' => 1), $actor);
	} else {
		foreach (problemProgramFields() as $field => $kind) {
			if (isset($settings[$field])) {
				$settings[$field] = $here($settings[$field]);
			}
		}
		if ($settings['scoring'] === 'subtasks' && $settings['subtasks'] && $settings['subtasks'][count($settings['subtasks']) - 1][0] === null) {
			$tests = problemDetectTests(problemUploadedNames($upload_dir), $settings['type'] === 'interactive' || $settings['type'] === 'multi_pass');
			$settings['subtasks'][count($settings['subtasks']) - 1][0] = count($tests['tests']);
		}
		list($found, $err) = problemApplySettings($problem, $settings, $actor);
		if ($err !== '') {
			$notes[] = '评测设置没有保存：' . $err;
		} elseif ($written > 0) {
			if ($package['conf'] !== null) {
				$notes[] = '按题目包里的 problem.conf 评测：它没有写测试点个数，测试点是按文件名找的';
			}
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
		$base = uojFileBaseName($source['name']);
		$refuse = function($why) use (&$results, $base) {
			$results[] = array('from' => $base, 'problem' => null, 'notes' => array(), 'error' => $why);
		};
		if ($source['error'] > 0 || !is_uploaded_file($source['path'])) {
			$refuse('文件没有传完（错误 ' . $source['error'] . '），可能是太大了');
			continue;
		}
		$ending = importEnding($base);
		if ($ending === 'md' || $ending === 'markdown' || $ending === 'txt') {
			if (filesize($source['path']) > 1000000) {
				$refuse('题面文件太大了');
				continue;
			}
			$make(importReadTemplate(file_get_contents($source['path']), importStem($base)), $base);
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
				$make(importReadTemplate(file_get_contents("$dir/$template"), importStem($template)), "$base / $template");
			}
		}
		exec('rm -rf ' . escapeshellarg($dir));
		if ($err !== '') {
			$refuse($err);
		}
	}
	return array($results, '');
}
