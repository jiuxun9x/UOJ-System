<?php
	requirePHPLib('form');
	if (!validateUInt($_GET['id']) || !($contest = queryContest($_GET['id']))) {
		become404Page();
	}
	genMoreContestInfo($contest);
	// a contest of a domain exists for the members of the domain
	if (!can($myUser, 'contest.view', $contest)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}
	
	if ($myUser == null) {
		redirectToLogin();
	} elseif (!can($myUser, 'contest.register', $contest) || $contest['cur_progress'] > CONTEST_IN_PROGRESS) {
		// The people who run it do not register, nobody registers twice, and nobody once it
		// is over. While it runs it can be joined: who comes late is late, and is not kept out.
		redirectTo($contest['cur_progress'] > CONTEST_IN_PROGRESS ? "/contest/{$contest['id']}" : '/contests');
	}
	$is_running = $contest['cur_progress'] == CONTEST_IN_PROGRESS;
	
	$register_form = new UOJForm('register');
	if ($contest['join_mode'] === 'password') {
		// Whoever knows the password may take part. Guessing is slow, and stops after a while.
		$register_form->addInput('join_password', 'password', '参赛密码', '',
			function($password) use ($contest) {
				$key = "contest_password_failures_{$contest['id']}";
				if (isset($_SESSION[$key]) && $_SESSION[$key] >= 20) {
					return '尝试次数过多，请重新登录后再试';
				}
				if (!is_string($password) || !password_verify($password, $contest['join_password'])) {
					$_SESSION[$key] = (isset($_SESSION[$key]) ? $_SESSION[$key] : 0) + 1;
					return '参赛密码不正确';
				}
				return '';
			},
			null
		);
	}
	$register_form->handle = function() {
		global $myUser, $contest;
		DB::query("insert into contests_registrants (username, user_rating, contest_id, has_participated) values ('{$myUser['username']}', {$myUser['rating']}, {$contest['id']}, 0)");
		updateContestPlayerNum($contest);
	};
	$register_form->submit_button_config['class_str'] = 'btn btn-primary';
	$register_form->submit_button_config['text'] = '报名比赛';
	// whoever registers for a contest that runs goes straight into it
	$register_form->succ_href = $is_running ? "/contest/{$contest['id']}" : "/contests";
	
	$register_form->runAtServer();
?>
<?php echoUOJPageHeader(HTML::stripTags($contest['name']) . ' - 报名') ?>
<?php echoContestDomainLink($contest) ?>
<?php if ($is_running): ?>
<div class="alert alert-info" id="register-while-running">这场比赛已经开始，现在仍然可以报名。计时从比赛开始时算起，不从你报名的时候算。</div>
<?php endif ?>
<h1 class="page-header">比赛规则</h1>
<ul>
	<li>比赛报名后不算正式参赛，报名后进了比赛页面也不算参赛，<strong>看了题目才算正式参赛</strong>。如果未正式参赛则不算rating。</li>
	<li>比赛中途可以提交，若同一题有多次提交按<strong>最后一次不是Compile Error的提交</strong>算成绩。（其实UOJ会自动无视你所有Compile Error的提交当作没看见）</li>
	<li>比赛中途提交后，可以看到<strong>测样例</strong>的结果。（若为提交答案题则对于每个测试点，该测试点有分则该测试点为满分）</li>
	<li>比赛结束后会进行最终测试，最终测试后的排名为最终排名。</li>
	<li>比赛排名按分数为第一关键字，完成题目的总时间为第二关键字。完成题目的总时间等于完成每道题所花时间之和（无视掉爆零的题目）。</li>
	<li>请遵守比赛规则，一位选手在一场比赛内不得报名多个账号，选手之间不能交流或者抄袭代码，如果被检测到将以0分处理或者封禁。</li>
</ul>
<?php if ($contest['join_mode'] === 'password'): ?>
<p id="contest-needs-password">这场比赛需要参赛密码才能报名，请向举办者索取。</p>
<?php elseif ($contest['join_mode'] === 'list'): ?>
<p id="contest-on-list">这场比赛只对名单里的人开放，你在名单里。</p>
<?php endif ?>
<?php $register_form->printHTML(); ?>
<?php echoUOJPageFooter() ?>
