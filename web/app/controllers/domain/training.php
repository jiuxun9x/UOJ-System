<?php
	// a training: its problems with what the user has done of them, and for the people who
	// look after the domain what every student has done
	$domain = domainOfPage();
	$training = queryTraining($_GET['training_id']);
	if (!$training || $training['domain_id'] != $domain['id'] || !can($myUser, 'training.view', $training)) {
		become404Page();
	}
	$can_manage = can($myUser, 'training.manage', $training);
	$can_view_progress = can($myUser, 'training.view_progress', $training);
	$view = $can_view_progress && isset($_GET['view']) && $_GET['view'] === 'progress' ? 'progress' : 'problems';
	$here = trainingUrl($domain, $training);
	
	$problems = trainingProblems($training);
	$ids = array();
	foreach ($problems as $problem) {
		$ids[] = (int)$problem['problem_id'];
	}
	
	if ($view === 'progress') {
		// the students of the domain, the ones who have done the most first
		$students = array();
		foreach (domainMembers($domain) as $member) {
			if ($member['role'] === 'member') {
				$students[] = $member['username'];
			}
		}
		$best = trainingBestScores($ids, $students);
		$rows = array();
		foreach ($students as $username) {
			$rows[$username] = trainingProgress($problems, isset($best[$username]) ? $best[$username] : array());
		}
		uksort($rows, function($a, $b) use ($rows) {
			return $rows[$b]['solved'] != $rows[$a]['solved'] ? $rows[$b]['solved'] - $rows[$a]['solved'] : strcmp($a, $b);
		});
		$identities = array();
		if ($students) {
			$names = implode(',', array_map(function($name) {
				return "'".DB::escape($name)."'";
			}, $students));
			foreach (DB::selectAll("select username, student_id, real_name from external_identities where username in ($names) order by id desc") as $row) {
				$identities[$row['username']] = $row;
			}
		}
		
		if (isset($_GET['export'])) {
			$head = array('username', 'student_id', 'real_name');
			foreach ($problems as $problem) {
				$head[] = '#' . $problem['problem_id'];
			}
			$lines = array(array_merge($head, array('solved', 'done')));
			foreach ($rows as $username => $progress) {
				$line = array($username, isset($identities[$username]) ? $identities[$username]['student_id'] : '', isset($identities[$username]) ? $identities[$username]['real_name'] : '');
				foreach ($ids as $id) {
					$line[] = isset($best[$username][$id]) ? $best[$username][$id] : '';
				}
				$lines[] = array_merge($line, array($progress['solved'], $progress['done'] ? 'yes' : 'no'));
			}
			auditLog('training.export_progress', 'training', $training['id'], null, array('rows' => count($lines) - 1));
			header('Content-Type: text/csv; charset=utf-8');
			header("Content-Disposition: attachment; filename=training_{$training['id']}_progress.csv");
			// the mark that tells a spreadsheet the file is UTF-8
			echo "\xEF\xBB\xBF";
			$out = fopen('php://output', 'w');
			foreach ($lines as $line) {
				fputcsv($out, $line);
			}
			die();
		}
	} else {
		$best = Auth::check() ? trainingBestScores($ids, array(Auth::id())) : array();
		$mine = isset($best[Auth::id()]) ? $best[Auth::id()] : array();
		$progress = trainingProgress($problems, $mine);
	}
?>
<?php echoDomainPageHeader($domain, 'trainings', $training['title']) ?>
<div class="d-flex flex-wrap align-items-start mb-2">
	<h3 class="mr-auto mb-2">
		<?= HTML::escape($training['title']) ?>
		<?php if ($training['status'] === 'draft'): ?>
		<span class="badge badge-secondary" style="font-size:0.8rem">草稿</span>
		<?php endif ?>
	</h3>
	<div class="mb-2">
		<?php if ($can_manage): ?>
		<a class="btn btn-outline-secondary btn-sm" href="<?= $here ?>/manage" id="link-manage-training"><span class="glyphicon glyphicon-cog"></span> 管理</a>
		<?php endif ?>
	</div>
</div>
<?php if ($can_view_progress): ?>
<ul class="nav nav-pills mb-3">
	<li class="nav-item"><a class="nav-link<?= $view === 'problems' ? ' active' : '' ?>" href="<?= $here ?>">题目</a></li>
	<li class="nav-item"><a class="nav-link<?= $view === 'progress' ? ' active' : '' ?>" href="<?= $here ?>?view=progress" id="link-training-progress">学生完成情况</a></li>
</ul>
<?php endif ?>

<?php if ($view === 'problems'): ?>
<?php if (trim($training['description']) !== ''): ?>
<div class="card mb-3"><div class="card-body"><article class="uoj-article"><?= $training['description'] ?></article></div></div>
<?php endif ?>
<?php if (!$problems): ?>
<div class="uoj-domain-empty">这份训练里还没有题目。</div>
<?php else: ?>
<div class="d-flex align-items-center mb-3" id="training-progress">
	<div class="progress flex-grow-1 mr-3" style="height: 0.8rem">
		<div class="progress-bar bg-success" style="width: <?= round(100 * $progress['solved'] / $progress['total']) ?>%"></div>
	</div>
	<span>已通过 <strong><?= $progress['solved'] ?></strong> / <?= $progress['total'] ?> 题</span>
	<?php if ($progress['done']): ?>
	<span class="badge badge-success ml-2">已完成</span>
	<?php endif ?>
</div>
<div class="table-responsive">
	<table class="table table-hover" id="table-training-problems">
		<thead>
			<tr>
				<th style="width:3em">#</th>
				<th>题目</th>
				<th style="width:8em">我的成绩</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($problems as $index => $problem): ?>
			<?php $score = isset($mine[(int)$problem['problem_id']]) ? $mine[(int)$problem['problem_id']] : null; ?>
			<tr data-problem="<?= $problem['problem_id'] ?>">
				<td><?= $index + 1 ?></td>
				<td>
					<a href="<?= trainingProblemUrl($domain, $problem) ?>"><?= $problem['title'] ?></a>
					<?php if (!$problem['required']): ?><span class="badge badge-light border">选做</span><?php endif ?>
					<?php if ($problem['is_hidden'] && $can_manage): ?>
					<span class="badge badge-warning" title="这道题现在是隐藏的，学生打不开。请到题目管理里取消隐藏。">学生看不到</span>
					<?php endif ?>
				</td>
				<td>
					<?php if ($score === null): ?>
					<span class="text-muted">未提交</span>
					<?php elseif ($score >= 100): ?>
					<span class="text-success"><span class="glyphicon glyphicon-ok"></span> 已通过</span>
					<?php else: ?>
					<span class="uoj-score" data-max="100"><?= $score ?></span> 分
					<?php endif ?>
				</td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php else: ?>
<div class="d-flex flex-wrap align-items-center mb-2">
	<p class="text-muted mr-auto mb-2">每格是这名学生在这道题上的最好成绩，不论是在哪里交的。只列出角色为学生的成员，共 <?= count($rows) ?> 人。</p>
	<a class="btn btn-outline-primary btn-sm mb-2" href="<?= $here ?>?view=progress&amp;export=1" id="link-export-progress">导出 CSV</a>
</div>
<?php if (!$rows): ?>
<div class="uoj-domain-empty">这个域里还没有学生。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-bordered table-sm uoj-scoreboard" id="table-training-progress">
		<thead>
			<tr>
				<th class="uoj-scoreboard-name">学生</th>
				<?php if ($identities): ?>
				<th>学号</th>
				<th>姓名</th>
				<?php endif ?>
				<?php foreach ($problems as $index => $problem): ?>
				<th title="<?= HTML::escape(strip_tags($problem['title'])) ?>"><a href="<?= trainingProblemUrl($domain, $problem) ?>"><?= $index + 1 ?></a></th>
				<?php endforeach ?>
				<th>通过</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($rows as $username => $progress): ?>
			<tr data-username="<?= $username ?>">
				<td class="uoj-scoreboard-name"><?= getUserLink($username) ?></td>
				<?php if ($identities): ?>
				<td><?= isset($identities[$username]) ? HTML::escape($identities[$username]['student_id']) : '' ?></td>
				<td><?= isset($identities[$username]) ? HTML::escape($identities[$username]['real_name']) : '' ?></td>
				<?php endif ?>
				<?php foreach ($ids as $id): ?>
				<?php $score = isset($best[$username][$id]) ? $best[$username][$id] : null; ?>
				<td class="<?= $score === null ? '' : ($score >= 100 ? 'uoj-score-full' : ($score > 0 ? 'uoj-score-part' : 'uoj-score-zero')) ?>" data-problem="<?= $id ?>"><?= $score === null ? '<span class="text-muted">—</span>' : $score ?></td>
				<?php endforeach ?>
				<td><strong><?= $progress['solved'] ?></strong> / <?= $progress['total'] ?><?php if ($progress['done']): ?> <span class="glyphicon glyphicon-ok text-success" title="已完成"></span><?php endif ?></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
