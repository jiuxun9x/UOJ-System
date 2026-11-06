<?php

// The outer board of a contest: what it says of the standings.

require_once __DIR__ . '/../app/libs/uoj-contest-lib.php';
require_once __DIR__ . '/../app/libs/uoj-board-lib.php';

// ---- the ICPC rule. The standings as calcStandings() leaves them, and the cells: score,
// penalty, submission, failed attempts, attempts nobody knows the outcome of
$standings = array(
	array(300, 3000 + 4200 + 1800 + 2 * 1200, array('ann', 1600, '安'), 1),
	array(200, 720 + 5400, array('bob', 1500, ''), 2),
	array(200, 720 + 5400, array('cat', 1500, ''), 2),
	array(100, 600, array('dan', 1500, ''), 4),
	array(0, 0, array('eve', 1500, ''), 5),
	array(0, 0, array('fay', 1500, ''), 5)
);
$score = array(
	// solved A and B, C after two attempts in vain, and failed D once
	'ann' => array(0 => array(100, 3000, 11, 0, 0), 1 => array(100, 4200, 12, 0, 0), 2 => array(100, 1800 + 2 * 1200, 13, 2, 0), 3 => array(0, 0, 14, 1, 0)),
	'bob' => array(0 => array(100, 720, 21, 0, 0), 1 => array(100, 5400, 22, 0, 0)),
	'cat' => array(0 => array(100, 720, 31, 0, 0), 1 => array(100, 5400, 32, 0, 0)),
	// solved A before anybody, and what he submitted to C since the board froze is not told
	'dan' => array(0 => array(100, 600, 41, 0, 0), 2 => array(0, 0, 42, 1, 2)),
	'eve' => array(3 => array(0, 0, 51, 3, 0)),
	'fay' => array()
);
$last_try = array('ann' => array(3 => 7000), 'eve' => array(3 => 300), 'dan' => array(2 => 9000));
$board = contestOuterBoard('ICPC', 5, $standings, $score, $last_try);

// a row as one line: rank, who, solved, penalty, dirt, band, and what every cell says
$line = function($row) {
	$cells = array();
	foreach ($row['cells'] as $cell) {
		$cells[] = $cell['kind'] === 'none' ? '.' : $cell['kind'] . ':' . $cell['says'] . ($cell['under'] !== '' ? '@' . $cell['under'] : '');
	}
	return $row['rank'] . ' ' . $row['key'] . ' ' . $row['solved'] . '/' . $row['penalty'] . ' dirt=' . var_export($row['dirt'], true) . ' band=' . $row['band'] . ' ' . join(' ', $cells);
};
check_same(array(
	'1 ann 3/11400 dirt=40 band=0 solved:+@0:50 first:+@1:10 first:+2@0:30 failed:-1@1:56 .',
	'2 bob 2/6120 dirt=0 band=1 solved:+@0:12 solved:+@1:30 . . .',
	'2 cat 2/6120 dirt=0 band=1 solved:+@0:12 solved:+@1:30 . . .',
	'4 dan 1/600 dirt=0 band=0 first:+@0:10 . pending:?@1 + 2 . .',
	'5 eve 0/0 dirt=NULL band=1 . . . failed:-3@0:05 .',
	'5 fay 0/0 dirt=NULL band=1 . . . . .'
), array_map($line, $board['rows']), 'the rows of an ICPC board: who solved what when, who was first, and the bands by how many were solved');
// Who solved first is told only when it is sure, and of the same second the smaller number is
// first, as on the balloon page: bob and cat solved E at the same second, cat with the smaller
// number though bob stands above him.
$same_second = $score;
$same_second['bob'][4] = array(100, 3600, 27, 0, 0);
$same_second['cat'][4] = array(100, 3600, 26, 0, 0);
$kinds = function($board, $pos) {
	$kinds = array();
	foreach ($board['rows'] as $row) {
		if ($row['cells'][$pos]['kind'] !== 'none') {
			$kinds[] = $row['key'] . ':' . $row['cells'][$pos]['kind'];
		}
	}
	return join(' ', $kinds);
};
check_same('bob:solved cat:first', $kinds(contestOuterBoard('ICPC', 5, $standings, $same_second, $last_try), 4), 'of the same second, the smaller number is first');
// a submission of before that is not judged yet may pass and be first: until it is judged nobody is
check_same('bob:solved cat:solved', $kinds(contestOuterBoard('ICPC', 5, $standings, $same_second, $last_try, array(array(25, 3500, 'eve', 4))), 4),
	'nobody is first while something submitted before waits');
check_same('bob:solved cat:first', $kinds(contestOuterBoard('ICPC', 5, $standings, $same_second, $last_try, array(array(28, 3600, 'eve', 4), array(29, 100, 'eve', 3))), 4),
	'what waits that was submitted after, or to another problem, does not matter');
check_same('ann:solved bob:solved cat:solved dan:first', $kinds(contestOuterBoard('ICPC', 5, $standings, $score, $last_try, array(array(25, 3500, 'eve', 4))), 0), 'nor to the first of another problem');

// contestFirstSolves(): by the second and then the number, sure once nothing before it waits
$firsts = contestFirstSolves(array(array(9, 300, 'bob', 1), array(7, 100, 'ann', 0), array(8, 100, 'cat', 1)), array(array(6, 200, 'dan', 1)));
check_same(array('key' => 'ann', 'submission' => 7, 'offset' => 100, 'pos' => 0, 'sure' => true), $firsts['contest'], 'the first of all, sure');
check_same(array('key' => 'cat', 'submission' => 8, 'offset' => 100, 'pos' => 1, 'sure' => true), $firsts['problems'][1], 'the first of a problem, sure, though something after it waits');
$firsts = contestFirstSolves(array(array(9, 300, 'bob', 1)), array(array(6, 200, 'dan', 0)));
check_same(false, $firsts['contest']['sure'], 'the first of all is not sure while anything submitted before waits');
check_same(true, $firsts['problems'][1]['sure'], 'the first of a problem is, where it waits for another problem');
check_same(array('problems' => array(), 'contest' => null), contestFirstSolves(array(), array(array(1, 1, 'ann', 0))), 'nobody solved anything');
// What the first submitted before to the same problem leaves the first theirs: A submitted at
// 5 seconds, that waits, and passed at 10. Before, to another problem, it still may not be the
// first of all; what somebody else submitted before still keeps it from being sure.
$firsts = contestFirstSolves(array(array(11, 10, 'A', 0), array(12, 20, 'B', 0)), array(array(10, 5, 'A', 0)));
check_same(array('A', true, 'A', true), array($firsts['problems'][0]['key'], $firsts['problems'][0]['sure'], $firsts['contest']['key'], $firsts['contest']['sure']),
	'the same contestant, the same problem: sure');
$firsts = contestFirstSolves(array(array(11, 10, 'A', 0), array(12, 20, 'B', 0)), array(array(10, 5, 'A', 1)));
check_same(array('A', true, 'A', false), array($firsts['problems'][0]['key'], $firsts['problems'][0]['sure'], $firsts['contest']['key'], $firsts['contest']['sure']),
	'the same contestant, another problem: the first of the problem is sure, the first of all is not');
$firsts = contestFirstSolves(array(array(11, 10, 'A', 0), array(12, 20, 'B', 0)), array(array(10, 5, 'C', 0)));
check_same(array('A', false, 'A', false), array($firsts['problems'][0]['key'], $firsts['problems'][0]['sure'], $firsts['contest']['key'], $firsts['contest']['sure']),
	'somebody else before: not sure');
// numbers that are usernames stay who they are
$firsts = contestFirstSolves(array(array(11, 10, '2026001', 0)), array(array(10, 5, '2026001', 0)));
check_same(true, $firsts['problems'][0]['sure'], 'a username that is a number is the same contestant');
check_same(array(array(11, 3000, 'ann', 0), array(13, 1800, 'ann', 2), array(41, 600, 'dan', 0)),
	array_values(array_filter(contestPassedOfScore('ICPC', $score), function($row) {
		return $row[2] === 'ann' && $row[3] != 1 || $row[2] === 'dan';
	})), 'what passed, with the time it passed at, not the penalty, and nothing that did not');

// of what a row solved, how much was submitted for nothing: ann solved three with five
// submissions, two of them in vain; what she failed and did not solve is not counted
check_same(40, $board['rows'][0]['dirt'], 'dirt is counted in what was solved');
check_same(array(
	array('solved' => 4, 'tries' => 4), array('solved' => 3, 'tries' => 3), array('solved' => 1, 'tries' => 6),
	array('solved' => 0, 'tries' => 4), array('solved' => 0, 'tries' => 0)
), $board['problems'], 'over a problem: how many solved it, out of how many submissions that count');
check_same(array('ann', 1600, '安'), $board['rows'][0]['person'], 'a row says who it is of');
// the percentage is cut, not rounded: two in vain out of three is 66
$once = contestOuterBoard('ICPC', 1, array(array(100, 3000, array('ann', 1500, ''), 1)), array('ann' => array(0 => array(100, 3000, 1, 2, 0))));
check_same(66, $once['rows'][0]['dirt'], 'dirt is cut to whole percents');
check_same('+2', $once['rows'][0]['cells'][0]['says'], 'a problem solved at the third attempt');
// what was tried in vain says when, where that is known, and nothing where it is not
$unknown = contestOuterBoard('ICPC', 1, array(array(0, 0, array('ann', 1500, ''), 1)), array('ann' => array(0 => array(0, 0, 1, 2, 0))));
check_same(array('kind' => 'failed', 'says' => '-2', 'under' => ''), $unknown['rows'][0]['cells'][0], 'an attempt in vain whose time is not known');
check_same(array('problems' => array(), 'rows' => array()), contestOuterBoard('ICPC', 0, array(), array()), 'a contest without problems and without contestants');
// somebody who sat the contest virtually is a row like any other
$starred = contestOuterBoard('ICPC', 1, array(array(100, 60, array('*ann', 1500, ''), 1)), array('*ann' => array(0 => array(100, 60, 1, 0, 0))));
check_same(array('*ann', 'first'), array($starred['rows'][0]['key'], $starred['rows'][0]['cells'][0]['kind']), 'a row under a star');

// ---- the other rules: a score, and when it was got
$oi = contestOuterBoard('OI', 3, array(
	array(200, 5000, array('ann', 1500, ''), 1), array(160, 4000, array('bob', 1500, ''), 2), array(160, 4100, array('cat', 1500, ''), 3), array(0, 0, array('dan', 1500, ''), 4)
), array(
	'ann' => array(0 => array(100, 1200, 1), 1 => array(100, 3800, 2)),
	'bob' => array(0 => array(100, 600, 3), 1 => array(60, 3400, 4)),
	'cat' => array(0 => array(100, 700, 5), 1 => array(60, 3400, 6), 2 => array(0, 0, 7)),
	'dan' => array(2 => array(0, 0, 8))
), array('cat' => array(2 => 4000)));
check_same(array(
	'1 ann 200/5000 dirt=NULL band=0 solved:100@0:20 first:100@1:03 .',
	'2 bob 160/4000 dirt=NULL band=1 first:100@0:10 part:60@0:56 .',
	'3 cat 160/4100 dirt=NULL band=1 solved:100@0:11 part:60@0:56 failed:0@1:06',
	'4 dan 0/0 dirt=NULL band=0 . . failed:0'
), array_map($line, $oi['rows']), 'the rows of a board of scores: the bands are by the score, and nothing is said of dirt');
check_same(array(array('solved' => 3, 'tries' => 3), array('solved' => 1, 'tries' => 3), array('solved' => 0, 'tries' => 2)), $oi['problems'],
	'over a problem of a board of scores: how many got all of it, out of how many tried');

// ---- ratings: who sits a contest alone is rated against nobody, and keeps the rating (PHP 8
// does not divide by the number of the others when it is zero)
check_same(array(1500), calcRating(array(array(100, 0, array('ann', 1500, ''), 1))), 'a contest of one changes no rating');
check_same(array(1700.0, 1300.0), calcRating(array(array(100, 0, array('ann', 1500, ''), 1), array(0, 0, array('bob', 1500, ''), 2))), 'the first of two gains what the second loses');
