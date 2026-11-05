<?php
	requirePHPLib('form');
	
	$forgot_form = new UOJForm('forgot');
	$forgot_form->addInput('username', 'text', '用户名', '',
		function($username, &$vdata) {
			if (!validateUsername($username)) {
				return '用户名不合法';
			}
			$vdata['user'] = queryUser($username);
			return '';
		},
		null
	);
	// Whether the user exists, has a mailbox, or has asked too often already, whoever asks is
	// told the same: the page tells nobody which accounts there are.
	$forgot_form->handle = function(&$vdata) {
		$user = $vdata['user'];
		$minutes = passwordResetMinutes();
		$token = $user && validateEmail($user['email']) ? passwordResetCreate($user, UOJContext::remoteAddr()) : null;
		if ($token !== null) {
			sendPasswordResetMail($user, $token, $minutes);
		}
		becomeMsgPage('<div class="text-center" id="forgot-password-sent"><h2>如果这个账号存在并且填写了邮箱，重设密码的链接已经发到邮箱</h2>'
			. "<p>链接 {$minutes} 分钟内有效，只能用一次。没有收到的话，请看看垃圾邮件，或者过一分钟再申请。</p></div>");
	};
	function sendPasswordResetMail($user, $token, $minutes) {
		$oj_name = UOJConfig::$data['profile']['oj-name'];
		$oj_name_short = UOJConfig::$data['profile']['oj-name-short'];
		$url = HTML::url("/reset-password", array('params' => array('p' => $token)));
		$html = <<<EOD
<base target="_blank" />

<p>{$user['username']}您好，</p>
<p>您刚刚申请了{$oj_name_short}的密码找回，请在 {$minutes} 分钟内打开下面的链接重设密码（链接只能用一次）：</p>
<p><a href="$url">$url</a></p>
<p>如果不是您本人申请的，请忽略这封邮件，您的密码不会改变。</p>
<p>{$oj_name}</p>

<style type="text/css">
body{font-size:14px;font-family:arial,verdana,sans-serif;line-height:1.666;padding:0;margin:0;overflow:auto;white-space:normal;word-wrap:break-word;min-height:100px}
pre {white-space:pre-wrap;white-space:-moz-pre-wrap;white-space:-pre-wrap;white-space:-o-pre-wrap;word-wrap:break-word}
</style>
EOD;
		
		$mailer = UOJMail::noreply();
		$mailer->addAddress($user['email'], $user['username']);
		$mailer->Subject = $oj_name_short."密码找回";
		$mailer->msgHTML($html);
		if (!$mailer->send()) {
			error_log("the mail to reset the password of {$user['username']} was not sent: " . $mailer->ErrorInfo);
		}
	}
	$forgot_form->submit_button_config['align'] = 'offset';
	
	$forgot_form->runAtServer();
?>
<?php echoUOJPageHeader('找回密码') ?>
<h2 class="page-header">找回密码</h2>
<h4>请输入需要找回密码的用户名：</h4>
<?php $forgot_form->printHTML(); ?>
<?php echoUOJPageFooter() ?>
