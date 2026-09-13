-- Seal vault documents onto the hash-chained ledger.
ALTER TABLE `case_file_documents`
    ADD COLUMN `ledger_hash` CHAR(64) DEFAULT NULL;

ALTER TABLE `case_file_documents`
    ADD COLUMN `ledger_block_index` INT DEFAULT NULL;
