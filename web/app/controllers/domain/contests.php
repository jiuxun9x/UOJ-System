<?php
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
	$draft = array(
		'name' => isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '',
		'start_time' => isset($_POST['start_time']) && is_string($_POST['start_time']) ? $_POST['start_time'] : date('Y-m-d H:00:00', time() + 86400),
		'last_min' => isset($_POST['last_min']) && is_string($_POST['last_min']) ? $_POST['last_min'] : '180'
	);
	$error = domainHandleForms(array(
		'new' => function() use ($domain, $can_teach, $draft) {
			global $myUser;
			if (!$can_teach) {
				return '没有权限';
			}
			if (trim($draft['name']) === '' || mb_strlen($draft['name'], 'UTF-8') > 100) {
				return '比赛标题不能为空，且不超过 100 个字符';
			}
			try {
				$start_time = new DateTime($draft['start_time']);
			} catch (Exception $e) {
				return '无效的开始时间';
			}
			if (!validateUInt($draft['last_min']) || $draft['last_min'] < 1 || $draft['last_min'] > 525600) {
				return '时长必须是正整数（分钟）';
			}
			$contest_id = contestCreate(trim($draft['name']), $start_time->format('Y-m-d H:i:s'), $draft['last_min'], $myUser, $domain);
			redirectTo("/contest/$contest_id/manage");
		}
	));
	
	$contests = array();
	foreach (DB::selectAll("select * from contests where domain_id = {$domain['id']} order by start_time desc, id desc") as $contest) {
		// a contest for the people on a list is shown to them
		if (can($myUser, 'contest.view', $contest)) {
			$contests[] = $contest;
		}
	}
	$progress_names = array(
		CONTEST_NOT_STARTED => array('未开始', 'badge-info'),
		CONTEST_IN_PROGRESS => array('进行中', 'badge-success'),
		CONTEST_PENDING_FINAL_TEST => array('等待评测', 'badge-warning'),
		CONTEST_TESTING => array('正在评测', 'badge-warning'),
		CONTEST_FINISHED => array('已结束', 'badge-secondary')
	);
?>
<?php echoDomainPageHeader($domain, 'contests', '比赛') ?>
<?php echoDomainError($error) ?>

<?php if ($can_teach): ?>
<div class="card mb-3">
	<div class="card-body">
		<form method="post" class="form-row align-items-end" id="form-new-domain-contest">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="new" />
			<div class="form-group col-md-5">
				<label for="input-contest-name">比赛标题</label>
				<input type="text" class="form-control" id="input-contest-name" name="name" maxlength="100" required="required" value="<?= HTML::escape($draft['name']) ?>" />
			</div>
			<div class="form-group col-md-3">
				<label for="input-contest-start">开始时间</label>
				<input type="text" class="form-control" id="input-contest-start" name="start_time" required="required" value="<?= HTML::escape($draft['start_time']) ?>" placeholder="2026-10-12 14:00:00" />
			</div>
			<div class="form-group col-md-2">
				<label for="input-contest-minutes">时长（分钟）</label>
				<input type="number" class="form-control" id="input-contest-minutes" name="last_min" min="1" required="required" value="<?= HTML::escape($draft['last_min']) ?>" />
			</div>
			<div class="form-group col-md-2">
				<button type="submit" class="btn btn-primary btn-block">新建比赛</button>
			</div>
		</form>
		<small class="text-muted">域内的比赛只有成员能看到和报名，不计入全站 Rating。创建后在比赛管理里添加试题：可以用本域的题目，也可以用全站公开的题目。</small>
	</div>
</div>
<?php endif ?>

<?php if (!$contests): ?>
<div class="uoj-domain-empty">这个域还没有比赛。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-hover" id="table-domain-contests">
		<thead>
			<tr>
				<th>比赛</th>
				<th style="width:11em">开始时间</th>
				<th style="width:6em">时长</th>
				<th style="width:6em">报名人数</th>
				<th style="width:7em">状态</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($contests as $contest): ?>
			<?php genMoreContestInfo($contest); ?>
			<tr>
				<td>
					<a href="/contest/<?= $contest['id'] ?>"><?= $contest['name'] ?></a>
					<?php if ($contest['cur_progress'] == CONTEST_NOT_STARTED && Auth::check() && !can($myUser, 'contest.assist', $contest)): ?>
					<?php if (hasRegistered($myUser, $contest)): ?>
					<span class="badge badge-success">已报名</span>
					<?php else: ?>
					<a class="badge badge-primary" href="/contest/<?= $contest['id'] ?>/register">报名</a>
					<?php endif ?>
					<?php endif ?>
				</td>
				<td><small><?= $contest['start_time_str'] ?></small></td>
				<td><?= round($contest['last_min'] / 60, 1) ?> 小时</td>
				<td><a href="/contest/<?= $contest['id'] ?>/registrants"><?= $contest['player_num'] ?></a></td>
				<td><span class="badge <?= $progress_names[$contest['cur_progress']][1] ?>"><?= $progress_names[$contest['cur_progress']][0] ?></span></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
