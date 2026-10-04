<?php
	// the front page of a domain: for its members what is going on in it, for everybody else,
	// if the domain shows itself at all, what it is and how to join it
	$domain = isset($_GET['slug']) ? queryDomainBySlug($_GET['slug']) : null;
	if (!$domain || !can($myUser, 'domain.view_landing', $domain)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}
	$is_inside = can($myUser, 'domain.view', $domain);
	
	$error = domainHandleForms(array(
		'join' => function() use ($domain) {
			global $myUser;
			if (!can($myUser, 'domain.join', $domain)) {
				return '不能加入这个域';
			}
			DB::insert("insert ignore into domain_members (domain_id, username, role, joined_at, added_by) values ({$domain['id']}, '".DB::escape($myUser['username'])."', 'member', now(), '')");
			auditLog('domain.join', 'domain', $domain['id'], null, array('username' => $myUser['username'], 'by' => 'self'));
			domainFlash('你已加入这个域。');
			return '';
		}
	));
?>
<?php if ($is_inside): ?>
<?php echoDomainPageHeader($domain, 'overview', '概览') ?>
<?php echoDomainError($error) ?>
<div class="row">
	<div class="col-lg-8">
		<?php if (trim($domain['description']) !== ''): ?>
		<div class="card mb-3">
			<div class="card-body uoj-domain-description"><?= HTML::escape($domain['description']) ?></div>
		</div>
		<?php endif ?>
		<?php
			$now = homeworkNow();
			$current_homeworks = array();
			foreach (DB::selectAll("select * from homeworks where domain_id = {$domain['id']} and status = 'published' order by end_at") as $homework) {
				$homework = homeworkTouch($homework);
				if (homeworkPhase($homework, $now) !== 'ended') {
					$current_homeworks[] = $homework;
				}
			}
		?>
		<h3 class="uoj-domain-section-title mt-0">进行中和即将开始的作业</h3>
		<?php if (!$current_homeworks): ?>
		<div class="uoj-domain-empty mb-3" id="domain-overview-empty">现在没有进行中的作业。<a href="<?= domainUrl($domain, '/homeworks') ?>">查看全部作业</a></div>
		<?php endif ?>
		<?php foreach ($current_homeworks as $homework): ?>
		<?php
			$phase_name = homeworkPhaseName(homeworkPhase($homework, $now));
			$participation = Auth::check() ? homeworkParticipation($homework['id'], Auth::id()) : null;
			$is_participant = $participation && $participation['status'] === 'active';
			$points = array_sum(homeworkProblemPoints($homework));
			$mine = $is_participant && $now >= strtotime($homework['begin_at']) ? homeworkTotal(homeworkLiveScores($homework), Auth::id()) : null;
		?>
		<div class="card mb-3">
			<div class="card-body">
				<div class="d-flex flex-wrap align-items-start">
					<h5 class="card-title mr-auto mb-1"><a href="<?= homeworkUrl($domain, $homework) ?>"><?= HTML::escape($homework['title']) ?></a> <span class="badge <?= $phase_name[1] ?>"><?= $phase_name[0] ?></span></h5>
					<?php if ($is_participant): ?>
					<span class="badge badge-success">已认领</span>
					<?php elseif (can($myUser, 'homework.claim', $homework)): ?>
					<a class="btn btn-primary btn-sm" href="<?= homeworkUrl($domain, $homework) ?>">去认领</a>
					<?php endif ?>
				</div>
				<p class="text-muted small mb-2"><?= substr($homework['begin_at'], 0, 16) ?> 开始 <span class="mx-1">·</span> <?= substr($homework['end_at'], 0, 16) ?> 截止</p>
				<?php if ($mine !== null && $points > 0): ?>
				<div class="d-flex align-items-center">
					<div class="progress flex-grow-1 mr-2" style="height: 0.6rem"><div class="progress-bar bg-success" style="width: <?= round(100 * $mine / $points) ?>%"></div></div>
					<small><?= homeworkTrimNumber($mine) ?> / <?= $points ?></small>
				</div>
				<?php endif ?>
			</div>
		</div>
		<?php endforeach ?>
	</div>
	<div class="col-lg-4">
		<div class="card mb-3" id="card-announcements">
			<div class="card-header d-flex">
				<span class="mr-auto">公告</span>
				<a href="<?= domainUrl($domain, '/announcements') ?>" class="small">全部</a>
			</div>
			<?php $announcements = domainAnnouncements($domain, 5); ?>
			<?php if (!$announcements): ?>
			<div class="card-body text-muted small">暂无公告</div>
			<?php else: ?>
			<div class="list-group list-group-flush">
				<?php foreach ($announcements as $announcement): ?>
				<a class="list-group-item list-group-item-action" href="<?= domainUrl($domain, '/announcements') ?>#announcement-<?= $announcement['id'] ?>">
					<?php if ($announcement['pinned']): ?><span class="badge badge-warning">置顶</span><?php endif ?>
					<?= HTML::escape($announcement['title']) ?>
					<small class="text-muted d-block"><?= substr($announcement['created_at'], 0, 10) ?></small>
				</a>
				<?php endforeach ?>
			</div>
			<?php endif ?>
		</div>
		<div class="card mb-3">
			<div class="card-header">关于</div>
			<ul class="list-group list-group-flush">
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">类型</span> <?= domainTypes()[$domain['type']] ?></li>
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">所有者</span> <?= getUserLink($domain['owner_username']) ?></li>
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">成员</span> <a href="<?= domainUrl($domain, '/members') ?>"><?= domainMemberCount($domain) ?> 人</a></li>
				<li class="list-group-item d-flex"><span class="text-muted mr-auto">创建于</span> <?= substr($domain['created_at'], 0, 10) ?></li>
			</ul>
		</div>
	</div>
</div>
<?php else: ?>
<?php echoUOJPageHeader(HTML::escape($domain['name'])) ?>
<?php echoDomainError($error) ?>
<div class="row justify-content-center">
	<div class="col-lg-8">
		<div class="card" id="domain-landing">
			<div class="card-body">
				<h2 class="card-title"><?= HTML::escape($domain['name']) ?> <span class="badge badge-light border" style="font-size:0.8rem"><?= domainTypes()[$domain['type']] ?></span></h2>
				<p class="text-muted"><span class="glyphicon glyphicon-user"></span> <?= domainMemberCount($domain) ?> 人 <span class="mx-1">·</span> <?= getUserLink($domain['owner_username']) ?></p>
				<?php if (trim($domain['description']) !== ''): ?>
				<div class="uoj-domain-description mb-3"><?= HTML::escape($domain['description']) ?></div>
				<?php endif ?>
				<hr />
				<?php if ($domain['archived_at'] !== null): ?>
				<p class="mb-0 text-muted">这个域已归档。</p>
				<?php elseif (!Auth::check()): ?>
				<p class="mb-0">域里的内容只有成员能看到。请先 <a href="/login">登录</a>。</p>
				<?php elseif ($domain['join_method'] === 'all'): ?>
				<form method="post">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="join" />
					<button type="submit" class="btn btn-primary" id="button-join-domain">加入这个域</button>
				</form>
				<?php elseif ($domain['join_method'] === 'code'): ?>
				<p class="mb-0">这个域凭邀请加入。拿到邀请后，请到 <a href="/domains/join">凭邀请加入</a> 页面输入。</p>
				<?php else: ?>
				<p class="mb-0">这个域只能由管理者添加成员，请联系老师。</p>
				<?php endif ?>
			</div>
		</div>
	</div>
</div>
<?php endif ?>
<?php echoUOJPageFooter() ?>
