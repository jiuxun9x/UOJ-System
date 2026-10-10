<?php

// The balloons of a contest: who is brought one, for what, and what is said of it.

require_once __DIR__ . '/../app/libs/uoj-balloon-lib.php';

// a balloon as one line: when, who, which problem, what is special about it, what they have
$lines = function($passed, $until = null) {
	list($balloons, $held_back) = balloonsOf($passed, $until);
	$lines = array();
	foreach ($balloons as $balloon) {
		$lines[] = $balloon['offset'] . ' ' . $balloon['username'] . ' ' . chr(ord('A') + $balloon['pos'])
			. ($balloon['first_in_contest'] ? ' first' : '') . ($balloon['first_for_problem'] ? ' first-of-problem' : '')
			. ' #' . $balloon['nth'] . ' has ' . join('', array_map(function($pos) {
				return chr(ord('A') + $pos);
			}, $balloon['has'])) . ' by ' . $balloon['submission'];
	}
	return array($lines, $held_back);
};

check_same(array(array(), 0), $lines(array()), 'a contest nobody solved anything in has no balloons');
// rows of id, seconds into the contest, username, position of the problem
$passed = array(
	array(11, 600, 'ann', 0),
	array(12, 900, 'bob', 0),
	array(13, 1200, 'ann', 0),   // solved again: no second balloon
	array(14, 1500, 'bob', 1),
	array(15, 1800, 'ann', 1),
	array(16, 2400, 'cat', 2)
);
check_same(array(array(
	'600 ann A first first-of-problem #1 has A by 11',
	'900 bob A #1 has A by 12',
	'1500 bob B first-of-problem #2 has AB by 14',
	'1800 ann B #2 has AB by 15',
	'2400 cat C first-of-problem #1 has C by 16'
), 0), $lines($passed), 'one balloon for a problem of a contestant, the first of everything marked');
$shuffled = array($passed[4], $passed[2], $passed[0], $passed[5], $passed[3], $passed[1]);
check_same($lines($passed), $lines($shuffled), 'the order they are handed over in does not matter');
// what was submitted at the same second is told apart by what was submitted first
check_same(array(array('60 bob A first first-of-problem #1 has A by 3', '60 ann A #1 has A by 4'), 0),
	$lines(array(array(4, 60, 'ann', 0), array(3, 60, 'bob', 0))), 'the same second');
// a submission that was moved in time (a rejudge does not move it; a test does) is where its time says
check_same(array(array('30 bob A first first-of-problem #1 has A by 9', '60 ann A #1 has A by 4'), 0),
	$lines(array(array(4, 60, 'ann', 0), array(9, 30, 'bob', 0))), 'the time decides, not the number');

// ---- while the board is frozen
check_same(array(array(
	'600 ann A first first-of-problem #1 has A by 11',
	'900 bob A #1 has A by 12',
	'1500 bob B first-of-problem #2 has AB by 14'
), 2), $lines($passed, 1800), 'what was solved since the board froze is held back, and counted');
check_same(array(array('600 ann A first first-of-problem #1 has A by 11', '900 bob A #1 has A by 12'), 3), $lines($passed, 1200),
	'a problem that was solved before is not held back for being solved again');
check_same(array(array(), 5), $lines($passed, 0), 'a board that is frozen from the start');
check_same($lines($passed), $lines($passed, 2401), 'and one that froze after everything');
// usernames that are numbers stay strings, and are told apart
list($numbered) = balloonsOf(array(array(1, 10, '20260101', 0), array(2, 20, '20260102', 0)));
check_same(array('20260101', '20260102'), array($numbered[0]['username'], $numbered[1]['username']), 'usernames that are numbers');

// ---- colours
$palette = balloonPalette();
check_same(true, count($palette) >= 13, 'a contest of thirteen problems has thirteen colours');
check_same(count($palette), count(array_unique(array_column($palette, 0))), 'no colour of the palette is there twice');
check_same(count($palette), count(array_unique(array_column($palette, 1))), 'nor any of its names');
foreach ($palette as $preset) {
	check_same('', balloonColorError($preset[0], $preset[1]), "the colour {$preset[1]} of the palette is a colour");
}
check_same(array('color' => $palette[0][0], 'name' => $palette[0][1]), balloonDefaultColor(0), 'the first problem has the first colour');
check_same(balloonDefaultColor(1), balloonDefaultColor(1 + count($palette)), 'more problems than colours: the colours come round again');
foreach (array('#12ab9F' => '', '#000000' => '', 'red' => 'x', '#12345' => 'x', '#1234567' => 'x', '#12345g' => 'x', "#123456\n" => 'x', '' => 'x') as $color => $refused) {
	check_same($refused === '', balloonColorError($color, '绿色') === '', 'the colour ' . json_encode($color));
}
check_same(false, balloonColorError(null, '绿色') === '', 'a colour that is not text');
foreach (array('' => '', '荧光绿' => '', 'light green' => '', '一二三四五六七八九十一二三四五六七八九十' => '', '一二三四五六七八九十一二三四五六七八九十一' => 'x', '<b>' => 'x', 'a"b' => 'x', "a\nb" => 'x') as $name => $refused) {
	check_same($refused === '', balloonColorError('#00ff00', $name) === '', 'the name ' . json_encode($name, JSON_UNESCAPED_UNICODE));
}
check_same(false, balloonColorError('#00ff00', array('x')) === '', 'a name that is not text');
// what is written on a balloon can be read on it
check_same(array('#ffffff', '#000000', '#000000', '#ffffff', '#ffffff', '#000000', '#000000'),
	array(balloonInk('#212121'), balloonInk('#ffffff'), balloonInk('#fdd835'), balloonInk('#1e88e5'), balloonInk('#E53935'), balloonInk('#00ff00'), balloonInk('#fb8c00')),
	'dark ink on light colours, light ink on dark ones; green is light and blue is dark');

// ---- which contests have balloons to give
check_same(array(true, true, false), array(balloonRuleGives('ICPC'), balloonRuleGives('IOI'), balloonRuleGives('OI')), 'a rule that tells nobody what was solved gives no balloons');
