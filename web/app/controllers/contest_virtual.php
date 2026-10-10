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
	// and the board, as during the contest. This page is where one starts, reserves, and
	// looks back at one that is over.
	if ($phase === 'running') {
		redirectTo("/contest/{$contest['id']}");
	}
	$problems = virtualProblems($contest);
	$tab = isset($_GET['tab']) && $_GET['tab'] === 'standings' ? 'standings' : 'problems';
	$flash = domainTakeFlash();
	
	$submissions = array();
	$standings = array();
	if ($virtual && $phase !== 'upcoming') {
		$submissions = virtualSubmissions($virtual, $problems);
		$standings = virtualStandingsNow($contest, $virtual, $problems);
	}
	$pieces = array('contest' => $contest, 'virtual' => $virtual, 'phase' => $phase, 'problems' => $problems, 'submissions' => $submissions, 'standings' => $standings);
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
			<li>开始后直接在这场比赛的页面里做：题目、提交、榜单都和比赛进行时一样，榜单按原比赛同一时刻回放。</li>
			<li>虚拟参赛不影响 Rating。结束后你的成绩可以在原比赛的榜单里对照着看（带“虚拟”标记，不改变别人的名次）。</li>
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
<?php uojIncludeView('contest-virtual', $pieces + array('part' => 'status')) ?>
<div class="d-flex flex-wrap mb-3">
	<a class="btn btn-light border mr-2" id="link-virtual-on-board" href="/contest/<?= $contest['id'] ?>/standings?virtual=1">在比赛榜单里看我的位置</a>
	<?php if ($can_virtual): ?>
	<form method="post" onsubmit="return confirm('重新开始会丢掉这一次的虚拟成绩（提交记录还在）。继续吗？')">
		<?= HTML::hiddenToken() ?>
		<input type="hidden" name="form" value="start" />
		<button type="submit" class="btn btn-outline-secondary" id="button-virtual-restart">重新开始</button>
	</form>
	<?php endif ?>
</div>
<ul class="nav nav-tabs mb-3">
	<li class="nav-item"><a class="nav-link<?= $tab === 'problems' ? ' active' : '' ?>" href="<?= $here ?>">题目与提交</a></li>
	<li class="nav-item"><a class="nav-link<?= $tab === 'standings' ? ' active' : '' ?>" href="<?= $here ?>?tab=standings" id="link-virtual-standings">榜单回放</a></li>
</ul>
<?php uojIncludeView('contest-virtual', $pieces + array('part' => $tab === 'standings' ? 'standings' : 'problems')) ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
