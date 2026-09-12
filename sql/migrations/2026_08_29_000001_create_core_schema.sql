-- Core LEXSHIELD schema, reconstructed from column/table usage across the
-- application code (config/db.php, config/bootstrap.php, and the original
-- sql/migrations/ directory were not present in the uploaded repository).
--
-- Tables that the application already creates for itself on first use are
-- intentionally NOT included here (they use `CREATE TABLE IF NOT EXISTS`
-- inside their own feature code, so a migration would be redundant):
--   - rate_limits          (security/rate_limiter.php)
--   - video_call_sessions, video_call_signals (config/video_call/helpers.php)
--   - messages, message_deletions, case_files, case_file_folders,
--     case_file_documents (config/messages/*.php, config/case_files/*.php -
--     still missing from this repo, but their "ensure" functions are)
--
-- Some columns below are best-effort reconstructions where only `table.*` or
-- `alias.*` selects were found in the code (no explicit column list). Adjust
-- if your real schema differs - nothing else in the app depends on exact
-- column order, only on the names used in queries.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(190) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','lawyer','client') NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `avatar_stored_name` VARCHAR(190) DEFAULT NULL,
    `failed_login_attempts` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `last_login` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clients` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `contact_number` VARCHAR(40) DEFAULT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `risk_level` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_clients_user_id` (`user_id`),
    CONSTRAINT `fk_clients_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lawyers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `bar_number` VARCHAR(60) DEFAULT NULL,
    `specialization` VARCHAR(190) DEFAULT NULL,
    `status` ENUM('active','busy','inactive') NOT NULL DEFAULT 'active',
    `bio` TEXT,
    `background` TEXT,
    `contact_number` VARCHAR(40) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lawyers_user_id` (`user_id`),
    KEY `idx_lawyers_status` (`status`),
    CONSTRAINT `fk_lawyers_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cases` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_number` VARCHAR(60) NOT NULL,
    `title` VARCHAR(190) DEFAULT NULL,
    `description` TEXT,
    `lawyer_id` INT NOT NULL,
    `client_id` INT NOT NULL,
    `status` ENUM('open','ongoing','closed','archived') NOT NULL DEFAULT 'open',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
    `filed_date` DATE DEFAULT NULL,
    `closed_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cases_case_number` (`case_number`),
    KEY `idx_cases_lawyer` (`lawyer_id`),
    KEY `idx_cases_client` (`client_id`),
    KEY `idx_cases_status` (`status`),
    CONSTRAINT `fk_cases_lawyer_id` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cases_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `appointments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_id` INT NOT NULL,
    `client_id` INT NOT NULL,
    `lawyer_id` INT NOT NULL,
    `scheduled_at` DATETIME NOT NULL,
    `appointment_type` VARCHAR(120) DEFAULT NULL,
    `status` ENUM('pending','confirmed','cancelled','deleted') NOT NULL DEFAULT 'pending',
    `notes` TEXT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_appointments_case` (`case_id`),
    KEY `idx_appointments_client` (`client_id`),
    KEY `idx_appointments_lawyer` (`lawyer_id`),
    KEY `idx_appointments_scheduled_at` (`scheduled_at`),
    CONSTRAINT `fk_appointments_case_id` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_appointments_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_appointments_lawyer_id` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lawyer_reviews` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `lawyer_id` INT NOT NULL,
    `client_id` INT NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `comment` TEXT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lawyer_reviews_lawyer_client` (`lawyer_id`, `client_id`),
    KEY `idx_lawyer_reviews_client` (`client_id`),
    CONSTRAINT `fk_lawyer_reviews_lawyer_id` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lawyer_reviews_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manual_payments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `client_id` INT NOT NULL,
    `payment_channel` VARCHAR(30) NOT NULL DEFAULT 'gcash',
    `payment_for` VARCHAR(190) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'PHP',
    `payer_name` VARCHAR(190) DEFAULT NULL,
    `payer_contact` VARCHAR(60) DEFAULT NULL,
    `reference_number` VARCHAR(120) DEFAULT NULL,
    `notes` TEXT,
    `proof_original_name` VARCHAR(255) DEFAULT NULL,
    `proof_stored_name` VARCHAR(255) DEFAULT NULL,
    `proof_mime_type` VARCHAR(120) DEFAULT NULL,
    `proof_size` INT DEFAULT NULL,
    `status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    `admin_notes` TEXT,
    `reviewed_by_user_id` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_manual_payments_client` (`client_id`),
    KEY `idx_manual_payments_status` (`status`),
    CONSTRAINT `fk_manual_payments_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_manual_payments_reviewed_by` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `phishing_scans` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT DEFAULT NULL,
    `submitted_url` TEXT NOT NULL,
    `final_url` TEXT,
    `status` ENUM('safe','suspicious','phishing') NOT NULL DEFAULT 'safe',
    `score` INT NOT NULL DEFAULT 0,
    `findings_json` TEXT,
    `redirect_count` INT NOT NULL DEFAULT 0,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_phishing_scans_user` (`user_id`),
    KEY `idx_phishing_scans_status` (`status`),
    CONSTRAINT `fk_phishing_scans_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quick_inquiries` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(190) DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `phone` VARCHAR(60) DEFAULT NULL,
    `topic` VARCHAR(190) DEFAULT NULL,
    `message` TEXT,
    `status` ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_quick_inquiries_status` (`status`),
    KEY `idx_quick_inquiries_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_settings` (
    `setting_key` VARCHAR(191) NOT NULL,
    `setting_value` TEXT,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_otps` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(190) NOT NULL,
    `otp_hash` VARCHAR(255) NOT NULL,
    `purpose` VARCHAR(64) NOT NULL,
    `is_used` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_otps_email_purpose` (`email`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `is_used` TINYINT(1) NOT NULL DEFAULT 0,
    `used_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_password_resets_user` (`user_id`),
    KEY `idx_password_resets_token_hash` (`token_hash`),
    CONSTRAINT `fk_password_resets_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(128) NOT NULL,
    `target_table` VARCHAR(64) DEFAULT NULL,
    `target_id` VARCHAR(64) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `performed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_logs_user` (`user_id`),
    KEY `idx_audit_logs_action` (`action`),
    KEY `idx_audit_logs_performed_at` (`performed_at`),
    CONSTRAINT `fk_audit_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `type` VARCHAR(40) NOT NULL DEFAULT 'general',
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user` (`user_id`, `is_read`),
    CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional / legacy: referenced defensively (lex_manage_clients_table_exists())
-- by admin/manage_clients.php, so the app works fine whether or not this
-- table exists. Included for completeness since it is also read directly.
CREATE TABLE IF NOT EXISTS `risk_assessments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `client_id` INT NOT NULL,
    `risk_level` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
    `notes` TEXT,
    `assessed_by_user_id` INT DEFAULT NULL,
    `assessed_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_risk_assessments_client` (`client_id`),
    CONSTRAINT `fk_risk_assessments_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_risk_assessments_assessed_by` FOREIGN KEY (`assessed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
