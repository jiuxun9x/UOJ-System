<?php
	// sitting a contest that is over, alone and against the clock
	if (!validateUInt($_GET['id']) || !($contest = queryContest($_GET['id']))) {
		become404Page();
	}
	genMoreContestInfo($contest);
	if (!can($myUser, 'contest.view', $contest)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}
	if ($myUser == null) {
		redirectToLogin();
	}
	if (!can($myUser, 'contest.enter', $contest)) {
		become404Page();
	}
	$here = "/contest/{$contest['id']}/virtual";
	$can_virtual = can($myUser, 'contest.virtual', $contest);
	
	$error = domainHandleForms(array(
		'start' => function() use ($contest) {
			global $myUser;
			return virtualStart($contest, $myUser, '');
		},
		'reserve' => function() use ($contest) {
			global $myUser;
			// a browser posts "2026-10-12T19:00" from a field for a date and a time
			$start = isset($_POST['start_time']) && is_string($_POST['start_time']) ? str_replace('T', ' ', trim($_POST['start_time'])) : '';
			if (strlen($start) == 16) {
				$start .= ':00';
			}
			if ($start === '') {
				return '请填写开始时间';
			}
			$err = virtualStart($contest, $myUser, $start);
			if ($err === '') {
				domainFlash("已预约，虚拟参赛将在 $start 开始。");
			}
			return $err;
		},
		'cancel' => function() use ($contest) {
			global $myUser;
			return virtualCancel($contest, $myUser);
		}
	), $here);
	
	$now = UOJTime::$time_now->getTimestamp();
	$virtual = queryVirtual($contest['id'], $myUser['username']);
	$phase = $virtual ? virtualPhase($virtual, $now) : null;
	$problems = virtualProblems($contest);
	$tab = isset($_GET['tab']) && $_GET['tab'] === 'standings' ? 'standings' : 'problems';
	$flash = domainTakeFlash();
	
	$submissions = array();
	$standings = array();
	$my_row = null;
	if ($virtual && $phase !== 'upcoming') {
		$submissions = virtualSubmissions($virtual, $problems);
		$standings = virtualStandingsNow($contest, $virtual, $problems);
		foreach ($standings as $row) {
			if ($row['virtual']) {
				$my_row = $row;
			}
		}
		$elapsed = virtualElapsed($virtual, $now);
		$duration = $virtual['last_min'] * 60;
	}
	$score_class = function($score) {
		return $score >= 100 ? 'uoj-score-full' : ($score > 0 ? 'uoj-score-part' : 'uoj-score-zero');
	};
	// under the ICPC rule a problem is solved or not, and the board says so in its own way
	$is_icpc = contestRule($contest) === 'ICPC';
	$icpc_text = function($cell) {
		list($says, $under) = contestIcpcCell($cell);
		return $says . ($under !== '' ? ' <small class="text-muted">' . $under . '</small>' : '');
	};
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 虚拟参赛') ?>
<?php echoContestDomainLink($contest) ?>
<div class="d-flex flex-wrap align-items-center mb-3">
	<h2 class="mr-auto mb-2"><a href="/contest/<?= $contest['id'] ?>"><?= $contest['name'] ?></a> <small class="text-muted">虚拟参赛</small></h2>
</div>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>" role="alert"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<?php echoDomainError($error) ?>

<?php if (!$virtual): ?>
<div class="card mb-3" id="virtual-none">
	<div class="card-body">
		<p>这场比赛已经结束。虚拟参赛让你按原比赛的时长（<?= $contest['last_min'] ?> 分钟）自己做一遍：开始后计时，期间你对比赛题目的提交单独计分，榜单会按原比赛的时间线回放其他选手当时的成绩，结束时你能看到自己相当于第几名。</p>
		<ul class="text-muted small">
			<li>提交直接用完整数据评测并立即显示结果；原比赛如果只显示样例结果，这一点和原比赛不同。</li>
			<li>和比赛一样，每道题以最后一次提交为准（编译错误不算）；罚时是那次提交距开始的时间。</li>
			<li>虚拟参赛不影响 Rating，也不会出现在原比赛的榜单里。</li>
		</ul>
		<?php if (!$can_virtual): ?>
		<p class="text-danger mb-0" id="virtual-not-yet">这场比赛还没有公布最终成绩，公布之后才能虚拟参赛。</p>
		<?php else: ?>
		<div class="d-flex flex-wrap align-items-end">
			<form method="post" class="mr-4 mb-2">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="start" />
				<button type="submit" class="btn btn-primary" id="button-virtual-start">现在开始</button>
			</form>
			<form method="post" class="form-inline mb-2" id="form-virtual-reserve">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="reserve" />
				<label class="mr-2" for="input-start_time">或者预约在</label>
				<?php // filled in already with the next minute that ends in 0 or 5: it is changed, not typed from nothing ?>
				<input type="datetime-local" class="form-control mr-2 uoj-virtual-start" id="input-start_time" name="start_time" value="<?= virtualDefaultStart($now) ?>" min="<?= date('Y-m-d\TH:i', $now) ?>" max="<?= date('Y-m-d\TH:i', $now + 30 * 86400) ?>" required="required" />
				<button type="submit" class="btn btn-outline-primary">预约</button>
			</form>
			<script type="text/javascript">
			// The page may stay open: the time that is offered moves on with the clock, for as
			// long as nobody has put a time of their own into the field. The clock is the
			// server's, which is the one the reservation is kept by.
			$(function() {
				var field = $('#input-start_time');
				var offered = field.val();
				var opened = new Date().getTime();
				var two = function(n) {
					return (n < 10 ? '0' : '') + n;
				};
				// what the server's clock shows, read as if it were this machine's
				var server = new Date(<?= date('Y', $now) ?>, <?= date('n', $now) - 1 ?>, <?= date('j', $now) ?>, <?= (int)date('G', $now) ?>, <?= (int)date('i', $now) ?>, <?= (int)date('s', $now) ?>).getTime();
				setInterval(function() {
					if (field.val() !== offered) {
						return;
					}
					var now = new Date(server + new Date().getTime() - opened);
					now.setSeconds(0, 0);
					now.setMinutes(now.getMinutes() + 5 - now.getMinutes() % 5);
					offered = now.getFullYear() + '-' + two(now.getMonth() + 1) + '-' + two(now.getDate()) + 'T' + two(now.getHours()) + ':' + two(now.getMinutes());
					field.val(offered);
				}, 15000);
			});
			</script>
		</div>
		<?php endif ?>
	</div>
</div>
<?php elseif ($phase === 'upcoming'): ?>
<div class="card mb-3 border-info" id="virtual-upcoming">
	<div class="card-body">
		<h5 class="card-title">已预约：<?= $virtual['start_time'] ?> 开始，时长 <?= $virtual['last_min'] ?> 分钟</h5>
		<p>到时间后打开这个页面即可，计时从预约的时间算起，不会等你。距开始还有 <strong id="virtual-countdown"></strong>。</p>
		<div class="d-flex flex-wrap">
			<form method="post" class="mr-2">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="start" />
				<button type="submit" class="btn btn-primary">不等了，现在开始</button>
			</form>
			<form method="post">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="cancel" />
				<button type="submit" class="btn btn-outline-secondary" id="button-virtual-cancel">取消预约</button>
			</form>
		</div>
	</div>
</div>
<script type="text/javascript">
$('#virtual-countdown').countdown(<?= strtotime($virtual['start_time']) - $now ?>, function() {
	window.location.reload();
});
</script>
<?php else: ?>
<div class="card mb-3 <?= $phase === 'running' ? 'border-success' : 'border-secondary' ?>" id="virtual-<?= $phase ?>">
	<div class="card-body">
		<div class="d-flex flex-wrap align-items-center">
			<div class="mr-auto">
				<?php if ($phase === 'running'): ?>
				<h5 class="card-title mb-1">虚拟参赛进行中</h5>
				<span class="text-muted">已进行 <?= virtualClock($elapsed) ?>，剩余 <strong id="virtual-countdown"></strong></span>
				<?php else: ?>
				<h5 class="card-title mb-1">虚拟参赛已结束</h5>
				<span class="text-muted"><?= $virtual['start_time'] ?> 开始，时长 <?= $virtual['last_min'] ?> 分钟</span>
				<?php endif ?>
			</div>
			<div class="text-right">
				<div class="uoj-homework-total" id="virtual-score"><?= $my_row['score'] ?> <small class="text-muted" style="font-size:1rem">分</small></div>
				<div id="virtual-rank"><?= $phase === 'running' ? '此刻排在' : '相当于' ?>第 <strong><?= $my_row['rank'] ?></strong> 名 <small class="text-muted">（共 <?= count($standings) ?> 人）</small></div>
			</div>
		</div>
		<div class="progress mt-2" style="height: 0.5rem">
			<div class="progress-bar <?= $phase === 'running' ? 'bg-success' : 'bg-secondary' ?>" style="width: <?= $duration > 0 ? round(100 * $elapsed / $duration) : 100 ?>%"></div>
		</div>
		<?php if ($phase === 'ended' && $can_virtual): ?>
		<form method="post" class="mt-3" onsubmit="return confirm('重新开始会丢掉这一次的虚拟成绩（提交记录还在）。继续吗？')">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="start" />
			<button type="submit" class="btn btn-outline-secondary btn-sm" id="button-virtual-restart">重新开始</button>
		</form>
		<?php endif ?>
	</div>
</div>
<?php if ($phase === 'running'): ?>
<script type="text/javascript">
$('#virtual-countdown').countdown(<?= $duration - $elapsed ?>, function() {
	window.location.reload();
});
</script>
<?php endif ?>

<ul class="nav nav-tabs mb-3">
	<li class="nav-item"><a class="nav-link<?= $tab === 'problems' ? ' active' : '' ?>" href="<?= $here ?>">题目与提交</a></li>
	<li class="nav-item"><a class="nav-link<?= $tab === 'standings' ? ' active' : '' ?>" href="<?= $here ?>?tab=standings" id="link-virtual-standings">榜单回放</a></li>
</ul>

<?php if ($tab === 'problems'): ?>
<div class="row">
	<div class="col-lg-6">
		<table class="table table-hover" id="table-virtual-problems">
			<thead><tr><th style="width:3em">#</th><th>题目</th><th style="width:7em"><?= $is_icpc ? '我的结果' : '我的得分' ?></th></tr></thead>
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
	<div class="col-lg-6">
		<table class="table table-sm" id="table-virtual-submissions">
			<thead><tr><th>提交</th><th>题目</th><th>结果</th><th>时间</th></tr></thead>
			<tbody>
				<?php foreach (array_reverse($submissions) as $submission): ?>
				<tr>
					<td><a href="/submission/<?= $submission['id'] ?>">#<?= $submission['id'] ?></a></td>
					<td><?= $problems[$submission['pos']]['letter'] ?></td>
					<td><?= $submission['score'] !== null ? ($is_icpc ? ($submission['score'] == 100 ? '<span class="text-success">通过</span>' : '<span class="text-danger">未通过</span>') : $submission['score']) : '<span class="text-muted">' . HTML::escape($submission['status']) . '</span>' ?></td>
					<td><small><?= virtualClock($submission['offset']) ?></small></td>
				</tr>
				<?php endforeach ?>
				<?php if (!$submissions): ?>
				<tr><td colspan="4" class="text-center text-muted">还没有提交。点左边的题目去做题。</td></tr>
				<?php endif ?>
			</tbody>
		</table>
	</div>
</div>
<?php else: ?>
<p class="text-muted" id="virtual-replay-note">
	<?php if ($phase === 'running'): ?>
	榜单回放到比赛开始后 <?= virtualClock($elapsed) ?>：其他选手显示的是他们在原比赛同一时刻已经拿到的成绩。页面每分钟自动刷新。
	<?php else: ?>
	这是原比赛的最终榜单，加上你的虚拟成绩。
	<?php endif ?>
</p>
<div class="table-responsive">
	<table class="table table-bordered table-sm uoj-scoreboard" id="table-virtual-standings">
		<thead>
			<tr>
				<th style="width:3em">#</th>
				<th class="uoj-scoreboard-name">选手</th>
				<th><?= $is_icpc ? '通过 / 罚时' : '总分' ?></th>
				<?php foreach ($problems as $problem): ?>
				<th title="<?= HTML::escape(strip_tags($problem['title'])) ?>"><a href="/contest/<?= $contest['id'] ?>/problem/<?= $problem['letter'] ?>"><?= $problem['letter'] ?></a></th>
				<?php endforeach ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($standings as $row): ?>
			<tr<?= $row['virtual'] ? ' class="table-info" id="virtual-my-row"' : '' ?> data-username="<?= $row['username'] ?>" data-rank="<?= $row['rank'] ?>">
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
<?php endif ?>
<?php echoUOJPageFooter() ?>
