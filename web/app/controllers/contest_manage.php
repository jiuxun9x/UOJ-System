<?php
	requirePHPLib('form');
	
	if (!validateUInt($_GET['id']) || !($contest = queryContest($_GET['id']))) {
		become404Page();
	}
	genMoreContestInfo($contest);
	// a contest of a domain exists for the members of the domain
	if (!can($myUser, 'contest.view', $contest)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}
	
	if (!can($myUser, 'contest.manage', $contest)) {
		become403Page();
	}
	
	$time_form = new UOJForm('time');
	$time_form->addInput(
		'name', 'text', '比赛标题', $contest['name'],
		function($str) {
			return '';
		},
		null
	);
	$time_form->addInput(
		'start_time', 'text', '开始时间', $contest['start_time_str'],
		function($str, &$vdata) {
			try {
				$vdata['start_time'] = new DateTime($str);
			} catch (Exception $e) {
				return '无效时间格式';
			}
			return '';
		},
		null
	);
	$time_form->addInput(
		'last_min', 'text', '时长（单位：分钟）', $contest['last_min'],
		function($str) {
			return !validateUInt($str) ? '必须为一个整数' : '';
		},
		null
	);
	$time_form->handle = function(&$vdata) {
		global $contest;
		$start_time_str = $vdata['start_time']->format('Y-m-d H:i:s');
		
		$purifier = HTML::pruifier();
		
		$esc_name = $_POST['name'];
		$esc_name = $purifier->purify($esc_name);
		$esc_name = DB::escape($esc_name);
		
		DB::update("update contests set start_time = '$start_time_str', last_min = {$_POST['last_min']}, name = '$esc_name' where id = {$contest['id']}");
		auditLog('contest.edit', 'contest', $contest['id'],
			array('name' => $contest['name'], 'start_time' => $contest['start_time_str'], 'last_min' => (int)$contest['last_min']),
			array('name' => $_POST['name'], 'start_time' => $start_time_str, 'last_min' => (int)$_POST['last_min']));
	};
	
	// "+mike" makes mike an assistant, "+mike [owner]" an owner, "-mike" takes mike off the staff
	$parse_manager_cmd = function($cmd) {
		if (!preg_match('/^([a-zA-Z0-9_]{1,20})\s*(\[(owner|assistant)\])?$/', $cmd, $matches)) {
			return null;
		}
		return array($matches[1], isset($matches[3]) ? $matches[3] : 'assistant');
	};
	$managers_form = newAddDelCmdForm('managers',
		function($cmd) use ($parse_manager_cmd) {
			$parsed = $parse_manager_cmd($cmd);
			if ($parsed === null) {
				return '格式错误';
			}
			if (!queryUser($parsed[0])) {
				return "不存在名为{$parsed[0]}的用户";
			}
			return '';
		},
		function($type, $cmd) use ($parse_manager_cmd) {
			global $contest;
			list($username, $role) = $parse_manager_cmd($cmd);
			if ($type == '+') {
				DB::query("insert into contests_permissions (contest_id, username, role) values (${contest['id']}, '$username', '$role') on duplicate key update role = '$role'");
				auditLog('contest.add_staff', 'contest', $contest['id'], null, array('username' => $username, 'role' => $role));
			} elseif ($type == '-') {
				DB::query("delete from contests_permissions where contest_id = ${contest['id']} and username = '$username'");
				auditLog('contest.remove_staff', 'contest', $contest['id'], array('username' => $username), null);
			}
		}
	);
	
	$problems_form = newAddDelCmdForm('problems',
		function($cmd) {
			if (!preg_match('/^(\d+)\s*(\[\S+\])?$/', $cmd, $matches)) {
				return "无效题号";
			}
			$problem_id = $matches[1];
			if (!validateUInt($problem_id) || !($problem = queryProblemBrief($problem_id))) {
				return "不存在题号为{$problem_id}的题";
			}
			global $contest;
			// a contest of a domain may also use the public problems of the site
			if (!can(Auth::user(), 'problem.manage', $problem) && !($contest['domain_id'] && can(Auth::user(), 'problem.use', $problem))) {
				return "无权添加题号为{$problem_id}的题";
			}
			if ($problem['owner_domain_id'] && $problem['owner_domain_id'] != $contest['domain_id']) {
				return "题号为{$problem_id}的题属于另一个域";
			}
			return '';
		},
		function($type, $cmd) {
			global $contest;
			
			if (!preg_match('/^(\d+)\s*(\[\S+\])?$/', $cmd, $matches)) {
				return "无效题号";
			}
			
			$problem_id = $matches[1];
			
			if ($type == '+') {
				DB::insert("insert into contests_problems (contest_id, problem_id) values ({$contest['id']}, '$problem_id')");
				auditLog('contest.add_problem', 'contest', $contest['id'], null, array('problem_id' => (int)$problem_id, 'setting' => isset($matches[2]) ? $matches[2] : ''));
			} elseif ($type == '-') {
				DB::delete("delete from contests_problems where contest_id = {$contest['id']} and problem_id = '$problem_id'");
				auditLog('contest.remove_problem', 'contest', $contest['id'], array('problem_id' => (int)$problem_id), null);
			}
			
			if (isset($matches[2])) {
				switch ($matches[2]) {
					case '[sample]':
						unset($contest['extra_config']["problem_$problem_id"]);
						break;
					case '[full]':
						$contest['extra_config']["problem_$problem_id"] = 'full';
						break;
					case '[no-details]':
						$contest['extra_config']["problem_$problem_id"] = 'no-details';
						break;
				}
				$esc_extra_config = json_encode($contest['extra_config']);
				$esc_extra_config = DB::escape($esc_extra_config);
				DB::update("update contests set extra_config = '$esc_extra_config' where id = {$contest['id']}");
			}
		}
	);
	
	if (can($myUser, 'contest.rate', $contest)) {
		$rating_k_form = new UOJForm('rating_k');
		$rating_k_form->addInput('rating_k', 'text', 'rating 变化上限', isset($contest['extra_config']['rating_k']) ? $contest['extra_config']['rating_k'] : 400,
			function ($x) {
				if (!validateUInt($x) || $x < 1 || $x > 1000) {
					return '不合法的上限';
				}
				return '';
			},
			null
		);
		$rating_k_form->handle = function() {
			global $contest;
			$contest['extra_config']['rating_k'] = $_POST['rating_k'];
			$esc_extra_config = json_encode($contest['extra_config']);
			auditLog('contest.edit_config', 'contest', $contest['id'], null, $contest['extra_config']);
			$esc_extra_config = DB::escape($esc_extra_config);
			DB::update("update contests set extra_config = '$esc_extra_config' where id = {$contest['id']}");
		};
		$rating_k_form->runAtServer();
		
		$rated_form = new UOJForm('rated');
		$rated_form->handle = function() {
			global $contest;
			if (isset($contest['extra_config']['unrated'])) {
				unset($contest['extra_config']['unrated']);
			} else {
				$contest['extra_config']['unrated'] = '';
			}
			$esc_extra_config = json_encode($contest['extra_config']);
			auditLog('contest.edit_config', 'contest', $contest['id'], null, $contest['extra_config']);
			$esc_extra_config = DB::escape($esc_extra_config);
			DB::update("update contests set extra_config = '$esc_extra_config' where id = {$contest['id']}");
		};
		$rated_form->submit_button_config['class_str'] = 'btn btn-warning btn-block';
		$rated_form->submit_button_config['text'] = isset($contest['extra_config']['unrated']) ? '设置比赛为rated' : '设置比赛为unrated';
		$rated_form->submit_button_config['smart_confirm'] = '';
	
		$rated_form->runAtServer();
	}
	
	// the rules of the contest are its owner's to choose
	$version_form = new UOJForm('version');
	$version_form->addInput('standings_version', 'text', '排名版本', $contest['extra_config']['standings_version'],
		function ($x) {
			if (!validateUInt($x) || $x < 1 || $x > 2) {
				return '不是合法的版本号';
			}
			return '';
		},
		null
	);
	$version_form->handle = function() {
		global $contest;
		$contest['extra_config']['standings_version'] = $_POST['standings_version'];
		$esc_extra_config = json_encode($contest['extra_config']);
		auditLog('contest.edit_config', 'contest', $contest['id'], null, $contest['extra_config']);
		$esc_extra_config = DB::escape($esc_extra_config);
		DB::update("update contests set extra_config = '$esc_extra_config' where id = {$contest['id']}");
	};
	$version_form->runAtServer();

	$contest_type_form = new UOJForm('contest_type');
	$contest_type_form->addInput('contest_type', 'text', '赛制', $contest['extra_config']['contest_type'],
		function ($x) {
			if ($x != 'OI' && $x != 'ACM' && $x != 'IOI') {
				return '不是合法的赛制名';
			}
			return '';
		},
		null
	);
	$contest_type_form->handle = function() {
		global $contest;
		$contest['extra_config']['contest_type'] = $_POST['contest_type'];
		$esc_extra_config = json_encode($contest['extra_config']);
		auditLog('contest.edit_config', 'contest', $contest['id'], null, $contest['extra_config']);
		$esc_extra_config = DB::escape($esc_extra_config);
		DB::update("update contests set extra_config = '$esc_extra_config' where id = {$contest['id']}");
	};
	$contest_type_form->runAtServer();
	
	$time_form->runAtServer();
	$managers_form->runAtServer();
	$problems_form->runAtServer();

	// who may take part
	$access_error = domainHandleForms(array(
		'join_mode' => function() use ($contest) {
			global $myUser;
			$err = contestSetJoinMode($contest, isset($_POST['join_mode']) && is_string($_POST['join_mode']) ? $_POST['join_mode'] : '', isset($_POST['join_password']) ? $_POST['join_password'] : '', $myUser);
			if ($err === '') {
				domainFlash('参加方式已保存。');
			}
			return $err;
		},
		'allow' => function() use ($contest) {
			global $myUser;
			list($added, $refused) = contestAllowUsers($contest, isset($_POST['names']) && is_string($_POST['names']) ? $_POST['names'] : '', $myUser);
			domainFlash("名单里新增了 $added 人。" . ($refused ? '无法识别：' . join('、', array_slice($refused, 0, 10)) : ''), $refused ? 'warning' : 'success');
			return '';
		},
		'disallow' => function() use ($contest) {
			global $myUser;
			return contestDisallowUser($contest, isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '', $myUser);
		}
	), "/contest/{$contest['id']}/manage#tab-access");
	$access_flash = domainTakeFlash();
	$allowed_users = contestAllowedUsers($contest);
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 比赛管理') ?>
<?php echoContestDomainLink($contest) ?>
<h1 class="page-header" align="center"><?=$contest['name']?> 管理</h1>
<ul class="nav nav-tabs mb-3" role="tablist">
	<li class="nav-item"><a class="nav-link active" href="#tab-time" role="tab" data-toggle="tab">比赛时间</a></li>
	<li class="nav-item"><a class="nav-link" href="#tab-managers" role="tab" data-toggle="tab">管理者</a></li>
	<li class="nav-item"><a class="nav-link" href="#tab-problems" role="tab" data-toggle="tab">试题</a></li>
	<li class="nav-item"><a class="nav-link" href="#tab-access" role="tab" data-toggle="tab">参加方式</a></li>
	<li class="nav-item"><a class="nav-link" href="#tab-others" role="tab" data-toggle="tab">其它</a></li>
	<li class="nav-item"><a class="nav-link" href="/contest/<?=$contest['id']?>" role="tab">返回</a></li>
</ul>
<div class="tab-content top-buffer-sm">
	<div class="tab-pane active" id="tab-time">
		<?php $time_form->printHTML(); ?>
	</div>
	
	<div class="tab-pane" id="tab-managers">
		<table class="table table-hover">
			<thead>
				<tr>
					<th>#</th>
					<th>用户名</th>
					<th>角色</th>
				</tr>
			</thead>
			<tbody>
<?php
	$row_id = 0;
	$result = DB::query("select username, role from contests_permissions where contest_id = {$contest['id']} order by role desc, username");
	while ($row = DB::fetch($result, MYSQLI_ASSOC)) {
		$row_id++;
		echo '<tr>', '<td>', $row_id, '</td>', '<td>', getUserLink($row['username']), '</td>', '<td>', $row['role'] == 'owner' ? '负责人' : '助理', '</td>', '</tr>';
	}
?>
			</tbody>
		</table>
		<p class="text-center">命令格式：命令一行一个，+mike表示把mike加为助理，+mike [owner]表示把mike加为负责人，-mike表示把mike移除</p>
		<p class="text-center">负责人可以修改比赛设置、试题和人员，开始最终测试并公布成绩；助理可以进入后台、查看所有提交并回答提问。</p>
		<?php $managers_form->printHTML(); ?>
	</div>
	
	<div class="tab-pane" id="tab-problems">
		<table class="table table-hover">
			<thead>
				<tr>
					<th>#</th>
					<th>试题名</th>
				</tr>
			</thead>
			<tbody>
<?php
	$result = DB::query("select problem_id from contests_problems where contest_id = ${contest['id']} order by problem_id asc");
	while ($row = DB::fetch($result, MYSQLI_ASSOC)) {
		$problem = queryProblemBrief($row['problem_id']);
		$problem_config_str = isset($contest['extra_config']["problem_{$problem['id']}"]) ? $contest['extra_config']["problem_{$problem['id']}"] : 'sample';
		echo '<tr>', '<td>', $problem['id'], '</td>', '<td>', getProblemLink($problem), ' ', "[$problem_config_str]", '</td>', '</tr>';
	}
?>
			</tbody>
		</table>
		<p class="text-center">命令格式：命令一行一个，+233表示把题号为233的试题加入比赛，-233表示把题号为233的试题从比赛中移除</p>
		<?php $problems_form->printHTML(); ?>
	</div>
	<div class="tab-pane text-left" id="tab-access">
		<?php if ($access_flash): ?>
		<div class="alert alert-<?= $access_flash[0] ?>" role="alert"><?= HTML::escape($access_flash[1]) ?></div>
		<?php endif ?>
		<?php echoDomainError($access_error) ?>
		<form method="post" id="form-join-mode" class="mb-4">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="join_mode" />
			<?php foreach (contestJoinModes() as $mode => $mode_label): ?>
			<div class="custom-control custom-radio mb-2">
				<input type="radio" class="custom-control-input" id="input-join_mode-<?= $mode ?>" name="join_mode" value="<?= $mode ?>"<?= $contest['join_mode'] === $mode ? ' checked="checked"' : '' ?> />
				<label class="custom-control-label" for="input-join_mode-<?= $mode ?>"><?= $mode_label ?></label>
			</div>
			<?php endforeach ?>
			<div class="form-group mt-2" style="max-width:24em">
				<label for="input-join_password">参赛密码</label>
				<input type="password" class="form-control" id="input-join_password" name="join_password" maxlength="64" autocomplete="new-password" placeholder="<?= $contest['join_password'] !== '' ? '已设置，留空表示不修改' : '选择“密码限制”时填写' ?>" />
				<small class="form-text text-muted">密码保存后不再显示，4 到 64 个字符。把它告诉要参加的人。</small>
			</div>
			<button type="submit" class="btn btn-primary" id="button-save-join-mode">保存</button>
			<small class="form-text text-muted">
				名单限制和密码限制的比赛，开始后它的题目、榜单和提交只有报名成功的选手和工作人员能看到，结束后也是如此；要对所有人开放，把参加方式改回“自由参加”。已经报名的人不受改动影响。
				<?php if ($contest['domain_id']): ?>这场比赛属于一个域，无论哪种方式，都只有域的成员能参加。<?php endif ?>
			</small>
		</form>

		<h4>名单 <small class="text-muted">（<?= count($allowed_users) ?> 人，选择“名单限制”时生效）</small></h4>
		<?php if ($allowed_users): ?>
		<div class="mb-3" id="list-allowed-users">
			<?php foreach ($allowed_users as $allowed): ?>
			<form method="post" class="d-inline">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="disallow" />
				<input type="hidden" name="username" value="<?= HTML::escape($allowed['username']) ?>" />
				<span class="badge badge-light border p-2 mr-1 mb-1">
					<?= $allowed['user'] !== null ? getUserLink($allowed['user']) : HTML::escape($allowed['username']) . ' <small class="text-muted">还没有登录过</small>' ?>
					<button type="submit" class="close ml-1" style="font-size:1rem" title="从名单里移除">&times;</button>
				</span>
			</form>
			<?php endforeach ?>
		</div>
		<?php endif ?>
		<form method="post" id="form-allow-users">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="allow" />
			<div class="form-group">
				<textarea class="form-control" name="names" rows="5" placeholder="每行一个用户名或学号"></textarea>
				<small class="form-text text-muted">写学号时，这个学号的学生不论用户名是什么、现在有没有登录过，都在名单里。</small>
			</div>
			<button type="submit" class="btn btn-outline-primary">加入名单</button>
		</form>
	</div>
	<div class="tab-pane" id="tab-others">
		<div class="row">
			<?php if (can($myUser, 'contest.rate', $contest)): ?>
			<div class="col-sm-12">
				<h3>Rating控制</h3>
				<div class="row">
					<div class="col-sm-3">
						<?php $rated_form->printHTML(); ?>
					</div>
				</div>
				<div class="top-buffer-sm"></div>
				<?php $rating_k_form->printHTML(); ?>
			</div>
			<?php else: ?>
			<div class="col-sm-12">
				<p class="text-muted">此比赛<?= isset($contest['extra_config']['unrated']) ? '不计入' : '计入' ?> Rating。是否计入 Rating 由管理员设置。</p>
			</div>
			<?php endif ?>
			<div class="col-sm-12 top-buffer-sm">
				<h3>版本控制</h3>
				<?php $version_form->printHTML(); ?>
			</div>
			<div class="col-sm-12 top-buffer-sm">
				<h3>赛制</h3>
				<?php $contest_type_form->printHTML(); ?>
			</div>
		</div>
	</div>
</div>
<?php echoUOJPageFooter() ?>