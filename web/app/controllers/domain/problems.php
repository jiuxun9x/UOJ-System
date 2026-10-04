<?php
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
	$error = domainHandleForms(array(
		'new' => function() use ($domain, $can_teach) {
			global $myUser;
			if (!$can_teach) {
				return '没有权限';
			}
			$id = domainNewProblem($domain, $myUser);
			redirectTo("/problem/$id/manage/statement");
		},
		'copy' => function() use ($domain, $can_teach) {
			global $myUser;
			if (!$can_teach) {
				return '没有权限';
			}
			$source = isset($_POST['problem_id']) && validateUInt($_POST['problem_id']) ? queryProblemBrief($_POST['problem_id']) : null;
			// a problem that may not be copied is refused like one that does not exist
			if (!$source || !can($myUser, 'problem.copy', $source)) {
				return '题目不存在，或者你没有权限复制它';
			}
			list($id, $err) = domainCopyProblem($source, $domain, $myUser);
			if ($err === '') {
				domainFlash("已把题目 #{$source['id']} 复制为本域的题目 #{$id}。它现在是隐藏的，数据就绪后可以在题目管理里公开。");
			}
			return $err;
		}
	));
	
	$esc_username = Auth::check() ? DB::escape(Auth::id()) : '';
	$problems = DB::selectAll("select problems.*, best_ac_submissions.submission_id as accepted_submission_id from problems left join best_ac_submissions on best_ac_submissions.problem_id = problems.id and best_ac_submissions.submitter = '$esc_username' where problems.owner_domain_id = {$domain['id']}".($can_teach ? '' : ' and problems.is_hidden = 0')." order by problems.id");
?>
<?php echoDomainPageHeader($domain, 'problems', '题目') ?>
<?php echoDomainError($error) ?>

<?php if ($can_teach): ?>
<div class="card mb-3">
	<div class="card-body d-flex flex-wrap align-items-center">
		<form method="post" class="mr-3 mb-2">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="new" />
			<button type="submit" class="btn btn-primary" id="button-new-domain-problem"><span class="glyphicon glyphicon-plus"></span> 新建题目</button>
		</form>
		<form method="post" class="form-inline mb-2" id="form-copy-problem">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="copy" />
			<label class="mr-2" for="input-copy-problem-id">从题库复制</label>
			<input type="text" class="form-control mr-2" id="input-copy-problem-id" name="problem_id" pattern="[0-9]+" required="required" placeholder="题号" style="width:7em" />
			<button type="submit" class="btn btn-outline-primary">复制到本域</button>
		</form>
	</div>
	<div class="card-footer text-muted small">
		复制得到的是一道独立的题目：题面、数据和配置都可以单独修改，和原题互不影响。训练可以直接使用全站公开的题目，不必复制；作业在发布时会自动把用到的公开题复制进来。
	</div>
</div>
<?php endif ?>

<?php if (!$problems): ?>
<div class="uoj-domain-empty">这个域还没有<?= $can_teach ? '' : '公开的' ?>题目。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-hover" id="table-domain-problems">
		<thead>
			<tr>
				<th style="width:6em">#</th>
				<th>题目</th>
				<?php if ($can_teach): ?>
				<th style="width:9em">来源</th>
				<?php endif ?>
				<th style="width:8em" class="text-center">通过 / 提交</th>
				<?php if ($can_teach): ?>
				<th style="width:5em"></th>
				<?php endif ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($problems as $problem): ?>
			<?php $data_state = $can_teach ? domainProblemDataState($problem['id']) : array('ready', ''); ?>
			<tr>
				<td<?= $problem['accepted_submission_id'] ? ' class="table-success"' : '' ?>>#<?= $problem['id'] ?></td>
				<td>
					<a href="<?= domainProblemUrl($domain, $problem['id']) ?>"><?= $problem['title'] ?></a>
					<?php if ($problem['is_hidden']): ?>
					<span class="badge badge-secondary">隐藏</span>
					<?php endif ?>
					<?php if ($data_state[0] === 'pending' || $data_state[0] === 'preparing'): ?>
					<span class="badge badge-info">数据准备中</span>
					<?php elseif ($data_state[0] === 'failed'): ?>
					<span class="badge badge-danger" title="<?= HTML::escape(strip_tags($data_state[1])) ?>">数据同步失败</span>
					<?php elseif ($data_state[0] === 'none'): ?>
					<span class="badge badge-warning">还没有数据</span>
					<?php endif ?>
				</td>
				<?php if ($can_teach): ?>
				<td>
					<?php if ($problem['source_problem_id']): ?>
					<small class="text-muted">复制自 #<?= $problem['source_problem_id'] ?> v<?= $problem['source_data_version'] ?></small>
					<?php endif ?>
				</td>
				<?php endif ?>
				<td class="text-center"><?= $problem['ac_num'] ?> / <?= $problem['submit_num'] ?></td>
				<?php if ($can_teach): ?>
				<td class="text-right"><a class="btn btn-outline-secondary btn-sm" href="/problem/<?= $problem['id'] ?>/manage/statement">管理</a></td>
				<?php endif ?>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
