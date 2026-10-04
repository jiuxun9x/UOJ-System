<?php
	// creating a homework, and everything the people who manage it do with it
	$domain = domainOfPage();
	$homework = null;
	if (isset($_GET['homework_id'])) {
		$homework = queryHomework($_GET['homework_id']);
		if (!$homework || $homework['domain_id'] != $domain['id']) {
			become404Page();
		}
		if (!can($myUser, 'homework.manage', $homework)) {
			become403Page();
		}
		$homework = homeworkTouch($homework);
	} elseif (!can($myUser, 'domain.teach', $domain)) {
		become403Page();
	}
	$can_teach = can($myUser, 'domain.teach', $domain);
	$here = $homework ? homeworkUrl($domain, $homework, '/manage') : domainUrl($domain, '/homework/new');
	
	$tabs = array('settings' => '设置');
	if ($homework) {
		$tabs += array('problems' => '题目', 'participants' => '参与者', 'scores' => '成绩与结算');
	}
	$tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'settings';
	
	// the user of a form, who has to be somebody
	$posted_user = function() {
		return isset($_POST['username']) && is_string($_POST['username']) ? queryUser(trim($_POST['username'])) : null;
	};
	$posted_problem_id = function() {
		return isset($_POST['problem_id']) && validateUInt($_POST['problem_id']) ? (int)$_POST['problem_id'] : 0;
	};
	
	$forms = array(
		'save' => function() use ($domain, $homework) {
			global $myUser;
			$input = $_POST;
			foreach (array('begin_at', 'end_at', 'penalty_since', 'claim_end_at') as $key) {
				// a browser posts "2026-10-12T23:59" from a field for a date and a time
				if (isset($input[$key]) && is_string($input[$key])) {
					$input[$key] = str_replace('T', ' ', $input[$key]);
				}
			}
			list($id, $err) = homeworkSave($domain, $homework, $input, $myUser);
			if ($err !== '') {
				return $err;
			}
			domainFlash($homework ? '设置已保存。' : '作业已创建，现在是草稿。接下来添加题目，然后发布。');
			redirectTo(domainUrl($domain, "/homework/$id/manage") . ($homework ? '' : '?tab=problems'));
		}
	);
	if ($homework) {
		$forms += array(
			'add_problem' => function() use ($homework, $posted_problem_id) {
				global $myUser;
				$score = isset($_POST['score']) && validateUInt($_POST['score']) ? (int)$_POST['score'] : 0;
				return homeworkAddProblem($homework, queryProblemBrief($posted_problem_id()), $score, !isset($_POST['optional']), $myUser);
			},
			'update_problem' => function() use ($homework, $posted_problem_id) {
				global $myUser;
				$score = isset($_POST['score']) && validateUInt($_POST['score']) ? (int)$_POST['score'] : 0;
				return homeworkUpdateProblem($homework, $posted_problem_id(), $score, !isset($_POST['optional']), $myUser);
			},
			'remove_problem' => function() use ($homework, $posted_problem_id) {
				global $myUser;
				return homeworkRemoveProblem($homework, $posted_problem_id(), $myUser);
			},
			'move_problem' => function() use ($homework, $posted_problem_id) {
				return homeworkMoveProblemUp($homework, $posted_problem_id());
			},
			'publish' => function() use ($homework) {
				global $myUser;
				return homeworkRequestPublish($homework, $myUser);
			},
			'unpublish' => function() use ($homework) {
				global $myUser;
				return homeworkUnpublish($homework, $myUser);
			},
			'add_participant' => function() use ($homework, $posted_user) {
				global $myUser;
				$target = $posted_user();
				return $target ? homeworkSetParticipant($homework, $target, true, $myUser) : '用户不存在';
			},
			'remove_participant' => function() use ($homework, $posted_user) {
				global $myUser;
				$target = $posted_user();
				return $target ? homeworkSetParticipant($homework, $target, false, $myUser) : '用户不存在';
			},
			'add_all' => function() use ($homework) {
				global $myUser;
				$count = homeworkAddAllUnclaimed($homework, $myUser);
				domainFlash("已把 $count 位未认领的学生加入作业。");
				return '';
			},
			'resettle' => function() use ($homework) {
				global $myUser;
				$scope = isset($_POST['scope']) ? $_POST['scope'] : '';
				$problem_id = $scope === 'none' ? null : ($scope === 'all' ? 0 : (validateUInt($scope) ? (int)$scope : -1));
				if ($problem_id === -1) {
					return '请选择要重测的范围';
				}
				return homeworkStartResettle($homework, $myUser, isset($_POST['reason']) && is_string($_POST['reason']) ? $_POST['reason'] : '', $problem_id);
			},
			'confirm' => function() use ($homework) {
				global $myUser;
				$err = homeworkDecideSnapshot($homework, isset($_POST['snapshot_id']) ? $_POST['snapshot_id'] : 0, true, $myUser);
				if ($err === '') {
					domainFlash('新的成绩已经生效，参与者会收到站内消息。');
				}
				return $err;
			},
			'discard' => function() use ($homework) {
				global $myUser;
				return homeworkDecideSnapshot($homework, isset($_POST['snapshot_id']) ? $_POST['snapshot_id'] : 0, false, $myUser);
			}
		);
		// only the people who teach in the domain decide who looks after a homework, copy it or delete it
		if ($can_teach) {
			$forms += array(
				'add_maintainer' => function() use ($homework, $posted_user) {
					global $myUser;
					$target = $posted_user();
					return $target ? homeworkSetMaintainer($homework, $target, true, $myUser) : '用户不存在';
				},
				'remove_maintainer' => function() use ($homework, $posted_user) {
					global $myUser;
					$target = $posted_user();
					return $target ? homeworkSetMaintainer($homework, $target, false, $myUser) : '用户不存在';
				},
				'clone' => function() use ($domain, $homework) {
					global $myUser;
					$id = homeworkClone($homework, $myUser);
					domainFlash('已复制为一份草稿。请检查时间后再发布。');
					redirectTo(domainUrl($domain, "/homework/$id/manage"));
				},
				'delete' => function() use ($domain, $homework) {
					global $myUser;
					if ($homework['status'] !== 'draft' || DB::selectFirst("select 1 from submissions where homework_id = {$homework['id']} limit 1")) {
						return '只有没有提交的草稿可以删除';
					}
					foreach (array('homework_problems', 'homework_participants', 'homework_maintainers') as $table) {
						DB::delete("delete from $table where homework_id = {$homework['id']}");
					}
					DB::delete("delete from homeworks where id = {$homework['id']}");
					auditLog('homework.delete', 'homework', $homework['id'], array('title' => $homework['title']), null);
					domainFlash('草稿已删除。');
					redirectTo(domainUrl($domain, '/homeworks'));
				}
			);
		}
	}
	$error = domainHandleForms($forms, $here . ($tab === 'settings' ? '' : "?tab=$tab"));
	
	// ---- what the page shows
	$now = homeworkNow();
	if ($homework) {
		$phase = homeworkPhase($homework, $now);
		$problems = homeworkProblems($homework);
	}
	// the form: what was posted, or what the homework has, or for a new one the rules of the last homework of the domain
	if ($homework) {
		$form = $homework;
		$rule_rows = homeworkPenaltyRuleRows(homeworkPenaltyRules($homework));
	} else {
		$last = DB::selectFirst("select * from homeworks where domain_id = {$domain['id']} order by id desc limit 1", MYSQLI_ASSOC);
		$begin = strtotime(date('Y-m-d 08:00:00', $now + 86400));
		$form = array('title' => '', 'description_md' => '', 'begin_at' => date('Y-m-d H:i:s', $begin), 'penalty_since' => date('Y-m-d 23:59:00', $begin + 6 * 86400), 'end_at' => date('Y-m-d 23:59:00', $begin + 8 * 86400), 'claim_end_at' => null, 'allow_withdraw' => 1);
		$rule_rows = $last ? homeworkPenaltyRuleRows(homeworkPenaltyRules($last)) : array(array('after' => '0', 'unit' => 'hour', 'percent' => '80'), array('after' => '1', 'unit' => 'day', 'percent' => '60'));
		if ($last && $last['penalty_since'] === null) {
			$form['penalty_since'] = null;
		}
	}
	if ($error !== '' && isset($_POST['form']) && $_POST['form'] === 'save') {
		foreach (array('title', 'description_md', 'begin_at', 'end_at', 'claim_end_at') as $key) {
			$form[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? str_replace('T', ' ', $_POST[$key]) : '';
		}
		$form['penalty_since'] = isset($_POST['allow_late']) ? (isset($_POST['penalty_since']) ? str_replace('T', ' ', $_POST['penalty_since']) : '') : null;
		$form['allow_withdraw'] = isset($_POST['allow_withdraw']) ? 1 : 0;
		$rule_rows = array();
		if (isset($_POST['penalty_after']) && is_array($_POST['penalty_after'])) {
			foreach ($_POST['penalty_after'] as $index => $after) {
				$rule_rows[] = array('after' => (string)$after, 'unit' => isset($_POST['penalty_unit'][$index]) && $_POST['penalty_unit'][$index] === 'day' ? 'day' : 'hour', 'percent' => isset($_POST['penalty_percent'][$index]) ? (string)$_POST['penalty_percent'][$index] : '');
			}
		}
	}
	// the value of a field for a date and a time
	$local = function($time) {
		return $time === null || $time === '' ? '' : str_replace(' ', 'T', substr($time, 0, 16));
	};
?>
<?php echoDomainPageHeader($domain, 'homeworks', $homework ? $homework['title'] . ' - 管理' : '布置作业') ?>
<?php if ($homework): ?>
<?php $phase_name = homeworkPhaseName($phase); ?>
<div class="d-flex flex-wrap align-items-center mb-3">
	<h3 class="mr-auto mb-2"><?= HTML::escape($homework['title']) ?> <span class="badge <?= $phase_name[1] ?>" id="homework-phase"><?= $phase_name[0] ?></span></h3>
	<div class="mb-2">
		<a class="btn btn-outline-secondary btn-sm" href="<?= homeworkUrl($domain, $homework) ?>">查看作业</a>
		<a class="btn btn-outline-secondary btn-sm" href="<?= homeworkUrl($domain, $homework, '/scoreboard') ?>">成绩表</a>
	</div>
</div>
<ul class="nav nav-pills mb-3">
	<?php foreach ($tabs as $tab_id => $tab_name): ?>
	<li class="nav-item"><a class="nav-link<?= $tab === $tab_id ? ' active' : '' ?>" href="<?= $here ?><?= $tab_id === 'settings' ? '' : "?tab=$tab_id" ?>"><?= $tab_name ?></a></li>
	<?php endforeach ?>
</ul>
<?php else: ?>
<h3 class="mb-3">布置作业</h3>
<?php endif ?>
<?php echoDomainError($error) ?>

<?php if ($homework && $homework['status'] === 'publishing'): ?>
<div class="alert alert-info" id="homework-publishing">正在发布：作业用到的公开题正在复制到本域，并等待评测机准备数据。完成后会自动变为“已发布”，可以稍后刷新这个页面。</div>
<?php elseif ($homework && $homework['status'] === 'draft' && $homework['publish_error'] !== null): ?>
<div class="alert alert-danger" id="homework-publish-error">上次发布没有成功：<?= HTML::escape($homework['publish_error']) ?></div>
<?php endif ?>

<?php if ($tab === 'settings'): ?>
<form method="post" id="form-homework-settings">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="save" />
	<div class="form-group">
		<label for="input-title">标题</label>
		<input type="text" class="form-control" id="input-title" name="title" maxlength="200" required="required" value="<?= HTML::escape($form['title']) ?>" placeholder="例如：第 3 次作业 链表" />
	</div>
	<div class="form-group">
		<label for="input-description_md">说明</label>
		<textarea class="form-control" id="input-description_md" name="description_md" rows="4" placeholder="支持 Markdown。认领之前就能看到，不要在这里写题目内容。"><?= HTML::escape($form['description_md']) ?></textarea>
	</div>
	<div class="form-row">
		<div class="form-group col-md-4">
			<label for="input-begin_at">开始时间</label>
			<input type="datetime-local" class="form-control" id="input-begin_at" name="begin_at" required="required" value="<?= $local($form['begin_at']) ?>" />
			<small class="form-text text-muted">开始后，认领了作业的学生才能看到题目。</small>
		</div>
		<div class="form-group col-md-4">
			<label for="input-end_at">最终截止时间</label>
			<input type="datetime-local" class="form-control" id="input-end_at" name="end_at" required="required" value="<?= $local($form['end_at']) ?>" />
			<small class="form-text text-muted">到这个时间结算正式成绩，之后的提交只算订正。</small>
		</div>
		<div class="form-group col-md-4">
			<label for="input-claim_end_at">认领截止时间 <span class="text-muted">（可选）</span></label>
			<input type="datetime-local" class="form-control" id="input-claim_end_at" name="claim_end_at" value="<?= $local($form['claim_end_at']) ?>" />
			<small class="form-text text-muted">留空表示截止前都可以认领。</small>
		</div>
	</div>

	<div class="card mb-3" id="card-penalty">
		<div class="card-header">
			<div class="custom-control custom-switch">
				<input type="checkbox" class="custom-control-input" id="input-allow_late" name="allow_late"<?= $form['penalty_since'] !== null ? ' checked="checked"' : '' ?> />
				<label class="custom-control-label" for="input-allow_late">允许迟交，迟交的提交按比例计分</label>
			</div>
		</div>
		<div class="card-body" id="penalty-settings"<?= $form['penalty_since'] !== null ? '' : ' style="display:none"' ?>>
			<div class="form-row">
				<div class="form-group col-md-4">
					<label for="input-penalty_since">正常截止时间</label>
					<input type="datetime-local" class="form-control" id="input-penalty_since" name="penalty_since" value="<?= $local($form['penalty_since']) ?>" />
					<small class="form-text text-muted">在这之前提交按 100% 计分；之后到最终截止时间之间算迟交。</small>
				</div>
			</div>
			<label>迟交的计分规则</label>
			<table class="table table-sm uoj-penalty-rules" id="table-penalty-rules">
				<tbody>
					<?php foreach ($rule_rows as $rule_row): ?>
					<tr>
						<td class="uoj-penalty-text">迟交超过</td>
						<td><input type="text" class="form-control form-control-sm" name="penalty_after[]" value="<?= HTML::escape($rule_row['after']) ?>" /></td>
						<td>
							<select class="form-control form-control-sm" name="penalty_unit[]">
								<option value="hour"<?= $rule_row['unit'] === 'hour' ? ' selected="selected"' : '' ?>>小时</option>
								<option value="day"<?= $rule_row['unit'] === 'day' ? ' selected="selected"' : '' ?>>天</option>
							</select>
						</td>
						<td class="uoj-penalty-text">起，按</td>
						<td><input type="text" class="form-control form-control-sm" name="penalty_percent[]" value="<?= HTML::escape($rule_row['percent']) ?>" /></td>
						<td class="uoj-penalty-text">% 计分</td>
						<td><button type="button" class="btn btn-outline-danger btn-sm uoj-penalty-remove" title="删除这一段">&times;</button></td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
			<div class="mb-2">
				<button type="button" class="btn btn-outline-primary btn-sm" id="button-add-penalty-rule"><span class="glyphicon glyphicon-plus"></span> 添加一段</button>
				<span class="text-muted small ml-2">常用：</span>
				<button type="button" class="btn btn-link btn-sm uoj-penalty-preset" data-rules="0,hour,80">迟交统一 80%</button>
				<button type="button" class="btn btn-link btn-sm uoj-penalty-preset" data-rules="0,hour,80;1,day,60;2,day,40">每迟一天少 20%</button>
				<button type="button" class="btn btn-link btn-sm uoj-penalty-preset" data-rules="0,hour,100">迟交不扣分</button>
			</div>
			<div class="alert alert-secondary small mb-0" id="penalty-preview"></div>
		</div>
	</div>

	<div class="custom-control custom-checkbox mb-3">
		<input type="checkbox" class="custom-control-input" id="input-allow_withdraw" name="allow_withdraw"<?= $form['allow_withdraw'] ? ' checked="checked"' : '' ?> />
		<label class="custom-control-label" for="input-allow_withdraw">作业开始之前，允许学生取消认领</label>
	</div>
	<button type="submit" class="btn btn-primary" id="button-save-homework"><?= $homework ? '保存' : '创建' ?></button>
	<?php if (!$homework): ?>
	<a class="btn btn-link" href="<?= domainUrl($domain, '/homeworks') ?>">取消</a>
	<?php endif ?>
</form>
<?php if ($homework && $homework['settle_state'] === 'settled'): ?>
<p class="text-muted small mt-3">这个作业已经结算。修改时间和迟交规则不会改变已有的正式成绩；要按新的规则重新计算，请在“成绩与结算”里重新结算。</p>
<?php endif ?>

<script type="text/javascript">
$(document).ready(function() {
	var $rows = $('#table-penalty-rules tbody');
	function addRule(after, unit, percent) {
		var $row = $('<tr>' +
			'<td class="uoj-penalty-text">迟交超过</td>' +
			'<td><input type="text" class="form-control form-control-sm" name="penalty_after[]" /></td>' +
			'<td><select class="form-control form-control-sm" name="penalty_unit[]"><option value="hour">小时</option><option value="day">天</option></select></td>' +
			'<td class="uoj-penalty-text">起，按</td>' +
			'<td><input type="text" class="form-control form-control-sm" name="penalty_percent[]" /></td>' +
			'<td class="uoj-penalty-text">% 计分</td>' +
			'<td><button type="button" class="btn btn-outline-danger btn-sm uoj-penalty-remove" title="删除这一段">&times;</button></td>' +
			'</tr>');
		$row.find('[name="penalty_after[]"]').val(after);
		$row.find('[name="penalty_unit[]"]').val(unit);
		$row.find('[name="penalty_percent[]"]').val(percent);
		$rows.append($row);
	}
	function show(time) {
		return time ? time.replace('T', ' ') : '（未填写）';
	}
	// says in words what the rules mean, the way the students will read them
	function preview() {
		var rules = [];
		$rows.find('tr').each(function() {
			var after = parseFloat($(this).find('[name="penalty_after[]"]').val());
			var percent = parseFloat($(this).find('[name="penalty_percent[]"]').val());
			if (!isNaN(after) && !isNaN(percent)) {
				rules.push({hours: after * ($(this).find('[name="penalty_unit[]"]').val() == 'day' ? 24 : 1), percent: percent});
			}
		});
		rules.sort(function(a, b) { return a.hours - b.hours; });
		function span(hours) {
			return hours > 0 && hours % 24 == 0 ? (hours / 24) + ' 天' : hours + ' 小时';
		}
		var lines = [show($('#input-penalty_since').val()) + ' 前提交：按 100% 计分'];
		if (rules.length == 0 || rules[0].hours > 0) {
			lines.push('迟交' + (rules.length ? '不超过 ' + span(rules[0].hours) : '') + '：按 100% 计分');
		}
		for (var i = 0; i < rules.length; i++) {
			if (i + 1 < rules.length && rules[i].hours == 0) {
				lines.push('迟交不超过 ' + span(rules[i + 1].hours) + '：按 ' + rules[i].percent + '% 计分');
			} else if (i + 1 < rules.length) {
				lines.push('迟交 ' + span(rules[i].hours) + ' 到 ' + span(rules[i + 1].hours) + '：按 ' + rules[i].percent + '% 计分');
			} else {
				lines.push('迟交' + (rules[i].hours > 0 ? '超过 ' + span(rules[i].hours) : '') + '：按 ' + rules[i].percent + '% 计分');
			}
		}
		lines.push(show($('#input-end_at').val()) + ' 后提交：不计入正式成绩');
		$('#penalty-preview').html('<strong>学生会看到：</strong><br />' + $.map(lines, function(line) { return $('<span/>').text(line).html(); }).join('<br />'));
	}
	$('#input-allow_late').change(function() {
		$('#penalty-settings').toggle(this.checked);
		if (this.checked && !$('#input-penalty_since').val()) {
			$('#input-penalty_since').val($('#input-end_at').val());
		}
		preview();
	});
	$('#button-add-penalty-rule').click(function() {
		addRule('', 'hour', '');
		preview();
	});
	$rows.on('click', '.uoj-penalty-remove', function() {
		$(this).closest('tr').remove();
		preview();
	});
	$('.uoj-penalty-preset').click(function() {
		$rows.empty();
		$.each($(this).data('rules').split(';'), function(index, rule) {
			var parts = rule.split(',');
			addRule(parts[0], parts[1], parts[2]);
		});
		preview();
	});
	$('#form-homework-settings').on('input change', 'input, select', preview);
	preview();
});
</script>
<?php endif ?>

<?php if ($homework && $tab === 'problems'): ?>
<?php $is_draft = $homework['status'] === 'draft'; ?>
<?php if (!$problems): ?>
<div class="uoj-domain-empty mb-3">作业里还没有题目。</div>
<?php else: ?>
<div class="table-responsive">
	<table class="table table-hover uoj-domain-members" id="table-homework-problems">
		<thead>
			<tr>
				<th style="width:3em">#</th>
				<th>题目</th>
				<th style="width:16em">分值</th>
				<?php if ($is_draft): ?>
				<th style="width:9em"></th>
				<?php endif ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($problems as $index => $problem): ?>
			<tr>
				<td><?= chr(ord('A') + $index % 26) ?></td>
				<td>
					<a href="<?= $problem['owner_domain_id'] ? domainProblemUrl($domain, $problem['problem_id']) : '/problem/' . $problem['problem_id'] ?>">#<?= $problem['problem_id'] ?>. <?= $problem['title'] ?></a>
					<?php if (!$problem['owner_domain_id']): ?>
					<span class="badge badge-info" title="发布作业时会复制成本域的题目">全站公开题</span>
					<?php elseif ($problem['source_problem_id']): ?>
					<small class="text-muted">复制自 #<?= $problem['source_problem_id'] ?> v<?= $problem['source_data_version'] ?></small>
					<?php endif ?>
					<?php if (!$problem['required']): ?><span class="badge badge-light border">选做</span><?php endif ?>
				</td>
				<td>
					<?php if ($is_draft): ?>
					<form method="post" class="form-inline">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="update_problem" />
						<input type="hidden" name="problem_id" value="<?= $problem['problem_id'] ?>" />
						<input type="number" class="form-control form-control-sm mr-2" name="score" min="1" max="10000" value="<?= $problem['score'] ?>" style="width:5em" />
						<div class="custom-control custom-checkbox mr-2">
							<input type="checkbox" class="custom-control-input" id="input-optional-<?= $problem['problem_id'] ?>" name="optional"<?= $problem['required'] ? '' : ' checked="checked"' ?> />
							<label class="custom-control-label" for="input-optional-<?= $problem['problem_id'] ?>">选做</label>
						</div>
						<button type="submit" class="btn btn-outline-secondary btn-sm">保存</button>
					</form>
					<?php else: ?>
					<?= $problem['score'] ?> 分
					<?php endif ?>
				</td>
				<?php if ($is_draft): ?>
				<td class="text-right">
					<?php if ($index > 0): ?>
					<form method="post" class="d-inline">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="move_problem" />
						<input type="hidden" name="problem_id" value="<?= $problem['problem_id'] ?>" />
						<button type="submit" class="btn btn-outline-secondary btn-sm" title="上移"><span class="glyphicon glyphicon-arrow-up"></span></button>
					</form>
					<?php endif ?>
					<form method="post" class="d-inline">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="remove_problem" />
						<input type="hidden" name="problem_id" value="<?= $problem['problem_id'] ?>" />
						<button type="submit" class="btn btn-outline-danger btn-sm">移除</button>
					</form>
				</td>
				<?php endif ?>
			</tr>
			<?php endforeach ?>
		</tbody>
		<tfoot>
			<tr><td></td><td class="text-right text-muted">合计</td><td><strong><?= array_sum(array_column($problems, 'score')) ?> 分</strong></td><?php if ($is_draft): ?><td></td><?php endif ?></tr>
		</tfoot>
	</table>
</div>
<?php endif ?>

<?php if ($is_draft): ?>
<div class="card mb-3">
	<div class="card-body">
		<form method="post" class="form-inline" id="form-add-homework-problem">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_problem" />
			<label class="mr-2 mb-2" for="input-homework-problem-id">添加题目</label>
			<input type="text" class="form-control mr-2 mb-2" id="input-homework-problem-id" name="problem_id" pattern="[0-9]+" required="required" placeholder="题号" style="width:7em" />
			<input type="number" class="form-control mr-2 mb-2" name="score" min="1" max="10000" value="100" title="分值" style="width:6em" />
			<div class="custom-control custom-checkbox mr-3 mb-2">
				<input type="checkbox" class="custom-control-input" id="input-new-optional" name="optional" />
				<label class="custom-control-label" for="input-new-optional">选做</label>
			</div>
			<button type="submit" class="btn btn-primary mb-2">添加</button>
		</form>
		<small class="text-muted">可以用本域的题目和全站公开的题目。全站公开题在发布时会自动复制成本域的隐藏题，之后别人修改原题不会影响这次作业。</small>
	</div>
</div>
<form method="post" class="d-inline" onsubmit="return confirm('发布后学生就能看到这个作业并认领，题目列表不能再改。确定发布吗？');">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="publish" />
	<button type="submit" class="btn btn-success" id="button-publish-homework"<?= $problems ? '' : ' disabled="disabled"' ?>>发布作业</button>
</form>
<?php elseif ($homework['status'] === 'published' && $phase === 'upcoming'): ?>
<p class="text-muted">作业发布后题目列表不能修改。开始之前可以撤回发布，改好再重新发布。</p>
<form method="post" onsubmit="return confirm('撤回后学生暂时看不到这个作业，已认领的学生不受影响。确定吗？');">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="unpublish" />
	<button type="submit" class="btn btn-outline-secondary" id="button-unpublish-homework">撤回发布</button>
</form>
<?php elseif ($homework['status'] === 'published'): ?>
<p class="text-muted">作业已经开始，题目列表不能修改。题目本身（题面、数据）仍可以在题目管理里修改；修改数据后请到“成绩与结算”里重测。</p>
<?php endif ?>

<?php if ($can_teach): ?>
<hr />
<form method="post" class="d-inline">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="clone" />
	<button type="submit" class="btn btn-outline-secondary btn-sm" id="button-clone-homework">复制这个作业</button>
</form>
<?php if ($is_draft): ?>
<form method="post" class="d-inline" onsubmit="return confirm('确定要删除这份草稿吗？');">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="delete" />
	<button type="submit" class="btn btn-outline-danger btn-sm" id="button-delete-homework">删除草稿</button>
</form>
<?php endif ?>
<?php endif ?>
<?php endif ?>

<?php if ($homework && $tab === 'participants'): ?>
<?php
	$participants = homeworkParticipants($homework);
	$withdrawn = homeworkParticipants($homework, 'withdrawn');
	$unclaimed = homeworkUnclaimedMembers($homework);
	$maintainers = homeworkMaintainers($homework);
?>
<div class="row">
	<div class="col-lg-8">
		<h4 class="uoj-domain-section-title mt-0">已认领 <small class="text-muted">(<?= count($participants) ?>)</small></h4>
		<form method="post" class="form-inline mb-2" id="form-add-participant">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_participant" />
			<input type="text" class="form-control form-control-sm mr-2" name="username" maxlength="20" required="required" placeholder="用户名" />
			<button type="submit" class="btn btn-outline-primary btn-sm">加入作业</button>
		</form>
		<?php if (!$participants): ?>
		<p class="text-muted">还没有人认领。</p>
		<?php else: ?>
		<div class="uoj-homework-names" id="list-participants">
			<?php foreach ($participants as $username): ?>
			<form method="post" class="d-inline-block mr-2 mb-2" onsubmit="return confirm('把 <?= $username ?> 移出这个作业吗？已有的提交会保留。');">
				<?= HTML::hiddenToken() ?>
				<input type="hidden" name="form" value="remove_participant" />
				<input type="hidden" name="username" value="<?= $username ?>" />
				<span class="badge badge-light border p-2"><?= getUserLink($username) ?> <button type="submit" class="close ml-1" style="font-size:1rem" title="移出">&times;</button></span>
			</form>
			<?php endforeach ?>
		</div>
		<?php endif ?>

		<h4 class="uoj-domain-section-title">未认领的学生 <small class="text-muted">(<?= count($unclaimed) ?>)</small></h4>
		<?php if (!$unclaimed): ?>
		<p class="text-muted">域里的学生都认领了。</p>
		<?php else: ?>
		<p id="list-unclaimed">
			<?php foreach ($unclaimed as $username): ?>
			<span class="badge badge-light border p-2 mr-1 mb-1"><?= getUserLink($username) ?></span>
			<?php endforeach ?>
		</p>
		<form method="post" onsubmit="return confirm('把这 <?= count($unclaimed) ?> 位学生都加入作业吗？');">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_all" />
			<button type="submit" class="btn btn-outline-primary btn-sm" id="button-add-all-unclaimed">把他们全部加入作业</button>
			<small class="text-muted ml-2">没有认领的学生不在成绩表里；加入后，没交的题记 0 分。</small>
		</form>
		<?php endif ?>

		<?php if ($withdrawn): ?>
		<h4 class="uoj-domain-section-title">已退出 <small class="text-muted">(<?= count($withdrawn) ?>)</small></h4>
		<p>
			<?php foreach ($withdrawn as $username): ?>
			<span class="badge badge-light border p-2 mr-1 mb-1"><?= getUserLink($username) ?></span>
			<?php endforeach ?>
		</p>
		<?php endif ?>
	</div>
	<div class="col-lg-4">
		<h4 class="uoj-domain-section-title mt-0">维护者</h4>
		<p class="text-muted small">维护者可以管理这一个作业：改设置、管参与者、看成绩和提交、重新结算。本域的教师和管理员不用添加。</p>
		<ul class="list-group mb-2" id="list-maintainers">
			<?php foreach ($maintainers as $username): ?>
			<li class="list-group-item d-flex align-items-center py-2">
				<span class="mr-auto"><?= getUserLink($username) ?></span>
				<?php if ($can_teach): ?>
				<form method="post">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="remove_maintainer" />
					<input type="hidden" name="username" value="<?= $username ?>" />
					<button type="submit" class="btn btn-outline-danger btn-sm">移除</button>
				</form>
				<?php endif ?>
			</li>
			<?php endforeach ?>
			<?php if (!$maintainers): ?>
			<li class="list-group-item text-muted small">没有另外指定维护者</li>
			<?php endif ?>
		</ul>
		<?php if ($can_teach): ?>
		<form method="post" class="form-inline" id="form-add-maintainer">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="add_maintainer" />
			<input type="text" class="form-control form-control-sm mr-2" name="username" maxlength="20" required="required" placeholder="域内成员的用户名" />
			<button type="submit" class="btn btn-outline-primary btn-sm">添加</button>
		</form>
		<?php endif ?>
	</div>
</div>
<?php endif ?>

<?php if ($homework && $tab === 'scores'): ?>
<?php
	$official = homeworkOfficialScores($homework);
	$live = homeworkLiveScores($homework);
	$active_snapshot = homeworkActiveSnapshot($homework);
	$snapshots = homeworkSnapshots($homework);
	$drift = homeworkDataDrift($homework, $official !== null ? $official : $live);
	$unjudged = homeworkUnjudgedSubmissions($homework);
	// what the official scores would be now, where that is not what they are
	$stale = $official !== null && !$active_snapshot ? homeworkScoreDifferences($official, $live) : array();
	$titles = array();
	foreach ($problems as $problem) {
		$titles[(int)$problem['problem_id']] = $problem['title'];
	}
?>
<?php if ($homework['status'] !== 'published'): ?>
<div class="uoj-domain-empty">作业发布之后，这里显示成绩的结算情况。</div>
<?php else: ?>
<div class="card mb-3" id="card-settlement">
	<div class="card-body">
		<?php if ($homework['settle_state'] === 'settled'): ?>
		<?php $current = queryHomeworkSnapshot($homework['current_official_snapshot_id']); ?>
		<h5 class="card-title mb-1">正式成绩：第 <?= $current['version'] ?> 份快照</h5>
		<p class="text-muted mb-0">结算于 <?= $current['confirmed_at'] ?>。之后的提交、重测和对作业的修改都不会改变它。</p>
		<?php elseif ($homework['settle_state'] === 'waiting_judgements'): ?>
		<h5 class="card-title mb-1">等待评测</h5>
		<p class="text-muted mb-0" id="settlement-waiting">作业已截止，还有 <?= count($unjudged) ?> 份截止前的提交没有评完，评完后自动结算。如果一直评不完（例如评测机不在线），将在 <?= date('Y-m-d H:i', strtotime($homework['settle_waiting_since']) + homeworkSettleGrace()) ?> 之后用已评完的提交结算。</p>
		<?php elseif ($phase === 'ended'): ?>
		<h5 class="card-title mb-1">即将结算</h5>
		<p class="text-muted mb-0">作业已截止，正在结算。</p>
		<?php else: ?>
		<h5 class="card-title mb-1">尚未结算</h5>
		<p class="text-muted mb-0">作业在 <?= substr($homework['end_at'], 0, 16) ?> 截止时结算正式成绩。在那之前，成绩表显示的是按当前提交实时计算的成绩。</p>
		<?php endif ?>
	</div>
</div>

<?php if ($drift): ?>
<div class="alert alert-warning" id="alert-drift">
	<strong>题目数据在计分之后有过修改：</strong>
	<ul class="mb-0">
		<?php foreach ($drift as $problem_id => $info): ?>
		<li>#<?= $problem_id ?>. <?= $info['title'] ?>：当前数据是 v<?= $info['current'] ?>，有 <?= $info['scores'] ?> 份计分的提交是按 v<?= join('、v', $info['judged_with']) ?> 评测的。</li>
		<?php endforeach ?>
	</ul>
	<div class="mt-1">如果修改影响判题结果，请在下面重测对应题目<?= $homework['settle_state'] === 'settled' ? '并重新结算' : '' ?>。</div>
</div>
<?php endif ?>

<?php if ($stale): ?>
<div class="alert alert-info" id="alert-stale">
	按现在的提交和规则计算，有 <?= count($stale) ?> 处成绩与正式成绩不同（例如结算后才评完的提交、被重测过的提交，或修改过的分值和时间）。正式成绩不会自动改变；需要更新时请重新结算。
</div>
<?php endif ?>

<?php if ($active_snapshot): ?>
<div class="card border-primary mb-3" id="card-active-snapshot">
	<div class="card-header bg-primary text-white">正在重新结算：第 <?= $active_snapshot['version'] ?> 份快照</div>
	<div class="card-body">
		<p class="mb-2">原因：<?= HTML::escape($active_snapshot['reason']) ?> <small class="text-muted">（<?= $active_snapshot['created_by'] !== '' ? getUserLink($active_snapshot['created_by']) : '系统' ?>，<?= $active_snapshot['created_at'] ?>）</small></p>
		<?php if ($active_snapshot['status'] === 'rejudging'): ?>
		<p class="mb-2" id="snapshot-rejudging">正在重测：还有 <?= count($unjudged) ?> 份提交没有评完。评完后这里会显示新旧成绩的差异，确认之后才生效。在那之前，正式成绩保持不变。</p>
		<?php else: ?>
		<?php
			$candidate = homeworkSnapshotScores($active_snapshot['id']);
			$changes = homeworkScoreDifferences($official !== null ? $official : array(), $candidate);
			$candidate_rules = json_decode($active_snapshot['rules_json'], true);
		?>
		<?php if (!empty($candidate_rules['unjudged_submissions'])): ?>
		<div class="alert alert-warning py-2">有 <?= count($candidate_rules['unjudged_submissions']) ?> 份提交在计算时还没有评完，没有计入这份成绩。</div>
		<?php endif ?>
		<?php if (!$changes): ?>
		<p class="mb-2" id="snapshot-no-changes">新的成绩与现在的正式成绩完全相同。</p>
		<?php else: ?>
		<p class="mb-2">与现在的正式成绩相比，有 <?= count($changes) ?> 处变化：</p>
		<div class="table-responsive">
			<table class="table table-sm table-bordered" id="table-snapshot-changes" style="max-width:40em">
				<thead><tr><th>学生</th><th>题目</th><th>现在</th><th>新的</th></tr></thead>
				<tbody>
					<?php foreach (array_slice($changes, 0, 200) as $change): ?>
					<tr>
						<td><?= getUserLink($change['username']) ?></td>
						<td>#<?= $change['problem_id'] ?><?= isset($titles[$change['problem_id']]) ? '. ' . $titles[$change['problem_id']] : '' ?></td>
						<td><?= homeworkTrimNumber($change['before']) ?></td>
						<td class="<?= $change['after'] > $change['before'] ? 'text-success' : 'text-danger' ?>"><strong><?= homeworkTrimNumber($change['after']) ?></strong></td>
					</tr>
					<?php endforeach ?>
				</tbody>
			</table>
		</div>
		<?php endif ?>
		<?php endif ?>
		<?php if ($active_snapshot['status'] === 'candidate'): ?>
		<form method="post" class="d-inline" onsubmit="return confirm('确认后，这份成绩成为正式成绩，参与者会收到通知。确定吗？');">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="confirm" />
			<input type="hidden" name="snapshot_id" value="<?= $active_snapshot['id'] ?>" />
			<button type="submit" class="btn btn-primary" id="button-confirm-snapshot">确认，作为正式成绩</button>
		</form>
		<?php endif ?>
		<form method="post" class="d-inline" onsubmit="return confirm('放弃这次重新结算吗？正式成绩保持不变。');">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="discard" />
			<input type="hidden" name="snapshot_id" value="<?= $active_snapshot['id'] ?>" />
			<button type="submit" class="btn btn-outline-secondary" id="button-discard-snapshot">放弃</button>
		</form>
	</div>
</div>
<?php else: ?>
<div class="card mb-3">
	<div class="card-header"><?= $homework['settle_state'] === 'settled' ? '重测与重新结算' : '重测' ?></div>
	<div class="card-body">
		<form method="post" id="form-resettle">
			<?= HTML::hiddenToken() ?>
			<input type="hidden" name="form" value="resettle" />
			<div class="form-row">
				<div class="form-group col-md-4">
					<label for="input-scope">重测范围</label>
					<select class="form-control" id="input-scope" name="scope">
						<?php if ($homework['settle_state'] === 'settled'): ?>
						<option value="none">不重测，只按现在的提交重新计算</option>
						<?php endif ?>
						<?php foreach ($problems as $problem): ?>
						<option value="<?= $problem['problem_id'] ?>"<?= isset($drift[(int)$problem['problem_id']]) ? ' selected="selected"' : '' ?>>只重测 #<?= $problem['problem_id'] ?>. <?= HTML::escape(strip_tags($problem['title'])) ?></option>
						<?php endforeach ?>
						<option value="all">重测全部题目</option>
					</select>
				</div>
				<div class="form-group col-md-8">
					<label for="input-reason">原因</label>
					<input type="text" class="form-control" id="input-reason" name="reason" maxlength="500" required="required" placeholder="例如：第 17 个测试点的标准答案有误" />
				</div>
			</div>
			<button type="submit" class="btn btn-warning" id="button-resettle"><?= $homework['settle_state'] === 'settled' ? '开始重新结算' : '重测' ?></button>
			<small class="text-muted ml-2">只重测通过这个作业提交的记录，不影响这道题的其他提交。<?= $homework['settle_state'] === 'settled' ? '重测完成后会先给出新旧成绩的差异，确认之后才生效。' : '' ?></small>
		</form>
	</div>
</div>
<?php endif ?>

<?php if ($snapshots): ?>
<h4 class="uoj-domain-section-title">成绩快照</h4>
<div class="table-responsive">
	<table class="table table-sm" id="table-snapshots">
		<thead><tr><th style="width:5em">版本</th><th style="width:8em">状态</th><th>原因</th><th style="width:10em">发起人</th><th style="width:11em">时间</th><th style="width:5em"></th></tr></thead>
		<tbody>
			<?php foreach ($snapshots as $snapshot): ?>
			<?php
				$is_current = $snapshot['id'] == $homework['current_official_snapshot_id'];
				$status_names = array('rejudging' => '重测中', 'candidate' => '待确认', 'official' => $is_current ? '当前正式成绩' : '历史正式成绩', 'discarded' => '已放弃');
			?>
			<tr>
				<td>#<?= $snapshot['version'] ?></td>
				<td><span class="badge <?= $is_current ? 'badge-success' : ($snapshot['status'] === 'official' ? 'badge-secondary' : ($snapshot['status'] === 'discarded' ? 'badge-light border' : 'badge-primary')) ?>"><?= $status_names[$snapshot['status']] ?></span></td>
				<td><?= HTML::escape($snapshot['reason']) ?></td>
				<td><?= $snapshot['created_by'] !== '' ? getUserLink($snapshot['created_by']) : '<span class="text-muted">系统</span>' ?></td>
				<td><small><?= $snapshot['created_at'] ?></small></td>
				<td>
					<?php if ($snapshot['status'] !== 'rejudging'): ?>
					<a href="<?= homeworkUrl($domain, $homework, '/scoreboard') ?>?snapshot=<?= $snapshot['id'] ?>">查看</a>
					<?php endif ?>
				</td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
<?php endif ?>
<?php endif ?>
<?php echoUOJPageFooter() ?>
