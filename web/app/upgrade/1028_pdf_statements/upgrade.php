<?php

// A statement that is a PDF file: an attachment of its problem that is shown as the statement,
// in a language (zh_cn, en, ...), instead of in the list of the files that come with it.
return function ($type) {
	if ($type == 'up') {
		Upgrader::addColumn('attachments', 'statement_lang', "varchar(20) DEFAULT NULL AFTER `name`");
	} else {
		Upgrader::dropColumn('attachments', 'statement_lang');
	}
};
