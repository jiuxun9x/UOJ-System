<?php
	// Above a list of problems, for who manages some of them: what is done to the ones that are
	// ticked. The boxes are in the rows of the list (input name="problems[]" form="form-problems-batch"),
	// the one in its head ticks them all. See problemsBatchForm().
?>
<form method="post" class="uoj-batch" id="form-problems-batch">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="batch" />
	<input type="hidden" name="action" value="" id="input-batch-action" />
	<span class="uoj-batch-count" id="batch-count">已选 0 道</span>
	<button type="button" class="btn btn-sm btn-outline-success" data-batch="show" disabled="disabled">公开</button>
	<button type="button" class="btn btn-sm btn-outline-secondary" data-batch="hide" disabled="disabled">隐藏</button>
	<button type="button" class="btn btn-sm btn-outline-danger" data-batch="delete" disabled="disabled">删除</button>
	<div class="uoj-batch-confirm" id="batch-confirm" style="display:none">
		<p class="mb-1">删除之后<strong>不能恢复</strong>，题目的数据、提交和 Hack 一起删除；还在作业、训练或比赛里的题不会删除。要删除的是：</p>
		<ul class="mb-2" id="batch-confirm-list"></ul>
		<div class="uoj-batch-confirm-row">
			<label class="mb-0" for="input-batch-confirm">请输入要删除的题目数 <strong id="batch-confirm-count"></strong> 确认</label>
			<input type="text" class="form-control form-control-sm" name="confirm" id="input-batch-confirm" autocomplete="off" inputmode="numeric" />
			<button type="submit" class="btn btn-sm btn-danger" id="button-batch-delete">删除这些题目</button>
			<button type="button" class="btn btn-sm btn-light border" id="button-batch-cancel">取消</button>
		</div>
	</div>
</form>
<script type="text/javascript">
(function() {
	var form = $('#form-problems-batch');
	var boxes = function() {
		return $('input[name="problems[]"][form="form-problems-batch"]');
	};
	var last = null;
	var update = function() {
		var all = boxes();
		var on = all.filter(':checked');
		$('#batch-count').text('已选 ' + on.length + ' 道');
		form.find('[data-batch]').prop('disabled', on.length === 0);
		$('.uoj-batch-all').prop('checked', all.length > 0 && on.length === all.length).prop('indeterminate', on.length > 0 && on.length < all.length);
		all.closest('tr').each(function() {
			$(this).toggleClass('uoj-batch-picked', $(this).find('input[name="problems[]"]').prop('checked'));
		});
		if (on.length === 0) {
			$('#batch-confirm').hide();
		}
	};
	// with Shift, what lies between this box and the one before goes with it
	$(document).on('click', 'input[name="problems[]"][form="form-problems-batch"]', function(e) {
		var all = boxes();
		var at = all.index(this);
		if (e.shiftKey && last !== null && last !== at) {
			all.slice(Math.min(last, at), Math.max(last, at) + 1).prop('checked', this.checked);
		}
		last = at;
		update();
	});
	$(document).on('change', '.uoj-batch-all', function() {
		boxes().prop('checked', this.checked);
		last = null;
		update();
	});
	form.find('[data-batch]').on('click', function() {
		var action = $(this).attr('data-batch');
		$('#input-batch-action').val(action);
		if (action !== 'delete') {
			$('#input-batch-confirm').val('');
			form.trigger('submit');
			return;
		}
		var on = boxes().filter(':checked');
		$('#batch-confirm-list').empty().append(on.map(function() {
			return $('<li />').text($(this).attr('data-label'))[0];
		}).get());
		$('#batch-confirm-count').text(on.length);
		$('#input-batch-confirm').val('');
		$('#batch-confirm').show();
		$('#input-batch-confirm').trigger('focus');
	});
	$('#button-batch-cancel').on('click', function() {
		$('#batch-confirm').hide();
		$('#input-batch-action').val('');
	});
	form.on('submit', function(e) {
		if ($('#input-batch-action').val() === 'delete' && $.trim($('#input-batch-confirm').val()) !== String(boxes().filter(':checked').length)) {
			e.preventDefault();
			$('#input-batch-confirm').addClass('is-invalid').trigger('focus');
		}
	});
	$('#input-batch-confirm').on('input', function() {
		$(this).removeClass('is-invalid');
	});
	// (the list comes after this)
	$(update);
})();
</script>
