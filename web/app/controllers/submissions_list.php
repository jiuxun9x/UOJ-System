<?php
	requirePHPLib('judger');
	$conds = array();
	
	$q_problem_id = isset($_GET['problem_id']) && validateUInt($_GET['problem_id']) ? $_GET['problem_id'] : null;
	$q_submitter = isset($_GET['submitter']) && validateUsername($_GET['submitter']) ? $_GET['submitter'] : null;
	$q_min_score = isset($_GET['min_score']) && validateUInt($_GET['min_score']) ? $_GET['min_score'] : null;
	$q_max_score = isset($_GET['max_score']) && validateUInt($_GET['max_score']) ? $_GET['max_score'] : null;
	$q_language = isset($_GET['language']) ? $_GET['language'] : null;
	// what was submitted to a homework: who may see which of it is decided as everywhere
	$q_homework = isset($_GET['homework_id']) && validateUInt($_GET['homework_id']) ? queryHomework($_GET['homework_id']) : null;
	$q_homework_domain = $q_homework ? queryDomain($q_homework['domain_id']) : null;
	if ($q_homework && !($q_homework_domain && can($myUser, 'homework.view', $q_homework))) {
		$q_homework = null;
	}
	if ($q_homework) {
		$conds[] = "submissions.homework_id = {$q_homework['id']}";
	}
	// what was submitted to the problems of a training: a training keeps nothing of its own,
	// its problems are problems of the domain, so this is what they were sent
	$q_training = isset($_GET['training_id']) && validateUInt($_GET['training_id']) ? queryTraining($_GET['training_id']) : null;
	$q_training_domain = $q_training ? queryDomain($q_training['domain_id']) : null;
	if ($q_training && !($q_training_domain && can($myUser, 'domain.view', $q_training_domain) && can($myUser, 'training.view', $q_training))) {
		$q_training = null;
	}
	if ($q_training) {
		$conds[] = "submissions.problem_id in (select problem_id from training_problems where training_id = {$q_training['id']})";
	}
	if ($q_problem_id != null) {
		$conds[] = "problem_id = $q_problem_id";
	}
	if ($q_submitter != null) {
		$conds[] = "submitter = '$q_submitter'";
	}
	if ($q_min_score != null) {
		$conds[] = "score >= $q_min_score";
	}
	if ($q_max_score != null) {
		$conds[] = "score <= $q_max_score";
	}
	if ($q_language != null) {
		$conds[] = sprintf("language = '%s'", DB::escape($q_language));
	}
	
	$html_esc_q_language = htmlspecialchars($q_language);
	
	if ($conds) {
		$cond = join($conds, ' and ');
	} else {
		$cond = '1';
	}
?>
<?php echoUOJPageHeader(UOJLocale::get('submissions')) ?>
<?php if ($q_homework): ?>
<p class="uoj-domain-back" id="submissions-of-homework"><a href="<?= homeworkUrl($q_homework_domain, $q_homework) ?>"><span class="glyphicon glyphicon-chevron-left"></span> 作业：<?= HTML::escape($q_homework['title']) ?></a>
<span class="text-muted">下面是提交到这个作业的记录<?= can($myUser, 'homework.view_scores', $q_homework) ? '，所有人的都在' : '' ?>。</span></p>
<?php elseif ($q_training): ?>
<p class="uoj-domain-back" id="submissions-of-training"><a href="<?= trainingUrl($q_training_domain, $q_training) ?>"><span class="glyphicon glyphicon-chevron-left"></span> 训练：<?= HTML::escape($q_training['title']) ?></a>
<span class="text-muted">下面是这份训练里的题目收到的提交<?= can($myUser, 'training.view_progress', $q_training) ? '，所有人的都在' : '' ?>。</span></p>
<?php endif ?>
<div class="d-none d-sm-block">
	<?php if ($myUser != null): ?>
	<div class="float-right">
		<a href="/submissions?submitter=<?= $myUser['username'] ?>" class="btn btn-primary btn-sm"><?= UOJLocale::get('problems::my submissions') ?></a>
	</div>
	<?php endif ?>
	<form id="form-search" class="form-inline" method="get">
		<div id="form-group-problem_id" class="form-group mr-3 mb-2">
			<label for="input-problem_id" class="control-label mr-1"><?= UOJLocale::get('problems::problem id')?>:</label>
			<input type="text" class="form-control input-sm" name="problem_id" id="input-problem_id" value="<?= $q_problem_id ?>" maxlength="10" style="width:6em" />
		</div>
		<div id="form-group-submitter" class="form-group mr-3 mb-2">
			<label for="input-submitter" class="control-label mr-1"><?= UOJLocale::get('username')?>:</label>
			<input type="text" class="form-control input-sm" name="submitter" id="input-submitter" value="<?= $q_submitter ?>" maxlength="20" style="width:10em" />
		</div>
		<div id="form-group-score" class="form-group mr-3 mb-2">
			<label for="input-min_score" class="control-label mr-1"><?= UOJLocale::get('score range')?>:</label>
			<input type="text" class="form-control input-sm" name="min_score" id="input-min_score" value="<?= $q_min_score ?>" maxlength="3" style="width:4em" placeholder="0" />
			<label for="input-max_score" class="control-label mx-1">~</label>
			<input type="text" class="form-control input-sm" name="max_score" id="input-max_score" value="<?= $q_max_score ?>" maxlength="3" style="width:4em" placeholder="100" />
		</div>
		<div id="form-group-language" class="form-group mr-3 mb-2">
			<label for="input-language" class="control-label mr-1"><?= UOJLocale::get('problems::language')?>:</label>
			<?php
				// the languages there are to choose from; one that is asked for and no longer offered stays chosen
				$language_options = $GLOBALS['uojSupportedLanguages'];
				if ($q_language != null && !in_array($q_language, $language_options, true)) {
					$language_options[] = $q_language;
				}
			?>
			<select class="form-control input-sm" name="language" id="input-language" style="width:8em">
				<option value=""><?= UOJLocale::get('problems::all languages') ?></option>
				<?php foreach ($language_options as $language_option): ?>
				<option value="<?= HTML::escape($language_option) ?>"<?= $q_language === $language_option ? ' selected="selected"' : '' ?>><?= HTML::escape($language_option) ?></option>
				<?php endforeach ?>
			</select>
		</div>
		<button type="submit" id="submit-search" class="btn btn-secondary btn-sm mb-2"><?= UOJLocale::get('search')?></button>
	</form>
	<script type="text/javascript">
		$('#form-search').submit(function(e) {
			e.preventDefault();
			
			url = '/submissions';
			qs = [];
			$(['problem_id', 'submitter', 'min_score', 'max_score', 'language']).each(function () {
				if ($('#input-' + this).val()) {
					qs.push(this + '=' + encodeURIComponent($('#input-' + this).val()));
				}
			});
			<?php if ($q_homework): ?>
			qs.push('homework_id=<?= $q_homework['id'] ?>');
			<?php elseif ($q_training): ?>
			qs.push('training_id=<?= $q_training['id'] ?>');
			<?php endif ?>
			if (qs.length > 0) {
				url += '?' + qs.join('&');
			}
			location.href = url;
		});
	</script>
	<div class="top-buffer-sm"></div>
</div>
<?php
	// in the list of one homework a problem is called what it is called in the homework
	echoSubmissionsList($cond, 'order by id desc', array('judge_time_hidden' => '') + ($q_homework ? array('inside' => '') : array()), $myUser);
?>
<?php echoUOJPageFooter() ?>
