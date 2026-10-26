<?php

// The requests the web server makes itself, to the providers of the single sign-on.
class UOJHttp {
	// Returns array('status' => 200, 'body' => '...'), or throws when there is no answer.
	// $options: 'headers' => array('Name: value'), 'form' => array of the fields to post.
	public static function request($method, $url, $options = array()) {
		if (!preg_match('/^https?:\/\//i', $url)) {
			throw new RuntimeException("not an http(s) address: $url");
		}
		$headers = isset($options['headers']) ? $options['headers'] : array();
		$headers[] = 'Connection: close';
		$http = array(
			'method' => $method,
			'timeout' => 10,
			// an error status is an answer too, and a redirect is never what was asked for
			'ignore_errors' => true,
			'follow_location' => 0,
			'max_redirects' => 0,
			'protocol_version' => 1.1,
			'user_agent' => 'UOJ'
		);
		if (isset($options['form'])) {
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			$http['content'] = http_build_query($options['form'], '', '&');
		}
		$http['header'] = implode("\r\n", $headers);
		$context = stream_context_create(array(
			'http' => $http,
			'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)
		));
		// no provider has a megabyte to say; the headers of the answer are read off the stream,
		// which every version of PHP has (the variable PHP used to fill is deprecated)
		$stream = @fopen($url, 'r', false, $context);
		$body = false;
		$response_headers = null;
		if ($stream !== false) {
			$meta = stream_get_meta_data($stream);
			$response_headers = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : null;
			$body = stream_get_contents($stream, 1048576);
			fclose($stream);
		}
		if ($body === false || empty($response_headers)) {
			throw new RuntimeException('no answer from ' . parse_url($url, PHP_URL_HOST));
		}
		if (!preg_match('/^HTTP\/\S+\s+(\d{3})/', $response_headers[0], $matches)) {
			throw new RuntimeException('bad answer from ' . parse_url($url, PHP_URL_HOST));
		}
		return array('status' => (int)$matches[1], 'body' => $body);
	}
	public static function get($url, $headers = array()) {
		return self::request('GET', $url, array('headers' => $headers));
	}
	public static function post($url, $form, $headers = array()) {
		return self::request('POST', $url, array('headers' => $headers, 'form' => $form));
	}

	public static function addQuery($url, $params) {
		return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}
}
