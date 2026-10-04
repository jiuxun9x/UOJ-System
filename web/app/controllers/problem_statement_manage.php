<?php
	requirePHPLib('form');
	
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
	
	$problem_editor->save = function($data) {
		global $problem, $problem_tags, $problem_content;
		auditLog('problem.edit_statement', 'problem', $problem['id'],
			array('title' => $problem['title'], 'is_hidden' => (int)$problem['is_hidden'], 'tags' => $problem_tags, 'statement_sha256' => hash('sha256', $problem_content['statement_md'])),
			array('title' => $data['title'], 'is_hidden' => (int)$data['is_hidden'], 'tags' => $data['tags'], 'statement_sha256' => hash('sha256', $data['content_md'])));
		DB::update("update problems set title = '".DB::escape($data['title'])."' where id = {$problem['id']}");
		DB::update("update problems_contents set statement = '".DB::escape($data['content'])."', statement_md = '".DB::escape($data['content_md'])."' where id = {$problem['id']}");
		
		if ($data['tags'] !== $problem_tags) {
			DB::delete("delete from problems_tags where problem_id = {$problem['id']}");
			foreach ($data['tags'] as $tag) {
				DB::insert("insert into problems_tags (problem_id, tag) values ({$problem['id']}, '".DB::escape($tag)."')");
			}
		}
		if ($data['is_hidden'] != $problem['is_hidden'] ) {
			DB::update("update problems set is_hidden = {$data['is_hidden']} where id = {$problem['id']}");
			DB::update("update submissions set is_hidden = {$data['is_hidden']} where problem_id = {$problem['id']}");
			DB::update("update hacks set is_hidden = {$data['is_hidden']} where problem_id = {$problem['id']}");
		}
	};
	
	$problem_editor->runAtServer();
?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - 编辑 - 题目管理') ?>
<h1 class="page-header" align="center">#<?= problemNumber($problem) ?> : <?=$problem['title']?> 管理</h1>
<ul class="nav nav-tabs" role="tablist">
	<li class="nav-item"><a class="nav-link active" href="<?= problemUrl($problem, '/manage/statement') ?>" role="tab">编辑</a></li>
	<li class="nav-item"><a class="nav-link" href="<?= problemUrl($problem, '/manage/managers') ?>" role="tab">管理者</a></li>
	<li class="nav-item"><a class="nav-link" href="<?= problemUrl($problem, '/manage/data') ?>" role="tab">数据</a></li>
	<li class="nav-item"><a class="nav-link" href="<?= problemUrl($problem) ?>" role="tab">返回</a></li>
</ul>
<?php $problem_editor->printHTML() ?>
<?php echoUOJPageFooter() ?>
