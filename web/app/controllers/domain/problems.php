<?php
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
	$error = domainHandleForms(array(
		'copy' => function() use ($domain, $can_teach) {
			global $myUser;
			if (!$can_teach) {
				return '没有权限';
			}
			// "12" is problem 12 of the site, "cs101#3" is problem 3 of the domain cs101
			$source = null;
			$wanted = isset($_POST['problem_id']) && is_string($_POST['problem_id']) ? trim($_POST['problem_id']) : '';
			if (validateUInt($wanted)) {
				$source = queryProblemBrief($wanted);
				if ($source && $source['owner_domain_id']) {
					$source = null;
				}
			} elseif (preg_match('/^([a-z0-9][a-z0-9-]{1,30})[#\/]([1-9][0-9]{0,8})$/D', $wanted, $matches)) {
				$from = queryDomainBySlug($matches[1]);
				$source = $from ? queryDomainProblem($from['id'], $matches[2]) : null;
			}
			// a problem that may not be copied is refused like one that does not exist
			if (!$source || !can($myUser, 'problem.copy', $source)) {
				return '题目不存在，或者你没有权限复制它';
			}
			list($id, $err) = domainCopyProblem($source, $domain, $myUser);
			if ($err === '') {
				domainFlash("已把" . ($source['owner_domain_id'] ? '' : '主站的') . "题目 " . problemLabel($source) . " 复制为本域的题目 #" . problemNumber(queryProblemBrief($id)) . "。它现在是隐藏的，数据就绪后可以在题目管理里公开。");
			}
			return $err;
		}
	));
	
	// where a problem can be copied from: the site, and the other domains one teaches in
	$copy_sources = array();
	if ($can_teach) {
		foreach (domainsOfUser($myUser['username']) as $other) {
			if ($other['id'] != $domain['id'] && in_array($other['my_role'], array('owner', 'admin', 'teacher'), true)) {
				$copy_sources[] = $other;
			}
		}
	}

	$esc_username = Auth::check() ? DB::escape(Auth::id()) : '';
	$problems = DB::selectAll("select problems.*, best_ac_submissions.submission_id as accepted_submission_id from problems left join best_ac_submissions on best_ac_submissions.problem_id = problems.id and best_ac_submissions.submitter = '$esc_username' where problems.owner_domain_id = {$domain['id']}".($can_teach ? '' : ' and problems.is_hidden = 0')." order by problems.domain_pid, problems.id");
?>
<?php echoDomainPageHeader($domain, 'problems', '题目') ?>
<?php echoDomainError($error) ?>

<?php if ($can_teach): ?>
<div class="card mb-3">
	<div class="card-body d-flex flex-wrap align-items-center">
		<a class="btn btn-primary mr-3 mb-2" id="button-new-domain-problem" href="<?= domainUrl($domain, '/problem/new') ?>"><span class="glyphicon glyphicon-plus"></span> 新建题目</a>
		<form method="post" class="form-inline mb-2" id="form-copy-problem">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="copy" />
			<?php if ($copy_sources): ?>
			<label class="mr-2" for="select-copy-source">复制</label>
			<select class="form-control mr-2" id="select-copy-source" title="从哪里复制">
				<option value="site">主站的题目</option>
				<?php foreach ($copy_sources as $other): ?>
				<option value="<?= $other['slug'] ?>"><?= HTML::escape($other['name']) ?> 的题目</option>
				<?php endforeach ?>
			</select>
			<?php else: ?>
			<label class="mr-2" for="input-copy-problem-id">从主站复制</label>
			<?php endif ?>
			<input type="text" class="form-control mr-2 uoj-problem-picker" id="input-copy-problem-id" name="problem_id" required="required" placeholder="题号或标题的一部分" data-scope="site" style="width:18em" />
			<button type="submit" class="btn btn-outline-primary ml-2">复制到本域</button>
		</form>
		<?php if ($copy_sources): ?>
		<script type="text/javascript">
		// a problem of another domain is sent as "the address name of the domain # its number"
		$('#select-copy-source').on('change', function() {
			var from = $(this).val();
			$('#input-copy-problem-id').trigger('uoj-picker-scope', [from, from === 'site' ? '' : from + '#']);
		});
		</script>
		<?php endif ?>
	</div>
	<div class="card-footer text-muted small">
		本域的题目有自己的编号，从 1 开始，和主站的题号互不相干。作业、训练和比赛只能用本域的题目：要用主站的题，先在这里复制。复制得到的是一道独立的题目，题面、数据和配置都可以单独修改，和原题互不影响。输入题号或标题的一部分，从列出的题目里选。你任教的另一个域里的题也可以复制<?= $copy_sources ? '：先在左边选那个域' : '' ?>，或者直接写成“域的地址名#题号”，例如 <code>cs101#3</code>。
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
				<td<?= $problem['accepted_submission_id'] ? ' class="table-success"' : '' ?>>#<?= problemNumber($problem) ?></td>
				<td>
					<a href="<?= problemUrl($problem) ?>"><?= $problem['title'] ?></a>
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
					<small class="text-muted"><?= HTML::escape(problemSourceNote($problem['source_problem_id'], $problem['source_data_version'])) ?></small>
					<?php endif ?>
				</td>
				<?php endif ?>
				<td class="text-center"><?= $problem['ac_num'] ?> / <?= $problem['submit_num'] ?></td>
				<?php if ($can_teach): ?>
				<td class="text-right"><a class="btn btn-outline-secondary btn-sm" href="<?= problemUrl($problem, '/manage/statement') ?>">管理</a></td>
				<?php endif ?>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
