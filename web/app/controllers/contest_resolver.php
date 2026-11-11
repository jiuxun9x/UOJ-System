<?php
	// The resolver of an ICPC contest that is over: its board as it froze, and what became of
	// everything it did not tell, revealed one by one in front of the room, the lowest of the
	// board first, as the resolver of the ICPC Tools does it. For the staff of the contest, who
	// know the results anyway: the page holds all of them. See boardResolverData().
	if (!validateUInt($_GET['id']) || !($contest = queryContest($_GET['id']))) {
		become404Page();
	}
	genMoreContestInfo($contest);
	if (!can($myUser, 'contest.assist', $contest)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}
	if (contestRule($contest) !== 'ICPC') {
		becomeMsgPage('<h1 id="resolver-closed">只有 ICPC 赛制的比赛可以滚榜</h1>');
	}
	if ($contest['cur_progress'] <= CONTEST_IN_PROGRESS) {
		becomeMsgPage('<h1 id="resolver-closed">比赛结束之后才能滚榜</h1><p>滚榜会揭晓封榜之后的全部结果。</p>');
	}
	// for the resolvers of others: everything, as the CCS specifications or XCPCIO write it
	if (isset($_GET['export'])) {
		requirePHPLib('feed');
		$facts = feedFacts($contest);
		if ($_GET['export'] === 'clics') {
			header('Content-Type: application/x-ndjson; charset=utf-8');
			header("Content-Disposition: attachment; filename=\"contest-{$contest['id']}-event-feed.ndjson\"");
			header('Cache-Control: no-store');
			die(feedClics($facts));
		}
		if ($_GET['export'] === 'xcpcio') {
			$file = tempnam(sys_get_temp_dir(), 'uoj-xcpcio-');
			$zip = new ZipArchive();
			if ($file === false || $zip->open($file, ZipArchive::OVERWRITE) !== true) {
				becomeMsgPage('<h1>导出失败</h1><p>服务器上没法写临时文件。</p>');
			}
			foreach (feedXcpcio($facts) as $name => $text) {
				$zip->addFromString($name, $text);
			}
			$zip->close();
			header('Content-Type: application/zip');
			header("Content-Disposition: attachment; filename=\"contest-{$contest['id']}-xcpcio.zip\"");
			header('Content-Length: ' . filesize($file));
			header('Cache-Control: no-store');
			readfile($file);
			unlink($file);
			exit;
		}
		become404Page();
	}
	$data = boardResolverData($contest);
	$data['name'] = html_entity_decode(strip_tags((string)$contest['name']), ENT_QUOTES, 'UTF-8');
	$data['duration'] = (int)$contest['last_min'] * 60;
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 滚榜', array('ShowPageHeader' => false)) ?>
<?= HTML::css_link('/fonts/roboto/roboto.css?v=1') ?>
<?= HTML::css_link('/css/uoj-resolver.css?v=2') ?>
<div class="uoj-rs" id="resolver" data-contest="/contest/<?= $contest['id'] ?>" style="--rs-problems: <?= count($data['problems']) ?>">
	<div class="uoj-rs-bar" id="resolver-bar">
		<div class="uoj-rs-state">
			<span class="uoj-rs-pill" id="resolver-phase">滚榜</span>
			<span class="uoj-rs-left" id="resolver-left"></span>
			<span class="uoj-rs-medals" id="resolver-medals" style="display:none"></span>
		</div>
		<div class="uoj-rs-tools">
			<div class="uoj-rs-group">
				<button type="button" class="uoj-rs-button" id="resolver-restart" title="R">从头</button>
				<button type="button" class="uoj-rs-button" id="resolver-back" title="← / Backspace">上一步</button>
				<button type="button" class="uoj-rs-button uoj-rs-next" id="resolver-next" title="空格 / → / Enter">下一步</button>
				<button type="button" class="uoj-rs-button" id="resolver-auto" title="A">自动</button>
				<select class="uoj-rs-select" id="resolver-speed" title="速度">
					<option value="2400">慢</option>
					<option value="1200" selected="selected">中</option>
					<option value="600">快</option>
				</select>
			</div>
			<div class="uoj-rs-group">
				<a class="uoj-rs-button" id="resolver-export-clics" href="/contest/<?= $contest['id'] ?>/resolver?export=clics" title="ICPC Tools Resolver">导出 CLICS</a>
				<a class="uoj-rs-button" id="resolver-export-xcpcio" href="/contest/<?= $contest['id'] ?>/resolver?export=xcpcio" title="XCPCIO">导出 XCPCIO</a>
			</div>
			<div class="uoj-rs-group">
				<button type="button" class="uoj-rs-button" id="resolver-full" title="F">全屏</button>
				<a class="uoj-rs-button" id="resolver-leave" href="/contest/<?= $contest['id'] ?>">返回比赛</a>
			</div>
		</div>
	</div>
	<div class="uoj-rs-stage" id="resolver-stage">
		<div class="uoj-rs-head">
			<span class="uoj-rs-rank">排名</span>
			<span class="uoj-rs-content"><span class="uoj-rs-title"><?= HTML::escape($data['name']) ?></span><span class="uoj-rs-count" id="resolver-count"></span></span>
			<span class="uoj-rs-total">通过</span>
			<span class="uoj-rs-penalty">罚时</span>
		</div>
		<div class="uoj-rs-view" id="resolver-view">
			<div class="uoj-rs-list" id="table-resolver"></div>
		</div>
		<div class="uoj-rs-award" id="resolver-award" style="display:none"></div>
	</div>
	<noscript><p class="text-center">滚榜需要浏览器打开 JavaScript。</p></noscript>
</div>
<script type="text/javascript">
window.uojResolver = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?= HTML::js_src('/js/uoj-resolver.js?v=3') ?>
<?php echoUOJPageFooter(array('ShowPageFooter' => false)) ?>
