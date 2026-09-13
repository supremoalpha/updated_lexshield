<?php

declare(strict_types=1);

/**
 * Always-loaded messaging primitives. Kept separate from
 * config/messages/shared.php (the page renderer) because
 * files/messages/attachment.php only requires config/bootstrap.php and
 * calls lex_messages_table_ensure() / lex_message_deletions_table_ensure()
 * / lex_messages_attachment_path() directly.
 */

if (!function_exists('lex_messages_table_ensure')) {
    function lex_messages_table_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        lex_db_retry(static function () use (&$done): void {
            $pdo = lex_pdo();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `messages` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `sender_id` INT NOT NULL,
                    `receiver_id` INT NOT NULL,
                    `case_id` INT DEFAULT NULL,
                    `body` TEXT,
                    `body_encryption_algorithm` VARCHAR(40) DEFAULT NULL,
                    `body_encryption_iv` VARCHAR(64) DEFAULT NULL,
                    `body_encryption_tag` VARCHAR(64) DEFAULT NULL,
                    `attachment_original_name` VARCHAR(255) DEFAULT NULL,
                    `attachment_stored_name` VARCHAR(255) DEFAULT NULL,
                    `attachment_mime_type` VARCHAR(120) DEFAULT NULL,
                    `attachment_size` INT DEFAULT NULL,
                    `attachment_encryption_algorithm` VARCHAR(40) DEFAULT NULL,
                    `attachment_encryption_iv` VARCHAR(64) DEFAULT NULL,
                    `attachment_encryption_tag` VARCHAR(64) DEFAULT NULL,
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            lex_messages_encryption_columns_ensure($pdo);
            $done = true;
        });
    }
}

if (!function_exists('lex_messages_encryption_columns_ensure')) {
    function lex_messages_encryption_columns_ensure(PDO $pdo): void
    {
        $columns = [
            'body_encryption_algorithm' => 'VARCHAR(40) DEFAULT NULL',
            'body_encryption_iv' => 'VARCHAR(64) DEFAULT NULL',
            'body_encryption_tag' => 'VARCHAR(64) DEFAULT NULL',
            'attachment_encryption_algorithm' => 'VARCHAR(40) DEFAULT NULL',
            'attachment_encryption_iv' => 'VARCHAR(64) DEFAULT NULL',
            'attachment_encryption_tag' => 'VARCHAR(64) DEFAULT NULL',
        ];
        foreach ($columns as $name => $definition) {
            try {
                $pdo->exec('ALTER TABLE `messages` ADD COLUMN `' . $name . '` ' . $definition);
            } catch (PDOException $e) {
                // Column already exists on an older install.
            }
        }
    }
}

if (!function_exists('lex_messages_encrypt')) {
    /**
     * AES-256-GCM encrypt $plaintext. Same construction as the case-file vault.
     *
     * @return array{ciphertext:string, iv:string, tag:string, algorithm:string}
     */
    function lex_messages_encrypt(string $plaintext): array
    {
        $algorithm = 'aes-256-gcm';
        $key = hash('sha256', lex_app_encryption_key(), true);
        $ivLength = openssl_cipher_iv_length($algorithm);
        if ($ivLength === false || $ivLength < 1) {
            throw new RuntimeException('Unable to encrypt message.');
        }
        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, $algorithm, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt message.');
        }

        return [
            'ciphertext' => $ciphertext,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'algorithm' => $algorithm,
        ];
    }
}

if (!function_exists('lex_messages_decrypt')) {
    function lex_messages_decrypt(string $ciphertext, string $algorithm, string $ivB64, string $tagB64): string
    {
        if ($algorithm === '') {
            return $ciphertext;
        }

        $key = hash('sha256', lex_app_encryption_key(), true);
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        if ($iv === false || $iv === '' || $tag === false || $tag === '') {
            throw new RuntimeException('Unable to decrypt message (missing IV or tag).');
        }

        $plaintext = openssl_decrypt($ciphertext, $algorithm, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt message (integrity check failed).');
        }

        return $plaintext;
    }
}

if (!function_exists('lex_messages_decrypt_body')) {
    /**
     * @param array<string, mixed> $row
     */
    function lex_messages_decrypt_body(?string $body, array $row): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $algorithm = (string) ($row['body_encryption_algorithm'] ?? '');
        if ($algorithm === '') {
            return $body;
        }

        $ciphertext = base64_decode($body, true);
        if ($ciphertext === false) {
            return $body;
        }

        try {
            return lex_messages_decrypt(
                $ciphertext,
                $algorithm,
                (string) ($row['body_encryption_iv'] ?? ''),
                (string) ($row['body_encryption_tag'] ?? '')
            );
        } catch (Throwable $e) {
            return '[Unable to decrypt message]';
        }
    }
}

if (!function_exists('lex_message_deletions_table_ensure')) {
    function lex_message_deletions_table_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        lex_db_retry(static function () use (&$done): void {
            lex_pdo()->exec(
                "CREATE TABLE IF NOT EXISTS `message_deletions` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `message_id` INT NOT NULL,
                    `user_id` INT NOT NULL,
                    `deleted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_message_deletions` (`message_id`, `user_id`),
                    KEY `idx_message_deletions_user` (`user_id`),
                    CONSTRAINT `fk_message_deletions_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_message_deletions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        });
    }
}

if (!function_exists('lex_messages_attachment_path')) {
    function lex_messages_attachment_path(string $storedName): string
    {
        $dir = lex_storage_ensure_dir(lex_storage_path('messages'));
        return $dir . '/' . basename($storedName);
    }
}

if (!function_exists('lex_messages_store_attachment')) {
    /**
     * Validates and stores a message attachment outside the web root.
     * Throws RuntimeException on invalid uploads.
     *
     * @param array<string, mixed> $file
     * @return array{original_name:string, stored_name:string, mime_type:string, size:int, encryption_algorithm:string, encryption_iv:string, encryption_tag:string}|null
     */
    function lex_messages_store_attachment(array $file): ?array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The attachment failed to upload.');
        }

        $maxSize = 15 * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) > $maxSize) {
            throw new RuntimeException('Attachments must be smaller than 15 MB.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid file upload.');
        }

        $allowedMimeExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
        ];

        $detectedMime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = (string) finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
        }

        if (!isset($allowedMimeExtensions[$detectedMime])) {
            throw new RuntimeException('That file type is not allowed.');
        }

        lex_virus_scan_upload($file);

        $originalName = lex_sanitize_filename(basename((string) $file['name']));
        $extension = $allowedMimeExtensions[$detectedMime];
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = lex_messages_attachment_path($storedName);

        $plaintext = (string) file_get_contents($tmpPath);
        $encrypted = lex_messages_encrypt($plaintext);
        if (file_put_contents($destination, $encrypted['ciphertext'], LOCK_EX) === false) {
            throw new RuntimeException('Unable to save the attachment.');
        }
        @chmod($destination, 0640);

        return [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $detectedMime,
            'size' => strlen($plaintext),
            'encryption_algorithm' => $encrypted['algorithm'],
            'encryption_iv' => $encrypted['iv'],
            'encryption_tag' => $encrypted['tag'],
        ];
    }
}

if (!function_exists('lex_messages_previewable_mime')) {
    function lex_messages_previewable_mime(string $mime, string $name = ''): bool
    {
        $mime = strtolower(trim($mime));
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (preg_match('/^(image\/|video\/|application\/pdf$|text\/)/', $mime) === 1) {
            return true;
        }

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'm4v', 'webm', 'mov', 'pdf', 'txt'], true);
    }
}

if (!function_exists('lex_messages_attachment_url')) {
    function lex_messages_attachment_url(int $messageId, bool $preview = false): string
    {
        $href = 'message_attachment.php?id=' . $messageId;
        if ($preview) {
            $href .= '&preview=1';
        }

        return function_exists('lex_nav_href') ? lex_nav_href($href) : lex_app_url($href);
    }
}

if (!function_exists('lex_messages_view_url')) {
    function lex_messages_view_url(int $messageId): string
    {
        $href = 'message_view.php?id=' . $messageId;

        return function_exists('lex_nav_href') ? lex_nav_href($href) : lex_app_url($href);
    }
}

if (!function_exists('lex_messages_send_attachment')) {
    function lex_messages_send_attachment(): never
    {
        $lockFile = __DIR__ . DIRECTORY_SEPARATOR . 'lock.php';
        if (is_file($lockFile)) {
            require_once $lockFile;
        }

        $user = lex_require_login();
        lex_messages_table_ensure();
        lex_message_deletions_table_ensure();

        if (function_exists('lex_messages_lock_applies') && lex_messages_lock_applies($user)
            && function_exists('lex_messages_lock_is_unlocked') && !lex_messages_lock_is_unlocked((int) $user['id'])) {
            header('Location: ' . (function_exists('lex_messages_lock_url') ? lex_messages_lock_url($user) : lex_app_url('chat.php')));
            exit;
        }

        $messageId = lex_sanitize_int($_GET['id'] ?? 0);
        if (!$messageId) {
            http_response_code(404);
            exit('Attachment not found.');
        }

        $stmt = lex_pdo()->prepare(
            'SELECT m.id, m.sender_id, m.receiver_id, m.attachment_original_name, m.attachment_stored_name, m.attachment_mime_type, m.attachment_size,
                    m.attachment_encryption_algorithm, m.attachment_encryption_iv, m.attachment_encryption_tag
             FROM messages m
             WHERE m.id = :id
               AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
             LIMIT 1'
        );
        $stmt->execute(['id' => $messageId, 'viewer_id' => (int) $user['id']]);
        $message = $stmt->fetch();

        if (!$message) {
            http_response_code(404);
            exit('Attachment not found.');
        }

        $currentUserId = (int) $user['id'];
        if ($currentUserId !== (int) $message['sender_id'] && $currentUserId !== (int) $message['receiver_id']) {
            http_response_code(403);
            exit('Access denied.');
        }

        $storedName = (string) ($message['attachment_stored_name'] ?? '');
        $originalName = (string) ($message['attachment_original_name'] ?? '');
        if ($storedName === '') {
            http_response_code(404);
            exit('Attachment not found.');
        }
        if ($originalName === '') {
            $originalName = basename($storedName);
        }

        $path = lex_messages_attachment_path($storedName);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Attachment file missing.');
        }

        $mime = (string) ($message['attachment_mime_type'] ?? 'application/octet-stream');
        $preview = (string) ($_GET['preview'] ?? '') === '1';
        $canInline = $preview && lex_messages_previewable_mime($mime, $originalName);
        $cipherData = (string) file_get_contents($path);
        $algorithm = (string) ($message['attachment_encryption_algorithm'] ?? '');
        if ($algorithm !== '') {
            try {
                $outputData = lex_messages_decrypt(
                    $cipherData,
                    $algorithm,
                    (string) ($message['attachment_encryption_iv'] ?? ''),
                    (string) ($message['attachment_encryption_tag'] ?? '')
                );
            } catch (Throwable $e) {
                lex_audit('failed_decrypt_message_attachment', 'messages', (string) $messageId);
                http_response_code(500);
                exit('Unable to decrypt attachment.');
            }
        } else {
            $outputData = $cipherData;
        }

        lex_audit($canInline ? 'preview_message_attachment' : 'download_message_attachment', 'messages', (string) $messageId);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) strlen($outputData));
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        if ($canInline) {
            header('X-Frame-Options: SAMEORIGIN', true);
            header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $originalName) . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $originalName) . '"');
        }
        echo $outputData;
        exit;
    }
}
