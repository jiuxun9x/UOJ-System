<?php
	// What the field that picks problems asks: the problems that answer what was typed, or
	// the problems with given numbers. Answers in JSON, and says nothing of a place the
	// user may not look in.
	requirePHPLib('problem');

	header('Content-Type: application/json; charset=utf-8');
	$scope = isset($_GET['scope']) && is_string($_GET['scope']) ? $_GET['scope'] : 'site';
	$purpose = isset($_GET['purpose']) && $_GET['purpose'] === 'manage' ? 'manage' : 'read';
	if (isset($_GET['numbers']) && is_string($_GET['numbers'])) {
		$problems = problemPickByNumbers($myUser, $scope, $purpose, preg_split('/[^0-9]+/', $_GET['numbers'], -1, PREG_SPLIT_NO_EMPTY));
	} else {
		$problems = problemPick($myUser, $scope, $purpose, isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '');
	}
	die(json_encode(array('problems' => $problems === null ? array() : $problems), JSON_UNESCAPED_UNICODE));
