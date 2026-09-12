-- AES-256-GCM at-rest encryption for chat bodies and attachments.
-- Runtime self-heal in lex_messages_encryption_columns_ensure() also
-- adds these columns, so this migration is safe to skip on a live app.

SET NAMES utf8mb4;

ALTER TABLE `messages`
    ADD COLUMN `body_encryption_algorithm` VARCHAR(40) DEFAULT NULL,
    ADD COLUMN `body_encryption_iv` VARCHAR(64) DEFAULT NULL,
    ADD COLUMN `body_encryption_tag` VARCHAR(64) DEFAULT NULL,
    ADD COLUMN `attachment_encryption_algorithm` VARCHAR(40) DEFAULT NULL,
    ADD COLUMN `attachment_encryption_iv` VARCHAR(64) DEFAULT NULL,
    ADD COLUMN `attachment_encryption_tag` VARCHAR(64) DEFAULT NULL;
