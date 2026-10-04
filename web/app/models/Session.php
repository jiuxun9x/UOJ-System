<?php

class Session {
	public static function init() {
		session_name('UOJSESSID');
		ini_set('session.cookie_path', '/');
		ini_set('session.cookie_domain', UOJContext::cookieDomain());
		// scripts have no business with the session, and other sites can not use it
		ini_set('session.cookie_httponly', '1');
		ini_set('session.cookie_samesite', 'Lax');
		if (UOJContext::isSecureSite()) {
			ini_set('session.cookie_secure', '1');
		}
		// an id that this server did not hand out is not accepted
		ini_set('session.use_strict_mode', '1');
		ini_set('session.use_only_cookies', '1');
		
		session_start();
		
		register_shutdown_function(function() {
			if (empty($_SESSION)) {
				session_unset();
				session_destroy();
			}
		});
	}
	// Whoever knew the id of the session before the user logged in, or still knows it after
	// they logged out, must not be the user: the session moves to a new id.
	public static function renew() {
		if (session_status() == PHP_SESSION_ACTIVE && !headers_sent()) {
			session_regenerate_id(true);
		}
	}
}
