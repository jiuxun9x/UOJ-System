<?php
	// Actually, these things should be done by main_judger so that the code would be much simpler.
	// However, this lib exists due to some history issues.

	function dataNewProblem($id) {
		mkdir("/var/uoj_data/upload/$id");
		mkdir("/var/uoj_data/$id");

		// the archive of a problem that has no data yet. One that was there is replaced; for a
		// problem that was just made there is none, and that is nothing to complain about
		exec("cd /var/uoj_data; rm -f $id.zip; zip $id.zip $id -r -q");
	}

	// ---- versions of the data of a problem
	//
	// Every sync makes a new version. Its archive waits in the staging folder until a judger
	// has built the programs it comes with, and is published at once when there is nothing to
	// build. The web server itself never compiles or runs anything of a problem.

	function dataStageDir($problem_id) {
		return "/var/uoj_data/prepare_$problem_id";
	}
	function dataArchivePath($problem_id, $version) {
		return "/var/uoj_data/archive/$problem_id/$version.zip";
	}

	// the files below a folder: name => [size, SHA256]
	function dataManifest($dir) {
		$manifest = array();
		$dirs = array('');
		while ($dirs) {
			$cur = array_shift($dirs);
			foreach (scandir("$dir/$cur") as $name) {
				if ($name === '.' || $name === '..') {
					continue;
				}
				$path = "$dir/$cur$name";
				if (is_dir($path) && !is_link($path)) {
					$dirs[] = "$cur$name/";
				} elseif (is_file($path)) {
					$manifest["$cur$name"] = array(filesize($path), hash_file('sha256', $path));
				}
			}
		}
		ksort($manifest, SORT_STRING);
		return $manifest;
	}

	function queryProblemDataVersion($problem_id, $version) {
		return DB::selectFirst("select * from problem_data_versions where problem_id = $problem_id and version = $version");
	}
	function queryProblemDataVersionById($id) {
		return DB::selectFirst("select * from problem_data_versions where id = $id");
	}

	// Registers a new version of a problem and returns its row, or null when the archive is
	// missing. $fields are the escaped values of the other columns.
	function dataInsertVersion($problem_id, $archive, $manifest, $fields) {
		clearstatcache();
		if (!is_file($archive)) {
			return null;
		}
		$fields['sha256'] = hash_file('sha256', $archive);
		$fields['size'] = filesize($archive);
		$fields['manifest'] = DB::escape(json_encode($manifest));
		$fields['problem_id'] = $problem_id;
		for ($tries = 0; $tries < 10; $tries++) {
			$fields['version'] = 1 + (int)DB::selectFirst("select ifnull(max(version), 0) from problem_data_versions where problem_id = $problem_id", MYSQLI_NUM)[0];
			$columns = join(', ', array_keys($fields));
			$values = "'" . join("', '", array_values($fields)) . "'";
			// the number is taken when two requests register a version at the same time
			if (DB::insert("insert into problem_data_versions ($columns, created_at) values ($values, now())")) {
				return queryProblemDataVersion($problem_id, $fields['version']);
			}
		}
		return null;
	}

	// The version of the published data of a problem. Data that was published before versions
	// existed, or outside of a sync, is registered as it is: its programs are already built.
	function dataCurrentVersion($problem, $reason = 'legacy') {
		$id = $problem['id'];
		$data_version = DB::selectFirst("select data_version from problems where id = $id", MYSQLI_NUM)[0];
		if ($data_version > 0) {
			return queryProblemDataVersion($id, $data_version);
		}
		if (!is_dir("/var/uoj_data/$id")) {
			return null;
		}
		$row = dataInsertVersion($id, "/var/uoj_data/$id.zip", dataManifest("/var/uoj_data/$id"), array(
			'status' => 'ready',
			'prepare' => '[]',
			'created_by' => '',
			'reason' => DB::escape($reason)
		));
		if ($row == null) {
			return null;
		}
		DB::update("update problem_data_versions set published_at = created_at where id = {$row['id']}");
		DB::update("update problems set data_version = {$row['version']} where id = $id and data_version = 0");
		// another request may have registered the data at the same time
		$data_version = DB::selectFirst("select data_version from problems where id = $id", MYSQLI_NUM)[0];
		return queryProblemDataVersion($id, $data_version);
	}

	// the file to send to a judger that asks for a version of the data of a problem
	function dataArchiveOfVersion($version_row) {
		$id = $version_row['problem_id'];
		$candidates = array(
			"/var/uoj_data/$id.zip",
			dataArchivePath($id, $version_row['version']),
			dataStageDir($id) . "/data.zip"
		);
		// the files are moved while a version is published, so only the content tells them apart
		clearstatcache();
		foreach ($candidates as $file_name) {
			if (is_file($file_name) && filesize($file_name) == $version_row['size'] && hash_file('sha256', $file_name) === $version_row['sha256']) {
				return $file_name;
			}
		}
		return null;
	}

	function dataKeptVersions() {
		$kept = isset(UOJConfig::$data['data']['kept-versions']) ? (int)UOJConfig::$data['data']['kept-versions'] : 5;
		return max($kept, 1);
	}

	// keeps the archives of the most recent versions of a problem, the current one included
	function dataPruneArchives($problem_id) {
		$files = glob("/var/uoj_data/archive/$problem_id/*.zip");
		if (!$files) {
			return;
		}
		usort($files, function($a, $b) {
			return (int)basename($b, '.zip') - (int)basename($a, '.zip');
		});
		foreach (array_slice($files, dataKeptVersions() - 1) as $file_name) {
			unlink($file_name);
		}
	}

	// A successful hack that changes nothing must not go unnoticed: tell the people who can fix it.
	function notifyHackNotApplied($problem, $hack_id, $err) {
		$title = "Hack #$hack_id 成功，但题目 #{$problem['id']} 的数据未更新";
		$reason = HTML::escape(mb_substr(trim(strip_tags($err)), 0, 150, 'UTF-8'));
		if (mb_strlen($reason, 'UTF-8') > 200) {
			// the message is stored in 300 characters, do not cut an entity in two
			$reason = preg_replace('/&[^;]*$/', '', mb_substr($reason, 0, 200, 'UTF-8'));
		}
		$content = "新的 Extra Test 未生效，已通过的提交未重测。请检查数据后重新同步并重测。原因：$reason";

		$receivers = array();
		foreach (DB::selectAll("select username from problems_permissions where problem_id = {$problem['id']}") as $row) {
			$receivers[$row['username']] = true;
		}
		foreach (DB::selectAll("select username from user_info where usergroup = 'S'") as $row) {
			$receivers[$row['username']] = true;
		}
		foreach (array_keys($receivers) as $username) {
			sendSystemMsg($username, $title, $content);
		}
	}

	// Publishes a version that is waiting in the staging folder of its problem.
	function dataPublishVersion($version_row) {
		$id = $version_row['problem_id'];
		$stage_dir = dataStageDir($id);
		$prepare_dir = "$stage_dir/$id";
		$data_dir = "/var/uoj_data/$id";
		$pending = json_decode($version_row['pending'], true);

		if (!DB::update("update problem_data_versions set status = 'ready', published_at = now(), pending = null where id = {$version_row['id']} and status in ('pending', 'preparing')") || DB::affected_rows() != 1) {
			return;
		}
		$problem = queryProblemBrief($id);

		// Judgers check what they download against the SHA256 of the version they were told to
		// use, so they just try again when they ask in the middle of these renames.
		clearstatcache();
		$mtime = filemtime($prepare_dir);
		if ($problem['data_version'] > 0 && is_file("/var/uoj_data/$id.zip")) {
			if (!is_dir("/var/uoj_data/archive/$id")) {
				mkdir("/var/uoj_data/archive/$id", 0755, true);
			}
			rename("/var/uoj_data/$id.zip", dataArchivePath($id, $problem['data_version']));
		}
		rename("$stage_dir/data.zip", "/var/uoj_data/$id.zip");
		if (file_exists($data_dir)) {
			rename($data_dir, "$stage_dir/old");
		}
		rename($prepare_dir, $data_dir);
		// judgers that do not know versions yet compare modification times
		touch($data_dir, $mtime);
		exec("rm " . escapeshellarg($stage_dir) . " -rf");

		$set = array("data_version = {$version_row['version']}");
		if (isset($pending['hackable'])) {
			$set[] = "hackable = " . ($pending['hackable'] ? 1 : 0);
		}
		if (isset($pending['requirement']) && !json_decode($problem['submission_requirement'], true)) {
			$set[] = "submission_requirement = '" . DB::escape(json_encode($pending['requirement'])) . "'";
		}
		if (array_key_exists('custom_judger_fingerprint', (array)$pending)) {
			$extra_config = json_decode($problem['extra_config'], true);
			$extra_config = is_array($extra_config) ? $extra_config : array();
			if ($pending['custom_judger_fingerprint'] === null) {
				unset($extra_config['custom_judger_fingerprint']);
			} else {
				$extra_config['custom_judger_fingerprint'] = $pending['custom_judger_fingerprint'];
				// what was approved is this content, whichever problem it turns up in
				dataRegisterApprovedJudger($pending['custom_judger_fingerprint'], $version_row['created_by'], $id);
			}
			$set[] = "extra_config = '" . DB::escape(json_encode($extra_config)) . "'";
		}
		DB::update("update problems set " . join(', ', $set) . " where id = $id");

		dataPruneArchives($id);

		if (isset($pending['after']) && $pending['after'] == 'rejudge_ac') {
			rejudgeProblemAC($problem);
		}
	}

	// Gives up a version that is waiting in the staging folder of its problem.
	function dataFailVersion($version_row, $message) {
		if (!DB::update("update problem_data_versions set status = 'failed', pending = null, message = '" . DB::escape($message) . "' where id = {$version_row['id']} and status in ('pending', 'preparing')") || DB::affected_rows() != 1) {
			return;
		}
		exec("rm " . escapeshellarg(dataStageDir($version_row['problem_id'])) . " -rf");

		$pending = json_decode($version_row['pending'], true);
		if (isset($pending['hack_id'])) {
			$problem = queryProblemBrief($version_row['problem_id']);
			error_log("hack #{$pending['hack_id']} succeeded but its extra test was not added: $message");
			notifyHackNotApplied($problem, $pending['hack_id'], $message);
		}
	}

	// the version of a problem that waits for a judger, if any
	function dataWaitingVersion($problem_id) {
		return DB::selectFirst("select * from problem_data_versions where problem_id = $problem_id and status in ('pending', 'preparing') order by id desc limit 1");
	}

	class UOJProblemConfException extends Exception {
		public function __construct($message) {
			parent::__construct("<strong>problem.conf</strong> : $message");
		}
	}
	class UOJFileNotFoundException extends Exception {
		public function __construct($file_name) {
			parent::__construct("file <strong>" . htmlspecialchars($file_name) . '</strong> not found');
		}
	}

	function dataClearProblemData($problem) {
		$id = $problem['id'];
		if (!validateUInt($id)) {
			error_log("dataClearProblemData: hacker detected");
			return "invalid problem id";
		}

		$waiting = dataWaitingVersion($id);
		if ($waiting) {
			dataFailVersion($waiting, 'the data of the problem was cleared');
		}
		exec("rm " . escapeshellarg(dataStageDir($id)) . " -rf");
		exec("rm /var/uoj_data/upload/$id -r");
		exec("rm /var/uoj_data/$id -r");
		dataNewProblem($id);

		// judgers must not go on with the copy they have
		DB::update("update problems set data_version = 0 where id = $id");
		dataCurrentVersion($problem, 'clear');
	}

	// ---- approval of custom judgers by content
	//
	// A system administrator who syncs a problem with a custom judger approves what the files
	// are, not the row of the problem. The fingerprint below depends on nothing but the content
	// of the files and of problem.conf, so the same approval holds for a copy of the problem
	// with the same files, and for no copy in which anything was changed.
	function dataRegisterApprovedJudger($fingerprint, $approved_by, $problem_id) {
		if (!is_string($fingerprint) || !preg_match('/^[0-9a-f]{64}$/', $fingerprint)) {
			return;
		}
		DB::insert("insert ignore into approved_judger_fingerprints (fingerprint, approved_by, approved_at, problem_id) values ('$fingerprint', '".DB::escape($approved_by)."', now(), ".(int)$problem_id.")");
	}
	function dataIsApprovedJudger($fingerprint) {
		if (!is_string($fingerprint) || !preg_match('/^[0-9a-f]{64}$/', $fingerprint)) {
			return false;
		}
		return DB::selectFirst("select 1 from approved_judger_fingerprints where fingerprint = '$fingerprint'") != null;
	}

	// A fingerprint of everything the build of a custom judger can depend on: every uploaded
	// file except the extra tests, and problem.conf without the number of extra tests. Adding an
	// extra test, which is what a successful hack does, does not change it.
	function dataCustomJudgerFingerprint($upload_dir, $problem_conf) {
		$skip = array('problem.conf' => true);
		$n_ex_tests = getUOJConfVal($problem_conf, 'n_ex_tests', 0);
		if (validateUInt((string)$n_ex_tests)) {
			for ($num = 1; $num <= $n_ex_tests; $num++) {
				$skip[getUOJProblemExtraInputFileName($problem_conf, $num)] = true;
				$skip[getUOJProblemExtraOutputFileName($problem_conf, $num)] = true;
			}
		}

		$conf = $problem_conf;
		unset($conf['n_ex_tests']);
		ksort($conf);

		$ctx = hash_init('sha256');
		hash_update($ctx, json_encode($conf));
		$dirs = array('');
		while ($dirs) {
			$dir = array_shift($dirs);
			$names = scandir("$upload_dir/$dir");
			sort($names, SORT_STRING);
			foreach ($names as $name) {
				if ($name === '.' || $name === '..') {
					continue;
				}
				$rel = $dir . $name;
				if (isset($skip[$rel])) {
					continue;
				}
				$path = "$upload_dir/$rel";
				if (is_link($path)) {
					hash_update($ctx, "\0link\0$rel\0" . readlink($path));
				} elseif (is_dir($path)) {
					hash_update($ctx, "\0dir\0$rel");
					$dirs[] = "$rel/";
				} else {
					hash_update($ctx, "\0file\0$rel\0" . hash_file('sha256', $path));
				}
			}
		}
		return hash_final($ctx);
	}

	class SyncProblemDataHandler {
		// a sync that has not touched its staging folder for this long is considered dead
		const STALE_SYNC_SECONDS = 1800;

		private $problem, $user, $options;
		private $upload_dir, $data_dir, $stage_dir, $prepare_dir;
		private $requirement, $problem_extra_config;
		private $problem_conf, $final_problem_conf;
		private $allow_files;
		// what a judger has to build before the data can be used
		private $prepare_steps = array();

		public function __construct($problem, $user, $options = array()) {
			$this->problem = $problem;
			$this->user = $user;
			$this->options = $options;
		}

		private function check_conf_on($name) {
			return isset($this->problem_conf[$name]) && $this->problem_conf[$name] == 'on';
		}

		private function copy_source_files_to_prepare($name) {
			$found = false;
			foreach (array_keys($this->allow_files) as $file_name) {
				if (strpos($file_name, $name) === 0) {
					$rest = substr($file_name, strlen($name));
					if (strlen($rest) > 0 && ($rest[0] === '.' || is_numeric($rest[0])) && is_file("{$this->upload_dir}/$file_name")) {
						$this->copy_to_prepare($file_name);
						$found = true;
					}
				}
			}
			return $found;
		}
		// The source of one of the programs of the problem: the file problem.conf names for
		// it, given the name the judgers look for, or else the files that have that name.
		private function copy_program_source($name) {
			$key = "{$name}_source";
			if (!isset($this->problem_conf[$key])) {
				return $this->copy_source_files_to_prepare($name);
			}
			global $uojMainJudgerWorkPath;
			$file_name = $this->problem_conf[$key];
			$err = dataProgramSourceError($file_name);
			if ($err !== '') {
				throw new UOJProblemConfException("$key: $err");
			}
			if (!isset($this->allow_files[$file_name]) || !is_file("{$this->upload_dir}/$file_name")) {
				throw new UOJFileNotFoundException($file_name);
			}
			$src = escapeshellarg("{$this->upload_dir}/$file_name");
			$dest = escapeshellarg("{$this->prepare_dir}/$name" . dataProgramSourceSuffix($file_name));
			if (isset($this->problem_extra_config['dont_use_formatter'])) {
				exec("cp $src $dest -fT", $output, $ret);
			} else {
				exec("$uojMainJudgerWorkPath/run/formatter <$src >$dest", $output, $ret);
			}
			if ($ret) {
				throw new UOJFileNotFoundException($file_name);
			}
			return true;
		}
		private function copy_to_prepare($file_name) {
			global $uojMainJudgerWorkPath;
			if (!isset($this->allow_files[$file_name])) {
				throw new UOJFileNotFoundException($file_name);
			}
			$src = escapeshellarg("{$this->upload_dir}/$file_name");
			$dest = escapeshellarg("{$this->prepare_dir}/$file_name");
			if (isset($this->problem_extra_config['dont_use_formatter']) || !is_file("{$this->upload_dir}/$file_name")) {
				exec("cp $src $dest -rfT", $output, $ret);
			} else {
				exec("$uojMainJudgerWorkPath/run/formatter <$src >$dest", $output, $ret);
			}
			if ($ret) {
				throw new UOJFileNotFoundException($file_name);
			}
		}
		private function copy_file_to_prepare($file_name) {
			global $uojMainJudgerWorkPath;
			if (!isset($this->allow_files[$file_name]) || !is_file("{$this->upload_dir}/$file_name")) {
				throw new UOJFileNotFoundException($file_name);
			}
			$this->copy_to_prepare($file_name);
		}
		// The programs are built by the judgers, in the sandbox that also compiles submissions.
		private function need_compile($name, $config = array()) {
			$step = array('type' => 'compile', 'name' => $name);
			if (isset($config['need_include_header']) && $config['need_include_header']) {
				$step['include'] = true;
			}
			if (isset($config['impl'])) {
				$step['impl'] = $config['impl'];
			}
			if (isset($config['path'])) {
				$step['path'] = $config['path'];
			}
			$this->prepare_steps[] = $step;
		}
		private function need_make() {
			$this->prepare_steps[] = array('type' => 'make');
		}

		public function handle() {
			$id = $this->problem['id'];
			if (!validateUInt($id)) {
				error_log("dataSyncProblemData: hacker detected");
				return "invalid problem id";
			}

			$this->upload_dir = "/var/uoj_data/upload/$id";
			$this->data_dir = "/var/uoj_data/$id";
			// The data is prepared in a folder named after the problem inside a staging folder,
			// so that the archive for the judgers can be built before anything is published.
			$this->stage_dir = "/var/uoj_data/prepare_$id";
			$this->prepare_dir = "{$this->stage_dir}/$id";

			$waiting = dataWaitingVersion($id);
			if ($waiting) {
				if (time() - strtotime($waiting['created_at']) <= self::STALE_SYNC_SECONDS) {
					return "please wait until a judger has checked the data of the last sync";
				}
				dataFailVersion($waiting, 'no judger checked the data in time');
			}
			if (file_exists($this->stage_dir)) {
				if (!$this->is_stage_stale()) {
					return "please wait until the last sync finish";
				}
				error_log("dataSyncProblemData: removing the staging folder of a dead sync of problem #$id");
				exec("rm " . escapeshellarg($this->stage_dir) . " -rf");
			}

			// creating the folder is what makes this sync the only one of the problem
			if (!@mkdir($this->stage_dir, 0755)) {
				return "please wait until the last sync finish";
			}

			try {
				$this->requirement = array();
				$this->problem_extra_config = json_decode($this->problem['extra_config'], true);

				mkdir($this->prepare_dir, 0755);
				if (!is_file("{$this->upload_dir}/problem.conf")) {
					throw new UOJFileNotFoundException("problem.conf");
				}

				$this->problem_conf = getUOJConf("{$this->upload_dir}/problem.conf");
				$this->final_problem_conf = $this->problem_conf;
				if ($this->problem_conf === -1) {
					throw new UOJFileNotFoundException("problem.conf");
				} elseif ($this->problem_conf === -2) {
					throw new UOJProblemConfException("syntax error");
				}

				$this->allow_files = array_flip(array_filter(scandir($this->upload_dir), function($x) {
					return $x !== '.' && $x !== '..';
				}));

				$zip_file = new ZipArchive();
				if ($zip_file->open("{$this->prepare_dir}/download.zip", ZipArchive::CREATE) !== true) {
					throw new Exception("<strong>download.zip</strong> : failed to create the zip file");
				}

				if ($this->check_conf_on('use_builtin_judger')) {
					if (isset($this->allow_files['require']) && is_dir("{$this->upload_dir}/require")) {
						$this->copy_to_prepare('require');
					}
					$n_tests = getUOJConfVal($this->problem_conf, 'n_tests', 10);
					if (!validateUInt($n_tests) || $n_tests <= 0) {
						throw new UOJProblemConfException("n_tests must be a positive integer");
					}
					for ($num = 1; $num <= $n_tests; $num++) {
						$input_file_name = getUOJProblemInputFileName($this->problem_conf, $num);
						$output_file_name = getUOJProblemOutputFileName($this->problem_conf, $num);

						$this->copy_file_to_prepare($input_file_name);
						$this->copy_file_to_prepare($output_file_name);
					}

					if (!$this->check_conf_on('interaction_mode')) {
						if (isset($this->problem_conf['use_builtin_checker'])) {
							if (!preg_match('/^[a-zA-Z0-9_]{1,20}$/', $this->problem_conf['use_builtin_checker'])) {
								throw new Exception("<strong>" . htmlspecialchars($this->problem_conf['use_builtin_checker']) . "</strong> is not a valid checker");
							}
						} else {
							if (!$this->copy_program_source('chk')) {
								throw new UOJFileNotFoundException('chk.*');
							}
							$this->need_compile('chk', array('need_include_header' => true));
						}
					}

					if ($this->check_conf_on('submit_answer')) {
						if ($this->problem['hackable']) {
							throw new UOJProblemConfException("the problem can't be hackable if submit_answer is on");
						}
						if (isset($this->problem_conf['multi_pass']) && $this->problem_conf['multi_pass'] > 1) {
							throw new UOJProblemConfException("multi_pass can't be combined with submit_answer");
						}

						for ($num = 1; $num <= $n_tests; $num++) {
							$input_file_name = getUOJProblemInputFileName($this->problem_conf, $num);
							$output_file_name = getUOJProblemOutputFileName($this->problem_conf, $num);

							if (!isset($this->problem_extra_config['dont_download_input'])) {
								$zip_file->addFile("{$this->prepare_dir}/$input_file_name", "$input_file_name");
							}

							$this->requirement[] = array('name' => "output$num", 'type' => 'text', 'file_name' => $output_file_name);
						}
					} else {
						$n_ex_tests = getUOJConfVal($this->problem_conf, 'n_ex_tests', 0);
						if (!validateUInt($n_ex_tests) || $n_ex_tests < 0) {
							throw new UOJProblemConfException("n_ex_tests must be a non-negative integer");
						}

						for ($num = 1; $num <= $n_ex_tests; $num++) {
							$input_file_name = getUOJProblemExtraInputFileName($this->problem_conf, $num);
							$output_file_name = getUOJProblemExtraOutputFileName($this->problem_conf, $num);

							$this->copy_file_to_prepare($input_file_name);
							$this->copy_file_to_prepare($output_file_name);
						}

						if ($this->problem['hackable']) {
							if (!$this->copy_program_source('std')) {
								throw new UOJFileNotFoundException('std.*');
							}
							if (isset($this->problem_conf['with_implementer']) && $this->problem_conf['with_implementer'] == 'on') {
								$this->need_compile('std',
									array(
										'impl' => 'implementer',
										'path' => 'require'
									)
								);
							} else {
								$this->need_compile('std');
							}
							if (!$this->copy_program_source('val')) {
								throw new UOJFileNotFoundException('val.*');
							}
							$this->need_compile('val', array('need_include_header' => true));
						}

						if ($this->check_conf_on('interaction_mode')) {
							if (!$this->copy_program_source('interactor')) {
								throw new UOJFileNotFoundException('interactor.*');
							}
							$this->need_compile('interactor', array('need_include_header' => true));
						}

						// A multi-pass problem: after every pass of a program its checker says
						// whether the program runs again, and on what. Only a checker of the
						// problem's own does that.
						if (isset($this->problem_conf['multi_pass'])) {
							$n_passes = $this->problem_conf['multi_pass'];
							if (!validateUInt($n_passes) || $n_passes > 20) {
								throw new UOJProblemConfException("multi_pass must be an integer between 0 and 20");
							}
							if ($n_passes > 1) {
								if ($this->check_conf_on('interaction_mode')) {
									throw new UOJProblemConfException("multi_pass can't be combined with interaction_mode");
								}
								if ($this->problem['hackable']) {
									throw new UOJProblemConfException("the problem can't be hackable if multi_pass is on");
								}
								if (isset($this->problem_conf['use_builtin_checker'])) {
									throw new UOJProblemConfException("multi_pass needs a checker of the problem's own (chk), a builtin checker never asks for another pass");
								}
							}
						}

						$n_sample_tests = getUOJConfVal($this->problem_conf, 'n_sample_tests', $n_tests);
						if (!validateUInt($n_sample_tests) || $n_sample_tests < 0) {
							throw new UOJProblemConfException("n_sample_tests must be a non-negative integer");
						}
						if ($n_sample_tests > $n_ex_tests) {
							throw new UOJProblemConfException("n_sample_tests can't be greater than n_ex_tests");
						}

						if (!isset($this->problem_extra_config['dont_download_sample'])) {
							for ($num = 1; $num <= $n_sample_tests; $num++) {
								$input_file_name = getUOJProblemExtraInputFileName($this->problem_conf, $num);
								$output_file_name = getUOJProblemExtraOutputFileName($this->problem_conf, $num);
								$zip_file->addFile("{$this->prepare_dir}/{$input_file_name}", "$input_file_name");
								if (!isset($this->problem_extra_config['dont_download_sample_output'])) {
									$zip_file->addFile("{$this->prepare_dir}/{$output_file_name}", "$output_file_name");
								}
							}
						}

						$this->requirement[] = array('name' => 'answer', 'type' => 'source code', 'file_name' => 'answer.code');
					}
				} else {
					if (!$this->may_use_custom_judger()) {
						throw new UOJProblemConfException("use_builtin_judger must be on.");
					} else {
						foreach ($this->allow_files as $file_name => $file_num) {
							$this->copy_to_prepare($file_name);
						}
						$this->need_make();

						$this->requirement[] = array('name' => 'answer', 'type' => 'source code', 'file_name' => 'answer.code');
					}
				}
				putUOJConf("{$this->prepare_dir}/problem.conf", $this->final_problem_conf);

				if (isset($this->allow_files['download']) && is_dir("{$this->upload_dir}/download")) {
					foreach (scandir("{$this->upload_dir}/download") as $file_name) {
						if (is_file("{$this->upload_dir}/download/{$file_name}")) {
							$zip_file->addFile("{$this->upload_dir}/download/{$file_name}", $file_name);
						}
					}
				}

				$zip_file->close();

				$this->build_archive();
				$version_row = $this->register_version();
			} catch (Exception $e) {
				exec("rm " . escapeshellarg($this->stage_dir) . " -rf");
				return $e->getMessage();
			}

			if (!$this->prepare_steps) {
				dataPublishVersion($version_row);
			}

			return '';
		}

		// everything that is applied to the problem when the version is published
		private function pending_changes() {
			$pending = array(
				'hackable' => $this->problem['hackable'] ? 1 : 0,
				'requirement' => $this->requirement
			);
			if ($this->check_conf_on('use_builtin_judger')) {
				if (isset($this->problem_extra_config['custom_judger_fingerprint'])) {
					$pending['custom_judger_fingerprint'] = null;
				}
			} elseif (can($this->user, 'problem.approve_judger')) {
				$pending['custom_judger_fingerprint'] = dataCustomJudgerFingerprint($this->upload_dir, $this->problem_conf);
			}
			foreach (array('after', 'hack_id') as $name) {
				if (isset($this->options[$name])) {
					$pending[$name] = $this->options[$name];
				}
			}
			return $pending;
		}

		private function register_version() {
			$version_row = dataInsertVersion($this->problem['id'], "{$this->stage_dir}/data.zip", dataManifest($this->prepare_dir), array(
				'status' => 'pending',
				'prepare' => DB::escape(json_encode($this->prepare_steps)),
				'pending' => DB::escape(json_encode($this->pending_changes())),
				'created_by' => $this->user ? DB::escape($this->user['username']) : '',
				'reason' => DB::escape(isset($this->options['reason']) ? $this->options['reason'] : 'sync')
			));
			if ($version_row == null) {
				throw new Exception("failed to register the new version of the data");
			}
			// without a user, this is the sync after a successful hack
			auditLog('problem.sync_data', 'problem', $this->problem['id'], null, array(
				'version' => (int)$version_row['version'],
				'sha256' => $version_row['sha256'],
				'reason' => $version_row['reason'],
				'hackable' => $this->problem['hackable'] ? 1 : 0
			) + (isset($this->options['hack_id']) ? array('hack_id' => $this->options['hack_id']) : array()), $this->user ? $this->user : false);
			return $version_row;
		}

		// A custom judger runs unrestricted on the judgers, so only a super user may sync one.
		// Anybody else, including the sync after a successful hack that no user asked for, may
		// only rebuild exactly what a super user synced before.
		private function may_use_custom_judger() {
			if (can($this->user, 'problem.approve_judger')) {
				return true;
			}
			// Nothing is taken on trust from the record of the problem: the files that are about
			// to be built are hashed, and that hash has to be one a system administrator approved,
			// for this problem or, byte for byte the same, for another.
			$fingerprint = dataCustomJudgerFingerprint($this->upload_dir, $this->problem_conf);
			if (isset($this->problem_extra_config['custom_judger_fingerprint'])) {
				$approved = $this->problem_extra_config['custom_judger_fingerprint'];
				if (is_string($approved) && hash_equals($approved, $fingerprint)) {
					return true;
				}
			}
			return dataIsApprovedJudger($fingerprint);
		}

		private function is_stage_stale() {
			clearstatcache();
			$last_touched = max((int)@filemtime($this->stage_dir), (int)@filemtime($this->prepare_dir));
			return time() - $last_touched > self::STALE_SYNC_SECONDS;
		}

		private function build_archive() {
			$id = $this->problem['id'];
			exec("cd " . escapeshellarg($this->stage_dir) . " && zip data.zip $id -r -q", $output, $ret);
			if ($ret !== 0 || !is_file("{$this->stage_dir}/data.zip")) {
				throw new Exception("<strong>$id.zip</strong> : failed to create the archive for judgers");
			}
		}
	}

	// Makes a new version of the data of a problem from its upload folder. Returns '' when the
	// version was published or is waiting for a judger to build its programs, or the reason
	// why there is no new version.
	function dataSyncProblemData($problem, $user = null, $options = array()) {
		return (new SyncProblemDataHandler($problem, $user, $options))->handle();
	}
	// Adds the data of a successful hack as an extra test and syncs the problem. The accepted
	// submissions are judged again once the new data is published. Returns '' or the reason why
	// this could not be done.
	function dataAddExtraTest($problem, $input_file_name, $output_file_name, $hack_id) {
		$id = $problem['id'];

		$cur_dir = "/var/uoj_data/upload/$id";

		// two hacks of the same problem may succeed at the same time
		$lock = fopen("/var/uoj_data/upload/$id.lock", 'c');
		if ($lock === false || !flock($lock, LOCK_EX)) {
			return 'failed to lock the data of the problem';
		}
		try {
			$problem_conf = getUOJConf("{$cur_dir}/problem.conf");
			if ($problem_conf === -1 || $problem_conf === -2) {
				return 'problem.conf is missing or invalid';
			}
			$problem_conf['n_ex_tests'] = getUOJConfVal($problem_conf, 'n_ex_tests', 0) + 1;

			$new_input_name = getUOJProblemExtraInputFileName($problem_conf, $problem_conf['n_ex_tests']);
			$new_output_name = getUOJProblemExtraOutputFileName($problem_conf, $problem_conf['n_ex_tests']);

			if (!move_uploaded_file($input_file_name, "$cur_dir/$new_input_name") || !move_uploaded_file($output_file_name, "$cur_dir/$new_output_name")) {
				return 'failed to save the data of the hack';
			}
			putUOJConf("$cur_dir/problem.conf", $problem_conf);

			// nobody asked for this sync, so it runs without the permissions of any user
			$ret = dataSyncProblemData($problem, null, array('reason' => 'hack', 'after' => 'rejudge_ac', 'hack_id' => (int)$hack_id));
			if ($ret !== '') {
				return 'the extra test was saved but the sync failed: ' . $ret;
			}
			return '';
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
?>
