<?php

require $_SERVER['DOCUMENT_ROOT'].'/app/vendor/phpmailer/PHPMailerAutoload.php';

class UOJMail {
	// The mailbox mails are sent from: the one the system administrators set on the site, or
	// the one of the configuration file as long as they have set none.
	public static function settings() {
		if (siteSetting('mail.host') !== '') {
			return array(
				'host' => siteSetting('mail.host'),
				'port' => siteSetting('mail.port'),
				'secure' => siteSetting('mail.secure') === 'none' ? '' : siteSetting('mail.secure'),
				'username' => siteSetting('mail.username'),
				'password' => siteSetting('mail.password'),
				'from_name' => siteSetting('mail.from_name'),
				'source' => 'site'
			);
		}
		$config = UOJConfig::$data['mail']['noreply'];
		return array(
			'host' => $config['host'],
			'port' => $config['port'],
			'secure' => $config['secure'],
			'username' => $config['username'],
			'password' => $config['password'],
			'from_name' => '',
			'source' => 'config'
		);
	}
	// whether a mailbox was set up at all: the one the configuration comes with does not exist
	public static function configured() {
		$settings = self::settings();
		return $settings['source'] === 'site' || $settings['host'] !== 'smtp.local_uoj.ac';
	}
	public static function noreply() {
		$settings = self::settings();
		$mailer = new PHPMailer();
		$mailer->isSMTP();
		$mailer->Host = $settings['host'];
		$mailer->Port = $settings['port'];
		// a server that asks for no user is not given one
		$mailer->SMTPAuth = $settings['username'] !== '' && $settings['password'] !== '';
		$mailer->SMTPSecure = $settings['secure'];
		$mailer->Username = $settings['username'];
		$mailer->Password = $settings['password'];
		// a page that sends a mail does not wait for a server that does not answer
		$mailer->Timeout = 15;
		$from_name = $settings['from_name'] !== '' ? $settings['from_name'] : UOJConfig::$data['profile']['oj-name-short'];
		$mailer->setFrom($settings['username'], $from_name);
		$mailer->CharSet = "utf-8";
		$mailer->Encoding = "base64";
		return $mailer;
	}
	// Sends a mail to addresses. Returns '' or why it could not be sent.
	public static function send($addresses, $subject, $html) {
		if (!$addresses) {
			return '没有收件人';
		}
		$mailer = self::noreply();
		foreach ($addresses as $address) {
			$mailer->addAddress($address);
		}
		$mailer->Subject = $subject;
		$mailer->msgHTML($html);
		if (!$mailer->send()) {
			return $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : '发送失败';
		}
		return '';
	}
}
