<?php
	$domain = domainOfPage();
	$can_teach = can($myUser, 'domain.teach', $domain);
	
	$error = domainHandleForms(array(
		'copy' => function() use ($domain, $can_teach) {
			global $myUser;
			if (!$can_teach) {
				return '没有权限';
			}
			// Several problems at once: what was picked, or typed with blanks or commas between.
			// "12" is problem 12 of the site, "cs101#3" is problem 3 of the domain cs101.
			$typed = isset($_POST['problem_id']) && is_string($_POST['problem_id']) ? $_POST['problem_id'] : '';
			$wanted = array_slice(array_values(array_unique(preg_split('/[\s,，;；]+/u', trim($typed), -1, PREG_SPLIT_NO_EMPTY))), 0, 50);
			if (!$wanted) {
				return '请填写要复制的题目';
			}
			set_time_limit(0);
			$copied = array();
			$failed = array();
			foreach ($wanted as $one) {
				$source = null;
				if (validateUInt($one)) {
					$source = queryProblemBrief($one);
					if ($source && $source['owner_domain_id']) {
						$source = null;
					}
				} elseif (preg_match('/^([a-z0-9][a-z0-9-]{1,30})[#\/]([1-9][0-9]{0,8})$/D', $one, $matches)) {
					$from = queryDomainBySlug($matches[1]);
					$source = $from ? queryDomainProblem($from['id'], $matches[2]) : null;
				}
				// a problem that may not be copied is refused like one that does not exist
				if (!$source || !can($myUser, 'problem.copy', $source)) {
					$failed[] = "$one：题目不存在，或者你没有权限复制它";
					continue;
				}
				list($id, $err) = domainCopyProblem($source, $domain, $myUser);
				if ($err !== '') {
					$failed[] = "$one：$err";
					continue;
				}
				$copied[] = ($source['owner_domain_id'] ? '' : '主站 ') . problemLabel($source) . ' → 本域 #' . problemNumber(queryProblemBrief($id));
			}
			if (!$copied) {
				// nothing was copied: the form says why, with what was typed still in it
				return count($failed) == 1 ? preg_replace('/^[^：]*：/u', '', $failed[0]) : '一道也没有复制成：' . join('；', $failed);
			}
			domainFlash('已复制 ' . count($copied) . ' 道题：' . join('，', $copied) . '。它们现在是隐藏的，数据同步完成后就可以使用。'
				. ($failed ? '没有复制的：' . join('；', $failed) . '。' : ''), $failed ? 'warning' : 'success');
			return '';
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
	// what is typed into the search field is looked for in the numbers, the titles and the tags
	$search = isset($_GET['q']) && is_string($_GET['q']) ? trim(mb_substr($_GET['q'], 0, 50, 'UTF-8')) : '';
	$n_problems = DB::selectCount("select count(*) from problems where problems.owner_domain_id = {$domain['id']}".($can_teach ? '' : ' and problems.is_hidden = 0'));
	$problems = DB::selectAll("select problems.*, best_ac_submissions.submission_id as accepted_submission_id from problems left join best_ac_submissions on best_ac_submissions.problem_id = problems.id and best_ac_submissions.submitter = '$esc_username' where problems.owner_domain_id = {$domain['id']}".($can_teach ? '' : ' and problems.is_hidden = 0').($search === '' ? '' : ' and '.problemSearchCond($search, 'domain_pid'))." order by problems.domain_pid, problems.id");
	// the tags of the problems that are listed: array(id of the problem => its tags)
	$tags_of = array();
	if ($problems) {
		$ids = array();
		foreach ($problems as $problem) {
			$ids[] = (int)$problem['id'];
		}
		foreach (DB::selectAll("select problem_id, tag from problems_tags where problem_id in (".join(', ', $ids).") order by id") as $row) {
			$tags_of[(int)$row['problem_id']][] = $row['tag'];
		}
	}
?>
<?php echoDomainPageHeader($domain, 'problems', '题目') ?>
<?php echoDomainError($error) ?>

<?php if ($can_teach): ?>
<div class="card mb-3">
	<div class="card-body">
		<?php // one row: the way to a new problem, then where a copy comes from, what is copied, and the button that copies it ?>
		<form method="post" class="uoj-copy-row" id="form-copy-problem">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="copy" />
			<a class="btn btn-primary" id="button-new-domain-problem" href="<?= domainUrl($domain, '/problem/new') ?>"><span class="glyphicon glyphicon-plus"></span> 新建题目</a>
			<a class="btn btn-light border" id="button-import-domain-problems" href="<?= domainUrl($domain, '/problems/import') ?>" title="填好的模板，或 Hydro 格式的题目包"><span class="glyphicon glyphicon-import"></span> 导入</a>
			<span class="uoj-copy-divider"></span>
			<?php if ($copy_sources): ?>
			<select class="form-control" id="select-copy-source" title="从哪里复制" aria-label="从哪里复制">
				<option value="site">从主站复制</option>
				<?php foreach ($copy_sources as $other): ?>
				<option value="<?= $other['slug'] ?>">从 <?= HTML::escape($other['name']) ?> 复制</option>
				<?php endforeach ?>
			</select>
			<?php else: ?>
			<label class="mb-0" for="input-copy-problem-id-search">从主站复制</label>
			<?php endif ?>
			<input type="text" class="form-control uoj-problem-picker" id="input-copy-problem-id" name="problem_id" required="required" placeholder="题号或标题的一部分，可以选好几道" data-scope="site" data-multiple="" />
			<button type="submit" class="btn btn-outline-primary">复制到本域</button>
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

<?php if ($n_problems > 0): ?>
<form method="get" class="form-inline mb-3" id="form-search-domain-problems">
	<div class="input-group" style="max-width:24em">
		<input type="text" class="form-control" name="q" id="input-search-domain-problems" value="<?= HTML::escape($search) ?>" placeholder="题号、标题或标签" maxlength="50" />
		<div class="input-group-append">
			<button type="submit" class="btn btn-outline-primary"><span class="glyphicon glyphicon-search"></span> 搜索</button>
		</div>
	</div>
	<?php if ($search !== ''): ?>
	<span class="text-muted ml-3">找到 <?= count($problems) ?> 道。<a href="<?= domainUrl($domain, '/problems') ?>">显示全部</a></span>
	<?php endif ?>
</form>
<?php endif ?>
<?php if (!$problems): ?>
<div class="uoj-domain-empty"><?= $search !== '' ? '没有题号、标题或标签里有“' . HTML::escape($search) . '”的题目。' : '这个域还没有' . ($can_teach ? '' : '公开的') . '题目。' ?></div>
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
					<?php foreach (isset($tags_of[(int)$problem['id']]) ? $tags_of[(int)$problem['id']] : array() as $tag): ?>
					<a class="badge badge-pill badge-light border uoj-domain-problem-tag" href="<?= domainUrl($domain, '/problems') ?>?q=<?= rawurlencode($tag) ?>"><?= HTML::escape($tag) ?></a>
					<?php endforeach ?>
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
