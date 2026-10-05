<?php
	// creating a training, and what the people who teach in the domain do with it
	$domain = domainOfPage();
	$training = null;
	if (isset($_GET['training_id'])) {
		$training = queryTraining($_GET['training_id']);
		if (!$training || $training['domain_id'] != $domain['id']) {
			become404Page();
		}
		if (!can($myUser, 'training.manage', $training)) {
			become403Page();
		}
	} elseif (!can($myUser, 'domain.teach', $domain)) {
		become403Page();
	}
	
	$posted_problem_id = function() {
		return isset($_POST['problem_id']) && validateUInt($_POST['problem_id']) ? (int)$_POST['problem_id'] : 0;
	};
	$settings = $training ? $training : array('title' => '', 'description_md' => '', 'status' => 'draft');
	foreach (array('title', 'description_md', 'status') as $key) {
		if (isset($_POST['form']) && $_POST['form'] === 'save' && isset($_POST[$key]) && is_string($_POST[$key])) {
			$settings[$key] = $_POST[$key];
		}
	}
	
	$forms = array(
		'save' => function() use ($domain, $training, $settings) {
			global $myUser;
			list($id, $err) = trainingSave($domain, $training, $settings, $myUser);
			if ($err !== '') {
				return $err;
			}
			domainFlash($training ? '设置已保存。' : '训练已创建，现在是草稿。添加题目后把状态改为“已发布”，学生就能看到。');
			redirectTo(domainUrl($domain, "/training/$id/manage"));
		}
	);
	if ($training) {
		$forms += array(
			'add_problem' => function() use ($domain, $training) {
				global $myUser;
				// the numbers that are typed or picked are the numbers the problems have in the domain
				$numbers = domainPostedProblemNumbers();
				foreach ($numbers ? $numbers : array(0) as $number) {
					$err = trainingAddProblem($training, queryDomainProblem($domain['id'], $number), !isset($_POST['optional']), $myUser);
					if ($err !== '') {
						return count($numbers) > 1 ? "题目 #{$number}：$err" : $err;
					}
				}
				return '';
			},
			'update_problem' => function() use ($training, $posted_problem_id) {
				global $myUser;
				return trainingUpdateProblem($training, $posted_problem_id(), !isset($_POST['optional']), $myUser);
			},
			'remove_problem' => function() use ($training, $posted_problem_id) {
				global $myUser;
				return trainingRemoveProblem($training, $posted_problem_id(), $myUser);
			},
			'move_problem' => function() use ($training, $posted_problem_id) {
				return trainingMoveProblem($training, $posted_problem_id(), isset($_POST['direction']) && $_POST['direction'] === 'down');
			},
			'delete' => function() use ($domain, $training) {
				global $myUser;
				trainingDelete($training, $myUser);
				domainFlash('训练已删除。');
				redirectTo(domainUrl($domain, '/trainings'));
			}
		);
	}
	$error = domainHandleForms($forms);
	$problems = $training ? trainingProblems($training) : array();
?>
<?php echoDomainPageHeader($domain, 'trainings', $training ? '管理 ' . $training['title'] : '新建训练') ?>
<?php echoDomainError($error) ?>
<div class="d-flex flex-wrap align-items-start mb-3">
	<h3 class="mr-auto mb-2"><?= $training ? HTML::escape($training['title']) : '新建训练' ?></h3>
	<?php if ($training): ?>
	<a class="btn btn-outline-secondary btn-sm mb-2" href="<?= trainingUrl($domain, $training) ?>">查看训练</a>
	<?php endif ?>
</div>

<div class="row">
	<div class="<?= $training ? 'col-lg-5' : 'col-lg-8' ?>">
		<form method="post" class="uoj-domain-form" id="form-training-settings">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="save" />
			<div class="form-group">
				<label for="input-title">标题</label>
				<input type="text" class="form-control" id="input-title" name="title" maxlength="100" required="required" value="<?= HTML::escape($settings['title']) ?>" placeholder="例如：第一章 线性表" />
			</div>
			<div class="form-group">
				<label for="input-description_md">说明</label>
				<textarea class="form-control" id="input-description_md" name="description_md" rows="6" placeholder="支持 Markdown"><?= HTML::escape($settings['description_md']) ?></textarea>
			</div>
			<div class="form-group">
				<label for="input-status">状态</label>
				<select class="form-control" id="input-status" name="status">
					<option value="draft"<?= $settings['status'] === 'draft' ? ' selected="selected"' : '' ?>>草稿：只有教师能看到</option>
					<option value="published"<?= $settings['status'] === 'published' ? ' selected="selected"' : '' ?>>已发布：域里的成员都能看到</option>
				</select>
			</div>
			<button type="submit" class="btn btn-primary" id="button-save-training"><?= $training ? '保存' : '创建' ?></button>
			<?php if (!$training): ?>
			<a class="btn btn-link" href="<?= domainUrl($domain, '/trainings') ?>">取消</a>
			<?php endif ?>
		</form>
		<?php if ($training): ?>
		<hr />
		<form method="post" onsubmit="return confirm('删除这份训练？学生的提交不受影响。')">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="delete" />
			<button type="submit" class="btn btn-outline-danger btn-sm" id="button-delete-training">删除训练</button>
		</form>
		<?php endif ?>
	</div>
	<?php if ($training): ?>
	<div class="col-lg-7">
		<h4 class="uoj-domain-section-title mt-3 mt-lg-0">题目</h4>
		<?php if (!$problems): ?>
		<div class="uoj-domain-empty mb-3">还没有题目。</div>
		<?php else: ?>
		<div class="table-responsive">
			<table class="table table-hover uoj-domain-members" id="table-training-problems">
				<tbody>
					<?php foreach ($problems as $index => $problem): ?>
					<tr>
						<td style="width:2.5em"><?= $index + 1 ?></td>
						<td>
							<a href="<?= trainingProblemUrl($domain, $problem) ?>"><?= $problem['owner_domain_id'] ? '#' . problemNumber($problem) : '主站 #' . $problem['problem_id'] ?>. <?= $problem['title'] ?></a>
							<?php if (!$problem['owner_domain_id']): ?>
							<span class="badge badge-info" title="这个训练是在题目必须先复制到本域之前建的">主站题目</span>
							<?php endif ?>
							<?php if ($problem['is_hidden']): ?>
							<span class="badge badge-warning" title="这道题现在是隐藏的，学生打不开">学生看不到</span>
							<?php endif ?>
						</td>
						<td class="text-right">
							<form method="post" class="d-inline">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="update_problem" />
								<input type="hidden" name="problem_id" value="<?= $problem['problem_id'] ?>" />
								<?php if ($problem['required']): ?>
								<input type="hidden" name="optional" value="on" />
								<button type="submit" class="btn btn-outline-secondary btn-sm" title="现在是必做，点击改为选做">必做</button>
								<?php else: ?>
								<button type="submit" class="btn btn-outline-secondary btn-sm" title="现在是选做，点击改为必做">选做</button>
								<?php endif ?>
							</form>
							<?php if (count($problems) > 1): ?>
							<form method="post" class="d-inline">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="move_problem" />
								<input type="hidden" name="problem_id" value="<?= $problem['problem_id'] ?>" />
								<button type="submit" name="direction" value="up" class="btn btn-outline-secondary btn-sm" title="上移"<?= $index > 0 ? '' : ' disabled="disabled"' ?>><span class="glyphicon glyphicon-arrow-up"></span></button>
								<button type="submit" name="direction" value="down" class="btn btn-outline-secondary btn-sm" title="下移"<?= $index < count($problems) - 1 ? '' : ' disabled="disabled"' ?>><span class="glyphicon glyphicon-arrow-down"></span></button>
							</form>
							<?php endif ?>
							<form method="post" class="d-inline">
								<?= HTML::hiddenToken() ?>
								<input type="hidden" name="form" value="remove_problem" />
								<input type="hidden" name="problem_id" value="<?= $problem['problem_id'] ?>" />
								<button type="submit" class="btn btn-outline-danger btn-sm">移除</button>
							</form>
						</td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>
		<form method="post" class="form-inline" id="form-add-training-problem">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_problem" />
			<label class="mr-2 mb-2" for="input-problem_id">添加题目</label>
			<input type="text" class="form-control mr-2 mb-2 uoj-problem-picker" id="input-problem_id" name="problem_id" placeholder="题号或标题的一部分" required="required" data-scope="<?= $domain['slug'] ?>" data-multiple="" style="width:16em" />
			<div class="custom-control custom-checkbox mr-2 mb-2">
				<input type="checkbox" class="custom-control-input" id="input-optional" name="optional" />
				<label class="custom-control-label" for="input-optional">选做</label>
			</div>
			<button type="submit" class="btn btn-primary mb-2">添加</button>
		</form>
		<p class="text-muted small">输入题号或标题的一部分，从列出的本域题目里选，可以一次选几道。本域的题目在 <a href="<?= domainUrl($domain, '/problems') ?>">题目</a> 页里新建；要用主站的题目，先在那里把它复制到本域。</p>
	</div>
	<?php endif ?>
</div>
<?php echoUOJPageFooter() ?>
