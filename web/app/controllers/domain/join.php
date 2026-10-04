<?php
	// Joining a domain with an invitation. The address of this page names no domain, and an
	// invitation that is not good is refused the same way whatever is wrong with it, so that
	// nobody learns from here which domains exist.
	//
	// An invitation link carries its token behind the "#", which browsers do not send: it does
	// not end up in the logs of the web server or of a proxy.
	$error = '';
	if (Auth::check()) {
		$error = domainHandleForms(array(
			'redeem' => function() {
				global $myUser;
				$domain = domainRedeemInvite(isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '', $myUser);
				if ($domain === null) {
					return '邀请无效或已过期。如果你已经是成员，请直接从“域”里进入。';
				}
				domainFlash('你已加入这个域。');
				redirectTo(domainUrl($domain));
			}
		));
	}
?>
<?php echoUOJPageHeader('凭邀请加入') ?>
<div class="row justify-content-center">
	<div class="col-md-8 col-lg-6">
		<div class="card">
			<div class="card-body">
				<h3 class="card-title">凭邀请加入域</h3>
				<?php echoDomainError($error) ?>
				<?php if (Auth::check()): ?>
				<p class="text-muted">打开老师给的邀请链接会自动填好下面的邀请，也可以把邀请粘贴进来。</p>
				<form method="post" id="form-redeem-invite">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="redeem" />
					<div class="form-group">
						<label for="input-invite-token">邀请</label>
						<input type="text" class="form-control uoj-domain-secret" id="input-invite-token" name="token" maxlength="64" required="required" autocomplete="off" />
					</div>
					<button type="submit" class="btn btn-primary" id="button-redeem-invite">加入</button>
					<a class="btn btn-link" href="/domains">返回</a>
				</form>
				<?php else: ?>
				<p>加入域需要先登录。登录后回到这个页面，邀请会自动填好。</p>
				<a class="btn btn-primary" href="/login">登录</a>
				<?php endif ?>
			</div>
		</div>
	</div>
</div>
<script type="text/javascript">
$(document).ready(function() {
	// the invitation travels behind the "#"; it is taken out of the address at once, and kept
	// for this tab only, so that it survives logging in
	var token = location.hash.replace(/^#/, '');
	try {
		if (token) {
			sessionStorage.setItem('uoj_domain_invite', token);
			history.replaceState(null, '', location.pathname);
		} else {
			token = sessionStorage.getItem('uoj_domain_invite') || '';
		}
	} catch (e) {
	}
	if (token && $('#input-invite-token').val() === '') {
		$('#input-invite-token').val(token);
	}
	$('#form-redeem-invite').submit(function() {
		try {
			sessionStorage.removeItem('uoj_domain_invite');
		} catch (e) {
		}
	});
});
</script>
<?php echoUOJPageFooter() ?>
