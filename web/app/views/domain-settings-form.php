<?php
	// the settings of a domain: $settings, and $is_new for the form that creates one
?>
<form method="post" class="uoj-domain-form" id="form-domain-settings">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="<?= $is_new ? 'create' : 'settings' ?>" />
	<div class="form-row">
		<div class="form-group col-md-7">
			<label for="input-name">名称</label>
			<input type="text" class="form-control" id="input-name" name="name" maxlength="100" required="required" value="<?= HTML::escape($settings['name']) ?>" placeholder="例如：2026 秋 数据结构 计科 1 班" />
		</div>
		<div class="form-group col-md-5">
			<label for="input-slug">地址</label>
			<div class="input-group">
				<div class="input-group-prepend"><span class="input-group-text">/d/</span></div>
				<input type="text" class="form-control" id="input-slug" name="slug" maxlength="31" pattern="[a-z0-9][a-z0-9-]{0,29}[a-z0-9]" required="required" value="<?= HTML::escape($settings['slug']) ?>" placeholder="ds-2026-a"<?= $is_new ? '' : ' disabled="disabled"' ?> />
			</div>
			<small class="form-text text-muted"><?= $is_new ? '小写字母、数字和连字符，创建后不能修改。' : '地址创建后不能修改。' ?></small>
		</div>
	</div>
	<div class="form-group">
		<label for="input-description">简介</label>
		<textarea class="form-control" id="input-description" name="description" rows="3" maxlength="2000"><?= HTML::escape($settings['description']) ?></textarea>
	</div>
	<div class="form-row">
		<div class="form-group col-md-4">
			<label for="input-type">类型</label>
			<select class="form-control" id="input-type" name="type">
				<?php foreach (domainTypes() as $value => $label): ?>
				<option value="<?= $value ?>"<?= $settings['type'] === $value ? ' selected="selected"' : '' ?>><?= $label ?></option>
				<?php endforeach ?>
			</select>
		</div>
	</div>
	<p class="text-muted small">域只有它的成员和全站管理员能看到，其他人看不到它，也搜不到它。成员由管理者添加、按名单导入，或凭邀请链接加入。</p>
	<button type="submit" class="btn btn-primary" id="button-submit-domain"><?= $is_new ? '创建' : '保存' ?></button>
	<?php if ($is_new): ?>
	<a class="btn btn-link" href="/domains">取消</a>
	<?php endif ?>
</form>
