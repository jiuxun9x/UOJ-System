<?php
	// The outer board of a contest: its board for the people who watch, on a wall of the room
	// or from outside. A page of its own, without the site around it, that keeps itself up to
	// date. It is for everybody, also who is not logged in, whatever it takes to take part in
	// the contest; a contest of a domain keeps it with the people of the domain unless they made
	// it public ('contest.view_board'). It shows nobody more than everybody may know: see
	// uoj-board-lib.php.
	if (!validateUInt($_GET['id']) || !($contest = queryContest($_GET['id']))) {
		become404Page();
	}
	genMoreContestInfo($contest);
	if (!can($myUser, 'contest.view_board', $contest)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		if (!can($myUser, 'contest.view', $contest)) {
			become404Page();
		}
		becomeMsgPage('<h1 id="contest-closed">这场比赛不对所有人开放</h1><p>它的榜单只有报名参加的选手能看到。</p>');
	}

	// whose view of the board of a contest that is over is asked for, and whether it is with the
	// people who sat it virtually
	$options = array();
	if (isset($_GET['vp']) && is_string($_GET['vp']) && validateUsername($_GET['vp'])) {
		$options['vp'] = $_GET['vp'];
	}
	if (isset($_GET['virtual']) && $_GET['virtual'] === '0') {
		$options['virtual'] = false;
	}
	$state = boardState($contest, $myUser, $options);
	$here = "/contest/{$contest['id']}/board";
	// (what the page asks for again is what it was asked for)
	$asked = http_build_query(array('json' => 1) + (isset($options['vp']) ? array('vp' => $options['vp']) : array()) + (isset($options['virtual']) ? array('virtual' => 0) : array()));

	// ---- asked by the page itself, every few seconds: what there is now, or that it is what
	// the page has already
	if (isset($_GET['json'])) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		if (isset($_GET['have']) && $_GET['have'] === $state['hash']) {
			die(json_encode(array('same' => true, 'hash' => $state['hash'], 'phase' => $state['phase'], 'elapsed' => $state['elapsed'],
				'ticking' => $state['ticking'], 'frozen' => $state['frozen'], 'starts_in' => $state['starts_in'])));
		}
		die(json_encode($state));
	}
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 外榜', array('ShowPageHeader' => false)) ?>
<?= HTML::css_link('/css/uoj-board.css?v=5') ?>
<div class="uoj-ob" id="outer-board" data-url="<?= $here ?>?<?= HTML::escape($asked) ?>" data-here="<?= $here ?>" data-me="<?= Auth::check() ? Auth::id() : '' ?>" data-contest="/contest/<?= $contest['id'] ?>">
	<div class="uoj-ob-top">
		<h1 class="uoj-ob-title" id="outer-board-title"><?= HTML::escape($state['name']) ?></h1>
		<p class="uoj-ob-viewing" id="outer-board-viewing" style="display:none"></p>
		<div class="uoj-ob-bar">
			<div class="uoj-ob-state">
				<span class="uoj-ob-pill" id="outer-board-phase"></span>
				<span class="uoj-ob-rule"><?= $state['rule'] ?></span>
			</div>
			<div class="uoj-ob-clock">
				<span class="uoj-ob-clock-label" id="outer-board-clock-label"></span>
				<strong id="outer-board-clock"></strong>
				<span class="uoj-ob-clock-of" id="outer-board-clock-of"></span>
			</div>
			<div class="uoj-ob-tools">
				<span class="uoj-ob-live" id="outer-board-live" title="榜单自己会更新，不用刷新页面"><i></i><span></span></span>
				<select class="uoj-ob-select" id="outer-board-vp" title="看某位虚拟参赛者此刻看到的榜单" style="display:none"></select>
				<button type="button" class="uoj-ob-button" id="outer-board-virtual" title="名字前带 * 的是赛后虚拟参赛的选手，不是正式成绩" style="display:none"></button>
				<button type="button" class="uoj-ob-button" id="outer-board-scroll" title="榜单很长时自己慢慢往下滚，到底再回到顶上：投在墙上用">自动滚动</button>
				<button type="button" class="uoj-ob-button" id="outer-board-theme" title="深色 / 浅色">深色</button>
				<button type="button" class="uoj-ob-button" id="outer-board-full" title="全屏">全屏</button>
				<a class="uoj-ob-button" href="/contest/<?= $contest['id'] ?>" id="outer-board-back">返回比赛</a>
			</div>
		</div>
		<div class="uoj-ob-progress" id="outer-board-progress" title="比赛进度">
			<div class="uoj-ob-progress-frozen" id="outer-board-progress-frozen"></div>
			<div class="uoj-ob-progress-done" id="outer-board-progress-done"></div>
		</div>
		<p class="uoj-ob-note" id="outer-board-note"></p>
	</div>
	<div class="uoj-ob-scroll">
		<table class="uoj-ob-table" id="table-outer-board">
			<thead></thead>
			<tbody></tbody>
		</table>
	</div>
	<p class="uoj-ob-empty" id="outer-board-empty" style="display:none"></p>
	<div class="uoj-ob-medals" id="outer-board-medals" title="奖牌：同名次的拿同一档，一题没过的不拿，虚拟参赛的不拿也不占名额" style="display:none"></div>
	<div class="uoj-ob-legend" id="outer-board-legend">
		<span><i class="uoj-ob-first"></i><span id="outer-board-legend-first">最先通过</span></span>
		<span><i class="uoj-ob-solved"></i>通过</span>
		<span><i class="uoj-ob-failed"></i>尝试过，未通过</span>
		<span><i class="uoj-ob-pending"></i>结果待揭晓（封榜后或评测中）</span>
		<span class="uoj-ob-legend-virtual" id="outer-board-legend-virtual" style="display:none">名字前带 * 的是赛后虚拟参赛的选手，不是正式成绩；点名字看 TA 的视角</span>
		<span class="uoj-ob-legend-dirt">Dirt：已通过的题目里，未通过的提交占全部提交的比例</span>
	</div>
	<noscript><p class="text-center">外榜需要浏览器打开 JavaScript 才能显示。</p></noscript>
</div>
<script type="text/javascript">
window.uojOuterBoard = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?= HTML::js_src('/js/uoj-board.js?v=4') ?>
<?php echoUOJPageFooter(array('ShowPageFooter' => false)) ?>
