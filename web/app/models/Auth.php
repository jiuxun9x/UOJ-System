<?php

class Auth {
	public static function check() {
		global $myUser;
		return $myUser != null;
	}
	public static function id() {
		global $myUser;
		return $myUser ? $myUser['username'] : null;
	}
	public static function user() {
		global $myUser;
		return $myUser;
	}
	public static function login($username, $remember = true) {
		if (!validateUsername($username)) {
			return;
		}
		Session::renew();
		$_SESSION['username'] = $username;
		if ($remember) {
			// a token for this device: the database keeps its hash only
			$remember_token = uojRandString(60);
			DB::insert("insert into user_remember_tokens (username, token_hash, created_at, last_used_at) values ('$username', '".Auth::tokenHash($remember_token)."', now(), now())");

			$expire = time() + 60 * 60 * 24 * 365 * 10;
			Cookie::safeSet('uoj_username', $username, $expire, '/', array('httponly' => true));
			Cookie::safeSet('uoj_remember_token', $remember_token, $expire, '/', array('httponly' => true));
		}
	}
	public static function tokenHash($token) {
		return hash('sha256', $token);
	}
	public static function logout() {
		// the login this device remembers is forgotten, the ones of the other devices stay
		$remember_token = Cookie::safeGet('uoj_remember_token', '/');
		if (Auth::check() && is_string($remember_token) && $remember_token !== '') {
			DB::delete("delete from user_remember_tokens where username = '".DB::escape(Auth::id())."' and token_hash = '".Auth::tokenHash($remember_token)."'");
		}
		// nothing of the session survives, not even its token against forged requests
		$_SESSION = array();
		Session::renew();
		Cookie::safeUnset('uoj_username', '/');
		Cookie::safeUnset('uoj_remember_token', '/');
	}

	private static function initMyUser() {
		global $myUser;
		$myUser = null;
		
		Cookie::safeCheck('uoj_username', '/');
		Cookie::safeCheck('uoj_remember_token', '/');
		
		if (isset($_SESSION['username'])) {
			if (!validateUsername($_SESSION['username'])) {
				return;
			}
			$myUser = queryUser($_SESSION['username']);
			return;
		}

		$remember_token = Cookie::safeGet('uoj_remember_token', '/');
		if ($remember_token != null) {
			// No token is not a token to match. A token is looked for by its hash, which says whose
			// it is: under the name they have now, whatever name they had when they logged in.
			if (!is_string($remember_token) || $remember_token === '') {
				return;
			}
			$token = DB::selectFirst("select id, username, last_used_at < now() - interval 1 day as stale from user_remember_tokens where token_hash = '".Auth::tokenHash($remember_token)."'");
			if (!$token || !($myUser = queryUser($token['username']))) {
				$myUser = null;
				return;
			}
			if ($token['stale']) {
				// a token not used for half a year is forgotten (site:tick)
				DB::update("update user_remember_tokens set last_used_at = now() where id = {$token['id']}");
			}
			return;
		}
	}
	public static function init() {
		global $myUser;
		
		Auth::initMyUser();
		if ($myUser) {
			if ($myUser['usergroup'] == 'B') {
				$myUser = null;
			}
		}
		if ($myUser) {
			$forwarded_for = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
			DB::update("update user_info set remote_addr = '".DB::escape(UOJContext::remoteAddr())."', http_x_forwarded_for = '".DB::escape($forwarded_for)."' where username = '".DB::escape($myUser['username'])."'");
			$_SESSION['last_visited'] = time();
		}
	}
}
