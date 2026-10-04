<?php
	// the scores of a homework, for the people who look after it
	$domain = domainOfPage();
	$homework = isset($_GET['homework_id']) ? queryHomework($_GET['homework_id']) : null;
	if (!$homework || $homework['domain_id'] != $domain['id']) {
		become404Page();
	}
	if (!can($myUser, 'homework.view_scores', $homework)) {
		become403Page();
	}
	$homework = homeworkTouch($homework);
	$problems = homeworkProblems($homework);
	$total_points = array_sum(array_column($problems, 'score'));
	
	// which scores: a snapshot, the scores as they would be settled now, or how far everybody has got
	$snapshot = null;
	if (isset($_GET['snapshot']) && validateUInt($_GET['snapshot'])) {
		$snapshot = queryHomeworkSnapshot($_GET['snapshot']);
		if (!$snapshot || $snapshot['homework_id'] != $homework['id'] || $snapshot['status'] === 'rejudging') {
			become404Page();
		}
	}
	$view = isset($_GET['view']) && in_array($_GET['view'], array('live', 'correction')) ? $_GET['view'] : 'official';
	if ($snapshot === null && $view === 'official' && $homework['current_official_snapshot_id'] !== null) {
		$snapshot = queryHomeworkSnapshot($homework['current_official_snapshot_id']);
	}
	if ($snapshot !== null) {
		$scores = homeworkSnapshotScores($snapshot['id']);
		$usernames = array_keys($scores);
		$what = "第 {$snapshot['version']} 份快照" . ($snapshot['id'] == $homework['current_official_snapshot_id'] ? '（正式成绩）' : '');
	} else {
		$scores = $view === 'correction' ? homeworkCorrectionScores($homework) : homeworkLiveScores($homework);
		$usernames = homeworkParticipants($homework);
		$what = $view === 'correction' ? '订正进度（不计迟交，包括截止后的提交）' : '按当前提交实时计算';
	}
	$totals = array();
	foreach ($usernames as $username) {
		$totals[$username] = homeworkTotal($scores, $username);
	}
	uksort($totals, function($a, $b) use ($totals) {
		return $totals[$a] != $totals[$b] ? ($totals[$a] < $totals[$b] ? 1 : -1) : strcmp($a, $b);
	});
	
	// who the students are, for the people who may know
	$identities = array();
	foreach (DB::selectAll("select username, student_id, real_name from external_identities where username in (select username from homework_participants where homework_id = {$homework['id']}) order by id desc") as $row) {
		$identities[$row['username']] = $row;
	}
	$nicknames = array();
	foreach (DB::selectAll("select username, nickname from user_info where username in (select username from homework_participants where homework_id = {$homework['id']})") as $row) {
		$nicknames[$row['username']] = $row['nickname'];
	}
	
	if (isset($_GET['export'])) {
		$rows = array();
		$header = array('Rank', 'Username', 'Nickname', 'StudentID', 'RealName', 'Claimed');
		foreach ($problems as $index => $problem) {
			$header[] = chr(ord('A') + $index % 26) . '_' . $problem['problem_id'];
		}
		$header[] = 'Total';
		$rows[] = $header;
		$rank = 0;
		foreach ($totals as $username => $total) {
			$rank++;
			$row = array($rank, $username, isset($nicknames[$username]) ? $nicknames[$username] : '', isset($identities[$username]) ? $identities[$username]['student_id'] : '', isset($identities[$username]) ? $identities[$username]['real_name'] : '', 'yes');
			foreach ($problems as $problem) {
				$row[] = isset($scores[$username][$problem['problem_id']]) ? homeworkTrimNumber($scores[$username][$problem['problem_id']]['score']) : '0';
			}
			$row[] = homeworkTrimNumber($total);
			$rows[] = $row;
		}
		// the students who never claimed the homework have no scores: they are listed as such
		if (isset($_GET['unclaimed'])) {
			foreach (homeworkUnclaimedMembers($homework) as $username) {
				if (isset($totals[$username])) {
					continue;
				}
				$identity = UOJSSO::identitiesOf($username);
				$user = queryUser($username);
				$rows[] = array_merge(array('', $username, $user['nickname'], $identity ? $identity[0]['student_id'] : '', $identity ? $identity[0]['real_name'] : '', 'no'), array_fill(0, count($problems) + 1, ''));
			}
		}
		auditLog('homework.export_scores', 'homework', $homework['id'], null, array('rows' => count($rows) - 1, 'scores' => $what));
		header('Content-Type: text/csv; charset=utf-8');
		header("Content-Disposition: attachment; filename=homework_{$homework['id']}_scores.csv");
		// the mark that tells a spreadsheet the file is UTF-8
		echo "\xEF\xBB\xBF";
		$out = fopen('php://output', 'w');
		foreach ($rows as $row) {
			fputcsv($out, $row);
		}
		die();
	}
	
	$here = homeworkUrl($domain, $homework, '/scoreboard');
	$query = $snapshot !== null && $snapshot['id'] != $homework['current_official_snapshot_id'] ? "snapshot={$snapshot['id']}&" : ($snapshot === null && $view !== 'official' ? "view=$view&" : ($snapshot === null ? 'view=live&' : ''));
?>
<?php echoDomainPageHeader($domain, 'homeworks', $homework['title'] . ' - 成绩表') ?>
<div class="d-flex flex-wrap align-items-center mb-3">
	<h3 class="mr-auto mb-2"><a href="<?= homeworkUrl($domain, $homework) ?>"><?= HTML::escape($homework['title']) ?></a> <small class="text-muted">成绩表</small></h3>
	<div class="mb-2">
		<div class="btn-group btn-group-sm mr-2">
			<a class="btn btn-outline-secondary<?= $snapshot !== null || ($view === 'official' && $homework['current_official_snapshot_id'] === null) ? ' active' : '' ?>" href="<?= $here ?>"><?= $homework['current_official_snapshot_id'] !== null ? '正式成绩' : '当前成绩' ?></a>
			<?php if ($homework['current_official_snapshot_id'] !== null): ?>
			<a class="btn btn-outline-secondary<?= $snapshot === null && $view === 'live' ? ' active' : '' ?>" href="<?= $here ?>?view=live">按现在重算</a>
			<?php endif ?>
			<a class="btn btn-outline-secondary<?= $snapshot === null && $view === 'correction' ? ' active' : '' ?>" href="<?= $here ?>?view=correction">订正进度</a>
		</div>
		<div class="btn-group btn-group-sm">
			<a class="btn btn-outline-primary" href="<?= $here ?>?<?= $query ?>export=1" id="link-export-scores">导出 CSV</a>
			<a class="btn btn-outline-primary" href="<?= $here ?>?<?= $query ?>export=1&amp;unclaimed=1" title="未认领的学生也列出来，成绩留空">含未认领</a>
		</div>
	</div>
</div>
<p class="text-muted" id="scoreboard-what">
	<?= HTML::escape($what) ?>
	<?php if ($snapshot !== null): ?>
	· <?= $snapshot['reason'] !== '' ? HTML::escape($snapshot['reason']) . ' · ' : '' ?><?= $snapshot['confirmed_at'] !== null ? $snapshot['confirmed_at'] : $snapshot['created_at'] ?>
	<?php endif ?>
	· <?= count($totals) ?> 人
</p>

<?php if (!$totals): ?>
<div class="uoj-domain-empty">还没有人参加这个作业。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-bordered table-sm uoj-scoreboard" id="table-scoreboard">
		<thead>
			<tr>
				<th style="width:3em">#</th>
				<th class="uoj-scoreboard-name">学生</th>
				<?php if ($identities): ?>
				<th>学号</th>
				<th>姓名</th>
				<?php endif ?>
				<?php foreach ($problems as $index => $problem): ?>
				<th title="<?= HTML::escape(strip_tags($problem['title'])) ?>"><a href="<?= homeworkUrl($domain, $homework, '/problem/' . problemNumber($problem)) ?>"><?= chr(ord('A') + $index % 26) ?></a><br /><small class="text-muted"><?= $problem['score'] ?></small></th>
				<?php endforeach ?>
				<th>总分<br /><small class="text-muted"><?= $total_points ?></small></th>
			</tr>
		</thead>
		<tbody>
			<?php $rank = 0; ?>
			<?php foreach ($totals as $username => $total): ?>
			<?php $rank++; ?>
			<tr data-username="<?= $username ?>">
				<td><?= $rank ?></td>
				<td class="uoj-scoreboard-name"><?= getUserLink($username) ?></td>
				<?php if ($identities): ?>
				<td><?= isset($identities[$username]) ? HTML::escape($identities[$username]['student_id']) : '' ?></td>
				<td><?= isset($identities[$username]) ? HTML::escape($identities[$username]['real_name']) : '' ?></td>
				<?php endif ?>
				<?php foreach ($problems as $problem): ?>
				<?php
					$cell = isset($scores[$username][$problem['problem_id']]) ? $scores[$username][$problem['problem_id']] : null;
					$class = '';
					if ($cell && $cell['submission_id'] !== null) {
						$class = $cell['score'] >= $problem['score'] ? 'uoj-score-full' : ($cell['score'] > 0 ? 'uoj-score-part' : 'uoj-score-zero');
						// judged with data that the problem no longer has
						if (isset($cell['data_version']) && $cell['data_version'] !== null && $cell['data_version'] != $problem['data_version']) {
							$class .= ' uoj-score-stale';
						}
					}
				?>
				<td class="<?= $class ?>" data-problem="<?= $problem['problem_id'] ?>">
					<?php if ($cell && $cell['submission_id'] !== null): ?>
					<a href="/submission/<?= $cell['submission_id'] ?>"><?= homeworkTrimNumber($cell['score']) ?></a>
					<?php if (isset($cell['multiplier']) && $cell['multiplier'] < 1): ?><br /><small class="text-muted">×<?= homeworkTrimNumber($cell['multiplier'] * 100) ?>%</small><?php endif ?>
					<?php else: ?>
					<span class="text-muted">—</span>
					<?php endif ?>
				</td>
				<?php endforeach ?>
				<td><strong><?= homeworkTrimNumber($total) ?></strong></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<p class="text-muted small">绿色是满分，黄色是部分得分，红色是零分；虚线框表示这份成绩是用题目修改之前的数据评测的。</p>
<?php endif ?>

<?php if ($snapshot !== null): ?>
<?php $rules = json_decode($snapshot['rules_json'], true); ?>
<?php if (is_array($rules)): ?>
<details class="mb-3" id="snapshot-rules">
	<summary>这份成绩是按什么规则算出来的</summary>
	<ul class="small mt-2">
		<li>开始 <?= HTML::escape($rules['begin_at']) ?>，<?= $rules['penalty_since'] !== null ? '正常截止 ' . HTML::escape($rules['penalty_since']) . '，' : '' ?>最终截止 <?= HTML::escape($rules['end_at']) ?></li>
		<?php foreach ($rules['penalty_rules'] as $rule): ?>
		<li>迟交 <?= homeworkTrimNumber($rule['after_hours']) ?> 小时起按 <?= homeworkTrimNumber($rule['multiplier'] * 100) ?>% 计分</li>
		<?php endforeach ?>
		<?php foreach ($rules['problems'] as $problem): ?>
		<li>#<?= isset($problem['number']) ? (int)$problem['number'] : (int)$problem['problem_id'] ?><?= isset($problem['title']) ? '. ' . HTML::escape(strip_tags($problem['title'])) : '' ?>：<?= (int)$problem['score'] ?> 分，数据 v<?= (int)$problem['data_version'] ?> <code><?= HTML::escape(substr((string)$problem['data_sha256'], 0, 16)) ?></code></li>
		<?php endforeach ?>
		<?php if (!empty($rules['unjudged_submissions'])): ?>
		<li class="text-danger">结算时有 <?= count($rules['unjudged_submissions']) ?> 份截止前的提交没有评完，没有计入：#<?= join('、#', array_map('intval', $rules['unjudged_submissions'])) ?></li>
		<?php endif ?>
	</ul>
</details>
<?php endif ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
