<?php
	if (!Auth::check()) {
		redirectToLogin();
	}
	if (!can($myUser, 'domain.create')) {
		become403Page();
	}
	
	$settings = array('name' => '', 'slug' => '', 'description' => '', 'type' => 'course', 'visibility' => 'private', 'join_method' => 'none');
	foreach ($settings as $key => $default) {
		if (isset($_POST[$key]) && is_string($_POST[$key])) {
			$settings[$key] = $_POST[$key];
		}
	}
	$error = domainHandleForms(array(
		'create' => function() use ($settings) {
			global $myUser;
			$err = domainCreate($myUser, $settings);
			if ($err === '') {
				domainFlash('域已创建。接下来可以在“成员”里添加学生，或在“设置”里调整加入方式。');
				redirectTo("/d/{$settings['slug']}");
			}
			return $err;
		}
	));
?>
<?php echoUOJPageHeader('创建域') ?>
<h2 class="page-header">创建域</h2>
<?php echoDomainError($error) ?>
<?php uojIncludeView('domain-settings-form', array('settings' => $settings, 'is_new' => true)) ?>
<?php echoUOJPageFooter() ?>
