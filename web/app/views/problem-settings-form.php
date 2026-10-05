<?php
	// The fields that say what kind of problem a problem is and how it is judged: used where a
	// problem is made and on the page of its data. $settings is what the fields show, as
	// problemSettingsOfConf() gives it.
	$types = problemTypes();
	$checkers = problemCheckers();
	// a builtin checker that the list does not have is shown as what it is
	if (!isset($checkers[$settings['checker']])) {
		$checkers[$settings['checker']] = '内置校验器 ' . $settings['checker'];
	}
?>
<div class="form-group">
	<label>题目类型</label>
	<?php foreach ($types as $type => $info): ?>
	<div class="custom-control custom-radio mb-1">
		<input type="radio" class="custom-control-input" id="input-problem-type-<?= $type ?>" name="type" value="<?= $type ?>"<?= $settings['type'] === $type ? ' checked="checked"' : '' ?> data-needs="<?= HTML::escape($info['needs']) ?>" />
		<label class="custom-control-label" for="input-problem-type-<?= $type ?>"><strong><?= $info['name'] ?></strong> <span class="text-muted">— <?= $info['description'] ?></span></label>
	</div>
	<?php endforeach ?>
	<small class="form-text text-info" id="problem-type-needs"></small>
</div>
<div class="form-row" id="group-problem-limits">
	<div class="form-group col-md-3">
		<label for="input-problem-time_limit">时间限制（秒）</label>
		<input type="text" class="form-control" id="input-problem-time_limit" name="time_limit" inputmode="decimal" value="<?= HTML::escape($settings['time_limit']) ?>" />
		<small class="form-text text-muted">每个测试点；可以写小数，如 2.5。</small>
	</div>
	<div class="form-group col-md-3">
		<label for="input-problem-memory_limit">内存限制（MB）</label>
		<input type="number" class="form-control" id="input-problem-memory_limit" name="memory_limit" min="1" max="16384" value="<?= HTML::escape($settings['memory_limit']) ?>" />
	</div>
</div>
<div class="form-group" id="group-problem-checker" style="max-width:40em">
	<label for="input-problem-checker">答案怎么比较</label>
	<select class="form-control" id="input-problem-checker" name="checker">
		<?php foreach ($checkers as $checker => $label): ?>
		<option value="<?= HTML::escape($checker) ?>"<?= $settings['checker'] === $checker ? ' selected="selected"' : '' ?>><?= HTML::escape($label) ?></option>
		<?php endforeach ?>
	</select>
	<small class="form-text text-muted">交互题由交互器判定对错，不用这一项。</small>
</div>
<div class="form-group">
	<label>怎么计分</label>
	<?php foreach (problemScorings() as $scoring => $label): ?>
	<div class="custom-control custom-radio mb-1">
		<input type="radio" class="custom-control-input" id="input-problem-scoring-<?= $scoring ?>" name="scoring" value="<?= $scoring ?>"<?= $settings['scoring'] === $scoring ? ' checked="checked"' : '' ?> />
		<label class="custom-control-label" for="input-problem-scoring-<?= $scoring ?>"><?= $label ?></label>
	</div>
	<?php endforeach ?>
	<div class="mt-2" id="group-problem-subtasks" style="max-width:26em">
		<textarea class="form-control" name="subtasks" rows="4" placeholder="3 30&#10;7 30&#10;10 40"><?= HTML::escape(problemSubtasksText($settings['subtasks'])) ?></textarea>
		<small class="form-text text-muted">一行一个子任务，写两个数：它的最后一个测试点的编号，和它的分值。上面的例子是 1–3、4–7、8–10 三个子任务，分值 30、30、40。分值加起来要是 100，最后一个子任务要以最后一个测试点结束。ICPC 赛制的比赛只看是否得满分。</small>
	</div>
</div>
<div class="form-group" id="group-problem-samples" style="max-width:26em">
	<label for="input-problem-n_samples">样例个数 <small class="text-muted">（可以不填）</small></label>
	<input type="number" class="form-control" id="input-problem-n_samples" name="n_samples" min="0" placeholder="全部额外测试点" value="<?= $settings['n_samples'] === null ? '' : (int)$settings['n_samples'] ?>" />
	<small class="form-text text-muted">文件名以 sample 或 ex_ 开头的测试点是“额外测试点”：不计分，但不通过就不算满分。其中前几个是样例，选手可以下载，OI 赛制的比赛中只测它们。不填就是全部。</small>
</div>
<script type="text/javascript">
// the fields that belong to a choice are there when the choice is made
$(document).ready(function() {
	var refresh = function() {
		var type = $('input[name=type]:checked');
		$('#problem-type-needs').text(type.data('needs') || '');
		$('#group-problem-limits').toggle(type.val() !== 'submit_answer');
		$('#group-problem-checker').toggle(type.val() !== 'interactive');
		$('#group-problem-samples').toggle(type.val() !== 'submit_answer');
		$('#group-problem-subtasks').toggle($('input[name=scoring]:checked').val() === 'subtasks');
	};
	$('input[name=type], input[name=scoring]').on('change', refresh);
	refresh();
});
</script>
