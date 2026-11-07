<?php
	// The fields that say what a contest is: used where a contest is made and where it is
	// changed. $settings is what the fields show, as contestSettings() gives it.
	//   $may_rate       whether the reader decides if the contest counts for the ratings
	//   $in_domain      whether the contest belongs to a domain
	//   $has_password   whether the contest has a password to take part already
	$rules = contestRules();
	$picker_time = str_replace(' ', 'T', substr($settings['start_time'], 0, 16));
?>
<div class="form-group">
	<label for="input-contest-name">比赛名称</label>
	<input type="text" class="form-control" id="input-contest-name" name="name" maxlength="100" required="required" value="<?= HTML::escape($settings['name']) ?>" />
</div>
<div class="form-row">
	<div class="form-group col-md-5">
		<label for="input-contest-start_time">开始时间</label>
		<input type="datetime-local" class="form-control" id="input-contest-start_time" name="start_time" required="required" value="<?= HTML::escape($picker_time) ?>" />
		<small class="form-text text-muted">按服务器所在的时区。</small>
	</div>
	<div class="form-group col-md-3">
		<label for="input-contest-last_min">时长（分钟）</label>
		<input type="number" class="form-control" id="input-contest-last_min" name="last_min" min="1" max="525600" required="required" value="<?= (int)$settings['last_min'] ?>" />
		<small class="form-text text-muted" id="contest-ends-at"></small>
	</div>
</div>

<div class="form-group">
	<label>赛制</label>
	<?php foreach ($rules as $rule => $info): ?>
	<div class="custom-control custom-radio mb-1">
		<input type="radio" class="custom-control-input" id="input-contest-rule-<?= $rule ?>" name="rule" value="<?= $rule ?>"<?= $settings['rule'] === $rule ? ' checked="checked"' : '' ?> />
		<label class="custom-control-label" for="input-contest-rule-<?= $rule ?>"><strong><?= $info['name'] ?></strong> <span class="text-muted">— <?= $info['description'] ?></span></label>
	</div>
	<?php endforeach ?>
	<small class="form-text text-muted">不论哪种赛制，比赛进行中选手都看不到每个测试点的结果。</small>
</div>
<div class="form-row">
	<div class="form-group col-md-4" id="group-contest-freeze">
		<label for="input-contest-freeze_minutes">封榜：最后多少分钟</label>
		<input type="number" class="form-control" id="input-contest-freeze_minutes" name="freeze_minutes" min="0" value="<?= (int)$settings['freeze_minutes'] ?>" />
		<small class="form-text text-muted">只用于 ICPC 赛制。0 表示不封榜。封榜后榜单停在那一刻，直到公布成绩。</small>
	</div>
	<div class="form-group col-md-6" id="group-contest-standings_version">
		<label for="input-contest-standings_version">得 0 分的提交算不算用时</label>
		<select class="form-control" id="input-contest-standings_version" name="standings_version">
			<option value="2"<?= $settings['standings_version'] != 1 ? ' selected="selected"' : '' ?>>不算（推荐）</option>
			<option value="1"<?= $settings['standings_version'] == 1 ? ' selected="selected"' : '' ?>>算（旧版 UOJ 的排名规则）</option>
		</select>
		<small class="form-text text-muted">只用于 OI 和 IOI 赛制：同分时比较用时，用时是每题最后一次提交的时间之和。</small>
	</div>
</div>

<div class="form-group">
	<label>Rating</label>
	<?php if ($in_domain): ?>
	<p class="form-control-plaintext text-muted py-0" id="contest-rated-note">域内的比赛不计入 Rating。</p>
	<?php elseif ($may_rate): ?>
	<div class="form-row align-items-center">
		<div class="col-auto">
			<div class="custom-control custom-checkbox">
				<input type="checkbox" class="custom-control-input" id="input-contest-rated" name="rated"<?= $settings['rated'] ? ' checked="checked"' : '' ?> />
				<label class="custom-control-label" for="input-contest-rated">这场比赛计入 Rating</label>
			</div>
		</div>
		<div class="col-auto">
			<label class="mb-0" for="input-contest-rating_k">变化上限</label>
		</div>
		<div class="col-auto">
			<input type="number" class="form-control form-control-sm" id="input-contest-rating_k" name="rating_k" min="1" max="1000" style="width:6em" value="<?= (int)$settings['rating_k'] ?>" />
		</div>
	</div>
	<small class="form-text text-muted">计入 Rating 的比赛在公布成绩时改变参赛选手的 Rating；变化上限是一场比赛最多能让一个人涨多少分，默认 400。</small>
	<?php else: ?>
	<p class="form-control-plaintext text-muted py-0" id="contest-rated-note">这场比赛<?= $settings['rated'] ? '计入' : '不计入' ?> Rating。是否计入由管理员决定。</p>
	<?php endif ?>
</div>

<div class="form-group">
	<label>谁能参加</label>
	<?php foreach (contestJoinModes() as $mode => $mode_label): ?>
	<div class="custom-control custom-radio mb-1">
		<input type="radio" class="custom-control-input" id="input-join_mode-<?= $mode ?>" name="join_mode" value="<?= $mode ?>"<?= $settings['join_mode'] === $mode ? ' checked="checked"' : '' ?> />
		<label class="custom-control-label" for="input-join_mode-<?= $mode ?>"><?= $mode_label ?></label>
	</div>
	<?php endforeach ?>
	<div class="mt-2" id="group-contest-join_password" style="max-width:24em">
		<label for="input-join_password">参赛密码</label>
		<input type="password" class="form-control" id="input-join_password" name="join_password" maxlength="64" autocomplete="new-password" placeholder="<?= $has_password ? '已设置，留空表示不修改' : '4 到 64 个字符' ?>" />
		<small class="form-text text-muted">保存后不再显示。把它告诉要参加的人。</small>
	</div>
	<small class="form-text text-muted">
		名单限制和密码限制的比赛，开始后它的题目、榜单和提交只有报名成功的选手和工作人员能看到，结束后也是如此。名单在保存之后的“名单”页里填。
		<?php if ($in_domain): ?>这场比赛属于一个域，无论哪种方式，都只有域的成员能参加。<?php endif ?>
	</small>
</div>
<?php if ($in_domain): ?>
<div class="form-group">
	<label>外榜</label>
	<div class="custom-control custom-checkbox">
		<input type="checkbox" class="custom-control-input" id="input-contest-public_board" name="public_board"<?= !empty($settings['public_board']) ? ' checked="checked"' : '' ?> />
		<label class="custom-control-label" for="input-contest-public_board">公开外榜</label>
	</div>
	<small class="form-text text-muted">
		勾选后，这场比赛的外榜谁都能看，不用登录，也不用是本域的成员：上面只有选手的名字、分数和时间，没有题目和代码。不勾选则外榜只有本域的成员能看。比赛的题目、榜单和提交不受影响。
	</small>
</div>
<?php endif ?>
<div class="form-group" id="group-contest-medals">
	<label>外榜的奖牌</label>
	<input type="hidden" name="medals" id="input-contest-medals" value="<?= HTML::escape(json_encode($settings['medals'], JSON_UNESCAPED_UNICODE)) ?>" />
	<div class="table-responsive" style="max-width:44em">
		<table class="table table-sm mb-1 uoj-medal-tiers" id="table-contest-medals">
			<thead>
				<tr>
					<th>名称</th>
					<th style="width:4.5em">颜色</th>
					<th style="width:6em">数量</th>
					<th style="width:9em">单位</th>
					<th style="width:6.5em"></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
	<p class="text-muted small mb-1" id="contest-medals-none" style="display:none">不发奖牌。</p>
	<div class="form-inline">
		<button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-1" id="button-contest-medal-add">添加一档</button>
		<select class="form-control form-control-sm mr-2 mb-1" id="select-contest-medal-preset" title="换成常用的分法">
			<option value="">常用的分法…</option>
			<option value="top3">前三名：金、银、铜各 1 人</option>
			<option value="icpc">区域赛：金 10%、银 20%、铜 30%（有效人数）</option>
			<option value="icpc-honor">区域赛加优胜奖：金 10%、银 20%、铜 30%、优胜奖 20%（有效人数）</option>
			<option value="none">不发奖牌</option>
		</select>
	</div>
	<small class="form-text text-muted">
		从上到下是第一档、第二档……，种类和颜色自己定。每一档的数量可以是固定人数，也可以是参赛人数或有效人数的百分比，按百分比算出的人数向上取整
		（参赛人数是外榜上的正式选手数，有效人数是其中至少通过一题、OI 和 IOI 赛制是得分大于 0 的人数）。
		各档依次往下排：第一档给前若干名，第二档给接下来的若干名……；和拿奖牌的选手名次相同的拿同一档，一题没过（零分）的不拿，赛后虚拟参赛的不拿、也不占名额。奖牌只显示在外榜上。
	</small>
</div>
<div class="form-group">
	<label>比赛结束后</label>
	<div class="custom-control custom-checkbox">
		<input type="checkbox" class="custom-control-input" id="input-contest-reveal_problems" name="reveal_problems"<?= $settings['reveal_problems'] ? ' checked="checked"' : '' ?> />
		<label class="custom-control-label" for="input-contest-reveal_problems">自动公开题目</label>
	</div>
	<small class="form-text text-muted">
		勾选后，比赛到了结束时间，其中隐藏的题目就自动<?= $in_domain ? '对本域的成员' : '' ?>公开，不用再逐题去改；封榜的比赛等公布成绩之后再公开。
		不勾选则题目保持隐藏。同时还在别的没结束的比赛或作业里的题目，等那边也结束了才公开。
	</small>
</div>
<script type="text/javascript">
// the fields that belong to a choice are there when the choice is made
$(document).ready(function() {
	var refresh = function() {
		var rule = $('input[name=rule]:checked').val();
		$('#group-contest-freeze').toggle(rule === 'ICPC');
		$('#group-contest-standings_version').toggle(rule !== 'ICPC');
		$('#group-contest-join_password').toggle($('input[name=join_mode]:checked').val() === 'password');
		var start = new Date($('#input-contest-start_time').val());
		var minutes = parseInt($('#input-contest-last_min').val(), 10);
		if (!isNaN(start.getTime()) && minutes > 0) {
			var end = new Date(start.getTime() + minutes * 60000);
			var two = function(n) { return (n < 10 ? '0' : '') + n; };
			$('#contest-ends-at').text('到 ' + end.getFullYear() + '-' + two(end.getMonth() + 1) + '-' + two(end.getDate()) + ' ' + two(end.getHours()) + ':' + two(end.getMinutes()) + ' 结束');
		} else {
			$('#contest-ends-at').text('');
		}
	};
	$('input[name=rule], input[name=join_mode], #input-contest-start_time, #input-contest-last_min').on('change input', refresh);
	refresh();

	// The medals: a row for every kind, written back into the field the form sends as they change.
	var medals = $('#input-contest-medals');
	var tiers = $('#table-contest-medals tbody');
	// a tier: name, colour, how many, and what of: 'count' (people), 'all' or 'effective' (percent)
	var presets = {
		top3: [['金牌', '#f6c945', '1', 'count'], ['银牌', '#cfd6de', '1', 'count'], ['铜牌', '#e0a36a', '1', 'count']],
		icpc: [['金牌', '#f6c945', '10', 'effective'], ['银牌', '#cfd6de', '20', 'effective'], ['铜牌', '#e0a36a', '30', 'effective']],
		'icpc-honor': [['金牌', '#f6c945', '10', 'effective'], ['银牌', '#cfd6de', '20', 'effective'], ['铜牌', '#e0a36a', '30', 'effective'], ['优胜奖', '#9ec5fe', '20', 'effective']],
		none: []
	};
	var write = function() {
		var list = [];
		tiers.children('tr').each(function() {
			var row = $(this);
			var of = row.find('.uoj-medal-of').val();
			var tier = {name: row.find('.uoj-medal-name').val(), color: row.find('.uoj-medal-color').val(), amount: $.trim(row.find('.uoj-medal-amount').val()) + (of === 'count' ? '' : '%')};
			if (of !== 'count') {
				tier.of = of;
			}
			list.push(tier);
		});
		medals.val(JSON.stringify(list));
		$('#contest-medals-none').toggle(list.length === 0);
		$('#table-contest-medals').toggle(list.length > 0);
		$('#button-contest-medal-add').prop('disabled', list.length >= 10);
	};
	var add = function(name, color, amount, of) {
		var row = $('<tr />');
		row.append($('<td />').append($('<input type="text" class="form-control form-control-sm uoj-medal-name" maxlength="20" />').val(name)));
		row.append($('<td />').append($('<input type="color" class="form-control form-control-sm uoj-medal-color" />').val(color)));
		row.append($('<td />').append($('<input type="text" class="form-control form-control-sm uoj-medal-amount" inputmode="decimal" />').val(amount)));
		row.append($('<td />').append($('<select class="form-control form-control-sm uoj-medal-of" />').append(
			$('<option value="count">人</option>'), $('<option value="all">% 参赛人数</option>'), $('<option value="effective">% 有效人数</option>')
		).val(of)));
		row.append($('<td class="text-nowrap" />').append(
			$('<button type="button" class="btn btn-sm btn-link px-1" title="上移">↑</button>').on('click', function() {
				row.prev().before(row);
				write();
			}),
			$('<button type="button" class="btn btn-sm btn-link px-1" title="下移">↓</button>').on('click', function() {
				row.next().after(row);
				write();
			}),
			$('<button type="button" class="btn btn-sm btn-link text-danger px-1" title="删除这一档">×</button>').on('click', function() {
				row.remove();
				write();
			})
		));
		tiers.append(row);
	};
	var fill = function(list) {
		tiers.empty();
		$.each(list, function(index, tier) {
			add(tier[0], tier[1], tier[2], tier[3]);
		});
		write();
	};
	var told = [];
	try {
		told = $.map(JSON.parse(medals.val()) || [], function(tier) {
			var amount = String(tier.amount);
			var percent = amount.charAt(amount.length - 1) === '%';
			return [[tier.name, tier.color, percent ? amount.slice(0, -1) : amount, percent ? (tier.of === 'all' ? 'all' : 'effective') : 'count']];
		});
	} catch (e) {
		told = presets.top3;
	}
	fill(told);
	tiers.on('input change', 'input, select', write);
	$('#button-contest-medal-add').on('click', function() {
		add('', '#9ec5fe', '1', 'count');
		write();
		tiers.find('.uoj-medal-name').last().focus();
	});
	$('#select-contest-medal-preset').on('change', function() {
		if (presets[$(this).val()]) {
			fill(presets[$(this).val()]);
		}
		$(this).val('');
	});
});
</script>
