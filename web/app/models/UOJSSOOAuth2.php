<?php

// OAuth 2.0 with the authorization code, and OpenID Connect on top of it: the browser is sent
// to the school and comes back with a code, which the web server exchanges for a token and the
// token for the profile of the user. The web server talks to the school itself over TLS, so the
// profile is not taken from anything the browser carried.
//
// 'client_id', 'client_secret'
// 'authorize_url', 'token_url', 'userinfo_url'
//             or, for type 'oidc', 'issuer': the three are read from its discovery document
// 'scope'     'openid profile email' by default for 'oidc', none for 'oauth2'
// 'pkce'      true by default, false for a school that refuses the extra parameters
// 'token_auth'     'post' (the default) sends the secret in the form, 'basic' in a header
// 'userinfo_auth'  'header' (the default) sends the token as a Bearer header, 'query' as
//                  the parameter access_token
class UOJSSOOAuth2 {
	public $name;
	public $config;

	public function __construct($name, $config) {
		$this->name = $name;
		$this->config = $config + array(
			'scope' => $config['type'] == 'oidc' ? 'openid profile email' : '',
			'pkce' => true,
			'token_auth' => 'post',
			'userinfo_auth' => 'header'
		);
	}

	private function endpoint($name) {
		if (isset($this->config[$name . '_url'])) {
			return $this->config[$name . '_url'];
		}
		if (!isset($this->config['issuer'])) {
			throw new RuntimeException("统一身份认证未配置 {$name}_url");
		}
		$names = array('authorize' => 'authorization_endpoint', 'token' => 'token_endpoint', 'userinfo' => 'userinfo_endpoint');
		$discovery = $this->discovery();
		if (!isset($discovery[$names[$name]]) || !is_string($discovery[$names[$name]])) {
			throw new RuntimeException("统一身份认证的发现文档缺少 {$names[$name]}");
		}
		return $discovery[$names[$name]];
	}

	// the discovery document of the issuer, fetched once a day
	private function discovery() {
		$issuer = rtrim($this->config['issuer'], '/');
		$cache = sys_get_temp_dir() . '/uoj_oidc_' . md5($issuer) . '.json';
		if (is_file($cache) && filemtime($cache) > time() - 86400) {
			$discovery = json_decode(file_get_contents($cache), true);
			if (is_array($discovery)) {
				return $discovery;
			}
		}
		$response = UOJHttp::get($issuer . '/.well-known/openid-configuration');
		$discovery = json_decode($response['body'], true);
		if ($response['status'] != 200 || !is_array($discovery) || !isset($discovery['issuer']) || rtrim($discovery['issuer'], '/') !== $issuer) {
			throw new RuntimeException('无法读取统一身份认证的发现文档');
		}
		file_put_contents($cache, json_encode($discovery), LOCK_EX);
		return $discovery;
	}

	public static function pkceChallenge($verifier) {
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	public function loginUrl($callback_url, &$session) {
		$session['state'] = uojRandString(32);
		$params = array(
			'response_type' => 'code',
			'client_id' => $this->config['client_id'],
			'redirect_uri' => $callback_url,
			'state' => $session['state']
		);
		if ($this->config['scope'] !== '') {
			$params['scope'] = $this->config['scope'];
		}
		if ($this->config['pkce']) {
			$session['verifier'] = uojRandString(64);
			$params['code_challenge'] = self::pkceChallenge($session['verifier']);
			$params['code_challenge_method'] = 'S256';
		}
		return UOJHttp::addQuery($this->endpoint('authorize'), $params);
	}

	public function identify($callback_url, $query, $session) {
		// the state ties the answer to the browser that asked, whatever the answer is
		if (!isset($session['state']) || !isset($query['state']) || !is_string($query['state']) || !hash_equals($session['state'], $query['state'])) {
			throw new RuntimeException('登录请求与当前会话不符，请重新登录');
		}
		if (isset($query['error'])) {
			throw new RuntimeException('统一身份认证拒绝了登录：' . (is_string($query['error']) ? $query['error'] : ''));
		}
		if (!isset($query['code']) || !is_string($query['code']) || $query['code'] === '' || strlen($query['code']) > 2048) {
			throw new RuntimeException('统一身份认证没有返回授权码');
		}

		$form = array(
			'grant_type' => 'authorization_code',
			'code' => $query['code'],
			'redirect_uri' => $callback_url
		);
		$headers = array('Accept: application/json');
		if ($this->config['token_auth'] == 'basic') {
			$headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($this->config['client_id']) . ':' . rawurlencode($this->config['client_secret']));
		} else {
			$form['client_id'] = $this->config['client_id'];
			$form['client_secret'] = $this->config['client_secret'];
		}
		if (isset($session['verifier'])) {
			$form['code_verifier'] = $session['verifier'];
		}
		$response = UOJHttp::post($this->endpoint('token'), $form, $headers);
		$token = json_decode($response['body'], true);
		if ($response['status'] != 200 || !is_array($token) || !isset($token['access_token']) || !is_string($token['access_token'])) {
			$reason = is_array($token) && isset($token['error']) && is_string($token['error']) ? $token['error'] : "HTTP {$response['status']}";
			throw new RuntimeException("统一身份认证没有签发令牌（{$reason}）");
		}
		if (isset($token['token_type']) && strcasecmp($token['token_type'], 'bearer') != 0) {
			throw new RuntimeException('统一身份认证签发了不支持的令牌类型');
		}

		if ($this->config['userinfo_auth'] == 'query') {
			$response = UOJHttp::get(UOJHttp::addQuery($this->endpoint('userinfo'), array('access_token' => $token['access_token'])), array('Accept: application/json'));
		} else {
			$response = UOJHttp::get($this->endpoint('userinfo'), array('Accept: application/json', 'Authorization: Bearer ' . $token['access_token']));
		}
		$profile = json_decode($response['body'], true);
		if ($response['status'] != 200 || !is_array($profile)) {
			throw new RuntimeException("统一身份认证没有返回用户信息（HTTP {$response['status']}）");
		}
		return $profile;
	}

	public function defaultAttributes() {
		if ($this->config['type'] == 'oidc') {
			return array('external_id' => 'sub', 'student_id' => 'preferred_username', 'real_name' => 'name', 'email' => 'email');
		}
		return array('external_id' => 'id', 'student_id' => 'id', 'real_name' => 'name', 'email' => 'email');
	}
}
