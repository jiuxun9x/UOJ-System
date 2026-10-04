<?php
	// the browser comes back from the login of the school
	$provider = UOJSSO::provider($_GET['provider']);
	if ($provider === null) {
		become404Page();
	}
	if (Auth::check()) {
		redirectTo('/');
	}
	
	// what was remembered when the login started is used once, whatever comes of it
	$pending = isset($_SESSION['sso'][$provider->name]) ? $_SESSION['sso'][$provider->name] : array();
	unset($_SESSION['sso']);
	try {
		if (!isset($pending['time']) || $pending['time'] < time() - 600) {
			throw new RuntimeException('登录请求已过期，请重新登录');
		}
		$attributes = $provider->identify(UOJSSO::callbackUrl($provider->name), $_GET, $pending);
		$mapping = (isset($provider->config['attributes']) ? $provider->config['attributes'] : array()) + $provider->defaultAttributes();
		$identity = UOJSSO::identityFromAttributes($attributes, $mapping);
		$result = UOJSSO::signIn($provider->name, $identity);
	} catch (RuntimeException $e) {
		error_log("sso login through {$provider->name} failed: " . $e->getMessage());
		becomeMsgPage('<h3>统一身份认证登录失败</h3><p>' . HTML::escape($e->getMessage()) . '</p><p><a href="/login">返回登录页</a></p>');
	}
	
	if (isset($result['bind'])) {
		$_SESSION['sso_bind'] = array('provider' => $provider->name, 'identity' => $identity, 'time' => time(), 'tries' => 0);
		redirectTo("/login/sso/{$provider->name}/bind");
	}
	if ($result['user']['usergroup'] == 'B') {
		becomeMsgPage('该用户已被封停，请联系管理员。');
	}
	Auth::login($result['user']['username']);
	redirectTo('/');
