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
	
	$managers_form = newAddDelCmdForm('managers',
		function($username) {
			if (!validateUsername($username) || !queryUser($username)) {
				return "不存在名为{$username}的用户";
			}
			return '';
		},
		function($type, $username) {
			global $problem;
			if ($type == '+') {
				DB::query("insert into problems_permissions (problem_id, username) values (${problem['id']}, '$username')");
				auditLog('problem.add_manager', 'problem', $problem['id'], null, array('username' => $username));
			} elseif ($type == '-') {
				DB::query("delete from problems_permissions where problem_id = ${problem['id']} and username = '$username'");
				auditLog('problem.remove_manager', 'problem', $problem['id'], array('username' => $username), null);
			}
		}
	);
	
	$managers_form->runAtServer();
?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - 管理者 - 题目管理') ?>
<h1 class="page-header" align="center">#<?= problemNumber($problem) ?> : <?=$problem['title']?> 管理</h1>
<?php echoProblemManageTabs($problem, 'managers') ?>

<table class="table table-hover">
	<thead>
		<tr>
			<th>#</th>
			<th>用户名</th>
		</tr>
	</thead>
	<tbody>
<?php
	$row_id = 0;
	$result = DB::query("select username from problems_permissions where problem_id = ${problem['id']}");
	while ($row = DB::fetch($result, MYSQLI_ASSOC)) {
		$row_id++;
		echo '<tr>', '<td>', $row_id, '</td>', '<td>', getUserLink($row['username']), '</td>', '</tr>';
	}
?>
	</tbody>
</table>
<p class="text-center">命令格式：命令一行一个，+mike表示把mike加入管理者，-mike表示把mike从管理者中移除</p>
<?php $managers_form->printHTML(); ?>
<?php echoUOJPageFooter() ?>
