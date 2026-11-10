<?php
	// the front page of a domain: what is going on in it
	$domain = domainOfPage();
	$now = homeworkNow();
	$can_teach = can($myUser, 'domain.teach', $domain);
	// the homework that runs or is to come, the soonest to end first
	$current_homeworks = array();
	foreach (DB::selectAll("select * from homeworks where domain_id = {$domain['id']} and status = 'published' order by end_at") as $homework) {
		$homework = homeworkTouch($homework);
		if (homeworkPhase($homework, $now) !== 'ended') {
			$current_homeworks[] = $homework;
		}
	}
	// the contests that run or are to come, for who may see them
	$current_contests = array();
	foreach (DB::selectAll("select * from contests where domain_id = {$domain['id']} order by start_time, id") as $contest) {
		genMoreContestInfo($contest);
		if ($contest['cur_progress'] <= CONTEST_IN_PROGRESS && can($myUser, 'contest.view', $contest)) {
			$current_contests[] = $contest;
		}
	}
	$trainings = array();
	foreach (DB::selectAll("select * from trainings where domain_id = {$domain['id']} and status = 'published' order by id") as $training) {
		$training_problems = trainingProblems($training);
		$training_ids = array();
		foreach ($training_problems as $problem) {
			$training_ids[] = (int)$problem['problem_id'];
		}
		$best = Auth::check() ? trainingBestScores($training_ids, array(Auth::id())) : array();
		$training['progress'] = trainingProgress($training_problems, isset($best[Auth::id()]) ? $best[Auth::id()] : array());
		$trainings[] = $training;
	}
	$announcements = domainAnnouncements($domain, 5);
	// what waits for the people who can do something about it
	$pending = $can_teach ? domainPendingWork($domain) : array();
	$my_role = permissionDomainRole($myUser, $domain);
?>
<?php echoDomainPageHeader($domain, 'overview', '概览') ?>
<div class="row">
	<div class="col-lg-9">
		<?php if ($pending): ?>
		<div class="card mb-4 border-warning" id="domain-overview-pending">
			<div class="card-header">待处理</div>
			<div class="list-group list-group-flush">
				<?php foreach ($pending as $item): ?>
				<a class="list-group-item list-group-item-action" href="<?= $item[3] ?>">
					<span class="badge badge-<?= $item[0] ?> mr-1"><?= HTML::escape($item[1]['title']) ?></span>
					<?= HTML::escape($item[2]) ?>
				</a>
				<?php endforeach ?>
			</div>
		</div>
		<?php endif ?>

		<div class="uoj-page-head">
			<h4>作业</h4>
			<a class="small" href="<?= domainUrl($domain, '/homeworks') ?>">全部作业</a>
		</div>
		<?php if (!$current_homeworks): ?>
		<div class="uoj-domain-empty mb-4" id="domain-overview-empty">现在没有进行中的作业。</div>
		<?php else: ?>
		<div class="table-responsive mb-4">
			<table class="table table-bordered table-hover table-striped uoj-list-table" id="table-overview-homeworks">
				<thead>
					<tr><th class="uoj-list-main">作业</th><th style="width:11em">截止时间</th><th style="width:12em"><?= $my_role === 'member' ? '我的得分' : '题目' ?></th></tr>
				</thead>
				<tbody>
					<?php foreach ($current_homeworks as $homework): ?>
					<?php
						$phase_name = homeworkPhaseName(homeworkPhase($homework, $now));
						$participation = Auth::check() ? homeworkParticipation($homework['id'], Auth::id()) : null;
						$is_participant = $participation && $participation['status'] === 'active';
						$points = homeworkProblemPoints($homework);
						$mine = $is_participant && $now >= strtotime($homework['begin_at']) ? homeworkTotal(homeworkLiveScores($homework), Auth::id()) : null;
					?>
					<tr>
						<td class="uoj-list-main">
							<a href="<?= homeworkUrl($domain, $homework) ?>"><?= HTML::escape($homework['title']) ?></a>
							<sup><span class="badge <?= $phase_name[1] ?>"><?= $phase_name[0] ?></span></sup>
						</td>
						<td><small><?= substr($homework['end_at'], 0, 16) ?></small></td>
						<td>
							<?php if ($mine !== null && array_sum($points) > 0): ?>
							<div class="uoj-score-bar" title="<?= homeworkTrimNumber($mine) ?> / <?= array_sum($points) ?>">
								<div class="progress"><div class="progress-bar bg-success" style="width: <?= round(100 * $mine / array_sum($points)) ?>%"></div></div>
								<small><?= homeworkTrimNumber($mine) ?> / <?= array_sum($points) ?></small>
							</div>
							<?php elseif ($is_participant): ?>
							<span class="badge badge-success">已认领</span>
							<?php elseif (can($myUser, 'homework.claim', $homework)): ?>
							<a class="btn btn-primary btn-sm" href="<?= homeworkUrl($domain, $homework) ?>">去认领</a>
							<?php else: ?>
							<small class="text-muted"><?= count($points) ?> 题，共 <?= array_sum($points) ?> 分</small>
							<?php endif ?>
						</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>

		<?php if ($current_contests): ?>
		<div class="uoj-page-head">
			<h4>比赛</h4>
			<a class="small" href="<?= domainUrl($domain, '/contests') ?>">全部比赛</a>
		</div>
		<div class="table-responsive mb-4">
			<table class="table table-bordered table-hover table-striped uoj-list-table" id="table-overview-contests">
				<thead>
					<tr><th class="uoj-list-main">比赛</th><th style="width:11em">开始时间</th><th style="width:12em">时长</th></tr>
				</thead>
				<tbody>
					<?php foreach ($current_contests as $contest): ?>
					<tr>
						<td class="uoj-list-main">
							<a href="/contest/<?= $contest['id'] ?>"><?= $contest['name'] ?></a>
							<sup><span class="badge <?= $contest['cur_progress'] == CONTEST_IN_PROGRESS ? 'badge-success' : 'badge-info' ?>"><?= $contest['cur_progress'] == CONTEST_IN_PROGRESS ? '进行中' : '未开始' ?></span></sup>
						</td>
						<td><small><?= $contest['start_time_str'] ?></small></td>
						<td><?= round($contest['last_min'] / 60, 1) ?> 小时</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>

		<?php if ($trainings): ?>
		<div class="uoj-page-head">
			<h4>训练</h4>
			<a class="small" href="<?= domainUrl($domain, '/trainings') ?>">全部训练</a>
		</div>
		<div class="table-responsive mb-4" id="domain-overview-trainings">
			<table class="table table-bordered table-hover table-striped uoj-list-table">
				<thead>
					<tr><th class="uoj-list-main">训练</th><th style="width:12em">进度</th></tr>
				</thead>
				<tbody>
					<?php foreach ($trainings as $training): ?>
					<tr>
						<td class="uoj-list-main"><a href="<?= trainingUrl($domain, $training) ?>"><?= HTML::escape($training['title']) ?></a></td>
						<td>
							<?php if ($training['progress']['done']): ?>
							<span class="badge badge-success">已完成</span>
							<?php else: ?>
							<div class="uoj-score-bar">
								<div class="progress"><div class="progress-bar bg-success" style="width: <?= $training['progress']['total'] > 0 ? round(100 * $training['progress']['solved'] / $training['progress']['total']) : 0 ?>%"></div></div>
								<small><?= $training['progress']['solved'] ?> / <?= $training['progress']['total'] ?></small>
							</div>
							<?php endif ?>
						</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>
	</div>

	<div class="col-lg-3 uoj-side">
		<div class="card" id="card-domain-about">
			<div class="card-body">
				<h5 class="card-title mb-1"><?= HTML::escape($domain['name']) ?></h5>
				<p class="text-muted small mb-2">
					<?= domainTypes()[$domain['type']] ?> <span class="mx-1">·</span>
					<a href="<?= domainUrl($domain, '/members') ?>"><?= domainMemberCount($domain) ?> 人</a> <span class="mx-1">·</span>
					<?= getUserLink($domain['owner_username']) ?>
				</p>
				<?php if (trim($domain['description']) !== ''): ?>
				<div class="uoj-domain-description small"><?= HTML::escape($domain['description']) ?></div>
				<?php endif ?>
			</div>
		</div>
		<?php if ($can_teach): ?>
		<div class="card" id="card-domain-shortcuts">
			<div class="list-group list-group-flush">
				<a class="list-group-item list-group-item-action" href="<?= domainUrl($domain, '/homework/new') ?>"><span class="glyphicon glyphicon-pencil"></span> 布置作业</a>
				<a class="list-group-item list-group-item-action" href="<?= domainUrl($domain, '/contest/new') ?>"><span class="glyphicon glyphicon-stats"></span> 新建比赛</a>
				<a class="list-group-item list-group-item-action" href="<?= domainUrl($domain, '/training/new') ?>"><span class="glyphicon glyphicon-road"></span> 新建训练</a>
				<a class="list-group-item list-group-item-action" href="<?= domainUrl($domain, '/problem/new') ?>"><span class="glyphicon glyphicon-list-alt"></span> 新建题目</a>
			</div>
		</div>
		<?php endif ?>
		<div class="card" id="card-announcements">
			<div class="card-header d-flex">
				<span class="mr-auto">公告</span>
				<a href="<?= domainUrl($domain, '/announcements') ?>" class="small">全部</a>
			</div>
			<?php if (!$announcements): ?>
			<div class="card-body text-muted small">暂无公告</div>
			<?php else: ?>
			<div class="list-group list-group-flush">
				<?php foreach ($announcements as $announcement): ?>
				<a class="list-group-item list-group-item-action" href="<?= domainUrl($domain, '/announcements') ?>#announcement-<?= $announcement['id'] ?>">
					<?php if ($announcement['pinned']): ?><span class="badge badge-warning">置顶</span><?php endif ?>
					<?= HTML::escape($announcement['title']) ?>
					<small class="text-muted d-block"><?= substr($announcement['created_at'], 0, 10) ?></small>
				</a>
				<?php endforeach ?>
			</div>
			<?php endif ?>
		</div>
	</div>
</div>
<?php echoUOJPageFooter() ?>
