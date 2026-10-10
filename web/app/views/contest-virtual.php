<?php
	// A virtual participation, in the pieces that the pages of its contest are made of.
	//   $part         'status': how it stands, with the clock; 'problems': the problems with
	//                 what its user has on each, and what they submitted; 'submissions': what
	//                 they submitted, by itself; 'standings': the board of the contest
	//                 replayed to this moment, with its user among the contestants
	//   $contest      the contest
	//   $virtual      the participation
	//   $phase        'running' or 'ended'
	//   $problems     virtualProblems()
	//   $submissions  virtualSubmissions()
	//   $standings    virtualStandingsNow()
	$now = UOJTime::$time_now->getTimestamp();
	$elapsed = virtualElapsed($virtual, $now);
	$duration = $virtual['last_min'] * 60;
	$my_row = null;
	foreach ($standings as $row) {
		if ($row['virtual']) {
			$my_row = $row;
		}
	}
	$is_icpc = contestRule($contest) === 'ICPC';
	$score_class = function($score) {
		return $score >= 100 ? 'uoj-score-full' : ($score > 0 ? 'uoj-score-part' : 'uoj-score-zero');
	};
	// under the ICPC rule a problem is solved or not, and the board says so in its own way
	$icpc_text = function($cell) {
		list($says, $under) = contestIcpcCell($cell);
		return $says . ($under !== '' ? ' <small class="text-muted">' . $under . '</small>' : '');
	};
?>
<?php if ($part === 'status'): ?>
<div class="card mb-3 <?= $phase === 'running' ? 'border-success' : 'border-secondary' ?>" id="virtual-<?= $phase ?>">
	<div class="card-body py-2">
		<div class="d-flex flex-wrap align-items-center">
			<div class="mr-auto text-left">
				<?php if ($phase === 'running'): ?>
				<h5 class="card-title mb-1"><span class="badge badge-success">虚拟参赛</span> 进行中</h5>
				<span class="text-muted">已进行 <?= virtualClock($elapsed) ?>，剩余 <strong id="virtual-countdown"><?= virtualClock($duration - $elapsed) ?></strong>。在这场比赛的页面里做题、交题就行，和正式比赛一样。</span>
				<?php else: ?>
				<h5 class="card-title mb-1"><span class="badge badge-secondary">虚拟参赛</span> 已结束</h5>
				<span class="text-muted"><?= $virtual['start_time'] ?> 开始，时长 <?= $virtual['last_min'] ?> 分钟</span>
				<?php endif ?>
			</div>
			<div class="text-right">
				<?php if ($is_icpc): ?>
				<div class="uoj-homework-total" id="virtual-score"><?= $my_row['score'] / 100 ?> <small class="text-muted" style="font-size:1rem">题</small></div>
				<?php else: ?>
				<div class="uoj-homework-total" id="virtual-score"><?= $my_row['score'] ?> <small class="text-muted" style="font-size:1rem">分</small></div>
				<?php endif ?>
				<div id="virtual-rank"><?= $phase === 'running' ? '此刻排在' : '相当于' ?>第 <strong><?= $my_row['rank'] ?></strong> 名 <small class="text-muted">（共 <?= count($standings) ?> 人）</small></div>
			</div>
		</div>
		<div class="progress mt-2" style="height: 0.4rem">
			<div class="progress-bar <?= $phase === 'running' ? 'bg-success' : 'bg-secondary' ?>" style="width: <?= $duration > 0 ? round(100 * $elapsed / $duration) : 100 ?>%"></div>
		</div>
	</div>
</div>
<?php if ($phase === 'running'): ?>
<script type="text/javascript">
// the time that is left, in the words of the sentence it stands in: the clock beside the
// page is the one that is looked at
(function() {
	var rest = <?= $duration - $elapsed ?>;
	var two = function(n) {
		return (n < 10 ? '0' : '') + n;
	};
	setInterval(function() {
		rest--;
		if (rest <= 0) {
			window.location.reload();
			return;
		}
		$('#virtual-countdown').text(Math.floor(rest / 3600) + ':' + two(Math.floor(rest / 60) % 60) + ':' + two(rest % 60));
	}, 1000);
})();
</script>
<?php endif ?>

<?php elseif ($part === 'problems' || $part === 'submissions'): ?>
<div class="row text-left">
	<?php if ($part === 'problems'): ?>
	<div class="col-lg-6">
		<table class="table table-hover" id="table-virtual-problems">
			<thead><tr><th style="width:3em">#</th><th>题目</th><th style="width:8em"><?= $is_icpc ? '我的结果' : '我的得分' ?></th></tr></thead>
			<tbody>
				<?php foreach ($problems as $index => $problem): ?>
				<?php $cell = isset($my_row['cells'][$index]) ? $my_row['cells'][$index] : null; ?>
				<tr>
					<td><?= $problem['letter'] ?></td>
					<td><a href="/contest/<?= $contest['id'] ?>/problem/<?= $problem['letter'] ?>"><?= $problem['title'] ?></a></td>
					<td data-problem="<?= $problem['id'] ?>"><?= $cell ? '<a href="/submission/' . $cell[2] . '">' . ($is_icpc ? $icpc_text($cell) : $cell[0]) . '</a>' : '<span class="text-muted">—</span>' ?></td>
				</tr>
				<?php endforeach ?>
			</tbody>
		</table>
	</div>
	<?php endif ?>
	<div class="<?= $part === 'problems' ? 'col-lg-6' : 'col-12' ?>">
		<table class="table table-sm" id="table-virtual-submissions">
			<thead><tr><th>提交</th><th>题目</th><th>结果</th><th>时间</th></tr></thead>
			<tbody>
				<?php foreach (array_reverse($submissions) as $submission): ?>
				<tr>
					<td><a href="/submission/<?= $submission['id'] ?>">#<?= $submission['id'] ?></a></td>
					<td><?= $problems[$submission['pos']]['letter'] ?><?= $part === 'submissions' ? '. ' . $problems[$submission['pos']]['title'] : '' ?></td>
					<td><?= $submission['score'] !== null ? ($is_icpc ? ($submission['score'] == 100 ? '<span class="text-success">通过</span>' : '<span class="text-danger">未通过</span>') : $submission['score']) : '<span class="text-muted">' . HTML::escape($submission['status']) . '</span>' ?></td>
					<td><small><?= virtualClock($submission['offset']) ?></small></td>
				</tr>
				<?php endforeach ?>
				<?php if (!$submissions): ?>
				<tr><td colspan="4" class="text-center text-muted">还没有提交。<?= $part === 'problems' ? '点左边的题目去做题。' : '到“比赛主页”里点题目去做题。' ?></td></tr>
				<?php endif ?>
			</tbody>
		</table>
	</div>
</div>

<?php elseif ($part === 'standings'): ?>
<p class="text-muted text-left" id="virtual-replay-note">
	<?php if ($phase === 'running'): ?>
	榜单回放到比赛开始后 <?= virtualClock($elapsed) ?>：其他选手显示的是他们在原比赛同一时刻已经拿到的成绩。页面每分钟自动刷新。
	<?php else: ?>
	这是原比赛的最终榜单，加上你的虚拟成绩。
	<?php endif ?>
</p>
<div class="table-responsive">
	<table class="table table-bordered table-sm uoj-scoreboard<?= $is_icpc ? ' uoj-icpc-board' : '' ?>" id="table-virtual-standings">
		<thead>
			<tr>
				<th style="width:3em">#</th>
				<th class="uoj-scoreboard-name">选手</th>
				<th><?= $is_icpc ? '通过<div class="uoj-icpc-under">罚时</div>' : '总分' ?></th>
				<?php foreach ($problems as $problem): ?>
				<th title="<?= HTML::escape(strip_tags($problem['title'])) ?>"><a href="/contest/<?= $contest['id'] ?>/problem/<?= $problem['letter'] ?>"><?= $problem['letter'] ?></a></th>
				<?php endforeach ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($standings as $row): ?>
			<tr<?= $row['virtual'] ? ' class="uoj-scoreboard-me" id="virtual-my-row"' : '' ?> data-username="<?= $row['username'] ?>" data-rank="<?= $row['rank'] ?>">
				<td><?= $row['rank'] ?></td>
				<td class="uoj-scoreboard-name">
					<span class="uoj-username" data-rating="<?= $row['rating'] ?>"<?= $row['nickname'] !== '' ? ' data-alias="' . HTML::escape($row['nickname']) . '"' : '' ?>><?= $row['username'] ?></span>
					<?php if ($row['virtual']): ?><span class="badge badge-info">虚拟</span><?php endif ?>
				</td>
				<?php if ($is_icpc): ?>
				<td><span class="<?= $row['score'] > 0 ? 'uoj-icpc-total' : 'uoj-icpc-total-none' ?>"><?= $row['score'] / 100 ?></span><div class="uoj-icpc-under"><?= contestClock($row['penalty']) ?></div></td>
				<?php else: ?>
				<td><strong><?= $row['score'] ?></strong><br /><small class="text-muted"><?= virtualClock($row['penalty']) ?></small></td>
				<?php endif ?>
				<?php foreach ($problems as $index => $problem): ?>
				<?php $cell = isset($row['cells'][$index]) ? $row['cells'][$index] : null; ?>
				<?php if ($is_icpc): ?>
				<?php list($says, $under, $cell_class) = contestIcpcCell($cell); ?>
				<td class="<?= $cell_class ?>">
					<?php if ($says !== ''): ?>
					<a href="/submission/<?= $cell[2] ?>"><?= $says ?></a><?php if ($under !== ''): ?><div class="uoj-icpc-under"><?= $under ?></div><?php endif ?>
					<?php endif ?>
				</td>
				<?php else: ?>
				<td class="<?= $cell ? $score_class($cell[0]) : '' ?>">
					<?php if ($cell): ?>
					<a href="/submission/<?= $cell[2] ?>"><?= $cell[0] ?></a><br /><small class="text-muted"><?= virtualClock($cell[1]) ?></small>
					<?php endif ?>
				</td>
				<?php endif ?>
				<?php endforeach ?>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php if ($phase === 'running'): ?>
<script type="text/javascript">
setTimeout(function() {
	window.location.reload();
}, 60000);
</script>
<?php endif ?>
<?php endif ?>
