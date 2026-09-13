-- Messaging, Case Files (with encrypted vault), and the blockchain-backed
-- lawyer-to-lawyer data sharing workflow. These tables are also created
-- defensively at runtime (see config/messages/core.php,
-- config/case_files/core.php and config/blockchain/ledger.php), matching
-- the same self-healing pattern the rest of this app already uses for
-- rate_limits/video_call_*. Running this migration is the recommended
-- path; the runtime self-heal exists purely as a safety net.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `sender_id` INT NOT NULL,
    `receiver_id` INT NOT NULL,
    `case_id` INT DEFAULT NULL,
    `body` TEXT,
    `attachment_original_name` VARCHAR(255) DEFAULT NULL,
    `attachment_stored_name` VARCHAR(255) DEFAULT NULL,
    `attachment_mime_type` VARCHAR(120) DEFAULT NULL,
    `attachment_size` INT DEFAULT NULL,
    `is_important` TINYINT(1) NOT NULL DEFAULT 0,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_messages_sender` (`sender_id`),
    KEY `idx_messages_receiver` (`receiver_id`, `is_read`),
    KEY `idx_messages_case` (`case_id`),
    KEY `idx_messages_created_at` (`created_at`),
    CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_deletions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `message_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `deleted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_message_deletions` (`message_id`, `user_id`),
    KEY `idx_message_deletions_user` (`user_id`),
    CONSTRAINT `fk_message_deletions_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_message_deletions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `case_files` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_id` INT DEFAULT NULL,
    `full_name` VARCHAR(190) NOT NULL,
    `case_file_title` VARCHAR(190) NOT NULL,
    `description` TEXT,
    `client_user_id` INT NOT NULL,
    `assigned_lawyer_user_id` INT NOT NULL,
    `created_by_user_id` INT NOT NULL,
    `folder_name` VARCHAR(191) NOT NULL,
    `status` ENUM('open','ongoing','closed') NOT NULL DEFAULT 'open',
    `attachments_json` LONGTEXT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_files_folder_name` (`folder_name`),
    KEY `idx_case_files_client` (`client_user_id`),
    KEY `idx_case_files_lawyer` (`assigned_lawyer_user_id`),
    KEY `idx_case_files_status` (`status`),
    CONSTRAINT `fk_case_files_client_user` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_case_files_lawyer_user` FOREIGN KEY (`assigned_lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_case_files_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `case_file_folders` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_file_id` INT NOT NULL,
    `parent_id` INT NOT NULL DEFAULT 0,
    `slug` VARCHAR(64) NOT NULL,
    `name` VARCHAR(190) NOT NULL,
    `created_by_user_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_file_folders_parent` (`case_file_id`, `parent_id`, `slug`),
    KEY `idx_case_file_folders_parent` (`parent_id`),
    CONSTRAINT `fk_case_file_folders_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `case_file_documents` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_file_id` INT NOT NULL,
    `folder_id` INT NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(120) DEFAULT NULL,
    `file_size` INT DEFAULT NULL,
    `encryption_algorithm` VARCHAR(40) DEFAULT NULL,
    `encryption_iv` VARCHAR(64) DEFAULT NULL,
    `encryption_tag` VARCHAR(64) DEFAULT NULL,
    `upload_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `uploaded_by_user_id` INT NOT NULL,
    `reviewed_by_user_id` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `ledger_hash` CHAR(64) DEFAULT NULL,
    `ledger_block_index` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_case_file_documents_folder` (`folder_id`),
    KEY `idx_case_file_documents_case_file` (`case_file_id`),
    KEY `idx_case_file_documents_status` (`upload_status`),
    CONSTRAINT `fk_case_file_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_case_file_documents_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only, hash-chained ledger. See config/blockchain/ledger.php for
-- how block_index/previous_hash/hash are computed and verified.
CREATE TABLE IF NOT EXISTS `blockchain_ledger` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `block_index` INT NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `actor_user_id` INT DEFAULT NULL,
    `data_json` LONGTEXT NOT NULL,
    `previous_hash` CHAR(64) NOT NULL,
    `hash` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blockchain_ledger_index` (`block_index`),
    KEY `idx_blockchain_ledger_event` (`event_type`),
    KEY `idx_blockchain_ledger_actor` (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blockchain_ledger_lock` (
    `id` TINYINT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `blockchain_ledger_lock` (`id`) VALUES (1);

-- Lawyer A requests to share a case file with Lawyer B; an admin must
-- approve or reject before Lawyer B gains any access (case_file_shares).
-- Every request/decision is also recorded as a blockchain_ledger block.
CREATE TABLE IF NOT EXISTS `data_sharing_requests` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_file_id` INT NOT NULL,
    `from_lawyer_user_id` INT NOT NULL,
    `to_lawyer_user_id` INT NOT NULL,
    `note` TEXT,
    `status` ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
    `decided_by_user_id` INT DEFAULT NULL,
    `decision_note` TEXT,
    `decided_at` DATETIME DEFAULT NULL,
    `request_block_id` INT DEFAULT NULL,
    `decision_block_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_data_sharing_case_file` (`case_file_id`),
    KEY `idx_data_sharing_from` (`from_lawyer_user_id`),
    KEY `idx_data_sharing_to` (`to_lawyer_user_id`),
    KEY `idx_data_sharing_status` (`status`),
    CONSTRAINT `fk_data_sharing_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_data_sharing_from` FOREIGN KEY (`from_lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_data_sharing_to` FOREIGN KEY (`to_lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `case_file_shares` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_file_id` INT NOT NULL,
    `lawyer_user_id` INT NOT NULL,
    `request_id` INT NOT NULL,
    `granted_by_user_id` INT NOT NULL,
    `granted_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_file_shares_active` (`case_file_id`, `lawyer_user_id`),
    KEY `idx_case_file_shares_lawyer` (`lawyer_user_id`),
    CONSTRAINT `fk_case_file_shares_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_case_file_shares_lawyer` FOREIGN KEY (`lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_case_file_shares_request` FOREIGN KEY (`request_id`) REFERENCES `data_sharing_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
