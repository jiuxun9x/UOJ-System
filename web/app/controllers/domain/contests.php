<?php
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
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
<?php if ($can_teach): ?>
<div class="card mb-3">
	<div class="card-body d-flex flex-wrap align-items-center">
		<a class="btn btn-primary mr-3" id="button-new-domain-contest" href="<?= domainUrl($domain, '/contest/new') ?>"><span class="glyphicon glyphicon-plus"></span> 新建比赛</a>
		<small class="text-muted">域内的比赛只有成员能看到和报名，不计入全站 Rating，用的是本域的题目。</small>
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
