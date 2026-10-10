<?php
	requirePHPLib('form');
	
	if (!validateUInt($_GET['id']) || !($contest = queryContest($_GET['id']))) {
		become404Page();
	}
	genMoreContestInfo($contest);
	// a contest that is over shows its problems, if it was told to: also where nothing runs
	// by itself every minute
	contestRevealProblems($contest);
	// a contest of a domain exists for the members of the domain
	if (!can($myUser, 'contest.view', $contest)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}

	if (!can($myUser, 'contest.assist', $contest)) {
		if ($contest['cur_progress'] == CONTEST_NOT_STARTED) {
			header("Location: /contest/{$contest['id']}/register");
			die();
		} elseif ($contest['cur_progress'] == CONTEST_IN_PROGRESS) {
			if ($myUser == null || !hasRegistered(Auth::user(), $contest)) {
				becomeMsgPage($contest['join_mode'] === 'open' ? "<h1>比赛正在进行中</h1><p>很遗憾，您尚未报名。比赛结束后再来看吧～</p>" : "<h1>比赛正在进行中</h1><p>很遗憾，您尚未报名。这场比赛只对报名参加的选手开放。</p>");
			}
		} elseif (!can($myUser, 'contest.enter', $contest)) {
			// over, and not for everybody
			becomeMsgPage('<h1 id="contest-closed">这场比赛不对所有人开放</h1><p>它的题目、榜单和提交只有报名参加的选手能看到。</p>');
		}
	}
	
	if (isset($_GET['tab'])) {
		$cur_tab = $_GET['tab'];
	} else {
		$cur_tab = 'dashboard';
	}
	
	$tabs_info = array(
		'dashboard' => array(
			'name' => UOJLocale::get('contests::contest dashboard'),
			'url' => "/contest/{$contest['id']}"
		),
		'submissions' => array(
			'name' => UOJLocale::get('contests::contest submissions'),
			'url' => "/contest/{$contest['id']}/submissions"
		),
		'standings' => array(
			'name' => UOJLocale::get('contests::contest standings'),
			'url' => "/contest/{$contest['id']}/standings"
		)
	);
	
	if (can($myUser, 'contest.assist', $contest)) {
		$tabs_info['backstage'] = array(
			'name' => UOJLocale::get('contests::contest backstage'),
			'url' => "/contest/{$contest['id']}/backstage"
		);
	}
	
	if (!isset($tabs_info[$cur_tab])) {
		become404Page();
	}
	
	if (isset($_POST['check_notice'])) {
		$result = DB::query("select * from contests_notice where contest_id = '${contest['id']}' order by time desc limit 10");
		$ch = array();
		$flag = false;
		try {
			while ($row = DB::fetch($result)) {
				if (new DateTime($row['time']) > new DateTime($_POST['last_time'])) {
					$ch[] = $row['title'].': '.$row['content'];
				}
			}
		} catch (Exception $e) {
		}
		global $myUser;
		$result=DB::query("select * from contests_asks where contest_id='${contest['id']}' and username='${myUser['username']}' order by reply_time desc limit 10");
		try {
			while ($row = DB::fetch($result)) {
				if (new DateTime($row['reply_time']) > new DateTime($_POST['last_time'])) {
					$ch[] = $row['question'].': '.$row['answer'];
				}
			}
		} catch (Exception $e) {
		}
		if ($ch) {
			die(json_encode(array('msg' => $ch, 'time' => UOJTime::$time_now_str)));
		} else {
			die(json_encode(array('time' => UOJTime::$time_now_str)));
		}
	}
	
	if (can($myUser, 'contest.manage', $contest)) {
		// A contest that judged with the samples while it ran has a final test with all the
		// data before its results are published: an OI contest, and any contest that has
		// such submissions from when it was one. A contest that judged with everything has
		// nothing left to judge: its results are published as they are.
		$needs_final_test = $contest['cur_progress'] == CONTEST_PENDING_FINAL_TEST && (contestRule($contest) === 'OI'
			|| DB::selectFirst("select 1 from submissions where contest_id = {$contest['id']} and content like '%\"final\\_test\\_config\"%' limit 1") != null);
		if ($needs_final_test || $contest['cur_progress'] == CONTEST_TESTING) {
			$start_test_form = new UOJForm('start_test');
			$start_test_form->handle = function() {
				global $contest;
				// starting it again judges everything again, for the data that was corrected
				$again = $contest['cur_progress'] == CONTEST_TESTING;
				$result = DB::query("select id, problem_id, content from submissions where contest_id = {$contest['id']}");
				while ($submission = DB::fetch($result, MYSQLI_ASSOC)) {
					$content = json_decode($submission['content'], true);
					if (isset($content['final_test_config']) || ($again && !isset($contest['extra_config']["problem_{$submission['problem_id']}"]))) {
						if (isset($content['final_test_config'])) {
							$content['config'] = $content['final_test_config'];
							unset($content['final_test_config']);
						}
						if (isset($content['first_test_config'])) {
							unset($content['first_test_config']);
						}
						$esc_content = DB::escape(json_encode($content));
						DB::update("update submissions set judge_time = NULL, result = '', score = NULL, status = 'Waiting Rejudge', content = '$esc_content' where id = {$submission['id']}");
					}
				}
				DB::query("update contests set status = 'testing' where id = {$contest['id']}");
				auditLog('contest.start_final_test', 'contest', $contest['id']);
			};
			$start_test_form->submit_button_config['class_str'] = 'btn btn-danger btn-block';
			$start_test_form->submit_button_config['smart_confirm'] = '';
			if ($contest['cur_progress'] < CONTEST_TESTING) {
				$start_test_form->submit_button_config['text'] = '开始最终测试';
			} else {
				$start_test_form->submit_button_config['text'] = '重新开始最终测试';
			}

			$start_test_form->runAtServer();
		}
		if ($contest['cur_progress'] >= CONTEST_TESTING || ($contest['cur_progress'] == CONTEST_PENDING_FINAL_TEST && !$needs_final_test)) {
			$publish_result_form = new UOJForm('publish_result');
			$publish_result_form->handle = function() {
				// time config
				set_time_limit(0);
				ignore_user_abort(true);

				global $contest;
				// what is not judged yet would count for nothing
				$unjudged = DB::selectCount("select count(*) from submissions where contest_id = {$contest['id']} and status != 'Judged'");
				if ($unjudged > 0) {
					becomeMsgPage("<p>还有 $unjudged 个提交没有评测完，现在公布的话它们不会计入成绩。请等评测结束后再公布。</p><p><a href=\"/contest/{$contest['id']}\">返回</a></p>");
				}
				$contest_data = queryContestData($contest);
				calcStandings($contest, $contest_data, $score, $standings, true);
				$rated = contestIsRated($contest);
				if ($rated) {
					$rating_k = isset($contest['extra_config']['rating_k']) ? $contest['extra_config']['rating_k'] : 400;
					$ratings = calcRating($standings, $rating_k);
				} else {
					$ratings = array();
					for ($i = 0; $i < count($standings); $i++) {
						$ratings[$i] = $standings[$i][2][1];
					}
				}

				for ($i = 0; $i < count($standings); $i++) {
					$user = queryUser($standings[$i][2][0]);
					if (!$rated) {
						// the rating somebody has now, not the one they registered with
						$ratings[$i] = $user['rating'];
					}
					$change = $ratings[$i] - $user['rating'];
					$user_link = getUserLink($user['username']);

					if ($change != 0) {
						$tail = '<strong style="color:red">' . ($change > 0 ? '+' : '') . $change . '</strong>';
						$content = <<<EOD
<p>${user_link} 您好：</p>
<p class="indent2">您在 <a href="/contest/{$contest['id']}">{$contest['name']}</a> 这场比赛后的Rating变化为${tail}，当前Rating为 <strong style="color:red">{$ratings[$i]}</strong>。</p>
EOD;
					} else {
						$content = <<<EOD
<p>${user_link} 您好：</p>
<p class="indent2">您在 <a href="/contest/{$contest['id']}">{$contest['name']}</a> 这场比赛后Rating没有变化。当前Rating为 <strong style="color:red">{$ratings[$i]}</strong>。</p>
EOD;
					}
					// a contest of a domain has nothing to say about ratings
					if (empty($contest['domain_id'])) {
						sendSystemMsg($user['username'], 'Rating变化通知', $content);
					}
					if ($rated) {
						DB::query("update user_info set rating = {$ratings[$i]} where username = '{$standings[$i][2][0]}'");
					}
					DB::query("update contests_registrants set rank = {$standings[$i][3]} where contest_id = {$contest['id']} and username = '{$standings[$i][2][0]}'");
				}
				DB::query("update contests set status = 'finished' where id = {$contest['id']}");
				auditLog('contest.publish_results', 'contest', $contest['id'], null, array('rated' => $rated, 'participants' => count($standings)));
				// a contest whose board froze shows its problems now, if it was told to show them
				$finished = queryContest($contest['id']);
				genMoreContestInfo($finished);
				contestRevealProblems($finished);
			};
			$publish_result_form->submit_button_config['class_str'] = 'btn btn-danger btn-block';
			$publish_result_form->submit_button_config['smart_confirm'] = '';
			$publish_result_form->submit_button_config['text'] = '公布成绩';
			
			$publish_result_form->runAtServer();
		}
	}
	
	if ($cur_tab == 'dashboard') {
		if ($contest['cur_progress'] <= CONTEST_IN_PROGRESS) {
			$post_question = new UOJForm('post_question');
			$post_question->addVTextArea('qcontent', '问题', '', 
				function($content) {
					if (!Auth::check()) {
						return '您尚未登录';
					}
					if (!$content || strlen($content) == 0) {
						return '问题不能为空';
					}
					if (strlen($content) > 140 * 4) {
						return '问题太长';
					}
					return '';
				},
				null
			);
			$post_question->handle = function() {
				global $contest;
				$content = DB::escape($_POST['qcontent']);
				$username = Auth::id();
				DB::query("insert into contests_asks (contest_id, question, username, post_time, is_hidden) values ('{$contest['id']}', '$content', '$username', now(), 1)");
			};
			$post_question->runAtServer();
		} else {
			$post_question = null;
		}
	} elseif ($cur_tab == 'backstage') {
		if (can($myUser, 'contest.manage', $contest)) {
			$post_notice = new UOJForm('post_notice');
			$post_notice->addInput('title', 'text', '标题', '',
				function($title) {
					if (!$title) {
						return '标题不能为空';
					}
					return '';
				},
				null
			);
			$post_notice->addTextArea('content', '正文', '', 
				function($content) {
					if (!$content) {
						return '公告不能为空';
					}
					return '';
				},
				null
			);
			$post_notice->handle = function() {
				global $contest;
				$title = DB::escape($_POST['title']);
				$content = DB::escape($_POST['content']);
				DB::insert("insert into contests_notice (contest_id, title, content, time) values ('{$contest['id']}', '$title', '$content', now())");
			};
			$post_notice->runAtServer();
		} else {
			$post_notice = null;
		}
		
		if (can($myUser, 'contest.assist', $contest)) {
			$reply_question = new UOJForm('reply_question');
			$reply_question->addHidden('rid', '0',
				function($id) {
					global $contest;
				    
					if (!validateUInt($id)) {
						return '无效ID';
					}
					$q = DB::selectFirst("select * from contests_asks where id = $id");
					if ($q['contest_id'] != $contest['id']) {
						return '无效ID';
					}
					return '';
				},
				null
			);
			$reply_question->addVSelect('rtype', [
				'public' => '公开',
				'private' => '非公开',
				'statement' => '请仔细阅读题面（非公开）',
				'no_comment' => '无可奉告（非公开）',
				'no_play' => '请认真比赛（非公开）',
			], '回复类型', 'private');
			$reply_question->addVTextArea('rcontent', '回复', '', 
				function($content) {
					if (!Auth::check()) {
						return '您尚未登录';
					}
					switch ($_POST['rtype']) {
				    	case 'public':
				    	case 'private':
				    		if (strlen($content) == 0) {
				    			return '回复不能为空';
				    		}
							break;
				    }
					return '';
				},
				null
			);
			$reply_question->handle = function() {
				global $contest;
				$content = DB::escape($_POST['rcontent']);
				$is_hidden = 1;
				switch ($_POST['rtype']) {
					case 'statement':
						$content = '请仔细阅读题面';
						break;
					case 'no_comment':
						$content = '无可奉告 ╮(╯▽╰)╭ ';
						break;
					case 'no_play':
						$content = '请认真比赛 (￣口￣)!!';
						break;
					case 'public':
						$is_hidden = 0;
						break;
					default:
						break;
				}
				DB::update("update contests_asks set answer = '$content', reply_time = now(), is_hidden = {$is_hidden} where id = {$_POST['rid']}");
			};
			$reply_question->runAtServer();
		} else {
			$reply_question = null;
		}
	}
	
	function echoDashboard() {
		global $contest, $post_notice, $post_question, $reply_question;
		
		$myname = Auth::id();
		$contest_problems = DB::selectAll("select contests_problems.problem_id, best_ac_submissions.submission_id from contests_problems left join best_ac_submissions on contests_problems.problem_id = best_ac_submissions.problem_id and submitter = '{$myname}' where contest_id = {$contest['id']} order by contests_problems.position, contests_problems.problem_id");
		
		for ($i = 0; $i < count($contest_problems); $i++) {
			$contest_problems[$i]['problem'] = queryProblemBrief($contest_problems[$i]['problem_id']);
		}
		
		$contest_notice = DB::selectAll("select * from contests_notice where contest_id = {$contest['id']} order by time desc");
		
		if (Auth::check()) {
			$my_questions = DB::selectAll("select * from contests_asks where contest_id = {$contest['id']} and username = '{$myname}' order by post_time desc");
			$my_questions_pag = new Paginator([
				'data' => $my_questions
			]);
		} else {
			$my_questions_pag = null;
		}
		
		$others_questions_pag = new Paginator([
			'col_names' => array('*'),
			'table_name' => 'contests_asks',
			'cond' => "contest_id = {$contest['id']} and username != '{$myname}' and is_hidden = 0",
			'tail' => 'order by reply_time desc',
			'page_len' => 10
		]);
		
		uojIncludeView('contest-dashboard', [
			'contest' => $contest,
			// what comes with the contest, for the people who are inside it
			'attachments' => can(Auth::user(), 'contest.read', $contest) ? attachmentsOf('contest', $contest['id']) : array(),
			'contest_notice' => $contest_notice,
			'contest_problems' => $contest_problems,
			'post_question' => $post_question,
			'my_questions_pag' => $my_questions_pag,
			'others_questions_pag' => $others_questions_pag
		]);
	}
	
	function echoBackstage() {
		global $contest, $post_notice, $reply_question;
		
		$questions_pag = new Paginator([
			'col_names' => array('*'),
			'table_name' => 'contests_asks',
			'cond' => "contest_id = {$contest['id']}",
			'tail' => 'order by post_time desc',
			'page_len' => 50
		]);
		
		if ($contest['cur_progress'] < CONTEST_TESTING) {
			$contest_data = queryContestData($contest, ['pre_final' => true]);
			calcStandings($contest, $contest_data, $score, $standings);
			
			$standings_data = [
				'contest' => $contest,
				'standings' => $standings,
				'score' => $score,
				'contest_data' => $contest_data
			];
		} else {
			$standings_data = null;
		}
		
		uojIncludeView('contest-backstage', [
			'contest' => $contest,
			'post_notice' => $post_notice,
			'reply_question' => $reply_question,
			'questions_pag' => $questions_pag,
			'standings_data' => $standings_data
		]);
	}
	
	function echoMySubmissions() {
		global $contest, $myUser;

		// Whose submissions: one's own, or everybody's. The people who run the contest have
		// none of their own and are there to look at everybody's, so that is what they are
		// shown unless they say otherwise; everybody else is shown their own unless they ask.
		$choice = Cookie::get('show_all_submissions');
		$show_all = $choice === null ? can($myUser, 'contest.assist', $contest) : $choice !== '0';
		$show_all_submissions_status = $show_all ? 'checked="checked" ' : '';
		$show_all_submissions = UOJLocale::get('contests::show all submissions');
		echo <<<EOD
			<div class="checkbox text-right">
				<label for="input-show_all_submissions"><input type="checkbox" id="input-show_all_submissions" $show_all_submissions_status/> $show_all_submissions</label>
			</div>
			<script type="text/javascript">
				$('#input-show_all_submissions').click(function() {
					$.cookie('show_all_submissions', this.checked ? '' : '0');
					location.reload();
				});
			</script>
EOD;
		if ($show_all) {
			echoSubmissionsList("contest_id = {$contest['id']}", 'order by id desc', array('judge_time_hidden' => '', 'inside' => ''), $myUser);
		} else {
			echoSubmissionsList("submitter = '{$myUser['username']}' and contest_id = {$contest['id']}", 'order by id desc', array('judge_time_hidden' => '', 'inside' => ''), $myUser);
		}
	}
	
	function echoStandings() {
		global $contest;
		
		// While the board is frozen everybody sees it as it was when it froze. The staff sees
		// everything, and can ask for what the others see.
		$is_staff = can(Auth::user(), 'contest.assist', $contest);
		$board_is_frozen = contestBoardIsFrozen($contest);
		$frozen = $board_is_frozen && (!$is_staff || isset($_GET['frozen']));
		$contest_data = queryContestData($contest);
		calcStandings($contest, $contest_data, $score, $standings, false, $frozen ? contestFreezeOffset($contest) : null);
		if ($board_is_frozen) {
			$since = virtualClock(contestFreezeOffset($contest));
			echo '<div class="alert alert-info" id="standings-frozen">';
			if ($frozen) {
				echo "榜单已封榜：这是比赛开始后 $since 时的榜单，此后的提交只显示次数，公布成绩时揭晓。";
				if ($is_staff) {
					echo ' <a class="alert-link" href="/contest/', $contest['id'], '/standings">看完整的榜单</a>';
				}
			} else {
				echo "选手看到的榜单从比赛开始后 $since 起封榜，公布成绩时揭晓。这是只有工作人员能看到的完整榜单。";
				echo ' <a class="alert-link" href="/contest/', $contest['id'], '/standings?frozen=1">看选手看到的榜单</a>';
			}
			echo '</div>';
		}
		
		if ($contest['cur_progress'] >= CONTEST_FINISHED && can(Auth::user(), 'contest.assist', $contest)) {
			echo <<<EOD
				<div>
					<a class="btn btn-info" href="/contest/{$contest['id']}/export_standings">下载排名</a>
				</div>
			EOD;
		}
		
		// A contestant who looks at the frozen board is shown, above it, how it really stands
		// with them: one row, which is theirs alone.
		$mine = null;
		if ($frozen && !$is_staff && Auth::check()) {
			calcStandings($contest, $contest_data, $true_score, $true_standings);
			foreach ($true_standings as $row) {
				if ($row[2][0] === Auth::id()) {
					$mine = array('row' => $row, 'cells' => $true_score[Auth::id()]);
				}
			}
		}
		
		uojIncludeView(contestRule($contest) === 'ICPC' ? 'contest-standings-icpc' : 'contest-standings', [
			'contest' => $contest,
			'standings' => $standings,
			'score' => $score,
			'contest_data' => $contest_data,
			'frozen' => $frozen,
			'mine' => $mine
		]);
	}
	
	function echoContestCountdown() {
		global $contest;
		$rest_second = $contest['end_time']->getTimestamp() - UOJTime::$time_now->getTimestamp();
		$time_str = UOJTime::$time_now_str;
		$contest_ends_in = UOJLocale::get('contests::contest ends in');
		echo <<<EOD
 		<div class="card border-info">
 			<div class="card-header bg-info">
 				<h3 class="card-title">$contest_ends_in</h3>
 			</div>
 			<div class="card-body text-center countdown" data-rest="$rest_second"></div>
 		</div>
		<script type="text/javascript">
			checkContestNotice({$contest['id']}, '$time_str');
		</script>
EOD;
	}
	
	function echoContestJudgeProgress() {
		global $contest;
		if ($contest['cur_progress'] < CONTEST_TESTING) {
			$rop = 0;
			// only a contest that judged with the samples has a final test to wait for
			$title = contestRule($contest) === 'OI' ? UOJLocale::get('contests::contest pending final test') : '比赛已结束，等待公布成绩';
		} else {
			$total = DB::selectCount("select count(*) from submissions where contest_id = {$contest['id']}");
			$n_judged = DB::selectCount("select count(*) from submissions where contest_id = {$contest['id']} and status = 'Judged'");
			$rop = $total == 0 ? 100 : (int)($n_judged / $total * 100);
			$title = UOJLocale::get('contests::contest final testing');
		}
		echo <<<EOD
 		<div class="card border-info">
 			<div class="card-header bg-info">
 				<h3 class="card-title">$title</h3>
 			</div>
 			<div class="card-body">
				<div class="progress bot-buffer-no">
					<div class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="$rop" aria-valuemin="0" aria-valuemax="100" style="width: {$rop}%; min-width: 20px;">{$rop}%</div>
				</div>
			</div>
 		</div>
EOD;
	}
	
	function echoContestFinished() {
		global $contest, $myUser;
		$title = UOJLocale::get('contests::contest ended');
		echo <<<EOD
 		<div class="card border-info">
 			<div class="card-header bg-info">
 				<h3 class="card-title">$title</h3>
 			</div>
 		</div>
EOD;
		// the contest can be sat again, alone and against the clock
		if (can($myUser, 'contest.virtual', $contest)) {
			echo '<a class="btn btn-outline-primary btn-block top-buffer-md" id="link-virtual" href="/contest/', $contest['id'], '/virtual">虚拟参赛</a>';
		}
	}
	
	$page_header = HTML::stripTags($contest['name']) . ' - ';
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - ' . $tabs_info[$cur_tab]['name'] . ' - ' . UOJLocale::get('contests::contest')) ?>
<?php echoContestDomainLink($contest) ?>
<div class="text-center">
	<h1><?= $contest['name'] ?></h1>
	<?= getClickZanBlock('C', $contest['id'], $contest['zan']) ?>
</div>
<div class="row">
	<?php if ($cur_tab == 'standings'): ?>
	<div class="col-sm-12">
	<?php else: ?>
	<div class="col-sm-9">
	<?php endif ?>
		<?= HTML::tablist($tabs_info, $cur_tab) ?>
		<div class="top-buffer-md">
		<?php
			if ($cur_tab == 'dashboard') {
				echoDashboard();
			} elseif ($cur_tab == 'submissions') {
				echoMySubmissions();
			} elseif ($cur_tab == 'standings') {
				echoStandings();
			} elseif ($cur_tab == 'backstage') {
				echoBackstage();
			}
		?>
		</div>
	</div>
	
	<?php if ($cur_tab == 'standings'): ?>
	<div class="col-sm-12">
		<hr />
	</div>
	<?php endif ?>

	<div class="col-sm-3">
		<?php
			if ($contest['cur_progress'] <= CONTEST_IN_PROGRESS) {
				echoContestCountdown();
			} elseif ($contest['cur_progress'] <= CONTEST_TESTING) {
				echoContestJudgeProgress();
			} else {
				echoContestFinished();
			}
		?>
		<?php if ($cur_tab == 'standings'): ?>
	</div>
	<div class="col-sm-3">
	<?php endif ?>
	<?php $contest_rule = contestRule($contest); ?>
	<div id="contest-rule" data-rule="<?= $contest_rule ?>" class="text-left mb-2">
		<h4 class="mt-2 mb-2">赛制：<?= $contest_rule ?></h4>
		<ul class="list-group">
			<?php foreach (contestRuleFacts($contest) as $fact): ?>
			<li class="list-group-item py-2"><?= HTML::escape($fact) ?></li>
			<?php endforeach ?>
		</ul>
	</div>
	
		<a href="/contest/<?=$contest['id']?>/registrants" class="btn btn-info btn-block"><?= UOJLocale::get('contests::contest registrants') ?></a>
		<?php if (can($myUser, 'contest.manage', $contest)): ?>
		<a href="/contest/<?=$contest['id']?>/manage" class="btn btn-primary btn-block">管理</a>
		<?php if (isset($start_test_form)): ?>
		<div class="top-buffer-sm">
			<?php $start_test_form->printHTML(); ?>
		</div>
		<?php endif ?>
		<?php if (isset($publish_result_form)): ?>
		<div class="top-buffer-sm">
			<?php $publish_result_form->printHTML(); ?>
		</div>
		<?php endif ?>
		<?php endif ?>
	
		<?php if ($contest['extra_config']['links']) { ?>
			<?php if ($cur_tab == 'standings'): ?>
	</div>
	<div class="col-sm-3">
		<div class="card border-info">
		<?php else: ?>
		<div class="card border-info top-buffer-lg">
		<?php endif ?>
			<div class="card-header bg-info">
				<h3 class="card-title">比赛资料</h3>
			</div>
			<div class="list-group">
			<?php foreach ($contest['extra_config']['links'] as $link) { ?>
				<a href="/blogs/<?=$link[1]?>" class="list-group-item"><?=$link[0]?></a>
			<?php } ?>
			</div>
		</div>
		<?php } ?>
	</div>
</div>
<?php echoUOJPageFooter() ?>
