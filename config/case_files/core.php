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
                    `parent_id` INT NOT NULL DEFAULT 0,
                    `slug` VARCHAR(64) NOT NULL,
                    `name` VARCHAR(190) NOT NULL,
                    `created_by_user_id` INT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_case_file_folders_parent` (`case_file_id`, `parent_id`, `slug`),
                    KEY `idx_case_file_folders_parent` (`parent_id`),
                    CONSTRAINT `fk_case_file_folders_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            lex_case_file_vault_folder_parent_ensure($pdo);
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
                    `ledger_hash` CHAR(64) DEFAULT NULL,
                    `ledger_block_index` INT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_case_file_documents_folder` (`folder_id`),
                    KEY `idx_case_file_documents_case_file` (`case_file_id`),
                    KEY `idx_case_file_documents_status` (`upload_status`),
                    CONSTRAINT `fk_case_file_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_case_file_documents_case_file` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            lex_case_file_document_ledger_columns_ensure($pdo);
            $done = true;
        });
    }
}

if (!function_exists('lex_case_file_vault_folder_parent_ensure')) {
    function lex_case_file_vault_folder_parent_ensure(PDO $pdo): void
    {
        try {
            $pdo->exec('ALTER TABLE `case_file_folders` ADD COLUMN `parent_id` INT NOT NULL DEFAULT 0');
        } catch (PDOException $e) {
            // Column already exists on an older install.
        }

        try {
            $pdo->exec('ALTER TABLE `case_file_folders` DROP INDEX `uq_case_file_folders`');
        } catch (PDOException $e) {
            // Unique key already replaced, or this install used the nested key from the start.
        }

        try {
            $pdo->exec('ALTER TABLE `case_file_folders` ADD UNIQUE KEY `uq_case_file_folders_parent` (`case_file_id`, `parent_id`, `slug`)');
        } catch (PDOException $e) {
            // Nested unique key already exists.
        }

        try {
            $pdo->exec('ALTER TABLE `case_file_folders` ADD KEY `idx_case_file_folders_parent` (`parent_id`)');
        } catch (PDOException $e) {
            // Parent index already exists.
        }
    }
}

if (!function_exists('lex_case_file_document_ledger_columns_ensure')) {
    function lex_case_file_document_ledger_columns_ensure(PDO $pdo): void
    {
        try {
            $pdo->exec('ALTER TABLE `case_file_documents` ADD COLUMN `ledger_hash` CHAR(64) DEFAULT NULL');
        } catch (PDOException $e) {
            // Column already exists.
        }
        try {
            $pdo->exec('ALTER TABLE `case_file_documents` ADD COLUMN `ledger_block_index` INT DEFAULT NULL');
        } catch (PDOException $e) {
            // Column already exists.
        }
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

if (!function_exists('lex_case_file_vault_relative_dir')) {
    function lex_case_file_vault_relative_dir(array $folder): string
    {
        $slug = lex_case_file_vault_slug((string) ($folder['slug'] ?? $folder['folder_slug'] ?? 'general'));
        $id = (int) ($folder['folder_id'] ?? 0);
        if ($id <= 0) {
            $id = (int) ($folder['id'] ?? 0);
        }

        return $id > 0 ? ($id . '-' . $slug) : $slug;
    }
}

if (!function_exists('lex_case_file_document_abs_path')) {
    function lex_case_file_document_abs_path(string $caseFolderName, array $folder, string $storedName): string
    {
        $base = lex_case_files_folder_path($caseFolderName);
        $stored = basename($storedName);
        $candidates = [
            $base . DIRECTORY_SEPARATOR . lex_case_file_vault_relative_dir($folder) . DIRECTORY_SEPARATOR . $stored,
            $base . DIRECTORY_SEPARATOR . lex_case_file_vault_slug((string) ($folder['slug'] ?? 'general')) . DIRECTORY_SEPARATOR . $stored,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }
}

if (!function_exists('lex_case_file_vault_access')) {
    /**
     * Returns 'manage' (full control: creator/assigned lawyer or admin),
     * 'client' (read-only, approved documents only) or 'none'.
     *
     * @param array{client_user_id:int,assigned_lawyer_user_id:int,created_by_user_id:int} $caseFile
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

        // A lawyer who is neither the creator nor the assigned lawyer can
        // still be granted read access to this case file's vault through
        // the admin-approved, blockchain-logged data sharing workflow.
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

if (!function_exists('lex_case_file_guess_mime')) {
    function lex_case_file_guess_mime(string $mime, string $name): string
    {
        $mime = strtolower(trim($mime));
        if ($mime !== '' && preg_match('/^(image\/|video\/|application\/pdf$|text\/)/', $mime) === 1) {
            return $mime;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'txt', 'log', 'md', 'csv' => 'text/plain',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            '3gp' => 'video/3gpp',
            default => $mime !== '' ? $mime : 'application/octet-stream',
        };
    }
}

if (!function_exists('lex_case_file_previewable_mime')) {
    function lex_case_file_previewable_mime(string $mime, string $name = ''): bool
    {
        if ($name !== '' && function_exists('lex_case_file_guess_mime')) {
            $mime = lex_case_file_guess_mime($mime, $name);
        }

        return (bool) preg_match('/^(image\/|video\/|application\/pdf$|text\/)/', $mime);
    }
}

if (!function_exists('lex_case_file_upload_max_bytes')) {
    function lex_case_file_upload_max_bytes(): int
    {
        return 80 * 1024 * 1024;
    }
}

if (!function_exists('lex_case_file_upload_accept')) {
    function lex_case_file_upload_accept(): string
    {
        return 'image/*,video/*,.pdf,.txt,.csv,.doc,.docx';
    }
}

if (!function_exists('lex_case_file_is_allowed_upload')) {
    function lex_case_file_is_allowed_upload(string $mime, string $name = ''): bool
    {
        if ($name !== '' && function_exists('lex_case_file_guess_mime')) {
            $mime = lex_case_file_guess_mime($mime, $name);
        }

        return (bool) preg_match(
            '/^(image\/(jpeg|png|gif|webp)$|video\/(mp4|webm|quicktime|3gpp|x-m4v)$|application\/pdf$|text\/|application\/(msword|vnd\.openxmlformats-officedocument\.wordprocessingml\.document)$)/',
            $mime
        );
    }
}

if (!function_exists('lex_case_file_detect_upload_mime')) {
    function lex_case_file_detect_upload_mime(array $file): string
    {
        $name = (string) ($file['name'] ?? '');
        $tmp = (string) ($file['tmp_name'] ?? '');
        $mime = '';
        if ($tmp !== '' && is_file($tmp) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }

        return function_exists('lex_case_file_guess_mime')
            ? lex_case_file_guess_mime($mime, $name)
            : ($mime !== '' ? $mime : 'application/octet-stream');
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
        header('X-Frame-Options: SAMEORIGIN', true);
        header(
            "Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'",
            true
        );
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
    }
}

if (!function_exists('lex_case_file_watermark_data_uri')) {
    function lex_case_file_watermark_data_uri(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="520" height="200" viewBox="0 0 520 200">'
            . '<text x="260" y="100" fill="rgba(148,163,184,0.28)" font-family="Arial,sans-serif" '
            . 'font-size="16" font-weight="700" text-anchor="middle" dominant-baseline="middle" '
            . 'transform="rotate(-18 260 100)">' . $safe . '</text></svg>';

        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
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

if (!function_exists('lex_case_files_owns_or_manages')) {
    function lex_case_files_owns_or_manages(PDO $pdo, int $caseFileId, array $user): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $caseFileId]);
        $record = $stmt->fetch();
        if (!$record) {
            return null;
        }

        $access = lex_case_file_vault_access([
            'id' => (int) $record['id'],
            'client_user_id' => (int) $record['client_user_id'],
            'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
            'created_by_user_id' => (int) $record['created_by_user_id'],
        ], $user);

        return $access === 'manage' ? $record : null;
    }
}

if (!function_exists('lex_case_files_handle_post')) {
    /**
     * Always defined from core.php so Case Files works on XAMPP even when
     * config/case_files/actions.php is missing or is an older copy.
     *
     * @return array{error:string, failed_action:string}
     */
    function lex_case_files_handle_post(PDO $pdo, array $user, array $filters, array $clients, array $lawyers): array
    {
        if (!function_exists('lex_case_files_collect_request_filters')) {
            $helpers = __DIR__ . '/helpers.php';
            if (is_file($helpers)) {
                require_once $helpers;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['error' => '', 'failed_action' => ''];
        }

        $action = (string) ($_POST['action'] ?? '');

        if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
            lex_audit_csrf_failure('case_files.php:' . $action);
            return ['error' => 'Invalid CSRF token. Please try again.', 'failed_action' => $action];
        }

        $redirect = static function (array $overrides = []) use ($filters) {
            $params = array_filter([
                'q' => $filters['q'],
                'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
                'sort' => $filters['sort'] !== 'updated_at' ? $filters['sort'] : null,
                'dir' => strtoupper($filters['dir']) !== 'DESC' ? $filters['dir'] : null,
                'page' => $filters['page'] > 1 ? $filters['page'] : null,
                'folder' => (($overrides['folder'] ?? $filters['folder'] ?? 0) > 0)
                    ? (int) ($overrides['folder'] ?? $filters['folder'])
                    : null,
            ] + $overrides, static function ($v) {
                return $v !== null && $v !== '';
            });
            header('Location: ' . lex_app_url('case_files.php') . (($params) ? ('?' . http_build_query($params)) : ''));
            exit;
        };

        try {
            if ($action === 'create' && $filters['role'] === 'lawyer') {
                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $title = lex_sanitize_text($_POST['case_file_title'] ?? '');
                $clientUserId = lex_sanitize_int($_POST['client_user_id'] ?? 0);
                $lawyerUserId = lex_sanitize_int($_POST['assigned_lawyer_user_id'] ?? $user['id']);
                $status = lex_safe_identifier((string) ($_POST['status'] ?? 'open'), ['open', 'ongoing', 'closed'], 'open');
                $description = lex_sanitize_multiline_text($_POST['description'] ?? '');

                if ($fullName === '' || $title === '' || $clientUserId <= 0) {
                    return ['error' => 'Full name, client, and case file title are required.', 'failed_action' => 'create'];
                }

                $folderName = 'CF-' . bin2hex(random_bytes(6));
                $stmt = $pdo->prepare(
                    'INSERT INTO case_files (full_name, case_file_title, description, client_user_id, assigned_lawyer_user_id, created_by_user_id, folder_name, status)
                     VALUES (:full_name, :title, :description, :client_user_id, :lawyer_user_id, :created_by, :folder_name, :status)'
                );
                $stmt->execute([
                    'full_name' => $fullName,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'client_user_id' => $clientUserId,
                    'lawyer_user_id' => $lawyerUserId,
                    'created_by' => (int) $user['id'],
                    'folder_name' => $folderName,
                    'status' => $status,
                ]);
                $newId = (int) $pdo->lastInsertId();
                if (function_exists('lex_case_files_ensure_client_vault_tree')) {
                    lex_case_file_vault_table_ensure();
                    lex_case_files_ensure_client_vault_tree($pdo, [
                        'id' => $newId,
                        'full_name' => $fullName,
                        'client_user_id' => $clientUserId,
                    ], (int) $user['id']);
                } elseif (function_exists('lex_case_files_ensure_default_vault_folders')) {
                    lex_case_file_vault_table_ensure();
                    lex_case_files_ensure_default_vault_folders($pdo, $newId, (int) $user['id']);
                }
                lex_audit('create_case_file', 'case_files', (string) $newId);
                lex_flash_set('success', 'Case file created.');
                $redirect(['record' => $newId]);
            }

            if ($action === 'update') {
                $caseFileId = lex_sanitize_int($_POST['case_id'] ?? 0);
                $record = lex_case_files_owns_or_manages($pdo, $caseFileId, $user);
                if (!$record) {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'update'];
                }

                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $title = lex_sanitize_text($_POST['case_file_title'] ?? '');
                $status = lex_safe_identifier((string) ($_POST['status'] ?? 'open'), ['open', 'ongoing', 'closed'], (string) $record['status']);
                $description = lex_sanitize_multiline_text($_POST['description'] ?? '');

                if ($fullName === '' || $title === '') {
                    return ['error' => 'Full name and case file title are required.', 'failed_action' => 'update'];
                }

                $pdo->prepare('UPDATE case_files SET full_name = :full_name, case_file_title = :title, description = :description, status = :status WHERE id = :id')
                    ->execute([
                        'full_name' => $fullName,
                        'title' => $title,
                        'description' => $description !== '' ? $description : null,
                        'status' => $status,
                        'id' => $caseFileId,
                    ]);
                lex_audit('update_case_file', 'case_files', (string) $caseFileId);
                lex_flash_set('success', 'Case file updated.');
                $redirect(['record' => $caseFileId]);
            }

            if ($action === 'upload_attachment') {
                $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $caseFileId]);
                $record = $stmt->fetch();
                $access = $record ? lex_case_file_vault_access([
                    'id' => (int) $record['id'],
                    'client_user_id' => (int) $record['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $record['created_by_user_id'],
                ], $user) : 'none';

                if (!$record || $access === 'none') {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'upload_attachment'];
                }

                $category = lex_safe_identifier((string) ($_POST['category'] ?? 'DOCUMENTS'), LEX_CASE_FILE_CATEGORIES, 'DOCUMENTS');
                $file = $_FILES['attachment'] ?? [];
                if (empty($file['name'])) {
                    return ['error' => 'Choose a file to upload.', 'failed_action' => 'upload_attachment'];
                }
                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
                    return ['error' => 'The file failed to upload or is larger than 20 MB.', 'failed_action' => 'upload_attachment'];
                }

                try {
                    lex_virus_scan_upload($file);
                } catch (RuntimeException $e) {
                    return ['error' => $e->getMessage(), 'failed_action' => 'upload_attachment'];
                }

                $folderPath = lex_case_files_folder_path((string) $record['folder_name']) . DIRECTORY_SEPARATOR . $category;
                lex_storage_ensure_dir($folderPath);
                $storedName = bin2hex(random_bytes(16));
                $originalName = lex_sanitize_filename(basename((string) $file['name']));
                $mime = 'application/octet-stream';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $mime = (string) finfo_file($finfo, (string) $file['tmp_name']);
                        finfo_close($finfo);
                    }
                }
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $storedFileName = $storedName . ($extension !== '' ? ('.' . $extension) : '');

                if (!move_uploaded_file((string) $file['tmp_name'], $folderPath . DIRECTORY_SEPARATOR . $storedFileName)) {
                    return ['error' => 'Unable to save the file.', 'failed_action' => 'upload_attachment'];
                }

                $attachments = function_exists('lex_case_files_parse_attachments')
                    ? lex_case_files_parse_attachments((string) ($record['attachments_json'] ?? '[]'))
                    : [];
                $attachments[] = [
                    'name' => $originalName,
                    'stored_name' => $storedFileName,
                    'mime_type' => $mime,
                    'size' => (int) filesize($folderPath . DIRECTORY_SEPARATOR . $storedFileName),
                    'category' => $category,
                    'uploaded_at' => date('c'),
                    'uploaded_by' => (int) $user['id'],
                ];
                $pdo->prepare('UPDATE case_files SET attachments_json = :json WHERE id = :id')
                    ->execute(['json' => json_encode($attachments, JSON_UNESCAPED_SLASHES), 'id' => $caseFileId]);
                lex_audit('upload_case_file_attachment', 'case_files', (string) $caseFileId);
                lex_flash_set('success', 'Attachment uploaded.');
                $redirect(['record' => $caseFileId]);
            }

            if ($action === 'vault_upload') {
                $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $caseFileId]);
                $record = $stmt->fetch();
                $access = $record ? lex_case_file_vault_access([
                    'id' => (int) $record['id'],
                    'client_user_id' => (int) $record['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $record['created_by_user_id'],
                ], $user) : 'none';

                if (!$record || $access === 'none' || (function_exists('lex_case_file_is_view_only') && lex_case_file_is_view_only($access))) {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'vault_upload'];
                }

                $file = $_FILES['document'] ?? [];
                if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    return ['error' => 'Choose a picture, video, or document to upload.', 'failed_action' => 'vault_upload'];
                }
                $maxBytes = function_exists('lex_case_file_upload_max_bytes') ? lex_case_file_upload_max_bytes() : (80 * 1024 * 1024);
                if ((int) ($file['size'] ?? 0) > $maxBytes) {
                    return ['error' => 'Files must be smaller than 80 MB.', 'failed_action' => 'vault_upload'];
                }

                $mime = function_exists('lex_case_file_detect_upload_mime')
                    ? lex_case_file_detect_upload_mime($file)
                    : 'application/octet-stream';
                $originalName = lex_sanitize_filename(basename((string) $file['name']));
                if (function_exists('lex_case_file_is_allowed_upload') && !lex_case_file_is_allowed_upload($mime, $originalName)) {
                    return ['error' => 'Upload a picture (JPG, PNG, GIF, WEBP), a video (MP4, WEBM, MOV), or a document (PDF, TXT).', 'failed_action' => 'vault_upload'];
                }

                try {
                    lex_virus_scan_upload($file);
                } catch (RuntimeException $e) {
                    return ['error' => $e->getMessage(), 'failed_action' => 'vault_upload'];
                }

                lex_case_file_vault_table_ensure();
                $folder = null;
                $postedFolderId = lex_sanitize_int($_POST['folder_id'] ?? 0);
                $stayFolderId = lex_sanitize_int($_POST['folder'] ?? $filters['folder'] ?? 0);
                $clientFolder = function_exists('lex_case_files_ensure_client_vault_tree')
                    ? lex_case_files_ensure_client_vault_tree($pdo, $record, (int) $user['id'])
                    : null;
                if ($postedFolderId > 0) {
                    $folderStmt = $pdo->prepare('SELECT * FROM case_file_folders WHERE id = :id AND case_file_id = :case_file_id LIMIT 1');
                    $folderStmt->execute(['id' => $postedFolderId, 'case_file_id' => $caseFileId]);
                    $folder = $folderStmt->fetch() ?: null;
                }
                if (!$folder) {
                    $suggested = function_exists('lex_case_file_suggested_folder_name')
                        ? lex_case_file_suggested_folder_name($mime, $originalName)
                        : 'Documents';
                    $typeParentId = $clientFolder ? (int) $clientFolder['id'] : 0;
                    $folder = lex_case_files_ensure_vault_folder($pdo, $caseFileId, $suggested, (int) $user['id'], $typeParentId);
                }

                $plaintext = file_get_contents((string) $file['tmp_name']);
                if ($plaintext === false) {
                    return ['error' => 'Unable to read the uploaded file.', 'failed_action' => 'vault_upload'];
                }
                $encrypted = lex_case_file_document_encrypt($plaintext);

                $storedName = bin2hex(random_bytes(16)) . '.enc';
                $relativeDir = function_exists('lex_case_file_vault_relative_dir')
                    ? lex_case_file_vault_relative_dir($folder)
                    : lex_case_file_vault_slug((string) $folder['slug']);
                $folderPath = lex_case_files_folder_path((string) $record['folder_name']) . DIRECTORY_SEPARATOR . $relativeDir;
                lex_storage_ensure_dir($folderPath);
                file_put_contents($folderPath . DIRECTORY_SEPARATOR . $storedName, $encrypted['ciphertext'], LOCK_EX);

                $status = $access === 'manage' ? 'approved' : 'pending';
                $kindLabel = str_starts_with($mime, 'image/') ? 'Picture' : (str_starts_with($mime, 'video/') ? 'Video' : 'File');
                $pdo->prepare(
                    'INSERT INTO case_file_documents (case_file_id, folder_id, original_name, stored_name, mime_type, file_size, encryption_algorithm, encryption_iv, encryption_tag, upload_status, uploaded_by_user_id)
                     VALUES (:case_file_id, :folder_id, :original_name, :stored_name, :mime_type, :file_size, :algorithm, :iv, :tag, :status, :uploaded_by)'
                )->execute([
                    'case_file_id' => $caseFileId,
                    'folder_id' => (int) $folder['id'],
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'mime_type' => $mime,
                    'file_size' => strlen($plaintext),
                    'algorithm' => $encrypted['algorithm'],
                    'iv' => $encrypted['iv'],
                    'tag' => $encrypted['tag'],
                    'status' => $status,
                    'uploaded_by' => (int) $user['id'],
                ]);

                $documentId = (int) $pdo->lastInsertId();
                $contentHash = hash('sha256', $plaintext);
                $block = function_exists('lex_vault_blockchain_record')
                    ? lex_vault_blockchain_record('vault_file_uploaded', [
                        'case_file_id' => $caseFileId,
                        'document_id' => $documentId,
                        'folder_id' => (int) $folder['id'],
                        'folder_name' => (string) $folder['name'],
                        'original_name' => $originalName,
                        'mime_type' => $mime,
                        'file_size' => strlen($plaintext),
                        'content_hash' => $contentHash,
                        'upload_status' => $status,
                    ], (int) $user['id'])
                    : null;
                if ($block) {
                    try {
                        $pdo->prepare('UPDATE case_file_documents SET ledger_hash = :hash, ledger_block_index = :block_index WHERE id = :id')
                            ->execute([
                                'hash' => (string) $block['hash'],
                                'block_index' => (int) $block['block_index'],
                                'id' => $documentId,
                            ]);
                    } catch (Throwable $e) {
                        error_log('Vault document ledger columns update failed: ' . $e->getMessage());
                    }
                }

                lex_audit('vault_upload_case_file_document', 'case_files', (string) $caseFileId);
                lex_flash_set('success', $status === 'approved' ? ($kindLabel . ' uploaded to the ' . (string) $folder['name'] . ' folder.') : ($kindLabel . ' uploaded and is pending your lawyer\'s approval.'));
                $redirect(['record' => $caseFileId, 'folder' => $stayFolderId > 0 ? $stayFolderId : (int) $folder['id']]);
            }

            if ($action === 'vault_folder_create') {
                $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $caseFileId]);
                $record = $stmt->fetch();
                $access = $record ? lex_case_file_vault_access([
                    'id' => (int) $record['id'],
                    'client_user_id' => (int) $record['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $record['created_by_user_id'],
                ], $user) : 'none';

                if (!$record || $access === 'none' || (function_exists('lex_case_file_is_view_only') && lex_case_file_is_view_only($access))) {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'vault_folder_create'];
                }

                $folderName = trim(lex_sanitize_text($_POST['folder_name'] ?? ''));
                if ($folderName === '' || strlen($folderName) > 80) {
                    return ['error' => 'Enter a folder name up to 80 characters.', 'failed_action' => 'vault_folder_create'];
                }
                $slug = lex_case_file_vault_slug($folderName);
                if ($slug === '') {
                    return ['error' => 'Enter a folder name that includes letters or numbers.', 'failed_action' => 'vault_folder_create'];
                }

                lex_case_file_vault_table_ensure();
                $clientFolder = function_exists('lex_case_files_ensure_client_vault_tree')
                    ? lex_case_files_ensure_client_vault_tree($pdo, $record, (int) $user['id'])
                    : null;
                $parentId = lex_sanitize_int($_POST['parent_folder_id'] ?? 0);
                $stayFolderId = lex_sanitize_int($_POST['folder'] ?? $filters['folder'] ?? 0);
                if ($parentId > 0 && function_exists('lex_case_files_get_vault_folder')) {
                    $parent = lex_case_files_get_vault_folder($pdo, $caseFileId, $parentId);
                    $parentId = $parent ? (int) $parent['id'] : ($clientFolder ? (int) $clientFolder['id'] : 0);
                } elseif ($parentId <= 0) {
                    $parentId = $clientFolder ? (int) $clientFolder['id'] : 0;
                }

                $exists = $pdo->prepare(
                    'SELECT id FROM case_file_folders
                     WHERE case_file_id = :case_file_id AND slug = :slug AND parent_id = :parent_id
                     LIMIT 1'
                );
                $exists->execute(['case_file_id' => $caseFileId, 'slug' => $slug, 'parent_id' => $parentId]);
                if ($exists->fetch()) {
                    return ['error' => 'That folder already exists.', 'failed_action' => 'vault_folder_create'];
                }

                $created = lex_case_files_ensure_vault_folder($pdo, $caseFileId, $folderName, (int) $user['id'], $parentId);
                if (function_exists('lex_vault_blockchain_record')) {
                    lex_vault_blockchain_record('vault_folder_created', [
                        'case_file_id' => $caseFileId,
                        'folder_id' => (int) ($created['id'] ?? 0),
                        'folder_name' => $folderName,
                        'parent_id' => $parentId,
                    ], (int) $user['id']);
                }
                lex_audit('create_case_file_vault_folder', 'case_files', (string) $caseFileId);
                lex_flash_set('success', 'Folder created.');
                $redirect(['record' => $caseFileId, 'folder' => $stayFolderId > 0 ? $stayFolderId : (int) ($created['parent_id'] ?? $parentId)]);
            }

            if ($action === 'vault_decision') {
                $documentId = lex_sanitize_int($_POST['document_id'] ?? 0);
                $decision = lex_safe_identifier((string) ($_POST['decision'] ?? ''), ['approved', 'rejected'], '');

                $stmt = $pdo->prepare(
                    'SELECT d.id, d.case_file_id, d.original_name, d.ledger_hash, cf.client_user_id, cf.assigned_lawyer_user_id, cf.created_by_user_id
                     FROM case_file_documents d JOIN case_files cf ON cf.id = d.case_file_id
                     WHERE d.id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $documentId]);
                $document = $stmt->fetch();
                $access = $document ? lex_case_file_vault_access([
                    'id' => (int) $document['case_file_id'],
                    'client_user_id' => (int) $document['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $document['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $document['created_by_user_id'],
                ], $user) : 'none';

                if (!$document || $decision === '' || $access !== 'manage') {
                    return ['error' => 'Unable to update that document.', 'failed_action' => 'vault_decision'];
                }

                $pdo->prepare('UPDATE case_file_documents SET upload_status = :status, reviewed_by_user_id = :reviewer, reviewed_at = NOW() WHERE id = :id')
                    ->execute(['status' => $decision, 'reviewer' => (int) $user['id'], 'id' => $documentId]);
                if (function_exists('lex_vault_blockchain_record')) {
                    lex_vault_blockchain_record('vault_file_' . $decision, [
                        'case_file_id' => (int) $document['case_file_id'],
                        'document_id' => $documentId,
                        'original_name' => (string) ($document['original_name'] ?? ''),
                        'previous_ledger_hash' => (string) ($document['ledger_hash'] ?? ''),
                    ], (int) $user['id']);
                }
                lex_audit('vault_' . $decision . '_case_file_document', 'case_file_documents', (string) $documentId);
                lex_flash_set('success', 'Document ' . $decision . '.');
                $redirect([
                    'record' => (int) $document['case_file_id'],
                    'folder' => lex_sanitize_int($_POST['folder'] ?? $filters['folder'] ?? 0),
                ]);
            }
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage(), 'failed_action' => $action];
        }

        return ['error' => '', 'failed_action' => ''];
    }
}
