<?php

function mergeConfig(&$config, $default_config) {
	foreach ($default_config as $key => $val) {
		if (!isset($config[$key])) {
			$config[$key] = $val;
		} elseif (is_array($config[$key])) {
			mergeConfig($config[$key], $val);
		}
	}
}

function strStartWith($str, $pre) {
	return substr($str, 0, strlen($pre)) === $pre;
}

function strEndWith($str, $suf) {
	return substr($str, -strlen($suf)) === $suf;
}

function strOmit($str, $len) {
	if (strlen($str) <= $len + 3) {
		return $str;
	} else {
		return substr($str, 0, $len) . '...';
	}
}

function uojTextEncode($str, $config = array()) {
	mergeConfig($config, [
		'allow_CR' => false,
		'html_escape' => false
	]);
	
	$allow = array();
	for ($c = 32; $c <= 126; $c++) {
		$allow[chr($c)] = true;
	}
	$allow["\n"] = true;
	$allow[" "] = true;
	$allow["\t"] = true;
	
	if ($config['allow_CR']) {
		$allow["\r"] = true;
	}
	
	$len = strlen($str);
	$ok = true;
	for ($i = 0; $i < $len; $i++) {
		$c = $str[$i];
		if (!isset($allow[$c])) {
			$ok = false;
		}
	}
	if ($ok && mb_check_encoding($str, 'utf-8')) {
		if (!$config['html_escape']) {
			return $str;
		} else {
			return HTML::escape($str);
		}
	} else {
		$len = strlen($str);
		$res = '';
		$i = 0;
		while ($i < $len) {
			$c = $str[$i];
			if (ord($c) < 128) {
				if (isset($allow[$c])) {
					if ($config['html_escape']) {
						if ($c == '&') {
							$res .= '&amp;';
						} elseif ($c == '"') {
							$res .= '&quot;';
						} elseif ($c == '<') {
							$res .= '&lt;';
						} elseif ($c == '>') {
							$res .= '&gt;';
						} else {
							$res .= $c;
						}
					} else {
						$res .= $c;
					}
				} else {
					$res .= '<b>\x' . bin2hex($c) . '</b>';
				}
				$i++;
			} else {
				$ok = false;
				$cur = $c;
				for ($j = $i + 1; $j < $i + 4 && $j < $len; $j++) {
					$cur .= $str[$j];
					if (mb_check_encoding($cur, 'utf-8')) {
						$ok = true;
						break;
					}
				}
				if ($ok) {
					$res .= $cur;
					$i = $j + 1;
				} else {
					$res .= '<b>\x' . bin2hex($c) . '</b>';
					$i++;
				}
			}
		}
		return $res;
	}
}

function base64url_encode($data) {
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode($data) {
	return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

// The name of a file without the folders it lies in, and the two parts of it around its last
// dot. basename() and pathinfo() go by the locale before PHP 8: in the "C" locale of a server
// they drop the characters a name like 题面.pdf begins with.
function uojFileBaseName($path) {
	$cut = max(strrpos('/' . $path, '/'), strrpos('\\' . $path, '\\'));
	return (string)substr($path, $cut);
}
// array(what is before the last dot, what is after it); a name without a dot has no ending
function uojFileNameParts($name) {
	$dot = strrpos($name, '.');
	if ($dot === false) {
		return array($name, '');
	}
	return array((string)substr($name, 0, $dot), (string)substr($name, $dot + 1));
}

function blog_name_encode($name) {
	$name = str_replace('-', '_', $name);
	if (!strStartWith($name, '_') && !strEndWith($name, '_')) {
		$name = str_replace('_', '-', $name);
	}
	$name = strtolower($name);
	return $name;
}
function blog_name_decode($name) {
	$name = str_replace('-', '_', $name);
	$name = strtolower($name);
	return $name;
}

function getProblemExtraConfig($problem) {
	$extra_config = json_decode($problem['extra_config'], true);
	
	$default_extra_config = array(
		'view_content_type' => 'ALL',
		'view_all_details_type' => 'ALL',
		'view_details_type' => 'ALL'
	);
	
	mergeConfig($extra_config, $default_extra_config);
	
	return $extra_config;
}
function getProblemSubmissionRequirement($problem) {
	return json_decode($problem['submission_requirement'], true);
}
function getProblemCustomTestRequirement($problem) {
	$extra_config = json_decode($problem['extra_config'], true);
	if (isset($extra_config['custom_test_requirement'])) {
		return $extra_config['custom_test_requirement'];
	} else {
		$answer = array(
			'name' => 'answer',
			'type' => 'source code',
			'file_name' => 'answer.code'
		);
		foreach (getProblemSubmissionRequirement($problem) as $req) {
			if ($req['name'] == 'answer' && $req['type'] == 'source code' && isset($req['languages'])) {
				$answer['languages'] = $req['languages'];
			}
		}
		return array(
			$answer,
			array(
				'name' => 'input',
				'type' => 'text',
				'file_name' => 'input.txt'
			)
		);
	}
}

function sendSystemMsg($username, $title, $content) {
	$content = DB::escape($content);
	$title = DB::escape($title);
	DB::insert("insert into user_system_msg (receiver, title, content, send_time) values ('$username', '$title', '$content', now())");
}

// Records who did what to the audit log. $before and $after are what changed, as far as it is
// worth keeping. The actor is the user who is logged in, unless one is given; without a user
// it is the system itself, as when a successful hack changes the data of a problem.
// The log never stops what it records: a failure to write it is only reported.
function auditLog($action, $resource_type, $resource_id, $before = null, $after = null, $actor = null) {
	if ($actor === null) {
		$actor = Auth::user();
	}
	$json = function($value) {
		return $value === null ? 'null' : "'".DB::escape(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))."'";
	};
	$ok = DB::insert("insert into audit_logs (actor, actor_id, actor_type, action, resource_type, resource_id, before_json, after_json, ip, created_at) values ("
		."'".($actor ? DB::escape($actor['username']) : '')."', "
		.($actor && isset($actor['id']) ? (int)$actor['id'] : 'null').", "
		."'".($actor ? 'user' : 'system')."', "
		."'".DB::escape($action)."', '".DB::escape($resource_type)."', '".DB::escape($resource_id)."', "
		.$json($before).", ".$json($after).", "
		."'".DB::escape(UOJContext::remoteAddr())."', now())");
	if (!$ok) {
		error_log("audit log: failed to record $action of $resource_type $resource_id");
	}
}
