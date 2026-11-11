<?php
	requirePHPLib('judger');

	requireJudgerAuthentication();

	// The main judger tells the others to update, once it updated itself (judge_client does so
	// only as main_judger), and only while its account is enabled: the account of any judger,
	// one that was disabled among them, made every judger download, build and restart, as often
	// as it liked.
	$caller = $GLOBALS['uojJudger'];
	if ($caller['judger_name'] !== 'main_judger' || !$caller['enabled']) {
		header($_SERVER['SERVER_PROTOCOL'] . " 403 Forbidden", true, 403);
		die("only main_judger tells the other judgers to update");
	}

	// A judger that can not be reached is said, and the ones after it are told all the same.
	$lost = array();
	foreach (DB::selectAll("select * from judger_info where ip != ''") as $judger) {
		$socket = @fsockopen($judger['ip'], UOJConfig::$data['judger']['socket']['port'], $errno, $errstr, 5);
		if ($socket === false) {
			$lost[] = $judger['ip'];
			continue;
		}
		fwrite($socket, json_encode([
			'password' => UOJConfig::$data['judger']['socket']['password'],
			'cmd' => 'update'
		]));
		fclose($socket);
	}

	die($lost ? "judge clients lost: " . join(', ', $lost) : "ok");
?>
