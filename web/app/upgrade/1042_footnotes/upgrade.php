<?php

// Markdown has footnotes now ([^name] where a note is called, "[^name]: ..." for the note), as
// the statements of Codeforces and of Luogu have them. A statement is kept as the page it was
// made into when it was saved: the ones that call notes are made into pages anew, and the notes
// they called by name, as text, are footnotes.
return function ($type) {
	if ($type == 'up') {
		foreach (DB::selectAll("select id, statement_md from problems_contents where statement_md like '%[^%]:%'", MYSQLI_ASSOC) as $row) {
			$statement = HTML::pruifier()->purify(HTML::parsedown()->text($row['statement_md']));
			DB::update("update problems_contents set statement = '" . DB::escape($statement) . "' where id = " . (int)$row['id']);
		}
	}
	// (going back, they are as the Markdown of then made them the next time they are saved)
};
