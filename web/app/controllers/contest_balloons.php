<?php
	// The balloons of a contest: which are to be brought, where to, and which were. For the
	// people who run the contest; whoever carries the balloons is one of its assistants.
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
	if (!can($myUser, 'contest.assist', $contest)) {
		become403Page();
	}
	$may_manage = can($myUser, 'contest.manage', $contest);
	$rule = contestRule($contest);

	// what the list was asked to show stays with it through every form
	$show = isset($_GET['show']) && in_array($_GET['show'], array('pending', 'done'), true) ? $_GET['show'] : 'all';
	$seat = isset($_GET['seat']) && is_string($_GET['seat']) ? mb_substr(trim($_GET['seat']), 0, 20, 'UTF-8') : '';
	$address = function($show, $seat) use ($contest) {
		$query = http_build_query(array_filter(array('show' => $show === 'all' ? '' : $show, 'seat' => $seat), 'strlen'));
		return "/contest/{$contest['id']}/balloons" . ($query !== '' ? '?' . $query : '');
	};
	$here = $address($show, $seat);

	$posted = function($name) {
		return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
	};
	// how the contest gives balloons is for the people who decide about the contest
	$managing = function($change) use ($may_manage) {
		return function() use ($change, $may_manage) {
			if (!$may_manage) {
				become403Page();
			}
			return $change();
		};
	};
	$error = domainHandleForms(array(
		'settings' => $managing(function() use ($contest) {
			global $myUser;
			$err = balloonSaveSettings($contest, isset($_POST['balloons']), isset($_POST['after_freeze']), $myUser);
			if ($err === '') {
				domainFlash(isset($_POST['balloons']) ? '已保存。' : '这场比赛不再发气球。已经记下的“已送”还在，重新启用后照旧。');
			}
			return $err;
		}),
		'colors' => $managing(function() use ($contest, $posted) {
			global $myUser;
			$colors = array();
			foreach (contestProblemIds($contest['id']) as $problem_id) {
				if (isset($_POST["color_$problem_id"])) {
					$colors[(int)$problem_id] = array($posted("color_$problem_id"), $posted("name_$problem_id"));
				}
			}
			$err = balloonSaveColors($contest, $colors, $myUser);
			if ($err === '') {
				domainFlash('颜色已保存。');
			}
			return $err;
		}),
		'colors_bulk' => $managing(function() use ($contest) {
			global $myUser;
			$problem_ids = array_map('intval', contestProblemIds($contest['id']));
			$letters = array();
			foreach ($problem_ids as $index => $problem_id) {
				$letters[chr(ord('A') + $index % 26)] = $problem_id;
			}
			list($chosen, $err) = balloonParseBulk(isset($_POST['bulk']) && is_string($_POST['bulk']) ? $_POST['bulk'] : '', array_keys($letters));
			if ($err !== '') {
				return $err;
			}
			$colors = array();
			foreach ($chosen as $letter => $color) {
				$colors[$letters[$letter]] = $color;
			}
			$err = balloonSaveColors($contest, $colors, $myUser);
			if ($err === '') {
				domainFlash('已保存 ' . count($colors) . ' 道题的颜色。');
			}
			return $err;
		}),
		'done' => function() use ($contest, $posted) {
			global $myUser;
			return balloonSetDone($contest, $posted('username'), validateUInt($posted('problem_id')) ? (int)$posted('problem_id') : 0, true, $myUser);
		},
		'undo' => function() use ($contest, $posted) {
			global $myUser;
			return balloonSetDone($contest, $posted('username'), validateUInt($posted('problem_id')) ? (int)$posted('problem_id') : 0, false, $myUser);
		}
	), $here);
	$flash = domainTakeFlash();

	$gives = balloonRuleGives($rule);
	$enabled = balloonsEnabled($contest);
	$problems = array();
	$colors = balloonColors($contest);
	foreach (contestProblemIds($contest['id']) as $index => $problem_id) {
		$problem = queryProblemBrief($problem_id);
		$problems[] = array('id' => (int)$problem_id, 'letter' => chr(ord('A') + $index % 26), 'title' => $problem ? $problem['title'] : '') + $colors[(int)$problem_id];
	}
	$letter_color = array();
	foreach ($problems as $problem) {
		$letter_color[$problem['letter']] = $problem;
	}
	$balloon_mark = function($problem, $small = false, $first = false) {
		return balloonMarkHTML($problem['letter'], $problem['color'], $problem['name'], $small, $first);
	};

	$queue = $enabled ? balloonQueue($contest) : array('balloons' => array(), 'held_back' => 0);
	$n_pending = 0;
	$n_done = 0;
	// of every problem: how many of its balloons wait, and how many were brought
	$counts = array();
	foreach ($problems as $problem) {
		$counts[$problem['letter']] = array(0, 0);
	}
	foreach ($queue['balloons'] as $balloon) {
		if ($balloon['done_at'] === null) {
			$n_pending++;
			$counts[$balloon['letter']][0]++;
		} else {
			$n_done++;
			$counts[$balloon['letter']][1]++;
		}
	}
	// the ones that wait come first, the one that waits longest before the others; then the
	// ones that were brought, the last of them first
	$listed = array();
	foreach ($queue['balloons'] as $balloon) {
		$is_done = $balloon['done_at'] !== null;
		if (($show === 'pending' && $is_done) || ($show === 'done' && !$is_done)) {
			continue;
		}
		if ($seat !== '' && mb_stripos($balloon['seat'], $seat, 0, 'UTF-8') === false) {
			continue;
		}
		$listed[] = $balloon;
	}
	usort($listed, function($lhs, $rhs) {
		$lhs_done = $lhs['done_at'] !== null;
		$rhs_done = $rhs['done_at'] !== null;
		if ($lhs_done != $rhs_done) {
			return $lhs_done ? 1 : -1;
		}
		if ($lhs_done && $lhs['done_at'] !== $rhs['done_at']) {
			return strcmp($rhs['done_at'], $lhs['done_at']);
		}
		return $lhs['offset'] != $rhs['offset'] ? $lhs['offset'] - $rhs['offset'] : $lhs['submission'] - $rhs['submission'];
	});
	$identities = rosterIdentities(array_unique(array_column($listed, 'username')));
	$frozen = contestFreezeOffset($contest) !== null && $contest['cur_progress'] < CONTEST_FINISHED
		&& UOJTime::$time_now->getTimestamp() >= $contest['start_time']->getTimestamp() + contestFreezeOffset($contest);
	$without_seat = $enabled ? DB::selectCount("select count(*) from contests_registrants where contest_id = {$contest['id']} and seat = ''") : 0;
	// the settings are open when there is something to do in them
	$settings_open = !$enabled || ($error !== '' && isset($_POST['form']) && in_array($_POST['form'], array('settings', 'colors', 'colors_bulk'), true));
	$bulk_text = $error !== '' && isset($_POST['form']) && $_POST['form'] === 'colors_bulk' && isset($_POST['bulk']) && is_string($_POST['bulk']) ? $_POST['bulk'] : balloonBulkText($problems);
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 气球') ?>
<?php echoContestDomainLink($contest) ?>
<div class="d-flex flex-wrap align-items-center mb-3">
	<h2 class="mr-auto mb-2 text-left"><a href="/contest/<?= $contest['id'] ?>"><?= $contest['name'] ?></a> <small class="text-muted">气球</small></h2>
	<div class="uoj-actions mb-2">
		<?php if ($may_manage && $enabled): ?>
		<a class="btn btn-light border btn-sm" href="#balloon-settings" id="link-balloon-settings">颜色与设置</a>
		<?php endif ?>
		<?php if ($may_manage): ?>
		<a class="btn btn-light border btn-sm" href="/contest/<?= $contest['id'] ?>/manage#tab-contestants" id="link-balloon-seats">选手与座位</a>
		<?php endif ?>
		<a class="btn btn-light border btn-sm" href="/contest/<?= $contest['id'] ?>">返回比赛</a>
	</div>
</div>

<?php if (!$gives): ?>
<?php echoDomainError($error) ?>
<div class="card text-left" id="balloons-not-for-rule">
	<div class="card-body">
		<h5 class="card-title">这场比赛没有气球</h5>
		<p class="mb-0">OI 赛制的比赛在进行中只用样例评测、不公布结果，谁过了题要到比赛结束后才知道，所以没有气球可发。想发气球，请在“管理 → 设置”里把赛制改成 ICPC 或 IOI。</p>
	</div>
</div>
<?php elseif (!$enabled): ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?> text-left" role="alert" id="balloons-flash"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<?php echoDomainError($error) ?>
<div class="card text-left" id="balloons-off">
	<div class="card-body">
		<h5 class="card-title">这场比赛还没有启用气球</h5>
		<p>启用之后，选手每通过一道题，这里就多一个要送的气球：什么颜色、送到哪个座位、是不是全场或这道题的第一个。送到了点一下“送到了”，大家看到的是同一张单子。</p>
		<ul class="text-muted">
			<li>每道题有一个颜色，启用后按 A、B、C… 自动分配，可以改成你手里气球的颜色：从色板里选，或者自己写颜色代码。</li>
			<li>座位号在“管理 → 选手”里填，可以批量；启用气球后选手也能在比赛页面自己填。</li>
			<li>封榜后默认不再发气球，免得桌上的气球泄露封榜后的结果；也可以设成继续发。</li>
			<li>送气球的志愿者加为比赛的“助理”就能打开这个页面。</li>
		</ul>
		<?php if ($may_manage): ?>
		<form method="post">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="settings" />
			<input type="hidden" name="balloons" value="on" />
			<?php if (!empty($contest['balloons_after_freeze'])): ?>
			<input type="hidden" name="after_freeze" value="on" />
			<?php endif ?>
			<button type="submit" class="btn btn-primary" id="button-enable-balloons">启用气球</button>
		</form>
		<?php else: ?>
		<p class="mb-0 text-danger">启用气球要由比赛的负责人来做。</p>
		<?php endif ?>
	</div>
</div>
<?php else: ?>
<form method="get" class="form-inline mb-2 uoj-balloon-toolbar" id="form-balloon-filter">
	<div class="btn-group btn-group-sm mr-3 mb-1" role="group">
		<?php foreach (array('all' => '全部', 'pending' => '待送', 'done' => '已送') as $key => $label): ?>
		<a class="btn <?= $show === $key ? 'btn-primary' : 'btn-light border' ?>" href="<?= HTML::escape($address($key, $seat)) ?>" data-show="<?= $key ?>"><?= $label ?></a>
		<?php endforeach ?>
	</div>
	<?php if ($show !== 'all'): ?>
	<input type="hidden" name="show" value="<?= $show ?>" />
	<?php endif ?>
	<label class="mr-1 mb-1" for="input-balloon-seat">座位</label>
	<input type="text" class="form-control form-control-sm mr-1 mb-1" id="input-balloon-seat" name="seat" value="<?= HTML::escape($seat) ?>" maxlength="20" placeholder="例如 A-，只看这一片" style="width:11em" />
	<button type="submit" class="btn btn-light border btn-sm mr-3 mb-1">筛选</button>
	<?php if ($seat !== ''): ?>
	<a class="btn btn-link btn-sm mr-3 mb-1" href="<?= HTML::escape($address($show, '')) ?>">清除</a>
	<?php endif ?>
	<div class="custom-control custom-checkbox ml-auto mb-1">
		<input type="checkbox" class="custom-control-input" id="input-balloons-auto" checked="checked" />
		<label class="custom-control-label" for="input-balloons-auto">自动刷新 <small class="text-muted ml-1" id="balloons-refreshed"></small></label>
	</div>
</form>

<div id="balloon-board" data-pending="<?= $n_pending ?>" data-done="<?= $n_done ?>">
	<?php if ($flash): ?>
	<div class="alert alert-<?= $flash[0] ?> text-left py-2" role="alert" id="balloons-flash"><?= HTML::escape($flash[1]) ?></div>
	<?php endif ?>
	<?php echoDomainError($error) ?>
	<div class="d-flex flex-wrap align-items-center text-left mb-2">
		<span class="mr-3">待送 <strong class="<?= $n_pending > 0 ? 'text-danger' : '' ?>" id="balloons-pending" style="font-size:1.3rem"><?= $n_pending ?></strong></span>
		<span class="mr-3 text-muted">已送 <span id="balloons-done"><?= $n_done ?></span></span>
		<?php if ($frozen && balloonsHeldFrom($contest) !== null): ?>
		<span class="badge badge-info text-wrap text-left mr-3" id="balloons-held-back">已封榜：封榜后通过的<?= $queue['held_back'] > 0 ? ' ' . $queue['held_back'] . ' 个' : '' ?>气球先不发，公布成绩后出现在这里</span>
		<?php elseif ($frozen): ?>
		<span class="badge badge-warning mr-3" id="balloons-after-freeze">已封榜，按设置继续发气球</span>
		<?php endif ?>
		<?php if ($without_seat > 0): ?>
		<span class="text-muted small" id="balloons-without-seat">有 <?= $without_seat ?> 位选手没填座位<?= $may_manage ? '，可以到“选手与座位”里填' : '' ?></span>
		<?php endif ?>
	</div>
	<?php // every problem with its colour, and how many of its balloons wait: what to have at hand ?>
	<div class="uoj-balloon-legend text-left mb-3" id="balloon-legend">
		<?php foreach ($problems as $problem): ?>
		<span class="uoj-balloon-count<?= $counts[$problem['letter']][0] > 0 ? ' uoj-balloon-count-waiting' : '' ?>" data-problem="<?= $problem['letter'] ?>" data-color="<?= $problem['color'] ?>" data-waiting="<?= $counts[$problem['letter']][0] ?>" data-brought="<?= $counts[$problem['letter']][1] ?>"
			title="<?= $problem['letter'] ?> 题<?= $problem['name'] !== '' ? '，' . HTML::escape($problem['name']) : '' ?>：待送 <?= $counts[$problem['letter']][0] ?>，已送 <?= $counts[$problem['letter']][1] ?>">
			<?= $balloon_mark($problem) ?>
			<span class="uoj-balloon-count-text"><span class="uoj-balloon-name"><?= HTML::escape($problem['name'] !== '' ? $problem['name'] : $problem['color']) ?></span><small>待送 <b><?= $counts[$problem['letter']][0] ?></b> · 已送 <?= $counts[$problem['letter']][1] ?></small></span>
		</span>
		<?php endforeach ?>
	</div>
	<div class="table-responsive">
		<table class="table table-hover text-left uoj-balloons" id="table-balloons">
			<thead>
				<tr>
					<th class="uoj-bl-time">时间</th>
					<th class="uoj-bl-balloon">气球</th>
					<th class="uoj-bl-seat">座位</th>
					<th class="uoj-bl-who">选手</th>
					<th class="uoj-bl-has">已有气球</th>
					<th class="uoj-bl-awards">奖项</th>
					<th class="uoj-bl-act"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($listed as $balloon): ?>
				<?php
					$is_done = $balloon['done_at'] !== null;
					$problem = $letter_color[$balloon['letter']];
					$identity = isset($identities[$balloon['username']]) ? $identities[$balloon['username']] : null;
					// the problems the contestant has a balloon for with this one, by their letters
					$has_letters = '';
					foreach ($balloon['has'] as $pos) {
						$has_letters .= chr(ord('A') + $pos % 26);
					}
					// the first of the contest, or of a problem, is seen from across the room
					$first_class = $balloon['first_in_contest'] ? ' uoj-balloon-first-of-all' : ($balloon['first_for_problem'] ? ' uoj-balloon-first' : '');
				?>
				<tr class="<?= $is_done ? 'uoj-balloon-done' : 'uoj-balloon-pending' ?><?= $first_class ?>" data-balloon="<?= $balloon['username'] ?>/<?= $balloon['letter'] ?>" data-username="<?= $balloon['username'] ?>" data-problem="<?= $balloon['letter'] ?>" data-done="<?= $is_done ? 1 : 0 ?>" data-submission="<?= $balloon['submission'] ?>">
					<td class="uoj-bl-time"><a class="text-muted" href="/submission/<?= $balloon['submission'] ?>"><?= contestClock($balloon['offset']) ?></a></td>
					<td class="uoj-bl-balloon text-nowrap"><?= $balloon_mark($problem, false, $balloon['first_for_problem']) ?> <span class="uoj-balloon-name"><?= HTML::escape($problem['name'] !== '' ? $problem['name'] : $problem['color']) ?></span></td>
					<td class="uoj-balloon-seat"><?= $balloon['seat'] !== '' ? HTML::escape($balloon['seat']) : '<span class="text-muted font-weight-normal">未填</span>' ?></td>
					<td class="uoj-bl-who">
						<?= getUserLink($balloon['username']) ?>
						<?php if ($identity && $identity['real_name'] !== ''): ?>
						<span class="ml-1"><?= HTML::escape($identity['real_name']) ?></span>
						<?php endif ?>
					</td>
					<td class="uoj-bl-has text-nowrap" data-has="<?= $has_letters ?>" title="到这个气球为止，这位选手一共有 <?= $balloon['nth'] ?> 个"><?php foreach ($balloon['has'] as $pos): ?><?= $balloon_mark($letter_color[chr(ord('A') + $pos % 26)], true) ?><?php endforeach ?></td>
					<td class="uoj-bl-awards">
						<?php if ($balloon['first_in_contest']): ?>
						<span class="uoj-award uoj-award-contest" data-award="contest" title="全场第一个通过题目的选手">★ 全场一血</span>
						<?php endif ?>
						<?php if ($balloon['first_for_problem']): ?>
						<span class="uoj-award uoj-award-problem" data-award="problem" title="第一个通过这道题的选手">★ <?= $balloon['letter'] ?> 题一血</span>
						<?php endif ?>
					</td>
					<td class="uoj-bl-act text-right text-nowrap">
						<form method="post" class="d-inline">
							<?= HTML::hiddenToken() ?>
							<input type="hidden" name="form" value="<?= $is_done ? 'undo' : 'done' ?>" />
							<input type="hidden" name="username" value="<?= $balloon['username'] ?>" />
							<input type="hidden" name="problem_id" value="<?= $balloon['problem_id'] ?>" />
							<?php if ($is_done): ?>
							<span class="text-success mr-1" title="<?= HTML::escape($balloon['done_by']) ?> 在 <?= $balloon['done_at'] ?> 记下的">已送<?= passedMark('') ?> <small class="text-muted"><?= HTML::escape($balloon['done_by']) ?> <?= substr($balloon['done_at'], 11, 5) ?></small></span>
							<button type="submit" class="btn btn-link btn-sm p-0" title="其实还没送到">撤销</button>
							<?php else: ?>
							<button type="submit" class="btn btn-primary btn-sm">送到了</button>
							<?php endif ?>
						</form>
					</td>
				</tr>
				<?php endforeach ?>
				<?php if (!$listed): ?>
				<tr><td colspan="7" class="text-center text-muted py-4" id="balloons-none"><?= $queue['balloons'] ? '没有符合条件的气球。' : '还没有人通过题目。有人通过时，气球会自己出现在这里。' ?></td></tr>
				<?php endif ?>
			</tbody>
		</table>
	</div>
</div>
<script type="text/javascript">
// The list keeps itself up to date: it is fetched again every few seconds, and a balloon is
// ticked off without the page being loaded anew. Without this script every button of it
// still works, as the forms they are.
$(function() {
	var board = '#balloon-board';
	var title = document.title;
	var busy = false;
	var tell = function() {
		var pending = $(board).data('pending');
		document.title = (pending > 0 ? '(' + pending + ') ' : '') + title;
		var now = new Date();
		var two = function(n) {
			return (n < 10 ? '0' : '') + n;
		};
		$('#balloons-refreshed').text(two(now.getHours()) + ':' + two(now.getMinutes()) + ':' + two(now.getSeconds()));
	};
	var show = function(html) {
		var fresh = $('<div />').append($.parseHTML(html)).find(board);
		if (!fresh.length) {
			// logged out, or the contest gives no balloons any more: the page says which
			window.location.reload();
			return;
		}
		var known = {};
		$(board).find('tr[data-balloon]').each(function() {
			known[$(this).attr('data-balloon')] = true;
		});
		$(board).replaceWith(fresh);
		$(board).find('tr[data-balloon]').each(function() {
			if (!known[$(this).attr('data-balloon')]) {
				$(this).addClass('uoj-balloon-new');
			}
		});
		$(board).uoj_highlight();
		tell();
	};
	$(document).on('submit', board + ' form', function(e) {
		e.preventDefault();
		var form = $(this);
		var data = form.serialize();
		form.find('button').prop('disabled', true);
		busy = true;
		$.post(window.location.href, data).done(show).fail(function() {
			window.location.reload();
		}).always(function() {
			busy = false;
		});
	});
	setInterval(function() {
		if (busy || !$('#input-balloons-auto').prop('checked')) {
			return;
		}
		busy = true;
		$.get(window.location.href).done(show).always(function() {
			busy = false;
		});
	}, 10000);
	tell();
});
</script>

<?php if ($may_manage): ?>
<details class="text-left mt-4" id="balloon-settings"<?= $settings_open ? ' open' : '' ?>>
	<summary class="h5">气球的设置</summary>
	<div class="row mt-3">
		<div class="col-lg-7">
			<form method="post" id="form-balloon-colors">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="colors" />
				<p class="text-muted">每道题的气球颜色。没改过的题按 A、B、C… 的顺序自动取色。要改：从“现成的颜色”里选；或者自己写<strong>颜色代码</strong>（<code>#e53935</code>，也可以写 <code>red</code>、<code>lightblue</code> 这样的英文颜色名），点旁边的色块可以用取色器挑。名字是给送气球的人看的。</p>
				<table class="table table-sm uoj-balloon-colors" id="table-balloon-colors">
					<thead><tr><th style="width:3.5em"></th><th>试题</th><th style="width:8.5em">现成的颜色</th><th style="width:11.5em">颜色代码</th><th style="width:9em">名字</th></tr></thead>
					<tbody>
						<?php foreach ($problems as $problem): ?>
						<?php
							// what was typed stays in the form when it is refused
							$typed_color = isset($_POST["color_{$problem['id']}"]) && is_string($_POST["color_{$problem['id']}"]) ? $_POST["color_{$problem['id']}"] : $problem['color'];
							$typed_name = isset($_POST["name_{$problem['id']}"]) && is_string($_POST["name_{$problem['id']}"]) ? $_POST["name_{$problem['id']}"] : $problem['name'];
							$shown_color = balloonParseColor($typed_color) !== null ? balloonParseColor($typed_color) : $problem['color'];
						?>
						<tr data-problem="<?= $problem['letter'] ?>">
							<td class="uoj-balloon-preview"><?= balloonMarkHTML($problem['letter'], $shown_color, $problem['name']) ?></td>
							<td><?= $problem['title'] ?></td>
							<td>
								<select class="form-control form-control-sm uoj-balloon-preset" aria-label="<?= $problem['letter'] ?> 题现成的颜色">
									<option value="">自己写…</option>
									<?php foreach (balloonPalette() as $preset): ?>
									<option value="<?= $preset[0] ?>" data-name="<?= $preset[1] ?>"<?= $preset[0] === $shown_color && $preset[1] === $typed_name ? ' selected="selected"' : '' ?>><?= $preset[1] ?></option>
									<?php endforeach ?>
								</select>
							</td>
							<td>
								<div class="uoj-balloon-code">
									<input type="text" class="form-control form-control-sm" name="color_<?= $problem['id'] ?>" value="<?= HTML::escape($typed_color) ?>" maxlength="30" spellcheck="false" autocomplete="off" placeholder="#rrggbb" aria-label="<?= $problem['letter'] ?> 题的颜色代码" />
									<input type="color" value="<?= $shown_color ?>" tabindex="-1" title="用取色器挑" aria-label="<?= $problem['letter'] ?> 题的取色器" />
								</div>
							</td>
							<td><input type="text" class="form-control form-control-sm uoj-balloon-color-name" name="name_<?= $problem['id'] ?>" value="<?= HTML::escape($typed_name) ?>" maxlength="20" aria-label="<?= $problem['letter'] ?> 题颜色的名字" /></td>
						</tr>
						<?php endforeach ?>
					</tbody>
				</table>
				<button type="submit" class="btn btn-primary btn-sm" id="button-save-balloon-colors">保存颜色</button>
			</form>
			<details class="mt-3" id="balloon-bulk"<?= isset($_POST['form']) && $_POST['form'] === 'colors_bulk' && $error !== '' ? ' open' : '' ?>>
				<summary>一次填完：每行“题号 颜色 名字”</summary>
				<form method="post" id="form-balloon-bulk" class="mt-2">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="colors_bulk" />
					<textarea class="form-control uoj-conf-text" id="input-balloon-bulk" name="bulk" rows="<?= max(4, min(16, count($problems) + 1)) ?>" spellcheck="false" aria-label="每行一道题的颜色"><?= HTML::escape($bulk_text) ?></textarea>
					<small class="form-text text-muted">每行一道题：题号、颜色、名字（名字可以不写），例如 <code>A #e53935 红色</code>、<code>B lightblue</code>、<code>C 黄色</code>。没写到的题保持不变。这段文字可以整段复制到另一场比赛里用。</small>
					<button type="submit" class="btn btn-primary btn-sm mt-2" id="button-save-balloon-bulk">保存</button>
				</form>
			</details>
		</div>
		<div class="col-lg-5 mt-4 mt-lg-0">
			<form method="post" id="form-balloon-settings">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="settings" />
				<div class="custom-control custom-checkbox mb-2">
					<input type="checkbox" class="custom-control-input" id="input-balloons" name="balloons" checked="checked" />
					<label class="custom-control-label" for="input-balloons">这场比赛发气球</label>
					<small class="form-text text-muted">关掉之后这个页面不再列出气球，选手也不再能自己填座位；记下的“已送”和颜色都留着。</small>
				</div>
				<div class="custom-control custom-checkbox mb-3">
					<input type="checkbox" class="custom-control-input" id="input-balloons-after-freeze" name="after_freeze"<?= !empty($contest['balloons_after_freeze']) ? ' checked="checked"' : '' ?> />
					<label class="custom-control-label" for="input-balloons-after-freeze">封榜后继续发气球</label>
					<small class="form-text text-muted">默认不发：封榜后通过的题，气球等公布成绩后才出现在单子上，免得桌上的气球让全场知道榜上没显示的结果。<?= contestFreezeOffset($contest) === null ? '这场比赛不封榜，这一项不起作用。' : '' ?></small>
				</div>
				<button type="submit" class="btn btn-primary btn-sm" id="button-save-balloon-settings">保存</button>
			</form>
		</div>
	</div>
</details>
<script type="text/javascript">
$(function() {
	// What somebody wrote as a colour, as '#rrggbb', or null: a code, or a name the browser knows.
	// (The server knows the same names, and the ones of the palette, and has the last word.)
	var asCode = function(text) {
		text = $.trim(text).toLowerCase();
		var found = /^#?([0-9a-f]{6})$/.exec(text);
		if (found) {
			return '#' + found[1];
		}
		found = /^#?([0-9a-f])([0-9a-f])([0-9a-f])$/.exec(text);
		if (found) {
			return '#' + found[1] + found[1] + found[2] + found[2] + found[3] + found[3];
		}
		if (!/^[a-z][a-z \-]*$/.test(text)) {
			return null;
		}
		var probe = document.createElement('span');
		probe.style.color = text.replace(/[ \-]/g, '');
		if (probe.style.color === '') {
			return null;
		}
		document.body.appendChild(probe);
		var parts = /(\d+)[^\d]+(\d+)[^\d]+(\d+)/.exec(window.getComputedStyle(probe).color);
		document.body.removeChild(probe);
		if (!parts) {
			return null;
		}
		var code = '#';
		for (var i = 1; i <= 3; i++) {
			code += ('0' + parseInt(parts[i], 10).toString(16)).slice(-2);
		}
		return code;
	};
	// the ink a letter is written in on a colour: the rule the server has
	var inkOn = function(code) {
		var light = 0, weights = [0.2126, 0.7152, 0.0722];
		for (var i = 0; i < 3; i++) {
			var part = parseInt(code.substr(1 + 2 * i, 2), 16) / 255;
			light += weights[i] * (part <= 0.03928 ? part / 12.92 : Math.pow((part + 0.055) / 1.055, 2.4));
		}
		return light > 0.25 ? '#000000' : '#ffffff';
	};
	// the balloon of a row is drawn in the colour the row says
	var paint = function(row, code) {
		row.find('.uoj-balloon-body').attr('fill', code);
		row.find('.uoj-balloon text').attr('fill', inkOn(code));
	};
	$('#table-balloon-colors').on('change', '.uoj-balloon-preset', function() {
		// a colour that is ready is a colour and its name
		var chosen = $(this).find('option:selected');
		if (chosen.val() !== '') {
			var row = $(this).closest('tr');
			row.find('.uoj-balloon-code input[type=text]').val(chosen.val()).removeClass('is-invalid');
			row.find('input[type=color]').val(chosen.val());
			row.find('.uoj-balloon-color-name').val(chosen.attr('data-name'));
			paint(row, chosen.val());
		}
	}).on('input change', 'input[type=color]', function() {
		var row = $(this).closest('tr');
		row.find('.uoj-balloon-code input[type=text]').val($(this).val()).removeClass('is-invalid');
		row.find('.uoj-balloon-preset').val('');
		paint(row, $(this).val());
	}).on('input change', '.uoj-balloon-code input[type=text]', function() {
		var row = $(this).closest('tr');
		var code = asCode($(this).val());
		$(this).toggleClass('is-invalid', code === null && $.trim($(this).val()) !== '');
		row.find('.uoj-balloon-preset').val('');
		if (code !== null) {
			row.find('input[type=color]').val(code);
			paint(row, code);
		}
	});
	// the settings are at the foot of the page: the button at its head opens them
	$('#link-balloon-settings').click(function() {
		$('#balloon-settings').attr('open', 'open');
	});
	if (window.location.hash === '#balloon-settings') {
		$('#balloon-settings').attr('open', 'open');
	}
});
</script>
<?php endif ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
