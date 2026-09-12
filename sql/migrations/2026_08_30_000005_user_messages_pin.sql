-- Move the Messages PIN onto users so admins and lawyers both have one
-- PIN that unlocks all of their conversations. Runtime self-heal also
-- adds this column and copies any older lawyers.messages_pin_hash values.

SET NAMES utf8mb4;

ALTER TABLE `users`
    ADD COLUMN `messages_pin_hash` VARCHAR(255) DEFAULT NULL;
