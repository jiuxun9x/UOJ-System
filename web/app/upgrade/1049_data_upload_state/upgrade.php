<?php

// What the files uploaded for a problem were when a version of its data was made of them. Every
// change of the files writes a new state (/var/uoj_data/upload/<id>.state); a sync writes down
// the state it read. A copy of a problem is made of its files only while they are still what its
// published data was made of: a copy took whatever was uploaded, published or not, and called it
// the version that was published. Versions from before have no state, and are told by the times
// the files were changed.
return function ($type) {
	if ($type == 'up') {
		if (!Upgrader::columnExists('problem_data_versions', 'upload_state')) {
			Upgrader::exec("alter table `problem_data_versions` add column `upload_state` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL");
		}
	} else {
		if (Upgrader::columnExists('problem_data_versions', 'upload_state')) {
			Upgrader::exec("alter table `problem_data_versions` drop column `upload_state`");
		}
	}
};
