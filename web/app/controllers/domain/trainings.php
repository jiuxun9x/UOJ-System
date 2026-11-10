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
<div class="uoj-page-head">
	<h4>训练</h4>
	<?php if ($can_teach): ?>
	<a class="btn btn-primary" href="<?= domainUrl($domain, '/training/new') ?>" id="button-new-training"><span class="glyphicon glyphicon-plus"></span> 新建训练</a>
	<?php endif ?>
</div>
<?php if (!$trainings): ?>
<div class="uoj-domain-empty">这个域还没有训练。训练是一份按顺序排好的题单，没有截止时间，做完一题算一题。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-bordered table-hover table-striped uoj-list-table" id="table-trainings">
		<thead>
			<tr>
				<th class="uoj-list-main">训练</th>
				<th style="width:10em">题目</th>
				<th style="width:14em">我的进度</th>
			</tr>
		</thead>
		<tbody>
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
			<tr id="training-<?= $training['id'] ?>">
				<td class="uoj-list-main">
					<a href="<?= trainingUrl($domain, $training) ?>"><?= HTML::escape($training['title']) ?></a>
					<?php if ($training['status'] === 'draft'): ?>
					<sup><span class="badge badge-secondary">草稿</span></sup>
					<?php endif ?>
				</td>
				<td><small><?= $progress['total'] ?> 题<?php if ($progress['required'] > 0 && $progress['required'] < $progress['total']): ?>，其中 <?= $progress['required'] ?> 题必做<?php endif ?></small></td>
				<td>
					<?php if ($progress['done']): ?>
					<span class="badge badge-success">已完成</span>
					<?php elseif ($progress['total'] > 0): ?>
					<div class="uoj-score-bar">
						<div class="progress"><div class="progress-bar bg-success" style="width: <?= round(100 * $progress['solved'] / $progress['total']) ?>%"></div></div>
						<small class="uoj-training-progress"><?= $progress['solved'] ?> / <?= $progress['total'] ?></small>
					</div>
					<?php endif ?>
				</td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
