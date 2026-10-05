<?php
	// The fields that say what kind of problem a problem is and how it is judged: used where a
	// problem is made and on the page of its data.
	//   $settings   what the fields show, as problemSettingsOfConf() gives it
	//   $files      the uploaded files a program of the problem can be chosen among, or null
	//               where there are no files yet to choose among
	//   $hackable   whether the problem can be hacked, and so has a solution and a validator
	$types = problemTypes();
	$checkers = problemCheckers();
	// a builtin checker that the list does not have is shown as what it is
	if (!isset($checkers[$settings['checker']])) {
		$checkers[$settings['checker']] = '内置校验器 ' . $settings['checker'];
	}
	$files = isset($files) ? $files : null;
	$hackable = !empty($hackable);
	// The field that says which file a program is. Nothing chosen means the file that looks
	// like it by its name, which is said, so that nobody has to guess what will be taken.
	$program_field = function($field, $label, $optional = false) use ($settings, $files) {
		$kind = problemProgramFields()[$field];
		$chosen = isset($settings[$field]) ? (string)$settings[$field] : '';
		$guess = $files === null ? '' : problemGuessProgram($files, $kind);
		echo '<label for="input-problem-', $field, '">', $label, '</label>';
		if ($files === null) {
			echo '<p class="form-control-plaintext text-muted py-0" id="input-problem-', $field, '">创建之后，在“数据与评测”页里从上传的文件中选。</p>';
			return;
		}
		echo '<select class="form-control" id="input-problem-', $field, '" name="', $field, '">';
		if ($optional) {
			echo '<option value="">按文件名找（', $kind, '.cpp）</option>';
		} elseif ($guess !== '') {
			echo '<option value="">自动：', HTML::escape($guess), '</option>';
		} else {
			echo '<option value="">', $files ? '请选择文件' : '还没有上传源文件', '</option>';
		}
		foreach ($files as $file) {
			echo '<option value="', HTML::escape($file), '"', $chosen === $file ? ' selected="selected"' : '', '>', HTML::escape($file), '</option>';
		}
		if ($chosen !== '' && !in_array($chosen, $files, true)) {
			echo '<option value="', HTML::escape($chosen), '" selected="selected">', HTML::escape($chosen), '（文件不在了）</option>';
		}
		echo '</select>';
	};
?>
<div class="form-group">
	<label class="d-block">题目类型</label>
	<div class="btn-group btn-group-toggle flex-wrap" data-toggle="buttons" id="group-problem-type">
		<?php foreach ($types as $type => $info): ?>
		<label class="btn btn-outline-primary<?= $settings['type'] === $type ? ' active' : '' ?>" for="input-problem-type-<?= $type ?>">
			<input type="radio" id="input-problem-type-<?= $type ?>" name="type" value="<?= $type ?>"<?= $settings['type'] === $type ? ' checked="checked"' : '' ?> data-description="<?= HTML::escape($info['description']) ?>" data-needs="<?= HTML::escape($info['needs']) ?>" autocomplete="off" /> <?= $info['name'] ?>
		</label>
		<?php endforeach ?>
	</div>
	<small class="form-text text-muted" id="problem-type-description"></small>
	<small class="form-text text-info" id="problem-type-needs"></small>
</div>
<div class="form-row" id="group-problem-limits">
	<div class="form-group col-sm-4">
		<label for="input-problem-time_limit">时间限制（秒）</label>
		<input type="text" class="form-control" id="input-problem-time_limit" name="time_limit" inputmode="decimal" value="<?= HTML::escape($settings['time_limit']) ?>" />
		<small class="form-text text-muted">每个测试点；可以写小数，如 2.5。</small>
	</div>
	<div class="form-group col-sm-4">
		<label for="input-problem-memory_limit">内存限制（MB）</label>
		<input type="number" class="form-control" id="input-problem-memory_limit" name="memory_limit" min="1" max="16384" value="<?= HTML::escape($settings['memory_limit']) ?>" />
	</div>
	<div class="form-group col-sm-4" id="group-problem-passes">
		<label for="input-problem-passes">最多运行几轮</label>
		<input type="number" class="form-control" id="input-problem-passes" name="passes" min="2" max="20" value="<?= (int)$settings['passes'] ?>" />
	</div>
</div>
<small class="form-text text-muted mb-3" id="note-problem-passes" style="margin-top:-0.5rem">2 轮就是常说的“运行两次”。校验器可以提前结束：某一轮之后不再给出下一轮的输入，这一轮的结论就是结果。到了最后一轮还要求再运行，算校验器出错。每一轮各自受时间和内存限制。</small>
<div class="form-row">
	<div class="form-group col-md-7" id="group-problem-checker">
		<label for="input-problem-checker">答案怎么比较</label>
		<select class="form-control" id="input-problem-checker" name="checker">
			<?php foreach ($checkers as $checker => $label): ?>
			<option value="<?= HTML::escape($checker) ?>"<?= $settings['checker'] === $checker ? ' selected="selected"' : '' ?>><?= HTML::escape($label) ?></option>
			<?php endforeach ?>
		</select>
	</div>
	<div class="form-group col-md-5" id="group-problem-checker_file">
		<?php $program_field('checker_file', '校验器文件') ?>
	</div>
	<div class="form-group col-md-5" id="group-problem-interactor_file">
		<?php $program_field('interactor_file', '交互器文件') ?>
	</div>
</div>
<div class="form-group">
	<label>怎么计分</label>
	<?php foreach (problemScorings() as $scoring => $label): ?>
	<div class="custom-control custom-radio mb-1">
		<input type="radio" class="custom-control-input" id="input-problem-scoring-<?= $scoring ?>" name="scoring" value="<?= $scoring ?>"<?= $settings['scoring'] === $scoring ? ' checked="checked"' : '' ?> />
		<label class="custom-control-label" for="input-problem-scoring-<?= $scoring ?>"><?= $label ?></label>
	</div>
	<?php endforeach ?>
	<div class="mt-2" id="group-problem-subtasks" style="max-width:30em">
		<table class="table table-sm table-borderless mb-1" id="table-problem-subtasks" style="display:none">
			<thead><tr><th style="width:5em">子任务</th><th>测试点</th><th style="width:7em">分值</th><th style="width:3em"></th></tr></thead>
			<tbody></tbody>
			<tfoot><tr><td colspan="2"><button type="button" class="btn btn-outline-secondary btn-sm" id="button-add-subtask">添加子任务</button></td><td colspan="2"><span id="subtasks-total" class="small"></span></td></tr></tfoot>
		</table>
		<textarea class="form-control" name="subtasks" rows="4" placeholder="3 30&#10;7 30&#10;10 40"><?= HTML::escape(problemSubtasksText($settings['subtasks'])) ?></textarea>
		<small class="form-text text-muted">每个子任务写它的最后一个测试点和分值：子任务里的测试点全部通过才得到它的分。分值加起来要是 100，最后一个子任务要以最后一个测试点结束。ICPC 赛制的比赛只看是否得满分。</small>
	</div>
</div>
<div class="form-group" id="group-problem-samples" style="max-width:26em">
	<label for="input-problem-n_samples">样例个数 <small class="text-muted">（可以不填）</small></label>
	<input type="number" class="form-control" id="input-problem-n_samples" name="n_samples" min="0" placeholder="全部额外测试点" value="<?= $settings['n_samples'] === null ? '' : (int)$settings['n_samples'] ?>" />
	<small class="form-text text-muted">文件名以 sample 或 ex_ 开头的测试点是“额外测试点”：不计分，但不通过就不算满分。其中前几个是样例，选手可以下载，OI 赛制的比赛中只测它们。不填就是全部。</small>
</div>
<?php if ($hackable && $files !== null): ?>
<div class="form-row" id="group-problem-hack">
	<div class="form-group col-md-6">
		<?php $program_field('std_file', 'Hack 用的标准程序', true) ?>
	</div>
	<div class="form-group col-md-6">
		<?php $program_field('val_file', 'Hack 用的数据校验器', true) ?>
	</div>
</div>
<?php endif ?>
<script type="text/javascript">
// the fields that belong to a choice are there when the choice is made
$(document).ready(function() {
	var refresh = function() {
		var type = $('input[name=type]:checked');
		var kind = type.val();
		$('#problem-type-description').text(type.data('description') || '');
		$('#problem-type-needs').text(type.data('needs') || '');
		$('#group-problem-limits').toggle(kind !== 'submit_answer');
		$('#group-problem-passes, #note-problem-passes').toggle(kind === 'multi_pass');
		$('#group-problem-checker').toggle(kind !== 'interactive' && kind !== 'multi_pass');
		$('#group-problem-checker_file').toggle(kind === 'multi_pass' || (kind !== 'interactive' && $('#input-problem-checker').val() === 'custom'));
		$('#group-problem-interactor_file').toggle(kind === 'interactive');
		$('#group-problem-samples, #group-problem-hack').toggle(kind !== 'submit_answer');
		$('#group-problem-subtasks').toggle($('input[name=scoring]:checked').val() === 'subtasks');
	};
	$('input[name=type], input[name=scoring], #input-problem-checker').on('change', refresh);
	refresh();

	// The subtasks are written into a table, a row for each; the field they are sent in holds
	// them the way they are typed without the table, a line for each.
	var text = $('textarea[name=subtasks]');
	var table = $('#table-problem-subtasks');
	var rows = table.children('tbody');
	var write = function() {
		var lines = [];
		var total = 0;
		var from = 1;
		rows.children('tr').each(function(index) {
			var end = $.trim($(this).find('.subtask-end').val());
			var score = $.trim($(this).find('.subtask-score').val());
			$(this).children('td').first().text(index + 1);
			$(this).find('.subtask-from').text(from + ' –');
			if (/^[0-9]+$/.test(end)) {
				from = parseInt(end, 10) + 1;
			}
			if (end !== '' || score !== '') {
				lines.push(end + ' ' + score);
			}
			total += /^[0-9]+$/.test(score) ? parseInt(score, 10) : 0;
		});
		text.val(lines.join('\n')).trigger('change');
		$('#subtasks-total').text('分值合计 ' + total).toggleClass('text-danger', total !== 100).toggleClass('text-success', total === 100);
	};
	var addRow = function(end, score) {
		var row = $('<tr><td></td><td><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text subtask-from"></span></div>'
			+ '<input type="number" class="form-control subtask-end" min="1" title="这个子任务的最后一个测试点" /></div></td>'
			+ '<td><input type="number" class="form-control form-control-sm subtask-score" min="0" max="100" /></td>'
			+ '<td><button type="button" class="close" title="去掉这个子任务">&times;</button></td></tr>');
		row.find('.subtask-end').val(end);
		row.find('.subtask-score').val(score);
		row.find('input').on('input', write);
		row.find('.close').on('click', function() {
			row.remove();
			write();
		});
		rows.append(row);
	};
	$.each(text.val().split(/\r\n|\r|\n/), function(index, line) {
		var parts = $.trim(line).split(/[\s,，:：]+/);
		if (parts.length === 2 && parts[0] !== '') {
			addRow(parts[0], parts[1]);
		}
	});
	if (!rows.children('tr').length) {
		addRow('', '');
		addRow('', '');
	}
	$('#button-add-subtask').on('click', function() {
		addRow('', '');
		write();
	});
	// what was typed that the table can not hold is left to be seen as it was typed
	if ($.trim(text.val()) === '' || rows.children('tr').length === $.trim(text.val()).split(/\r\n|\r|\n/).length) {
		table.show();
		text.hide();
		$('#subtasks-total').text('');
		rows.children('tr').each(function(index) {
			$(this).children('td').first().text(index + 1);
		});
		var from = 1;
		rows.children('tr').each(function() {
			$(this).find('.subtask-from').text(from + ' –');
			var end = $.trim($(this).find('.subtask-end').val());
			if (/^[0-9]+$/.test(end)) {
				from = parseInt(end, 10) + 1;
			}
		});
	}
});
</script>
