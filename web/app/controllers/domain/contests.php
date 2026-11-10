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
<?php
	// as the contests of the site are listed: what runs or is to come, the soonest first, and what is over
	$lists = array('current' => array(), 'ended' => array());
	foreach ($contests as $contest) {
		genMoreContestInfo($contest);
		$lists[$contest['cur_progress'] <= CONTEST_IN_PROGRESS ? 'current' : 'ended'][] = $contest;
	}
	$lists['current'] = array_reverse($lists['current']);
	$list_names = array('current' => '正在进行或即将到来的比赛', 'ended' => '已结束的比赛');
	$rule_names = array('OI' => 'OI', 'IOI' => 'IOI', 'ICPC' => 'ICPC');
?>
<?php echoDomainPageHeader($domain, 'contests', '比赛') ?>
<?php if (!$contests): ?>
<div class="uoj-page-head">
	<h4>比赛</h4>
	<?php if ($can_teach): ?>
	<a class="btn btn-primary" id="button-new-domain-contest" href="<?= domainUrl($domain, '/contest/new') ?>"><span class="glyphicon glyphicon-plus"></span> 新建比赛</a>
	<?php endif ?>
</div>
<div class="uoj-domain-empty">这个域还没有比赛。</div>
<?php endif ?>
<?php $first = true; ?>
<?php foreach ($lists as $list => $rows): ?>
<?php if ($rows): ?>
<div class="uoj-page-head<?= $first ? '' : ' mt-4' ?>">
	<h4><?= $list_names[$list] ?></h4>
	<?php if ($first && $can_teach): ?>
	<a class="btn btn-primary" id="button-new-domain-contest" href="<?= domainUrl($domain, '/contest/new') ?>"><span class="glyphicon glyphicon-plus"></span> 新建比赛</a>
	<?php endif ?>
</div>
<div class="table-responsive">
	<table class="table table-bordered table-hover table-striped uoj-list-table"<?= $first ? ' id="table-domain-contests"' : '' ?>>
		<thead>
			<tr>
				<th class="uoj-list-main">比赛名称</th>
				<th style="width:11em">开始时间</th>
				<th style="width:6em">时长</th>
				<th style="width:6em">赛制</th>
				<th style="width:6em">报名人数</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($rows as $contest): ?>
			<?php $rule = contestRule($contest); ?>
			<tr>
				<td class="uoj-list-main">
					<a href="/contest/<?= $contest['id'] ?>"><?= $contest['name'] ?></a>
					<sup><span class="badge <?= $progress_names[$contest['cur_progress']][1] ?>"><?= $progress_names[$contest['cur_progress']][0] ?></span></sup>
					<?php if (contestIsHidden($contest)): ?>
					<sup><span class="badge badge-dark">隐藏</span></sup>
					<?php endif ?>
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
				<td><?= isset($rule_names[$rule]) ? $rule_names[$rule] : HTML::escape($rule) ?></td>
				<td><a href="/contest/<?= $contest['id'] ?>/registrants"><span class="glyphicon glyphicon-user"></span> &times;<?= $contest['player_num'] ?></a></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php $first = false; ?>
<?php endif ?>
<?php endforeach ?>
<?php echoUOJPageFooter() ?>
