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
});
</script>
