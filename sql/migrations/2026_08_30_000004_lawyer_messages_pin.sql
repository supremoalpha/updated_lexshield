-- Personal message-code hash for attorneys. After logout they must enter
-- this code before client conversations are shown. Runtime self-heal in
-- lex_messages_lock_columns_ensure() also adds the column.

SET NAMES utf8mb4;

ALTER TABLE `lawyers`
    ADD COLUMN `messages_pin_hash` VARCHAR(255) DEFAULT NULL;
