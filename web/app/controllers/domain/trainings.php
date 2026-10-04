<?php
	// the trainings of a domain, each with what the user has done of it
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
	$trainings = array();
	foreach (DB::selectAll("select * from trainings where domain_id = {$domain['id']} order by id") as $training) {
		if (can($myUser, 'training.view', $training)) {
			$trainings[] = $training;
		}
	}
?>
<?php echoDomainPageHeader($domain, 'trainings', '训练') ?>

<?php if ($can_teach): ?>
<div class="text-right mb-3">
	<a class="btn btn-primary" href="<?= domainUrl($domain, '/training/new') ?>" id="button-new-training"><span class="glyphicon glyphicon-plus"></span> 新建训练</a>
</div>
<?php endif ?>

<?php if (!$trainings): ?>
<div class="uoj-domain-empty">这个域还没有训练。训练是一份按顺序排好的题单，没有截止时间，做完一题算一题。</div>
<?php endif ?>
<?php foreach ($trainings as $training): ?>
<?php
	$problems = trainingProblems($training);
	$ids = array();
	foreach ($problems as $problem) {
		$ids[] = (int)$problem['problem_id'];
	}
	$best = Auth::check() ? trainingBestScores($ids, array(Auth::id())) : array();
	$progress = trainingProgress($problems, isset($best[Auth::id()]) ? $best[Auth::id()] : array());
?>
<div class="card mb-3" id="training-<?= $training['id'] ?>">
	<div class="card-body">
		<div class="d-flex flex-wrap align-items-start">
			<h5 class="card-title mr-auto mb-1">
				<a href="<?= trainingUrl($domain, $training) ?>"><?= HTML::escape($training['title']) ?></a>
				<?php if ($training['status'] === 'draft'): ?>
				<span class="badge badge-secondary">草稿</span>
				<?php endif ?>
			</h5>
			<?php if ($progress['done']): ?>
			<span class="badge badge-success">已完成</span>
			<?php endif ?>
		</div>
		<p class="text-muted small mb-2">
			<?= $progress['total'] ?> 题<?php if ($progress['required'] > 0 && $progress['required'] < $progress['total']): ?>，其中 <?= $progress['required'] ?> 题必做<?php endif ?>
		</p>
		<?php if ($progress['total'] > 0): ?>
		<div class="d-flex align-items-center">
			<div class="progress flex-grow-1 mr-2" style="height: 0.6rem">
				<div class="progress-bar bg-success" style="width: <?= round(100 * $progress['solved'] / $progress['total']) ?>%"></div>
			</div>
			<small class="uoj-training-progress"><?= $progress['solved'] ?> / <?= $progress['total'] ?></small>
		</div>
		<?php endif ?>
	</div>
</div>
<?php endforeach ?>
<?php echoUOJPageFooter() ?>
