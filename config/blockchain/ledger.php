<?php

declare(strict_types=1);

/**
 * A tamper-evident, hash-chained audit ledger ("blockchain") used to record
 * every step of the lawyer-to-lawyer data sharing approval workflow:
 *   share_requested -> share_approved | share_rejected | share_revoked
 *
 * Each block stores a SHA-256 hash of (index + previous block's hash +
 * timestamp + event type + payload). Because every block's hash depends on
 * the one before it, changing or deleting any historical row breaks every
 * hash that comes after it - lex_blockchain_verify_chain() recomputes the
 * whole chain and reports exactly where it was tampered with, if at all.
 * This is intentionally a single, shared, append-only ledger (not a
 * separate table per feature) so it can also be extended to other
 * high-value events later without changing its shape.
 */

const LEX_BLOCKCHAIN_GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

if (!function_exists('lex_blockchain_table_ensure')) {
    function lex_blockchain_table_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        lex_db_retry(static function () use (&$done): void {
            $pdo = lex_pdo();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `blockchain_ledger` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            // A single-row lock table serializes concurrent block appends so
            // two simultaneous requests can never both read the same "last
            // block" and produce two competing chains.
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `blockchain_ledger_lock` (
                    `id` TINYINT NOT NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec("INSERT IGNORE INTO `blockchain_ledger_lock` (`id`) VALUES (1)");
            $done = true;
        });
    }
}

if (!function_exists('lex_blockchain_compute_hash')) {
    function lex_blockchain_compute_hash(int $index, string $previousHash, string $createdAt, string $eventType, string $dataJson): string
    {
        return hash('sha256', $index . '|' . $previousHash . '|' . $createdAt . '|' . $eventType . '|' . $dataJson);
    }
}

if (!function_exists('lex_blockchain_add_block')) {
    /**
     * Appends a new block to the ledger and returns the stored row.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    function lex_blockchain_add_block(string $eventType, array $data, ?int $actorUserId = null): array
    {
        lex_blockchain_table_ensure();

        return lex_db_retry(static function () use ($eventType, $data, $actorUserId): array {
            $pdo = lex_pdo();
            $pdo->beginTransaction();
            try {
                // Serialize concurrent appends.
                $pdo->query('SELECT id FROM blockchain_ledger_lock WHERE id = 1 FOR UPDATE');

                $last = $pdo->query('SELECT block_index, hash FROM blockchain_ledger ORDER BY block_index DESC LIMIT 1')->fetch();
                $nextIndex = $last ? ((int) $last['block_index'] + 1) : 0;
                $previousHash = $last ? (string) $last['hash'] : LEX_BLOCKCHAIN_GENESIS_HASH;

                $createdAt = date('Y-m-d H:i:s');
                $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($dataJson === false) {
                    throw new RuntimeException('Unable to encode ledger payload.');
                }

                $hash = lex_blockchain_compute_hash($nextIndex, $previousHash, $createdAt, $eventType, $dataJson);

                $stmt = $pdo->prepare(
                    'INSERT INTO blockchain_ledger (block_index, event_type, actor_user_id, data_json, previous_hash, hash, created_at)
                     VALUES (:block_index, :event_type, :actor_user_id, :data_json, :previous_hash, :hash, :created_at)'
                );
                $stmt->execute([
                    'block_index' => $nextIndex,
                    'event_type' => $eventType,
                    'actor_user_id' => $actorUserId,
                    'data_json' => $dataJson,
                    'previous_hash' => $previousHash,
                    'hash' => $hash,
                    'created_at' => $createdAt,
                ]);

                $pdo->commit();

                return [
                    'id' => (int) $pdo->lastInsertId(),
                    'block_index' => $nextIndex,
                    'event_type' => $eventType,
                    'actor_user_id' => $actorUserId,
                    'data_json' => $dataJson,
                    'data' => $data,
                    'previous_hash' => $previousHash,
                    'hash' => $hash,
                    'created_at' => $createdAt,
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
    }
}

if (!function_exists('lex_blockchain_verify_chain')) {
    /**
     * Recomputes every block's hash and checks the previous_hash linkage,
     * detecting any tampering (edited payload, re-ordered rows, deleted
     * blocks, etc.).
     *
     * @return array{valid: bool, blocks_checked: int, broken_at: array<int, array{index:int, reason:string}>}
     */
    function lex_blockchain_verify_chain(): array
    {
        lex_blockchain_table_ensure();

        $pdo = lex_pdo();
        $rows = $pdo->query('SELECT * FROM blockchain_ledger ORDER BY block_index ASC')->fetchAll();

        $expectedPreviousHash = LEX_BLOCKCHAIN_GENESIS_HASH;
        $expectedIndex = 0;
        $brokenAt = [];

        foreach ($rows as $row) {
            $index = (int) $row['block_index'];

            if ($index !== $expectedIndex) {
                $brokenAt[] = ['index' => $index, 'reason' => 'Non-sequential block index (expected ' . $expectedIndex . ').'];
            }

            if ((string) $row['previous_hash'] !== $expectedPreviousHash) {
                $brokenAt[] = ['index' => $index, 'reason' => 'previous_hash does not match the prior block\'s hash.'];
            }

            $recomputed = lex_blockchain_compute_hash(
                $index,
                (string) $row['previous_hash'],
                (string) $row['created_at'],
                (string) $row['event_type'],
                (string) $row['data_json']
            );

            if (!hash_equals($recomputed, (string) $row['hash'])) {
                $brokenAt[] = ['index' => $index, 'reason' => 'Stored hash does not match the recomputed hash - data was altered.'];
            }

            $expectedPreviousHash = (string) $row['hash'];
            $expectedIndex = $index + 1;
        }

        return [
            'valid' => empty($brokenAt),
            'blocks_checked' => count($rows),
            'broken_at' => $brokenAt,
        ];
    }
}

if (!function_exists('lex_blockchain_recent_blocks')) {
    function lex_blockchain_recent_blocks(int $limit = 50, int $offset = 0): array
    {
        lex_blockchain_table_ensure();
        $stmt = lex_pdo()->prepare(
            'SELECT bl.*, u.full_name AS actor_name
             FROM blockchain_ledger bl
             LEFT JOIN users u ON u.id = bl.actor_user_id
             ORDER BY bl.block_index DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}

if (!function_exists('lex_blockchain_event_label')) {
    function lex_blockchain_event_label(string $eventType): string
    {
        return match ($eventType) {
            'share_requested' => 'Share requested',
            'share_approved' => 'Share approved',
            'share_rejected' => 'Share rejected',
            'share_revoked' => 'Share access revoked',
            'vault_file_uploaded' => 'Vault file uploaded',
            'vault_folder_created' => 'Vault folder created',
            'vault_file_approved' => 'Vault file approved',
            'vault_file_rejected' => 'Vault file rejected',
            default => ucwords(str_replace('_', ' ', $eventType)),
        };
    }
}

if (!function_exists('lex_vault_blockchain_record')) {
    /**
     * Seals a vault event onto the shared hash chain.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    function lex_vault_blockchain_record(string $eventType, array $data, ?int $actorUserId = null): ?array
    {
        if (!function_exists('lex_blockchain_add_block')) {
            return null;
        }

        $data['scope'] = 'vault';
        try {
            return lex_blockchain_add_block($eventType, $data, $actorUserId);
        } catch (Throwable $e) {
            error_log('Vault blockchain record failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('lex_blockchain_blocks_for_case')) {
    /**
     * @return list<array<string, mixed>>
     */
    function lex_blockchain_blocks_for_case(int $caseFileId, int $limit = 20): array
    {
        if ($caseFileId <= 0) {
            return [];
        }

        lex_blockchain_table_ensure();
        $stmt = lex_pdo()->prepare(
            'SELECT bl.*, u.full_name AS actor_name
             FROM blockchain_ledger bl
             LEFT JOIN users u ON u.id = bl.actor_user_id
             WHERE bl.event_type LIKE :prefix
               AND (bl.data_json LIKE :like_mid OR bl.data_json LIKE :like_end)
             ORDER BY bl.block_index DESC
             LIMIT :limit'
        );
        $stmt->bindValue('prefix', 'vault_%');
        $stmt->bindValue('like_mid', '%"case_file_id":' . $caseFileId . ',%');
        $stmt->bindValue('like_end', '%"case_file_id":' . $caseFileId . '}%');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}

/* ---------------------------------------------------------------------
 * Lawyer-to-lawyer data sharing workflow (requires admin approval).
 * ------------------------------------------------------------------- */

if (!function_exists('lex_data_sharing_table_ensure')) {
    function lex_data_sharing_table_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        lex_db_retry(static function () use (&$done): void {
            $pdo = lex_pdo();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `data_sharing_requests` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `case_file_shares` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        });
    }
}

if (!function_exists('lex_data_sharing_create_request')) {
    function lex_data_sharing_create_request(int $caseFileId, int $fromLawyerUserId, int $toLawyerUserId, string $note): array
    {
        lex_data_sharing_table_ensure();
        $pdo = lex_pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO data_sharing_requests (case_file_id, from_lawyer_user_id, to_lawyer_user_id, note, status)
             VALUES (:case_file_id, :from_lawyer_user_id, :to_lawyer_user_id, :note, "pending")'
        );
        $stmt->execute([
            'case_file_id' => $caseFileId,
            'from_lawyer_user_id' => $fromLawyerUserId,
            'to_lawyer_user_id' => $toLawyerUserId,
            'note' => $note !== '' ? $note : null,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        $block = lex_blockchain_add_block('share_requested', [
            'request_id' => $requestId,
            'case_file_id' => $caseFileId,
            'from_lawyer_user_id' => $fromLawyerUserId,
            'to_lawyer_user_id' => $toLawyerUserId,
            'note' => $note,
        ], $fromLawyerUserId);

        $pdo->prepare('UPDATE data_sharing_requests SET request_block_id = :block_id WHERE id = :id')
            ->execute(['block_id' => $block['id'], 'id' => $requestId]);

        return ['request_id' => $requestId, 'block' => $block];
    }
}

if (!function_exists('lex_data_sharing_decide')) {
    /**
     * $decision must be 'approved' or 'rejected'.
     */
    function lex_data_sharing_decide(int $requestId, string $decision, int $decidedByUserId, string $decisionNote = ''): bool
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return false;
        }

        lex_data_sharing_table_ensure();
        $pdo = lex_pdo();

        $stmt = $pdo->prepare('SELECT * FROM data_sharing_requests WHERE id = :id AND status = "pending" LIMIT 1');
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE data_sharing_requests
                 SET status = :status, decided_by_user_id = :decided_by, decision_note = :decision_note, decided_at = NOW()
                 WHERE id = :id'
            )->execute([
                'status' => $decision,
                'decided_by' => $decidedByUserId,
                'decision_note' => $decisionNote !== '' ? $decisionNote : null,
                'id' => $requestId,
            ]);

            if ($decision === 'approved') {
                $pdo->prepare(
                    'INSERT INTO case_file_shares (case_file_id, lawyer_user_id, request_id, granted_by_user_id, granted_at)
                     VALUES (:case_file_id, :lawyer_user_id, :request_id, :granted_by, NOW())
                     ON DUPLICATE KEY UPDATE request_id = VALUES(request_id), granted_by_user_id = VALUES(granted_by_user_id), granted_at = VALUES(granted_at), revoked_at = NULL'
                )->execute([
                    'case_file_id' => (int) $request['case_file_id'],
                    'lawyer_user_id' => (int) $request['to_lawyer_user_id'],
                    'request_id' => $requestId,
                    'granted_by' => $decidedByUserId,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $block = lex_blockchain_add_block('share_' . $decision, [
            'request_id' => $requestId,
            'case_file_id' => (int) $request['case_file_id'],
            'from_lawyer_user_id' => (int) $request['from_lawyer_user_id'],
            'to_lawyer_user_id' => (int) $request['to_lawyer_user_id'],
            'decided_by_user_id' => $decidedByUserId,
            'decision_note' => $decisionNote,
        ], $decidedByUserId);

        $pdo->prepare('UPDATE data_sharing_requests SET decision_block_id = :block_id WHERE id = :id')
            ->execute(['block_id' => $block['id'], 'id' => $requestId]);

        return true;
    }
}

if (!function_exists('lex_data_sharing_has_access')) {
    function lex_data_sharing_has_access(int $caseFileId, int $lawyerUserId): bool
    {
        lex_data_sharing_table_ensure();
        $stmt = lex_pdo()->prepare(
            'SELECT 1 FROM case_file_shares WHERE case_file_id = :case_file_id AND lawyer_user_id = :lawyer_user_id AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute(['case_file_id' => $caseFileId, 'lawyer_user_id' => $lawyerUserId]);
        return (bool) $stmt->fetchColumn();
    }
}
