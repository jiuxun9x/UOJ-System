<?php

// Backups of the site by the site: the database, the data of the problems and what was
// submitted, as a directory a day under backupRoot(). Files that did not change since the
// backup before are hard links to it, so a backup costs the space of what changed.
//
//   uoj-20261004-030000/
//     db.sql.gz       the whole database
//     data/           /var/uoj_data
//     storage/        app/storage without its temporary files
//     manifest.json   what is in it: the rows of every table, the files and their size
//     .complete       written last: a backup without it was interrupted
//
// cli.php backup:run makes one, backup:verify tries one out, backup:restore puts one back.

function backupRoot() {
	return isset(UOJConfig::$data['backup']['path']) ? UOJConfig::$data['backup']['path'] : '/var/uoj_backup';
}
// what is copied as files: name in the backup => array(where it is, what of it is left out)
function backupSources() {
	return array(
		'data' => array('/var/uoj_data', array()),
		'storage' => array(UOJContext::storagePath(), array('/tmp/'))
	);
}
function validateBackupName($name) {
	return is_string($name) && preg_match('/^uoj-[0-9]{8}-[0-9]{6}$/D', $name);
}

// ---- deciding without doing

// Which of the backups are thrown away: $names are the complete ones, $now a timestamp.
// Backups older than $keep_days days go, but never the newest one.
function backupNamesToPrune($names, $keep_days, $now) {
	sort($names);
	$prune = array();
	$newest = end($names);
	foreach ($names as $name) {
		$time = backupTimeOf($name);
		if ($name !== $newest && $time !== null && $time < $now - $keep_days * 86400) {
			$prune[] = $name;
		}
	}
	return $prune;
}
// the moment a backup was started, from its name; null for a name that is none
function backupTimeOf($name) {
	if (!validateBackupName($name)) {
		return null;
	}
	$time = DateTime::createFromFormat('Ymd-His', substr($name, 4));
	return $time ? $time->getTimestamp() : null;
}
// Whether it is time for the backup of the day: at the hour that was set, or later the same
// day when the site was down at that hour, once a day.
function backupIsDue($enabled, $hour, $now, $last_started) {
	if (!$enabled || (int)date('G', $now) < $hour) {
		return false;
	}
	return $last_started === null || date('Y-m-d', $last_started) !== date('Y-m-d', $now);
}
function backupSize($bytes) {
	if ($bytes === null) {
		return '—';
	}
	foreach (array('B', 'KB', 'MB', 'GB') as $unit) {
		if ($bytes < 1024 || $unit === 'GB') {
			return ($unit === 'B' ? $bytes : number_format($bytes, 1)) . ' ' . $unit;
		}
		$bytes /= 1024;
	}
}

// ---- what there is

function backupRuns($limit = 20) {
	return DB::selectAll("select * from backup_runs order by id desc limit ".(int)$limit);
}
// the complete backups that are on the disk, the oldest first
function backupNames() {
	$names = array();
	$root = backupRoot();
	if (is_dir($root)) {
		foreach (scandir($root) as $name) {
			if (validateBackupName($name) && is_file("$root/$name/.complete")) {
				$names[] = $name;
			}
		}
	}
	sort($names);
	return $names;
}
function backupManifest($name) {
	$path = backupRoot() . "/$name/manifest.json";
	return validateBackupName($name) && is_file($path) ? json_decode(file_get_contents($path), true) : null;
}

// ---- running the tools

// Runs a command of bash, returns array(exit code, what it printed, the end of it).
function backupExec($command) {
	exec("bash -c " . escapeshellarg("set -o pipefail; $command") . " 2>&1", $output, $code);
	return array($code, substr(join("\n", $output), -400));
}
// A file that tells mysql and mysqldump how to reach the database, so that the password is
// not on a command line for everybody on the machine to read. The caller removes it.
function backupClientFile() {
	$conf = UOJConfig::$data['database'];
	list($host, $port, $socket) = DB::connectParams($conf);
	$path = tempnam(sys_get_temp_dir(), 'uoj_my_');
	chmod($path, 0600);
	$quote = function($value) {
		return '"' . addcslashes($value, "\\\"") . '"';
	};
	$lines = array('[client]', 'user=' . $quote($conf['username']), 'password=' . $quote($conf['password']), 'default-character-set=utf8mb4');
	if ($socket !== null && $socket !== '') {
		$lines[] = 'socket=' . $quote($socket);
	} else {
		// mysqli wants an IPv6 address in brackets, the tools of mysql want it without
		$lines[] = 'host=' . $quote(trim($host, '[]'));
		$lines[] = 'port=' . (int)$port;
	}
	file_put_contents($path, join("\n", $lines) . "\n");
	return $path;
}
function backupTableCounts($database = null) {
	$from = $database === null ? '' : ' from `' . str_replace('`', '', $database) . '`';
	$prefix = $database === null ? '' : '`' . str_replace('`', '', $database) . '`.';
	$counts = array();
	foreach (DB::selectAll("show tables$from", MYSQLI_NUM) as $row) {
		$counts[$row[0]] = (int)DB::selectCount("select count(*) from $prefix`{$row[0]}`");
	}
	ksort($counts);
	return $counts;
}
// the files under a directory: how many, and how many bytes
function backupTreeSize($dir) {
	$count = 0;
	$bytes = 0;
	if (is_dir($dir)) {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->isFile() && !$file->isLink()) {
				$count++;
				$bytes += $file->getSize();
			}
		}
	}
	return array($count, $bytes);
}

// ---- making a backup

// Makes a backup. Only one runs at a time. Returns array(the name of the backup, '') or
// array(null, why there is none).
function backupRun($reason = 'manual') {
	$lock = DB::selectFirst("select get_lock('uoj_backup', 0)", MYSQLI_NUM);
	if (!$lock || $lock[0] != 1) {
		return array(null, '已经有一个备份在进行');
	}
	try {
		// whatever still says that it runs does not: the lock is ours
		DB::update("update backup_runs set status = 'failed', finished_at = now(), message = '备份被中断' where status = 'running'");
		$name = 'uoj-' . date('Ymd-His');
		DB::insert("insert into backup_runs (name, reason, status, started_at) values ('$name', '".DB::escape($reason)."', 'running', now())");
		$run_id = (int)DB::insert_id();
		list($summary, $err) = backupMake($name);
		if ($err !== '') {
			backupExec("rm -rf " . escapeshellarg(backupRoot() . "/$name"));
			DB::update("update backup_runs set status = 'failed', finished_at = now(), message = '".DB::escape(mb_substr($err, 0, 480, 'UTF-8'))."' where id = $run_id");
			auditLog('backup.fail', 'backup', $name, null, array('reason' => $reason, 'error' => $err), false);
			return array(null, $err);
		}
		DB::update("update backup_runs set status = 'ok', finished_at = now(), db_bytes = {$summary['db_bytes']}, files_count = {$summary['files_count']}, files_bytes = {$summary['files_bytes']} where id = $run_id");
		$pruned = backupPrune();
		auditLog('backup.run', 'backup', $name, null, array('reason' => $reason, 'pruned' => $pruned) + $summary, false);
		return array($name, '');
	} finally {
		DB::query("select release_lock('uoj_backup')");
	}
}
// The work of backupRun(): returns array(what the backup is, '') or array(null, why it failed).
function backupMake($name) {
	$root = backupRoot();
	$dir = "$root/$name";
	if (!is_dir($root) || !is_writable($root)) {
		return array(null, "备份目录 $root 不存在或不可写");
	}
	$names = backupNames();
	$previous = $names ? end($names) : null;
	if (!mkdir($dir, 0700)) {
		return array(null, "无法创建 $dir");
	}

	// The database first, with every table held still for as long as it takes: most tables
	// are MyISAM, which knows no transactions. The rows are counted under the same lock, so
	// that the counts are the ones of the dump.
	$client_file = backupClientFile();
	$locked = DB::query("flush tables with read lock") !== false;
	list($code, $output) = backupExec("mysqldump --defaults-extra-file=" . escapeshellarg($client_file) . " --loose-column-statistics=0 --hex-blob " . ($locked ? '--skip-lock-tables' : '--lock-tables') . " " . escapeshellarg(UOJConfig::$data['database']['database']) . " | gzip > " . escapeshellarg("$dir/db.sql.gz"));
	$tables = $code == 0 ? backupTableCounts() : array();
	if ($locked) {
		DB::query("unlock tables");
	}
	unlink($client_file);
	if ($code != 0) {
		return array(null, "导出数据库失败：$output");
	}

	$files_count = 0;
	$files_bytes = 0;
	$sources = array();
	foreach (backupSources() as $key => $source) {
		list($path, $excluded) = $source;
		$options = '-a --delete';
		foreach ($excluded as $pattern) {
			$options .= ' --exclude=' . escapeshellarg($pattern);
		}
		if ($previous !== null && is_dir("$root/$previous/$key")) {
			$options .= ' --link-dest=' . escapeshellarg("$root/$previous/$key");
		}
		list($code, $output) = backupExec("rsync $options " . escapeshellarg("$path/") . " " . escapeshellarg("$dir/$key/"));
		// 24: files vanished while they were copied, which a site that runs has every right to
		if ($code != 0 && $code != 24) {
			return array(null, "复制 $path 失败：$output");
		}
		list($count, $bytes) = backupTreeSize("$dir/$key");
		$sources[$key] = array('files' => $count, 'bytes' => $bytes);
		$files_count += $count;
		$files_bytes += $bytes;
	}

	$summary = array('db_bytes' => (int)filesize("$dir/db.sql.gz"), 'files_count' => $files_count, 'files_bytes' => $files_bytes);
	$manifest = array(
		'name' => $name,
		'made_at' => date('Y-m-d H:i:s'),
		'database' => UOJConfig::$data['database']['database'],
		'tables' => $tables,
		'tables_exact' => $locked,
		'sources' => $sources
	) + $summary;
	if (file_put_contents("$dir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false || !touch("$dir/.complete")) {
		return array(null, "无法写入 $dir");
	}
	return array($summary, '');
}
// Throws away the backups that are older than the administrators want to keep, and what is
// left of backups that were interrupted. Returns the names of the ones that went.
function backupPrune() {
	$root = backupRoot();
	$pruned = backupNamesToPrune(backupNames(), siteSetting('backup.keep_days'), time());
	foreach (scandir($root) as $name) {
		// an interrupted backup that is not the one being made
		if (validateBackupName($name) && !is_file("$root/$name/.complete") && backupTimeOf($name) < time() - 86400) {
			$pruned[] = $name;
		}
	}
	foreach ($pruned as $name) {
		backupExec("rm -rf " . escapeshellarg("$root/$name"));
	}
	return $pruned;
}

// ---- trying a backup out, and putting one back

// The rehearsal of a restore: loads the dump of a backup into a database of its own and
// compares what arrives with what the backup says it holds, then counts its files.
// Returns '' when the backup can be restored, or what is wrong with it.
function backupVerify($name) {
	$manifest = backupManifest($name);
	if ($manifest === null || !is_file(backupRoot() . "/$name/.complete")) {
		return '没有这个备份，或者它不完整';
	}
	$dir = backupRoot() . "/$name";
	$scratch = UOJConfig::$data['database']['database'] . '_verify';
	$client_file = backupClientFile();
	DB::query("drop database if exists `$scratch`");
	if (DB::query("create database `$scratch` default character set utf8mb4") === false) {
		unlink($client_file);
		return "无法创建用于演练的数据库 $scratch";
	}
	list($code, $output) = backupExec("gunzip -c " . escapeshellarg("$dir/db.sql.gz") . " | mysql --defaults-extra-file=" . escapeshellarg($client_file) . " " . escapeshellarg($scratch));
	unlink($client_file);
	$err = '';
	if ($code != 0) {
		$err = "导入数据库失败：$output";
	} else {
		$restored = backupTableCounts($scratch);
		if (array_keys($restored) !== array_keys($manifest['tables'])) {
			$err = '恢复出的表和备份记录的不一致';
		} elseif ($manifest['tables_exact'] && $restored !== $manifest['tables']) {
			$different = array();
			foreach ($restored as $table => $count) {
				if ($count !== $manifest['tables'][$table]) {
					$different[] = "{$table}（恢复出 {$count} 行，应为 {$manifest['tables'][$table]} 行）";
				}
			}
			$err = '恢复出的行数和备份记录的不一致：' . join('、', array_slice($different, 0, 5));
		}
	}
	DB::query("drop database if exists `$scratch`");
	if ($err === '') {
		foreach ($manifest['sources'] as $key => $expected) {
			list($count, $bytes) = backupTreeSize("$dir/$key");
			if ($count != $expected['files'] || $bytes != $expected['bytes']) {
				$err = "$key 里的文件和备份记录的不一致（现有 $count 个，应为 {$expected['files']} 个）";
				break;
			}
		}
	}
	if ($err === '') {
		DB::update("update backup_runs set verified_at = now() where name = '$name'");
	}
	auditLog('backup.verify', 'backup', $name, null, array('ok' => $err === '', 'error' => $err), false);
	return $err;
}

// Puts a backup back: the database becomes the one of the backup, and so do the files.
// Everything that happened since is lost. Returns '' or why it could not be done. The site
// should not be in use meanwhile, and its database is brought up to date afterwards with
// cli.php upgrade:latest.
function backupRestore($name) {
	$manifest = backupManifest($name);
	if ($manifest === null || !is_file(backupRoot() . "/$name/.complete")) {
		return '没有这个备份，或者它不完整';
	}
	$dir = backupRoot() . "/$name";
	$database = UOJConfig::$data['database']['database'];
	// The tables that were made after the backup are not in it. They go too: the upgrades
	// that made them are not recorded in the database that comes back.
	DB::query("set foreign_key_checks = 0");
	foreach (DB::selectAll("show tables", MYSQLI_NUM) as $row) {
		DB::query("drop table if exists `{$row[0]}`");
	}
	$client_file = backupClientFile();
	list($code, $output) = backupExec("gunzip -c " . escapeshellarg("$dir/db.sql.gz") . " | mysql --defaults-extra-file=" . escapeshellarg($client_file) . " " . escapeshellarg($database));
	unlink($client_file);
	if ($code != 0) {
		return "导入数据库失败：$output";
	}
	foreach (backupSources() as $key => $source) {
		list($path, $excluded) = $source;
		$options = '-a --delete';
		foreach ($excluded as $pattern) {
			$options .= ' --exclude=' . escapeshellarg($pattern);
		}
		list($code, $output) = backupExec("rsync $options " . escapeshellarg("$dir/$key/") . " " . escapeshellarg("$path/"));
		if ($code != 0) {
			return "恢复 $path 失败：$output";
		}
	}
	// The backup was being made when the database was dumped, and that is what the database
	// that came back says about it. It was finished, or it would not be here.
	DB::update("update backup_runs set status = 'ok', finished_at = started_at, db_bytes = ".(int)$manifest['db_bytes'].", files_count = ".(int)$manifest['files_count'].", files_bytes = ".(int)$manifest['files_bytes']." where name = '$name' and status = 'running'");
	auditLog('backup.restore', 'backup', $name, null, array('made_at' => $manifest['made_at']), false);
	return '';
}

// ---- what the minute brings

// Starts the backup of the day when it is time, as a process of its own: it may take long,
// and the rest of what the site does every minute does not wait for it.
function backupTick() {
	$free = DB::selectFirst("select is_free_lock('uoj_backup')", MYSQLI_NUM);
	if (!$free || $free[0] != 1) {
		return false;
	}
	// an administrator asked for one
	if (DB::selectFirst("select 1 from backup_runs where status = 'requested' limit 1")) {
		DB::delete("delete from backup_runs where status = 'requested'");
		backupStartInBackground('manual');
		return true;
	}
	$last = DB::selectFirst("select unix_timestamp(max(started_at)) from backup_runs where reason = 'scheduled'", MYSQLI_NUM);
	if (!backupIsDue(siteSettingIsOn('backup.enabled'), siteSetting('backup.hour'), time(), $last && $last[0] !== null ? (int)$last[0] : null)) {
		return false;
	}
	backupStartInBackground('scheduled');
	return true;
}
// what an administrator does on a page to have a backup made within a minute
function backupRequest($actor) {
	if (DB::selectFirst("select 1 from backup_runs where status in ('requested', 'running') limit 1")) {
		return '已经有一个备份在进行或等待开始';
	}
	DB::insert("insert into backup_runs (name, reason, status, started_at) values ('', 'manual', 'requested', now())");
	auditLog('backup.request', 'backup', '', null, null, $actor);
	return '';
}
// the alerts about backups as they should be now
function backupCurrentProblems() {
	$newest = DB::selectFirst("select * from backup_runs where status in ('ok', 'failed') order by id desc limit 1");
	$last_ok = DB::selectFirst("select unix_timestamp(max(started_at)) from backup_runs where status = 'ok'", MYSQLI_NUM);
	$since = DB::selectFirst("select unix_timestamp(min(register_time)) from user_info", MYSQLI_NUM);
	return backupFindProblems(siteSettingIsOn('backup.enabled'), $newest, $last_ok && $last_ok[0] !== null ? (int)$last_ok[0] : null, $since && $since[0] !== null ? (int)$since[0] : null, time());
}
function backupStartInBackground($reason) {
	$cli = escapeshellarg(UOJContext::documentRoot() . '/app/cli.php');
	exec("nohup php $cli backup:run " . escapeshellarg($reason) . " > /dev/null 2>&1 &");
}

// What is wrong with the backups, for the alerts: the newest one failed, or there has been
// none for too long although they are switched on.
function backupFindProblems($enabled, $newest_run, $last_ok_at, $site_since, $now) {
	$problems = array();
	if ($newest_run && $newest_run['status'] === 'failed') {
		$problems[] = array('kind' => 'backup_failed', 'subject' => '', 'message' => '最近一次备份失败：' . $newest_run['message']);
	}
	// a day and a half without one, on a site that is older than that
	if ($enabled && $site_since !== null && $site_since < $now - 129600 && ($last_ok_at === null || $last_ok_at < $now - 129600)) {
		$problems[] = array('kind' => 'backup_overdue', 'subject' => '', 'message' => $last_ok_at === null ? '还没有成功备份过' : '已经超过一天半没有成功备份');
	}
	return $problems;
}
