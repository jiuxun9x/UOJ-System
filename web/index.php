<?php

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
