<?php
	requirePHPLib('form');
	requirePHPLib('problem');
	
	// the number in the address is the number of the problem where the address is: on the
	// site, or in a domain
	if (!($problem = problemOfPage())) {
		become404Page();
	}
	if (!can($myUser, 'problem.manage', $problem)) {
		become403Page();
	}
	
	$problem_content = queryProblemContent($problem['id']);
	$problem_tags = queryProblemTags($problem['id']);
	
	$problem_editor = new UOJBlogEditor();
	$problem_editor->name = 'problem';
	$problem_editor->blog_url = problemUrl($problem);
	$problem_editor->cur_data = array(
		'title' => $problem['title'],
		'content_md' => $problem_content['statement_md'],
		'content' => $problem_content['statement'],
		'tags' => $problem_tags,
		'is_hidden' => $problem['is_hidden']
	);
	$problem_editor->label_text = array_merge($problem_editor->label_text, array(
		'view blog' => '查看题目',
		'blog visibility' => '题目可见性'
	));
	
	// The page saves by itself a moment after the typing stops, and what it saves is what
	// the problem says from then on: a statement is not lost because nobody pressed a button.
	$problem_editor->autosave = true;
	$problem_editor->draft_key = 'statement-' . $problem['id'];
	
	$problem_editor->save = function($data) {
		global $problem, $problem_tags, $problem_content, $myUser;
		$before = array('title' => $problem['title'], 'is_hidden' => (int)$problem['is_hidden'], 'tags' => $problem_tags, 'statement_sha256' => hash('sha256', (string)$problem_content['statement_md']));
		$after = array('title' => $data['title'], 'is_hidden' => (int)$data['is_hidden'], 'tags' => $data['tags'], 'statement_sha256' => hash('sha256', $data['content_md']));
		if ($problem_content !== null && $before === $after) {
			// nothing to write, and nothing to write down
			return array();
		}
		DB::update("update problems set title = '".DB::escape($data['title'])."' where id = {$problem['id']}");
		if (!problemSaveStatement($problem['id'], $data['content'], $data['content_md'])) {
			// whoever saved is told, and does not walk away from a statement that is not kept
			return array('extra' => '题面没有保存下来：数据库拒绝了这次写入。请把内容复制到别处留底，然后再试一次。');
		}
		
		if ($data['tags'] !== $problem_tags) {
			DB::delete("delete from problems_tags where problem_id = {$problem['id']}");
			foreach ($data['tags'] as $tag) {
				DB::insert("insert into problems_tags (problem_id, tag) values ({$problem['id']}, '".DB::escape($tag)."')");
			}
		}
		if ($data['is_hidden'] != $problem['is_hidden'] ) {
			problemSetHidden($problem['id'], $data['is_hidden']);
		}
		
		// What the page saved by itself while somebody was writing is one change, not one for
		// every pause in the typing: it is written down once, with where the writing has got to.
		$written = false;
		if (isset($_POST['autosave'])) {
			$after['autosave'] = true;
			$last = DB::selectFirst("select id, after_json from audit_logs where action = 'problem.edit_statement' and resource_type = 'problem' and resource_id = '{$problem['id']}' and actor = '".DB::escape($myUser['username'])."' and created_at > date_sub(now(), interval 10 minute) order by id desc limit 1");
			$last_after = $last ? json_decode($last['after_json'], true) : null;
			if (is_array($last_after) && !empty($last_after['autosave']) && $last_after['is_hidden'] === $after['is_hidden']) {
				$written = (bool)DB::update("update audit_logs set after_json = '".DB::escape(json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))."' where id = {$last['id']}");
			}
		}
		if (!$written) {
			auditLog('problem.edit_statement', 'problem', $problem['id'], $before, $after);
		}
		return array();
	};
	
	$problem_editor->runAtServer();
?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - 编辑 - 题目管理') ?>
<h1 class="page-header" align="center">#<?= problemNumber($problem) ?> : <?=$problem['title']?> 管理</h1>
<?php echoProblemManageTabs($problem, 'statement') ?>
<?php $problem_editor->printHTML() ?>
<?php echoUOJPageFooter() ?>
