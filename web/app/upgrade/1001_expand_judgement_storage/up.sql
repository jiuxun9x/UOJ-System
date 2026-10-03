-- The result of a judgement with many tests does not fit in a BLOB (64 KiB), and the JSON
-- configs of contests and problems outgrow their columns. MySQL truncated all of them silently.
--
-- These statements copy the tables, so run the upgrade while nobody is submitting.
ALTER TABLE `submissions` MODIFY `result` LONGBLOB NOT NULL;
ALTER TABLE `custom_test_submissions` MODIFY `result` LONGBLOB NOT NULL;
ALTER TABLE `hacks` MODIFY `details` LONGBLOB NOT NULL;
ALTER TABLE `contests` MODIFY `extra_config` VARCHAR(4000) NOT NULL;
ALTER TABLE `problems` MODIFY `extra_config` VARCHAR(4000) NOT NULL DEFAULT '{"view_content_type":"ALL","view_details_type":"ALL"}';
