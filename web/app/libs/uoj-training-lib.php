<?php

// Trainings: lists of problems of a domain to work through, in the order the teacher put
// them. Unlike a homework a training is not claimed, has no end and freezes nothing: what
// counts is the best score a member has on each problem, wherever it was submitted.

function queryTraining($id) {
	return DB::selectFirst("select * from trainings where id = ".(int)$id, MYSQLI_ASSOC);
}
function trainingUrl($domain, $training, $path = '') {
	return domainUrl($domain, "/training/{$training['id']}$path");
}
// the problems of a training in their order, each with what is known of the problem itself
function trainingProblems($training) {
	return DB::selectAll("select training_problems.problem_id, training_problems.position, training_problems.required, problems.id, problems.domain_pid, problems.title, problems.is_hidden, problems.owner_domain_id from training_problems join problems on problems.id = training_problems.problem_id where training_problems.training_id = {$training['id']} order by training_problems.position, training_problems.problem_id");
}
// Where a problem of a training is solved. Trainings made before problems had to be copied
// into the domain may still name problems of the site.
function trainingProblemUrl($domain, $problem) {
	return problemUrl($problem);
}

// the best scores of users on problems: array(username => array(problem id => score))
function trainingBestScores($problem_ids, $usernames) {
	$best = array();
	if (!$problem_ids || !$usernames) {
		return $best;
	}
	$ids = implode(',', array_map('intval', $problem_ids));
	$names = implode(',', array_map(function($name) {
		return "'".DB::escape($name)."'";
	}, $usernames));
	foreach (DB::selectAll("select submitter, problem_id, max(score) as score from submissions where problem_id in ($ids) and submitter in ($names) and score is not null group by submitter, problem_id") as $row) {
		$best[$row['submitter']][(int)$row['problem_id']] = (int)$row['score'];
	}
	return $best;
}

// What somebody has done of a training. $problems are its problems, $scores the best scores
// of the user by problem. A training is done when every problem that has to be solved is
// solved; when no problem has to be, when all of them are.
function trainingProgress($problems, $scores) {
	$progress = array('total' => count($problems), 'solved' => 0, 'tried' => 0, 'required' => 0, 'required_solved' => 0);
	foreach ($problems as $problem) {
		$score = isset($scores[(int)$problem['problem_id']]) ? $scores[(int)$problem['problem_id']] : null;
		$solved = $score !== null && $score >= 100;
		if ($score !== null) {
			$progress['tried']++;
		}
		if ($solved) {
			$progress['solved']++;
		}
		if ($problem['required']) {
			$progress['required']++;
			if ($solved) {
				$progress['required_solved']++;
			}
		}
	}
	if ($progress['required'] > 0) {
		$progress['done'] = $progress['required_solved'] == $progress['required'];
	} else {
		$progress['done'] = $progress['total'] > 0 && $progress['solved'] == $progress['total'];
	}
	return $progress;
}

// Returns '' when the settings of a training make sense, or what is wrong with them.
function trainingSettingsError($input) {
	if (!isset($input['title']) || !is_string($input['title']) || trim($input['title']) === '' || mb_strlen($input['title'], 'UTF-8') > 100) {
		return '标题不能为空，且不超过 100 个字符';
	}
	if (isset($input['description_md']) && (!is_string($input['description_md']) || strlen($input['description_md']) > 200000)) {
		return '说明太长';
	}
	if (!isset($input['status']) || !in_array($input['status'], array('draft', 'published'), true)) {
		return '无效的状态';
	}
	return '';
}

// ---- changes; each returns '' or why it was refused

// Creates a training, or changes one. Returns array(the id of the training, '') or
// array(null, why it was refused).
function trainingSave($domain, $training, $input, $actor) {
	$err = trainingSettingsError($input);
	if ($err !== '') {
		return array(null, $err);
	}
	$description_md = isset($input['description_md']) ? $input['description_md'] : '';
	$title = trim($input['title']);
	$set = "title = '".DB::escape($title)."', description_md = '".DB::escape($description_md)."', description = '".DB::escape(domainRenderMarkdown($description_md))."', status = '{$input['status']}', updated_at = now()";
	if ($training) {
		DB::update("update trainings set $set where id = {$training['id']}");
		auditLog('training.edit', 'training', $training['id'], array('title' => $training['title'], 'status' => $training['status']), array('title' => $title, 'status' => $input['status']), $actor);
		return array((int)$training['id'], '');
	}
	DB::insert("insert into trainings set domain_id = {$domain['id']}, created_by = '".DB::escape($actor['username'])."', created_at = now(), $set");
	$id = (int)DB::insert_id();
	auditLog('training.create', 'training', $id, null, array('domain_id' => (int)$domain['id'], 'title' => $title), $actor);
	return array($id, '');
}
function trainingDelete($training, $actor) {
	DB::delete("delete from training_problems where training_id = {$training['id']}");
	DB::delete("delete from trainings where id = {$training['id']}");
	auditLog('training.delete', 'training', $training['id'], array('domain_id' => (int)$training['domain_id'], 'title' => $training['title']), null, $actor);
	return '';
}

function trainingAddProblem($training, $problem, $required, $actor) {
	// like a homework, a training is made of the problems of its domain
	if (!$problem || $problem['owner_domain_id'] != $training['domain_id']) {
		return '本域没有这个题号。训练只能用本域的题目：主站的题目请先在“题目”页复制到本域';
	}
	$position = 1 + (int)DB::selectFirst("select ifnull(max(position), 0) from training_problems where training_id = {$training['id']}", MYSQLI_NUM)[0];
	if (!DB::insert("insert into training_problems (training_id, problem_id, position, required) values ({$training['id']}, {$problem['id']}, $position, ".($required ? 1 : 0).")")) {
		return '这道题已经在训练里了';
	}
	auditLog('training.add_problem', 'training', $training['id'], null, array('problem_id' => (int)$problem['id'], 'required' => $required ? 1 : 0), $actor);
	return '';
}
function trainingUpdateProblem($training, $problem_id, $required, $actor) {
	DB::update("update training_problems set required = ".($required ? 1 : 0)." where training_id = {$training['id']} and problem_id = ".(int)$problem_id);
	return '';
}
function trainingRemoveProblem($training, $problem_id, $actor) {
	DB::delete("delete from training_problems where training_id = {$training['id']} and problem_id = ".(int)$problem_id);
	auditLog('training.remove_problem', 'training', $training['id'], array('problem_id' => (int)$problem_id), null, $actor);
	return '';
}
// moves a problem one place in the order, up or down
function trainingMoveProblem($training, $problem_id, $down = false) {
	$ids = array();
	foreach (trainingProblems($training) as $row) {
		$ids[] = (int)$row['problem_id'];
	}
	$index = array_search((int)$problem_id, $ids, true);
	$other = $index === false ? -1 : ($down ? $index + 1 : $index - 1);
	if (!isset($ids[$other])) {
		return '';
	}
	$ids[$index] = $ids[$other];
	$ids[$other] = (int)$problem_id;
	foreach ($ids as $position => $id) {
		DB::update("update training_problems set position = ".($position + 1)." where training_id = {$training['id']} and problem_id = $id");
	}
	return '';
}
