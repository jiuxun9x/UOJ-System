<?php

class Upgrader {
	public static function upgraderRoot() {
		return UOJContext::documentRoot().'/app/upgrade';
	}
		
	// An upgrade that fails must stop whatever started it, so exit with a non-zero status.
	public static function fail($msg) {
		fwrite(STDERR, $msg);
		exit(1);
	}
	
	// The script runs on the connection of the web server, so it works with whatever host,
	// port or socket is configured. It can not use the DELIMITER command of the mysql client.
	public static function runSQL($filename) {
		$sql = file_get_contents($filename);
		if ($sql === false) {
			self::fail("run sql failed: can not read $filename\n");
		}
		$err = DB::multiQuery($sql);
		if ($err !== null) {
			self::fail("run sql failed: $filename\n$err\n");
		}
	}
	public static function runShell($cmd) {
		passthru("$cmd", $ret);
		if ($ret !== 0) {
			self::fail("run shell failed: $cmd\n");
		}
	}
	
	public static function transaction($fun) {
		// a named lock, unlike LOCK TABLES, lets an upgrade use every table
		$lock = DB::selectFirst("select get_lock('uoj_upgrade', 60)", MYSQLI_NUM);
		if (!$lock || $lock[0] != 1) {
			self::fail("another upgrade is running\n");
		}
		if (!DB::checkTableExists('upgrades')) {
			self::runSQL(self::upgraderRoot().'/create_table_upgrades.sql');
			echo "table upgrades created.\n";
		}
		
		$fun();
		
		DB::query("select release_lock('uoj_upgrade')");
	}
	
	public static function getStatus($name) {
		$u = DB::selectFirst("select * from upgrades where name = '".DB::escape($name)."'");
		if ($u) {
			return $u['status'];
		} else {
			return 'down';
		}
	}
	
	public static function upgrade($name, $type) {
		if ($type != 'up' && $type != 'down') {
			self::fail("invalid upgrade type\n");
		}
		
		echo $type.' '.HTML::escape($name).': ';
		
		$dir = self::upgraderRoot().'/'.$name;
		
		if (!is_dir($dir)) {
			self::fail("invalid upgrade name\n");
		}
		
		if (self::getStatus($name) == $type) {
			echo "OK\n";
			return;
		}
		
		if (is_file($dir.'/upgrade.php')) {
			$fun = include $dir.'/upgrade.php';
			$fun($type);
		}
		if (is_file($dir.'/'.$type.'.sql')) {
			self::runSQL($dir.'/'.$type.'.sql');
		}
		if (is_file($dir.'/upgrade.sh')) {
			self::runShell('/bin/bash'.' '.escapeshellarg($dir.'/upgrade.sh').' '.$type);
		}
		
		if (!DB::insert("insert into upgrades (name, status, updated_at) values ('".DB::escape($name)."', '$type', now()) on duplicate key update status = '$type', updated_at = now()")) {
			self::fail("failed to record the upgrade\n");
		}
		
		echo "DONE\n";
	}
	public static function up($name) {
		self::upgrade($name, 'up');
	}
	public static function down($name) {
		self::upgrade($name, 'down');
	}
	public static function refresh($name) {
		self::upgrade($name, 'down');
		self::upgrade($name, 'up');
	}
	public static function remove($name) {
		self::upgrade($name, 'down');
		DB::delete("delete from upgrades where name = '".DB::escape($name)."'");
	}
	public static function removeAll() {
		$names = [];
		foreach (DB::selectAll("select * from upgrades") as $u) {
			$names[] = $u['name'];
		}
		natsort($names);
		foreach (array_reverse($names) as $name) {
			self::upgrade($name, 'down');
		}
		DB::delete("delete from upgrades");
	}
	
	public static function upgradeToLatest() {
		$names = array_filter(scandir(self::upgraderRoot()), function ($name) {
			return is_dir(self::upgraderRoot().'/'.$name) && preg_match('/^\d+_[a-zA-Z_]+$/', $name);
		});
		
		natsort($names);
		
		$names_table = [];
		foreach ($names as $name) {
			$names_table[$name] = true;
		}
		
		$dres = DB::selectAll("select * from upgrades");
		foreach ($dres as $u) {
			if (!isset($names_table[$u['name']])) {
				self::fail('missing: '.$u['name']."\n");
			}
		}
		
		foreach ($names as $name) {
			self::up($name);
		}
	}
}
