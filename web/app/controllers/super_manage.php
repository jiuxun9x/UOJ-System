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

	// ---- the accounts judgers work with
	// What somebody who was just given the password of an account is shown: the three things
	// a judger needs, and how a judger is started with them. The password is shown this once.
	function judgerCredentialsPage($name, $password, $what) {
		$account = queryJudger($name);
		$number = $account ? (int)$account['id'] : 0;
		$name = HTML::escape($name);
		$url = rtrim(HTML::url('/'), '/');
		$esc_url = HTML::escape($url);
		becomeMsgPage(<<<EOD
<div class="text-left" id="judger-credentials">
	<h3>评测账户 <strong>{$name}</strong>（编号 #{$number}）{$what}</h3>
	<table class="table table-bordered" style="max-width:44em">
		<tr><th style="width:8em">站点地址</th><td><code id="judger-server-url">{$esc_url}</code></td></tr>
		<tr><th>评测账户</th><td><code id="judger-name">{$name}</code></td></tr>
		<tr><th>密码</th><td><code id="judger-password">{$password}</code></td></tr>
	</table>
	<p class="text-danger">密码只显示这一次，请现在就记下来或写进评测机的配置；丢了就在评测机列表里“重置密码”。</p>
	<h4>用它启动一台评测机</h4>
	<p>评测机可以在任何一台能访问上面这个地址的 Linux x86_64 机器上，不需要和本站在同一个局域网：是评测机主动连本站领取任务、下载数据，本站不需要能连到评测机。</p>
	<ol>
		<li>在那台机器上取得本站的代码，构建评测机镜像：<pre>docker build -t school-oj-judger ./judger</pre></li>
		<li>启动（只要填上面这三样）：
<pre id="judger-command">docker run -dit --name uoj-judger-{$name} --restart always --cap-add SYS_PTRACE \
  -e UOJ_SERVER_URL={$esc_url} \
  -e JUDGER_NAME={$name} \
  -e JUDGER_PASSWORD={$password} \
  -v "\$PWD/judger-log:/opt/uoj_judger/log" \
  school-oj-judger</pre></li>
		<li>回到评测机列表，一分钟之内它的状态会变成“在线”，随后自动把各题的数据同步过去，不用等到第一次评测。</li>
	</ol>
	<p class="text-muted small">本站不是 HTTPS 时，密码和提交的代码在网络上是明文传输的：评测机不在可信的内网里时，请先给本站配置 HTTPS。更多说明见部署文档的“评测机”一节。</p>
	<p><a class="btn btn-primary" href="/super-manage/judger">回到评测机列表</a></p>
</div>
EOD
		, '评测账户');
	}
	$judger_error = '';
	if ($can_manage_judgers) {
		$judger_adder = new UOJForm('judger_adder');
		$judger_adder->addInput('judger_adder_name', 'text', '账户名称', '',
			function ($x, &$vdata) {
				$err = judgerNameError($x);
				if ($err !== '') {
					return $err;
				}
				$vdata['name'] = $x;
				return '';
			},
			null
		);
		$judger_adder->addInput('judger_adder_note', 'text', '备注（可选，比如这台机器在哪里）', '',
			function ($x, &$vdata) {
				if (!is_string($x) || mb_strlen($x, 'UTF-8') > 100) {
					return '不能超过 100 个字';
				}
				$vdata['note'] = $x;
				return '';
			},
			null
		);
		$judger_adder->handle = function(&$vdata) {
			global $myUser;
			list($password, $err) = judgerCreate($vdata['name'], isset($vdata['note']) ? $vdata['note'] : '', $myUser);
			if ($err !== '') {
				becomeMsgPage(HTML::escape($err));
			}
			judgerCredentialsPage($vdata['name'], $password, '已创建');
		};
		$judger_adder->submit_button_config['text'] = '添加评测账户';
		$judger_adder->runAtServer();

		// what is done with an account that is there: the row of the account says which
		if (isset($_POST['form']) && in_array($_POST['form'], array('judger_switch', 'judger_reset', 'judger_delete'), true)) {
			crsf_defend();
			$name = isset($_POST['judger_name']) && is_string($_POST['judger_name']) ? $_POST['judger_name'] : '';
			if ($_POST['form'] === 'judger_reset') {
				list($password, $judger_error) = judgerResetPassword($name, $myUser);
				if ($judger_error === '') {
					judgerCredentialsPage($name, $password, '有了新的密码，原来的密码不能再用');
				}
			} elseif ($_POST['form'] === 'judger_switch') {
				$judger_error = judgerSwitch($name, $myUser);
			} else {
				$judger_error = judgerDelete($name, $myUser);
			}
			if ($judger_error === '') {
				domainFlash($_POST['form'] === 'judger_delete' ? "评测账户 {$name} 已删除。" : "评测账户 {$name} 已" . (queryJudger($name)['enabled'] ? '启用' : '停用') . '。');
				redirectTo('/super-manage/judger');
			}
		}
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
	$tabs_info['monitor'] = array(
		'name' => '运行状态',
		'url' => '/super-manage/monitor'
	);
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
	
	// The settings of the site. A box that is not ticked is not posted: a switch is off when
	// the form says that it had the switch and the switch did not come. A secret that is
	// left empty stays what it is.
	$site_settings_error = '';
	if ($cur_tab === 'settings' || $cur_tab === 'monitor') {
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
						if (isset($posted[$name]) || isset($_POST['present'][$name])) {
							$values[$name] = isset($posted[$name]);
						}
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
			'backup' => function() {
				global $myUser;
				if (!can($myUser, 'site.manage_settings')) {
					return '没有权限';
				}
				$err = backupRequest($myUser);
				if ($err === '') {
					domainFlash('已经请求备份，一分钟内开始。');
				}
				return $err;
			},
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
				echo '<dd>', $problem ? HTML::escape(problemLabel($problem)) : "#{$submission['problem_id']}", '</dd>';
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
			<div class="text-left">
			<?php $flash = domainTakeFlash(); ?>
			<?php if ($flash): ?>
			<div class="alert alert-<?= $flash[0] ?>" role="alert" id="judger-flash"><?= HTML::escape($flash[1]) ?></div>
			<?php endif ?>
			<?php echoDomainError($judger_error) ?>
			<h3>评测机</h3>
			<p class="text-muted">
				每台评测机用一个<strong>评测账户</strong>为本站工作：评测机主动连接本站，领取评测任务、下载题目数据，所以它可以在任何能访问本站的机器上，不必和本站在同一个局域网。
				评测机空闲时会自动把各题的数据同步过去，新上线的评测机不用等到第一次评测才下载数据。
			</p>
			<?php $judger_accounts = judgerAccounts(); ?>
			<div class="table-responsive">
				<table class="table table-bordered table-hover uoj-roster uoj-judger-accounts" id="table-judger-accounts">
					<thead>
						<tr>
							<th style="width:4em">编号</th>
							<th>评测账户</th>
							<th>状态</th>
							<th>题目数据</th>
							<th>正在评测</th>
							<th>操作</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($judger_accounts as $account): ?>
						<?php
							if ($account['last_heartbeat_at'] === null) {
								$state = array('never', '<span class="badge badge-light border">从未连接</span>');
							} elseif ($account['silent_seconds'] <= 30) {
								$state = array('online', '<span class="badge badge-success">在线</span>');
							} else {
								$state = array('offline', '<span class="badge badge-danger">离线</span>');
							}
						?>
						<tr data-judger="<?= HTML::escape($account['judger_name']) ?>" data-state="<?= $state[0] ?>" data-enabled="<?= (int)$account['enabled'] ?>">
							<td data-id="<?= (int)$account['id'] ?>">#<?= (int)$account['id'] ?></td>
							<td>
								<strong><?= HTML::escape($account['judger_name']) ?></strong>
								<?php if ($account['note'] !== '' || $account['created_by'] !== ''): ?>
								<small><?= HTML::escape($account['note']) ?><?php if ($account['created_by'] !== ''): ?><?= $account['note'] !== '' ? ' · ' : '' ?><?= HTML::escape($account['created_by']) ?> 添加<?php endif ?></small>
								<?php endif ?>
							</td>
							<td>
								<?= $state[1] ?><?php if (!$account['enabled']): ?> <span class="badge badge-secondary">已停用</span><?php endif ?>
								<small><?= $account['last_heartbeat_at'] !== null ? '最近响应 ' . $account['last_heartbeat_at'] : '还没有评测机用它连上来' ?></small>
								<?php if ($account['last_heartbeat_at'] !== null): ?>
								<small><?= $account['version'] !== '' ? '版本 ' . HTML::escape($account['version']) : '<span class="text-danger">没有上报版本，需要升级</span>' ?></small>
								<?php endif ?>
							</td>
							<td data-have="<?= $account['data_have'] === null ? '' : (int)$account['data_have'] ?>" data-total="<?= $account['data_total'] === null ? '' : (int)$account['data_total'] ?>">
								<?php if ($account['data_checked_at'] === null || $account['data_total'] === null): ?>
								<span class="text-muted" title="这台评测机还没有报告过">—</span>
								<?php elseif ($account['data_have'] >= $account['data_total']): ?>
								<span class="text-success">已同步</span> <small><?= (int)$account['data_total'] ?> 题</small>
								<?php else: ?>
								<?= (int)$account['data_have'] ?> / <?= (int)$account['data_total'] ?> 题
								<?php endif ?>
							</td>
							<td><small><?= $account['judging'] ? HTML::escape(join(', ', $account['judging'])) : '—' ?></small></td>
							<td>
								<form method="post" class="d-inline">
									<?= HTML::hiddenToken() ?>
									<input type="hidden" name="judger_name" value="<?= HTML::escape($account['judger_name']) ?>" />
									<button type="submit" name="form" value="judger_switch" class="btn btn-sm btn-outline-secondary" title="停用的账户不再领到新任务，正在评测的会评完"><?= $account['enabled'] ? '停用' : '启用' ?></button>
									<button type="submit" name="form" value="judger_reset" class="btn btn-sm btn-outline-secondary" onclick="return confirm('给 <?= HTML::escape($account['judger_name']) ?> 换一个新密码吗？用旧密码的评测机会连不上，要改它的配置。');">重置密码</button>
									<button type="submit" name="form" value="judger_delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('删除评测账户 <?= HTML::escape($account['judger_name']) ?> 吗？用它的评测机会连不上。');">删除</button>
								</form>
							</td>
						</tr>
						<?php endforeach ?>
						<?php if (!$judger_accounts): ?>
						<tr><td colspan="6" class="text-muted">还没有评测账户。</td></tr>
						<?php endif ?>
					</tbody>
				</table>
			</div>
			<p class="text-muted small">“题目数据”是这台评测机上次报告时手里有多少道题的最新数据：评测机空闲时每半分钟检查一次，新发布的数据先同步，不用等到第一次评测。每台评测机默认最多保留 300 道题的数据（启动时用 <code>DATA_CACHE_PROBLEMS</code> 调整）：没装满时把已有的题目都同步过去；装满之后只同步新发布的数据（最久没用到的被换出），其余的评测到时才下载。</p>
			<h4 class="mt-4">添加评测账户</h4>
			<p class="text-muted small">起一个名字，提交后会显示这个账户的密码和启动评测机的命令。一个账户给一台评测机用。</p>
			<form method="post" class="form-inline" id="form-add-judger">
				<?= HTML::hiddenToken() ?>
				<input type="text" class="form-control mr-2 mb-2" name="judger_adder_name" required="required" maxlength="20" pattern="[A-Za-z0-9_]{1,20}" placeholder="账户名称：字母、数字、下划线" style="width:17em" />
				<input type="text" class="form-control mr-2 mb-2" name="judger_adder_note" maxlength="100" placeholder="备注（可选），比如这台机器在哪里" style="width:20em" />
				<button type="submit" name="submit-judger_adder" value="judger_adder" class="btn btn-primary mb-2" id="button-add-judger">添加评测账户</button>
			</form>
			</div>
		<?php elseif ($cur_tab === 'monitor'): ?>
			<?php
				$silent_after = siteSetting('alert.judger_silent_seconds');
				$monitor_judgers = monitorJudgers();
				$monitor_queue = monitorQueue();
				$monitor_open = openAlerts();
			?>
			<div class="text-left">
			<?php $flash = domainTakeFlash(); ?>
			<?php if ($flash): ?>
			<div class="alert alert-<?= $flash[0] ?>" role="alert"><?= HTML::escape($flash[1]) ?></div>
			<?php endif ?>
			<?php echoDomainError($site_settings_error) ?>
			<h3>运行状态</h3>
			<?php if (!$monitor_open): ?>
			<div class="alert alert-success" id="monitor-ok">一切正常，没有未恢复的告警。</div>
			<?php else: ?>
			<div class="alert alert-danger" id="monitor-alerts">
				<strong>有 <?= count($monitor_open) ?> 条告警未恢复：</strong>
				<ul class="mb-0">
					<?php foreach ($monitor_open as $alert): ?>
					<li data-kind="<?= $alert['kind'] ?>"><?= HTML::escape($alert['message']) ?> <small>（从 <?= $alert['started_at'] ?> 起）</small></li>
					<?php endforeach ?>
				</ul>
			</div>
			<?php endif ?>

			<h4>评测机</h4>
			<table class="table table-bordered table-sm" id="table-monitor-judgers">
				<thead><tr><th>名称</th><th>状态</th><th>最近响应</th><th>正在评测</th><th>近一小时评测数</th><th>版本</th></tr></thead>
				<tbody>
					<?php foreach ($monitor_judgers as $judger): ?>
					<?php
						if (!$judger['enabled']) {
							$state = '<span class="badge badge-secondary">已停用</span>';
						} elseif ($judger['silent_seconds'] === null) {
							$state = '<span class="badge badge-secondary">从未连接</span>';
						} elseif ($judger['silent_seconds'] <= $silent_after) {
							$state = '<span class="badge badge-success">在线</span>';
						} else {
							$state = '<span class="badge badge-danger">离线</span>';
						}
					?>
					<tr data-judger="<?= HTML::escape($judger['name']) ?>">
						<td><?= HTML::escape($judger['name']) ?></td>
						<td><?= $state ?></td>
						<td><?= $judger['silent_seconds'] === null ? '—' : monitorDuration($judger['silent_seconds']) . '前' ?></td>
						<td><?= $judger['judging'] ? HTML::escape(join(', ', $judger['judging'])) : '<span class="text-muted">空闲</span>' ?></td>
						<td><?= $judger['judged_last_hour'] ?></td>
						<td><small><?= $judger['version'] !== '' ? HTML::escape($judger['version']) : '<span class="text-danger">未上报</span>' ?></small></td>
					</tr>
					<?php endforeach ?>
					<?php if (!$monitor_judgers): ?>
					<tr><td colspan="6" class="text-center text-muted">还没有登记评测机</td></tr>
					<?php endif ?>
				</tbody>
			</table>

			<h4>评测队列</h4>
			<p id="monitor-queue">
				等待评测的提交：<strong><?= $monitor_queue['waiting'] ?></strong> 份<?php if ($monitor_queue['oldest_wait_seconds'] !== null): ?>，最早的一份新提交已经等了 <?= monitorDuration($monitor_queue['oldest_wait_seconds']) ?><?php endif ?>。
			</p>

			<div class="d-flex align-items-center">
				<h4 class="mr-auto">备份</h4>
				<?php if (can($myUser, 'site.manage_settings')): ?>
				<form method="post" class="mb-2">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="backup" />
					<button type="submit" class="btn btn-outline-primary btn-sm" id="button-backup-now">立即备份</button>
				</form>
				<?php endif ?>
			</div>
			<p class="text-muted small" id="monitor-backup-schedule">
				<?php if (siteSettingIsOn('backup.enabled')): ?>
				每天 <?= siteSetting('backup.hour') ?> 点自动备份，保留 <?= siteSetting('backup.keep_days') ?> 天。
				<?php else: ?>
				<span class="text-danger">自动备份已关闭。</span>
				<?php endif ?>
				备份放在服务器的 <code>uoj_data/backup</code> 目录；恢复用仓库根目录的 <code>restore.sh</code>。
			</p>
			<table class="table table-sm" id="table-monitor-backups">
				<thead><tr><th>备份</th><th>开始</th><th>状态</th><th>数据库</th><th>文件</th><th>演练</th></tr></thead>
				<tbody>
					<?php $backup_runs = backupRuns(10); ?>
					<?php foreach ($backup_runs as $run): ?>
					<?php
						$run_states = array('requested' => '等待开始', 'running' => '进行中', 'ok' => '<span class="text-success">成功</span>', 'failed' => '<span class="text-danger">失败</span>');
					?>
					<tr data-status="<?= $run['status'] ?>">
						<td><code><?= $run['name'] !== '' ? $run['name'] : '—' ?></code> <small class="text-muted"><?= $run['reason'] === 'scheduled' ? '定时' : '手动' ?></small></td>
						<td><small><?= $run['started_at'] ?></small></td>
						<td><?= $run_states[$run['status']] ?><?php if ($run['status'] === 'failed'): ?> <small><?= HTML::escape($run['message']) ?></small><?php endif ?></td>
						<td><?= backupSize($run['db_bytes'] === null ? null : (int)$run['db_bytes']) ?></td>
						<td><?= $run['files_count'] === null ? '—' : $run['files_count'] . ' 个，' . backupSize((int)$run['files_bytes']) ?></td>
						<td><small><?= $run['verified_at'] !== null ? '已通过 ' . $run['verified_at'] : '—' ?></small></td>
					</tr>
					<?php endforeach ?>
					<?php if (!$backup_runs): ?>
					<tr><td colspan="6" class="text-center text-muted">还没有备份过</td></tr>
					<?php endif ?>
				</tbody>
			</table>

			<h4>最近的告警</h4>
			<table class="table table-sm" id="table-monitor-history">
				<thead><tr><th>开始</th><th>恢复</th><th>类型</th><th>内容</th><th>邮件</th></tr></thead>
				<tbody>
					<?php foreach (recentAlerts(30) as $alert): ?>
					<tr>
						<td><small><?= $alert['started_at'] ?></small></td>
						<td><small><?= $alert['resolved_at'] !== null ? $alert['resolved_at'] : '<span class="text-danger">未恢复</span>' ?></small></td>
						<td><?= alertKindName($alert['kind']) ?></td>
						<td><?= HTML::escape($alert['message']) ?></td>
						<td><small><?= $alert['mailed_at'] !== null ? '已发' : '—' ?></small></td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
			<p class="text-muted small">网站每分钟检查一次。告警出现和恢复时，系统管理员会收到站内消息；是否同时发邮件、多久算离线，在“站点设置 → 告警”里调整。</p>
			</div>
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
							<input type="hidden" name="present[<?= $name ?>]" value="1" />
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
