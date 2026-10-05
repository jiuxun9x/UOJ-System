<?php

// The announcements of the site. An announcement is a post that is marked as important: that
// is what the front page and the list of the announcements have always shown. Here they are
// written, changed and taken away by the administrators of the site, on pages of their own,
// with the pictures, films and other files that go with them.

// how far up an announcement stands: the higher, the further up
function announcementLevels() {
	return array(0 => '不置顶', 1 => '置顶', 2 => '置顶，排在前面', 3 => '置顶，排在最前');
}
function queryAnnouncement($id) {
	return DB::selectFirst("select blogs.*, important_blogs.level from blogs, important_blogs where blogs.id = ".(int)$id." and important_blogs.blog_id = blogs.id", MYSQLI_ASSOC);
}
function announcementUrl($id, $path = '') {
	return '/announcement/' . (int)$id . $path;
}
function announcementRender($content_md) {
	return HTML::pruifier()->purify(HTML::parsedown()->text($content_md));
}
// What a file that was uploaded for an announcement is written as in the text of the
// announcement: a picture is shown, a film or a sound is played, anything else is a link.
function announcementMediaSnippet($attachment) {
	$url = attachmentUrl($attachment);
	$name = str_replace(array('[', ']', "\n"), '', $attachment['name']);
	$type = (string)attachmentInlineType($attachment['name']);
	if (strncmp($type, 'image/', 6) === 0) {
		return "![$name]($url)";
	}
	if (strncmp($type, 'video/', 6) === 0) {
		return "<video controls src=\"$url\"></video>";
	}
	if (strncmp($type, 'audio/', 6) === 0) {
		return "<audio controls src=\"$url\"></audio>";
	}
	return "[$name]($url)";
}
// What the form of an announcement says, checked: array(the values, '') or array(null, why not).
function announcementFromForm($input) {
	$title = isset($input['title']) && is_string($input['title']) ? trim($input['title']) : '';
	if ($title === '' || mb_strlen($title, 'UTF-8') > 200 || !mb_check_encoding($title, 'UTF-8')) {
		return array(null, '标题不能为空，且不超过 200 个字');
	}
	$content_md = isset($input['content_md']) && is_string($input['content_md']) ? $input['content_md'] : '';
	if (strlen($content_md) > 500000 || !mb_check_encoding($content_md, 'UTF-8')) {
		return array(null, '正文太长了');
	}
	$level = isset($input['level']) && is_string($input['level']) ? $input['level'] : '0';
	if (!validateUInt($level) || !isset(announcementLevels()[(int)$level])) {
		return array(null, '请选择是否置顶');
	}
	return array(array('title' => $title, 'content_md' => $content_md, 'level' => (int)$level), '');
}
// Stores an announcement, a new one when $id is null: array(its id, '') or array(null, why
// not). Who posted it stays who posted it when somebody else changes it.
function announcementSave($id, $values, $actor) {
	// a title is kept the way the pages print it
	$esc_title = DB::escape(HTML::escape($values['title']));
	$esc_md = DB::escape($values['content_md']);
	$esc_content = DB::escape(announcementRender($values['content_md']));
	$level = (int)$values['level'];
	if ($id === null) {
		DB::insert("insert into blogs (title, content, content_md, post_time, poster, zan, is_hidden, type, is_draft) values ('$esc_title', '$esc_content', '$esc_md', now(), '".DB::escape($actor['username'])."', 0, 0, 'B', 0)");
		$id = (int)DB::insert_id();
		if ($id <= 0) {
			return array(null, '公告没有能保存，请再试一次');
		}
		DB::insert("insert into important_blogs (blog_id, level) values ($id, $level)");
		auditLog('announcement.post', 'announcement', $id, null, array('title' => $values['title'], 'level' => $level), $actor);
	} else {
		$id = (int)$id;
		$old = queryAnnouncement($id);
		if (!$old) {
			return array(null, '没有这条公告');
		}
		DB::update("update blogs set title = '$esc_title', content = '$esc_content', content_md = '$esc_md' where id = $id");
		DB::update("update important_blogs set level = $level where blog_id = $id");
		auditLog('announcement.edit', 'announcement', $id, array('title' => html_entity_decode($old['title'], ENT_QUOTES, 'UTF-8'), 'level' => (int)$old['level']), array('title' => $values['title'], 'level' => $level), $actor);
	}
	return array($id, '');
}
// Takes the files a form sent for an announcement, and writes each of them into the text of
// the announcement, at its end: a picture is there to be seen, a film to be played. Whoever
// wants them elsewhere moves the line. Returns array(how many were added, what was refused).
function announcementAddMedia($id, $field, $actor) {
	$id = (int)$id;
	$before = array();
	foreach (attachmentsOf('notice', $id) as $attachment) {
		$before[(int)$attachment['id']] = true;
	}
	list($added, $refused) = attachmentsAddUploaded('notice', $id, $field, $actor);
	$announcement = queryAnnouncement($id);
	if (!$announcement) {
		return array($added, $refused);
	}
	$content_md = $announcement['content_md'];
	$changed = false;
	foreach (attachmentsOf('notice', $id) as $attachment) {
		// a file that took the place of one of the same name is in the text already
		if (!isset($before[(int)$attachment['id']]) && strpos($content_md, attachmentUrl($attachment) . ')') === false && strpos($content_md, attachmentUrl($attachment) . '"') === false) {
			$content_md = rtrim($content_md) . "\n\n" . announcementMediaSnippet($attachment) . "\n";
			$changed = true;
		}
	}
	if ($changed) {
		DB::update("update blogs set content_md = '".DB::escape($content_md)."', content = '".DB::escape(announcementRender($content_md))."' where id = $id");
	}
	return array($added, $refused);
}
// the files of an announcement that its text does not show: they are listed under it
function announcementLooseFiles($announcement) {
	$loose = array();
	foreach (attachmentsOf('notice', $announcement['id']) as $attachment) {
		if (strpos($announcement['content_md'], attachmentUrl($attachment) . ')') === false && strpos($announcement['content_md'], attachmentUrl($attachment) . '"') === false) {
			$loose[] = $attachment;
		}
	}
	return $loose;
}
function announcementDelete($id, $actor) {
	$announcement = queryAnnouncement($id);
	if (!$announcement) {
		return '没有这条公告';
	}
	foreach (attachmentsOf('notice', $announcement['id']) as $attachment) {
		attachmentDelete($attachment, $actor);
	}
	deleteBlog($announcement['id']);
	auditLog('announcement.delete', 'announcement', $announcement['id'], array('title' => html_entity_decode($announcement['title'], ENT_QUOTES, 'UTF-8'), 'poster' => $announcement['poster']), null, $actor);
	return '';
}
