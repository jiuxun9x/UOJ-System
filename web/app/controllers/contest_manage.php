<?php
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

	$manage = "/contest/{$contest['id']}/manage";
	$may_rate = can($myUser, 'contest.rate', $contest);
	$settings = contestSettings($contest);

	$posted = function($name) {
		return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
	};
	// every form is on a tab: a form that went through leads back to its tab
	$done = function($tab, $message, $type = 'success') use ($manage) {
		domainFlash($message, $type);
		redirectTo("$manage#tab-$tab");
	};

	$error = domainHandleForms(attachmentForms('contest', $contest['id'], function($message, $type) use ($done) {
		$done('attachments', $message, $type);
	}) + array(
		// ---- what the contest is
		'settings' => function() use ($contest, $settings, $may_rate, $done) {
			global $myUser;
			list($checked, $err) = contestSettingsFromForm($_POST, $settings, $may_rate, $contest['join_password'] !== '');
			if ($err !== '') {
				return $err;
			}
			contestSaveSettings($contest, $checked, $myUser);
			$done('settings', '设置已保存。');
		},

		// ---- its problems
		'add_problem' => function() use ($contest, $posted, $done) {
			global $myUser;
			list($problems, $err) = contestProblemsByNumbers($contest, $posted('number'), $myUser);
			if ($err !== '') {
				return $err;
			}
			if (!$problems) {
				return '请填写题号';
			}
			foreach ($problems as $problem) {
				$err = contestAddProblem($contest, $problem, $myUser);
				if ($err !== '') {
					return '题目 #' . problemNumber($problem) . '：' . $err;
				}
			}
			$done('problems', '已添加 ' . count($problems) . ' 道题。');
		},
		'remove_problem' => function() use ($contest, $posted, $done) {
			global $myUser;
			$err = contestRemoveProblem($contest, validateUInt($posted('problem_id')) ? $posted('problem_id') : 0, $myUser);
			if ($err !== '') {
				return $err;
			}
			$done('problems', '已把这道题从比赛里移除。');
		},
		'move_problem' => function() use ($contest, $posted, $done) {
			global $myUser;
			$err = contestMoveProblem($contest, validateUInt($posted('problem_id')) ? $posted('problem_id') : 0, $posted('direction') === 'down', $myUser);
			if ($err !== '') {
				return $err;
			}
			$done('problems', '顺序已调整。');
		},
		'judge_problem' => function() use ($contest, $posted, $done) {
			global $myUser;
			if (!validateUInt($posted('problem_id')) || !in_array((int)$posted('problem_id'), contestProblemIds($contest['id']), true)) {
				return '比赛里没有这道题';
			}
			contestSetProblemJudging($contest, $posted('problem_id'), $posted('judged_with') === 'everything', $myUser);
			$done('problems', '已保存。');
		},

		// ---- the list of the people who may take part
		'allow' => function() use ($contest, $done) {
			global $myUser;
			list($added, $refused) = contestAllowUsers($contest, isset($_POST['names']) && is_string($_POST['names']) ? $_POST['names'] : '', $myUser);
			$done('access', "名单里新增了 $added 人。" . ($refused ? '无法识别：' . join('、', array_slice($refused, 0, 10)) : ''), $refused ? 'warning' : 'success');
		},
		'disallow' => function() use ($contest, $posted, $done) {
			global $myUser;
			$err = contestDisallowUser($contest, $posted('username'), $myUser);
			if ($err !== '') {
				return $err;
			}
			$done('access', '已从名单里移除。');
		},

		// ---- the end of it
		'delete_contest' => function() use ($contest) {
			global $myUser;
			$typed = isset($_POST['confirm']) && is_string($_POST['confirm']) ? trim($_POST['confirm']) : '';
			if ($typed !== contestDeletionWord($contest)) {
				return '输入的比赛名称和这场比赛的不一样，比赛没有删除';
			}
			contestDelete($contest, $myUser);
			domainFlash('比赛“' . contestDeletionWord($contest) . '”已删除。它的题目和提交还在。');
			$domain = empty($contest['domain_id']) ? null : queryDomain($contest['domain_id']);
			redirectTo($domain ? domainUrl($domain, '/contests') : '/contests');
		},

		// ---- the contestants: put in and taken out at any time, also while the contest runs
		'add_contestants' => function() use ($contest, $done) {
			global $myUser;
			list($added, $seated, $refused) = contestAddRegistrants($contest, isset($_POST['names']) && is_string($_POST['names']) ? $_POST['names'] : '', $myUser);
			$done('contestants', "加入了 $added 位选手" . ($seated ? "，更新了 $seated 个座位" : '') . '。' . ($refused ? '没有处理的行：' . join('；', array_slice($refused, 0, 10)) . (count($refused) > 10 ? ' 等 ' . count($refused) . ' 行' : '') : ''), $refused ? 'warning' : 'success');
		},
		'remove_contestant' => function() use ($contest, $posted, $done) {
			global $myUser;
			$err = contestRemoveRegistrant($contest, $posted('username'), $myUser);
			if ($err !== '') {
				return $err;
			}
			$done('contestants', '已把 ' . $posted('username') . ' 移出比赛。他在比赛里的提交还在，再加回来时照常计入。');
		},
		'set_seat' => function() use ($contest, $posted, $done) {
			global $myUser;
			$err = contestSetSeat($contest, $posted('username'), $posted('seat'), $myUser);
			if ($err !== '') {
				return $err;
			}
			$done('contestants', '座位已保存。');
		},

		// ---- the people who run it
		'add_manager' => function() use ($contest, $posted, $done) {
			$role = $posted('role') === 'owner' ? 'owner' : 'assistant';
			$user = validateUsername($posted('username')) ? queryUser($posted('username')) : null;
			if (!$user) {
				return '没有这个用户';
			}
			$esc_username = DB::escape($user['username']);
			DB::insert("insert into contests_permissions (contest_id, username, role) values ({$contest['id']}, '$esc_username', '$role') on duplicate key update role = '$role'");
			auditLog('contest.add_staff', 'contest', $contest['id'], null, array('username' => $user['username'], 'role' => $role));
			$done('managers', '已把 ' . $user['username'] . ' 设为' . ($role === 'owner' ? '负责人' : '助理') . '。');
		},
		'remove_manager' => function() use ($contest, $posted, $done) {
			if (!validateUsername($posted('username'))) {
				return '没有这个用户';
			}
			$esc_username = DB::escape($posted('username'));
			$row = DB::selectFirst("select role from contests_permissions where contest_id = {$contest['id']} and username = '$esc_username'");
			if (!$row) {
				return '这个用户不是这场比赛的管理者';
			}
			// a contest of the site is somebody's: its last owner hands it over before leaving
			if ($row['role'] === 'owner' && !$contest['domain_id']
					&& DB::selectCount("select count(*) from contests_permissions where contest_id = {$contest['id']} and role = 'owner'") <= 1) {
				return '这是最后一位负责人。先把另一个人设为负责人，再移除他';
			}
			DB::delete("delete from contests_permissions where contest_id = {$contest['id']} and username = '$esc_username'");
			auditLog('contest.remove_staff', 'contest', $contest['id'], array('username' => $posted('username')), null);
			$done('managers', '已移除。');
		}
	));
	$flash = domainTakeFlash();

	// a form that was refused is shown again on its tab, with what was typed into it
	$tabs = array('settings' => '设置', 'problems' => '试题', 'attachments' => '附件', 'contestants' => '选手', 'access' => '名单', 'managers' => '管理者', 'delete' => '删除');
	$active_tab = isset($_POST['tab']) && is_string($_POST['tab']) && isset($tabs[$_POST['tab']]) ? $_POST['tab'] : 'settings';
	if ($error !== '' && $active_tab === 'settings') {
		foreach (array('name', 'start_time', 'last_min', 'rule', 'freeze_minutes', 'standings_version', 'rating_k', 'join_mode') as $field) {
			if (isset($_POST[$field]) && is_string($_POST[$field])) {
				$settings[$field] = $_POST[$field];
			}
		}
		if ($may_rate) {
			$settings['rated'] = isset($_POST['rated']);
		}
	}

	$rule = contestRule($contest);
	$problems = array();
	foreach (contestProblemIds($contest['id']) as $index => $problem_id) {
		$problem = queryProblemBrief($problem_id);
		if ($problem) {
			$problems[] = array('letter' => chr(ord('A') + $index % 26), 'everything' => !contestJudgesSamplesOnly($contest, $problem_id)) + $problem;
		}
	}
	$managers = DB::selectAll("select username, role from contests_permissions where contest_id = {$contest['id']} order by role desc, username");
	$allowed_users = contestAllowedUsers($contest);
	$contestants = contestRegistrants($contest);
	$deletion_facts = contestDeletionFacts($contest);
	$contestant_identities = rosterIdentities(array_column($contestants, 'username'));
	$attachments = attachmentsOf('contest', $contest['id']);
	$has_begun = $contest['cur_progress'] > CONTEST_NOT_STARTED;
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 比赛管理') ?>
<?php echoContestDomainLink($contest) ?>
<h1 class="page-header" align="center"><?= $contest['name'] ?> 管理</h1>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>" role="alert" id="contest-manage-flash"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<?php echoDomainError($error) ?>
<ul class="nav nav-tabs mb-3" role="tablist" id="contest-manage-tabs">
	<?php foreach ($tabs as $tab => $label): ?>
	<li class="nav-item"><a class="nav-link<?= $tab === $active_tab ? ' active' : '' ?><?= $tab === 'delete' ? ' text-danger' : '' ?>" href="#tab-<?= $tab ?>" role="tab" data-toggle="tab"><?= $label ?><?php if ($tab === 'problems'): ?> <span class="badge badge-secondary"><?= count($problems) ?></span><?php elseif ($tab === 'attachments' && $attachments): ?> <span class="badge badge-secondary"><?= count($attachments) ?></span><?php endif ?></a></li>
	<?php endforeach ?>
	<li class="nav-item"><a class="nav-link" href="/contest/<?= $contest['id'] ?>/balloons" id="link-manage-balloons">气球</a></li>
	<li class="nav-item"><a class="nav-link" href="/contest/<?= $contest['id'] ?>" role="tab">返回比赛</a></li>
</ul>
<div class="tab-content text-left">
	<div class="tab-pane<?= $active_tab === 'settings' ? ' active' : '' ?>" id="tab-settings">
		<form method="post" id="form-contest-settings" style="max-width:52em">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="settings" />
			<input type="hidden" name="tab" value="settings" />
			<?php uojIncludeView('contest-settings-form', array('settings' => $settings, 'may_rate' => $may_rate, 'in_domain' => !empty($contest['domain_id']), 'has_password' => $contest['join_password'] !== '')) ?>
			<?php if ($has_begun): ?>
			<div class="alert alert-warning py-2">比赛已经开始。改变时间、赛制或封榜会影响正在比赛的选手和已经算出的榜单，请确认后再保存。</div>
			<?php endif ?>
			<button type="submit" class="btn btn-primary" id="button-save-contest-settings">保存</button>
		</form>
	</div>

	<div class="tab-pane<?= $active_tab === 'problems' ? ' active' : '' ?>" id="tab-problems">
		<?php if ($has_begun && $contest['cur_progress'] < CONTEST_FINISHED): ?>
		<div class="alert alert-warning py-2">比赛已经开始。增删题目或调整顺序会改变题目的字母编号。</div>
		<?php endif ?>
		<?php if ($problems): ?>
		<div class="table-responsive">
			<table class="table table-hover" id="table-contest-problems">
				<thead>
					<tr>
						<th style="width:3em"></th>
						<th style="width:6em">题号</th>
						<th>试题</th>
						<?php if ($rule === 'OI'): ?>
						<th style="width:16em">比赛中怎么评测</th>
						<?php endif ?>
						<th style="width:11em"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($problems as $index => $problem): ?>
					<tr data-problem="<?= $problem['id'] ?>">
						<td><strong><?= $problem['letter'] ?></strong></td>
						<td>#<?= problemNumber($problem) ?></td>
						<td><a href="<?= problemUrl($problem) ?>"><?= $problem['title'] ?></a><?php if ($problem['is_hidden']): ?> <span class="badge badge-secondary">隐藏</span><?php endif ?></td>
						<?php if ($rule === 'OI'): ?>
						<td>
							<form method="post" class="form-inline">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="judge_problem" />
								<input type="hidden" name="tab" value="problems" />
								<input type="hidden" name="problem_id" value="<?= $problem['id'] ?>" />
								<select class="form-control form-control-sm" name="judged_with" onchange="this.form.submit()">
									<option value="samples"<?= $problem['everything'] ? '' : ' selected="selected"' ?>>只测样例</option>
									<option value="everything"<?= $problem['everything'] ? ' selected="selected"' : '' ?>>测全部数据</option>
								</select>
								<noscript><button type="submit" class="btn btn-sm btn-outline-secondary ml-1">保存</button></noscript>
							</form>
						</td>
						<?php endif ?>
						<td class="text-right">
							<?php if (count($problems) > 1): ?>
							<form method="post" class="d-inline">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="move_problem" />
								<input type="hidden" name="tab" value="problems" />
								<input type="hidden" name="problem_id" value="<?= $problem['id'] ?>" />
								<button type="submit" name="direction" value="up" class="btn btn-sm btn-outline-secondary" title="上移一位"<?= $index > 0 ? '' : ' disabled="disabled"' ?>>上移</button>
								<button type="submit" name="direction" value="down" class="btn btn-sm btn-outline-secondary" title="下移一位"<?= $index < count($problems) - 1 ? '' : ' disabled="disabled"' ?>>下移</button>
							</form>
							<?php endif ?>
							<form method="post" class="d-inline" onsubmit="return confirm('把这道题从比赛里移除？');">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="remove_problem" />
								<input type="hidden" name="tab" value="problems" />
								<input type="hidden" name="problem_id" value="<?= $problem['id'] ?>" />
								<button type="submit" class="btn btn-sm btn-outline-danger">移除</button>
							</form>
						</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php else: ?>
		<div class="uoj-domain-empty" id="contest-no-problems">这场比赛还没有试题。</div>
		<?php endif ?>
		<form method="post" class="form-inline" id="form-add-contest-problem">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_problem" />
			<input type="hidden" name="tab" value="problems" />
			<label class="mr-2 mb-2" for="input-contest-problem-number">添加试题</label>
			<?php $contest_domain = $contest['domain_id'] ? queryDomain($contest['domain_id']) : null; ?>
			<input type="text" class="form-control mr-2 mb-2 uoj-problem-picker" id="input-contest-problem-number" name="number" required="required" placeholder="题号或标题的一部分" data-scope="<?= $contest_domain ? $contest_domain['slug'] : 'site' ?>" data-purpose="manage" data-multiple="" style="width:16em" />
			<button type="submit" class="btn btn-primary mb-2">添加</button>
		</form>
		<small class="form-text text-muted">
			输入题号或标题的一部分，从列出的题目里选，可以一次选几道；列出的是你管理的<?= $contest['domain_id'] ? '本域' : '' ?>题目。题目按表里的顺序编为 A、B、C……
			<?php if ($contest['domain_id']): ?>这是域内的比赛，用的是本域的题目：主站的题目要先在域的“题目”页复制到本域。<?php endif ?>
			<?php if ($rule === 'OI'): ?>OI 赛制下比赛中只用样例评测，结束后再用全部数据重测；个别题目可以在这里改成比赛中就用全部数据评测。<?php endif ?>
			比赛用的题目通常是隐藏的：比赛开始后选手能在比赛里看到它们；公布成绩之后要让所有人都能做，到题目的管理页把它设为公开。
		</small>
	</div>

	<div class="tab-pane<?= $active_tab === 'attachments' ? ' active' : '' ?>" id="tab-attachments">
		<p class="text-muted">比赛附件显示在比赛主页上，比如整场比赛的 PDF 题面。比赛开始之前只有工作人员能看到；开始之后，能进入比赛的人都能下载。单独一道题的附件在那道题的管理页里加。</p>
		<?php echoAttachmentsManager($attachments, 'attachments') ?>
	</div>
	
	<div class="tab-pane<?= $active_tab === 'delete' ? ' active' : '' ?>" id="tab-delete">
		<div class="card border-danger" id="card-delete-contest" style="max-width:48em">
			<div class="card-header bg-danger text-white">删除这场比赛</div>
			<div class="card-body">
				<p class="mb-2">删除之后<strong>不能恢复</strong>。</p>
				<ul id="contest-deletion-facts">
					<li><strong>会删除</strong>：<?= $deletion_facts['contestants'] ?> 位选手的报名和名次、榜单、公告、<?= $deletion_facts['questions'] ?> 条提问、名单和管理者<?= $deletion_facts['attachments'] > 0 ? '、' . $deletion_facts['attachments'] . ' 个附件' : '' ?>，以及在这场比赛上的虚拟参赛记录。</li>
					<li><strong>会留下</strong>：比赛里的 <?= $deletion_facts['problems'] ?> 道题目（是否公开保持现在的样子），和 <?= $deletion_facts['submissions'] ?> 份提交——它们变成这些题目的普通提交。</li>
					<?php if ($has_begun && $contest['cur_progress'] <= CONTEST_IN_PROGRESS): ?><li class="text-danger">这场比赛<strong>正在进行</strong>，删除后选手会立刻看不到它。</li><?php endif ?>
				</ul>
				<p class="text-muted small">已经计入的 Rating 不会退回。站点每天的自动备份里还留着删除之前的样子。</p>
				<form method="post" id="form-delete-contest">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="delete_contest" />
					<input type="hidden" name="tab" value="delete" />
					<div class="form-group">
						<label for="input-confirm-delete-contest">请输入这场比赛的名称 <strong><?= HTML::escape(contestDeletionWord($contest)) ?></strong> 确认</label>
						<input type="text" class="form-control" id="input-confirm-delete-contest" name="confirm" autocomplete="off" required="required" />
					</div>
					<button type="submit" class="btn btn-danger" id="button-delete-contest">删除这场比赛</button>
				</form>
			</div>
		</div>
	</div>

	<div class="tab-pane<?= $active_tab === 'contestants' ? ' active' : '' ?>" id="tab-contestants">
		<p class="text-muted">
			报名了的人就是选手。选手可以自己报名：比赛开始前，以及<strong>比赛进行中</strong>都可以（密码限制、名单限制照常生效）；比赛结束后不能再报名。
			你也可以在这里直接把人加进来或移出去，任何时候都行。座位号给现场赛用，气球页会显示它。
		</p>
		<h5>选手 <small class="text-muted">（<?= count($contestants) ?> 人）</small></h5>
		<?php if ($contestants): ?>
		<div class="table-responsive uoj-roster-box mb-3">
			<table class="table table-bordered table-hover table-sm uoj-roster" id="list-contestants" style="max-width:60em">
				<thead><tr><th style="width:4em">#</th><th>用户</th><th>学号</th><th>姓名</th><th style="width:15em">座位</th><th style="width:6em">操作</th></tr></thead>
				<tbody>
					<?php foreach ($contestants as $index => $contestant): ?>
					<?php $identity = isset($contestant_identities[$contestant['username']]) ? $contestant_identities[$contestant['username']] : array('student_id' => '', 'real_name' => ''); ?>
					<tr data-username="<?= $contestant['username'] ?>">
						<td><?= $index + 1 ?></td>
						<td><?= getUserLink($contestant['username']) ?></td>
						<td><?= HTML::escape($identity['student_id']) ?></td>
						<td><?= HTML::escape($identity['real_name']) ?></td>
						<td>
							<form method="post" class="form-inline justify-content-center flex-nowrap">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="set_seat" />
								<input type="hidden" name="tab" value="contestants" />
								<input type="hidden" name="username" value="<?= $contestant['username'] ?>" />
								<input type="text" class="form-control form-control-sm mr-1" name="seat" value="<?= HTML::escape($contestant['seat']) ?>" maxlength="20" placeholder="—" style="width:8em" />
								<button type="submit" class="btn btn-light btn-sm border">保存</button>
							</form>
						</td>
						<td>
							<form method="post">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="remove_contestant" />
								<input type="hidden" name="tab" value="contestants" />
								<input type="hidden" name="username" value="<?= $contestant['username'] ?>" />
								<button type="submit" class="btn btn-outline-danger btn-sm" title="移出比赛" onclick="return confirm('把 <?= $contestant['username'] ?> 移出这场比赛？他的提交不会删除。')">移出</button>
							</form>
						</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>
		<form method="post" id="form-add-contestants" style="max-width:48em">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_contestants" />
			<input type="hidden" name="tab" value="contestants" />
			<div class="form-group">
				<label for="input-contestants">加入选手，或者批量填座位</label>
				<textarea class="form-control" id="input-contestants" name="names" rows="5" placeholder="每行一个用户名或学号，后面可以跟座位号&#10;CS26010001 A-12&#10;CS26010002 A-13"></textarea>
				<small class="form-text text-muted">已经在比赛里的人，写了座位号就更新座位。只能加有账号的人：用学号登录过一次才有账号，还没登录过的人请放进“名单”，他登录后自己报名。</small>
			</div>
			<button type="submit" class="btn btn-primary">加入比赛</button>
		</form>
	</div>

	<div class="tab-pane<?= $active_tab === 'access' ? ' active' : '' ?>" id="tab-access">
		<p>
			当前的参加方式：<strong id="contest-join-mode"><?= HTML::escape(explode('：', contestJoinModes()[$contest['join_mode']])[0]) ?></strong>。
			<?php if ($contest['join_mode'] !== 'list'): ?>
			<span class="text-muted">名单只在“名单限制”时起作用，参加方式在“设置”页里选择。</span>
			<?php endif ?>
		</p>
		<h5>名单 <small class="text-muted">（<?= count($allowed_users) ?> 人）</small></h5>
		<?php if ($allowed_users): ?>
		<div class="table-responsive uoj-roster-box mb-3">
			<table class="table table-bordered table-hover table-sm uoj-roster" id="list-allowed-users" style="max-width:48em">
				<thead><tr><th style="width:4em">#</th><th>名单里写的</th><th>用户</th><th style="width:7em">操作</th></tr></thead>
				<tbody>
					<?php foreach ($allowed_users as $index => $allowed): ?>
					<tr>
						<td><?= $index + 1 ?></td>
						<td><?= HTML::escape($allowed['username']) ?></td>
						<td><?= $allowed['user'] !== null ? getUserLink($allowed['user']) : '<small class="text-muted">还没有登录过</small>' ?></td>
						<td>
							<form method="post">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="disallow" />
								<input type="hidden" name="tab" value="access" />
								<input type="hidden" name="username" value="<?= HTML::escape($allowed['username']) ?>" />
								<button type="submit" class="btn btn-outline-danger btn-sm" title="从名单里移除">移除</button>
							</form>
						</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>
		<form method="post" id="form-allow-users">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="allow" />
			<input type="hidden" name="tab" value="access" />
			<div class="form-group">
				<textarea class="form-control" name="names" rows="5" placeholder="每行一个用户名或学号"></textarea>
				<small class="form-text text-muted">写学号时，这个学号的学生不论用户名是什么、现在有没有登录过，都在名单里。把人从名单里移除不影响已经报名的人。</small>
			</div>
			<button type="submit" class="btn btn-outline-primary">加入名单</button>
		</form>
	</div>

	<div class="tab-pane<?= $active_tab === 'managers' ? ' active' : '' ?>" id="tab-managers">
		<?php if ($contest['domain_id']): ?>
		<p class="text-muted">这是域内的比赛：域的所有者、管理员和教师都能管理它，助教能进入后台。下面是另外指定的人。</p>
		<?php endif ?>
		<?php if ($managers): ?>
		<table class="table table-bordered table-hover table-sm uoj-roster" id="table-contest-managers" style="max-width:40em">
			<thead>
				<tr>
					<th>用户</th>
					<th style="width:8em">角色</th>
					<th style="width:6em"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($managers as $manager): ?>
				<tr>
					<td><?= getUserLink($manager['username']) ?></td>
					<td><?= $manager['role'] == 'owner' ? '负责人' : '助理' ?></td>
					<td>
						<form method="post" class="d-inline" onsubmit="return confirm('移除这位管理者？');">
							<?= HTML::hiddenToken() ?>
							<input type="hidden" name="form" value="remove_manager" />
							<input type="hidden" name="tab" value="managers" />
							<input type="hidden" name="username" value="<?= HTML::escape($manager['username']) ?>" />
							<button type="submit" class="btn btn-sm btn-outline-danger">移除</button>
						</form>
					</td>
				</tr>
				<?php endforeach ?>
			</tbody>
		</table>
		<?php endif ?>
		<form method="post" class="form-inline" id="form-add-contest-manager">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_manager" />
			<input type="hidden" name="tab" value="managers" />
			<label class="mr-2 mb-2" for="input-contest-manager">添加管理者</label>
			<input type="text" class="form-control mr-2 mb-2" id="input-contest-manager" name="username" required="required" maxlength="20" placeholder="用户名" style="width:12em" />
			<select class="form-control mr-2 mb-2" name="role">
				<option value="assistant">助理</option>
				<option value="owner">负责人</option>
			</select>
			<button type="submit" class="btn btn-primary mb-2">添加</button>
		</form>
		<small class="form-text text-muted">负责人可以修改比赛的设置、试题和人员，开始最终测试并公布成绩；助理可以进入后台、查看所有提交并回答提问。对已经在表里的人再添加一次，就是改他的角色。</small>
	</div>
</div>
<script type="text/javascript">
// the tab the address names is the one that is open, as after a form on it went through
$(document).ready(function() {
	if (/^#tab-[a-z]+$/.test(window.location.hash)) {
		$('#contest-manage-tabs a[href="' + window.location.hash + '"]').tab('show');
	}
	$('#contest-manage-tabs a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', $(e.target).attr('href'));
		}
	});
});
</script>
<?php echoUOJPageFooter() ?>
