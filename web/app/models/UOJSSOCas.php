<?php

// CAS 2.0 and 3.0: the browser is sent to the login of the school and comes back with a
// ticket, which the web server shows to the school to learn who logged in.
//
// 'server'   the address of the CAS server, like https://cas.example.edu.cn/cas
// 'version'  3 (the default) validates at /p3/serviceValidate, 2 at /serviceValidate
// 'service_state'  false if the school only accepts the exact address of the callback
class UOJSSOCas {
	public $name;
	public $config;

	public function __construct($name, $config) {
		$this->name = $name;
		$this->config = $config + array('version' => 3, 'service_state' => true);
	}

	// The address the school sends the browser back to. It carries a secret of this session, so
	// that a ticket somebody else was given can not be used to log this browser in as them.
	private function service($callback_url, $state) {
		if (!$this->config['service_state']) {
			return $callback_url;
		}
		return UOJHttp::addQuery($callback_url, array('state' => $state));
	}

	public function loginUrl($callback_url, &$session) {
		$session['state'] = uojRandString(32);
		return UOJHttp::addQuery(rtrim($this->config['server'], '/') . '/login', array(
			'service' => $this->service($callback_url, $session['state'])
		));
	}

	public function identify($callback_url, $query, $session) {
		if (!isset($session['state'])) {
			throw new RuntimeException('登录请求已过期，请重新登录');
		}
		if ($this->config['service_state']) {
			if (!isset($query['state']) || !is_string($query['state']) || !hash_equals($session['state'], $query['state'])) {
				throw new RuntimeException('登录请求与当前会话不符，请重新登录');
			}
		}
		if (!isset($query['ticket']) || !is_string($query['ticket']) || !preg_match('/^[\x21-\x7e]{1,512}$/', $query['ticket'])) {
			throw new RuntimeException('统一身份认证没有返回有效的票据');
		}
		$path = $this->config['version'] >= 3 ? '/p3/serviceValidate' : '/serviceValidate';
		$response = UOJHttp::get(UOJHttp::addQuery(rtrim($this->config['server'], '/') . $path, array(
			'service' => $this->service($callback_url, $session['state']),
			'ticket' => $query['ticket']
		)));
		if ($response['status'] != 200) {
			throw new RuntimeException("统一身份认证校验票据失败（HTTP {$response['status']}）");
		}
		return self::parseValidation($response['body']);
	}

	// Returns the attributes of the user of a successful validation, the name the school
	// calls them by as 'user'. Throws for everything else.
	public static function parseValidation($xml) {
		// A validation has no reason to declare entities. Refuse it before the parser sees it.
		if (!is_string($xml) || trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
			throw new RuntimeException('统一身份认证的响应格式不正确');
		}
		$doc = new DOMDocument();
		if (!@$doc->loadXML($xml, LIBXML_NONET)) {
			throw new RuntimeException('统一身份认证的响应格式不正确');
		}
		$cas = 'http://www.yale.edu/tp/cas';
		$root = $doc->documentElement;
		if ($root === null || $root->namespaceURI !== $cas || $root->localName !== 'serviceResponse') {
			throw new RuntimeException('统一身份认证的响应格式不正确');
		}
		$success = null;
		foreach ($root->childNodes as $node) {
			if ($node->nodeType != XML_ELEMENT_NODE || $node->namespaceURI !== $cas) {
				continue;
			}
			if ($node->localName === 'authenticationFailure') {
				throw new RuntimeException('统一身份认证拒绝了票据：' . $node->getAttribute('code'));
			}
			if ($node->localName === 'authenticationSuccess') {
				$success = $node;
			}
		}
		if ($success === null) {
			throw new RuntimeException('统一身份认证的响应格式不正确');
		}
		$attributes = array();
		foreach ($success->childNodes as $node) {
			if ($node->nodeType != XML_ELEMENT_NODE) {
				continue;
			}
			if ($node->localName === 'user') {
				$attributes['user'] = trim($node->textContent);
			} elseif ($node->localName === 'attributes') {
				foreach ($node->childNodes as $attribute) {
					// of an attribute with several values, the first
					if ($attribute->nodeType == XML_ELEMENT_NODE && !isset($attributes[$attribute->localName])) {
						$attributes[$attribute->localName] = trim($attribute->textContent);
					}
				}
			}
		}
		if (!isset($attributes['user']) || $attributes['user'] === '') {
			throw new RuntimeException('统一身份认证没有返回用户');
		}
		return $attributes;
	}

	public function defaultAttributes() {
		return array('external_id' => 'user', 'student_id' => 'user', 'real_name' => 'cn', 'email' => 'mail');
	}
}
