<?php
	requirePHPLib('form');
	requirePHPLib('judger');
	requirePHPLib('data');
	requirePHPLib('problem');
	
	// the number in the address is the number of the problem where the address is: on the
	// site, or in a domain
	if (!($problem = problemOfPage())) {
		become404Page();
	}
	if (!can($myUser, 'problem.manage', $problem)) {
		become403Page();
	}
	
	$oj_name = UOJConfig::$data['profile']['oj-name'];
	$problem_extra_config = getProblemExtraConfig($problem);

	// a version that no judger has checked for a long time will never be checked
	$waiting_version = dataWaitingVersion($problem['id']);
	if ($waiting_version && time() - strtotime($waiting_version['created_at']) > SyncProblemDataHandler::STALE_SYNC_SECONDS) {
		dataFailVersion($waiting_version, 'no judger checked the data in time');
	}

	$data_dir = "/var/uoj_data/${problem['id']}";

	function echoFileNotFound($file_name) {
		echo '<h4>', htmlspecialchars($file_name), '<sub class="text-danger"> ', '文件未找到', '</sub></h4>';
	}
	function echoFilePre($file_name) {
		global $data_dir;
		$file_full_name = $data_dir . '/' . $file_name;

		$finfo = finfo_open(FILEINFO_MIME);
		$mimetype = finfo_file($finfo, $file_full_name);
		if ($mimetype === false) {
			echoFileNotFound($file_name);
			return;
		}
		finfo_close($finfo);

		echo '<h4>', htmlspecialchars($file_name), '<sub> ', $mimetype, '</sub></h4>';
		echo "<pre>\n";

		$output_limit = 1000;
		if (strStartWith($mimetype, 'text/')) {
			echo htmlspecialchars(uojFilePreview($file_full_name, $output_limit));
		} else {
			echo htmlspecialchars(uojFilePreview($file_full_name, $output_limit, 'binary'));
		}
		echo "\n</pre>";
	}


	$data_page = problemUrl($problem, '/manage/data');

	// ---- an archive of data is uploaded
	$upload_message = null;
	if ($_POST['problem_data_file_submit']=='submit') {
		crsf_defend();
		list($uploaded, $message) = problemTakeUploadedData($problem, 'problem_data_file', $myUser);
		if ($uploaded === 'refused') {
			becomeMsgPage('<div id="upload-refused">' . HTML::escape($message) . '</div><a href="'.$data_page.'">返回</a>');
		} elseif ($uploaded === 'none') {
			becomeMsgPage('<div id="upload-refused">请选择要上传的 zip 文件</div><a href="'.$data_page.'">返回</a>');
		}
		$upload_message = "上传成功！写入了 $message 个文件。";
		// Data that does not say how it is to be judged is set up from the names of its
		// files: when there is no problem.conf, and when the one there is was written
		// before there was any data. A problem.conf that speaks of tests is left alone.
		if (problemAwaitsSetup($problem)) {
			list($found, $err) = problemApplySettings($problem, problemSettingsOfConf(problemUploadedConf($problem)), $myUser);
			$upload_message .= $err === '' ? join('。', $found) . '。' : '没有能自动识别测试点：' . $err;
		} else {
			$upload_message .= '评测设置没有变。如果测试点的个数变了，在下面保存一次评测设置，测试点会重新识别。';
		}
	}

	// ---- the files of the problem, one by one
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	if (isset($_GET['download_file']) && is_string($_GET['download_file'])) {
		$path = problemFilePath($problem, $_GET['download_file']);
		if ($path === null) {
			become404Page();
		}
		$name = uojFileBaseName($_GET['download_file']);
		header("X-Sendfile: $path");
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
		header('X-Content-Type-Options: nosniff');
		die();
	}
	if (isset($_GET['download_all'])) {
		// everything that was uploaded, as it lies there: what a version of the data is made of
		$rows = problemDataFiles($problem);
		if (!$rows) {
			become404Page();
		}
		set_time_limit(600);
		$zip_name = tempnam(sys_get_temp_dir(), 'uoj_files_');
		$zip = new ZipArchive();
		if ($zip->open($zip_name, ZipArchive::OVERWRITE) !== true) {
			becomeMsgPage('无法打包这道题的文件');
		}
		foreach ($rows as $row) {
			$zip->addFile("$upload_dir/{$row['name']}", $row['name']);
		}
		$zip->close();
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="problem_' . problemNumber($problem) . '_files.zip"');
		header('Content-Length: ' . filesize($zip_name));
		readfile($zip_name);
		unlink($zip_name);
		die();
	}
	if (isset($_POST['form']) && in_array($_POST['form'], array('upload_files', 'delete_file', 'rename_file'), true)) {
		crsf_defend();
		$posted = function($name) {
			return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
		};
		if ($_POST['form'] === 'upload_files') {
			set_time_limit(600);
			list($written, $refused) = problemTakeUploadedFiles($problem, 'data_files', $myUser);
			$message = $written > 0 ? "写入了 $written 个文件。" : '没有写入文件。';
			if ($refused) {
				$message .= '没有接受：' . join('；', $refused) . '。';
			}
			// as when an archive is uploaded: data that does not say yet how it is to be judged
			// is set up from the names of its files
			if ($written > 0 && problemAwaitsSetup($problem)) {
				list($found, $err) = problemApplySettings($problem, problemSettingsOfConf(problemUploadedConf($problem)), $myUser);
				$message .= $err === '' ? join('。', $found) . '。' : '没有能自动识别测试点：' . $err;
			} elseif ($written > 0) {
				$message .= '评测设置没有变：检查下面的设置后点“保存并同步数据”，新的文件才会用于评测。';
			}
			domainFlash($message, $refused || $written == 0 ? 'warning' : 'success');
		} elseif ($_POST['form'] === 'delete_file') {
			$err = problemDeleteFile($problem, $posted('name'), $myUser);
			domainFlash($err === '' ? '已删除 ' . $posted('name') . '。点“保存并同步数据”后评测才不再用它。' : $err, $err === '' ? 'success' : 'danger');
		} else {
			$err = problemRenameFile($problem, $posted('name'), $posted('new_name'), $myUser);
			domainFlash($err === '' ? '已把 ' . $posted('name') . ' 改名为 ' . trim($posted('new_name')) . '。' : $err, $err === '' ? 'success' : 'danger');
		}
		redirectTo($data_page . '#card-data-files');
	}

	// ---- how the problem is judged
	// what saving the form would write, for the form to show while it is filled in
	if (isset($_GET['preview_conf'])) {
		header('Content-Type: application/json; charset=utf-8');
		list($checked, $err) = problemSettingsFromForm($_POST);
		if ($err === '') {
			list($plan, $err) = problemPlanSettings($problem, $checked);
		}
		die(json_encode($err === '' ? array('ok' => true, 'conf' => problemConfText($plan['conf']), 'notes' => $plan['notes']) : array('ok' => false, 'error' => $err), JSON_UNESCAPED_UNICODE));
	}
	// problem.conf as somebody wrote it
	$conf_text_error = '';
	if (isset($_POST['form']) && $_POST['form'] === 'conf_text') {
		crsf_defend();
		$conf_text_error = problemSaveConfText($problem, isset($_POST['conf_text']) ? $_POST['conf_text'] : null, $myUser);
		if ($conf_text_error === '') {
			$went_well = true;
			$note = '';
			if (problemUploadedTestCount($problem) > 0) {
				list($went_well, $note) = problemSync($problem, $myUser);
			}
			domainFlash('problem.conf 已保存。' . ($note !== '' ? $note . '。' : ''), $went_well ? 'success' : 'warning');
			redirectTo($data_page);
		}
	}
	$settings_error = '';
	if (isset($_POST['form']) && $_POST['form'] === 'judge_settings') {
		crsf_defend();
		// a problem with a judger of its own is set up by the problem.conf somebody wrote for it
		$conf_now = problemUploadedConf($problem);
		if (is_array($conf_now) && isset($conf_now['use_builtin_judger']) && $conf_now['use_builtin_judger'] !== 'on') {
			$settings_error = '这道题使用自己的评测程序，评测设置在它的 problem.conf 里';
		} else {
			list($checked, $settings_error) = problemSettingsFromForm($_POST);
		}
		if ($settings_error === '') {
			list($found, $settings_error) = problemApplySettings($problem, $checked, $myUser);
		}
		if ($settings_error === '') {
			$went_well = true;
			// the settings take effect when the data is published with them
			if (problemUploadedTestCount($problem) > 0) {
				list($went_well, $note) = problemSync($problem, $myUser);
				$found[] = $note;
			}
			domainFlash('评测设置已保存。' . join('。', $found) . '。', $went_well ? 'success' : 'warning');
			redirectTo($data_page);
		}
	}

	$info_form = new UOJForm('info');
	$http_host = HTML::escape(UOJContext::httpHost());
	$download_url = HTML::url("/download.php?type=problem&id={$problem['id']}");
	$info_form->appendHTML(<<<EOD
<div class="form-group row">
	<!--<label class="col-sm-3 control-label">zip上传数据</label>
	<div class="col-sm-9">
		<div class="form-control-static">
			<row>
			<button type="button" style="width:30%" class="btn btn-primary" data-toggle="modal" data-target="#UploadDataModal">上传数据</button>
			<button type="submit" style="width:30%" id="button-submit-data" name="submit-data" value="data" class="btn btn-danger">检验配置并同步数据</button>
			</row>
		</div>
	</div>-->
</div>
EOD
	);
	$info_form->appendHTML(<<<EOD
<div class="form-group row">
	<label class="col-sm-3 control-label">problem_{$problem['id']}.zip</label>
	<div class="col-sm-9">
		<div class="form-control-static">
			<a href="$download_url">$download_url</a>
		</div>
	</div>
</div>
EOD
	);
	$info_form->appendHTML(<<<EOD
<div class="form-group row">
	<label class="col-sm-3 control-label">testlib.h</label>
	<div class="col-sm-9">
		<div class="form-control-static">
			<a href="/download.php?type=testlib.h">下载</a>
		</div>
	</div>
</div>
EOD
	);

	$esc_submission_requirement = HTML::escape(json_encode(json_decode($problem['submission_requirement']), JSON_PRETTY_PRINT));
	$info_form->appendHTML(<<<EOD
<div class="form-group row">
	<label class="col-sm-3 control-label">提交文件配置</label>
	<div class="col-sm-9">
		<div class="form-control-static"><pre>
$esc_submission_requirement
</pre>
		</div>
	</div>
</div>
EOD
	);
	$esc_extra_config = HTML::escape(json_encode(json_decode($problem['extra_config']), JSON_PRETTY_PRINT));
	$info_form->appendHTML(<<<EOD
<div class="form-group row">
	<label class="col-sm-3 control-label">其它配置</label>
	<div class="col-sm-9">
		<div class="form-control-static"><pre>
$esc_extra_config
</pre>
		</div>
	</div>
</div>
EOD
	);
	if (can($myUser, 'problem.edit_raw_config', $problem)) {
		$info_form->addVInput('submission_requirement', 'text', '提交文件配置', $problem['submission_requirement'],
			function ($submission_requirement, &$vdata) {
				$submission_requirement = json_decode($submission_requirement, true);
				if ($submission_requirement === null) {
					return '不是合法的JSON';
				}
				$vdata['submission_requirement'] = json_encode($submission_requirement);
			},
			null);
		$info_form->addVInput('extra_config', 'text', '其它配置', $problem['extra_config'],
			function ($extra_config, &$vdata) {
				$extra_config = json_decode($extra_config, true);
				if ($extra_config === null) {
					return '不是合法的JSON';
				}
				$vdata['extra_config'] = json_encode($extra_config);
			},
			null);
		$info_form->handle = function(&$vdata) {
			global $problem;
			$esc_submission_requirement = DB::escape($vdata['submission_requirement']);
			$esc_extra_config = DB::escape($vdata['extra_config']);
			DB::update("update problems set submission_requirement = '$esc_submission_requirement', extra_config = '$esc_extra_config' where id = {$problem['id']}");
			auditLog('problem.edit_raw_config', 'problem', $problem['id'],
				array('submission_requirement' => $problem['submission_requirement'], 'extra_config' => $problem['extra_config']),
				array('submission_requirement' => $vdata['submission_requirement'], 'extra_config' => $vdata['extra_config']));
		};
	} else {
		$info_form->no_submit = true;
	}

	class DataDisplayer {
		public $problem_conf = array();
		public $data_files = array();
		public $displayers = array();

		public function __construct($problem_conf = null, $data_files = null) {
			global $data_dir;

			if (isset($problem_conf)) {
				foreach ($problem_conf as $key => $val) {
					$this->problem_conf[$key] = array('val' => $val);
				}
			}

			if (!isset($data_files)) {
				$this->data_files = array_filter(scandir($data_dir), function($x) {
					return $x !== '.' && $x !== '..' && $x !== 'problem.conf';
				});
				natsort($this->data_files);
				array_unshift($this->data_files, 'problem.conf');
			} else {
				$this->data_files = $data_files;
			}

			$this->setDisplayer('problem.conf', function($self) {
				global $info_form;
				$info_form->printHTML();
				echo '<div class="top-buffer-md"></div>';

				echo '<table class="table table-bordered table-hover table-striped table-text-center">';
				echo '<thead>';
				echo '<tr>';
				echo '<th>key</th>';
				echo '<th>value</th>';
				echo '</tr>';
				echo '</thead>';
				echo '<tbody>';
				foreach ($self->problem_conf as $key => $info) {
					if (!isset($info['status'])) {
						echo '<tr>';
						echo '<td>', htmlspecialchars($key), '</td>';
						echo '<td>', htmlspecialchars($info['val']), '</td>';
						echo '</tr>';
					} elseif ($info['status'] == 'danger') {
						echo '<tr class="text-danger">';
						echo '<td>', htmlspecialchars($key), '</td>';
						echo '<td>', htmlspecialchars($info['val']), ' <span class="glyphicon glyphicon-remove"></span>', '</td>';
						echo '</tr>';
					}
				}
				echo '</tbody>';
				echo '</table>';

				echoFilePre('problem.conf');
			});
		}

		public function setProblemConfRowStatus($key, $status) {
			$this->problem_conf[$key]['status'] = $status;
			return $this;
		}

		public function setDisplayer($file_name, $fun) {
			$this->displayers[$file_name] = $fun;
			return $this;
		}
		public function addDisplayer($file_name, $fun) {
			$this->data_files[] = $file_name;
			$this->displayers[$file_name] = $fun;
			return $this;
		}
		public function echoDataFilesList($active_file) {
			foreach ($this->data_files as $file_name) {
				echo '<li class="nav-item">';
				if ($file_name != $active_file) {
					echo '<a class="nav-link" href="#">';
				} else {
					echo '<a class="nav-link active" href="#">';
				}
				echo htmlspecialchars($file_name), '</a>', '</li>';
			}
		}
		public function displayFile($file_name) {
			global $data_dir;

			if (isset($this->displayers[$file_name])) {
				$fun = $this->displayers[$file_name];
				$fun($this);
			} elseif (in_array($file_name, $this->data_files)) {
				echoFilePre($file_name);
			} else {
				echoFileNotFound($file_name);
			}
		}
	}

	function getDataDisplayer() {
		global $data_dir;
		global $problem;

		$allow_files = array_flip(array_filter(scandir($data_dir), function($x) {
			return $x !== '.' && $x !== '..';
		}));

		$getDisplaySrcFunc = function($name) use ($allow_files) {
			return function() use ($name, $allow_files) {
				$findSourceFile = function($name) use ($allow_files) {
					$found = false;
					foreach (array_keys($allow_files) as $file_name) {
						if (strpos($file_name, $name) === 0) {
							$rest = substr($file_name, strlen($name));
							if (strlen($rest) > 0 && ($rest[0] === '.' || is_numeric($rest[0]))) {
								return $file_name;
							}
						}
					}
					return null;
				};

				$src_name = $findSourceFile($name);
				if (isset($src_name)) {
					echoFilePre($src_name);
				} else {
					echoFileNotFound($src_name);
				}
				// the program itself is built by the judgers, it is only part of data published long ago
				if (isset($allow_files[$name])) {
					echoFilePre($name);
				}
			};
		};

		$problem_conf = getUOJConf("$data_dir/problem.conf");
		if ($problem_conf === -1) {
			return (new DataDisplayer())->setDisplayer('problem.conf', function() {
				global $info_form;
				$info_form->printHTML();
				echoFileNotFound('problem.conf');
			});
		}
		if ($problem_conf === -2) {
			return (new DataDisplayer())->setDisplayer('problem.conf', function() {
				global $info_form;
				$info_form->printHTML();
				echo '<h4 class="text-danger">problem.conf 格式有误</h4>';
				echoFilePre('problem.conf');
			});
		}

		$judger_name = getUOJConfVal($problem_conf, 'use_builtin_judger', null);
		if (!isset($problem_conf['use_builtin_judger'])) {
			return new DataDisplayer($problem_conf);
		}
		if ($problem_conf['use_builtin_judger'] == 'on') {
			$n_tests = getUOJConfVal($problem_conf, 'n_tests', 10);
			if (!validateUInt($n_tests)) {
				return (new DataDisplayer($problem_conf))->setProblemConfRowStatus('n_tests', 'danger');
			}

			$has_extra_tests = !(isset($problem_conf['submit_answer']) && $problem_conf['submit_answer'] == 'on');

			$data_disp = new DataDisplayer($problem_conf, array('problem.conf'));
			$data_disp->addDisplayer('tests',
				function($self) use ($problem_conf, $allow_files, $n_tests, $n_ex_tests) {
					for ($num = 1; $num <= $n_tests; $num++) {
						$input_file_name = getUOJProblemInputFileName($problem_conf, $num);
						$output_file_name = getUOJProblemOutputFileName($problem_conf, $num);
						echo '<div class="row">';
						echo '<div class="col-md-6">';
						if (isset($allow_files[$input_file_name])) {
							echoFilePre($input_file_name);
						} else {
							echoFileNotFound($input_file_name);
						}
						echo '</div>';
						echo '<div class="col-md-6">';
						if (isset($allow_files[$output_file_name])) {
							echoFilePre($output_file_name);
						} else {
							echoFileNotFound($output_file_name);
						}
						echo '</div>';
						echo '</div>';
					}
				}
			);
			if ($has_extra_tests) {
				$n_ex_tests = getUOJConfVal($problem_conf, 'n_ex_tests', 0);
				if (!validateUInt($n_ex_tests)) {
					return (new DataDisplayer($problem_conf))->setProblemConfRowStatus('n_ex_tests', 'danger');
				}

				$data_disp->addDisplayer('extra tests',
					function($self) use ($problem_conf, $allow_files, $n_tests, $n_ex_tests) {
						for ($num = 1; $num <= $n_ex_tests; $num++) {
							$input_file_name = getUOJProblemExtraInputFileName($problem_conf, $num);
							$output_file_name = getUOJProblemExtraOutputFileName($problem_conf, $num);
							echo '<div class="row">';
							echo '<div class="col-md-6">';
							if (isset($allow_files[$input_file_name])) {
								echoFilePre($input_file_name);
							} else {
								echoFileNotFound($input_file_name);
							}
							echo '</div>';
							echo '<div class="col-md-6">';
							if (isset($allow_files[$output_file_name])) {
								echoFilePre($output_file_name);
							} else {
								echoFileNotFound($output_file_name);
							}
							echo '</div>';
							echo '</div>';
						}
					}
				);
			}
			
			if (!isset($problem_conf['interaction_mode'])) {
				if (isset($problem_conf['use_builtin_checker'])) {
					$data_disp->addDisplayer('checker', function($self) {
						echo '<h4>use builtin checker : ', $self->problem_conf['use_builtin_checker']['val'], '</h4>';
					});
				} else {
					$data_disp->addDisplayer('checker', $getDisplaySrcFunc('chk'));
				}
			}
			if ($problem['hackable']) {
				$data_disp->addDisplayer('standard', $getDisplaySrcFunc('std'));
				$data_disp->addDisplayer('validator', $getDisplaySrcFunc('val'));
			}
			if (isset($problem_conf['interaction_mode'])) {
				$data_disp->addDisplayer('interactor', $getDisplaySrcFunc('interactor'));
			}
			return $data_disp;
		} else {
			return (new DataDisplayer($problem_conf))->setProblemConfRowStatus('use_builtin_judger', 'danger');
		}
	}

	$data_disp = getDataDisplayer();

	if (isset($_GET['display_file'])) {
		if (!isset($_GET['file_name'])) {
			echoFileNotFound('');
		} else {
			$data_disp->displayFile($_GET['file_name']);
		}
		die();
	}

	$hackable_form = new UOJForm('hackable');
	$hackable_form->handle = function() {
		global $problem, $myUser;
		$problem['hackable'] = !$problem['hackable'];
		//$problem['hackable'] = 0;
		// the switch takes effect when the data that was built for it is published
		$ret = dataSyncProblemData($problem, $myUser, array('reason' => 'hackable'));
		if ($ret) {
			becomeMsgPage('<div>' . $ret . '</div><a href="'.problemUrl($problem, '/manage/data').'">返回</a>');
		}
	};
	$hackable_form->submit_button_config['class_str'] = 'btn btn-warning btn-block';
	$hackable_form->submit_button_config['text'] = $problem['hackable'] ? '禁止使用hack' : '允许使用hack';
	$hackable_form->submit_button_config['smart_confirm'] = '';

	$data_form = new UOJForm('data');
	$data_form->handle = function() {
		global $problem, $myUser;
		set_time_limit(60 * 5);
		$ret = dataSyncProblemData($problem, $myUser);
		if ($ret) {
			becomeMsgPage('<div>' . $ret . '</div><a href="'.problemUrl($problem, '/manage/data').'">返回</a>');
		}
	};
	$data_form->submit_button_config['class_str'] = 'btn btn-danger btn-block';
	$data_form->submit_button_config['text'] = '检验配置并同步数据';
	$data_form->submit_button_config['smart_confirm'] = '';
	
	$clear_data_form = new UOJForm('clear_data');
	$clear_data_form->handle = function() {
		global $problem;
		dataClearProblemData($problem);
		auditLog('problem.clear_data', 'problem', $problem['id']);
	};
	$clear_data_form->submit_button_config['class_str'] = 'btn btn-danger btn-block';
	$clear_data_form->submit_button_config['text'] = '清空题目数据';
	$clear_data_form->submit_button_config['smart_confirm'] = '';

	$rejudge_form = new UOJForm('rejudge');
	$rejudge_form->handle = function() {
		global $problem;
		rejudgeProblem($problem);
		auditLog('problem.rejudge', 'problem', $problem['id'], null, array('scope' => 'all'));
	};
	$rejudge_form->succ_href = "/submissions?problem_id={$problem['id']}";
	$rejudge_form->submit_button_config['class_str'] = 'btn btn-danger btn-block';
	$rejudge_form->submit_button_config['text'] = '重测该题';
	$rejudge_form->submit_button_config['smart_confirm'] = '';
	
	$rejudgege97_form = new UOJForm('rejudgege97');
	$rejudgege97_form->handle = function() {
		global $problem;
		rejudgeProblemGe97($problem);
		auditLog('problem.rejudge', 'problem', $problem['id'], null, array('scope' => 'score >= 97'));
	};
	$rejudgege97_form->succ_href = "/submissions?problem_id={$problem['id']}";
	$rejudgege97_form->submit_button_config['class_str'] = 'btn btn-danger btn-block';
	$rejudgege97_form->submit_button_config['text'] = '重测 >=97 的程序';
	$rejudgege97_form->submit_button_config['smart_confirm'] = '';
	
	$view_type_form = new UOJForm('view_type');
	$view_type_form->addVSelect('view_content_type',
		array('NONE' => '禁止',
				'SELF' => '仅自己',
				'ALL_AFTER_AC' => 'AC后',
				'ALL' => '所有人'
		),
		'查看提交文件:',
		$problem_extra_config['view_content_type']
	);
	$view_type_form->addVSelect('view_all_details_type',
		array('NONE' => '禁止',
				'SELF' => '仅自己',
				'ALL_AFTER_AC' => 'AC后',
				'ALL' => '所有人'
		),
		'查看全部详细信息:',
		$problem_extra_config['view_all_details_type']
	);
	$view_type_form->addVSelect('view_details_type',
		array('NONE' => '禁止',
				'SELF' => '仅自己',
				'ALL_AFTER_AC' => 'AC后',
				'ALL' => '所有人'
		),
		'查看测试点详细信息:',
		$problem_extra_config['view_details_type']
	);
	$view_type_form->handle = function() {
		global $problem, $problem_extra_config;
		$config = $problem_extra_config;
		$config['view_content_type'] = $_POST['view_content_type'];
		$config['view_all_details_type'] = $_POST['view_all_details_type'];
		$config['view_details_type'] = $_POST['view_details_type'];
		$esc_config = DB::escape(json_encode($config));
		DB::query("update problems set extra_config = '$esc_config' where id = '{$problem['id']}'");
		$view_types = array('view_content_type' => 0, 'view_all_details_type' => 0, 'view_details_type' => 0);
		auditLog('problem.edit_visibility', 'problem', $problem['id'], array_intersect_key($problem_extra_config, $view_types), array_intersect_key($config, $view_types));
	};
	$view_type_form->submit_button_config['class_str'] = 'btn btn-warning btn-block top-buffer-sm';
	
	if ($problem['hackable']) {
		$test_std_form = new UOJForm('test_std');
		$test_std_form->handle = function() {
			global $myUser, $problem;
			
			$user_std = queryUser('std');
			if (!$user_std) {
				becomeMsgPage('请建立"std"账号。');
			}
			
			$requirement = json_decode($problem['submission_requirement'], true);
			
			$zip_file_name = uojRandAvailableSubmissionFileName();
			$zip_file = new ZipArchive();
			if ($zip_file->open(UOJContext::storagePath().$zip_file_name, ZipArchive::CREATE) !== true) {
				becomeMsgPage('提交失败');
			}
		
			$content = array();
			$content['file_name'] = $zip_file_name;
			$content['config'] = array();
			foreach ($requirement as $req) {
				if ($req['type'] == "source code") {
					$content['config'][] = array("{$req['name']}_language", "C++");
				}
			}
		
			$tot_size = 0;
			foreach ($requirement as $req) {
				$zip_file->addFile("/var/uoj_data/{$problem['id']}/std.cpp", $req['file_name']);
				$tot_size += $zip_file->statName($req['file_name'])['size'];
			}
		
			$zip_file->close();
		
			$content['config'][] = array('validate_input_before_test', 'on');
			$content['config'][] = array('problem_id', $problem['id']);
			$esc_content = DB::escape(json_encode($content));
			$esc_language = DB::escape('C++');
		 	
			$result = array();
			$result['status'] = "Waiting";
			$result_json = json_encode($result);
			$is_hidden = $problem['is_hidden'] ? 1 : 0;
			
			DB::insert("insert into submissions (problem_id, submit_time, submitter, content, language, tot_size, status, result, is_hidden) values ({$problem['id']}, now(), '{$user_std['username']}', '$esc_content', '$esc_language', $tot_size, '{$result['status']}', '$result_json', $is_hidden)");
		};
		$test_std_form->succ_href = "/submissions?problem_id={$problem['id']}";
		$test_std_form->submit_button_config['class_str'] = 'btn btn-danger btn-block';
		$test_std_form->submit_button_config['text'] = '检验数据正确性';
		$test_std_form->runAtServer();
	}
	
	$hackable_form->runAtServer();
	$view_type_form->runAtServer();
	$data_form->runAtServer();
	$clear_data_form->runAtServer();
	$rejudge_form->runAtServer();
	$rejudgege97_form->runAtServer();
	$info_form->runAtServer();
?>
<?php
	$REQUIRE_LIB['dialog'] = '';
?>
<?php echoUOJPageHeader(HTML::stripTags($problem['title']) . ' - 数据 - 题目管理') ?>
<h1 class="page-header" align="center">#<?= problemNumber($problem) ?> : <?=$problem['title']?> 管理</h1>
<?php echoProblemManageTabs($problem, 'data') ?>
<?php $data_flash = domainTakeFlash(); ?>
<?php if ($data_flash): ?>
<div class="alert alert-<?= $data_flash[0] ?> text-left" role="alert" id="data-flash"><?= HTML::escape($data_flash[1]) ?></div>
<?php endif ?>
<?php if ($upload_message !== null): ?>
<div class="alert alert-success text-left" role="alert" id="upload-done"><?= HTML::escape($upload_message) ?></div>
<?php endif ?>

<?php
	$data_versions = DB::selectAll("select version, status, sha256, size, created_at, created_by, reason, message, judger_name from problem_data_versions where problem_id = {$problem['id']} order by version desc limit 20");
	$data_version_status_names = array('pending' => '等待评测机', 'preparing' => '评测机校验中', 'ready' => '已发布', 'failed' => '未发布');
	$data_version_reason_names = array('sync' => '同步数据', 'hackable' => '切换 hack', 'hack' => 'hack 成功', 'clear' => '清空数据', 'legacy' => '已有数据');
?>
<?php if ($data_versions && in_array($data_versions[0]['status'], array('pending', 'preparing'))): ?>
<div class="alert alert-info top-buffer-sm" role="alert">
	数据版本 <?= $data_versions[0]['version'] ?> 正在等待评测机编译题目附带的程序，通过后自动发布。在此之前评测仍使用已发布的数据。
</div>
<?php elseif ($data_versions && $data_versions[0]['status'] == 'failed'): ?>
<div class="alert alert-danger top-buffer-sm" role="alert">
	数据版本 <?= $data_versions[0]['version'] ?> 未能发布，评测仍使用之前发布的数据。
	<pre><?= HTML::escape($data_versions[0]['message']) ?></pre>
</div>
<?php endif ?>
<?php
	// what is wrong with the data as it lies there, before anybody syncs it
	$upload_dir = "/var/uoj_data/upload/{$problem['id']}";
	$upload_files = is_dir($upload_dir) ? array_values(array_diff(scandir($upload_dir), array('.', '..'))) : array();
	$preflight = uploadPreflight($upload_files, is_file("$upload_dir/problem.conf") ? getUOJConf("$upload_dir/problem.conf") : -1, (bool)$problem['hackable']);
?>
<div class="card mb-3 text-left <?= $preflight['errors'] ? 'border-danger' : ($preflight['warnings'] ? 'border-warning' : 'border-success') ?>" id="data-preflight">
	<div class="card-body py-2">
		<strong>数据检查：</strong>
		<?php if ($preflight['errors']): ?>
		<span class="text-danger" id="preflight-state">有问题，同步会失败</span>
		<?php elseif ($preflight['warnings']): ?>
		<span class="text-warning" id="preflight-state">可以同步，但有几处值得看一眼</span>
		<?php else: ?>
		<span class="text-success" id="preflight-state">文件齐全，可以同步</span>
		<?php endif ?>
		<?php if ($preflight['facts']): ?>
		<small class="text-muted ml-2"><?= HTML::escape(join('；', $preflight['facts'])) ?></small>
		<?php endif ?>
		<?php if ($preflight['errors'] || $preflight['warnings']): ?>
		<ul class="mb-0 mt-1">
			<?php foreach ($preflight['errors'] as $line): ?>
			<li class="text-danger"><?= HTML::escape($line) ?></li>
			<?php endforeach ?>
			<?php foreach ($preflight['warnings'] as $line): ?>
			<li><?= HTML::escape($line) ?></li>
			<?php endforeach ?>
		</ul>
		<?php endif ?>
		<small class="text-muted d-block mt-1">上传文件 → 在评测设置里选好 → 保存并同步数据：这里没有红字，数据就会发布。这里只检查文件是否齐全；校验器等程序能否编译，由评测机在同步时告诉你，结果在页面最上方和下面的“数据版本”里。</small>
	</div>
</div>
<?php
	// The form that says how the problem is judged. A problem with a judger of its own says
	// that in a problem.conf somebody wrote, which no form knows how to write.
	$current_conf = is_file("$upload_dir/problem.conf") ? getUOJConf("$upload_dir/problem.conf") : null;
	$has_own_judger = is_array($current_conf) && isset($current_conf['use_builtin_judger']) && $current_conf['use_builtin_judger'] !== 'on';
	$judge_settings = problemSettingsOfConf($current_conf);
	if ($settings_error !== '') {
		foreach (array_merge(array('type', 'time_limit', 'memory_limit', 'checker', 'scoring'), array_keys(problemProgramFields())) as $field) {
			if (isset($_POST[$field]) && is_string($_POST[$field])) {
				$judge_settings[$field] = $_POST[$field];
			}
		}
		if (!isset(problemTypes()[$judge_settings['type']])) {
			$judge_settings['type'] = 'traditional';
		}
		if (isset($_POST['passes']) && is_string($_POST['passes']) && validateUInt($_POST['passes'])) {
			$judge_settings['passes'] = (int)$_POST['passes'];
		}
	}
	// the files that were uploaded, and what each of them is to the problem
	$data_files = problemDataFiles($problem);
	$data_file_names = array_column($data_files, 'name');
	$data_file_roles = problemFileRoles($data_file_names, $current_conf);
	$data_files_bytes = array_sum(array_column($data_files, 'size'));
	$readable_size = function($bytes) {
		return $bytes < 1024 ? $bytes . ' B' : ($bytes < 1048576 ? round($bytes / 1024, 1) . ' KB' : round($bytes / 1048576, 1) . ' MB');
	};
	$upload_limits = uploadLimits();
	$max_file_uploads = max(1, (int)ini_get('max_file_uploads'));
	// problem.conf as it is, and as it was typed when it was refused
	$conf_text = $conf_text_error !== '' && isset($_POST['conf_text']) && is_string($_POST['conf_text']) ? $_POST['conf_text'] : problemConfText(is_array($current_conf) ? $current_conf : array());
?>
<div class="card mb-3 text-left" id="card-data-files">
	<div class="card-header d-flex flex-wrap justify-content-between align-items-center">
		<span>数据文件 <small class="text-muted"><?= count($data_files) ?> 个，<?= $readable_size($data_files_bytes) ?></small></span>
		<span>
			<button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#UploadDataModal" id="button-upload-files"><span class="glyphicon glyphicon-upload"></span> 上传文件</button>
			<?php if ($data_files): ?>
			<a class="btn btn-sm btn-outline-secondary" href="<?= $data_page ?>?download_all=1" id="link-download-all-files"><span class="glyphicon glyphicon-download-alt"></span> 下载全部</a>
			<?php endif ?>
		</span>
	</div>
	<?php if (!$data_files): ?>
	<div class="card-body text-muted" id="data-files-empty">还没有文件。把测试数据、校验器等文件传上来：可以一次选很多个文件，也可以传一个 zip 压缩包。</div>
	<?php else: ?>
	<div class="uoj-data-files">
		<table class="table table-sm table-hover mb-0" id="table-data-files">
			<thead><tr><th>文件名</th><th style="width:7em">大小</th><th style="width:13em">用途</th><th style="width:7em"></th></tr></thead>
			<tbody>
				<?php foreach ($data_files as $file): ?>
				<tr data-name="<?= HTML::escape($file['name']) ?>">
					<td class="uoj-data-file-name"><a href="<?= $data_page ?>?download_file=<?= rawurlencode($file['name']) ?>" title="下载"><?= HTML::escape($file['name']) ?></a></td>
					<td><?= $readable_size($file['size']) ?></td>
					<td><?php if (isset($data_file_roles[$file['name']])): ?><span class="badge badge-light border"><?= HTML::escape($data_file_roles[$file['name']]) ?></span><?php else: ?><small class="text-muted">没有用到</small><?php endif ?></td>
					<td class="text-right text-nowrap">
						<button type="button" class="btn btn-link btn-sm p-0 mr-2 uoj-file-rename" title="改名，或者移进 require、download 文件夹"><span class="glyphicon glyphicon-pencil"></span></button>
						<button type="button" class="btn btn-link btn-sm p-0 text-danger uoj-file-delete" title="删除"><span class="glyphicon glyphicon-trash"></span></button>
					</td>
				</tr>
				<?php endforeach ?>
			</tbody>
		</table>
	</div>
	<?php endif ?>
	<div class="card-footer text-muted small">
		测试点成对即可（<code>1.in</code> 和 <code>1.out</code>、<code>input1.txt</code> 和 <code>output1.txt</code>……），文件名以 <code>sample</code> 或 <code>ex_</code> 开头的是样例和额外测试点；校验器、交互器叫什么都行，在下面的评测设置里选。上传的文件加进已有的文件里，同名的被替换。改了文件之后，点下面的“保存并同步数据”才用于评测。
	</div>
</div>
<form method="post" id="form-file-action" style="display:none">
	<?= HTML::hiddenToken() ?>
	<input type="hidden" name="form" value="" />
	<input type="hidden" name="name" value="" />
	<input type="hidden" name="new_name" value="" />
</form>
<script type="text/javascript">
// one form for every row of the files: which file is meant is said when it is sent
$(document).ready(function() {
	var act = function(form, name, new_name) {
		var f = $('#form-file-action');
		f.find('input[name=form]').val(form);
		f.find('input[name=name]').val(name);
		f.find('input[name=new_name]').val(new_name || '');
		f.submit();
	};
	$('#table-data-files .uoj-file-delete').on('click', function() {
		var name = $(this).closest('tr').attr('data-name');
		if (confirm('删除 ' + name + ' 吗？')) {
			act('delete_file', name);
		}
	});
	$('#table-data-files .uoj-file-rename').on('click', function() {
		var name = $(this).closest('tr').attr('data-name');
		var new_name = prompt('新的文件名。写成 require/名字 或 download/名字 就是把它移进那个文件夹。', name);
		if (new_name !== null && $.trim(new_name) !== '' && $.trim(new_name) !== name) {
			act('rename_file', name, new_name);
		}
	});
});
</script>

<div class="card mb-3 text-left" id="card-judge-settings">
	<div class="card-header">评测设置</div>
	<div class="card-body">
		<div class="row">
			<div class="col-lg-5 mb-3" id="pane-problem-conf">
				<form method="post" id="form-conf-text">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="conf_text" />
					<div class="d-flex justify-content-between align-items-center mb-1">
						<strong>problem.conf</strong>
						<div class="custom-control custom-switch">
							<input type="checkbox" class="custom-control-input" id="switch-edit-conf"<?= $has_own_judger || $conf_text_error !== '' ? ' checked="checked"' : '' ?><?= $has_own_judger ? ' disabled="disabled"' : '' ?> />
							<label class="custom-control-label" for="switch-edit-conf">直接编辑</label>
						</div>
					</div>
					<?php if ($conf_text_error !== ''): ?>
					<div class="alert alert-danger py-2" role="alert" id="conf-text-error"><?= HTML::escape($conf_text_error) ?></div>
					<?php endif ?>
					<textarea class="form-control uoj-conf-text" id="conf-text" name="conf_text" rows="18" spellcheck="false"<?= $has_own_judger || $conf_text_error !== '' ? '' : ' readonly="readonly"' ?>><?= HTML::escape($conf_text) ?></textarea>
					<small class="form-text" id="conf-preview-state"></small>
					<button type="submit" class="btn btn-primary btn-sm mt-2" id="button-save-conf-text">保存 problem.conf 并同步数据</button>
					<small class="form-text text-muted" id="conf-text-hint">
						这是评测机读的配置，每行一个“键 值”。平时不用管它：右边的选项改了，这里跟着变，保存之后才写进题目。
						选项里没有的设置（个别测试点的时限 <code>time_limit_3 5</code>、子任务依赖 <code>subtask_dependence_2 1</code> 等）打开“直接编辑”写在这里；之后再用右边的选项保存，这些行也会保留。
					</small>
				</form>
			</div>
			<div class="col-lg-7">
				<?php if ($has_own_judger): ?>
				<p class="mb-0 text-muted" id="judge-settings-own-judger">这道题使用自己的评测程序（problem.conf 里 <code>use_builtin_judger</code> 不是 <code>on</code>），怎么评测由它的 problem.conf 和 Makefile 决定，这里没有可选的设置。要改，直接编辑左边的 problem.conf。</p>
				<?php else: ?>
				<?php if ($settings_error !== ''): ?>
				<div class="alert alert-danger" role="alert" id="judge-settings-error"><?= HTML::escape($settings_error) ?></div>
				<?php endif ?>
				<form method="post" id="form-judge-settings">
					<?= HTML::hiddenToken() ?>
					<input type="hidden" name="form" value="judge_settings" />
					<fieldset id="fieldset-judge-settings">
						<?php uojIncludeView('problem-settings-form', array('settings' => $judge_settings, 'files' => problemSourceFiles(problemUploadedNames($upload_dir)), 'hackable' => (bool)$problem['hackable'])) ?>
						<?php if ($settings_error !== '' && isset($_POST['subtasks']) && is_string($_POST['subtasks'])): ?>
						<script type="text/javascript">$('textarea[name=subtasks]').val(<?= json_encode($_POST['subtasks']) ?>);</script>
						<?php endif ?>
						<button type="submit" class="btn btn-primary" id="button-save-judge-settings">保存并同步数据</button>
						<small class="form-text text-muted">
							保存时按文件名重新识别测试点，写出左边的 problem.conf，然后把数据交给评测机校验、发布。
						</small>
					</fieldset>
				</form>
				<?php endif ?>
			</div>
		</div>
	</div>
</div>
<script type="text/javascript">
// problem.conf beside the form: while the form is filled in it shows what saving would write;
// switched to be edited, it is what is saved, and the form stands still.
$(document).ready(function() {
	var text = $('#conf-text');
	var state = $('#conf-preview-state');
	var form = $('#form-judge-settings');
	var written = text.val();
	var asked = 0;
	var timer = null;
	// until something in the form is changed, problem.conf is shown as it is saved
	var touched = false;
	var editing = function() {
		return $('#switch-edit-conf').prop('checked');
	};
	var preview = function() {
		if (editing() || !form.length) {
			return;
		}
		var mine = ++asked;
		$.post('<?= $data_page ?>?preview_conf=1', form.serialize(), function(answer) {
			if (mine !== asked || editing()) {
				return;
			}
			if (answer.ok) {
				text.val(answer.conf);
				state.removeClass('text-danger').addClass(answer.conf === written ? 'text-muted' : 'text-info')
					.text((answer.conf === written ? '和已保存的一样。' : '还没有保存。') + answer.notes.join('；') + '。');
			} else {
				state.removeClass('text-muted text-info').addClass('text-danger').text(answer.error);
			}
		}, 'json');
	};
	var refresh = function() {
		var on = editing();
		text.prop('readonly', !on);
		$('#button-save-conf-text').toggle(on);
		$('#fieldset-judge-settings').prop('disabled', on);
		if (on) {
			state.removeClass('text-danger text-info').addClass('text-muted').text('正在直接编辑：右边的选项暂时不能用，保存的是这里写的内容。');
		} else if (touched) {
			preview();
		} else {
			state.removeClass('text-danger text-info').addClass('text-muted').text(form.length ? '这是已保存的 problem.conf。改右边的选项，这里显示保存之后的样子。' : '这是已保存的 problem.conf。');
		}
	};
	$('#switch-edit-conf').on('change', function() {
		// what the form would write comes back when the editing is given up
		if (!editing()) {
			text.val(written);
		}
		refresh();
	});
	form.on('change input', function() {
		touched = true;
		clearTimeout(timer);
		timer = setTimeout(preview, 250);
	});
	refresh();
});
</script>
<h4 class="text-left mt-4 mb-1" id="published-data">已发布的数据</h4>
<p class="text-left text-muted small mb-0">评测机现在用的是这一份：上面的文件和设置保存并同步之后，才会成为这里的内容。右边是对整道题的操作。</p>
<div class="row">
	<div class="col-md-10 top-buffer-sm">
		<div class="row">
			<div class="col-md-3 top-buffer-sm" id="div-file_list">
				<ul class="nav nav-pills flex-column">
					<?php $data_disp->echoDataFilesList('problem.conf'); ?>
				</ul>
			</div>
			<div class="col-md-9 top-buffer-sm" id="div-file_content">
				<?php $data_disp->displayFile('problem.conf'); ?>
			</div>
			<script type="text/javascript">
				curFileName = '';
				$('#div-file_list a').click(function(e) {
					$('#div-file_content').html('<h3>Loading...</h3>');
					$(this).tab('show');

					var fileName = $(this).text();
					curFileName = fileName;
					$.get('<?= problemUrl($problem, '/manage/data') ?>', {
							display_file: '',
							file_name: fileName
						},
						function(data) {
							if (curFileName != fileName) {
								return;
							}
							$('#div-file_content').html(data);
						},
						'html'
					);
					return false;
				});
			</script>
		</div>
	</div>
	<div class="col-md-2 top-buffer-sm">
		<div class="top-buffer-md">
			<?php if ($problem['hackable']): ?>
				<span class="glyphicon glyphicon-ok"></span> hack功能已启用
			<?php else: ?>
				<span class="glyphicon glyphicon-remove"></span> hack功能已禁止
			<?php endif ?>
			<?php $hackable_form->printHTML() ?>
		</div>
		<div class="top-buffer-md">
		<?php if ($problem['hackable']): ?>
			<?php $test_std_form->printHTML() ?>
		<?php endif ?>
		</div>
		<div class="top-buffer-md">
			<button id="button-display_view_type" type="button" class="btn btn-primary btn-block" onclick="$('#div-view_type').toggle('fast');">提交记录可视权限</button>
			<div class="top-buffer-sm" id="div-view_type" style="display:none; padding-left:5px; padding-right:5px;">
				<?php $view_type_form->printHTML(); ?>
			</div>
		</div>
		<div class="top-buffer-md">
			<?php $data_form->printHTML(); ?>
		</div>
		<div class="top-buffer-md">
			<?php $clear_data_form->printHTML(); ?>
		</div>
		<div class="top-buffer-md">
			<?php $rejudge_form->printHTML(); ?>
		</div>
		<div class="top-buffer-md">
			<?php $rejudgege97_form->printHTML(); ?>
		</div>

		<div class="top-buffer-md">
			<button type="button" class="btn btn-block btn-primary" data-toggle="modal" data-target="#UploadDataModal">上传文件</button>
		</div>
	</div>

	<div class="col-md-12 top-buffer-md">
		<h4>数据版本</h4>
		<table class="table table-bordered table-hover table-striped table-text-center">
			<thead>
				<tr>
					<th>版本</th>
					<th>状态</th>
					<th>时间</th>
					<th>操作者</th>
					<th>原因</th>
					<th>大小</th>
					<th>SHA256</th>
					<th>数据包</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($data_versions as $data_version): ?>
				<tr>
					<td><?= $data_version['version'] ?><?= $data_version['version'] == $problem['data_version'] ? '（当前）' : '' ?></td>
					<td><?= $data_version_status_names[$data_version['status']] ?></td>
					<td><?= $data_version['created_at'] ?></td>
					<td><?= $data_version['created_by'] !== '' ? HTML::escape($data_version['created_by']) : '系统' ?></td>
					<td><?= isset($data_version_reason_names[$data_version['reason']]) ? $data_version_reason_names[$data_version['reason']] : HTML::escape($data_version['reason']) ?></td>
					<td><?= $data_version['size'] ?></td>
					<td><code><?= substr($data_version['sha256'], 0, 16) ?></code></td>
					<td>
					<?php if (dataArchiveOfVersion(array('problem_id' => $problem['id']) + $data_version) !== null): ?>
						<a href="/download.php?type=problem-data&amp;id=<?= $problem['id'] ?>&amp;version=<?= $data_version['version'] ?>">下载</a>
					<?php endif ?>
					</td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
	<div class="modal fade" id="UploadDataModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<form action="<?= $data_page ?>" method="post" enctype="multipart/form-data" role="form" id="form-upload-files">
					<div class="modal-header">
						<h4 class="modal-title" id="myModalLabel">上传文件</h4>
						<button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
					</div>
					<div class="modal-body text-left">
						<?= HTML::hiddenToken() ?>
						<input type="hidden" name="form" value="upload_files" />
						<div class="form-group mb-2">
							<input type="file" class="form-control-file" name="data_files[]" id="input-data-files" multiple="multiple" required="required" />
						</div>
						<p class="text-danger small mb-2" id="upload-too-many" style="display:none">一次最多上传 <?= $max_file_uploads ?> 个文件：文件再多，请打成一个 zip 压缩包上传。</p>
						<p class="text-muted small mb-0">
							可以一次选很多个文件（最多 <?= $max_file_uploads ?> 个），它们以自己的名字保存；选一个 zip 压缩包，则把里面的文件解压出来，文件都放在一个文件夹里也可以。同名的文件被替换；要从头再来，先点“清空题目数据”。
							解压后这道题的数据不超过 <?= round($upload_limits['bytes'] / 1048576) ?> MB、<?= $upload_limits['files'] ?> 个文件。
						</p>
					</div>
					<div class="modal-footer">
						<button type="submit" class="btn btn-success" id="button-submit-upload">上传</button>
						<button type="button" class="btn btn-secondary" data-dismiss="modal">关闭</button>
					</div>
				</form>
			</div>
		</div>
	</div>
	<script type="text/javascript">
	// more files than the server takes in one go would be dropped without a word
	$('#input-data-files').on('change', function() {
		var many = this.files && this.files.length > <?= $max_file_uploads ?>;
		$('#upload-too-many').toggle(!!many);
		$('#button-submit-upload').prop('disabled', !!many);
	});
	</script>

</div>
<?php echoUOJPageFooter() ?>
