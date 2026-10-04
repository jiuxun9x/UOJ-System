<?php
	// sends the browser to the login of the school
	$provider = UOJSSO::provider($_GET['provider']);
	if ($provider === null) {
		become404Page();
	}
	if (Auth::check()) {
		redirectTo('/');
	}
	
	$pending = array('time' => time());
	try {
		$url = $provider->loginUrl(UOJSSO::callbackUrl($provider->name), $pending);
	} catch (RuntimeException $e) {
		becomeMsgPage('<h3>统一身份认证登录失败</h3><p>' . HTML::escape($e->getMessage()) . '</p>');
	}
	// one login at a time: starting a new one forgets the one before
	$_SESSION['sso'] = array($provider->name => $pending);
	unset($_SESSION['sso_bind']);
	redirectTo($url);
