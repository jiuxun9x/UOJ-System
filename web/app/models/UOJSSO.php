<?php

// Logging in through the single sign-on of the school.
//
// A provider only says who somebody is. What becomes of that is decided here, the same way
// for every protocol: the identity is looked up in external_identities, and the first time it
// is seen, a user is created for it or an existing user is bound to it.
class UOJSSO {
	// the configured providers by name; the name appears in the address of the login
	public static function providers() {
		$providers = array();
		foreach (UOJConfig::$data['sso']['providers'] as $name => $config) {
			if (preg_match('/^[a-zA-Z0-9_-]{1,20}$/', $name) && is_array($config) && isset($config['type'])) {
				$providers[$name] = $config;
			}
		}
		return $providers;
	}
	public static function enabled() {
		return count(self::providers()) > 0;
	}
	public static function provider($name) {
		$providers = self::providers();
		if (!is_string($name) || !isset($providers[$name])) {
			return null;
		}
		switch ($providers[$name]['type']) {
			case 'cas':
				return new UOJSSOCas($name, $providers[$name]);
			case 'oauth2':
			case 'oidc':
				return new UOJSSOOAuth2($name, $providers[$name]);
		}
		return null;
	}
	// what the pages call a provider
	public static function displayName($name) {
		$providers = self::providers();
		return isset($providers[$name]['name']) ? $providers[$name]['name'] : '统一身份认证';
	}

	// The address of a page of this site as the provider has to know it. It is built from the
	// configuration alone.
	public static function siteUrl($path) {
		$main = UOJConfig::$data['web']['main'];
		$url = $main['protocol'] . '://' . $main['host'];
		if (!(($main['protocol'] === 'http' && $main['port'] == 80) || ($main['protocol'] === 'https' && $main['port'] == 443))) {
			$url .= ':' . $main['port'];
		}
		return $url . $path;
	}
	public static function callbackUrl($name) {
		return self::siteUrl("/login/sso/$name/callback");
	}

	// the value of an attribute; "a.b" looks into nested profiles
	private static function attribute($attributes, $path) {
		foreach (explode('.', $path) as $key) {
			if (!is_array($attributes) || !isset($attributes[$key])) {
				return '';
			}
			$attributes = $attributes[$key];
		}
		if (is_array($attributes)) {
			$attributes = reset($attributes);
		}
		return is_scalar($attributes) ? trim((string)$attributes) : '';
	}

	// Turns what a provider reported into an identity:
	//   external_id  what the provider calls the user, unique and never reused
	//   student_id   the student number
	//   username     the username a new user is given: the student number, unless the
	//                configuration names another attribute
	//   real_name, email
	// $mapping says which attribute of the provider each of them is.
	public static function identityFromAttributes($attributes, $mapping) {
		$identity = array();
		foreach (array('external_id', 'student_id', 'real_name', 'email') as $key) {
			$identity[$key] = isset($mapping[$key]) ? self::attribute($attributes, $mapping[$key]) : '';
		}
		if ($identity['external_id'] === '' || strlen($identity['external_id']) > 190) {
			throw new RuntimeException('统一身份认证没有返回可用的用户标识');
		}
		if ($identity['student_id'] === '') {
			$identity['student_id'] = $identity['external_id'];
		}
		$identity['username'] = isset($mapping['username']) ? self::attribute($attributes, $mapping['username']) : $identity['student_id'];
		$identity['student_id'] = mb_substr($identity['student_id'], 0, 64, 'UTF-8');
		$identity['real_name'] = mb_substr($identity['real_name'], 0, 100, 'UTF-8');
		if (!validateEmail($identity['email'])) {
			$identity['email'] = '';
		}
		return $identity;
	}

	private static function identityColumns($identity) {
		return "student_id = '".DB::escape($identity['student_id'])."', real_name = '".DB::escape($identity['real_name'])."', email = '".DB::escape($identity['email'])."'";
	}

	// Binds a user to an identity. Returns false when either is bound already.
	public static function bind($provider_name, $identity, $user) {
		return DB::insert("insert into external_identities set provider = '".DB::escape($provider_name)."', external_id = '".DB::escape($identity['external_id'])."', username = '".DB::escape($user['username'])."', ".self::identityColumns($identity).", created_at = now(), last_login_at = now()");
	}

	// Finds the user of an identity, creating one the first time the identity is seen.
	// Returns array('user' => the user), or array('bind' => the user who has to prove that the
	// identity is theirs). Throws when an administrator has to sort things out.
	public static function signIn($provider_name, $identity) {
		$lock_name = DB::escape('uoj_sso_' . md5($provider_name . "\n" . $identity['external_id']));
		$lock = DB::selectFirst("select get_lock('$lock_name', 10)", MYSQLI_NUM);
		if (!$lock || $lock[0] != 1) {
			throw new RuntimeException('系统繁忙，请稍后再试');
		}
		try {
			return self::signInLocked($provider_name, $identity);
		} finally {
			DB::query("select release_lock('$lock_name')");
		}
	}
	private static function signInLocked($provider_name, $identity) {
		$esc_provider = DB::escape($provider_name);
		$row = DB::selectFirst("select * from external_identities where provider = '$esc_provider' and external_id = '".DB::escape($identity['external_id'])."'");
		if ($row) {
			$user = queryUser($row['username']);
			if (!$user) {
				throw new RuntimeException('该统一身份认证账号对应的用户不存在，请联系管理员');
			}
			// the school knows the name and the address of the user better than this site
			DB::update("update external_identities set ".self::identityColumns($identity).", last_login_at = now() where id = {$row['id']}");
			return array('user' => $user);
		}

		$username = $identity['username'];
		if (!validateUsername($username)) {
			throw new RuntimeException("学号“{$username}”不能作为用户名（只能包含字母、数字和下划线，且不超过 20 位），请联系管理员");
		}
		$user = queryUser($username);
		if ($user) {
			if (DB::selectFirst("select 1 from external_identities where provider = '$esc_provider' and username = '".DB::escape($user['username'])."'")) {
				throw new RuntimeException("用户 {$user['username']} 已绑定另一个统一身份认证账号，请联系管理员");
			}
			// Somebody registered this name before. Whether that was the same person, only
			// the password of that user can tell.
			return array('bind' => $user);
		}
		$err = usernameUnavailableReason($username, null, array('sso' => true));
		if ($err !== '') {
			throw new RuntimeException("无法创建用户 {$username}：{$err}，请联系管理员");
		}
		// The user has no password. Nothing about them is taken from an existing user with
		// the same address: an address proves nothing.
		$esc_email = DB::escape($identity['email']);
		if (!DB::insert("insert into user_info (username, email, password, svn_password, register_time) values ('$username', '$esc_email', '".unusablePassword()."', '".uojRandString(10)."', now())")) {
			throw new RuntimeException('创建用户失败，请稍后再试');
		}
		$user = queryUser($username);
		if (!self::bind($provider_name, $identity, $user)) {
			throw new RuntimeException('绑定统一身份认证账号失败，请联系管理员');
		}
		auditLog('sso.create_user', 'user', $user['username'], null, array('provider' => $provider_name, 'student_id' => $identity['student_id']), $user);
		return array('user' => $user, 'created' => true);
	}

	// the identities of a user, for the people who may know who a user is
	public static function identitiesOf($username) {
		return DB::selectAll("select * from external_identities where username = '".DB::escape($username)."' order by id");
	}
	public static function isBound($username) {
		return DB::selectFirst("select 1 from external_identities where username = '".DB::escape($username)."' limit 1") != null;
	}
}
