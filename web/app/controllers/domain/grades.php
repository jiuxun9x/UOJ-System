<?php
	// the grades of a domain: its students and what counts of every homework
	$domain = domainOfPage();
	if (!can($myUser, 'domain.assist', $domain)) {
		become403Page();
	}
	$grades = domainGrades($domain);
	$students = $grades['students'];
	$full = 0;
	foreach ($grades['homeworks'] as $homework) {
		if ($homework['settled']) {
			$full += $homework['points'];
		}
	}
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
		foreach ($grades['homeworks'] as $homework) {
			$head[] = $homework['title'] . ($homework['settled'] ? '' : ' (未结算)');
		}
		$lines = array(array_merge($head, array('total')));
		foreach ($students as $username) {
			$line = array($username, isset($identities[$username]) ? $identities[$username]['student_id'] : '', isset($identities[$username]) ? $identities[$username]['real_name'] : '');
			foreach ($grades['homeworks'] as $homework) {
				$cell = $grades['cells'][$username][$homework['id']];
				$line[] = $cell['total'] === null ? '' : homeworkTrimNumber($cell['total']);
			}
			$lines[] = array_merge($line, array(homeworkTrimNumber($grades['sums'][$username])));
		}
		auditLog('domain.export_grades', 'domain', $domain['id'], null, array('rows' => count($lines) - 1, 'homeworks' => count($grades['homeworks'])));
		header('Content-Type: text/csv; charset=utf-8');
		header("Content-Disposition: attachment; filename=domain_{$domain['slug']}_grades.csv");
		// the mark that tells a spreadsheet the file is UTF-8
		echo "\xEF\xBB\xBF";
		$out = fopen('php://output', 'w');
		foreach ($lines as $line) {
			fputcsv($out, $line);
		}
		die();
	}
?>
<?php echoDomainPageHeader($domain, 'grades', '成绩') ?>
<div class="d-flex flex-wrap align-items-center mb-2">
	<p class="text-muted mr-auto mb-2">
		每名学生在每次作业里的成绩。已结算的作业显示正式成绩并计入合计；还没结算的显示到目前为止的成绩，用斜体，不计入合计。
	</p>
	<a class="btn btn-outline-primary btn-sm mb-2" href="<?= domainUrl($domain, '/grades') ?>?export=1" id="link-export-grades">导出 CSV</a>
</div>
<?php if (!$grades['homeworks']): ?>
<div class="uoj-domain-empty">还没有已经开始的作业。</div>
<?php elseif (!$students): ?>
<div class="uoj-domain-empty">这个域里还没有学生。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-bordered table-sm uoj-scoreboard" id="table-grades">
		<thead>
			<tr>
				<th class="uoj-scoreboard-name">学生</th>
				<?php if ($identities): ?>
				<th>学号</th>
				<th>姓名</th>
				<?php endif ?>
				<?php foreach ($grades['homeworks'] as $homework): ?>
				<th class="uoj-grades-homework">
					<a href="<?= homeworkUrl($domain, $homework, '/scoreboard') ?>" title="<?= HTML::escape($homework['title']) ?>"><?= HTML::escape(mb_strimwidth($homework['title'], 0, 16, '…', 'UTF-8')) ?></a><br />
					<small class="text-muted"><?= $homework['points'] ?><?= $homework['settled'] ? '' : ' · 未结算' ?></small>
				</th>
				<?php endforeach ?>
				<th>合计<br /><small class="text-muted"><?= $full ?></small></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($students as $username): ?>
			<tr data-username="<?= $username ?>">
				<td class="uoj-scoreboard-name"><?= getUserLink($username) ?></td>
				<?php if ($identities): ?>
				<td><?= isset($identities[$username]) ? HTML::escape($identities[$username]['student_id']) : '' ?></td>
				<td><?= isset($identities[$username]) ? HTML::escape($identities[$username]['real_name']) : '' ?></td>
				<?php endif ?>
				<?php foreach ($grades['homeworks'] as $homework): ?>
				<?php
					$cell = $grades['cells'][$username][$homework['id']];
					$class = '';
					if ($cell['total'] !== null && $homework['points'] > 0) {
						$class = $cell['total'] >= $homework['points'] ? 'uoj-score-full' : ($cell['total'] > 0 ? 'uoj-score-part' : 'uoj-score-zero');
					}
				?>
				<td class="<?= $class ?>" data-homework="<?= $homework['id'] ?>" data-state="<?= $cell['state'] ?>">
					<?php if ($cell['state'] === 'unclaimed'): ?>
					<span class="text-muted small">未认领</span>
					<?php elseif ($cell['state'] === 'live'): ?>
					<em><?= homeworkTrimNumber($cell['total']) ?></em>
					<?php else: ?>
					<?= homeworkTrimNumber($cell['total']) ?>
					<?php endif ?>
				</td>
				<?php endforeach ?>
				<td><strong><?= homeworkTrimNumber($grades['sums'][$username]) ?></strong></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
