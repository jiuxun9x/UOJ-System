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

	// ---- the files a submission to a problem is made of (submission_requirement)
	//
	// The data says what they are: an answer file of every test for a problem of answers, a
	// program for any other. What the data says is put down whenever a version of it is
	// published - a test more is an answer more, a problem of answers that became one of
	// programs takes a program - unless somebody wrote what they are by hand
	// ('custom_submission_requirement' in extra_config): that stays as it was written. (D02: only
	// a problem that had none got them, and a second answer of a problem of answers was never
	// asked for.) A problem from before that was marked is taken as written by hand where what it
	// says is not something the data could have said.

	// whether what $requirement says could have been said by the data
	function dataRequirementLooksMade($requirement) {
		if (!is_array($requirement) || $requirement === array()) {
			return true;
		}
		if ($requirement == array(array('name' => 'answer', 'type' => 'source code', 'file_name' => 'answer.code'))) {
			return true;
		}
		foreach ($requirement as $item) {
			if (!is_array($item) || count($item) != 3 || !isset($item['name'], $item['type'], $item['file_name'])
					|| !preg_match('/^output[0-9]+$/D', (string)$item['name']) || $item['type'] !== 'text') {
				return false;
			}
		}
		return true;
	}
	// whether the files of a submission to a problem were written by hand, and stay as they are
	function dataRequirementIsCustom($problem) {
		$extra_config = json_decode($problem['extra_config'], true);
		if (is_array($extra_config) && array_key_exists('custom_submission_requirement', $extra_config)) {
			return $extra_config['custom_submission_requirement'] === true;
		}
		return !dataRequirementLooksMade(json_decode($problem['submission_requirement'], true));
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
		$extra_config = json_decode($problem['extra_config'], true);
		$extra_config = is_array($extra_config) ? $extra_config : array();
		$kept_config = $extra_config;
		if (isset($pending['requirement'])) {
			// what the data says the files of a submission are, as it says it now
			$extra_config['auto_submission_requirement'] = $pending['requirement'];
			if (!dataRequirementIsCustom($problem)) {
				$set[] = "submission_requirement = '" . DB::escape(json_encode($pending['requirement'])) . "'";
			}
		}
		if (array_key_exists('custom_judger_fingerprint', (array)$pending)) {
			if ($pending['custom_judger_fingerprint'] === null) {
				unset($extra_config['custom_judger_fingerprint']);
			} else {
				$extra_config['custom_judger_fingerprint'] = $pending['custom_judger_fingerprint'];
				// what was approved is this content, whichever problem it turns up in
				dataRegisterApprovedJudger($pending['custom_judger_fingerprint'], $version_row['created_by'], $id);
			}
		}
		if ($extra_config !== $kept_config) {
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
				exec("$uojMainJudgerWorkPath/run/formatter" . (isset($this->problem_extra_config['remove_cr']) ? ' --remove-cr' : '') . " <$src >$dest", $output, $ret);
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
				// what the problem says it is to be done to its files: see problemFormatters()
				exec("$uojMainJudgerWorkPath/run/formatter" . (isset($this->problem_extra_config['remove_cr']) ? ' --remove-cr' : '') . " <$src >$dest", $output, $ret);
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
		// Whether the tests of the problem may do without answers: where its own checker, or its
		// interactor, looks at what a program wrote, it may well need nothing but the input. A way
		// of comparing of this site compares with the answer, and needs one.
		private function answers_optional() {
			return !isset($this->problem_conf['use_builtin_checker']) || $this->check_conf_on('interaction_mode')
				|| (isset($this->problem_conf['multi_pass']) && (int)$this->problem_conf['multi_pass'] > 1)
				|| $this->check_conf_on('domjudge_multipass') || $this->check_conf_on('polygon_runtwice');
		}
		// The answer of a test: the file that was uploaded, or, where there is none and none is
		// needed, an empty one, which is what the checker is handed.
		private function copy_answer_to_prepare($file_name) {
			if (isset($this->allow_files[$file_name]) && is_file("{$this->upload_dir}/$file_name")) {
				$this->copy_to_prepare($file_name);
				return;
			}
			if (!$this->answers_optional()) {
				throw new Exception("file <strong>" . htmlspecialchars($file_name) . "</strong> not found：内置的比较方式要拿答案比较，每个测试点都要有答案文件（用自己写的校验器时可以不传）");
			}
			if (file_put_contents("{$this->prepare_dir}/$file_name", '') === false) {
				throw new Exception("<strong>" . htmlspecialchars($file_name) . "</strong> : failed to make an empty answer");
			}
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
			if (isset($config['define'])) {
				$step['define'] = $config['define'];
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
					// (what is not written is what the judgers take; validateUInt() takes a text, as a conf has it)
					$n_tests = getUOJConfVal($this->problem_conf, 'n_tests', '10');
					if (!validateUInt($n_tests) || $n_tests <= 0) {
						throw new UOJProblemConfException("n_tests must be a positive integer");
					}
					for ($num = 1; $num <= $n_tests; $num++) {
						$input_file_name = getUOJProblemInputFileName($this->problem_conf, $num);
						$output_file_name = getUOJProblemOutputFileName($this->problem_conf, $num);

						$this->copy_file_to_prepare($input_file_name);
						$this->copy_answer_to_prepare($output_file_name);
					}

					// A problem of Polygon that is run twice and whose interactor says the verdict has
					// no checker; any other problem compares answers, or has its interactor do it.
					$interactor_decides = $this->check_conf_on('polygon_runtwice') && $this->check_conf_on('polygon_runtwice_interactive')
						&& $this->check_conf_on('polygon_runtwice_interactor_verdict');
					if (!$this->check_conf_on('interaction_mode') && !$interactor_decides) {
						if (isset($this->problem_conf['use_builtin_checker'])) {
							if (!preg_match('/^[a-zA-Z0-9_]{1,20}$/', $this->problem_conf['use_builtin_checker'])) {
								throw new Exception("<strong>" . htmlspecialchars($this->problem_conf['use_builtin_checker']) . "</strong> is not a valid checker");
							}
						} else {
							if (!$this->copy_program_source('chk')) {
								throw new UOJFileNotFoundException('chk.*');
							}
							// an output validator of DOMjudge is compiled the way DOMjudge compiles it
							$this->need_compile('chk', array('need_include_header' => true) + ($this->check_conf_on('domjudge_multipass') ? array('define' => array('DOMJUDGE')) : array()));
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
						$n_ex_tests = getUOJConfVal($this->problem_conf, 'n_ex_tests', '0');
						if (!validateUInt($n_ex_tests) || $n_ex_tests < 0) {
							throw new UOJProblemConfException("n_ex_tests must be a non-negative integer");
						}

						for ($num = 1; $num <= $n_ex_tests; $num++) {
							$input_file_name = getUOJProblemExtraInputFileName($this->problem_conf, $num);
							$output_file_name = getUOJProblemExtraOutputFileName($this->problem_conf, $num);

							$this->copy_file_to_prepare($input_file_name);
							$this->copy_answer_to_prepare($output_file_name);
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
						} elseif ($this->check_conf_on('validate_input_before_test')) {
							// The inputs are run past the validator before every test. Without
							// one, every submission would fail at it: the data is not published.
							if (!$this->copy_program_source('val')) {
								throw new UOJProblemConfException("validate_input_before_test is on, but the problem has no validator (val): choose one in the settings, or turn the validation of the inputs off");
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

						// A problem whose program runs more than once on a test, in the way of another
						// judge: one way, with what that way needs.
						$ways = 0;
						foreach (array('polygon_runtwice', 'domjudge_multipass') as $key) {
							$ways += $this->check_conf_on($key) ? 1 : 0;
						}
						$ways += isset($this->problem_conf['multi_pass']) && validateUInt($this->problem_conf['multi_pass']) && $this->problem_conf['multi_pass'] > 1 ? 1 : 0;
						if ($ways > 1) {
							throw new UOJProblemConfException("only one of multi_pass, polygon_runtwice and domjudge_multipass can be on");
						}
						if ($ways == 1 && $this->problem['hackable']) {
							throw new UOJProblemConfException("the problem can't be hackable if its program runs more than once on a test");
						}
						if ($this->check_conf_on('domjudge_multipass')) {
							$n_passes = getUOJConfVal($this->problem_conf, 'validation_passes', '2');
							if (!validateUInt($n_passes) || $n_passes < 2 || $n_passes > 20) {
								throw new UOJProblemConfException("validation_passes must be an integer between 2 and 20");
							}
							if ($this->check_conf_on('interaction_mode')) {
								throw new UOJProblemConfException("domjudge_multipass can't be combined with interaction_mode");
							}
							if (isset($this->problem_conf['use_builtin_checker'])) {
								throw new UOJProblemConfException("domjudge_multipass needs an output validator of the problem's own (chk)");
							}
						}
						if ($this->check_conf_on('polygon_runtwice')) {
							if ($this->check_conf_on('interaction_mode')) {
								throw new UOJProblemConfException("polygon_runtwice can't be combined with interaction_mode: it talks to the interactor by itself");
							}
							if (!$this->copy_program_source('interactor')) {
								throw new UOJFileNotFoundException('interactor.*');
							}
							$this->need_compile('interactor', array('need_include_header' => true));
						}

						// (none where it is not said, as the judgers have it)
						$n_sample_tests = getUOJConfVal($this->problem_conf, 'n_sample_tests', '0');
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
	// ---- the answers the standard program makes
	//
	// Where the tests of a problem come without answers - a package of Polygon has its inputs and
	// its main solution, and Polygon makes the answers by running that - a judger runs the
	// standard program of the problem on the inputs and sends back what it wrote, which is then
	// the answers. The web server runs nothing itself (see above). It is all or nothing: where
	// the program fails on one test, no answer is written, and the job says on which and why.
	// Only an ordinary problem is answered so: its program reads the input and writes the answer,
	// once, with no interactor and no passes.

	define('UOJ_ANSWER_JOB_MAX_TESTS', 1000);

	function dataAnswerJobArchive($job_id) {
		return "/var/uoj_data/answers/" . (int)$job_id . ".zip";
	}
	// the job of a problem that is not over yet, if any
	function dataRunningAnswerJob($problem_id) {
		return DB::selectFirst("select * from problem_answer_jobs where problem_id = " . (int)$problem_id . " and status in ('pending', 'running') order by id desc limit 1");
	}
	function dataLastAnswerJob($problem_id) {
		return DB::selectFirst("select * from problem_answer_jobs where problem_id = " . (int)$problem_id . " order by id desc limit 1");
	}
	// Which answers of a problem its standard program would make, as its uploaded data stands:
	// array(the plan, why there is none or ''). The plan says the source of the program, the
	// tests (array(input, answer) of those whose answer is not there, or of all with $all), how
	// many have no answer, and the limits the program runs under.
	function dataAnswerPlan($problem, $all = false) {
		requirePHPLib('problem');
		requirePHPLib('upload');
		$upload_dir = "/var/uoj_data/upload/" . (int)$problem['id'];
		$conf = is_file("$upload_dir/problem.conf") ? getUOJConf("$upload_dir/problem.conf") : -1;
		if (!is_array($conf)) {
			return array(null, '还没有评测设置（problem.conf）');
		}
		$on = function($key) use ($conf) {
			return isset($conf[$key]) && $conf[$key] == 'on';
		};
		if (!$on('use_builtin_judger') || $on('submit_answer') || $on('interaction_mode') || $on('polygon_runtwice') || $on('domjudge_multipass')
			|| (isset($conf['multi_pass']) && (int)$conf['multi_pass'] > 1)) {
			return array(null, '只有普通的题（程序读输入、写输出，运行一次）能用标准程序生成答案');
		}
		if ($on('with_implementer')) {
			return array(null, '和实现器一起编译的标准程序不能用来生成答案');
		}
		// the standard program: the file problem.conf names, or one called so
		$source = isset($conf['std_source']) ? (string)$conf['std_source'] : '';
		if ($source === '') {
			foreach (problemUploadedNames($upload_dir) as $name) {
				if (preg_match('/^std[0-9]*\./', $name) && dataProgramSourceError($name) === '') {
					$source = $name;
					break;
				}
			}
		}
		if ($source === '' || dataProgramSourceError($source) !== '' || !is_file("$upload_dir/$source")) {
			return array(null, '没有标准程序：先在评测设置里选好标准程序');
		}
		$tests = array();
		$missing = 0;
		$add = function($input, $answer) use (&$tests, &$missing, $upload_dir, $all) {
			if (!is_file("$upload_dir/$input")) {
				return;
			}
			$absent = !is_file("$upload_dir/$answer") || filesize("$upload_dir/$answer") == 0;
			$missing += $absent ? 1 : 0;
			if ($absent || $all) {
				$tests[] = array($input, $answer);
			}
		};
		$n_tests = getUOJConfVal($conf, 'n_tests', '10');
		$n_ex_tests = getUOJConfVal($conf, 'n_ex_tests', '0');
		if (!validateUInt($n_tests) || !validateUInt($n_ex_tests)) {
			return array(null, 'problem.conf 里的测试点个数不对');
		}
		for ($num = 1; $num <= $n_tests; $num++) {
			$add(getUOJProblemInputFileName($conf, $num), getUOJProblemOutputFileName($conf, $num));
		}
		for ($num = 1; $num <= $n_ex_tests; $num++) {
			$add(getUOJProblemExtraInputFileName($conf, $num), getUOJProblemExtraOutputFileName($conf, $num));
		}
		if (!$tests) {
			return array(null, $all ? '没有测试点的输入文件' : '每个测试点都有答案了');
		}
		if (count($tests) > UOJ_ANSWER_JOB_MAX_TESTS) {
			return array(null, '测试点太多（超过 ' . UOJ_ANSWER_JOB_MAX_TESTS . ' 个）');
		}
		$time = (float)getUOJConfVal($conf, 'time_limit', '1');
		$memory = (int)getUOJConfVal($conf, 'memory_limit', '256');
		$output = (int)getUOJConfVal($conf, 'output_limit', '64');
		return array(array(
			'source' => $source,
			'tests' => $tests,
			'missing' => $missing,
			// the program is given more time than a submission: it is to make the answers, not to be judged
			'limits' => array('time' => (int)min(60, max(5, ceil(2 * $time))), 'memory' => max(64, min(4096, $memory)), 'output' => max(1, min(1024, $output)))
		), '');
	}
	// Has a judger make the answers the plan says. Returns '' or why not.
	function dataStartAnswerJob($problem, $actor, $all = false) {
		global $uojMainJudgerWorkPath;
		$id = (int)$problem['id'];
		if (dataRunningAnswerJob($id)) {
			return '上一次生成答案还没有结束';
		}
		list($plan, $err) = dataAnswerPlan($problem, $all);
		if ($err !== '') {
			return $err;
		}
		$upload_dir = "/var/uoj_data/upload/$id";
		if (!is_dir('/var/uoj_data/answers') && !@mkdir('/var/uoj_data/answers', 0755, true)) {
			return '无法创建生成答案用的文件夹';
		}
		// what the program is run on: the inputs as the judgers have them, which the files are
		// made into when the data is synced (see problemFormatters())
		$extra_config = json_decode($problem['extra_config'], true);
		$extra_config = is_array($extra_config) ? $extra_config : array();
		$file = tempnam('/var/uoj_data/answers', 'job_');
		$zip = new ZipArchive();
		if ($file === false || $zip->open($file, ZipArchive::OVERWRITE) !== true) {
			return '无法打包测试点的输入';
		}
		$temps = array();
		$tests = array();
		foreach ($plan['tests'] as $index => $test) {
			$src = "$upload_dir/{$test[0]}";
			if (isset($extra_config['dont_use_formatter'])) {
				$input = $src;
			} else {
				$input = tempnam(sys_get_temp_dir(), 'uoj_input_');
				$temps[] = $input;
				exec("$uojMainJudgerWorkPath/run/formatter" . (isset($extra_config['remove_cr']) ? ' --remove-cr' : '') . " <" . escapeshellarg($src) . " >" . escapeshellarg($input), $output, $ret);
				if ($ret) {
					$input = $src;
				}
			}
			$zip->addFile($input, 'input/' . ($index + 1));
			// the input is the answer's while it is what it was when the program ran on it
			$tests[] = array($test[0], $test[1], hash_file('sha256', $src));
		}
		$program = 'std' . dataProgramSourceSuffix($plan['source']);
		$zip->addFile("$upload_dir/{$plan['source']}", $program);
		$closed = $zip->close();
		foreach ($temps as $temp) {
			@unlink($temp);
		}
		if (!$closed) {
			@unlink($file);
			return '无法打包测试点的输入';
		}
		$limits = $plan['limits'] + array('program' => $program, 'source' => $plan['source']);
		DB::insert("insert into problem_answer_jobs (problem_id, status, overwrite, tests, limits, sha256, created_at, created_by) values ($id, 'pending', " . ($all ? 1 : 0)
			. ", '" . DB::escape(json_encode($tests, JSON_UNESCAPED_UNICODE)) . "', '" . DB::escape(json_encode($limits, JSON_UNESCAPED_UNICODE)) . "', '" . hash_file('sha256', $file) . "', now(), '" . DB::escape($actor ? $actor['username'] : 'root') . "')");
		$job_id = DB::insert_id();
		rename($file, dataAnswerJobArchive($job_id));
		chmod(dataAnswerJobArchive($job_id), 0644);
		auditLog('problem.make_answers', 'problem', $id, null, array('job' => (int)$job_id, 'tests' => count($tests), 'all' => $all, 'std' => $plan['source']), $actor);
		return '';
	}
	// What became of a job: failed, with why; or done, with the zip the judger sent of what the
	// program wrote, named after the place of each test in the job. The answers are written only
	// where the inputs are still what the program ran on (and, where only missing answers were to
	// be made, where nobody gave an answer meanwhile); then the data is synced.
	function dataFinishAnswerJob($job, $ok, $message, $zip_file = null) {
		$id = (int)$job['problem_id'];
		$fail = function($why) use ($job) {
			DB::update("update problem_answer_jobs set status = 'failed', message = '" . DB::escape($why) . "', finished_at = now(), judge_claim = null, judge_worker = null, judge_lease_until = null where id = {$job['id']} and status in ('pending', 'running')");
			@unlink(dataAnswerJobArchive($job['id']));
		};
		if (!$ok) {
			$fail($message);
			return;
		}
		$tests = json_decode($job['tests'], true);
		$limits = json_decode($job['limits'], true);
		$zip = new ZipArchive();
		if (!is_array($tests) || $zip_file === null || $zip->open($zip_file) !== true) {
			$fail('评测机交回的答案打不开');
			return;
		}
		$upload_dir = "/var/uoj_data/upload/$id";
		$lock = fopen("/var/uoj_data/upload/$id.lock", 'c');
		if ($lock === false || !flock($lock, LOCK_EX)) {
			$zip->close();
			$fail('无法锁定题目数据');
			return;
		}
		$written = 0;
		$kept = 0;
		try {
			$answers = array();
			foreach ($tests as $index => $test) {
				$stat = $zip->statName((string)($index + 1));
				if ($stat === false || $stat['size'] > (int)$limits['output'] * 1048576) {
					$fail('评测机交回的答案不全');
					return;
				}
				if (!is_file("$upload_dir/{$test[0]}") || hash_file('sha256', "$upload_dir/{$test[0]}") !== $test[2]) {
					$fail("生成期间 {$test[0]} 改动过了，没有写入答案：请重新生成");
					return;
				}
				$answers[$index] = $stat['index'];
			}
			foreach ($tests as $index => $test) {
				$target = "$upload_dir/{$test[1]}";
				if (!$job['overwrite'] && is_file($target) && filesize($target) > 0) {
					// somebody gave this answer meanwhile: theirs is kept
					$kept++;
					continue;
				}
				$temp = "$upload_dir/.answer_" . getmypid() . "_$index";
				$in = $zip->getStream($zip->getNameIndex($answers[$index]));
				$out = $in ? fopen($temp, 'w') : false;
				if (!$in || !$out || stream_copy_to_stream($in, $out) === false || !fclose($out) || !rename($temp, $target)) {
					@unlink($temp);
					$fail("无法写入 {$test[1]}");
					return;
				}
				fclose($in);
				$written++;
			}
		} finally {
			$zip->close();
			flock($lock, LOCK_UN);
			fclose($lock);
		}
		$said = "生成了 $written 个答案" . ($kept ? "，$kept 个在生成期间有人上传了，保留上传的" : '');
		DB::update("update problem_answer_jobs set status = 'done', message = '" . DB::escape($said) . "', finished_at = now(), judge_claim = null, judge_worker = null, judge_lease_until = null where id = {$job['id']} and status = 'running'");
		@unlink(dataAnswerJobArchive($job['id']));
		$problem = queryProblemBrief($id);
		$creator = queryUser($job['created_by']);
		auditLog('problem.answers_made', 'problem', $id, null, array('job' => (int)$job['id'], 'written' => $written), $creator);
		// the answers are used once the data is synced with them
		if ($problem && $written > 0) {
			$err = dataSyncProblemData($problem, $creator, array('reason' => 'answers'));
			if ($err !== '') {
				DB::update("update problem_answer_jobs set message = '" . DB::escape($said . '；同步数据没有成功：' . trim(html_entity_decode(strip_tags($err)))) . "' where id = {$job['id']}");
			}
		}
	}

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
