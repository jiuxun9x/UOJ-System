<?php

function uojHandleAtSign($str, $uri) {
	$referrers = array();
	$res = preg_replace_callback('/@(@|[a-zA-Z0-9_]{1,20})/', function($matches) use (&$referrers) {
		if ($matches[1] === '@') {
			return '@';
		} else {
			$user = queryUser($matches[1]);
			if ($user == null) {
				return $matches[0];
			} else {
				$referrers[$user['username']] = '';
				return '<span class="uoj-username" data-rating="'.$user['rating'].'">@'.$user['username'].'</span>';
			}
		}
	}, $str);
	
	$referrers_list = array();
	foreach ($referrers as $referrer => $val) {
		$referrers_list[] = $referrer;
	}
	
	return array($res, $referrers_list);
}

function uojFilePreview($file_name, $output_limit, $file_type = 'text') {
	switch ($file_type) {
		case 'text':
			return strOmit(file_get_contents($file_name, false, null, 0, $output_limit + 4), $output_limit);
		default:
			return strOmit(shell_exec('xxd -g 4 -l 5000 ' . escapeshellarg($file_name) . ' | head -c ' . ($output_limit + 4)), $output_limit);
	}
}

function uojIncludeView($name, $view_params = array()) {
	extract($view_params);
	include $_SERVER['DOCUMENT_ROOT'].'/app/views/'.$name.'.php';
}

function redirectTo($url) {
	header('Location: '.$url);
	die();
}
function permanentlyRedirectTo($url) {
	header("HTTP/1.1 301 Moved Permanently"); 
	header('Location: '.$url);
	die();
}
function redirectToLogin() {
	if (UOJContext::isAjax()) {
		die('please <a href="'.HTML::url('/login').'">login</a>');
	} else {
		header('Location: '.HTML::url('/login'));
		die();
	}
}
function becomeMsgPage($msg, $title = '消息') {
	if (UOJContext::isAjax()) {
		die($msg);
	} else {
		echoUOJPageHeader($title);
		echo $msg;
		echoUOJPageFooter();
		die();
	}
}
function become404Page() {
	header($_SERVER['SERVER_PROTOCOL'] . " 404 Not Found", true, 404);
	becomeMsgPage('<div class="text-center"><div style="font-size:233px">404</div><p>唔……未找到该页面……你是从哪里点进来的……&gt;_&lt;……</p></div>', '404');
}
function become403Page() {
	header($_SERVER['SERVER_PROTOCOL'] . " 403 Forbidden", true, 403); 
	becomeMsgPage('<div class="text-center"><div style="font-size:233px">403</div><p>禁止入内！ T_T</p></div>', '403');
}

function getUserLink($username, $rating = null) {
	if (validateUsername($username) && ($user = queryUser($username))) {
		if ($rating == null) {
			$rating = $user['rating'];
		}
		$alias = $user['nickname'] !== '' ? ' data-alias="'.HTML::escape($user['nickname']).'"' : '';
		return '<span class="uoj-username" data-rating="'.$rating.'"'.$alias.'>'.$username.'</span>';
	} else {
		$esc_username = HTML::escape($username);
		return '<span>'.$esc_username.'</span>';
	}
}

// who the people of a list are to the school: username => array(student_id, real_name)
function rosterIdentities($usernames) {
	$identities = array();
	$names = array();
	foreach ($usernames as $username) {
		$names[] = "'".DB::escape((string)$username)."'";
	}
	if ($names) {
		foreach (DB::selectAll("select username, student_id, real_name from external_identities where username in (".join(',', $names).") order by id desc") as $row) {
			$identities[$row['username']] = $row;
		}
	}
	return $identities;
}
// A list of people, as the lists of the site are: a table with everything in the middle of
// its cell. Every row has a number and who it is and, where the school knows them, the
// student number and the name. $action gives what a row ends with for a username, or is null.
function echoRoster($id, $usernames, $action = null) {
	$identities = rosterIdentities($usernames);
	echo '<div class="table-responsive uoj-roster-box">';
	echo '<table class="table table-bordered table-hover table-sm uoj-roster" id="', $id, '">';
	echo '<thead><tr><th style="width:4em">#</th><th>用户</th>';
	if ($identities) {
		echo '<th>学号</th><th>姓名</th>';
	}
	if ($action !== null) {
		echo '<th style="width:7em">操作</th>';
	}
	echo '</tr></thead><tbody>';
	$number = 0;
	foreach ($usernames as $username) {
		$username = (string)$username;
		$number++;
		echo '<tr data-username="', HTML::escape($username), '"><td>', $number, '</td><td>', getUserLink($username), '</td>';
		if ($identities) {
			echo '<td>', isset($identities[$username]) ? HTML::escape($identities[$username]['student_id']) : '', '</td>';
			echo '<td>', isset($identities[$username]) ? HTML::escape($identities[$username]['real_name']) : '', '</td>';
		}
		if ($action !== null) {
			echo '<td>', $action($username), '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

// the tabs of the pages that manage a problem
function echoProblemManageTabs($problem, $active) {
	$tabs = array(
		'statement' => '题面',
		'data' => '数据与评测',
		'attachments' => '附件',
		'managers' => '管理者'
	);
	echo '<ul class="nav nav-tabs mb-3" role="tablist" id="problem-manage-tabs">';
	foreach ($tabs as $tab => $label) {
		echo '<li class="nav-item"><a class="nav-link', $tab === $active ? ' active' : '', '" href="', problemUrl($problem, "/manage/$tab"), '" role="tab">', $label, '</a></li>';
	}
	echo '<li class="nav-item"><a class="nav-link" href="', problemUrl($problem), '" role="tab">返回题目</a></li>';
	// apart from the others, where nobody goes by mistake
	echo '<li class="nav-item ml-auto"><a class="nav-link text-danger', $active === 'delete' ? ' active' : '', '" href="', problemUrl($problem, '/manage/delete'), '" role="tab" id="tab-link-delete-problem">删除</a></li>';
	echo '</ul>';
}

function getProblemLink($problem, $problem_title = '!title_only') {
	if ($problem_title == '!title_only') {
		$problem_title = $problem['title'];
	} elseif ($problem_title == '!id_and_title') {
		$problem_title = problemLabel($problem) . ". ${problem['title']}";
	}
	return '<a href="'.problemUrl($problem).'">'.$problem_title.'</a>';
}
// In a contest a problem is A, B, C: that is what it is called there, on its page, on the
// board and in the list of what was submitted, and not the number it has outside.
function getContestProblemLink($problem, $contest_id, $problem_title = '!title_only') {
	if ($problem_title == '!title_only') {
		$problem_title = $problem['title'];
	} elseif ($problem_title == '!id_and_title') {
		$letter = contestProblemLetter($contest_id, $problem['id']);
		$problem_title = ($letter !== null ? $letter : problemLabel($problem)) . ". {$problem['title']}";
	}
	return '<a href="'.contestProblemUrl($contest_id, $problem).'">'.$problem_title.'</a>';
}
// A problem of a homework is read in the homework: the page of the problem itself may be
// closed to the people who do the homework. Whoever does not get into the homework is given
// the address of the problem as it is. In a homework a problem is called by its letter, as
// in a contest.
function getHomeworkProblemLink($problem, $homework_id, $problem_title = '!title_only') {
	static $homeworks = array();
	global $myUser;
	$homework_id = (int)$homework_id;
	if (!array_key_exists($homework_id, $homeworks)) {
		$homework = queryHomework($homework_id);
		$domain = $homework ? queryDomain($homework['domain_id']) : null;
		$homeworks[$homework_id] = $homework && $domain && can($myUser, 'homework.solve', $homework) ? homeworkUrl($domain, $homework) : null;
	}
	if ($problem_title == '!title_only') {
		$problem_title = $problem['title'];
	} elseif ($problem_title == '!id_and_title') {
		$letter = homeworkProblemLetter($homework_id, $problem['id']);
		$problem_title = ($letter !== null ? $letter : problemLabel($problem)) . ". {$problem['title']}";
	}
	$url = $homeworks[$homework_id] === null ? problemUrl($problem) : $homeworks[$homework_id] . '/problem/' . problemNumber($problem);
	return '<a href="'.$url.'">'.$problem_title.'</a>';
}
// the letter a problem has in a homework: its place among the problems of the homework
function homeworkProblemLetter($homework_id, $problem_id) {
	static $orders = array();
	$homework_id = (int)$homework_id;
	if (!isset($orders[$homework_id])) {
		$orders[$homework_id] = array();
		foreach (DB::selectAll("select problem_id from homework_problems where homework_id = $homework_id order by position, problem_id") as $row) {
			$orders[$homework_id][] = (int)$row['problem_id'];
		}
	}
	$index = array_search((int)$problem_id, $orders[$homework_id], true);
	return $index === false || $index >= 26 ? null : chr(ord('A') + $index);
}
// What a contest or a homework is called where there is little room: the beginning of its
// name, ready to be printed. $is_html says the name is kept the way pages print it.
function shortNameOfContext($name, $is_html) {
	$plain = $is_html ? html_entity_decode(strip_tags((string)$name), ENT_QUOTES, 'UTF-8') : (string)$name;
	return HTML::escape(mb_strimwidth($plain, 0, 22, '…', 'UTF-8'));
}
// What a submission was sent to, as a link, in a list of submissions.
//
// $inside: the list is the list of one contest or one homework. There a problem is what it
// is called there: A, B, C.
//
// Anywhere else submissions to all kinds of problems stand together, and a letter alone says
// nothing. A problem that is open in the problem set is called by its number there (the
// number it has in its domain, with the name of the domain, when it is a problem of a
// domain) and leads to its own page. A problem that is not open is known only from the
// contest or the homework it was submitted in: it is called "the contest-A", and leads there.
function getSubmissionProblemLink($problem, $contest_id, $homework_id, $inside = false) {
	if ($inside) {
		if ($contest_id) {
			return getContestProblemLink($problem, $contest_id, '!id_and_title');
		}
		if ($homework_id) {
			return getHomeworkProblemLink($problem, $homework_id, '!id_and_title');
		}
		return getProblemLink($problem, '!id_and_title');
	}
	if (!$problem['is_hidden']) {
		return getProblemLink($problem, '!id_and_title');
	}
	if ($contest_id) {
		$letter = contestProblemLetter($contest_id, $problem['id']);
		$contest = $letter !== null ? queryContest((int)$contest_id) : null;
		if ($contest) {
			return getContestProblemLink($problem, $contest_id, shortNameOfContext($contest['name'], true) . "-$letter. {$problem['title']}");
		}
	} elseif ($homework_id) {
		$letter = homeworkProblemLetter($homework_id, $problem['id']);
		$homework = $letter !== null ? queryHomework((int)$homework_id) : null;
		if ($homework) {
			return getHomeworkProblemLink($problem, $homework_id, shortNameOfContext($homework['title'], false) . "-$letter. {$problem['title']}");
		}
	}
	return getProblemLink($problem, '!id_and_title');
}
function getBlogLink($id) {
	if (validateUInt($id) && $blog = queryBlog($id)) {
		return '<a href="/blogs/'.$id.'">'.$blog['title'].'</a>';
	}
}
function getClickZanBlock($type, $id, $cnt, $val = null) {
	if ($val == null) {
		$val = queryZanVal($id, $type, Auth::user());
	}
	return '<div class="uoj-click-zan-block" data-id="'.$id.'" data-type="'.$type.'" data-val="'.$val.'" data-cnt="'.$cnt.'"></div>';
}


function getLongTablePageRawUri($page) {
	$path = strtok(UOJContext::requestURI(), '?');
	$query_string = strtok('?');
	parse_str($query_string, $param);
			
	$param['page'] = $page;
	if ($page == 1) {
		unset($param['page']);
	}
			
	if ($param) {
		return $path . '?' . http_build_query($param);
	} else {
		return $path;
	}
}
function getLongTablePageUri($page) {
	return HTML::escape(getLongTablePageRawUri($page));
}

function echoLongTable($col_names, $table_name, $cond, $tail, $header_row, $print_row, $config) {
	$pag_config = $config;
	$pag_config['col_names'] = $col_names;
	$pag_config['table_name'] = $table_name;
	$pag_config['cond'] = $cond;
	$pag_config['tail'] = $tail;
	$pag = new Paginator($pag_config);

	$div_classes = isset($config['div_classes']) ? $config['div_classes'] : array('table-responsive');
	$table_classes = isset($config['table_classes']) ? $config['table_classes'] : array('table', 'table-bordered', 'table-hover', 'table-striped', 'table-text-center');
		
	echo '<div class="', join($div_classes, ' '), '">';
	echo '<table class="', join($table_classes, ' '), '">';
	echo '<thead>';
	echo $header_row;
	echo '</thead>';
	echo '<tbody>';

	foreach ($pag->get() as $idx => $row) {
		if (isset($config['get_row_index'])) {
			$print_row($row, $idx);
		} else {
			$print_row($row);
		}
	}
	if ($pag->isEmpty()) {
		echo '<tr><td colspan="233">'.UOJLocale::get('none').'</td></tr>';
	}

	echo '</tbody>';
	echo '</table>';
	echo '</div>';
	
	if (isset($config['print_after_table'])) {
		$fun = $config['print_after_table'];
		$fun();
	}
		
	echo $pag->pagination();
}

function getSubmissionStatusDetails($submission) {
	$html = '<td colspan="233" style="vertical-align: middle">';
	
	$out_status = explode(', ', $submission['status'])[0];
	
	$fly = '<img src="/images/utility/qpx_n/b37.gif" alt="小熊像超人一样飞" class="img-rounded" />';
	$think = '<img src="/images/utility/qpx_n/b29.gif" alt="小熊像在思考" class="img-rounded" />';
	
	if ($out_status == 'Judged') {
		$status_text = '<strong>Judged!</strong>';
		$status_img = $fly;
	} else {
		if ($submission['status_details'] !== '') {
			$status_img = $fly;
			$status_text = HTML::escape($submission['status_details']);
		} else {
			$status_img = $think;
			$status_text = $out_status;
		}
	}
	$html .= '<div class="uoj-status-details-img-div">' . $status_img . '</div>';
	$html .= '<div class="uoj-status-details-text-div">' . $status_text . '</div>';

	$html .= '</td>';
	return $html;
}

// the rule of the contest a submission was made in, looked up once a page
function submissionContestRule($contest_id) {
	static $rules = array();
	$contest_id = (int)$contest_id;
	if (!isset($rules[$contest_id])) {
		$contest = queryContest($contest_id);
		if ($contest) {
			$contest['extra_config'] = json_decode($contest['extra_config'], true);
		}
		$rules[$contest_id] = $contest ? contestRule($contest) : 'OI';
	}
	return $rules[$contest_id];
}
function submissionVerdict($submission) {
	if ($submission['score'] == 100) {
		return 'Accepted';
	}
	$row = DB::selectFirst("select result from submissions where id = ".(int)$submission['id']);
	$result = $row ? json_decode($row['result'], true) : null;
	return submissionVerdictOf($submission['score'], is_array($result) && isset($result['details']) ? $result['details'] : '');
}

// what stands beside a full score, or a verdict that says the same: it is seen in a list
// without reading the numbers
// $says is what the tick stands for, for who points at it or has the page read to them; a
// tick beside words that say it already says nothing ('').
function passedMark($says = '通过') {
	// drawn, not taken from a font: a thin tick that looks the same on every machine
	$label = $says === '' ? ' aria-hidden="true">' : ' role="img" aria-label="' . $says . '"><title>' . $says . '</title>';
	return ' <svg class="uoj-passed-mark" viewBox="0 0 16 16"' . $label . '<path d="M3 8.6l3.3 3.3L13 4.9"/></svg>';
}

function echoSubmission($submission, $config, $user) {
	$problem = queryProblemBrief($submission['problem_id']);
	$submitterLink = getUserLink($submission['submitter']);
	
	if ($submission['score'] == null) {
		$used_time_str = "/";
		$used_memory_str = "/";
	} else {
		$used_time_str = $submission['used_time'] . 'ms';
		$used_memory_str = $submission['used_memory'] . 'kb';
	}
	
	$status = explode(', ', $submission['status'])[0];
	
	$show_status_details = Auth::check() && $submission['submitter'] === Auth::id() && $status !== 'Judged';
	
	if (!$show_status_details) {
		echo '<tr>';
	} else {
		echo '<tr class="warning">';
	}
	if (!isset($config['id_hidden'])) {
		echo '<td><a href="/submission/', $submission['id'], '">#', $submission['id'], '</a></td>';
	}
	if (!isset($config['problem_hidden'])) {
		// 'inside': the list is the list of one contest or one homework
		echo '<td>', getSubmissionProblemLink($problem, $submission['contest_id'], isset($submission['homework_id']) ? $submission['homework_id'] : null, isset($config['inside'])), '</td>';
	}
	if (!isset($config['submitter_hidden'])) {
		echo '<td>', $submitterLink, '</td>';
	}
	if (!isset($config['result_hidden'])) {
		echo '<td>';
		if ($status == 'Judged') {
			if ($submission['score'] == null) {
				// what went wrong before any test ran: CE for a program that does not compile
				if (verdictShort($submission['result_error']) !== $submission['result_error']) {
					echo '<a href="/submission/', $submission['id'], '" class="uoj-verdict text-danger" title="', HTML::escape($submission['result_error']), '"><strong>', verdictShort($submission['result_error']), '</strong></a>';
				} else {
					echo '<a href="/submission/', $submission['id'], '" class="small">', $submission['result_error'], '</a>';
				}
			} elseif (!empty($submission['contest_id']) && submissionContestRule($submission['contest_id']) === 'ICPC') {
				// under the ICPC rule a submission passed or did not, and is said to
				$verdict = submissionVerdict($submission);
				echo '<a href="/submission/', $submission['id'], '" class="uoj-verdict ', $verdict === 'Accepted' ? 'text-success' : 'text-danger', '" title="', HTML::escape($verdict), '"><strong>', HTML::escape(verdictShort($verdict)), '</strong>', $verdict === 'Accepted' ? passedMark() : '', '</a>';
			} else {
				echo '<a href="/submission/', $submission['id'], '" class="uoj-score">', $submission['score'], $submission['score'] == 100 ? passedMark() : '', '</a>';
			}
		} else {
			echo '<a href="/submission/', $submission['id'], '" class="small">', $status, '</a>';
		}
		echo '</td>';
	}
	if (!isset($config['used_time_hidden'])) {
		echo '<td>', $used_time_str, '</td>';
	}
	if (!isset($config['used_memory_hidden'])) {
		echo '<td>', $used_memory_str, '</td>';
	}

	echo '<td>', '<a href="/submission/', $submission['id'], '">', $submission['language'], '</a>', '</td>';

	if ($submission['tot_size'] < 1024) {
		$size_str = $submission['tot_size'] . 'b';
	} else {
		$size_str = sprintf("%.1f", $submission['tot_size'] / 1024) . 'kb';
	}
	echo '<td>', $size_str, '</td>';

	if (!isset($config['submit_time_hidden'])) {
		echo '<td><small>', $submission['submit_time'], '</small></td>';
	}
	if (!isset($config['judge_time_hidden'])) {
		echo '<td><small>', $submission['judge_time'], '</small></td>';
	}
	echo '</tr>';
	if ($show_status_details) {
		echo '<tr id="', "status_details_{$submission['id']}", '" class="info">';
		echo getSubmissionStatusDetails($submission);
		echo '</tr>';
		echo '<script type="text/javascript">update_judgement_status_details('.$submission['id'].')</script>';
	}
}


function echoSubmissionsListOnlyOne($submission, $config, $user) {
	echo '<div class="table-responsive">';
	echo '<table class="table table-bordered table-text-center">';
	echo '<thead>';
	echo '<tr>';
	if (!isset($config['id_hidden'])) {
		echo '<th>ID</th>';
	}
	if (!isset($config['problem_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::problem').'</th>';
	}
	if (!isset($config['submitter_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::submitter').'</th>';
	}
	if (!isset($config['result_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::result').'</th>';
	}
	if (!isset($config['used_time_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::used time').'</th>';
	}
	if (!isset($config['used_memory_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::used memory').'</th>';
	}
	echo '<th>'.UOJLocale::get('problems::language').'</th>';
	echo '<th>'.UOJLocale::get('problems::file size').'</th>';
	if (!isset($config['submit_time_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::submit time').'</th>';
	}
	if (!isset($config['judge_time_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::judge time').'</th>';
	}
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';
	echoSubmission($submission, $config, $user);
	echo '</tbody>';
	echo '</table>';
	echo '</div>';
}


function echoSubmissionsList($cond, $tail, $config, $user) {
	$header_row = '<tr>';
	$col_names = array();
	$col_names[] = 'submissions.status_details';
	$col_names[] = 'submissions.status';
	$col_names[] = 'submissions.result_error';
	$col_names[] = 'submissions.score';
	
	if (!isset($config['id_hidden'])) {
		$header_row .= '<th>ID</th>';
		$col_names[] = 'submissions.id';
	}
	if (!isset($config['problem_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::problem').'</th>';
		$col_names[] = 'submissions.problem_id';
		$col_names[] = 'submissions.contest_id';
		$col_names[] = 'submissions.homework_id';
	}
	if (!isset($config['submitter_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::submitter').'</th>';
		$col_names[] = 'submissions.submitter';
	}
	if (!isset($config['result_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::result').'</th>';
	}
	if (!isset($config['used_time_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::used time').'</th>';
		$col_names[] = 'submissions.used_time';
	}
	if (!isset($config['used_memory_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::used memory').'</th>';
		$col_names[] = 'submissions.used_memory';
	}
	$header_row .= '<th>'.UOJLocale::get('problems::language').'</th>';
	$col_names[] = 'submissions.language';
	$header_row .= '<th>'.UOJLocale::get('problems::file size').'</th>';
	$col_names[] = 'submissions.tot_size';

	if (!isset($config['submit_time_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::submit time').'</th>';
		$col_names[] = 'submissions.submit_time';
	}
	if (!isset($config['judge_time_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::judge time').'</th>';
		$col_names[] = 'submissions.judge_time';
	}
	$header_row .= '</tr>';
	
	$table_name = isset($config['table_name']) ? $config['table_name'] : 'submissions';
	
	$permission_cond = visibleSubmissionsCond($user);
	if ($permission_cond !== '1') {
		$cond = $cond !== '1' ? "($cond) and ($permission_cond)" : $permission_cond;
	}
	
	$table_config = isset($config['table_config']) ? $config['table_config'] : null;
	
	echoLongTable($col_names, $table_name, $cond, $tail, $header_row,
		function($submission) use ($config, $user) {
			echoSubmission($submission, $config, $user);
		}, $table_config);
}

function echoPastesList() {
	$header_row = '<tr>';
	$col_names = ['`index`','creator','created_at'];
	$header_row .= '<th>ID</th>';
	$header_row .= '<th>'.UOJLocale::get("problems::submitter").'</th>';
	$header_row .= '<th>'.UOJLocale::get('problems::submit time').'</th>';
	$header_row .= '<th> 操作 </th>';
	$header_row .= '</tr>';
	$table_name = 'pastes';
	echoLongTable($col_names, $table_name, "1", 'order by created_at desc', $header_row,
		function($paste) {
			$user = getUserLink($paste['creator']);
			$token = HTML::hiddenToken();
			echo <<<HTML
<tr>
	<td>
		<a href="/pastes/{$paste['index']}">{$paste['index']}</a>
	</td>
	<td>
		{$user}
	</td>
	<td>
		{$paste['created_at']}
	</td>
	<td>
		<form action="/super-manage/paste" method="post" class="form-horizontal">
		{$token}
		<input type="text" class="form-control" name="paste_deleter_name" id="input-paste_deleter_name" value="{$paste['index']}" style="display: none;">
		<button type="submit" name="submit-paste_deleter" value="paste_deleter" class="btn btn-sm btn-danger" style="margin: 0">删除</button>
		</form>
	</td>
</tr>
HTML;
		}, []);
}

function echoPasteContent($paste) {
	$zip_file = new ZipArchive();
	$submission_content = json_decode($paste['content'], true);
	$zip_file->open(UOJContext::storagePath().$submission_content['file_name']);

	$config = array();
	foreach ($submission_content['config'] as $config_key => $config_val) {
		$config[$config_val[0]] = $config_val[1];
	}

	$file_content = $zip_file->getFromName("paste.code");
	$file_content = uojTextEncode($file_content, array('allow_CR' => true, 'html_escape' => true));
	$file_language = htmlspecialchars($config["paste_language"]);
	$footer_text = UOJLocale::get('problems::source code').', '.UOJLocale::get('problems::language').': '.$file_language;
	$footer_text .= ", ".UOJLocale::get("problems::submitter") . <<<HTML
: <a href="/user/profile/${paste['creator']}">${paste['creator']}</a>
HTML;
	$footer_text .= ", ".UOJLocale::get("problems::submit time").": ".$paste['created_at'];

	switch ($file_language) {
		case 'C++':
		case 'C++98':
		case 'C++03':
		case 'C++11':
		case 'C++14':
		case 'C++17':
		case 'C++20':
		case 'C++23':
		case 'C++26':
			$sh_class = 'sh_cpp';
			break;
		case 'Python2':
		case 'Python3':
			$sh_class = 'sh_python';
			break;
		case 'Java8':
		case 'Java11':
		case 'Java17':
		case 'Java21':
			$sh_class = 'sh_java';
			break;
		case 'C':
		case 'C89':
		case 'C99':
		case 'C11':
		case 'C17':
		case 'C23':
			$sh_class = 'sh_c';
			break;
		case 'Pascal':
			$sh_class = 'sh_pascal';
			break;
		default:
			$sh_class = '';
			break;
	}
	echo '<div class="card border-info mb-3">';
	echo '<div class="card-header bg-info">';
	echo '<h4 class="card-title">Paste!</h4>';
	echo '</div>';
	echo '<div class="card-body">';
	echo '<pre><code class="'.$sh_class.'">'.$file_content."\n".'</code></pre>';
	echo '</div>';
	echo '<div class="card-footer">'.$footer_text.'</div>';
	echo '</div>';

	$zip_file->close();
}

function echoSubmissionContent($submission, $requirement) {
	$zip_file = new ZipArchive();
	$submission_content = json_decode($submission['content'], true);
	$zip_path = UOJContext::storagePath().$submission_content['file_name'];
	$open_res = $zip_file->open($zip_path);

	if ($open_res !== true) {
		echo '<div class="card border-info mb-3">';
		echo '<div class="card-header bg-danger">';
		echo '<h4 class="card-title">Zip file open error</h4>';
		echo '</div>';
		echo '<div class="card-body">';
		echo '<pre>'."\n"."Open zip file `".HTML::escape($zip_path)."` failed: $open_res"."\n".'</pre>';
		echo '</div>';
		echo '</div>';
		return;
	}
	
	$config = array();
	foreach ($submission_content['config'] as $config_key => $config_val) {
		$config[$config_val[0]] = $config_val[1];
	}
	
	foreach ($requirement as $req) {
		if ($req['type'] == "source code") {
			$file_content = $zip_file->getFromName("{$req['name']}.code");
			$file_content = uojTextEncode($file_content, array('allow_CR' => true, 'html_escape' => true));
			$file_language = htmlspecialchars($config["{$req['name']}_language"]);
			$footer_text = UOJLocale::get('problems::source code').', '.UOJLocale::get('problems::language').': '.$file_language;
			switch ($file_language) {
				case 'C++':
				case 'C++98':
				case 'C++03':
				case 'C++11':
				case 'C++14':
				case 'C++17':
				case 'C++20':
				case 'C++23':
				case 'C++26':
					$sh_class = 'sh_cpp';
					break;
				case 'Python2':
				case 'Python3':
					$sh_class = 'sh_python';
					break;
				case 'Java8':
				case 'Java11':
				case 'Java17':
				case 'Java21':
					$sh_class = 'sh_java';
					break;
				case 'C':
				case 'C89':
				case 'C99':
				case 'C11':
				case 'C17':
				case 'C23':
					$sh_class = 'sh_c';
					break;
				case 'Pascal':
					$sh_class = 'sh_pascal';
					break;
				default:
					$sh_class = '';
					break;
			}
			echo '<div class="card border-info mb-3">';
			echo '<div class="card-header bg-info">';
			echo '<h4 class="card-title">'.$req['name'].'</h4>';
			echo '</div>';
			echo '<div class="card-body">';
			echo '<pre><code class="'.$sh_class.'">'.$file_content."\n".'</code></pre>';
			echo '</div>';
			echo '<div class="card-footer">'.$footer_text.'</div>';
			echo '</div>';
		} elseif ($req['type'] == "text") {
			$file_content = $zip_file->getFromName("{$req['file_name']}", 504);
			$file_content = strOmit($file_content, 500);
			$file_content = uojTextEncode($file_content, array('allow_CR' => true, 'html_escape' => true));
			$footer_text = UOJLocale::get('problems::text file');
			echo '<div class="card border-info mb-3">';
			echo '<div class="card-header bg-info">';
			echo '<h4 class="card-title">'.$req['file_name'].'</h4>';
			echo '</div>';
			echo '<div class="card-body">';
			echo '<pre>'."\n".$file_content."\n".'</pre>';
			echo '</div>';
			echo '<div class="card-footer">'.$footer_text.'</div>';
			echo '</div>';
		}
	}

	$zip_file->close();
}


class JudgementDetailsPrinter {
	private $name;
	private $styler;
	private $dom;
	
	private $subtask_num;

	private function _print_c($node) {
		foreach ($node->childNodes as $child) {
			if ($child->nodeName == '#text') {
				echo htmlspecialchars($child->nodeValue);
			} else {
				$this->_print($child);
			}
		}
	}
	private function _print($node) {
		if ($node->nodeName == 'error') {
			echo "<pre>\n";
			$this->_print_c($node);
			echo "\n</pre>";
		} elseif ($node->nodeName == 'tests') {
			echo '<div id="', $this->name, '_details_accordion">';
			if ($this->styler->show_small_tip) {
				echo '<div class="text-right text-muted">', '小提示：点击横条可展开更详细的信息', '</div>';
			}
			$this->_print_c($node);
			echo '</div>';
		} elseif ($node->nodeName == 'subtask') {
			$subtask_num = $node->getAttribute('num');
			$subtask_score = $node->getAttribute('score');
			$subtask_info = $node->getAttribute('info');
			
			echo '<div class="card ', $this->styler->getTestInfoClass($subtask_info), ' mb-3">';
			
			$accordion_parent = "{$this->name}_details_accordion";
			$accordion_collapse =  "{$accordion_parent}_collapse_subtask_{$subtask_num}";
			$accordion_collapse_accordion =  "{$accordion_collapse}_accordion";
			echo 	'<div class="card-header" data-toggle="collapse" data-parent="#', $accordion_parent, '" data-target="#', $accordion_collapse, '">';
			
			echo 		'<div class="row">';
			echo 			'<div class="col-sm-2">';
			echo 				'<h3 class="card-title">', 'Subtask #', $subtask_num, ': ', '</h3>';
			echo 			'</div>';
			
			if ($this->styler->show_score) {
				echo 		'<div class="col-sm-2">';
				echo 			'score: ', $subtask_score;
				echo 		'</div>';
				echo 		'<div class="col-sm-2">';
				echo 			verdictHTML($subtask_info);
				echo 		'</div>';
			} else {
				echo 		'<div class="col-sm-4">';
				echo 			verdictHTML($subtask_info);
				echo 		'</div>';
			}

			echo 		'</div>';
			echo 	'</div>';
			
			echo 	'<div id="', $accordion_collapse, '" class="card-collapse collapse">';
			echo 		'<div class="card-body">';

			echo 			'<div id="', $accordion_collapse_accordion, '">';
			$this->subtask_num = $subtask_num;
			$this->_print_c($node);
			$this->subtask_num = null;
			echo 			'</div>';

			echo 		'</div>';
			echo 	'</div>';
			echo '</div>';
		} elseif ($node->nodeName == 'test') {
			$test_info = $node->getAttribute('info');
			$test_num = $node->getAttribute('num');
			$test_score = $node->getAttribute('score');
			$test_time = $node->getAttribute('time');
			$test_memory = $node->getAttribute('memory');

			echo '<div class="card ', $this->styler->getTestInfoClass($test_info), ' mb-3">';
			
			$accordion_parent = "{$this->name}_details_accordion";
			if ($this->subtask_num != null) {
				$accordion_parent .= "_collapse_subtask_{$this->subtask_num}_accordion";
			}
			$accordion_collapse = "{$accordion_parent}_collapse_test_{$test_num}";
			if (!$this->styler->shouldFadeDetails($test_info)) {
				echo '<div class="card-header" data-toggle="collapse" data-parent="#', $accordion_parent, '" data-target="#', $accordion_collapse, '">';
			} else {
				echo '<div class="card-header">';
			}
			echo '<div class="row">';
			echo '<div class="col-sm-2">';
			if ($test_num > 0) {
				echo '<h4 class="card-title">', 'Test #', $test_num, ': ', '</h4>';
			} else {
				echo '<h4 class="card-title">', 'Extra Test:', '</h4>';
			}
			echo '</div>';
				
			if ($this->styler->show_score) {
				echo '<div class="col-sm-2">';
				echo 'score: ', $test_score;
				echo '</div>';
				echo '<div class="col-sm-2">';
				echo verdictHTML($test_info);
				echo '</div>';
			} else {
				echo '<div class="col-sm-4">';
				echo verdictHTML($test_info);
				echo '</div>';
			}
				
			echo '<div class="col-sm-3">';
			if ($test_time >= 0) {
				echo 'time: ', $test_time, 'ms';
			}
			echo '</div>';

			echo '<div class="col-sm-3">';
			if ($test_memory >= 0) {
				echo 'memory: ', $test_memory, 'kb';
			}
			echo '</div>';

			echo '</div>';
			echo '</div>';

			if (!$this->styler->shouldFadeDetails($test_info)) {
				$accordion_collapse_class = 'card-collapse collapse';
				if ($this->styler->collapse_in) {
					$accordion_collapse_class .= ' in';
				}
				echo '<div id="', $accordion_collapse, '" class="', $accordion_collapse_class, '">';
				echo '<div class="card-body">';

				$this->_print_c($node);

				echo '</div>';
				echo '</div>';
			}

			echo '</div>';
		} elseif ($node->nodeName == 'custom-test') {
			$test_info = $node->getAttribute('info');
			$test_time = $node->getAttribute('time');
			$test_memory = $node->getAttribute('memory');

			echo '<div class="card ', $this->styler->getTestInfoClass($test_info), ' mb-3">';
			
			$accordion_parent = "{$this->name}_details_accordion";
			$accordion_collapse = "{$accordion_parent}_collapse_custom_test";
			if (!$this->styler->shouldFadeDetails($test_info)) {
				echo '<div class="card-header" data-toggle="collapse" data-parent="#', $accordion_parent, '" data-target="#', $accordion_collapse, '">';
			} else {
				echo '<div class="card-header">';
			}
			echo '<div class="row">';
			echo '<div class="col-sm-2">';
			echo '<h4 class="card-title">', 'Custom Test: ', '</h4>';
			echo '</div>';
				
			echo '<div class="col-sm-4">';
			echo htmlspecialchars($test_info);
			echo '</div>';
				
			echo '<div class="col-sm-3">';
			if ($test_time >= 0) {
				echo 'time: ', $test_time, 'ms';
			}
			echo '</div>';

			echo '<div class="col-sm-3">';
			if ($test_memory >= 0) {
				echo 'memory: ', $test_memory, 'kb';
			}
			echo '</div>';

			echo '</div>';
			echo '</div>';

			if (!$this->styler->shouldFadeDetails($test_info)) {
				$accordion_collapse_class = 'card-collapse collapse';
				if ($this->styler->collapse_in) {
					$accordion_collapse_class .= ' in';
				}
				echo '<div id="', $accordion_collapse, '" class="', $accordion_collapse_class, '">';
				echo '<div class="card-body">';

				$this->_print_c($node);

				echo '</div>';
				echo '</div>';

				echo '</div>';
			}
		} elseif ($node->nodeName == 'in') {
			echo "<h4>input:</h4><pre>\n";
			$this->_print_c($node);
			echo "\n</pre>";
		} elseif ($node->nodeName == 'out') {
			echo "<h4>output:</h4><pre>\n";
			$this->_print_c($node);
			echo "\n</pre>";
		} elseif ($node->nodeName == 'res') {
			echo "<h4>result:</h4><pre>\n";
			$this->_print_c($node);
			echo "\n</pre>";
		} else {
			echo '<', $node->nodeName;
			foreach ($node->attributes as $attr) {
				echo ' ', $attr->name, '="', htmlspecialchars($attr->value), '"';
			}
			echo '>';
			$this->_print_c($node);
			echo '</', $node->nodeName, '>';
		}
	}

	public function __construct($details, $styler, $name) {
		$this->name = $name;
		$this->styler = $styler;
		$this->details = $details;
		$this->dom = new DOMDocument();
		if (!$this->dom->loadXML($this->details)) {
			throw new Exception("XML syntax error");
		}
		$this->details = '';
	}
	public function printHTML() {
		$this->subtask_num = null;
		$this->_print($this->dom->documentElement);
	}
}

function echoJudgementDetails($raw_details, $styler, $name) {
	try {
		$printer = new JudgementDetailsPrinter($raw_details, $styler, $name);
		$printer->printHTML();
	} catch (Exception $e) {
		echo 'Failed to show details';
	}
}

class SubmissionDetailsStyler {
	public $show_score = true;
	public $show_small_tip = true;
	public $collapse_in = false;
	public $fade_all_details = false;
	public function getTestInfoClass($info) {
		if ($info == 'Accepted' || $info == 'Extra Test Passed') {
			return 'card-uoj-accepted';
		} elseif ($info == 'Time Limit Exceeded') {
			return 'card-uoj-tle';
		} elseif ($info == 'Acceptable Answer') {
			return 'card-uoj-acceptable-answer';
		} else {
			return 'card-uoj-wrong';
		}
	}
	public function shouldFadeDetails($info) {
		return $this->fade_all_details || $info == 'Extra Test Passed';
	}
}
class CustomTestSubmissionDetailsStyler {
	public $show_score = true;
	public $show_small_tip = false;
	public $collapse_in = true;
	public $fade_all_details = false;
	public $ioi_contest_is_running = false;
	public function getTestInfoClass($info) {
		if ($info == 'Success') {
			return 'card-uoj-accepted';
		} elseif ($info == 'Time Limit Exceeded') {
			return 'card-uoj-tle';
		} elseif ($info == 'Acceptable Answer') {
			return 'card-uoj-acceptable-answer';
		} else {
			return 'card-uoj-wrong';
		}
	}
	public function shouldFadeDetails($info) {
		return $this->fade_all_details;
	}
}
class HackDetailsStyler {
	public $show_score = false;
	public $show_small_tip = false;
	public $collapse_in = true;
	public $fade_all_details = false;
	public function getTestInfoClass($info) {
		if ($info == 'Accepted' || $info == 'Extra Test Passed') {
			return 'card-uoj-accepted';
		} elseif ($info == 'Time Limit Exceeded') {
			return 'card-uoj-tle';
		} elseif ($info == 'Acceptable Answer') {
			return 'card-uoj-acceptable-answer';
		} else {
			return 'card-uoj-wrong';
		}
	}
	public function shouldFadeDetails($info) {
		return $this->fade_all_details;
	}
}

function echoSubmissionDetails($submission_details, $name) {
	echoJudgementDetails($submission_details, new SubmissionDetailsStyler(), $name);
}
function echoCustomTestSubmissionDetails($submission_details, $name) {
	echoJudgementDetails($submission_details, new CustomTestSubmissionDetailsStyler(), $name);
}
function echoHackDetails($hack_details, $name) {
	echoJudgementDetails($hack_details, new HackDetailsStyler(), $name);
}

function echoHack($hack, $config, $user) {
	$problem = queryProblemBrief($hack['problem_id']);
	echo '<tr>';
	if (!isset($config['id_hidden'])) {
		echo '<td><a href="/hack/', $hack['id'], '">#', $hack['id'], '</a></td>';
	}
	if (!isset($config['submission_hidden'])) {
		echo '<td><a href="/submission/', $hack['submission_id'], '">#', $hack['submission_id'], '</a></td>';
	}
	if (!isset($config['problem_hidden'])) {
		echo '<td>', getSubmissionProblemLink($problem, $hack['contest_id'], null), '</td>';
	}
	if (!isset($config['hacker_hidden'])) {
		echo '<td>', getUserLink($hack['hacker']), '</td>';
	}
	if (!isset($config['owner_hidden'])) {
		echo '<td>', getUserLink($hack['owner']), '</td>';
	}
	if (!isset($config['result_hidden'])) {
		if ($hack['judge_time'] == null) {
			echo '<td><a href="/hack/', $hack['id'], '">Waiting</a></td>';
		} elseif ($hack['success'] == null) {
			echo '<td><a href="/hack/', $hack['id'], '">Judging</a></td>';
		} elseif ($hack['success']) {
			echo '<td><a href="/hack/', $hack['id'], '" class="uoj-status" data-success="1"><strong>Success!</strong></a></td>';
		} else {
			echo '<td><a href="/hack/', $hack['id'], '" class="uoj-status" data-success="0"><strong>Failed.</strong></a></td>';
		}
	} else {
		echo '<td>Hidden</td>';
	}
	if (!isset($config['submit_time_hidden'])) {
		echo '<td>', $hack['submit_time'], '</td>';
	}
	if (!isset($config['judge_time_hidden'])) {
		echo '<td>', $hack['judge_time'], '</td>';
	}
	echo '</tr>';
}
function echoHackListOnlyOne($hack, $config, $user) {
	echo '<div class="table-responsive">';
	echo '<table class="table table-bordered table-text-center">';
	echo '<thead>';
	echo '<tr>';
	if (!isset($config['id_hidden'])) {
		echo '<th>ID</th>';
	}
	if (!isset($config['submission_id_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::submission id').'</th>';
	}
	if (!isset($config['problem_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::problem').'</th>';
	}
	if (!isset($config['hacker_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::hacker').'</th>';
	}
	if (!isset($config['owner_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::owner').'</th>';
	}
	if (!isset($config['result_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::result').'</th>';
	}
	if (!isset($config['submit_time_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::submit time').'</th>';
	}
	if (!isset($config['judge_time_hidden'])) {
		echo '<th>'.UOJLocale::get('problems::judge time').'</th>';
	}
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';
	echoHack($hack, $config, $user);
	echo '</tbody>';
	echo '</table>';
	echo '</div>';
}
function echoHacksList($cond, $tail, $config, $user) {
	$header_row = '<tr>';
	$col_names = array();
	
	$col_names[] = 'id';
	$col_names[] = 'success';
	$col_names[] = 'judge_time';
	
	if (!isset($config['id_hidden'])) {
		$header_row .= '<th>ID</th>';
	}
	if (!isset($config['submission_id_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::submission id').'</th>';
		$col_names[] = 'submission_id';
	}
	if (!isset($config['problem_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::problem').'</th>';
		$col_names[] = 'problem_id';
	}
	if (!isset($config['hacker_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::hacker').'</th>';
		$col_names[] = 'hacker';
	}
	if (!isset($config['owner_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::owner').'</th>';
		$col_names[] = 'owner';
	}
	if (!isset($config['result_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::result').'</th>';
	}
	if (!isset($config['submit_time_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::submit time').'</th>';
		$col_names[] = 'submit_time';
	}
	if (!isset($config['judge_time_hidden'])) {
		$header_row .= '<th>'.UOJLocale::get('problems::judge time').'</th>';
	}
	$header_row .= '</tr>';

	$permission_cond = visibleHacksCond($user);
	if ($permission_cond !== '1') {
		$cond = $cond !== '1' ? "($cond) and ($permission_cond)" : $permission_cond;
	}

	echoLongTable($col_names, 'hacks', $cond, $tail, $header_row,
		function($hacks) use ($config, $user) {
			echoHack($hacks, $config, $user);
		}, null);
}

function echoBlog($blog, $config = array()) {
	$default_config = array(
		'blog' => $blog,
		'show_title_only' => false,
		'is_preview' => false
	);
	foreach ($default_config as $key => $val) {
		if (!isset($config[$key])) {
			$config[$key] = $val;
		}
	}
	uojIncludeView('blog-preview', $config);
}
function echoBlogTag($tag) {
	echo '<a class="uoj-blog-tag"><span class="badge badge-pill badge-secondary">', HTML::escape($tag), '</span></a>';
}

function echoUOJPageHeader($page_title, $extra_config = array()) {
	global $REQUIRE_LIB;
	$config = UOJContext::pageConfig();
	$config['REQUIRE_LIB'] = $REQUIRE_LIB;
	$config['PageTitle'] = $page_title;
	$config = array_merge($config, $extra_config);
	uojIncludeView('page-header', $config);
}
function echoUOJPageFooter($config = array()) {
	uojIncludeView('page-footer', $config);
}

function echoRanklist($config = array()) {
	$header_row = '';
	$header_row .= '<tr>';
	$header_row .= '<th style="width: 5em;">#</th>';
	$header_row .= '<th style="width: 14em;">'.UOJLocale::get('username').'</th>';
	$header_row .= '<th style="width: 50em;">'.UOJLocale::get('motto').'</th>';
	$header_row .= '<th style="width: 5em;">'.UOJLocale::get('rating').'</th>';
	$header_row .= '</tr>';
	
	$users = array();
	$print_row = function($user, $now_cnt) use (&$users) {
		if (!$users) {
			$rank = DB::selectCount("select count(*) from user_info where rating > {$user['rating']}") + 1;
		} elseif ($user['rating'] == $users[count($users) - 1]['rating']) {
			$rank = $users[count($users) - 1]['rank'];
		} else {
			$rank = $now_cnt;
		}
		
		$user['rank'] = $rank;
		
		echo '<tr>';
		echo '<td>' . $user['rank'] . '</td>';
		echo '<td>' . getUserLink($user['username']) . '</td>';
		echo '<td>' . HTML::escape($user['motto']) . '</td>';
		echo '<td>' . $user['rating'] . '</td>';
		echo '</tr>';
		
		$users[] = $user;
	};
	$col_names = array('username', 'rating', 'motto');
	$tail = 'order by rating desc, username asc';
	
	if (isset($config['top10'])) {
		$tail .= ' limit 10';
	}
	
	$config['get_row_index'] = '';
	echoLongTable($col_names, 'user_info', '1', $tail, $header_row, $print_row, $config);
}
