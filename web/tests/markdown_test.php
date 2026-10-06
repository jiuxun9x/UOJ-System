<?php

// What Markdown makes of a text that has formulas in it. The formulas are for MathJax: they
// reach the page as they were written, and the text around them is still all there.

require_once __DIR__ . '/../app/models/UOJMarkdown.php';

$markdown = new UOJMarkdown(array('math' => array('enabled' => true, 'matchSingleDollar' => true)));
$table = function($cell) {
	return "<table class=\"table table-bordered\">\n<thead>\n<tr>\n<th>范围</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>$cell</td>\n</tr>\n</tbody>\n</table>";
};

$cases = array(
	// ---- a formula is not Markdown
	'the underscores of a formula are not emphasis' => array(
		'给定 $a_1,a_2,\ldots,a_n$ 和 $b_i$。',
		'<p>给定 $a_1,a_2,\ldots,a_n$ 和 $b_i$。</p>'),
	'nor are its stars' => array(
		'2*3 和 $a*b$ 以及 $c*d$。',
		'<p>2*3 和 $a*b$ 以及 $c*d$。</p>'),
	'what a formula compares is written for a page' => array(
		'若 $1\le i<j\le n$ 且 $a_i>a_j$ & 其它。',
		'<p>若 $1\le i&lt;j\le n$ 且 $a_i&gt;a_j$ &amp; 其它。</p>'),
	'a bar in a formula does not divide the cells of a table' => array(
		"| 范围 |\n|---|\n| $|a_i| \\le 10^9$ |",
		$table('$|a_i| \le 10^9$')),
	'the other signs of a formula in a line' => array(
		'其中 \(a_i\) 是数，\(x_1 < x_2\)。',
		'<p>其中 \(a_i\) 是数，\(x_1 &lt; x_2\)。</p>'),

	// ---- the text after a formula is still there
	'a formula by itself and text after it on the same line' => array(
		"\$\$\\sum a_i\$\$，其中 \$a_i\$ 是数。\n\n## 输入格式\n\n一行。",
		"<p>\$\$\\sum a_i\$\$，其中 \$a_i\$ 是数。</p>\n<h2>输入格式</h2>\n<p>一行。</p>"),
	'a formula by itself within a line' => array(
		'求 $$\sum_{i=1}^n a_i$$ 的值。',
		'<p>求 $$\sum_{i=1}^n a_i$$ 的值。</p>'),
	'a formula on lines of its own' => array(
		"求\n\n$$\n\\sum_{i=1}^n a_i\n$$\n\n的值。",
		"<p>求</p>\n<p>$$\n\\sum_{i=1}^n a_i\n$$</p>\n<p>的值。</p>"),
	'blanks after the sign that closes it' => array(
		"\$\$\nx_1 + y\n\$\$  \n\n后文还在。",
		"<p>\$\$\nx_1 + y\n\$\$  </p>\n<p>后文还在。</p>"),
	'the same with brackets' => array(
		"\\[ x_1 + y \\] 和后文。\n\n\\[\na_1\n\\]",
		"<p>\\[ x_1 + y \\] 和后文。</p>\n<p>\\[\na_1\n\\]</p>"),
	'a formula in a quotation loses the signs of the quotation' => array(
		"> $$\n> a_1 + b\n> $$\n> 完。",
		"<blockquote>\n<p>$$\na_1 + b\n$$\n完。</p>\n</blockquote>"),

	// ---- a sign that is alone is a sign
	'a dollar that opens nothing' => array(
		'先有公式 $a_i$，然后价格 $5 元，后面这些字还在。',
		'<p>先有公式 $a_i$，然后价格 $5 元，后面这些字还在。</p>'),
	'an escaped dollar is left for MathJax, which shows a dollar' => array(
		'价格是 \$5 和 \$6，公式 $x_1$。',
		'<p>价格是 \$5 和 \$6，公式 $x_1$。</p>'),
	'a formula that is never closed does not take the text with it' => array(
		"$$\nx\n\n## 输入格式\n\n一行，这里还在。",
		"<p>$$\nx</p>\n<h2>输入格式</h2>\n<p>一行，这里还在。</p>"),
	'a formula does not reach into the next item of a list' => array(
		"- 第一 \$a\n- 第二 \$b",
		"<ul>\n<li>第一 \$a</li>\n<li>第二 \$b</li>\n</ul>"),
	'nor into the next heading' => array(
		"价格 \$5\n## 第 \$2 节",
		"<p>价格 \$5</p>\n<h2>第 \$2 节</h2>"),
	'but it may go on in the next line' => array(
		"其中 \$a_1 +\nb_1\$ 是和。",
		"<p>其中 \$a_1 +\nb_1\$ 是和。</p>"),

	// ---- code is not searched for formulas
	'code in a line' => array(
		'变量 `$x` 的值是 $y_1$，`a_b` 和 ``$p$ ` $q$``。',
		'<p>变量 <code>$x</code> 的值是 $y_1$，<code>a_b</code> 和 <code>$p$ ` $q$</code>。</p>'),
	'a fenced block' => array(
		"```cpp\nint \$a = 1; // \$b_1\$ <x>\n```\n\n\$n_1\$",
		"<pre><code class=\"language-cpp\">int \$a = 1; // \$b_1\$ &lt;x&gt;</code></pre>\n<p>\$n_1\$</p>"),
	'a sample the way Hydro writes it' => array(
		"```input1\n3\n\$ \$ \$\n```\n\n```output1\n\$\n```\n## 说明\n\n\$k=1\$",
		"<pre><code class=\"language-input1\">3\n\$ \$ \$</code></pre>\n<pre><code class=\"language-output1\">\$</code></pre>\n<h2>说明</h2>\n<p>\$k=1\$</p>"),
	'a sample the way UOJ writes it' => array(
		"<pre>\n5 \$\n</pre>\n\n求 \$n_1\$ 的值",
		"<pre>\n5 \$\n</pre>\n<p>求 \$n_1\$ 的值</p>"),

	// ---- an escaped sign is the sign
	'escapes' => array(
		'路径 C:\\\\dir 和 a\_b 和 \*星\*，\<b\> 后面还在。',
		'<p>路径 C:\dir 和 a_b 和 *星*，&lt;b&gt; 后面还在。</p>'),

	// ---- what nothing shows is not kept
	'characters that come along from a PDF' => array(
		"前文\x0c，后文\x08？\x00（完）\r\n\r\n第二段",
		"<p>前文，后文？（完）</p>\n<p>第二段</p>"),
);
foreach ($cases as $what => $case) {
	check_same($case[1], $markdown->text($case[0]), "Markdown: $what");
}

// a statement as it is written for another judge, whole
$statement = "## **题目描述**\n\n给定一个长度为 \$n\$ 的数组 \$a\$。对于每个 \$k=1,2,\\ldots,n\$：\n\n- `Backspace`：删除第一个元素；\n- `Delete`：删除最后一个元素。\n\n"
	. "## **输入格式**\n\n第二行包含 \$n\$ 个整数 \$a_1,a_2,\\ldots,a_n\$，表示数组 \$a\$ \$(1\\le a_i\\le 10^9)\$。\n\n"
	. "## **样例**\n\n```input1\n5\n2 7 8 1 4\n```\n\n```output1\n4 7 8 8 8\n```\n## **样例说明**\n\n对于 \$k=1\$，最大得分为 \$4\$。";
check_same(
	"<h2><strong>题目描述</strong></h2>\n<p>给定一个长度为 \$n\$ 的数组 \$a\$。对于每个 \$k=1,2,\\ldots,n\$：</p>\n<ul>\n<li><code>Backspace</code>：删除第一个元素；</li>\n<li><code>Delete</code>：删除最后一个元素。</li>\n</ul>\n"
	. "<h2><strong>输入格式</strong></h2>\n<p>第二行包含 \$n\$ 个整数 \$a_1,a_2,\\ldots,a_n\$，表示数组 \$a\$ \$(1\\le a_i\\le 10^9)\$。</p>\n"
	. "<h2><strong>样例</strong></h2>\n<pre><code class=\"language-input1\">5\n2 7 8 1 4</code></pre>\n<pre><code class=\"language-output1\">4 7 8 8 8</code></pre>\n<h2><strong>样例说明</strong></h2>\n<p>对于 \$k=1\$，最大得分为 \$4\$。</p>",
	$markdown->text($statement), 'Markdown: a statement written for Hydro');

// what is taken out of a text is what is put back: nothing of a long text is lost
$long = str_repeat("这是一段题面，包含公式 \$a_i \\le 10^9\$ 和 `code`，以及 **强调**。\n\n", 2000);
$html = $markdown->text($long);
check_same(2000, substr_count($html, '$a_i \le 10^9$'), 'Markdown: every formula of a long text is there');
check_same(false, strpos($html, 'UOJFORMULA'), 'Markdown: no word that stood for a formula is left');
