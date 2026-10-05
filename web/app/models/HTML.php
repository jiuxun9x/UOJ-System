<?php

class HTML {
	public static function escape($str) {
		return htmlspecialchars($str);
	}
	public static function stripTags($str) {
		return strip_tags($str);
	}
	// The picture of a user. The site draws it itself: the first character of the name on a
	// colour that is the user's own. Nothing is fetched from another site, and no other site
	// learns who looks at whom. With switch.gravatar the pictures come from Gravatar as they
	// used to.
	public static function avatar_addr($user, $size) {
		if (!empty(UOJConfig::$data['switch']['gravatar'])) {
			return '//cn.gravatar.com/avatar/' . md5(strtolower(trim($user['email']))) . "?d=mm&amp;s=$size";
		}
		$name = isset($user['nickname']) && $user['nickname'] !== '' ? $user['nickname'] : $user['username'];
		$letter = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
		$hue = hexdec(substr(md5($user['username']), 0, 4)) % 360;
		$size = (int)$size;
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100">'
			. '<rect width="100" height="100" fill="hsl(' . $hue . ',45%,55%)"/>'
			. '<text x="50" y="50" dy="0.35em" text-anchor="middle" font-family="sans-serif" font-size="52" fill="#fff">' . htmlspecialchars($letter, ENT_QUOTES | ENT_XML1) . '</text>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}
	// where MathJax is loaded from: the copy that comes with the site, or the one out there
	// where the site was set up without it
	public static function mathjax_url() {
		if (is_file($_SERVER['DOCUMENT_ROOT'] . '/js/mathjax/MathJax.js')) {
			return self::url('/js/mathjax/MathJax.js');
		}
		return 'https://cdn.jsdelivr.net/npm/mathjax@2.7.7/MathJax.js';
	}
		
	public static function tablist($tabs_info, $cur, $type = 'nav-tabs') {
		$html = '<ul class="nav '.$type.'" role="tablist">';
		foreach ($tabs_info as $id => $tab) {
			$html .= '<li class="nav-item"><a class="nav-link'.($cur == $id ? ' active' : '').'" href="'.$tab['url'].'" role="tab">'.$tab['name'].'</a></li>';
		}
		$html .= '</ul>';
		return $html;
	}
	
	public static function hiddenToken() {
		return '<input type="hidden" name="_token" value="'.crsf_token().'" />';
	}
	public static function div_vinput($name, $type, $label_text, $default_value) {
		return '<div id="'."div-$name".'">'
			. '<label for="'."input-$name".'" class="control-label">'.$label_text.'</label>'
			. '<input type="'.$type.'" class="form-control" name="'.$name.'" id="'."input-$name".'" value="'.HTML::escape($default_value).'" />'
			. '<span class="help-block" id="'."help-$name".'"></span>'
			. '</div>';
	}
	public static function div_vtextarea($name, $label_text, $default_value) {
		return '<div id="'."div-$name".'">'
			. '<label for="'."input-$name".'" class="control-label">'.$label_text.'</label>'
			. '<textarea class="form-control" name="'.$name.'" id="'."input-$name".'">'.HTML::escape($default_value).'</textarea>'
			. '<span class="help-block" id="'."help-$name".'"></span>'
			. '</div>';
	}
	public static function checkbox($name, $default_value) {
		$status = $default_value ? 'checked="checked" ' : '';
		return '<input type="checkbox" id="'."input-$name".'" name="'.$name.'" '.$status.'/>';
	}
	
	public static function blog_url($username, $uri) {
		switch (UOJConfig::$data['switch']['blog-domain-mode']) {
			case 1:
				$port = ((UOJConfig::$data['web']['blog']['protocol'] === "http" && UOJConfig::$data['web']['blog']['port'] == 80) || (UOJConfig::$data['web']['blog']['protocol'] === "https" && UOJConfig::$data['web']['blog']['port'] == 443)) ? '' : (':'.UOJConfig::$data['web']['blog']['port']);
				$url = UOJConfig::$data['web']['blog']['protocol'].'://'.blog_name_encode($username).'.'.UOJConfig::$data['web']['blog']['host'].$port;
				break;
			case 2:
				$port = ((UOJConfig::$data['web']['blog']['protocol'] === "http" && UOJConfig::$data['web']['blog']['port'] == 80) || (UOJConfig::$data['web']['blog']['protocol'] === "https" && UOJConfig::$data['web']['blog']['port'] == 443)) ? '' : (':'.UOJConfig::$data['web']['blog']['port']);
				$url = UOJConfig::$data['web']['blog']['protocol'].'://'.UOJConfig::$data['web']['blog']['host'].$port.'/'.blog_name_encode($username);
				break;
			case 3:
				$port = ((UOJConfig::$data['web']['main']['protocol'] === "http" && UOJConfig::$data['web']['main']['port'] == 80) || (UOJConfig::$data['web']['main']['protocol'] === "https" && UOJConfig::$data['web']['main']['port'] == 443)) ? '' : (':'.UOJConfig::$data['web']['main']['port']);
				$url = UOJConfig::$data['web']['main']['protocol'].'://'.UOJConfig::$data['web']['main']['host'].$port.'/blog/'.blog_name_encode($username);
				break;
		}
		$url .= $uri;
		$url = rtrim($url, '/');
		return HTML::escape($url);
	}
	public static function blog_list_url() {
		switch (UOJConfig::$data['switch']['blog-domain-mode']) {
			case 1:
			case 2:
				$port = ((UOJConfig::$data['web']['blog']['protocol'] === "http" && UOJConfig::$data['web']['blog']['port'] == 80) || (UOJConfig::$data['web']['blog']['protocol'] === "https" && UOJConfig::$data['web']['blog']['port'] == 443)) ? '' : (':'.UOJConfig::$data['web']['blog']['port']);
				$url = UOJConfig::$data['web']['blog']['protocol'].'://'.UOJConfig::$data['web']['blog']['host'].$port;
				break;
			case 3:
				$port = ((UOJConfig::$data['web']['main']['protocol'] === "http" && UOJConfig::$data['web']['main']['port'] == 80) || (UOJConfig::$data['web']['main']['protocol'] === "https" && UOJConfig::$data['web']['main']['port'] == 443)) ? '' : (':'.UOJConfig::$data['web']['main']['port']);
				$url = UOJConfig::$data['web']['main']['protocol'].'://'.UOJConfig::$data['web']['main']['host'].$port.'/blogs';
				break;
		}
		return HTML::escape(rtrim($url, '/'));
	}
	
	public static function url($uri, $config = array()) {
		$config = array_merge(array(
			'location' => 'main',
			'params' => null
		), $config);
		
		$path = strtok($uri, '?');
		$qs = strtok('?');
		parse_str($qs, $param);
		
		if ($config['params'] != null) {
			$param = array_merge($param, $config['params']);
		}
		
		$url = UOJConfig::$data['web'][$config['location']]['protocol'].'://'.UOJConfig::$data['web'][$config['location']]['host'];
		if ((UOJConfig::$data['web'][$config['location']]['protocol'] === "http" && UOJConfig::$data['web'][$config['location']]['port'] == 80) || (UOJConfig::$data['web'][$config['location']]['protocol'] === "https" && UOJConfig::$data['web'][$config['location']]['port'] == 443)) {
		} else {
			$url .= ':'.UOJConfig::$data['web'][$config['location']]['port'];
		}
		if ($param) {
			$url .= $path.'?'.HTML::query_string_encode($param);
		} else {
			$url .= rtrim($path, '/');
		}
		return HTML::escape($url);
	}
	public static function timeanddate_url($time, $config = array()) {
		$url = UOJConfig::$data['web']['blog']['protocol'].'://';
		$url .= 'www.timeanddate.com/worldclock/fixedtime.html';
		$url .= '?'.'iso='.$time->format('Ymd\THi');
		$url .= '&'.'p1=33';
		if (isset($config['duration']) && $config['duration'] < 3600) {
			$url .= '&'.'ah='.floor($config['duration'] / 60);
			if ($config['duration'] % 60 != 0) {
				$url .= '&'.'am='.($config['duration'] % 60);
			}
		}
		$url = HTML::escape($url);
		return $url;
	}
	
	public static function js_src($uri, $config = array('location' => 'main')) {
		return '<script src="'.HTML::url($uri, $config).'"></script>';
	}
	public static function css_link($uri, $config = array('location' => 'main')) {
		return '<link type="text/css" rel="stylesheet" href="'.HTML::url($uri, $config).'" />';
	}
	
	public static function query_string_encode($q, $array_name = null) {
		if (!is_array($q)) {
			return false;
		}
		$r = array();
		foreach ((array)$q as $k => $v) {
			if ($array_name !== null) {
				if (is_numeric($k)) {
					$k = $array_name."[]";
				} else {
					$k = $array_name."[$k]";
				}
			}
			if (is_array($v) || is_object($v)) {
				$r[] = self::query_string_encode($v, $k);
			} else {
				$r[] = urlencode($k)."=".urlencode($v);
			}
		}
		return implode("&", $r);
	}
	
	public static function pruifier() {
		include_once $_SERVER['DOCUMENT_ROOT'] . '/app/vendor/htmlpurifier/HTMLPurifier.auto.php';
		$config = HTMLPurifier_Config::createDefault();
		//$config->set('HTML.Doctype', 'HTML 4.01 Transitional');
		$config->set('Cache.DefinitionImpl', null);
		$def = $config->getHTMLDefinition(true);
		$def->addAttribute('span', 'data-rating', 'Number');
		
		$def->addElement('section', 'Block', 'Flow', 'Common');
		$def->addElement('nav',     'Block', 'Flow', 'Common');
		$def->addElement('article', 'Block', 'Flow', 'Common');
		$def->addElement('aside',   'Block', 'Flow', 'Common');
		$def->addElement('header',  'Block', 'Flow', 'Common');
		$def->addElement('footer',  'Block', 'Flow', 'Common');
		// films and sound, played by the browser: an announcement shows them in its text
		// (an attribute that has no value is named with its type, or it is dropped)
		$playing = array('src' => 'URI', 'preload' => 'Enum#auto,metadata,none', 'controls' => 'Bool#controls', 'loop' => 'Bool#loop', 'muted' => 'Bool#muted');
		$def->addElement('video', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', $playing + array('width' => 'Length', 'height' => 'Length', 'poster' => 'URI'));
		$def->addElement('audio', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', $playing);
		$def->addElement('source', 'Block', 'Empty', 'Common', array('src' => 'URI', 'type' => 'Text'));
		
		return new HTMLPurifier($config);
	}

	public static function parsedown($config = []) {
		return new UOJMarkdown($config + [
			'math' => [
				'enabled' => true,
				'matchSingleDollar' => true
			]
		]);
	}
}
