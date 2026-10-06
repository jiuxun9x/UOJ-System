<?php

require_once __DIR__ . '/../vendor/parsedown/ParsedownMath.php';

class UOJMarkdown extends ParsedownMath {
	public function __construct($options = '') {
		if (method_exists(get_parent_class(), "__construct")) {
			parent::__construct($options);
		}

		// https://gist.github.com/ShNURoK42/b5ce8baa570975db487c
		$this->InlineTypes['@'][] = 'UserMention';
	}

	// ---- formulas
	//
	// What stands between the signs of a formula is not Markdown: MathJax sets it in the
	// browser, as it was written. So the formulas are taken out of a text before Markdown
	// reads it, each leaving a word that Markdown has no reason to touch, and are put back
	// into what Markdown made of the rest.
	//
	//   $…$    \(…\)     a formula in a line
	//   $$…$$  \[…\]     a formula that stands by itself, on one line or on several
	//
	// A formula ends in the paragraph it begins in, so a sign that is alone does not take the
	// rest of the text with it. Code is not searched for formulas: what stands between
	// backticks, in a fenced block, or in <pre> and <code>. "\$" is a dollar sign.
	//
	// (The extension this class is built on reads formulas while Markdown reads the line.
	// It was written for a later Parsedown than the one that is here: a formula followed by
	// text on its line took that text for itself, and an escaped sign was lost.)

	public function text($text) {
		$text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
		// what is copied out of a PDF brings characters along that nothing shows
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
		list($text, $formulas) = self::takeFormulas($text);
		$html = parent::text($text);
		foreach ($formulas as $word => $formula) {
			$formulas[$word] = htmlspecialchars($formula, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
		return strtr($html, $formulas);
	}

	// array(the text with a word where each formula was, array(word => formula))
	public static function takeFormulas($text) {
		$n = strlen($text);
		$word = 'UOJFORMULA' . bin2hex(random_bytes(6)) . 'N';
		$formulas = array();
		$out = '';
		$i = 0;
		$line_start = 0;
		$at_line_start = true;
		$paragraph_end = -1;
		$quote = '[ \t]*(?:>[ \t]?)*[ \t]*';
		while ($i < $n) {
			if ($at_line_start) {
				$at_line_start = false;
				$line_start = $i;
				// a fenced block of code is left as it is, to the line that closes it
				if (preg_match("/\\G$quote(`{3,}|~{3,})[^\n]*(?:\n|$)/", $text, $m, 0, $i)) {
					$j = $i + strlen($m[0]);
					$closes = "/\\G$quote" . preg_quote($m[1][0], '/') . '{' . strlen($m[1]) . ",}[ \t]*(?:\n|$)/";
					while ($j < $n && !preg_match($closes, $text, $c, 0, $j)) {
						$next = strpos($text, "\n", $j);
						$j = $next === false ? $n : $next + 1;
					}
					$j = $j < $n ? $j + strlen($c[0]) : $n;
					$out .= substr($text, $i, $j - $i);
					$i = $j;
					$at_line_start = true;
					continue;
				}
			}
			$j = $i + strcspn($text, "`$\\<\n", $i);
			$out .= substr($text, $i, $j - $i);
			$i = $j;
			if ($i >= $n) {
				break;
			}
			$sign = $text[$i];
			if ($sign === "\n") {
				$out .= "\n";
				$i++;
				$at_line_start = true;
				continue;
			}
			if ($i >= $paragraph_end) {
				// where the paragraph ends: at an empty line, at a fenced block, or with the text
				$paragraph_end = preg_match("/\n$quote(?:\n|`{3,}|~{3,}|$)/", $text, $m, PREG_OFFSET_CAPTURE, $i) ? $m[0][1] : $n;
			}
			if ($sign === '`') {
				// code in a line: to as many backticks as it began with
				$run = strspn($text, '`', $i);
				$j = $i + $run;
				$close = null;
				while ($j < $paragraph_end && ($j = strpos($text, '`', $j)) !== false && $j < $paragraph_end) {
					$found = strspn($text, '`', $j);
					if ($found === $run) {
						$close = $j;
						break;
					}
					$j += $found;
				}
				$j = $close === null ? $i + $run : $close + $run;
				$out .= substr($text, $i, $j - $i);
				$i = $j;
				continue;
			}
			if ($sign === '<') {
				$j = $i + 1;
				if (preg_match('/\G<(pre|code)\b[^>]*>/i', $text, $m, 0, $i)) {
					$close = stripos($text, "</{$m[1]}>", $i);
					$j = $close === false ? $i + strlen($m[0]) : $close + strlen($m[1]) + 3;
				}
				$out .= substr($text, $i, $j - $i);
				$i = $j;
				continue;
			}
			$end = null;
			if ($sign === '\\') {
				$next = $i + 1 < $n ? $text[$i + 1] : '';
				if ($next === '(' || $next === '[') {
					$opens = 2;
					$end = self::formulaEnd($text, $i + 2, $next === '(' ? '\\)' : '\\]', $paragraph_end);
				}
				if ($end === null) {
					// any other pair that begins with a backslash is for Markdown to read
					$j = $i + ($next === '' || $next === "\n" ? 1 : 2);
					$out .= substr($text, $i, $j - $i);
					$i = $j;
					continue;
				}
			} else {
				$opens = $i + 1 < $n && $text[$i + 1] === '$' ? 2 : 1;
				$end = self::formulaEnd($text, $i + $opens, str_repeat('$', $opens), $paragraph_end);
				if ($end === null || $end === $i + $opens) {
					// a sign that opens nothing is a sign
					$out .= substr($text, $i, $opens);
					$i += $opens;
					continue;
				}
			}
			$formula = substr($text, $i, $end + $opens - $i);
			$in_line = $formula[0] === '$' ? $opens === 1 : $formula[1] === '(';
			if ($in_line && preg_match('/\n[ \t]*(?:>[ \t]?)*[ \t]*(?:#{1,6}[ \t]|[-*+][ \t]|\d+[.)][ \t]|\|)/', $formula)) {
				// A formula in a line may go on in the next line, but not into the next
				// heading, item of a list or row of a table: the sign was alone.
				$out .= substr($text, $i, $opens);
				$i += $opens;
				continue;
			}
			if (strpos($formula, "\n") !== false && preg_match('/\G[ \t]*>/', $text, $m, 0, $line_start)) {
				// the lines of a quotation begin with its sign, which is not of the formula
				$formula = preg_replace('/\n[ \t]*(?:>[ \t]?)+/', "\n", $formula);
			}
			$name = $word . count($formulas) . 'E';
			$formulas[$name] = $formula;
			$out .= $name;
			$i = $end + $opens;
		}
		return array($out, $formulas);
	}

	// where the sign that closes a formula is, looking from $from to $limit, or null
	private static function formulaEnd($text, $from, $closer, $limit) {
		$j = $from;
		while ($j < $limit) {
			$j += strcspn($text, '$\\', $j, $limit - $j);
			if ($j >= $limit) {
				return null;
			}
			if (substr_compare($text, $closer, $j, strlen($closer)) === 0 && $j + strlen($closer) <= $limit) {
				return $j;
			}
			// a sign after a backslash is not the end of anything
			$j += $text[$j] === '\\' ? 2 : 1;
		}
		return null;
	}

	// the formulas are not in the text any more when Markdown reads it
	protected function inlineMath($Excerpt) {
		return null;
	}
	protected function blockMath($Line) {
		return null;
	}
	protected function inlineEscapeSequence($Excerpt) {
		if (isset($Excerpt['text'][1]) && in_array($Excerpt['text'][1], $this->specialCharacters)) {
			return array('markup' => self::escape($Excerpt['text'][1]), 'extent' => 2);
		}
	}

	// https://github.com/taufik-nurrohman/parsedown-extra-plugin/blob/1653418c5a9cf5277cd28b0b23ba2d95d18e9bc4/ParsedownExtraPlugin.php#L340-L345
	protected function doGetAttributes($Element) {
		if (isset($Element['attributes'])) {
			return (array) $Element['attributes'];
		}
		return array();
	}

	// https://github.com/taufik-nurrohman/parsedown-extra-plugin/blob/1653418c5a9cf5277cd28b0b23ba2d95d18e9bc4/ParsedownExtraPlugin.php#L347-L358
	protected function doGetContent($Element) {
		if (isset($Element['text'])) {
			return $Element['text'];
		}
		if (isset($Element['rawHtml'])) {
			return $Element['rawHtml'];
		}
		if (isset($Element['handler']['argument'])) {
			return implode("\n", (array) $Element['handler']['argument']);
		}
		return null;
	}

	// https://github.com/taufik-nurrohman/parsedown-extra-plugin/blob/1653418c5a9cf5277cd28b0b23ba2d95d18e9bc4/ParsedownExtraPlugin.php#L369-L378
	protected function doSetAttributes(&$Element, $From, $Args = array()) {
		$Attributes = $this->doGetAttributes($Element);
		$Content = $this->doGetContent($Element);
		if (is_callable($From)) {
			$Args = array_merge(array($Content, $Attributes, &$Element), $Args);
			$Element['attributes'] = array_replace($Attributes, (array) call_user_func_array($From, $Args));
		} else {
			$Element['attributes'] = array_replace($Attributes, (array) $From);
		}
	}

	// Add classes to <table>
	protected function blockTableComplete($Block) {
		$this->doSetAttributes($Block['element'], ['class' => 'table table-bordered']);

		return $Block;
	}

	// https://gist.github.com/ShNURoK42/b5ce8baa570975db487c
	protected function inlineUserMention($Excerpt) {
		if (preg_match('/^@([^\s]+)/', $Excerpt['text'], $matches)) {
			$mentioned_user = queryUser($matches[1]);

			if ($mentioned_user) {
				return [
					'extent' => strlen($matches[0]),
					'element' => [
						'name' => 'span',
						'text' => '@' . $mentioned_user['username'],
						'attributes' => [
							'class' => "uoj-username",
						],
					],
				];
			}

			return [
				'extent' => strlen($matches[0]),
				'markup' => $matches[0],
			];
		}
	}
}
