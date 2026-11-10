<?php
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
	$homeworks = array();
	foreach (DB::selectAll("select * from homeworks where domain_id = {$domain['id']} order by begin_at desc, id desc") as $homework) {
		$homework = homeworkTouch($homework);
		if (can($myUser, 'homework.view', $homework)) {
			$homeworks[] = $homework;
		}
	}
	$now = homeworkNow();
?>
<?php
	// the lists of the page, as the contests of the site are listed: what runs or is to come,
	// what is over, and for the people who set them the ones that are not published yet
	$lists = array('draft' => array(), 'current' => array(), 'ended' => array());
	foreach ($homeworks as $homework) {
		$phase = homeworkPhase($homework, $now);
		$lists[$phase === 'draft' || $phase === 'publishing' ? 'draft' : ($phase === 'ended' ? 'ended' : 'current')][] = $homework;
	}
	// the soonest to end first among what runs
	usort($lists['current'], function($a, $b) {
		return strcmp($a['end_at'], $b['end_at']);
	});
	$list_names = array('draft' => '还没有发布的作业', 'current' => '正在进行或即将开始的作业', 'ended' => '已截止的作业');
	$my_role = permissionDomainRole($myUser, $domain);
?>
<?php echoDomainPageHeader($domain, 'homeworks', '作业') ?>
<?php if (!$homeworks): ?>
<div class="uoj-page-head">
	<h4>作业</h4>
	<?php if ($can_teach): ?>
	<a class="btn btn-primary" href="<?= domainUrl($domain, '/homework/new') ?>" id="button-new-homework"><span class="glyphicon glyphicon-plus"></span> 布置作业</a>
	<?php endif ?>
</div>
<div class="uoj-domain-empty">这个域还没有作业。</div>
<?php endif ?>
<?php $first = true; ?>
<?php foreach ($lists as $list => $rows): ?>
<?php if ($rows): ?>
<div class="uoj-page-head<?= $first ? '' : ' mt-4' ?>">
	<h4><?= $list_names[$list] ?></h4>
	<?php if ($first && $can_teach): ?>
	<a class="btn btn-primary" href="<?= domainUrl($domain, '/homework/new') ?>" id="button-new-homework"><span class="glyphicon glyphicon-plus"></span> 布置作业</a>
	<?php endif ?>
</div>
<?php $first = false; ?>
<div class="table-responsive">
	<table class="table table-bordered table-hover table-striped uoj-list-table" id="table-homeworks-<?= $list ?>">
		<thead>
			<tr>
				<th class="uoj-list-main">作业</th>
				<th style="width:10em" class="d-none d-md-table-cell">开始时间</th>
				<th style="width:10em">截止时间</th>
				<th style="width:8em" class="d-none d-md-table-cell">题目</th>
				<th style="width:11em"><?= $my_role === 'member' ? '我的成绩' : '参加人数' ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($rows as $homework): ?>
			<?php
				$phase = homeworkPhase($homework, $now);
				$phase_name = homeworkPhaseName($phase);
				$participation = Auth::check() ? homeworkParticipation($homework['id'], Auth::id()) : null;
				$is_participant = $participation && $participation['status'] === 'active';
				$points = homeworkProblemPoints($homework);
				$mine = null;
				if ($is_participant && $phase !== 'upcoming') {
					$official = homeworkOfficialScores($homework);
					$mine = homeworkTotal($official !== null ? $official : homeworkLiveScores($homework), Auth::id());
				}
			?>
			<tr class="uoj-homework-card" id="homework-<?= $homework['id'] ?>">
				<td class="uoj-list-main">
					<a href="<?= homeworkUrl($domain, $homework) ?>"><?= HTML::escape($homework['title']) ?></a>
					<sup><span class="badge <?= $phase_name[1] ?>"><?= $phase_name[0] ?></span></sup>
					<?php if ($homework['status'] === 'draft' && $homework['publish_error'] !== null): ?>
					<div class="text-danger small">发布失败：<?= HTML::escape($homework['publish_error']) ?></div>
					<?php endif ?>
				</td>
				<td class="d-none d-md-table-cell"><small><?= substr($homework['begin_at'], 0, 16) ?></small></td>
				<td>
					<small><?= substr($homework['end_at'], 0, 16) ?></small>
					<?php if ($homework['penalty_since'] !== null && $homework['penalty_since'] !== $homework['end_at']): ?>
					<small class="text-muted d-block" title="在这之后提交算迟交，按比例计分"><?= substr($homework['penalty_since'], 5, 11) ?> 起算迟交</small>
					<?php endif ?>
				</td>
				<td class="d-none d-md-table-cell"><small><?= count($points) ?> 题，共 <?= array_sum($points) ?> 分</small></td>
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
					<?php elseif ($my_role === 'member' && $homework['status'] === 'published'): ?>
					<span class="badge badge-secondary">未认领</span>
					<?php elseif ($my_role !== 'member' && $homework['status'] === 'published'): ?>
					<span class="glyphicon glyphicon-user"></span> &times;<?= count(homeworkParticipants($homework)) ?>
					<?php endif ?>
				</td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php endforeach ?>
<?php echoUOJPageFooter() ?>
