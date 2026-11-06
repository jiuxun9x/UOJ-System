<?php

// cli.php site:tick: every step is done whatever became of the ones before it, and what went
// wrong in one is told.

require_once __DIR__ . '/../app/libs/uoj-monitor-lib.php';

$done = array();
$failed = siteTickRunSteps(array(
	'first' => function () use (&$done) {
		$done[] = 'first';
	},
	'throws' => function () {
		throw new Exception('the disk is full');
	},
	'mistyped' => function () {
		// what PHP 8 throws on a mistake, which is no Exception
		return strlen(array());
	},
	'last' => function () use (&$done) {
		$done[] = 'last';
	}
));
check_same(array('first', 'last'), $done, 'the steps after one that failed are done');
check_same(array('throws', 'mistyped'), array_keys($failed), 'the steps that failed are told');
check_same(1, preg_match('/^Exception: the disk is full \(tick_test\.php:\d+\)$/', $failed['throws']), 'what went wrong, and where');
check_same(0, strpos($failed['mistyped'], 'TypeError: '), 'an error of PHP is caught as well');
check_same(array(), siteTickRunSteps(array('fine' => function () {})), 'nothing failed');
