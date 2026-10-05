<?php
	// Sends a file that comes with a problem or a contest to somebody who may have it.
	if (!validateUInt($_GET['id']) || !($attachment = queryAttachment($_GET['id']))) {
		become404Page();
	}
	// to whoever may not have it, it is not there
	if (!can($myUser, 'attachment.view', $attachment)) {
		if ($myUser == null) {
			redirectToLogin();
		}
		become404Page();
	}
	$file_name = attachmentPath($attachment);
	if (!is_file($file_name)) {
		become404Page();
	}
	// The name as the browser is to save it: plain for browsers that know no better, and as
	// it is for the others.
	$plain = preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment['name']);
	$inline_type = attachmentInlineType($attachment['name']);
	$inline = $inline_type !== null;
	header("X-Sendfile: $file_name");
	header('Content-Type: ' . ($inline ? $inline_type : 'application/octet-stream'));
	header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"$plain\"; filename*=UTF-8''" . rawurlencode($attachment['name']));
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: private, max-age=0, must-revalidate');
?>
