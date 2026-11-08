<?php
	requirePHPLib('form');
	requirePHPLib('judger');
	requirePHPLib('data');
	requirePHPLib('problem');

	// several problems at once, ticked in the list (see problemsBatchForm())
	$error = domainHandleForms(array('batch' => problemsBatchForm(null)), UOJContext::requestURI());
	
	function echoProblem($problem) {
		global $myUser, $batch;
		if (can($myUser, 'problem.view', $problem)) {
			echo '<tr class="text-center">';
			if ($batch) {
				echo '<td class="uoj-batch-cell">';
				if (can($myUser, 'problem.manage', $problem)) {
					echo '<input type="checkbox" name="problems[]" value="', $problem['id'], '" form="form-problems-batch" aria-label="选择 #', $problem['id'], '" data-label="#', $problem['id'], ' ', HTML::escape(problemPlainTitle($problem['title'])), '" />';
				}
				echo '</td>';
			}
			if ($problem['submission_id']) {
				echo '<td class="table-success">';
			} else {
				echo '<td>';
			}
			echo '#', $problem['id'], '</td>';
			echo '<td class="text-left">';
			if ($problem['is_hidden']) {
				echo ' <span class="text-danger">[隐藏]</span> ';
			}
			echo '<a href="/problem/', $problem['id'], '">', $problem['title'], '</a>';
			global $search;
			// what was looked for may have been found in the tags: they are shown then
			if (isset($_COOKIE['show_tags_mode']) || $search !== '') {
				foreach (queryProblemTags($problem['id']) as $tag) {
					echo '<a class="uoj-problem-tag">', '<span class="badge badge-pill badge-secondary">', HTML::escape($tag), '</span>', '</a>';
				}
			}
			echo '</td>';
			if (isset($_COOKIE['show_submit_mode'])) {
				$perc = $problem['submit_num'] > 0 ? round(100 * $problem['ac_num'] / $problem['submit_num']) : 0;
				echo <<<EOD
				<td><a href="/submissions?problem_id={$problem['id']}&min_score=100&max_score=100">&times;{$problem['ac_num']}</a></td>
				<td><a href="/submissions?problem_id={$problem['id']}">&times;{$problem['submit_num']}</a></td>
				<td>
					<div class="progress bot-buffer-no">
						<div class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="$perc" aria-valuemin="0" aria-valuemax="100" style="width: $perc%; min-width: 20px;">{$perc}%</div>
					</div>
				</td>
EOD;
			}
			echo '<td class="text-left">', getClickZanBlock('P', $problem['id'], $problem['zan']), '</td>';
			echo '</tr>';
		}
	}
	
	// what the viewer solved, which a guest has nothing of
	$esc_me = $myUser != null ? DB::escape($myUser['username']) : '';
	
	$cond = array();
	
	$search_tag = null;
	
	$cur_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';
	if ($cur_tab == 'template') {
		$search_tag = "模板题";
	}
	// (what is no text, such as tag[]=..., is no tag: the page is not to fail on it)
	if (isset($_GET['tag']) && is_string($_GET['tag'])) {
		$search_tag = $_GET['tag'];
	}
	if ($search_tag) {
		$cond[] = "'".DB::escape($search_tag)."' in (select tag from problems_tags where problems_tags.problem_id = problems.id)";
	}
	// what is typed into the search field is looked for in the numbers, the titles and the tags
	$search = isset($_GET['search']) && is_string($_GET['search']) ? trim(mb_substr($_GET['search'], 0, 50, 'UTF-8')) : '';
	if ($search !== '') {
		$cond[] = problemSearchCond($search);
	}
	
	// the problems of a domain are listed in the domain
	$cond[] = 'problems.owner_domain_id is null';
	
	if ($cond) {
		$cond = join(' and ', $cond);
	} else {
		$cond = '1';
	}
	
	$pag_config = array('page_len' => 100);
	$pag_config['col_names'] = array('*');
	$pag_config['table_name'] = "problems left join best_ac_submissions on best_ac_submissions.submitter = '$esc_me' and problems.id = best_ac_submissions.problem_id";
	$pag_config['cond'] = $cond;
	$pag_config['tail'] = "order by id asc";
	$pag = new Paginator($pag_config);
	$rows = $pag->get();
	// who manages some of the problems of the page ticks them, to do something to all at once
	$batch = false;
	foreach ($rows as $row) {
		if (can($myUser, 'problem.manage', $row)) {
			$batch = true;
			break;
		}
	}

	$header = '<tr>';
	if ($batch) {
		$header .= '<th class="uoj-batch-cell"><input type="checkbox" class="uoj-batch-all" aria-label="全选" title="全选" /></th>';
	}
	$header .= '<th class="text-center" style="width:5em;">ID</th>';
	$header .= '<th>'.UOJLocale::get('problems::problem').'</th>';
	if (isset($_COOKIE['show_submit_mode'])) {
		$header .= '<th class="text-center" style="width:5em;">'.UOJLocale::get('problems::ac').'</th>';
		$header .= '<th class="text-center" style="width:5em;">'.UOJLocale::get('problems::submit').'</th>';
		$header .= '<th class="text-center" style="width:150px;">'.UOJLocale::get('problems::ac rate').'</th>';
	}
	$header .= '<th class="text-center" style="width:180px;">'.UOJLocale::get('appraisal').'</th>';
	$header .= '</tr>';
	
	$tabs_info = array(
		'all' => array(
			'name' => UOJLocale::get('problems::all problems'),
			'url' => "/problems"
		),
		'template' => array(
			'name' => UOJLocale::get('problems::template problems'),
			'url' => "/problems/template"
		)
	);
	
	/*
	<?php
	echoLongTable(array('*'),
		"problems left join best_ac_submissions on best_ac_submissions.submitter = '$esc_me' and problems.id = best_ac_submissions.problem_id", $cond, 'order by id asc',
		$header,
		'echoProblem',
		array('page_len' => 3,
			'table_classes' => array('table', 'table-bordered', 'table-hover', 'table-striped'),
			'head_pagination' => true
		)
	);
?>*/

	$div_classes = array('table-responsive');
	$table_classes = array('table', 'table-bordered', 'table-hover', 'table-striped');
?>
<?php echoUOJPageHeader(UOJLocale::get('problems')) ?>
<?php echoDomainError($error) ?>
<?php $flash = domainTakeFlash(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash[0] ?>" role="alert" id="problems-flash"><?= HTML::escape($flash[1]) ?></div>
<?php endif ?>
<?php // above the list: which list it is, how it is shown, and - for who makes problems - where a problem is made ?>
<div class="uoj-bar" id="problems-bar">
	<div class="uoj-bar-lead">
		<?= HTML::tablist($tabs_info, $cur_tab, 'nav-pills') ?>
	</div>
	<div class="uoj-bar-options">
		<label for="input-show_tags_mode"><input type="checkbox" id="input-show_tags_mode" <?= isset($_COOKIE['show_tags_mode']) ? 'checked="checked" ': ''?>/> <?= UOJLocale::get('problems::show tags') ?></label>
		<label for="input-show_submit_mode"><input type="checkbox" id="input-show_submit_mode" <?= isset($_COOKIE['show_submit_mode']) ? 'checked="checked" ': ''?>/> <?= UOJLocale::get('problems::show statistics') ?></label>
	</div>
	<?php if (can($myUser, 'problem.create')): ?>
	<div class="uoj-actions">
		<a class="btn btn-light border" id="button-import-problems" href="/problems/import"><span class="glyphicon glyphicon-import"></span> 导入题目</a>
		<a class="btn btn-primary" id="button-new-problem" href="/problem/new"><span class="glyphicon glyphicon-plus"></span> <?= UOJLocale::get('problems::add new') ?></a>
	</div>
	<?php endif ?>
</div>
<?php echo $pag->pagination(); ?>
<?php if ($batch): ?>
<?php uojIncludeView('problems-batch') ?>
<?php endif ?>
<?php if ($search !== ''): ?>
<p class="text-muted mb-2" id="problem-search-note">题号、标题或标签里有“<?= HTML::escape($search) ?>”的题目。<a href="/problems">显示全部</a></p>
<?php endif ?>
<script type="text/javascript">
$('#input-show_tags_mode').click(function() {
	if (this.checked) {
		$.cookie('show_tags_mode', '', {path: '/problems'});
	} else {
		$.removeCookie('show_tags_mode', {path: '/problems'});
	}
	location.reload();
});
$('#input-show_submit_mode').click(function() {
	if (this.checked) {
		$.cookie('show_submit_mode', '', {path: '/problems'});
	} else {
		$.removeCookie('show_submit_mode', {path: '/problems'});
	}
	location.reload();
});
</script>
<?php
	echo '<div class="', join(' ', $div_classes), '">';
	echo '<table class="', join(' ', $table_classes), '">';
	echo '<thead>';
	echo $header;
	echo '</thead>';
	echo '<tbody>';
	
	foreach ($rows as $idx => $row) {
		echoProblem($row);
		echo "\n";
	}
	if ($pag->isEmpty()) {
		echo '<tr><td class="text-center" colspan="233">'.UOJLocale::get('none').'</td></tr>';
	}
	
	echo '</tbody>';
	echo '</table>';
	echo '</div>';
	
	echo $pag->pagination();
?>
<?php echoUOJPageFooter() ?>
