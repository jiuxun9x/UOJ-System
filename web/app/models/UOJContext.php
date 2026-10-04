<?php

class UOJContext {
	public static $data = array();
	
	public static function pageConfig() {
		if (!isset(self::$data['type'])) {
			return array(
				'PageNav' => 'main-nav'
			);
		} elseif (self::$data['type'] == 'blog') {
			return array(
				'PageNav' => 'blog-nav',
				'PageMainTitle' => UOJContext::$data['user']['username'] . '的博客',
				'PageMainTitleOnSmall' => '博客',
			);
		}
	}
	
	public static function isAjax() {
		return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
	}
	
	public static function contentLength() {
		if (!isset($_SERVER['CONTENT_LENGTH'])) {
			return null;
		}
		return (int)$_SERVER['CONTENT_LENGTH'];
	}
	
	public static function documentRoot() {
		return $_SERVER['DOCUMENT_ROOT'];
	}
	public static function storagePath() {
		return $_SERVER['DOCUMENT_ROOT'].'/app/storage';
	}
	// Whether an address is in a list of addresses and networks like '10.0.0.0/8' or '::1'.
	public static function ipInRanges($ip, $ranges) {
		$addr = @inet_pton($ip);
		if ($addr === false) {
			return false;
		}
		foreach ($ranges as $range) {
			$parts = explode('/', $range, 2);
			$net = @inet_pton($parts[0]);
			if ($net === false || strlen($net) != strlen($addr)) {
				continue;
			}
			$bits = isset($parts[1]) ? (int)$parts[1] : strlen($net) * 8;
			if (isset($parts[1]) && (!ctype_digit($parts[1]) || $bits > strlen($net) * 8)) {
				continue;
			}
			$bytes = intdiv($bits, 8);
			if (substr($addr, 0, $bytes) !== substr($net, 0, $bytes)) {
				continue;
			}
			if ($bits % 8 != 0) {
				$mask = (0xff << (8 - $bits % 8)) & 0xff;
				if ((ord($addr[$bytes]) & $mask) != (ord($net[$bytes]) & $mask)) {
					continue;
				}
			}
			return true;
		}
		return false;
	}
	// The X-Forwarded-* headers say whatever the sender likes. They are only believed when the
	// request comes from a reverse proxy listed in web.trusted-proxies.
	public static function trustedProxies() {
		return isset(UOJConfig::$data['web']['trusted-proxies']) ? UOJConfig::$data['web']['trusted-proxies'] : array();
	}
	public static function isFromTrustedProxy() {
		return isset($_SERVER['REMOTE_ADDR']) && self::ipInRanges($_SERVER['REMOTE_ADDR'], self::trustedProxies());
	}
	// the address of the client: behind our proxies, the last address that is not one of them
	public static function remoteAddr() {
		$addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		if (self::isFromTrustedProxy() && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$hops = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
			for ($i = count($hops) - 1; $i >= 0 && validateIP($hops[$i]); $i--) {
				$addr = $hops[$i];
				if (!self::ipInRanges($addr, self::trustedProxies())) {
					break;
				}
			}
		}
		return $addr;
	}
	public static function isHttps() {
		if (self::isFromTrustedProxy() && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			return strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
		}
		return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	}
	// whether cookies are marked Secure: the site is configured for https, or is visited so
	public static function isSecureSite() {
		return UOJConfig::$data['web']['main']['protocol'] === 'https' || self::isHttps();
	}
	public static function requestURI() {
		return $_SERVER['REQUEST_URI'];
	}
	public static function requestPath() {
		$uri = $_SERVER['REQUEST_URI'];
		$p = strpos($uri, '?');
		if ($p === false) {
			return $uri;
		} else {
			return substr($uri, 0, $p);
		}
	}
	public static function requestMethod() {
		return $_SERVER['REQUEST_METHOD'];
	}
	public static function httpHost() {
		if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && self::isFromTrustedProxy()) {
			return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
		} elseif (isset($_SERVER['HTTP_HOST'])) {
			return $_SERVER['HTTP_HOST'];
		} else {
			return $_SERVER['SERVER_NAME'].($_SERVER['SERVER_PORT'] == '80' ? '' : ':'.$_SERVER['SERVER_PORT']);
		}
	}
	public static function cookieDomain() {
		$domain = UOJConfig::$data['web']['domain'];
		if ($domain === null) {
			$domain = UOJConfig::$data['web']['main']['host'];
		}
		$domain = array_shift(explode(':', $domain));
		if (validateIP($domain) || $domain === 'localhost') {
			$domain = '';
		} else {
			$domain = '.'.$domain;
		}
		return $domain;
	}
	
	public static function setupBlog() {
		$username = blog_name_decode($_GET['blog_username']);
		if (!validateUsername($username) || !(self::$data['user'] = queryUser($username))) {
			become404Page();
		}
		if ($_GET['blog_username'] !== blog_name_encode(self::$data['user']['username'])) {
			permanentlyRedirectTo(HTML::blog_url(self::$data['user']['username'], '/'));
		}
		self::$data['type'] = 'blog';
	}
	
	public static function __callStatic($name, array $args) {
		switch (self::$data['type']) {
			case 'blog':
				switch ($name) {
					case 'user':
						return self::$data['user'];
					case 'userid':
						return self::$data['user']['username'];
					case 'hasBlogPermission':
						return can(Auth::user(), 'blog.manage', self::$data['user']['username']);
					case 'isHis':
						if (!isset($args[0])) {
							return false;
						}
						$blog = $args[0];
						return $blog['poster'] === self::$data['user']['username'];
					case 'isHisBlog':
						if (!isset($args[0])) {
							return false;
						}
						$blog = $args[0];
						return $blog['poster'] === self::$data['user']['username'] && $blog['type'] == 'B' && $blog['is_draft'] == false;
					case 'isHisSlide':
						if (!isset($args[0])) {
							return false;
						}
						$blog = $args[0];
						return $blog['poster'] === self::$data['user']['username'] && $blog['type'] == 'S' && $blog['is_draft'] == false;
				}
				break;
		}
	}
}
