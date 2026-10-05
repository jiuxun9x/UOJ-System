<?php

// Attachments: the files that come with a problem or with a contest. A problem has them for
// whoever reads its statement, a contest for whoever is inside it. They are no part of the
// data a problem is judged with: adding one takes effect at once, and nothing is judged again.

define('UOJ_ATTACHMENT_DIR', '/var/uoj_data/attachments');

function attachmentOwnerTypes() {
	return array('problem' => '题目', 'contest' => '比赛');
}
// how large an attachment may be, in bytes, and how many a problem or a contest may have
function attachmentLimits() {
	$conf = UOJConfig::$data['attachments'];
	return array('file_bytes' => (int)$conf['max-file-mb'] * 1048576, 'files' => (int)$conf['max-files']);
}
// Whether a file may be called this: '' or why not. An attachment is called what its file
// was called on the machine it came from, without any folder.
function attachmentNameError($name) {
	if (!is_string($name) || $name === '' || strlen($name) > 200) {
		return '文件名为空，或者超过了 200 个字节';
	}
	if (!mb_check_encoding($name, 'UTF-8')) {
		return '文件名不是 UTF-8 编码';
	}
	if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name)) {
		return '文件名里有斜杠、反斜杠或控制字符';
	}
	if ($name[0] === '.' || trim($name) !== $name) {
		return '文件名不能以点或空格开头，也不能以空格结尾';
	}
	return '';
}
function attachmentSizeText($bytes) {
	if ($bytes < 1024) {
		return $bytes . ' B';
	}
	if ($bytes < 1048576) {
		return round($bytes / 1024, 1) . ' KB';
	}
	return round($bytes / 1048576, 1) . ' MB';
}
// What the browser is told a file is. Only a PDF is shown in the browser, which reads it
// with a reader of its own; everything else is a download, whatever it calls itself, so that
// no file of somebody's making is ever a page of this site.
function attachmentIsShownInline($name) {
	return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf';
}

function queryAttachment($id) {
	return DB::selectFirst("select * from attachments where id = ".(int)$id, MYSQLI_ASSOC);
}
function attachmentsOf($owner_type, $owner_id) {
	return DB::selectAll("select * from attachments where owner_type = '".DB::escape($owner_type)."' and owner_id = ".(int)$owner_id." order by name", MYSQLI_ASSOC);
}
function attachmentPath($attachment) {
	return UOJ_ATTACHMENT_DIR . '/' . (int)$attachment['id'];
}
function attachmentUrl($attachment) {
	return '/attachment/' . (int)$attachment['id'];
}

// Adds a file to a problem or a contest, in place of the one of the same name if there is
// one. $path is where the file lies now; it is moved, or copied when $keep says so.
// Returns '' or why the file was refused.
function attachmentAdd($owner_type, $owner_id, $path, $name, $actor, $keep = false) {
	if (!isset(attachmentOwnerTypes()[$owner_type])) {
		return '附件只能属于题目或比赛';
	}
	$err = attachmentNameError($name);
	if ($err !== '') {
		return $err;
	}
	$limits = attachmentLimits();
	if (!is_file($path)) {
		return "没有收到文件 $name";
	}
	$size = filesize($path);
	if ($size > $limits['file_bytes']) {
		return "$name 太大了：单个附件不能超过 " . attachmentSizeText($limits['file_bytes']);
	}
	$owner_id = (int)$owner_id;
	$esc_type = DB::escape($owner_type);
	$esc_name = DB::escape($name);
	$existing = DB::selectFirst("select * from attachments where owner_type = '$esc_type' and owner_id = $owner_id and name = '$esc_name'", MYSQLI_ASSOC);
	if (!$existing && DB::selectCount("select count(*) from attachments where owner_type = '$esc_type' and owner_id = $owner_id") >= $limits['files']) {
		return "附件太多了：最多 {$limits['files']} 个";
	}
	if (!is_dir(UOJ_ATTACHMENT_DIR) && !@mkdir(UOJ_ATTACHMENT_DIR, 0755, true) && !is_dir(UOJ_ATTACHMENT_DIR)) {
		return '无法创建保存附件的目录';
	}
	$sha256 = hash_file('sha256', $path);
	$esc_actor = DB::escape($actor['username']);
	if ($existing) {
		$id = (int)$existing['id'];
	} else {
		DB::insert("insert into attachments (owner_type, owner_id, name, size, sha256, uploaded_by, uploaded_at) values ('$esc_type', $owner_id, '$esc_name', $size, '$sha256', '$esc_actor', now())");
		$id = DB::insert_id();
		if (!$id) {
			return "保存 $name 失败";
		}
	}
	$target = UOJ_ATTACHMENT_DIR . '/' . $id;
	// the file is put in place under another name first: nobody is ever sent half of it
	$staged = $target . '.new';
	$moved = $keep ? @copy($path, $staged) : (@move_uploaded_file($path, $staged) || @rename($path, $staged));
	if (!$moved || !@rename($staged, $target)) {
		@unlink($staged);
		if (!$existing) {
			DB::delete("delete from attachments where id = $id");
		}
		return "保存 $name 失败";
	}
	chmod($target, 0644);
	if ($existing) {
		DB::update("update attachments set size = $size, sha256 = '$sha256', uploaded_by = '$esc_actor', uploaded_at = now() where id = $id");
	}
	auditLog('attachment.add', $owner_type, $owner_id, $existing ? array('name' => $name, 'sha256' => $existing['sha256']) : null, array('name' => $name, 'size' => $size, 'sha256' => $sha256), $actor);
	return '';
}
function attachmentDelete($attachment, $actor) {
	DB::delete("delete from attachments where id = ".(int)$attachment['id']);
	@unlink(attachmentPath($attachment));
	auditLog('attachment.delete', $attachment['owner_type'], $attachment['owner_id'], array('name' => $attachment['name'], 'sha256' => $attachment['sha256']), null, $actor);
	return '';
}
// Takes the files a form sent in a field for several files. Returns array(how many were
// added, the reasons the others were refused).
function attachmentsAddUploaded($owner_type, $owner_id, $field, $actor) {
	$added = 0;
	$errors = array();
	if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
		return array($added, $errors);
	}
	foreach ($_FILES[$field]['name'] as $index => $name) {
		$code = $_FILES[$field]['error'][$index];
		if ($code == UPLOAD_ERR_NO_FILE) {
			continue;
		}
		if ($code != UPLOAD_ERR_OK) {
			$errors[] = "$name 没有传完（错误 $code）" . ($code == UPLOAD_ERR_INI_SIZE || $code == UPLOAD_ERR_FORM_SIZE ? '：文件太大' : '');
			continue;
		}
		$err = attachmentAdd($owner_type, $owner_id, $_FILES[$field]['tmp_name'][$index], is_string($name) ? basename(str_replace('\\', '/', $name)) : '', $actor);
		if ($err !== '') {
			$errors[] = $err;
		} else {
			$added++;
		}
	}
	return array($added, $errors);
}
// whether a form sent any file in a field for several files
function attachmentsWereUploaded($field) {
	if (!isset($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
		return false;
	}
	foreach ($_FILES[$field]['error'] as $code) {
		if ($code != UPLOAD_ERR_NO_FILE) {
			return true;
		}
	}
	return false;
}
// a copy of a problem has copies of the files of the problem
function attachmentsCopy($owner_type, $from_id, $to_id, $actor) {
	foreach (attachmentsOf($owner_type, $from_id) as $attachment) {
		if (is_file(attachmentPath($attachment))) {
			attachmentAdd($owner_type, $to_id, attachmentPath($attachment), $attachment['name'], $actor, true);
		}
	}
}

// The list of the files of a problem or a contest as its readers see it. Prints nothing when
// there is none.
function echoAttachments($attachments, $title = '附加文件') {
	if (!$attachments) {
		return;
	}
	echo '<div class="card mb-3 text-left uoj-attachments">';
	echo '<div class="card-header py-2"><span class="glyphicon glyphicon-paperclip"></span> ', HTML::escape($title), '</div>';
	echo '<ul class="list-group list-group-flush">';
	foreach ($attachments as $attachment) {
		echo '<li class="list-group-item py-2 d-flex justify-content-between align-items-center">';
		echo '<a href="', attachmentUrl($attachment), '"', attachmentIsShownInline($attachment['name']) ? ' target="_blank" rel="noopener"' : '', '>', HTML::escape($attachment['name']), '</a>';
		echo '<small class="text-muted ml-3 text-nowrap">', attachmentSizeText((int)$attachment['size']), '</small>';
		echo '</li>';
	}
	echo '</ul></div>';
}
// The files of a problem or a contest for the people who manage it: the list with a button
// to take each away, and the form that adds more. $tab is sent along with the forms.
function echoAttachmentsManager($attachments, $tab = '') {
	$limits = attachmentLimits();
	$hidden = HTML::hiddenToken() . ($tab !== '' ? '<input type="hidden" name="tab" value="' . HTML::escape($tab) . '" />' : '');
	if ($attachments) {
		echo '<table class="table table-hover" id="table-attachments" style="max-width:56em"><thead><tr><th>文件</th><th style="width:7em">大小</th><th style="width:16em">上传</th><th style="width:5em"></th></tr></thead><tbody>';
		foreach ($attachments as $attachment) {
			echo '<tr data-attachment="', (int)$attachment['id'], '">';
			echo '<td><a href="', attachmentUrl($attachment), '">', HTML::escape($attachment['name']), '</a></td>';
			echo '<td>', attachmentSizeText((int)$attachment['size']), '</td>';
			echo '<td><small class="text-muted">', HTML::escape($attachment['uploaded_by']), '，', $attachment['uploaded_at'], '</small></td>';
			echo '<td class="text-right"><form method="post" class="d-inline" onsubmit="return confirm(\'删除这个附件？\');">', $hidden;
			echo '<input type="hidden" name="form" value="delete_attachment" /><input type="hidden" name="attachment_id" value="', (int)$attachment['id'], '" />';
			echo '<button type="submit" class="btn btn-sm btn-outline-danger">删除</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<div class="uoj-domain-empty" id="no-attachments">还没有附件。</div>';
	}
	echo '<form method="post" enctype="multipart/form-data" class="form-inline" id="form-add-attachments">', $hidden;
	echo '<input type="hidden" name="form" value="add_attachments" />';
	echo '<label class="mr-2 mb-2" for="input-attachments">添加附件</label>';
	echo '<input type="file" class="form-control-file mr-2 mb-2" id="input-attachments" name="attachments[]" multiple="multiple" required="required" style="width:auto" />';
	echo '<button type="submit" class="btn btn-primary mb-2">上传</button>';
	echo '</form>';
	echo '<small class="form-text text-muted">可以一次选几个文件。单个文件不超过 ', attachmentSizeText($limits['file_bytes']), '，最多 ', $limits['files'], ' 个；同名的文件会被新上传的替换。上传后立刻生效。PDF 在浏览器里打开，其他文件都是下载。</small>';
}
// The two forms of echoAttachmentsManager(), for domainHandleForms(): each returns '' or why
// it was refused. $done is called with what happened when a form went through.
function attachmentForms($owner_type, $owner_id, $done) {
	return array(
		'add_attachments' => function() use ($owner_type, $owner_id, $done) {
			global $myUser;
			list($added, $errors) = attachmentsAddUploaded($owner_type, $owner_id, 'attachments', $myUser);
			if ($added == 0) {
				return $errors ? join('；', $errors) : '请选择要上传的文件';
			}
			return $done("已添加 $added 个附件。" . ($errors ? '没有添加的：' . join('；', $errors) : ''), $errors ? 'warning' : 'success');
		},
		'delete_attachment' => function() use ($owner_type, $owner_id, $done) {
			global $myUser;
			$attachment = isset($_POST['attachment_id']) && validateUInt($_POST['attachment_id']) ? queryAttachment($_POST['attachment_id']) : null;
			if (!$attachment || $attachment['owner_type'] !== $owner_type || $attachment['owner_id'] != $owner_id) {
				return '没有这个附件';
			}
			attachmentDelete($attachment, $myUser);
			return $done('附件已删除。', 'success');
		}
	);
}
