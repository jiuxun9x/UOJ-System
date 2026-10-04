<?php
	// Somebody logged in at the school for the first time, and a user with their student number
	// as username exists already. If that user is theirs, they know its password.
	$provider = UOJSSO::provider($_GET['provider']);
	if ($provider === null) {
		become404Page();
	}
	$pending = isset($_SESSION['sso_bind']) ? $_SESSION['sso_bind'] : null;
	if (Auth::check() || $pending === null || $pending['provider'] !== $provider->name || $pending['time'] < time() - 600) {
		unset($_SESSION['sso_bind']);
		redirectTo('/login');
	}
	$username = $pending['identity']['username'];
	
	function handleBindPost() {
		global $provider, $pending, $username;
		if (!crsf_check()) {
			return 'expired';
		}
		// a few tries, then the login at the school has to be done again
		$_SESSION['sso_bind']['tries']++;
		if ($_SESSION['sso_bind']['tries'] > 5) {
			unset($_SESSION['sso_bind']);
			return 'too many';
		}
		$user = queryUser($username);
		if (!$user || !isset($_POST['password']) || !validatePassword($_POST['password']) || !checkPassword($user, $_POST['password'])) {
			return 'failed';
		}
		if ($user['usergroup'] == 'B') {
			return 'banned';
		}
		if (!UOJSSO::bind($provider->name, $pending['identity'], $user)) {
			return 'bound';
		}
		unset($_SESSION['sso_bind']);
		auditLog('sso.bind', 'user', $user['username'], null, array('provider' => $provider->name, 'student_id' => $pending['identity']['student_id']), $user);
		Auth::login($user['username']);
		return 'ok';
	}
	
	if (isset($_POST['bind'])) {
		die(handleBindPost());
	}
?>
<?php
	$REQUIRE_LIB['md5'] = '';
?>
<?php echoUOJPageHeader('绑定' . HTML::escape(UOJSSO::displayName($provider->name))) ?>
<h2 class="page-header">绑定已有用户</h2>
<p>你已通过<?= HTML::escape(UOJSSO::displayName($provider->name)) ?>登录，学号为 <strong><?= HTML::escape($pending['identity']['student_id']) ?></strong>。</p>
<p>本站已经有一位用户名为 <strong><?= $username ?></strong> 的用户。如果这是你以前注册的账号，请输入它的密码完成绑定，之后即可直接通过<?= HTML::escape(UOJSSO::displayName($provider->name)) ?>登录；如果不是，请联系管理员处理。</p>
<form id="form-bind" class="form-horizontal" method="post">
	<div id="div-password" class="form-group">
		<label for="input-password" class="col-sm-2 control-label"><?= UOJLocale::get('password') ?></label>
		<div class="col-sm-3">
			<input type="password" class="form-control" id="input-password" name="password" placeholder="<?= UOJLocale::get('enter your password') ?>" maxlength="20" />
			<span class="help-block" id="help-password"></span>
		</div>
	</div>
	<div class="form-group">
		<div class="col-sm-offset-2 col-sm-3">
			<button type="submit" id="button-submit" class="btn btn-secondary">绑定</button>
		</div>
	</div>
</form>

<script type="text/javascript">
$(document).ready(function() {
	$('#form-bind').submit(function(e) {
		e.preventDefault();
		if (!getFormErrorAndShowHelp('password', validatePassword)) {
			return;
		}
		$.post(window.location.pathname, {
			_token : "<?= crsf_token() ?>",
			bind : '',
			password : md5($('#input-password').val(), "<?= getPasswordClientSalt() ?>")
		}, function(msg) {
			if (msg == 'ok') {
				window.location.href = '/';
			} else if (msg == 'failed') {
				$('#div-password').addClass('has-error');
				$('#help-password').html('密码错误。');
			} else if (msg == 'banned') {
				$('#div-password').addClass('has-error');
				$('#help-password').html('该用户已被封停，请联系管理员。');
			} else if (msg == 'bound') {
				$('#div-password').addClass('has-error');
				$('#help-password').html('该用户或该统一身份认证账号已被绑定，请联系管理员。');
			} else {
				window.location.href = '/login';
			}
		});
	});
});
</script>
<?php echoUOJPageFooter() ?>
