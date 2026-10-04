<?php
	requirePHPLib('form');
	requirePHPLib('judger');
	
	if (!can($myUser, 'site.manage')) {
		become403Page();
	}
	$can_manage_roles = can($myUser, 'user.manage_roles');
	$can_manage_judgers = can($myUser, 'judger.manage');
	
	$user_form = new UOJForm('user');
	$user_form->addInput('username', 'text', '用户名', '',
		function ($username) {
			if (!validateUsername($username)) {
				return '用户名不合法';
			}
			if (!queryUser($username)) {
				return '用户不存在';
			}
			return '';
		},
		null
	);
	$options = array(
		'banneduser' => '设为封禁用户',
		'normaluser' => '设为普通用户（解除封禁或取消系统管理员）'
	);
	if ($can_manage_roles) {
		$options['superuser'] = '设为系统管理员';
		foreach (grantableRoles() as $role => $role_name) {
			$options["grant:$role"] = "授予角色：$role_name";
			$options["revoke:$role"] = "收回角色：$role_name";
		}
	}
	$user_form->addSelect('op-type', $options, '操作类型', '');
	$user_form->handle = function() {
		global $myUser;
		
		$err = changeUserStanding($myUser, queryUser($_POST['username']), $_POST['op-type']);
		if ($err !== '') {
			becomeMsgPage(HTML::escape($err));
		}
	};
	$user_form->runAtServer();
	
	// A user changes their own username in their profile. This is for the users who can not:
	// the ones the school named, and whoever sits on a name that belongs to somebody else.
	if (can($myUser, 'user.rename')) {
		$rename_form = new UOJForm('rename');
		$rename_form->addInput('rename_username', 'text', '用户名', '',
			function ($username, &$vdata) {
				if (!validateUsername($username) || !($vdata['user'] = queryUser($username))) {
					return '用户不存在';
				}
				return '';
			},
			null
		);
		$rename_form->addInput('rename_new_username', 'text', '新用户名', '',
			function ($username, &$vdata) {
				return usernameUnavailableReason($username, isset($vdata['user']) ? $vdata['user'] : null, array('admin' => true));
			},
			null
		);
		$rename_form->handle = function(&$vdata) {
			global $myUser;
			$err = renameUser($vdata['user'], $_POST['rename_new_username'], $myUser, array('admin' => true));
			if ($err !== '') {
				becomeMsgPage(HTML::escape($err));
			}
		};
		$rename_form->runAtServer();
	}
	
	$blog_link_contests = new UOJForm('blog_link_contests');
	$blog_link_contests->addInput('blog_id', 'text', '博客ID', '',
		function ($x) {
			if (!validateUInt($x)) {
				return 'ID不合法';
			}
			if (!queryBlog($x)) {
				return '博客不存在';
			}
			return '';
		},
		null
	);
	$blog_link_contests->addInput('contest_id', 'text', '比赛ID', '',
		function ($x) {
			if (!validateUInt($x)) {
				return 'ID不合法';
			}
			if (!queryContest($x)) {
				return '比赛不存在';
			}
			return '';
		},
		null
	);
	$blog_link_contests->addInput('title', 'text', '标题', '',
		function ($x) {
			return '';
		},
		null
	);
	$options = array(
		'add' => '添加',
		'del' => '删除'
	);
	$blog_link_contests->addSelect('op-type', $options, '操作类型', '');
	$blog_link_contests->handle = function() {
		$blog_id = $_POST['blog_id'];
		$contest_id = $_POST['contest_id'];
		$str = DB::selectFirst(("select * from contests where id='${contest_id}'"));
		$all_config = json_decode($str['extra_config'], true);
		$config = $all_config['links'];

		$n = count($config);
		
		if ($_POST['op-type'] == 'add') {
			$row = array();
			$row[0] = $_POST['title'];
			$row[1] = $blog_id;
			$config[$n] = $row;
		}
		if ($_POST['op-type'] == 'del') {
			for ($i = 0; $i < $n; $i++) {
				if ($config[$i][1] == $blog_id) {
					$config[$i] = $config[$n - 1];
					unset($config[$n - 1]);
					break;
				}
			}
		}

		$all_config['links'] = $config;
		$str = json_encode($all_config);
		$str = DB::escape($str);
		DB::query("update contests set extra_config='${str}' where id='${contest_id}'");
	};
	$blog_link_contests->runAtServer();
	
	$blog_link_index = new UOJForm('blog_link_index');
	$blog_link_index->addInput('blog_id2', 'text', '博客ID', '',
		function ($x) {
			if (!validateUInt($x)) {
				return 'ID不合法';
			}
			if (!queryBlog($x)) {
				return '博客不存在';
			}
			return '';
		},
		null
	);
	$blog_link_index->addInput('blog_level', 'text', '置顶级别（删除不用填）', '0',
		function ($x) {
			if (!validateUInt($x)) {
				return '数字不合法';
			}
			if ($x > 3) {
				return '该级别不存在';
			}
			return '';
		},
		null
	);
	$options = array(
		'add' => '添加',
		'del' => '删除'
	);
	$blog_link_index->addSelect('op-type2', $options, '操作类型', '');
	$blog_link_index->handle = function() {
		$blog_id = $_POST['blog_id2'];
		$blog_level = $_POST['blog_level'];
		if ($_POST['op-type2'] == 'add') {
			if (DB::selectFirst("select * from important_blogs where blog_id = {$blog_id}")) {
				DB::update("update important_blogs set level = {$blog_level} where blog_id = {$blog_id}");
			} else {
				DB::insert("insert into important_blogs (blog_id, level) values ({$blog_id}, {$blog_level})");
			}
		}
		if ($_POST['op-type2'] == 'del') {
			DB::delete("delete from important_blogs where blog_id = {$blog_id}");
		}
	};
	$blog_link_index->runAtServer();
	
	$blog_deleter = new UOJForm('blog_deleter');
	$blog_deleter->addInput('blog_del_id', 'text', '博客ID', '',
		function ($x) {
			if (!validateUInt($x)) {
				return 'ID不合法';
			}
			if (!queryBlog($x)) {
				return '博客不存在';
			}
			return '';
		},
		null
	);
	$blog_deleter->handle = function() {
		deleteBlog($_POST['blog_del_id']);
	};
	$blog_deleter->runAtServer();

	$contest_submissions_deleter = new UOJForm('contest_submissions');
	$contest_submissions_deleter->addInput('contest_id', 'text', '比赛ID', '',
		function ($x) {
			if (!validateUInt($x)) {
				return 'ID不合法';
			}
			if (!queryContest($x)) {
				return '博客不存在';
			}
			return '';
		},
		null
	);
	$contest_submissions_deleter->handle = function() {
		$contest = queryContest($_POST['contest_id']);
		genMoreContestInfo($contest);
		
		$contest_problems = DB::selectAll("select problem_id from contests_problems where contest_id = {$contest['id']}");
		foreach ($contest_problems as $problem) {
			$submissions = DB::selectAll("select * from submissions where problem_id = {$problem['problem_id']} and submit_time < '{$contest['start_time_str']}'");
			foreach ($submissions as $submission) {
				$content = json_decode($submission['content'], true);
				unlink(UOJContext::storagePath().$content['file_name']);
				DB::delete("delete from submissions where id = {$submission['id']}");
				updateBestACSubmissions($submission['submitter'], $submission['problem_id']);
			}
		}
	};
	$contest_submissions_deleter->runAtServer();

	$custom_test_deleter = new UOJForm('custom_test_deleter');
	$custom_test_deleter->addInput('last', 'text', '删除末尾记录', '5',
		function ($x, &$vdata) {
			if (!validateUInt($x)) {
				return '不合法';
			}
			$vdata['last'] = $x;
			return '';
		},
		null
	);
	$custom_test_deleter->handle = function(&$vdata) {
		$all = DB::selectAll("select * from custom_test_submissions order by id asc limit {$vdata['last']}");
		foreach ($all as $submission) {
			$content = json_decode($submission['content'], true);
			unlink(UOJContext::storagePath().$content['file_name']);
		}
		DB::delete("delete from custom_test_submissions order by id asc limit {$vdata['last']}");
	};
	$custom_test_deleter->runAtServer();

	if ($can_manage_judgers) {
		$judger_adder = new UOJForm('judger_adder');
		$judger_adder->addInput('judger_adder_name', 'text', '评测机名称', '',
			function ($x, &$vdata) {
				if (!validateUsername($x)) {
					return '不合法';
				}
				if (DB::selectCount("select count(*) from judger_info where judger_name='$x'")!=0) {
					return '不合法';
				}
				$vdata['name'] = $x;
				return '';
			},
			null
		);
		$judger_adder->handle = function(&$vdata) {
			$password=uojRandString(32);
			DB::insert("insert into judger_info (judger_name,password) values('{$vdata['name']}','".judgerPasswordToStore($password)."')");
			auditLog('judger.add', 'judger', $vdata['name']);
			// only its hash is kept, so this is the one time the password can be shown
			becomeMsgPage('<p>评测机 <strong>' . $vdata['name'] . '</strong> 已添加，密码为 <code id="judger-password">' . $password . '</code>。</p><p>密码只显示这一次，请立即写入评测机的配置；遗失后只能删除评测机重新添加。</p><p><a href="/super-manage/judger">返回</a></p>');
		};
		$judger_adder->runAtServer();
	
		$judger_deleter = new UOJForm('judger_deleter');
		$judger_deleter->addInput('judger_deleter_name', 'text', '评测机名称', '',
			function ($x, &$vdata) {
				if (!validateUsername($x)) {
					return '不合法';
				}
				if (DB::selectCount("select count(*) from judger_info where judger_name='$x'")!=1) {
					return '不合法';
				}
				$vdata['name'] = $x;
				return '';
			},
			null
		);
		$judger_deleter->handle = function(&$vdata) {
			DB::delete("delete from judger_info where judger_name='{$vdata['name']}'");
			auditLog('judger.delete', 'judger', $vdata['name']);
		};
		$judger_deleter->runAtServer();

		// a judger that is switched off finishes what it is judging and is given nothing new
		$judger_switcher = new UOJForm('judger_switcher');
		$judger_switcher->addInput('judger_switcher_name', 'text', '评测机名称', '',
			function ($x, &$vdata) {
				if (!validateUsername($x)) {
					return '不合法';
				}
				if (DB::selectCount("select count(*) from judger_info where judger_name='$x'")!=1) {
					return '不合法';
				}
				$vdata['name'] = $x;
				return '';
			},
			null
		);
		$judger_switcher->handle = function(&$vdata) {
			DB::update("update judger_info set enabled = 1 - enabled where judger_name='{$vdata['name']}'");
			auditLog('judger.switch', 'judger', $vdata['name'], null, array('enabled' => (int)DB::selectFirst("select enabled from judger_info where judger_name='{$vdata['name']}'")['enabled']));
		};
		$judger_switcher->runAtServer();
	}

	$paste_deleter = new UOJForm('paste_deleter');
	$paste_deleter->addInput('paste_deleter_name', 'text', 'Paste ID', '',
		function ($x, &$vdata) {
			if (!is_string($x) || !preg_match('/^[0-9a-zA-Z]{1,20}$/', $x) || DB::selectCount("select count(*) from pastes where `index`='$x'")==0) {
				return '不合法';
			}
			$vdata['name'] = $x;
			return '';
		},
		null
	);
	$paste_deleter->handle = function(&$vdata) {
		DB::delete("delete from pastes where `index` = '${vdata['name']}'");
	};
	$paste_deleter->runAtServer();
	
	$judgerlist_cols = array('judger_name', 'enabled', 'last_heartbeat_at', 'version', 'timestampdiff(second, last_heartbeat_at, now()) as silent_seconds');
	$judgerlist_config = array();
	$judgerlist_header_row = <<<EOD
	<tr>
		<th>评测机名称</th>
		<th>状态</th>
		<th>最近响应</th>
		<th>版本</th>
		<th>正在评测</th>
	</tr>
EOD;
	$judgerlist_print_row = function($row) {
		if ($row['last_heartbeat_at'] === null) {
			$status = '从未连接';
		} elseif ($row['silent_seconds'] <= 30) {
			$status = '在线';
		} else {
			$status = '<span class="text-danger">离线</span>';
		}
		if (!$row['enabled']) {
			$status .= '，已停用';
		}
		// a judger that reports no version is too old to be given work
		$version = $row['version'] !== '' ? HTML::escape($row['version']) : '<span class="text-danger">未上报，需要升级</span>';
		$judging = array();
		foreach (DB::selectAll("select kind, target_id from submission_judgements where judger_name = '{$row['judger_name']}' and finished_at is null order by id desc limit 3") as $judgement) {
			$judging[] = $judgement['kind'] . ' #' . $judgement['target_id'];
		}
		$judging = join(', ', $judging);
		echo <<<EOD
			<tr>
				<td>{$row['judger_name']}</td>
				<td>{$status}</td>
				<td>{$row['last_heartbeat_at']}</td>
				<td>{$version}</td>
				<td>{$judging}</td>
			</tr>
EOD;
	};
	
	$banlist_cols = array('username', 'usergroup');
	$banlist_config = array();
	$banlist_header_row = <<<EOD
	<tr>
		<th>用户名</th>
	</tr>
EOD;
	$banlist_print_row = function($row) {
		$hislink = getUserLink($row['username']);
		echo <<<EOD
			<tr>
				<td>${hislink}</td>
			</tr>
EOD;
	};
	
	$cur_tab = isset($_GET['tab']) ? $_GET['tab'] : 'users';
	
	$tabs_info = array(
		'users' => array(
			'name' => '用户操作',
			'url' => "/super-manage/users"
		),
		'blogs' => array(
			'name' => '博客管理',
			'url' => "/super-manage/blogs"
		),
		'submissions' => array(
			'name' => '提交记录',
			'url' => "/super-manage/submissions"
		),
		'custom-test' => array(
			'name' => '自定义测试',
			'url' => '/super-manage/custom-test'
		),
		'click-zan' => array(
			'name' => '点赞管理',
			'url' => '/super-manage/click-zan'
		),
		'search' => array(
			'name' => '搜索管理',
			'url' => '/super-manage/search'
		),
		'judger' => array(
			'name' => '评测机管理',
			'url' => '/super-manage/judger'
		),
		'paste' => array(
			'name' => 'Paste管理',
			'url' => '/super-manage/paste'
		)
	);
	if (!$can_manage_judgers) {
		unset($tabs_info['judger']);
	}
	if (can($myUser, 'site.manage_settings')) {
		$tabs_info['settings'] = array(
			'name' => '站点设置',
			'url' => '/super-manage/settings'
		);
	}
	if (can($myUser, 'audit.view')) {
		$tabs_info['audit'] = array(
			'name' => '审计日志',
			'url' => '/super-manage/audit'
		);
	}
	
	if (!isset($tabs_info[$cur_tab])) {
		become404Page();
	}
	
	// The settings of the site. A box that is not ticked is not posted, which switches it
	// off; a secret that is left empty stays what it is.
	$site_settings_error = '';
	if ($cur_tab === 'settings') {
		$site_settings_error = domainHandleForms(array(
			'site_settings' => function() {
				global $myUser;
				if (!can($myUser, 'site.manage_settings')) {
					return '没有权限';
				}
				$posted = isset($_POST['setting']) && is_array($_POST['setting']) ? $_POST['setting'] : array();
				$values = array();
				foreach (siteSettings() as $name => $setting) {
					if ($setting['type'] === 'switch') {
						$values[$name] = isset($posted[$name]);
					} elseif (isset($posted[$name]) && !($setting['type'] === 'secret' && $posted[$name] === '')) {
						$values[$name] = $posted[$name];
					}
				}
				$err = setSiteSettings($values, $myUser);
				if ($err === '') {
					domainFlash('设置已保存。');
				}
				return $err;
			},
			// a mail to whoever asks, to see whether the mailbox works
			'test_mail' => function() {
				global $myUser;
				if (!can($myUser, 'site.manage_settings')) {
					return '没有权限';
				}
				$to = isset($_POST['to']) && is_string($_POST['to']) ? trim($_POST['to']) : '';
				if (!validateEmail($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
					return '收件地址不是一个邮箱地址';
				}
				$oj_name = HTML::escape(UOJConfig::$data['profile']['oj-name']);
				$err = UOJMail::send(array($to), UOJConfig::$data['profile']['oj-name-short'] . ' 测试邮件', "<p>这是一封测试邮件。收到它说明 {$oj_name} 的发信邮箱设置正确。</p>");
				auditLog('site.test_mail', 'mail', 'test', null, array('to' => $to, 'sent' => $err === ''));
				if ($err !== '') {
					return '发送失败：' . $err;
				}
				domainFlash("测试邮件已发往 {$to}，请到邮箱里确认。");
				return '';
			}
		));
	}
?>
<?php
	requireLib('hljs');
	requireLib('morris');
?>
<?php echoUOJPageHeader('系统管理') ?>
<div class="row">
	<div class="col-sm-3">
		<?= HTML::tablist($tabs_info, $cur_tab, 'nav-pills flex-column') ?>
	</div>
	
	<div class="col-sm-9">
		<?php if ($cur_tab === 'users'): ?>
			<?php $user_form->printHTML(); ?>
			<?php if (isset($rename_form)): ?>
			<h3>修改用户名</h3>
			<?php $rename_form->printHTML(); ?>
			<?php endif ?>
			<h3>角色名单</h3>
			<table class="table table-bordered table-hover table-striped table-text-center">
				<thead><tr><th>用户名</th><th>角色</th><th>授予者</th><th>授予时间</th></tr></thead>
				<tbody>
				<?php foreach (DB::selectAll("select username from user_info where usergroup = 'S' order by username") as $row): ?>
					<tr><td><?= getUserLink($row['username']) ?></td><td>系统管理员</td><td></td><td></td></tr>
				<?php endforeach ?>
				<?php foreach (DB::selectAll("select * from user_roles order by role, username") as $row): ?>
					<tr>
						<td><?= getUserLink($row['username']) ?></td>
						<td><?= HTML::escape(isset(grantableRoles()[$row['role']]) ? grantableRoles()[$row['role']] : $row['role']) ?></td>
						<td><?= getUserLink($row['granted_by']) ?></td>
						<td><?= $row['granted_at'] ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
			<h3>封禁名单</h3>
			<?php echoLongTable($banlist_cols, 'user_info', "usergroup='B'", '', $banlist_header_row, $banlist_print_row, $banlist_config) ?>
		<?php elseif ($cur_tab === 'blogs'): ?>
			<div>
				<h4>添加到比赛链接</h4>
				<?php $blog_link_contests->printHTML(); ?>
			</div>

			<div>
				<h4>添加到公告</h4>
				<?php $blog_link_index->printHTML(); ?>
			</div>
		
			<div>
				<h4>删除博客</h4>
				<?php $blog_deleter->printHTML(); ?>
			</div>
		<?php elseif ($cur_tab === 'submissions'): ?>
			<div>
				<h4>删除赛前提交记录</h4>
				<?php $contest_submissions_deleter->printHTML(); ?>
			</div>
			<div>
				<h4>测评失败的提交记录</h4>
				<?php echoSubmissionsList("result_error = 'Judgement Failed'", 'order by id desc', array('result_hidden' => ''), $myUser); ?>
			</div>
		<?php elseif ($cur_tab === 'custom-test'): ?>
		<?php $custom_test_deleter->printHTML() ?>
		<?php
			$submissions_pag = new Paginator(array(
				'col_names' => array('*'),
				'table_name' => 'custom_test_submissions',
				'cond' => '1',
				'tail' => 'order by id asc',
				'page_len' => 5
			));
			foreach ($submissions_pag->get() as $submission) {
				$problem = queryProblemBrief($submission['problem_id']);
				$submission_result = json_decode($submission['result'], true);
				echo '<dl class="dl-horizontal">';
				echo '<dt>id</dt>';
				echo '<dd>', "#{$submission['id']}", '</dd>';
				echo '<dt>problem_id</dt>';
				echo '<dd>', "#{$submission['problem_id']}", '</dd>';
				echo '<dt>submit time</dt>';
				echo '<dd>', $submission['submit_time'], '</dd>';
				echo '<dt>submitter</dt>';
				echo '<dd>', $submission['submitter'], '</dd>';
				echo '<dt>judge_time</dt>';
				echo '<dd>', $submission['judge_time'], '</dd>';
				echo '</dl>';
				echoSubmissionContent($submission, getProblemCustomTestRequirement($problem));
				echoCustomTestSubmissionDetails($submission_result['details'], "submission-{$submission['id']}-details");
			}
		?>
		<?= $submissions_pag->pagination() ?>
		<?php elseif ($cur_tab === 'click-zan'): ?>
		没写好QAQ
		<?php elseif ($cur_tab === 'search'): ?>
		<h2 class="text-center">一周搜索情况</h2>
		<div id="search-distribution-chart-week" style="height: 250px;"></div>
		<script type="text/javascript">
			new Morris.Line({
				element: 'search-distribution-chart-week',
				data: <?= json_encode(DB::selectAll("select DATE_FORMAT(created_at, '%Y-%m-%d %h:00'), count(*) from search_requests  where created_at > now() - interval 1 week group by DATE_FORMAT(created_at, '%Y-%m-%d %h:00')")) ?>,
				xkey: "DATE_FORMAT(created_at, '%Y-%m-%d %h:00')",
				ykeys: ["count(*)"],
				labels: ['number'],
				resize: true
			});
		</script>
		
		<h2 class="text-center">一月搜索情况</h2>
		<div id="search-distribution-chart-month" style="height: 250px;"></div>
		<script type="text/javascript">
			new Morris.Line({
				element: 'search-distribution-chart-month',
				data: <?= json_encode(DB::selectAll("select DATE_FORMAT(created_at, '%Y-%m-%d'), count(*) from search_requests  where created_at > now() - interval 1 week group by DATE_FORMAT(created_at, '%Y-%m-%d')")) ?>,
				xkey: "DATE_FORMAT(created_at, '%Y-%m-%d')",
				ykeys: ["count(*)"],
				labels: ['number'],
				resize: true
			});
		</script>
		
		<?php echoLongTable(array('*'), 'search_requests', "1", 'order by id desc',
			'<tr><th>id</th><th>created_at</th><th>remote_addr</th><th>type</th><th>q</th><tr>',
			function($row) {
				echo '<tr>';
				echo '<td>', $row['id'], '</td>';
				echo '<td>', $row['created_at'], '</td>';
				echo '<td>', $row['remote_addr'], '</td>';
				echo '<td>', $row['type'], '</td>';
				echo '<td>', HTML::escape($row['q']), '</td>';
				echo '</tr>';
			}, array(
				'page_len' => 1000
			))
		?>
		<?php elseif ($cur_tab === 'judger'): ?>
			<div>
				<h4>添加评测机</h4>
				<?php $judger_adder->printHTML(); ?>
			</div>
			<div>
				<h4>删除评测机</h4>
				<?php $judger_deleter->printHTML(); ?>
			</div>
			<div>
				<h4>停用/启用评测机</h4>
				<?php $judger_switcher->printHTML(); ?>
			</div>
			<h3>评测机列表</h3>
			<?php echoLongTable($judgerlist_cols, 'judger_info', "1=1", '', $judgerlist_header_row, $judgerlist_print_row, $judgerlist_config) ?>
		<?php elseif ($cur_tab === 'settings'): ?>
			<?php $flash = domainTakeFlash(); ?>
			<?php if ($flash): ?>
			<div class="alert alert-<?= $flash[0] ?>" role="alert"><?= HTML::escape($flash[1]) ?></div>
			<?php endif ?>
			<?php echoDomainError($site_settings_error) ?>
			<h3>站点设置</h3>
			<?php
				$setting_groups = array();
				foreach (siteSettings() as $name => $setting) {
					$setting_groups[$setting['group']][$name] = $setting;
				}
				$mail = UOJMail::settings();
			?>
			<form method="post" id="form-site-settings" class="text-left">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="site_settings" />
				<?php foreach ($setting_groups as $group => $group_settings): ?>
				<div class="card mb-3">
					<div class="card-header"><?= $group ?></div>
					<div class="card-body">
						<?php if ($group === '发信邮箱'): ?>
						<p class="text-muted small" id="mail-source">
							<?php if ($mail['source'] === 'site'): ?>
							现在用的是这里设置的邮箱。
							<?php elseif (UOJMail::configured()): ?>
							现在用的是配置文件里的邮箱（<?= HTML::escape($mail['username']) ?>）。在这里填写 SMTP 服务器后，以这里的为准。
							<?php else: ?>
							还没有设置发信邮箱：找回密码和告警邮件都发不出去。
							<?php endif ?>
						</p>
						<?php endif ?>
						<?php foreach ($group_settings as $name => $setting): ?>
						<?php
							$input_id = 'input-setting-' . str_replace('.', '-', $name);
							$value = siteSetting($name);
						?>
						<?php if ($setting['type'] === 'switch'): ?>
						<div class="custom-control custom-switch mb-3">
							<input type="checkbox" class="custom-control-input" id="<?= $input_id ?>" name="setting[<?= $name ?>]"<?= $value ? ' checked="checked"' : '' ?> />
							<label class="custom-control-label" for="<?= $input_id ?>"><?= $setting['label'] ?></label>
							<small class="form-text text-muted"><?= $setting['help'] ?></small>
						</div>
						<?php else: ?>
						<div class="form-group row">
							<label class="col-sm-3 col-form-label" for="<?= $input_id ?>"><?= $setting['label'] ?></label>
							<div class="col-sm-9">
								<?php if ($setting['type'] === 'choice'): ?>
								<select class="form-control" id="<?= $input_id ?>" name="setting[<?= $name ?>]">
									<?php foreach ($setting['choices'] as $choice => $choice_label): ?>
									<option value="<?= $choice ?>"<?= $value === $choice ? ' selected="selected"' : '' ?>><?= $choice_label ?></option>
									<?php endforeach ?>
								</select>
								<?php elseif ($setting['type'] === 'number'): ?>
								<input type="number" class="form-control" id="<?= $input_id ?>" name="setting[<?= $name ?>]" min="<?= $setting['min'] ?>" max="<?= $setting['max'] ?>" value="<?= $value ?>" />
								<?php elseif ($setting['type'] === 'secret'): ?>
								<input type="password" class="form-control" id="<?= $input_id ?>" name="setting[<?= $name ?>]" maxlength="<?= $setting['max'] ?>" value="" autocomplete="new-password" placeholder="<?= $value !== '' ? '已设置，留空表示不修改' : '未设置' ?>" />
								<?php else: ?>
								<input type="text" class="form-control" id="<?= $input_id ?>" name="setting[<?= $name ?>]" maxlength="<?= $setting['max'] ?>" value="<?= HTML::escape($value) ?>" />
								<?php endif ?>
								<?php if ($setting['help'] !== ''): ?>
								<small class="form-text text-muted"><?= $setting['help'] ?></small>
								<?php endif ?>
							</div>
						</div>
						<?php endif ?>
						<?php endforeach ?>
					</div>
				</div>
				<?php endforeach ?>
				<button type="submit" class="btn btn-primary" id="button-save-site-settings">保存</button>
			</form>
			<hr />
			<form method="post" class="form-inline" id="form-test-mail">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="test_mail" />
				<label class="mr-2" for="input-test-mail-to">发一封测试邮件到</label>
				<input type="text" class="form-control mr-2" id="input-test-mail-to" name="to" value="<?= HTML::escape($myUser['email']) ?>" style="min-width:18em" />
				<button type="submit" class="btn btn-outline-primary" id="button-test-mail">发送</button>
				<small class="form-text text-muted w-100 text-left">用已保存的设置发送。改了设置请先保存。</small>
			</form>
		<?php elseif ($cur_tab === 'audit'): ?>
			<?php
				// who changed what: the newest first, of one user or one kind of thing if asked for
				$audit_conds = array();
				if (isset($_GET['actor']) && validateUsername($_GET['actor'])) {
					$audit_conds[] = "actor = '{$_GET['actor']}'";
				}
				if (isset($_GET['resource_type']) && preg_match('/^[a-z_]{1,20}$/', $_GET['resource_type'])) {
					$audit_conds[] = "resource_type = '{$_GET['resource_type']}'";
				}
				if (isset($_GET['resource_id']) && preg_match('/^[a-zA-Z0-9_]{1,40}$/', $_GET['resource_id'])) {
					$audit_conds[] = "resource_id = '{$_GET['resource_id']}'";
				}
			?>
			<form class="form-inline bot-buffer-md" method="get">
				<input type="text" class="form-control input-sm" name="actor" placeholder="操作者" value="<?= HTML::escape(isset($_GET['actor']) ? $_GET['actor'] : '') ?>" />
				<input type="text" class="form-control input-sm ml-2" name="resource_type" placeholder="对象类型，如 problem" value="<?= HTML::escape(isset($_GET['resource_type']) ? $_GET['resource_type'] : '') ?>" />
				<input type="text" class="form-control input-sm ml-2" name="resource_id" placeholder="对象编号" value="<?= HTML::escape(isset($_GET['resource_id']) ? $_GET['resource_id'] : '') ?>" />
				<button type="submit" class="btn btn-secondary btn-sm ml-2">筛选</button>
			</form>
			<?php
				echoLongTable(array('*'), 'audit_logs', $audit_conds ? join(' and ', $audit_conds) : '1', 'order by id desc',
					'<tr><th>时间</th><th>操作者</th><th>操作</th><th>对象</th><th>修改前</th><th>修改后</th><th>IP</th></tr>',
					function($row) {
						echo '<tr>';
						echo '<td><small>', $row['created_at'], '</small></td>';
						echo '<td>', $row['actor_type'] == 'user' ? HTML::escape($row['actor']) . ' <small class="text-muted">#' . $row['actor_id'] . '</small>' : '系统', '</td>';
						echo '<td>', HTML::escape($row['action']), '</td>';
						echo '<td>', HTML::escape($row['resource_type']), ' ', HTML::escape($row['resource_id']), '</td>';
						echo '<td class="text-left"><small>', HTML::escape($row['before_json']), '</small></td>';
						echo '<td class="text-left"><small>', HTML::escape($row['after_json']), '</small></td>';
						echo '<td><small>', HTML::escape($row['ip']), '</small></td>';
						echo '</tr>';
					}, array('page_len' => 50));
			?>
		<?php elseif ($cur_tab === 'paste'): ?>
			<div>
				<h4>Paste管理</h4>
				<?php echoPastesList() ?>
			</div>
		<?php endif ?>
	</div>
</div>
<?php echoUOJPageFooter() ?>
