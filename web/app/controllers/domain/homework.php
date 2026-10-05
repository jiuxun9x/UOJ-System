<?php
	// a homework as the people who take part see it
	$domain = domainOfPage();
	$homework = isset($_GET['homework_id']) ? queryHomework($_GET['homework_id']) : null;
	if (!$homework || $homework['domain_id'] != $domain['id'] || !can($myUser, 'homework.view', $homework)) {
		become404Page();
	}
	$homework = homeworkTouch($homework);
	
	$error = domainHandleForms(array(
		'claim' => function() use ($homework) {
			global $myUser;
			$err = homeworkClaim($homework, $myUser);
			if ($err === '') {
				domainFlash('已认领。' . (homeworkNow() < strtotime($homework['begin_at']) ? '作业开始后这里会显示题目。' : ''));
			}
			return $err;
		},
		'withdraw' => function() use ($homework) {
			global $myUser;
			$err = homeworkWithdraw($homework, $myUser);
			if ($err === '') {
				domainFlash('已取消认领。');
			}
			return $err;
		}
	));
	
	$now = homeworkNow();
	$phase = homeworkPhase($homework, $now);
	$phase_name = homeworkPhaseName($phase);
	$can_manage = can($myUser, 'homework.manage', $homework);
	$can_view_scores = can($myUser, 'homework.view_scores', $homework);
	$can_solve = can($myUser, 'homework.solve', $homework);
	$participation = Auth::check() ? homeworkParticipation($homework['id'], Auth::id()) : null;
	$is_participant = $participation && $participation['status'] === 'active';
	$problems = homeworkProblems($homework);
	$total_points = array_sum(array_column($problems, 'score'));
	
	// the scores of the user: the official ones once the homework is settled, and how far they have got since
	$official = homeworkOfficialScores($homework);
	$mine = array();
	$mine_correction = array();
	if ($is_participant) {
		$scores = $official !== null ? $official : homeworkLiveScores($homework);
		$mine = isset($scores[Auth::id()]) ? $scores[Auth::id()] : array();
		$correction = homeworkCorrectionScores($homework);
		$mine_correction = isset($correction[Auth::id()]) ? $correction[Auth::id()] : array();
	}
	$my_total = array_sum(array_column($mine, 'score'));
	$my_correction_total = array_sum(array_column($mine_correction, 'score'));
	
	// where the moment is between the begin and the end, for the bar
	$begin = strtotime($homework['begin_at']);
	$end = strtotime($homework['end_at']);
	$penalty = $homework['penalty_since'] !== null ? strtotime($homework['penalty_since']) : $end;
	$share = function($time) use ($begin, $end) {
		return max(0, min(100, round(100 * ($time - $begin) / max(1, $end - $begin), 1)));
	};
	$REQUIRE_LIB['mathjax'] = '';
?>
<?php echoDomainPageHeader($domain, 'homeworks', $homework['title']) ?>
<?php echoDomainError($error) ?>
<div class="d-flex flex-wrap align-items-start mb-2">
	<h3 class="mr-auto mb-2"><?= HTML::escape($homework['title']) ?> <span class="badge <?= $phase_name[1] ?>" id="homework-phase"><?= $phase_name[0] ?></span></h3>
	<div class="mb-2">
		<?php if ($can_view_scores): ?>
		<a class="btn btn-outline-secondary btn-sm" href="/submissions?homework_id=<?= $homework['id'] ?>" id="link-homework-submissions">提交记录</a>
		<a class="btn btn-outline-secondary btn-sm" href="<?= homeworkUrl($domain, $homework, '/scoreboard') ?>">成绩表</a>
		<?php elseif ($is_participant): ?>
		<a class="btn btn-outline-secondary btn-sm" href="/submissions?homework_id=<?= $homework['id'] ?>&amp;submitter=<?= Auth::id() ?>" id="link-homework-submissions">我的提交</a>
		<?php endif ?>
		<?php if ($can_manage): ?>
		<a class="btn btn-outline-primary btn-sm" href="<?= homeworkUrl($domain, $homework, '/manage') ?>">管理</a>
		<?php endif ?>
	</div>
</div>

<div class="uoj-homework-timeline mb-3">
	<div class="progress">
		<div class="progress-bar bg-success" style="width: <?= $share($penalty) ?>%" title="按时提交"></div>
		<div class="progress-bar bg-warning" style="width: <?= 100 - $share($penalty) ?>%" title="迟交"></div>
	</div>
	<?php if ($phase === 'running' || $phase === 'penalty'): ?>
	<div class="uoj-homework-now" style="left: <?= $share($now) ?>%" title="现在"></div>
	<?php endif ?>
	<div class="d-flex small text-muted mt-1">
		<span class="mr-auto"><?= substr($homework['begin_at'], 0, 16) ?> 开始</span>
		<?php if ($penalty < $end): ?>
		<span class="mx-2"><?= substr($homework['penalty_since'], 0, 16) ?> 正常截止</span>
		<?php endif ?>
		<span class="ml-auto"><?= substr($homework['end_at'], 0, 16) ?> <?= $penalty < $end ? '最终截止' : '截止' ?></span>
	</div>
</div>

<div class="row">
	<div class="col-lg-8">
		<?php if (trim($homework['description']) !== ''): ?>
		<div class="card mb-3"><div class="card-body"><article class="uoj-article"><?= $homework['description'] ?></article></div></div>
		<?php endif ?>

		<?php if (!$can_solve): ?>
		<div class="uoj-domain-empty mb-3" id="homework-problems-closed">
			<?php if ($phase === 'upcoming' && $is_participant): ?>
			作业将在 <?= substr($homework['begin_at'], 0, 16) ?> 开始，开始后这里会显示题目。
			<?php elseif ($phase === 'upcoming'): ?>
			作业还没有开始。认领之后，开始时就能看到题目。
			<?php else: ?>
			认领这个作业之后才能看到题目。
			<?php endif ?>
		</div>
		<?php else: ?>
		<div class="table-responsive">
			<table class="table table-hover" id="table-homework-problems">
				<thead>
					<tr>
						<th style="width:3em">#</th>
						<th>题目</th>
						<th style="width:5em">分值</th>
						<?php if ($is_participant): ?>
						<th style="width:8em"><?= $official !== null ? '正式成绩' : '当前得分' ?></th>
						<?php if ($official !== null): ?>
						<th style="width:6em">订正</th>
						<?php endif ?>
						<?php endif ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($problems as $index => $problem): ?>
					<?php $row = isset($mine[$problem['problem_id']]) ? $mine[$problem['problem_id']] : null; ?>
					<tr>
						<td><?= chr(ord('A') + $index % 26) ?></td>
						<td>
							<a href="<?= homeworkUrl($domain, $homework, '/problem/' . problemNumber($problem)) ?>"><?= $problem['title'] ?></a>
							<?php if (!$problem['required']): ?><span class="badge badge-light border">选做</span><?php endif ?>
						</td>
						<td><?= $problem['score'] ?></td>
						<?php if ($is_participant): ?>
						<td>
							<?php if ($row && $row['submission_id'] !== null): ?>
							<a href="/submission/<?= $row['submission_id'] ?>" class="uoj-homework-score" data-full="<?= $row['score'] >= $problem['score'] ? 1 : 0 ?>"><?= homeworkTrimNumber($row['score']) ?></a>
							<?php if ($row['multiplier'] < 1): ?><small class="text-muted" title="迟交">×<?= homeworkTrimNumber($row['multiplier'] * 100) ?>%</small><?php endif ?>
							<?php else: ?>
							<span class="text-muted">—</span>
							<?php endif ?>
						</td>
						<?php if ($official !== null): ?>
						<td>
							<?php if (isset($mine_correction[$problem['problem_id']])): ?>
							<a href="/submission/<?= $mine_correction[$problem['problem_id']]['submission_id'] ?>"><?= homeworkTrimNumber($mine_correction[$problem['problem_id']]['score']) ?></a>
							<?php else: ?>
							<span class="text-muted">—</span>
							<?php endif ?>
						</td>
						<?php endif ?>
						<?php endif ?>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php if ($is_participant): ?>
		<h4 class="uoj-domain-section-title">我在这个作业里的提交</h4>
		<?php echoSubmissionsList("submissions.homework_id = {$homework['id']} and submissions.submitter = '".DB::escape(Auth::id())."'", 'order by id desc', array('judge_time_hidden' => '', 'submitter_hidden' => ''), $myUser) ?>
		<?php endif ?>
		<?php endif ?>
	</div>

	<div class="col-lg-4">
		<?php if ($is_participant): ?>
		<div class="card mb-3" id="card-my-score">
			<div class="card-body text-center">
				<?php if ($phase === 'upcoming'): ?>
				<p class="mb-2"><span class="badge badge-success">已认领</span></p>
				<p class="text-muted small mb-0">作业开始后就可以做题了。</p>
				<?php else: ?>
				<div class="text-muted small"><?= $official !== null ? '正式成绩' : '当前得分' ?></div>
				<div class="uoj-homework-total" id="my-official-score"><?= homeworkTrimNumber($my_total) ?> <small class="text-muted">/ <?= $total_points ?></small></div>
				<?php if ($official !== null): ?>
				<div class="text-muted small mt-2">当前订正进度</div>
				<div class="h4 mb-0" id="my-correction-score"><?= homeworkTrimNumber($my_correction_total) ?> <small class="text-muted">/ <?= $total_points ?></small></div>
				<p class="text-muted small mt-2 mb-0">正式成绩在截止时结算，之后的提交只算订正。</p>
				<?php elseif ($phase === 'ended'): ?>
				<p class="text-muted small mt-2 mb-0">作业已截止，正在等待评测完成后结算正式成绩。</p>
				<?php else: ?>
				<p class="text-muted small mt-2 mb-0">每题取得分最高的一次提交，截止时结算为正式成绩。</p>
				<?php endif ?>
				<?php endif ?>
				<?php if ($phase === 'upcoming' && $homework['allow_withdraw']): ?>
				<form method="post" class="mt-2" onsubmit="return confirm('确定要取消认领吗？');">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="withdraw" />
					<button type="submit" class="btn btn-outline-secondary btn-sm" id="button-withdraw-homework">取消认领</button>
				</form>
				<?php endif ?>
			</div>
		</div>
		<?php elseif (can($myUser, 'homework.claim', $homework)): ?>
		<div class="card border-primary mb-3" id="card-claim">
			<div class="card-body text-center">
				<p>认领之后才算参加这个作业，成绩才会计入。</p>
				<form method="post">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="claim" />
					<button type="submit" class="btn btn-primary btn-block" id="button-claim-homework">认领作业</button>
				</form>
			</div>
		</div>
		<?php elseif (permissionDomainRole($myUser, $domain) === 'member' && $homework['status'] === 'published'): ?>
		<div class="card mb-3"><div class="card-body text-muted text-center">认领已经截止。如需参加请联系老师。</div></div>
		<?php endif ?>

		<div class="card mb-3" id="card-rules">
			<div class="card-header">计分规则</div>
			<ul class="list-group list-group-flush small">
				<?php foreach (homeworkDescribePenalty($homework) as $line): ?>
				<li class="list-group-item py-2"><?= HTML::escape($line) ?></li>
				<?php endforeach ?>
			</ul>
		</div>
	</div>
</div>
<?php echoUOJPageFooter() ?>
