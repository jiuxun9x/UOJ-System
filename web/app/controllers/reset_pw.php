<?php
	if (!isset($_GET['p'])) {
		become404Page();
	}
	// the link while it works: one of the old kind, a used one, one asked for again since or one
	// older than half an hour does not
	$reset = passwordResetFind($_GET['p']);
	function resetPassword() {
		global $reset;
		if (!crsf_check()) {
			return '页面已过期，请刷新后重试';
		}
		if ($reset == null) {
			return '链接无效或已过期，请重新申请找回密码';
		}
		if (!isset($_POST['newPW']) || !validatePassword($_POST['newPW'])) {
			return '操作失败，无效密码';
		}
		$user = queryUser($reset['username']);
		if ($user == null || !passwordResetUse($reset, $_POST['newPW'])) {
			return '链接无效或已过期，请重新申请找回密码';
		}
		auditLog('user.password_reset', 'user', $user['username'], null, null, $user);
		return 'ok';
	}
	if (isset($_POST['reset'])) {
		die(resetPassword());
	}
	if ($reset == null) {
		becomeMsgPage('<div class="text-center" id="reset-link-invalid"><h2>这个链接无效或已过期</h2>'
			. '<p>重设密码的链接 ' . passwordResetMinutes() . ' 分钟内有效，只能用一次；再申请一次，之前的链接也就作废了。'
			. '<a href="/forgot-password">重新申请</a></p></div>');
	}
?>
<?php
	$REQUIRE_LIB['dialog'] = '';
	$REQUIRE_LIB['md5'] = '';
?>
<?php echoUOJPageHeader('更改密码') ?>
<h2 class="page-header">更改密码</h2>
<form id="form-reset" class="form-horizontal">
	<div id="div-password" class="form-group">
		<label for="input-password" class="col-sm-2 control-label">新密码</label>
		<div class="col-sm-3">
			<input type="password" class="form-control" id="input-password" name="password" placeholder="输入新密码" maxlength="20" />
			<input type="password" class="form-control top-buffer-sm" id="input-confirm_password" placeholder="再次输入新密码" maxlength="20" />
			<span class="help-block" id="help-password"></span>
		</div>
  </div>
  <div class="form-group">
	<div class="col-sm-offset-2 col-sm-3">
	  <button type="submit" id="button-submit" class="btn btn-secondary">提交</button>
	</div>
  </div>
</form>

<script type="text/javascript">
function validateResetPwPost() {
	var ok = true;
	ok &= getFormErrorAndShowHelp('password', validateSettingPassword);
	return ok;
}
$(document).ready(function() {
	$('#form-reset').submit(function(e) {
		if (!validateResetPwPost()) {
			return false;
		}
		$.post(<?= json_encode($_SERVER['REQUEST_URI']) ?>, {
			reset : '',
			_token : "<?= crsf_token() ?>",
			newPW : md5($('#input-password').val(), "<?= getPasswordClientSalt() ?>")
		}, function(res) {
			if (res == 'ok') {
				BootstrapDialog.show({
					title   : '提示',
					message : '密码更改成功',
					type    : BootstrapDialog.TYPE_SUCCESS,
					buttons: [{
						label: '好的',
						action: function(dialog) {
							dialog.close();
						}
					}],
					onhidden : function(dialog) {
						window.location.href = '/login';
					}
				});
			} else {
				BootstrapDialog.show({
					title   : '提示',
					message : res,
					type	: BootstrapDialog.TYPE_DANGER,
					buttons: [{
							label: '好的',
							action: function(dialog) {
								dialog.close();
							}
					}]
				});
			}
		});
		return false;
	});
});
</script>
<?php echoUOJPageFooter() ?>
