<?php

// Watching over the site: whether the judgers answer and the submissions get judged, and
// telling the administrators when they do not. cli.php site:tick looks every minute.

// ---- what there is to see

// the judgers as they are now: name, enabled, silent_seconds (null: never connected),
// version, judging (what they are judging), judged_last_hour
function monitorJudgers() {
	$judgers = array();
	foreach (DB::selectAll("select judger_name, enabled, version, last_heartbeat_at, timestampdiff(second, last_heartbeat_at, now()) as silent_seconds from judger_info order by judger_name") as $row) {
		$esc_name = DB::escape($row['judger_name']);
		$judging = array();
		foreach (DB::selectAll("select kind, target_id from submission_judgements where judger_name = '$esc_name' and finished_at is null and started_at > now() - interval 1 day order by id desc limit 3") as $judgement) {
			$judging[] = $judgement['kind'] . ' #' . $judgement['target_id'];
		}
		$judgers[] = array(
			'name' => $row['judger_name'],
			'enabled' => (bool)$row['enabled'],
			'version' => $row['version'],
			'last_heartbeat_at' => $row['last_heartbeat_at'],
			'silent_seconds' => $row['silent_seconds'] === null ? null : (int)$row['silent_seconds'],
			'judging' => $judging,
			'judged_last_hour' => (int)DB::selectCount("select count(*) from submission_judgements where judger_name = '$esc_name' and finished_at > now() - interval 1 hour")
		);
	}
	return $judgers;
}
// What waits to be judged: how many submissions, and how long the oldest new submission has
// waited. Submissions that are judged again wait behind the new ones and are not timed.
function monitorQueue() {
	$waiting = (int)DB::selectCount("select count(*) from submissions where status in ('Waiting', 'Waiting Rejudge', 'Judged, Waiting')");
	$oldest = DB::selectFirst("select min(submit_time) from submissions where status = 'Waiting'", MYSQLI_NUM);
	return array(
		'waiting' => $waiting,
		'oldest_wait_seconds' => $oldest && $oldest[0] !== null ? max(0, UOJTime::$time_now->getTimestamp() - strtotime($oldest[0])) : null
	);
}

function monitorDuration($seconds) {
	if ($seconds < 120) {
		return $seconds . ' 秒';
	}
	if ($seconds < 7200) {
		return floor($seconds / 60) . ' 分钟';
	}
	if ($seconds < 172800) {
		return floor($seconds / 3600) . ' 小时';
	}
	return floor($seconds / 86400) . ' 天';
}

// ---- what is wrong

// What is wrong with the judgers and the queue, as rows of kind, subject and message.
// $silent_after: seconds without a sign of life after which a judger counts as gone.
// $wait_after: seconds a new submission may wait.
function monitorFindProblems($judgers, $queue, $silent_after, $wait_after) {
	$problems = array();
	$online = 0;
	$silent = array();
	foreach ($judgers as $judger) {
		if (!$judger['enabled']) {
			continue;
		}
		if ($judger['silent_seconds'] !== null && $judger['silent_seconds'] <= $silent_after) {
			$online++;
		} elseif ($judger['silent_seconds'] !== null) {
			// a judger that never connected is one that is still being set up
			$silent[] = $judger;
		}
	}
	if ($online == 0) {
		// one alert for all of them, not one for each
		$problems[] = array('kind' => 'no_judger', 'subject' => '', 'message' => '没有在线的评测机，提交无法评测');
	} else {
		foreach ($silent as $judger) {
			$problems[] = array('kind' => 'judger_silent', 'subject' => $judger['name'], 'message' => "评测机 {$judger['name']} 没有响应");
		}
	}
	if ($queue['oldest_wait_seconds'] !== null && $queue['oldest_wait_seconds'] > $wait_after) {
		$problems[] = array('kind' => 'queue_stuck', 'subject' => '', 'message' => '提交等待评测的时间过长，评测机可能不够用或者卡住了');
	}
	return $problems;
}

// ---- alerts

function alertKindName($kind) {
	$names = array(
		'no_judger' => '评测机全部离线',
		'judger_silent' => '评测机离线',
		'queue_stuck' => '评测积压',
		'backup_failed' => '备份失败',
		'backup_overdue' => '备份过期'
	);
	return isset($names[$kind]) ? $names[$kind] : $kind;
}
function openAlerts() {
	return DB::selectAll("select * from site_alerts where active_slot = 1 order by started_at, id");
}
function recentAlerts($limit = 50) {
	return DB::selectAll("select * from site_alerts order by id desc limit ".(int)$limit);
}

// Makes the open alerts of some kinds what they should be: opens the ones for what is wrong
// and was not, closes the ones for what was wrong and is not any more. Returns
// array('opened' => alerts, 'resolved' => alerts).
function alertsSync($kinds, $problems) {
	$changes = array('opened' => array(), 'resolved' => array());
	$wrong = array();
	foreach ($problems as $problem) {
		$wrong[$problem['kind'] . "\n" . $problem['subject']] = true;
		$esc_kind = DB::escape($problem['kind']);
		$esc_subject = DB::escape($problem['subject']);
		// the unique key makes this the one open alert of the thing, whoever else looks
		DB::insert("insert ignore into site_alerts (kind, subject, message, started_at, active_slot) values ('$esc_kind', '$esc_subject', '".DB::escape($problem['message'])."', now(), 1)");
		if (DB::affected_rows() == 1) {
			$changes['opened'][] = DB::selectFirst("select * from site_alerts where kind = '$esc_kind' and subject = '$esc_subject' and active_slot = 1", MYSQLI_ASSOC);
		}
	}
	foreach (openAlerts() as $alert) {
		if (in_array($alert['kind'], $kinds, true) && !isset($wrong[$alert['kind'] . "\n" . $alert['subject']])) {
			DB::update("update site_alerts set resolved_at = now(), active_slot = null where id = {$alert['id']} and active_slot = 1");
			if (DB::affected_rows() == 1) {
				$changes['resolved'][] = $alert;
			}
		}
	}
	return $changes;
}

// who is told about alerts by mail: the addresses the administrators wrote down, or else
// the system administrators themselves
function alertRecipients() {
	$addresses = array();
	foreach (preg_split('/[\s,;，；]+/u', siteSetting('alert.recipients')) as $address) {
		if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
			$addresses[] = $address;
		}
	}
	if (!$addresses) {
		foreach (DB::selectAll("select email from user_info where usergroup = 'S'") as $row) {
			if (filter_var($row['email'], FILTER_VALIDATE_EMAIL) !== false) {
				$addresses[] = $row['email'];
			}
		}
	}
	return array_values(array_unique($addresses));
}

// Tells the system administrators that something went wrong, or that it is well again: with a
// message on the site, and by mail if they asked for that. Failing to tell stops nothing.
function alertNotify($alert, $resolved) {
	$title = ($resolved ? '已恢复：' : '告警：') . alertKindName($alert['kind']);
	$text = HTML::escape($alert['message']) . ($resolved ? "（开始于 {$alert['started_at']}，现已恢复）" : '');
	foreach (DB::selectAll("select username from user_info where usergroup = 'S'") as $row) {
		sendSystemMsg($row['username'], $title, "$text <a href=\"/super-manage/monitor\">查看运行状态</a>");
	}
	if (siteSettingIsOn('alert.email') && UOJMail::configured()) {
		$oj_name = HTML::escape(UOJConfig::$data['profile']['oj-name']);
		// A mail needs the whole address of the site. This runs from the command line, where
		// there is no request to take it from: it is known only if the configuration names it.
		$host = UOJConfig::$data['web']['main']['host'];
		$link = '';
		if (is_string($host) && preg_match('/^[A-Za-z0-9]/', $host)) {
			$url = HTML::url('/super-manage/monitor');
			$link = "<p><a href=\"$url\">$url</a></p>";
		}
		$err = UOJMail::send(alertRecipients(), '[' . UOJConfig::$data['profile']['oj-name-short'] . '] ' . $title, "<p>$text</p>$link<p>$oj_name</p>");
		if ($err === '') {
			DB::update("update site_alerts set mailed_at = now() where id = {$alert['id']}");
		} else {
			error_log("alert #{$alert['id']}: the mail was not sent: $err");
		}
	}
}

// What cli.php site:tick does every minute: looks at the judgers and the queue, and tells
// the administrators about what changed. Returns how many alerts were opened and resolved.
function monitorTick() {
	$problems = monitorFindProblems(monitorJudgers(), monitorQueue(), siteSetting('alert.judger_silent_seconds'), siteSetting('alert.queue_wait_seconds'));
	$changes = alertsSync(array('no_judger', 'judger_silent', 'queue_stuck'), $problems);
	foreach ($changes['opened'] as $alert) {
		alertNotify($alert, false);
	}
	foreach ($changes['resolved'] as $alert) {
		alertNotify($alert, true);
	}
	return array(count($changes['opened']), count($changes['resolved']));
}
