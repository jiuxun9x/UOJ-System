<?php
	requirePHPLib('form');
	
	if (!can($myUser, 'contest.create')) {
		become403Page();
	}
	$time_form = new UOJForm('time');
	$time_form->addInput(
		'name', 'text', '比赛标题', 'New Contest',
		function($str) {
			return '';
		},
		null
	);
	$time_form->addInput(
		'start_time', 'text', '开始时间', date("Y-m-d H:i:s"),
		function($str, &$vdata) {
			try {
				$vdata['start_time'] = new DateTime($str);
			} catch (Exception $e) {
				return '无效时间格式';
			}
			return '';
		},
		null
	);
	$time_form->addInput(
		'last_min', 'text', '时长（单位：分钟）', 180,
		function($str) {
			return !validateUInt($str) ? '必须为一个整数' : '';
		},
		null
	);
	$time_form->handle = function(&$vdata) {
		$start_time_str = $vdata['start_time']->format('Y-m-d H:i:s');
				
		$purifier = HTML::pruifier();
		
		$esc_name = $_POST['name'];
		$esc_name = $purifier->purify($esc_name);
		$esc_name = DB::escape($esc_name);
		
		// The ratings belong to the whole site: a contest only counts for them when an
		// administrator created it, or says so later.
		$esc_extra_config = DB::escape(json_encode(can(Auth::user(), 'contest.rate') ? new stdClass() : array('unrated' => '')));
		DB::query("insert into contests (name, start_time, last_min, status, extra_config) values ('$esc_name', '$start_time_str', ${_POST['last_min']}, 'unfinished', '$esc_extra_config')");
		$contest_id = DB::insert_id();
		DB::insert("insert into contests_permissions (username, contest_id, role) values ('".Auth::id()."', $contest_id, 'owner')");
		auditLog('contest.create', 'contest', $contest_id, null, array('name' => $_POST['name'], 'start_time' => $start_time_str, 'last_min' => (int)$_POST['last_min']));
	};
	$time_form->succ_href="/contests";
	$time_form->runAtServer();
?>
<?php echoUOJPageHeader('添加比赛') ?>
<h1 class="page-header">添加比赛</h1>
<div class="tab-pane active" id="tab-time">
<?php
	$time_form->printHTML();
?>
</div>
<?php echoUOJPageFooter() ?>