<?php

class DB {
	// returns the host, the port and the socket to pass to mysqli_connect()
	public static function connectParams($conf) {
		$host = isset($conf['host']) ? $conf['host'] : 'localhost';
		$port = isset($conf['port']) ? (int)$conf['port'] : 3306;
		$socket = isset($conf['socket']) && $conf['socket'] !== '' ? $conf['socket'] : null;
		if ($socket !== null) {
			$host = 'localhost';
		} elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$host = "[$host]";
		}
		return [$host, $port, $socket];
	}

	public static function init() {
		global $uojMySQL;
		// errors are reported by return values on every PHP version
		mysqli_report(MYSQLI_REPORT_OFF);
		list($host, $port, $socket) = self::connectParams(UOJConfig::$data['database']);
		@$uojMySQL = mysqli_connect($host, UOJConfig::$data['database']['username'], UOJConfig::$data['database']['password'], UOJConfig::$data['database']['database'], $port, $socket);
		if (!$uojMySQL) {
			echo 'There is something wrong with database >_<.... ' . mysqli_connect_error() . "\n";
			exit(1);
		}
	}
	public static function escape($str) {
		global $uojMySQL;
		return mysqli_real_escape_string($uojMySQL, $str);
	}
	public static function fetch($r, $opt = MYSQLI_ASSOC) {
		global $uojMySQL;
		return mysqli_fetch_array($r, $opt);
	}
	
	public static function query($q) {
		global $uojMySQL;
		return mysqli_query($uojMySQL, $q);
	}
	public static function update($q) {
		global $uojMySQL;
		return mysqli_query($uojMySQL, $q);
	}
	public static function insert($q) {
		global $uojMySQL;
		return mysqli_query($uojMySQL, $q);
	}
	public static function insert_id() {
		global $uojMySQL;
		return mysqli_insert_id($uojMySQL);
	}
		
	public static function delete($q) {
		global $uojMySQL;
		return mysqli_query($uojMySQL, $q);
	}
	public static function select($q) {
		global $uojMySQL;
		return mysqli_query($uojMySQL, $q);
	}
	public static function selectAll($q, $opt = MYSQLI_ASSOC) {
		global $uojMySQL;
		$res = array();
		$qr = mysqli_query($uojMySQL, $q);
		while ($row = mysqli_fetch_array($qr, $opt)) {
			$res[] = $row;
		}
		return $res;
	}
	public static function selectFirst($q, $opt = MYSQLI_ASSOC) {
		global $uojMySQL;
		return mysqli_fetch_array(mysqli_query($uojMySQL, $q), $opt);
	}
	public static function selectCount($q) {
		global $uojMySQL;
		list($cnt) = mysqli_fetch_array(mysqli_query($uojMySQL, $q), MYSQLI_NUM);
		return $cnt;
	}
	
	// runs a script of several statements, returns null or the message of the first error
	public static function multiQuery($q) {
		global $uojMySQL;
		if (!mysqli_multi_query($uojMySQL, $q)) {
			return mysqli_error($uojMySQL);
		}
		do {
			$res = mysqli_store_result($uojMySQL);
			if ($res) {
				mysqli_free_result($res);
			}
		} while (mysqli_more_results($uojMySQL) && mysqli_next_result($uojMySQL));
		if (mysqli_errno($uojMySQL)) {
			return mysqli_error($uojMySQL);
		}
		return null;
	}

	public static function error() {
		global $uojMySQL;
		return mysqli_error($uojMySQL);
	}

	public static function checkTableExists($name) {
		global $uojMySQL;
		return DB::query("select 1 from $name") !== false;
	}
	
	public static function num_rows() {
		global $uojMySQL;
		return mysqli_num_rows($uojMySQL);
	}
	public static function affected_rows() {
		global $uojMySQL;
		return mysqli_affected_rows($uojMySQL);
	}
}