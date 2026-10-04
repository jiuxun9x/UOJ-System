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
<?php echoDomainPageHeader($domain, 'homeworks', '作业') ?>

<?php if ($can_teach): ?>
<div class="text-right mb-3">
	<a class="btn btn-primary" href="<?= domainUrl($domain, '/homework/new') ?>" id="button-new-homework"><span class="glyphicon glyphicon-plus"></span> 布置作业</a>
</div>
<?php endif ?>

<?php if (!$homeworks): ?>
<div class="uoj-domain-empty">这个域还没有作业。</div>
<?php endif ?>
<?php foreach ($homeworks as $homework): ?>
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
<div class="card mb-3 uoj-homework-card" id="homework-<?= $homework['id'] ?>">
	<div class="card-body">
		<div class="d-flex flex-wrap align-items-start">
			<h5 class="card-title mr-auto mb-1">
				<a href="<?= homeworkUrl($domain, $homework) ?>"><?= HTML::escape($homework['title']) ?></a>
				<span class="badge <?= $phase_name[1] ?>"><?= $phase_name[0] ?></span>
			</h5>
			<div>
				<?php if ($is_participant): ?>
				<span class="badge badge-success">已认领</span>
				<?php elseif (can($myUser, 'homework.claim', $homework)): ?>
				<a class="btn btn-primary btn-sm" href="<?= homeworkUrl($domain, $homework) ?>">去认领</a>
				<?php elseif (permissionDomainRole($myUser, $domain) === 'member' && $homework['status'] === 'published'): ?>
				<span class="badge badge-secondary">未认领</span>
				<?php endif ?>
			</div>
		</div>
		<p class="text-muted small mb-2">
			<?= substr($homework['begin_at'], 0, 16) ?> 开始
			<?php if ($homework['penalty_since'] !== null && $homework['penalty_since'] !== $homework['end_at']): ?>
			<span class="mx-1">·</span> <?= substr($homework['penalty_since'], 0, 16) ?> 起算迟交
			<?php endif ?>
			<span class="mx-1">·</span> <?= substr($homework['end_at'], 0, 16) ?> 截止
			<span class="mx-1">·</span> <?= count($points) ?> 题，共 <?= array_sum($points) ?> 分
		</p>
		<?php if ($mine !== null && array_sum($points) > 0): ?>
		<div class="d-flex align-items-center">
			<div class="progress flex-grow-1 mr-2" style="height: 0.6rem">
				<div class="progress-bar bg-success" style="width: <?= round(100 * $mine / array_sum($points)) ?>%"></div>
			</div>
			<small><?= homeworkTrimNumber($mine) ?> / <?= array_sum($points) ?></small>
		</div>
		<?php endif ?>
		<?php if ($homework['status'] === 'draft' && $homework['publish_error'] !== null): ?>
		<div class="text-danger small">发布失败：<?= HTML::escape($homework['publish_error']) ?></div>
		<?php endif ?>
	</div>
</div>
<?php endforeach ?>
<?php echoUOJPageFooter() ?>
