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
	$balloon_mark = function($problem, $small = false) {
		return '<span class="uoj-balloon' . ($small ? ' uoj-balloon-sm' : '') . '" style="background-color:' . $problem['color'] . ';color:' . balloonInk($problem['color']) . '"'
			. ' title="' . HTML::escape($problem['letter'] . ($problem['name'] !== '' ? ' ' . $problem['name'] : '')) . '">' . $problem['letter'] . '</span>';
	};

	$queue = $enabled ? balloonQueue($contest) : array('balloons' => array(), 'held_back' => 0);
	$n_pending = 0;
	$n_done = 0;
	foreach ($queue['balloons'] as $balloon) {
		if ($balloon['done_at'] === null) {
			$n_pending++;
		} else {
			$n_done++;
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
	$settings_open = !$enabled || ($error !== '' && isset($_POST['form']) && in_array($_POST['form'], array('settings', 'colors'), true));
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 气球') ?>
<?php echoContestDomainLink($contest) ?>
<div class="d-flex flex-wrap align-items-center mb-3">
	<h2 class="mr-auto mb-2 text-left"><a href="/contest/<?= $contest['id'] ?>"><?= $contest['name'] ?></a> <small class="text-muted">气球</small></h2>
	<div class="mb-2">
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
			<li>每道题有一个颜色，启用后按 A、B、C… 自动分配，可以改成你手里气球的颜色。</li>
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
<div class="uoj-balloon-legend text-left mb-2" id="balloon-legend">
	<?php foreach ($problems as $problem): ?>
	<span class="mr-3 text-nowrap" data-problem="<?= $problem['letter'] ?>" data-color="<?= $problem['color'] ?>"><?= $balloon_mark($problem) ?> <?= HTML::escape($problem['name']) ?></span>
	<?php endforeach ?>
</div>
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
		<span class="badge badge-info mr-3" id="balloons-held-back">已封榜：封榜后通过的<?= $queue['held_back'] > 0 ? ' ' . $queue['held_back'] . ' 个' : '' ?>气球先不发，公布成绩后出现在这里</span>
		<?php elseif ($frozen): ?>
		<span class="badge badge-warning mr-3" id="balloons-after-freeze">已封榜，按设置继续发气球</span>
		<?php endif ?>
		<?php if ($without_seat > 0): ?>
		<span class="text-muted small" id="balloons-without-seat">有 <?= $without_seat ?> 位选手没填座位<?= $may_manage ? '，可以到“选手与座位”里填' : '' ?></span>
		<?php endif ?>
	</div>
	<div class="table-responsive">
		<table class="table table-hover text-left uoj-balloons" id="table-balloons">
			<thead>
				<tr>
					<th style="width:9em">气球</th>
					<th style="width:9em">座位</th>
					<th>选手</th>
					<th class="d-none d-md-table-cell" style="width:5em">时间</th>
					<th class="d-none d-md-table-cell">说明</th>
					<th style="width:11em"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($listed as $balloon): ?>
				<?php
					$is_done = $balloon['done_at'] !== null;
					$problem = $letter_color[$balloon['letter']];
					$identity = isset($identities[$balloon['username']]) ? $identities[$balloon['username']] : null;
				?>
				<tr class="<?= $is_done ? 'uoj-balloon-done' : 'uoj-balloon-pending' ?>" data-balloon="<?= $balloon['username'] ?>/<?= $balloon['letter'] ?>" data-username="<?= $balloon['username'] ?>" data-problem="<?= $balloon['letter'] ?>" data-done="<?= $is_done ? 1 : 0 ?>" data-submission="<?= $balloon['submission'] ?>">
					<td class="text-nowrap"><?= $balloon_mark($problem) ?> <span class="uoj-balloon-name"><?= HTML::escape($problem['name']) ?></span></td>
					<td class="uoj-balloon-seat"><?= $balloon['seat'] !== '' ? HTML::escape($balloon['seat']) : '<span class="text-muted font-weight-normal">未填</span>' ?></td>
					<td>
						<?= getUserLink($balloon['username']) ?>
						<?php if ($identity && $identity['real_name'] !== ''): ?>
						<span class="ml-1"><?= HTML::escape($identity['real_name']) ?></span>
						<?php endif ?>
					</td>
					<td class="d-none d-md-table-cell"><a class="text-muted" href="/submission/<?= $balloon['submission'] ?>"><?= contestClock($balloon['offset']) ?></a></td>
					<td class="d-none d-md-table-cell">
						<?php if ($balloon['first_in_contest']): ?>
						<span class="badge badge-warning" data-award="contest">全场第一个</span>
						<?php endif ?>
						<?php if ($balloon['first_for_problem']): ?>
						<span class="badge badge-success" data-award="problem">本题第一个</span>
						<?php endif ?>
						<span class="text-muted small text-nowrap" data-has="<?= join('', array_map(function($pos) { return chr(ord('A') + $pos % 26); }, $balloon['has'])) ?>">
							他的第 <?= $balloon['nth'] ?> 个<?php if ($balloon['nth'] > 1): ?>：<?php foreach ($balloon['has'] as $pos): ?><?= $balloon_mark($letter_color[chr(ord('A') + $pos % 26)], true) ?><?php endforeach ?><?php endif ?>
						</span>
					</td>
					<td class="text-right text-nowrap">
						<form method="post" class="d-inline">
							<?= HTML::hiddenToken() ?>
							<input type="hidden" name="form" value="<?= $is_done ? 'undo' : 'done' ?>" />
							<input type="hidden" name="username" value="<?= $balloon['username'] ?>" />
							<input type="hidden" name="problem_id" value="<?= $balloon['problem_id'] ?>" />
							<?php if ($is_done): ?>
							<span class="text-success mr-1" title="<?= HTML::escape($balloon['done_by']) ?> 在 <?= $balloon['done_at'] ?> 记下的">已送<?= passedMark() ?> <small class="text-muted"><?= HTML::escape($balloon['done_by']) ?> <?= substr($balloon['done_at'], 11, 5) ?></small></span>
							<button type="submit" class="btn btn-link btn-sm p-0" title="其实还没送到">撤销</button>
							<?php else: ?>
							<button type="submit" class="btn btn-primary btn-sm">送到了</button>
							<?php endif ?>
						</form>
					</td>
				</tr>
				<?php endforeach ?>
				<?php if (!$listed): ?>
				<tr><td colspan="6" class="text-center text-muted py-4" id="balloons-none"><?= $queue['balloons'] ? '没有符合条件的气球。' : '还没有人通过题目。有人通过时，气球会自己出现在这里。' ?></td></tr>
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
				<p class="text-muted">每道题的气球颜色。没改过的题按 A、B、C… 的顺序自动取色；选一个现成的颜色，或者自己挑一个并起个名字（送气球的人靠名字认）。</p>
				<table class="table table-sm uoj-balloon-colors" id="table-balloon-colors">
					<thead><tr><th style="width:4em"></th><th>试题</th><th style="width:9em">现成的颜色</th><th style="width:4em">颜色</th><th style="width:9em">名字</th></tr></thead>
					<tbody>
						<?php foreach ($problems as $problem): ?>
						<tr data-problem="<?= $problem['letter'] ?>">
							<td><?= $balloon_mark($problem) ?></td>
							<td><?= $problem['title'] ?></td>
							<td>
								<select class="form-control form-control-sm uoj-balloon-preset" aria-label="<?= $problem['letter'] ?> 题现成的颜色">
									<option value="">自己挑…</option>
									<?php foreach (balloonPalette() as $preset): ?>
									<option value="<?= $preset[0] ?>" data-name="<?= $preset[1] ?>"<?= $preset[0] === $problem['color'] && $preset[1] === $problem['name'] ? ' selected="selected"' : '' ?>><?= $preset[1] ?></option>
									<?php endforeach ?>
								</select>
							</td>
							<td><input type="color" class="form-control form-control-sm p-0" name="color_<?= $problem['id'] ?>" value="<?= isset($_POST["color_{$problem['id']}"]) && is_string($_POST["color_{$problem['id']}"]) && balloonColorError($_POST["color_{$problem['id']}"], '') === '' ? HTML::escape($_POST["color_{$problem['id']}"]) : $problem['color'] ?>" aria-label="<?= $problem['letter'] ?> 题的颜色" /></td>
							<td><input type="text" class="form-control form-control-sm" name="name_<?= $problem['id'] ?>" value="<?= HTML::escape(isset($_POST["name_{$problem['id']}"]) && is_string($_POST["name_{$problem['id']}"]) ? $_POST["name_{$problem['id']}"] : $problem['name']) ?>" maxlength="20" aria-label="<?= $problem['letter'] ?> 题颜色的名字" /></td>
						</tr>
						<?php endforeach ?>
					</tbody>
				</table>
				<button type="submit" class="btn btn-primary btn-sm" id="button-save-balloon-colors">保存颜色</button>
			</form>
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
// a colour that is ready is a colour and its name
$('#table-balloon-colors').on('change', '.uoj-balloon-preset', function() {
	var chosen = $(this).find('option:selected');
	if (chosen.val() !== '') {
		var row = $(this).closest('tr');
		row.find('input[type=color]').val(chosen.val());
		row.find('input[type=text]').val(chosen.attr('data-name'));
	}
}).on('input change', 'input[type=color]', function() {
	$(this).closest('tr').find('.uoj-balloon-preset').val('');
});
</script>
<?php endif ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
