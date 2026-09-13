-- Nested vault folders: a client folder at the root, with Documents / Pictures / Videos inside.
ALTER TABLE `case_file_folders`
    ADD COLUMN `parent_id` INT NOT NULL DEFAULT 0;

ALTER TABLE `case_file_folders`
    DROP INDEX `uq_case_file_folders`;

ALTER TABLE `case_file_folders`
    ADD UNIQUE KEY `uq_case_file_folders_parent` (`case_file_id`, `parent_id`, `slug`);

ALTER TABLE `case_file_folders`
    ADD KEY `idx_case_file_folders_parent` (`parent_id`);
