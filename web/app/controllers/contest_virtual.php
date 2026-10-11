<?php
	// Sitting a contest that is over, alone and against the clock. This page says what the
	// rules are and has the button that starts; once started, the pages of the contest are
	// where it happens.
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
			$err = virtualStart($contest, $myUser, '');
			if ($err === '') {
				// it is sat in the pages of the contest itself
				redirectTo("/contest/{$contest['id']}");
			}
			return $err;
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
	// While it runs, the pages of the contest are its pages: the problems, what was submitted
	// and the board, as during the contest.
	// (A form that was refused is answered here, with why: starting again while it runs is.)
	if ($phase === 'running' && $error === '') {
		redirectTo("/contest/{$contest['id']}");
	}
	$flash = domainTakeFlash();
	$rule = contestRule($contest);
	// how the last time went, for somebody who sat it before
	$result = $phase === 'ended' ? virtualResult($contest, $myUser['username']) : null;
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 虚拟参赛') ?>
<?php echoContestDomainLink($contest) ?>
<div class="text-left" id="virtual-page">
<h1 class="page-header">虚拟比赛规则</h1>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>" role="alert"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<?php echoDomainError($error) ?>

<?php if ($phase === 'running'): ?>
<div class="card mb-3 border-success" id="virtual-running-elsewhere">
	<div class="card-body">
		<p>你的虚拟参赛正在进行中，它在这场比赛自己的页面里：题目、提交记录和榜单都在那里。</p>
		<a class="btn btn-primary" href="/contest/<?= $contest['id'] ?>">回到比赛</a>
	</div>
</div>
<?php else: ?>
<p>虚拟比赛是一种参加过去比赛的方式，尽可能接近实际参赛时的体验：按原比赛的时长自己做一遍，排行榜回放到比赛的同一时刻。</p>
<p>本场比赛 <a href="/contest/<?= $contest['id'] ?>"><?= $contest['name'] ?></a> 的赛制为 <strong><?= $rule ?></strong>：</p>
<ul id="virtual-rules">
	<?php foreach (virtualRules($rule, (int)$contest['last_min'], contestFreezeOffset($contest)) as $line): ?>
	<li><?= $line[0] === '!' ? '<strong>' . HTML::escape(substr($line, 1)) . '</strong>' : HTML::escape($line) ?></li>
	<?php endforeach ?>
</ul>

<?php if ($phase === 'upcoming'): ?>
<div class="card mb-3 border-info" id="virtual-upcoming">
	<div class="card-body">
		<h5 class="card-title">已预约：<?= $virtual['start_time'] ?> 开始，时长 <?= $virtual['last_min'] ?> 分钟</h5>
		<p>到时间后打开这场比赛即可，计时从预约的时间算起，不会等你。距开始还有 <strong id="virtual-countdown"></strong>。</p>
		<form method="post">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="cancel" />
			<button type="submit" class="btn btn-outline-secondary btn-sm" id="button-virtual-cancel">取消预约</button>
		</form>
	</div>
</div>
<script type="text/javascript">
// counted down in the words of the sentence; when it is time, the contest is where to be
(function() {
	var rest = <?= strtotime($virtual['start_time']) - $now ?>;
	var two = function(n) {
		return (n < 10 ? '0' : '') + n;
	};
	var show = function() {
		$('#virtual-countdown').text(Math.floor(rest / 3600) + ':' + two(Math.floor(rest / 60) % 60) + ':' + two(rest % 60));
	};
	show();
	setInterval(function() {
		rest--;
		if (rest <= 0) {
			window.location.reload();
			return;
		}
		show();
	}, 1000);
})();
</script>
<?php elseif ($phase === 'ended'): ?>
<div class="alert alert-secondary" id="virtual-ended">
	你已经虚拟参赛过这场比赛（<?= $virtual['start_time'] ?> 开始）<?php if ($result): ?>：
	<?= $rule === 'ICPC' ? '通过 <strong>' . ($result['score'] / 100) . '</strong> 题，罚时 ' . contestClock($result['penalty']) : '得到 <strong>' . $result['score'] . '</strong> 分' ?>，
	在含非官方选手的排行榜上排第 <strong id="virtual-rank"><?= $result['rank'] ?></strong> 名（共 <?= $result['people'] ?> 人）<?php endif ?>。
	<a class="alert-link" id="link-virtual-on-board" href="/contest/<?= $contest['id'] ?>/standings?unofficial=1">在排行榜里看</a>。
	再次开始会用新的成绩替换这一次（提交记录都还在）。
</div>
<?php endif ?>

<?php if (!$can_virtual): ?>
<p class="text-danger" id="virtual-not-yet">这场比赛还没有公布最终成绩，公布之后才能虚拟参赛。</p>
<?php else: ?>
<p><strong>点击下方按钮立即开始您的虚拟参赛。</strong></p>
<form method="post" class="text-center mb-3"<?= $phase === 'ended' ? ' onsubmit="return confirm(\'重新开始会用新的成绩替换上一次的虚拟成绩。继续吗？\')"' : '' ?>>
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="start" />
	<button type="submit" class="btn btn-primary btn-lg px-5" id="button-virtual-start">开始！</button>
</form>
<?php // not now, but at a time: asked for by who wants it, and out of the way of who does not ?>
<details class="mb-3" id="virtual-reserve"<?= $error !== '' && isset($_POST['form']) && $_POST['form'] === 'reserve' ? ' open' : '' ?>>
	<summary class="text-muted">不想现在开始？可以预约一个时间</summary>
	<form method="post" class="form-inline mt-2" id="form-virtual-reserve">
		<?= HTML::hiddenToken() ?>
		<input type="hidden" name="form" value="reserve" />
		<label class="mr-2 mb-2" for="input-start_time">开始时间</label>
		<?php // filled in already with the next minute that ends in 0 or 5: it is changed, not typed from nothing ?>
		<input type="datetime-local" class="form-control mr-2 mb-2 uoj-virtual-start" id="input-start_time" name="start_time" value="<?= virtualDefaultStart($now) ?>" min="<?= date('Y-m-d\TH:i', $now) ?>" max="<?= date('Y-m-d\TH:i', $now + 30 * 86400) ?>" required="required" />
		<button type="submit" class="btn btn-outline-primary mb-2">预约</button>
		<small class="form-text text-muted w-100">到时间自己开始计时，不会等人；开始之前可以取消。</small>
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
</details>
<?php endif ?>
<?php endif ?>
</div>
<?php echoUOJPageFooter() ?>
