<?php

// Balloons: in a contest that is held in a room, whoever solves a problem is brought a
// balloon in the colour of the problem. The people who run the contest have a list of the
// balloons that are to be brought, with where the contestant sits, and tick off the ones
// that were. It is done the way DOMjudge does it:
// - a contestant gets one balloon for a problem, when they solve it for the first time;
// - a problem has a colour, which has a name that whoever carries the balloons can read;
// - the first to solve anything, and the first to solve a problem, are marked;
// - while the board is frozen no new balloons are given, unless the contest says otherwise:
//   a balloon on a desk tells the room what the board does not.
//
// A contest gives balloons when it says so ('balloons'). Under the OI rule nobody knows
// during the contest what was solved, and there are no balloons to give.

// ---- deciding without the database

// the colours that the problems of a contest have when nobody chose any, in their order:
// array(colour, its name)
function balloonPalette() {
	return array(
		array('#e53935', '红色'), array('#fb8c00', '橙色'), array('#fdd835', '黄色'), array('#43a047', '绿色'),
		array('#00acc1', '青色'), array('#1e88e5', '蓝色'), array('#8e24aa', '紫色'), array('#f06292', '粉色'),
		array('#ffffff', '白色'), array('#212121', '黑色'), array('#8d6e63', '棕色'), array('#9e9e9e', '灰色'),
		array('#c0ca33', '黄绿色'), array('#283593', '深蓝色'), array('#d4af37', '金色'), array('#80deea', '浅蓝色')
	);
}
// the colour of the problem at a position, when nobody chose one: array('color', 'name')
function balloonDefaultColor($pos) {
	$palette = balloonPalette();
	list($color, $name) = $palette[$pos % count($palette)];
	return array('color' => $color, 'name' => $name);
}
// The colours that have a name everybody who writes pages knows them by, which is what
// DOMjudge takes for the colour of a problem as well: name => '#rrggbb'.
function balloonColorNames() {
	static $names = null;
	if ($names === null) {
		$names = array();
		$list = 'aliceblue f0f8ff antiquewhite faebd7 aqua 00ffff aquamarine 7fffd4 azure f0ffff beige f5f5dc bisque ffe4c4 black 000000 blanchedalmond ffebcd blue 0000ff'
			. ' blueviolet 8a2be2 brown a52a2a burlywood deb887 cadetblue 5f9ea0 chartreuse 7fff00 chocolate d2691e coral ff7f50 cornflowerblue 6495ed cornsilk fff8dc crimson dc143c'
			. ' cyan 00ffff darkblue 00008b darkcyan 008b8b darkgoldenrod b8860b darkgray a9a9a9 darkgreen 006400 darkgrey a9a9a9 darkkhaki bdb76b darkmagenta 8b008b'
			. ' darkolivegreen 556b2f darkorange ff8c00 darkorchid 9932cc darkred 8b0000 darksalmon e9967a darkseagreen 8fbc8f darkslateblue 483d8b darkslategray 2f4f4f'
			. ' darkslategrey 2f4f4f darkturquoise 00ced1 darkviolet 9400d3 deeppink ff1493 deepskyblue 00bfff dimgray 696969 dimgrey 696969 dodgerblue 1e90ff firebrick b22222'
			. ' floralwhite fffaf0 forestgreen 228b22 fuchsia ff00ff gainsboro dcdcdc ghostwhite f8f8ff gold ffd700 goldenrod daa520 gray 808080 green 008000 greenyellow adff2f'
			. ' grey 808080 honeydew f0fff0 hotpink ff69b4 indianred cd5c5c indigo 4b0082 ivory fffff0 khaki f0e68c lavender e6e6fa lavenderblush fff0f5 lawngreen 7cfc00'
			. ' lemonchiffon fffacd lightblue add8e6 lightcoral f08080 lightcyan e0ffff lightgoldenrodyellow fafad2 lightgray d3d3d3 lightgreen 90ee90 lightgrey d3d3d3'
			. ' lightpink ffb6c1 lightsalmon ffa07a lightseagreen 20b2aa lightskyblue 87cefa lightslategray 778899 lightslategrey 778899 lightsteelblue b0c4de lightyellow ffffe0'
			. ' lime 00ff00 limegreen 32cd32 linen faf0e6 magenta ff00ff maroon 800000 mediumaquamarine 66cdaa mediumblue 0000cd mediumorchid ba55d3 mediumpurple 9370db'
			. ' mediumseagreen 3cb371 mediumslateblue 7b68ee mediumspringgreen 00fa9a mediumturquoise 48d1cc mediumvioletred c71585 midnightblue 191970 mintcream f5fffa'
			. ' mistyrose ffe4e1 moccasin ffe4b5 navajowhite ffdead navy 000080 oldlace fdf5e6 olive 808000 olivedrab 6b8e23 orange ffa500 orangered ff4500 orchid da70d6'
			. ' palegoldenrod eee8aa palegreen 98fb98 paleturquoise afeeee palevioletred db7093 papayawhip ffefd5 peachpuff ffdab9 peru cd853f pink ffc0cb plum dda0dd'
			. ' powderblue b0e0e6 purple 800080 rebeccapurple 663399 red ff0000 rosybrown bc8f8f royalblue 4169e1 saddlebrown 8b4513 salmon fa8072 sandybrown f4a460'
			. ' seagreen 2e8b57 seashell fff5ee sienna a0522d silver c0c0c0 skyblue 87ceeb slateblue 6a5acd slategray 708090 slategrey 708090 snow fffafa springgreen 00ff7f'
			. ' steelblue 4682b4 tan d2b48c teal 008080 thistle d8bfd8 tomato ff6347 turquoise 40e0d0 violet ee82ee wheat f5deb3 white ffffff whitesmoke f5f5f5 yellow ffff00'
			. ' yellowgreen 9acd32';
		$words = explode(' ', $list);
		for ($i = 0; $i + 1 < count($words); $i += 2) {
			$names[$words[$i]] = '#' . $words[$i + 1];
		}
	}
	return $names;
}
// The colour somebody wrote, as '#rrggbb', or null for what is no colour. A colour is written
// by its code, the way Hydro has the colours of balloons written - "#e53935", "e53935", "#e35"
// - or by its name: one of the names above, or the name of a colour of the palette ("红色").
function balloonParseColor($text) {
	if (!is_string($text)) {
		return null;
	}
	$text = strtolower(trim($text));
	if (preg_match('/^#?([0-9a-f]{6})$/D', $text, $matches)) {
		return '#' . $matches[1];
	}
	if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/D', $text, $matches)) {
		return '#' . $matches[1] . $matches[1] . $matches[2] . $matches[2] . $matches[3] . $matches[3];
	}
	$names = balloonColorNames();
	if (isset($names[str_replace(array(' ', '-', '_'), '', $text)])) {
		return $names[str_replace(array(' ', '-', '_'), '', $text)];
	}
	foreach (balloonPalette() as $preset) {
		if ($text === $preset[1] || $text . '色' === $preset[1]) {
			return $preset[0];
		}
	}
	return null;
}
// what the colours of the palette are called: '#rrggbb' => name
function balloonPaletteNames() {
	$names = array();
	foreach (balloonPalette() as $preset) {
		$names[$preset[0]] = $preset[1];
	}
	return $names;
}
// Returns '' when a balloon can have this colour and this name for it, or why not.
function balloonColorError($color, $name) {
	if (balloonParseColor($color) === null) {
		return '颜色要写成代码（例如 #e53935）或英文颜色名（例如 red、lightblue）';
	}
	if (!is_string($name) || mb_strlen($name, 'UTF-8') > 20 || preg_match('/[\x00-\x1f\x7f<>"\'&]/', $name)) {
		return '颜色的名字最多 20 个字，不能有引号和尖括号';
	}
	return '';
}
// What is written on a colour is read on it: '#000000' on a light one, '#ffffff' on a dark
// one. How light a colour is to the eye is not the mean of its parts: green is light, blue
// is dark.
function balloonInk($color) {
	$light = 0;
	foreach (array_combine(array('0.2126', '0.7152', '0.0722'), sscanf($color, '#%02x%02x%02x')) as $weight => $part) {
		$part /= 255;
		$light += $weight * ($part <= 0.03928 ? $part / 12.92 : pow(($part + 0.055) / 1.055, 2.4));
	}
	return $light > 0.25 ? '#000000' : '#ffffff';
}
// The colours of several problems, written the way people keep such lists: a line for a
// problem, with its letter, its colour and - if they like - what the colour is called.
//     A #e53935 红色
//     B: lightblue
//     C 黄色
//   $letters   the letters of the problems of the contest
// Returns array(letter => array(colour as '#rrggbb', name), '') or array(null, what is wrong
// with which line).
function balloonParseBulk($text, $letters) {
	$chosen = array();
	foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $index => $line) {
		$line = trim(preg_replace('/(^|\s)#\s.*$/u', '', $line));
		if ($line === '') {
			continue;
		}
		$number = $index + 1;
		if (!preg_match('/^([A-Za-z])\s*[:：,，\s]\s*(\S+)(?:\s+(.+))?$/u', $line, $matches)) {
			return array(null, "第 {$number} 行应是“题号 颜色 名字”，例如 A #e53935 红色");
		}
		$letter = strtoupper($matches[1]);
		if (!in_array($letter, $letters, true)) {
			return array(null, "第 {$number} 行：比赛里没有 {$letter} 题");
		}
		$color = balloonParseColor(trim($matches[2], '"\'，,'));
		if ($color === null) {
			return array(null, "第 {$number} 行：“{$matches[2]}”不是颜色，" . balloonColorError('', ''));
		}
		$name = isset($matches[3]) ? trim($matches[3], " \t\"'") : '';
		if ($name === '') {
			// a colour that has a name here is called by it; another is called by its code
			$palette = balloonPaletteNames();
			$name = isset($palette[$color]) ? $palette[$color] : (balloonParseColor($matches[2]) !== null && !preg_match('/^#?[0-9a-f]+$/i', $matches[2]) ? $matches[2] : '');
		}
		$err = balloonColorError($color, $name);
		if ($err !== '') {
			return array(null, "第 {$number} 行：{$err}");
		}
		$chosen[$letter] = array($color, $name);
	}
	if (!$chosen) {
		return array(null, '一行也没有写');
	}
	return array($chosen, '');
}
// The colours of the problems as balloonParseBulk() reads them: to be kept, changed as a
// text, or carried to another contest.
function balloonBulkText($problems) {
	$text = '';
	foreach ($problems as $problem) {
		$text .= $problem['letter'] . ' ' . $problem['color'] . ($problem['name'] !== '' ? ' ' . $problem['name'] : '') . "\n";
	}
	return $text;
}
// A balloon as a page draws it: in its colour, with the letter of its problem on it.
//   $first   whether it is the balloon of who solved the problem first: it has a star then,
//            which is how whoever carries it sees that it is one to make something of
function balloonMarkHTML($letter, $color, $name = '', $small = false, $first = false) {
	$title = htmlspecialchars($letter . ($name !== '' ? ' ' . $name : '') . ($first ? '（一血）' : ''), ENT_QUOTES, 'UTF-8');
	return '<span class="uoj-balloon' . ($small ? ' uoj-balloon-sm' : '') . ($first ? ' uoj-balloon-starred' : '') . '" title="' . $title . '" data-letter="' . $letter . '">'
		. '<svg viewBox="0 0 24 31" aria-hidden="true" focusable="false">'
		. '<path class="uoj-balloon-string" d="M12 25.5c0 2.2-1.8 2.6-1 5" />'
		. '<path class="uoj-balloon-body" fill="' . $color . '" d="M12 1.2c-5.6 0-9.6 4.2-9.6 9.8 0 5.3 4 9.9 8.3 11.3l-1.3 2.6h5.2l-1.3-2.6c4.3-1.4 8.3-6 8.3-11.3 0-5.6-4-9.8-9.6-9.8z" />'
		. '<text x="12" y="15.4" text-anchor="middle" fill="' . balloonInk($color) . '">' . $letter . '</text>'
		. ($first ? '<path class="uoj-balloon-star" d="M19.2 0.4L20.5 3.8L24.1 4.0L21.3 6.3L22.3 9.8L19.2 7.8L16.1 9.8L17.1 6.3L14.3 4.0L17.9 3.8z" />' : '')
		. '</svg></span>';
}

// whether a contest of this rule has anything to give balloons for while it runs
function balloonRuleGives($rule) {
	return $rule !== 'OI';
}

// The balloons of a contest.
//   $passed  the submissions of its contestants that passed: rows of id, seconds into the
//            contest, username, position of the problem; in any order
//   $until   seconds into the contest from which on no balloon is given, or null
// Returns array(the balloons, how many were held back). A balloon is the first time a
// contestant solved a problem: array('username', 'pos', 'submission', 'offset',
// 'first_in_contest', 'first_for_problem', 'nth' => the how-manieth balloon of the
// contestant it is, 'has' => the positions of the balloons they have with this one, in the
// order they got them). The oldest balloon is first.
function balloonsOf($passed, $until = null) {
	usort($passed, function($lhs, $rhs) {
		return $lhs[1] != $rhs[1] ? $lhs[1] - $rhs[1] : $lhs[0] - $rhs[0];
	});
	$balloons = array();
	$held_back = 0;
	$has = array();
	$solved = array();
	foreach ($passed as $row) {
		list($id, $offset, $username, $pos) = $row;
		$username = (string)$username;
		if (isset($has[$username]) && in_array($pos, $has[$username], true)) {
			continue;
		}
		if ($until !== null && $offset >= $until) {
			// counted once for a problem of a contestant, as a balloon would be
			$has[$username][] = $pos;
			$held_back++;
			continue;
		}
		$has[$username][] = $pos;
		$balloons[] = array(
			'username' => $username, 'pos' => $pos, 'submission' => (int)$id, 'offset' => (int)$offset,
			'first_in_contest' => !$balloons, 'first_for_problem' => !isset($solved[$pos]),
			'nth' => count($has[$username]), 'has' => $has[$username]
		);
		$solved[$pos] = true;
	}
	return array($balloons, $held_back);
}

// ---- queries

// whether the contest gives balloons
function balloonsEnabled($contest) {
	return !empty($contest['balloons']) && balloonRuleGives(contestRule($contest));
}
// From when on the contest holds its balloons back, in seconds into it, or null: while its
// board is frozen, which it is until the results are published.
function balloonsHeldFrom($contest) {
	if (!empty($contest['balloons_after_freeze']) || $contest['cur_progress'] >= CONTEST_FINISHED) {
		return null;
	}
	return contestFreezeOffset($contest);
}
// the colours of the problems of a contest: id of the problem => array('color', 'name',
// 'chosen' => whether somebody chose it)
function balloonColors($contest) {
	$chosen = array();
	foreach (DB::selectAll("select problem_id, color, name from contest_balloon_colors where contest_id = {$contest['id']}", MYSQLI_ASSOC) as $row) {
		$chosen[(int)$row['problem_id']] = array('color' => strtolower($row['color']), 'name' => $row['name'], 'chosen' => true);
	}
	$colors = array();
	foreach (contestProblemIds($contest['id']) as $pos => $problem_id) {
		$colors[(int)$problem_id] = isset($chosen[(int)$problem_id]) ? $chosen[(int)$problem_id] : balloonDefaultColor($pos) + array('chosen' => false);
	}
	return $colors;
}
// The list of the balloons of a contest, the oldest first. Returns array('balloons' =>
// what balloonsOf() says of each, with 'problem_id', 'letter', 'color', 'color_name', 'seat',
// 'done_at' and 'done_by' (null while it was not brought), 'held_back' => how many are not
// in it because the board is frozen).
function balloonQueue($contest) {
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$pos = array_flip($problem_ids);
	$start = $contest['start_time']->getTimestamp();
	$passed = array();
	$seats = array();
	foreach (DB::selectAll("select username, seat from contests_registrants where contest_id = {$contest['id']}", MYSQLI_ASSOC) as $row) {
		$seats[(string)$row['username']] = $row['seat'];
	}
	// what passed, of the people who are contestants of it now
	foreach (DB::selectAll("select id, submit_time, submitter, problem_id from submissions where contest_id = {$contest['id']} and score = 100 order by id", MYSQLI_ASSOC) as $row) {
		if (isset($pos[(int)$row['problem_id']]) && isset($seats[(string)$row['submitter']])) {
			$passed[] = array((int)$row['id'], strtotime($row['submit_time']) - $start, (string)$row['submitter'], $pos[(int)$row['problem_id']]);
		}
	}
	list($balloons, $held_back) = balloonsOf($passed, balloonsHeldFrom($contest));
	$done = array();
	foreach (DB::selectAll("select username, problem_id, done_at, done_by from contest_balloons where contest_id = {$contest['id']}", MYSQLI_ASSOC) as $row) {
		$done[$row['username'] . '/' . (int)$row['problem_id']] = $row;
	}
	$colors = balloonColors($contest);
	foreach ($balloons as &$balloon) {
		$problem_id = $problem_ids[$balloon['pos']];
		$mark = isset($done[$balloon['username'] . '/' . $problem_id]) ? $done[$balloon['username'] . '/' . $problem_id] : null;
		$balloon += array(
			'problem_id' => $problem_id, 'letter' => chr(ord('A') + $balloon['pos'] % 26),
			'color' => $colors[$problem_id]['color'], 'color_name' => $colors[$problem_id]['name'],
			'seat' => $seats[$balloon['username']],
			'done_at' => $mark ? $mark['done_at'] : null, 'done_by' => $mark ? $mark['done_by'] : null
		);
	}
	unset($balloon);
	return array('balloons' => $balloons, 'held_back' => $held_back);
}
// how many balloons of a contest wait to be brought
function balloonPendingCount($contest) {
	$pending = 0;
	foreach (balloonQueue($contest)['balloons'] as $balloon) {
		$pending += $balloon['done_at'] === null ? 1 : 0;
	}
	return $pending;
}

// ---- changes; each returns '' or why it was refused

// whether the contest gives balloons, and whether it goes on when the board is frozen
function balloonSaveSettings($contest, $enabled, $after_freeze, $actor) {
	if ($enabled && !balloonRuleGives(contestRule($contest))) {
		return 'OI 赛制的比赛在进行中不公布结果，没有气球可发';
	}
	DB::update("update contests set balloons = ".($enabled ? 1 : 0).", balloons_after_freeze = ".($after_freeze ? 1 : 0)." where id = {$contest['id']}");
	auditLog('contest.balloon_settings', 'contest', $contest['id'],
		array('balloons' => !empty($contest['balloons']), 'after_freeze' => !empty($contest['balloons_after_freeze'])),
		array('balloons' => (bool)$enabled, 'after_freeze' => (bool)$after_freeze), $actor);
	return '';
}
// The colours of the problems: $colors is id of the problem => array(colour, name). A
// problem that is given the colour it would have anyway has no colour of its own.
function balloonSaveColors($contest, $colors, $actor) {
	$problem_ids = array_map('intval', contestProblemIds($contest['id']));
	$rows = array();
	foreach ($colors as $problem_id => $chosen) {
		list($color, $name) = $chosen;
		$pos = array_search((int)$problem_id, $problem_ids, true);
		if ($pos === false) {
			return '比赛里没有这道题';
		}
		$err = balloonColorError($color, $name);
		if ($err !== '') {
			return chr(ord('A') + $pos % 26) . ' 题：' . $err;
		}
		$rows[(int)$problem_id] = array(balloonParseColor($color), trim($name), $pos);
	}
	foreach ($rows as $problem_id => $row) {
		list($color, $name, $pos) = $row;
		$default = balloonDefaultColor($pos);
		if ($color === $default['color'] && ($name === '' || $name === $default['name'])) {
			DB::delete("delete from contest_balloon_colors where contest_id = {$contest['id']} and problem_id = $problem_id");
		} else {
			DB::insert("insert into contest_balloon_colors (contest_id, problem_id, color, name) values ({$contest['id']}, $problem_id, '".DB::escape($color)."', '".DB::escape($name)."')"
				." on duplicate key update color = values(color), name = values(name)");
		}
	}
	auditLog('contest.balloon_colors', 'contest', $contest['id'], null, array('problems' => count($rows)), $actor);
	return '';
}
// Writes down that a balloon was brought, or that it was not after all. A balloon that is
// not on the list is not brought.
function balloonSetDone($contest, $username, $problem_id, $done, $actor) {
	if (!balloonsEnabled($contest)) {
		return '这场比赛没有启用气球';
	}
	$found = false;
	foreach (balloonQueue($contest)['balloons'] as $balloon) {
		$found = $found || ($balloon['username'] === (string)$username && $balloon['problem_id'] === (int)$problem_id);
	}
	if (!$found) {
		return '没有这个气球：可能是提交被重测了，或者选手被移出了比赛';
	}
	$esc_username = DB::escape($username);
	if ($done) {
		// the first to say so is who brought it
		DB::insert("insert ignore into contest_balloons (contest_id, username, problem_id, done_at, done_by) values ({$contest['id']}, '$esc_username', ".(int)$problem_id.", '".DB::escape(UOJTime::$time_now_str)."', '".DB::escape($actor['username'])."')");
	} else {
		DB::delete("delete from contest_balloons where contest_id = {$contest['id']} and username = '$esc_username' and problem_id = ".(int)$problem_id);
	}
	return '';
}
