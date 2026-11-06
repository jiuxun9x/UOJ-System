<?php

// While a backup is put back the site is closed: its database is being replaced, and nothing is
// to be read from it or written to it (see backupRestore() and restore.sh). This is looked at
// before anything is loaded, which would use the database.
if (is_file(sys_get_temp_dir() . '/uoj-maintenance')) {
	header($_SERVER['SERVER_PROTOCOL'] . ' 503 Service Unavailable', true, 503);
	header('Retry-After: 60');
	header('Content-Type: text/html; charset=utf-8');
	die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>维护中</title></head><body style="text-align:center;padding-top:15%"><h1>网站维护中</h1><p>正在恢复备份，请稍后再来。</p></body></html>');
}

require $_SERVER['DOCUMENT_ROOT'] . '/app/libs/uoj-lib.php';

require UOJContext::documentRoot().'/app/route.php';
require UOJContext::documentRoot().'/app/controllers/subdomain/blog/route.php';

include UOJContext::documentRoot().'/app/controllers'.call_user_func(function() {
	$route = Route::dispatch();
	$q_pos = strpos($route['action'], '?');
	
	if ($q_pos === false) {
		$path = $route['action'];
	} else {
		parse_str(substr($route['action'], $q_pos + 1), $vars);
		// what the route says is what the page is: the query of the request does not change it
		// (/judge/download/tmp/... with ?type=submission was another page)
		$_GET = $vars + $_GET;
		$path = substr($route['action'], 0, $q_pos);
	}
	
	if (isset($route['onload'])) {
		call_user_func($route['onload']);
	}
	
	return $path;
});
