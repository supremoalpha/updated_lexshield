<?php

declare(strict_types=1);

/**
 * Always-loaded case file / vault primitives. files/cases/attachment.php
 * and files/cases/document.php only require config/bootstrap.php and call
 * these functions directly, so they cannot live only in helpers.php.
 */

const LEX_CASE_FILE_CATEGORIES = ['DOCUMENTS', 'PHOTOS', 'EVIDENCE', 'COURT_FILINGS', 'CORRESPONDENCE'];

if (!function_exists('lex_case_files_table_ensure')) {
    function lex_case_files_table_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        lex_db_retry(static function () use (&$done): void {
            lex_pdo()->exec(
                "CREATE TABLE IF NOT EXISTS `case_files` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        });
    }
}

if (!function_exists('lex_case_file_vault_table_ensure')) {
    function lex_case_file_vault_table_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        lex_db_retry(static function () use (&$done): void {
            $pdo = lex_pdo();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `case_file_folders` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `case_file_id` INT NOT NULL,
                    `slug` VARCHAR(64) NOT NULL,
                    `name` VARCHAR(190) NOT NULL,
                    `created_by_user_id` INT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_case_file_folders` (`case_file_id`, `slug`),
                    CONSTRAINT `fk_case_file_folders_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `case_file_documents` (
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
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_case_file_documents_folder` (`folder_id`),
                    KEY `idx_case_file_documents_case_file` (`case_file_id`),
                    KEY `idx_case_file_documents_status` (`upload_status`),
                    CONSTRAINT `fk_case_file_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_case_file_documents_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        });
    }
}

if (!function_exists('lex_case_files_folder_path')) {
    function lex_case_files_folder_path(string $folderName): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $folderName) ?? '';
        $safe = $safe !== '' ? $safe : 'CF-UNKNOWN';
        return lex_storage_ensure_dir(lex_storage_path('case_files/' . $safe));
    }
}

if (!function_exists('lex_case_files_recursive_delete')) {
    /**
     * Recursively deletes a directory (and everything inside it) from
     * disk. Used when an owning record (e.g. a client) is permanently
     * deleted. Silently does nothing for paths outside storage/case_files
     * or paths that do not exist, as a guard against accidental misuse.
     */
    function lex_case_files_recursive_delete(string $path): void
    {
        $root = realpath(lex_storage_path('case_files'));
        $real = realpath($path);
        if ($root === false || $real === false || !str_starts_with($real, $root)) {
            return;
        }
        if (!is_dir($real)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir((string) $item->getRealPath());
            } else {
                @unlink((string) $item->getRealPath());
            }
        }
        @rmdir($real);
    }
}

if (!function_exists('lex_case_file_vault_slug')) {
    function lex_case_file_vault_slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? substr($slug, 0, 64) : 'general';
    }
}

if (!function_exists('lex_case_file_vault_access')) {
    /**
     * Returns 'manage' (full control: creator/assigned lawyer or admin),
     * 'client' (own case: approved documents), 'shared' (view-only grant),
     * or 'none'.
     *
     * @param array{id?:int,client_user_id:int,assigned_lawyer_user_id:int,created_by_user_id:int} $caseFile
     * @param array{id:int,role:string} $user
     */
    function lex_case_file_vault_access(array $caseFile, array $user): string
    {
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);

        if ($role === 'admin') {
            return 'manage';
        }

        if ($role === 'lawyer' && ($userId === (int) $caseFile['assigned_lawyer_user_id'] || $userId === (int) $caseFile['created_by_user_id'])) {
            return 'manage';
        }

        if ($role === 'client' && $userId === (int) $caseFile['client_user_id']) {
            return 'client';
        }

        // Admin-approved case file sharing: the other lawyer can see every
        // approved file but cannot download, copy, or save it.
        if ($role === 'lawyer' && function_exists('lex_data_sharing_has_access')
            && lex_data_sharing_has_access((int) ($caseFile['id'] ?? 0), $userId)) {
            return 'shared';
        }

        return 'none';
    }
}

if (!function_exists('lex_case_file_is_view_only')) {
    function lex_case_file_is_view_only(string $access): bool
    {
        return $access === 'shared';
    }
}

if (!function_exists('lex_case_file_previewable_mime')) {
    function lex_case_file_previewable_mime(string $mime): bool
    {
        return (bool) preg_match('/^(image\/|application\/pdf$|text\/)/', $mime);
    }
}

if (!function_exists('lex_case_file_view_url')) {
    function lex_case_file_view_url(array $query): string
    {
        $query = array_filter($query, static fn ($value) => $value !== '' && $value !== null);
        $href = 'case_file_view.php';
        if ($query !== []) {
            $href .= '?' . http_build_query($query);
        }

        return function_exists('lex_nav_href') ? lex_nav_href($href) : lex_app_url($href);
    }
}

if (!function_exists('lex_case_file_view_token_issue')) {
    function lex_case_file_view_token_issue(int $userId): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['lex_case_view_token'] = [
            't' => $token,
            'exp' => time() + 180,
            'uid' => $userId,
        ];

        return $token;
    }
}

if (!function_exists('lex_case_file_view_token_ok')) {
    function lex_case_file_view_token_ok(string $token, int $userId): bool
    {
        if ($token === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $row = $_SESSION['lex_case_view_token'] ?? null;
        if (!is_array($row)) {
            return false;
        }
        $stored = (string) ($row['t'] ?? '');
        $exp = (int) ($row['exp'] ?? 0);
        $uid = (int) ($row['uid'] ?? 0);

        return $stored !== ''
            && hash_equals($stored, $token)
            && $exp >= time()
            && $uid === $userId;
    }
}

if (!function_exists('lex_case_file_send_view_only_headers')) {
    function lex_case_file_send_view_only_headers(string $mime, string $name, int $size): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
    }
}

if (!function_exists('lex_case_file_document_encrypt')) {
    /**
     * AES-256-GCM encrypts $plaintext. Returns the ciphertext plus the
     * base64 IV/tag needed to decrypt it later (stored alongside the row).
     *
     * @return array{ciphertext:string, iv:string, tag:string, algorithm:string}
     */
    function lex_case_file_document_encrypt(string $plaintext): array
    {
        $algorithm = 'aes-256-gcm';
        $key = hash('sha256', lex_app_encryption_key(), true);
        $iv = random_bytes(openssl_cipher_iv_length($algorithm));
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, $algorithm, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt document.');
        }

        return [
            'ciphertext' => $ciphertext,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'algorithm' => $algorithm,
        ];
    }
}

if (!function_exists('lex_case_file_document_decrypt')) {
    /**
     * @param array{encryption_algorithm?:?string, encryption_iv?:?string, encryption_tag?:?string} $document
     */
    function lex_case_file_document_decrypt(string $cipherData, array $document): string
    {
        $algorithm = (string) ($document['encryption_algorithm'] ?? '');
        if ($algorithm === '') {
            return $cipherData;
        }

        $key = hash('sha256', lex_app_encryption_key(), true);
        $iv = base64_decode((string) ($document['encryption_iv'] ?? ''), true) ?: '';
        $tag = base64_decode((string) ($document['encryption_tag'] ?? ''), true) ?: '';

        $plaintext = openssl_decrypt($cipherData, $algorithm, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt document (integrity check failed).');
        }

        return $plaintext;
    }
}
