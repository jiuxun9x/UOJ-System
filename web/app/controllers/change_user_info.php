<?php
	if (!Auth::check()) {
		redirectToLogin();
	}
	function handlePost() {
		global $myUser;
		if (!crsf_check()) {
			return '页面已过期，请刷新后重试。';
		}
		// a user who logs in through the single sign-on has no password to ask for
		if (hasUsablePassword($myUser)) {
			if (!isset($_POST['old_password'])) {
				return '无效表单';
			}
			$old_password = $_POST['old_password'];
			if (!validatePassword($old_password) || !checkPassword($myUser, $old_password)) {
				return "失败：密码错误。";
			}
		}
		$nickname = isset($_POST['nickname']) ? $_POST['nickname'] : $myUser['nickname'];
		if (!validateNickname($nickname)) {
			return "失败：无效别名。别名不超过 20 个字符，且不能包含 < > & \" ' @ \\ 和括号。";
		}
		$new_username = isset($_POST['username']) && $_POST['username'] !== '' ? $_POST['username'] : $myUser['username'];
		if ($new_username !== $myUser['username']) {
			$err = usernameChangeRefusedReason($myUser);
			if ($err === '') {
				$err = usernameUnavailableReason($new_username, $myUser);
			}
			if ($err !== '') {
				return "失败：{$err}。";
			}
		}
		if ($_POST['ptag']) {
			$password = $_POST['password'];
			if (!validatePassword($password)) {
				return "失败：无效密码。";
			}
			setUserPassword($myUser['username'], $password);
		}
		DB::update("update user_info set nickname = '".DB::escape($nickname)."' where username = '{$myUser['username']}'");

		$email = $_POST['email'];
		if (!validateEmail($email)) {
			return "失败：无效电子邮箱。";
		}
		$esc_email = DB::escape($email);
		DB::update("update user_info set email = '$esc_email' where username = '{$myUser['username']}'");

		if ($_POST['Qtag']) {
			$qq = $_POST['qq'];
			if (!validateQQ($qq)) {
				return "失败：无效QQ。";
			}
			$esc_qq = DB::escape($qq);
			DB::update("update user_info set qq = '$esc_qq' where username = '{$myUser['username']}'");
		} else {
			DB::update("update user_info set QQ = NULL where username = '{$myUser['username']}'");
		}
		if ($_POST['sex'] == "U" || $_POST['sex'] == 'M' || $_POST['sex'] == 'F') {
			$sex = $_POST['sex'];
			$esc_sex = DB::escape($sex);
			DB::update("update user_info set sex = '$esc_sex' where username = '{$myUser['username']}'");
		}
		
		if (validateMotto($_POST['motto'])) {
			$esc_motto = DB::escape($_POST['motto']);
			DB::update("update user_info set motto = '$esc_motto' where username = '{$myUser['username']}'");
		}
		
		// the username goes last: everything above was stored under the old one
		if ($new_username !== $myUser['username']) {
			$err = renameUser($myUser, $new_username, $myUser);
			if ($err !== '') {
				return "失败：{$err}。";
			}
			Auth::login($new_username);
		}
		
		return "ok";
	}
	if (isset($_POST['change'])) {
		die(handlePost());
	}
	$username_change_refused = usernameChangeRefusedReason($myUser);
?>
<?php
	$REQUIRE_LIB['dialog'] = '';
	$REQUIRE_LIB['md5'] = '';
?>
<?php echoUOJPageHeader(UOJLocale::get('modify my profile')) ?>
<h2 class="page-header"><?= UOJLocale::get('modify my profile') ?></h2>
<form id="form-update" class="form-horizontal">
	<?php if (hasUsablePassword($myUser)): ?>
	<h4><?= UOJLocale::get('please enter your password for authorization') ?></h4>
	<div id="div-old_password" class="form-group">
		<label for="input-old_password" class="col-sm-2 control-label"><?= UOJLocale::get('password') ?></label>
		<div class="col-sm-3">
			<input type="password" class="form-control" name="old_password" id="input-old_password" placeholder="<?= UOJLocale::get('enter your password') ?>" maxlength="20" />
			<span class="help-block" id="help-old_password"></span>
		</div>
	</div>
	<?php endif ?>
	<h4><?= UOJLocale::get('please enter your new profile') ?></h4>
	<div id="div-username" class="form-group">
		<label for="input-username" class="col-sm-2 control-label"><?= UOJLocale::get('username') ?></label>
		<div class="col-sm-3">
			<input type="text" class="form-control" name="username" id="input-username" value="<?= $myUser['username'] ?>" maxlength="20"<?= $username_change_refused !== '' ? ' disabled="disabled"' : '' ?> />
			<span class="help-block" id="help-username"><?= $username_change_refused !== '' ? HTML::escape($username_change_refused) : '修改用户名后，旧用户名会为你保留，别人不能使用。' ?></span>
		</div>
	</div>
	<div id="div-nickname" class="form-group">
		<label for="input-nickname" class="col-sm-2 control-label">别名</label>
		<div class="col-sm-3">
			<input type="text" class="form-control" name="nickname" id="input-nickname" value="<?= HTML::escape($myUser['nickname']) ?>" maxlength="20" />
			<span class="help-block" id="help-nickname">别名会显示为“别名（用户名）”，留空则只显示用户名。</span>
		</div>
	</div>
	<div id="div-password" class="form-group">
		<label for="input-password" class="col-sm-2 control-label"><?= UOJLocale::get('new password') ?></label>
		<div class="col-sm-3">
			<input type="password" class="form-control" id="input-password" name="password" placeholder="<?= UOJLocale::get('enter your new password') ?>" maxlength="20" />
			<input type="password" class="form-control top-buffer-sm" id="input-confirm_password" placeholder="<?= UOJLocale::get('re-enter your new password') ?>" maxlength="20" />
			<span class="help-block" id="help-password"><?= UOJLocale::get('leave it blank if you do not want to change the password') ?></span>
		</div>
	</div>
	<div id="div-email" class="form-group">
		<label for="input-email" class="col-sm-2 control-label"><?= UOJLocale::get('email') ?></label>
		<div class="col-sm-3">
			<input type="email" class="form-control" name="email" id="input-email" value="<?=$myUser['email']?>" placeholder="<?= UOJLocale::get('enter your email') ?>" maxlength="50" />
			<span class="help-block" id="help-email"></span>
		</div>
	</div>
	<div id="div-qq" class="form-group">
		<label for="input-qq" class="col-sm-2 control-label"><?= UOJLocale::get('QQ') ?></label>
		<div class="col-sm-3">
			<input type="text" class="form-control" name="qq" id="input-qq" value="<?= $myUser['qq'] != 0 ? $myUser['qq'] : '' ?>" placeholder="<?= UOJLocale::get('enter your QQ') ?>" maxlength="50" />
			<span class="help-block" id="help-qq"></span>
		</div>
	</div>
	<div id="div-sex" class="form-group">
		<label for="input-sex" class="col-sm-2 control-label"><?= UOJLocale::get('sex') ?></label>
		<div class="col-sm-3">
			<select class="form-control" id="input-sex"  name="sex">
				<option value="U"<?= Auth::user()['sex'] == 'U' ? ' selected="selected"' : ''?>><?= UOJLocale::get('refuse to answer') ?></option>
				<option value="M"<?= Auth::user()['sex'] == 'M' ? ' selected="selected"' : ''?>><?= UOJLocale::get('male') ?></option>
				<option value="F"<?= Auth::user()['sex'] == 'F' ? ' selected="selected"' : ''?>><?= UOJLocale::get('female') ?></option>
			</select>
		</div>
	</div>
	<div id="div-motto" class="form-group">
		<label for="input-motto" class="col-sm-2 control-label"><?= UOJLocale::get('motto') ?></label>
		<div class="col-sm-3">
			<textarea class="form-control" id="input-motto"  name="motto"><?=HTML::escape($myUser['motto'])?></textarea>
			<span class="help-block" id="help-motto"></span>
		</div>
	</div>
	<div class="form-group">
    	<div class="col-sm-offset-2 col-sm-3">
	      <p class="form-control-static"><strong><?= UOJLocale::get('change avatar help') ?></strong></p>
	    </div>
	</div>
	<div class="form-group">
		<div class="col-sm-offset-2 col-sm-3">
			<button type="submit" id="button-submit" class="btn btn-secondary"><?= UOJLocale::get('submit') ?></button>
		</div>
	</div>
</form>

<script type="text/javascript">
	function validateUpdatePost() {
		var ok = true;
		ok &= getFormErrorAndShowHelp('email', validateEmail);
		if ($('#input-old_password').length > 0)
			ok &= getFormErrorAndShowHelp('old_password', validatePassword);
		if (!$('#input-username').prop('disabled'))
			ok &= getFormErrorAndShowHelp('username', validateUsername);

		if ($('#input-password').val().length > 0)
			ok &= getFormErrorAndShowHelp('password', validateSettingPassword);
		if ($('#input-qq').val().length > 0)
			ok &= getFormErrorAndShowHelp('qq', validateQQ);
		ok &= getFormErrorAndShowHelp('motto', validateMotto);
		return ok;
	}
	function submitUpdatePost() {
		if (!validateUpdatePost())
			return;
		$.post('/user/modify-profile', {
			change   : '',
			_token   : "<?= crsf_token() ?>",
			username : $('#input-username').prop('disabled') ? '' : $('#input-username').val(),
			nickname : $('#input-nickname').val(),
			etag     : $('#input-email').val().length,
			ptag     : $('#input-password').val().length,
			Qtag     : $('#input-qq').val().length,
			email    : $('#input-email').val(),
			password : md5($('#input-password').val(), "<?= getPasswordClientSalt() ?>"),
			old_password : $('#input-old_password').length > 0 ? md5($('#input-old_password').val(), "<?= getPasswordClientSalt() ?>") : '',
			qq       : $('#input-qq').val(),
			sex      : $('#input-sex').val(),
			motto    : $('#input-motto').val()
		}, function(msg) {
			if (msg == 'ok') {
				BootstrapDialog.show({
					title   : '修改成功',
					message : '用户信息修改成功',
					type    : BootstrapDialog.TYPE_SUCCESS,
					buttons : [{
						label: '好的',
						action: function(dialog) {
							dialog.close();
						}
					}],
					onhidden : function(dialog) {
						window.location.href = '/user/profile/' + $('#input-username').val();
					}
				});
			} else {
				BootstrapDialog.show({
					title   : '修改失败',
					message : msg,
					type    : BootstrapDialog.TYPE_DANGER,
					buttons: [{
						label: '好的',
						action: function(dialog) {
							dialog.close();
						}
					}],
				});
			}
		});
	}
	$(document).ready(function(){$('#form-update').submit(function(e) {submitUpdatePost();e.preventDefault();});
	});
</script>
<?php echoUOJPageFooter() ?>

