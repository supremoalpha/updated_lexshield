<?php
declare(strict_types=1);

function lex_rate_limit_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        lex_pdo()->exec(
            "CREATE TABLE IF NOT EXISTS `rate_limits` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `action` VARCHAR(64) NOT NULL,
                `identifier_hash` CHAR(64) NOT NULL,
                `identifier_label` VARCHAR(190) DEFAULT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `window_started_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `blocked_until` DATETIME DEFAULT NULL,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_rate_limits_action_identifier` (`action`, `identifier_hash`),
                KEY `idx_rate_limits_expires` (`expires_at`),
                KEY `idx_rate_limits_blocked_until` (`blocked_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    });
}

function lex_rate_limit_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function lex_rate_limit_user_part(): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    return $userId > 0 ? 'user:' . $userId : 'guest';
}

function lex_rate_limit_key(string ...$parts): string
{
    $clean = array_map(static fn (string $part): string => strtolower(trim($part)), $parts);
    return implode('|', array_filter($clean, static fn (string $part): bool => $part !== ''));
}

function lex_rate_limit_message(int $retryAfter): string
{
    $retryAfter = max(1, $retryAfter);
    if ($retryAfter < 60) {
        return 'Too many attempts. Please try again in ' . $retryAfter . ' second' . ($retryAfter === 1 ? '' : 's') . '.';
    }

    $minutes = (int) ceil($retryAfter / 60);
    return 'Too many attempts. Please try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}

function lex_rate_limit_hit(string $action, string $identifier, int $maxAttempts, int $windowSeconds, int $blockSeconds = 0, ?string $label = null): array
{
    lex_rate_limit_table_ensure();

    $action = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $action) ?? 'action', 0, 64);
    $identifier = $identifier !== '' ? $identifier : lex_rate_limit_client_ip();
    $identifierHash = hash('sha256', $identifier);
    $label = $label !== null ? substr($label, 0, 190) : null;
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $expiresAt = date('Y-m-d H:i:s', $now + max(1, $windowSeconds));

    return lex_db_retry(static function () use ($action, $identifierHash, $label, $maxAttempts, $windowSeconds, $blockSeconds, $now, $nowSql, $expiresAt): array {
        $pdo = lex_pdo();
        $stmt = $pdo->prepare(
            'SELECT *
             FROM rate_limits
             WHERE action = :action AND identifier_hash = :identifier_hash
             LIMIT 1'
        );
        $stmt->execute([
            'action' => $action,
            'identifier_hash' => $identifierHash,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            $blockedUntil = !empty($row['blocked_until']) ? strtotime((string) $row['blocked_until']) : 0;
            if ($blockedUntil > $now) {
                return [
                    'allowed' => false,
                    'retry_after' => max(1, $blockedUntil - $now),
                    'attempts' => (int) $row['attempts'],
                ];
            }

            $windowExpires = strtotime((string) $row['expires_at']);
            if ($windowExpires > $now) {
                $attempts = (int) $row['attempts'] + 1;
                $blockedUntilSql = null;
                $retryAfter = max(1, $windowExpires - $now);
                if ($attempts > $maxAttempts) {
                    $blockUntil = $now + ($blockSeconds > 0 ? $blockSeconds : $retryAfter);
                    $blockedUntilSql = date('Y-m-d H:i:s', $blockUntil);
                    $retryAfter = max(1, $blockUntil - $now);
                }

                $update = $pdo->prepare(
                    'UPDATE rate_limits
                     SET attempts = :attempts,
                         identifier_label = :identifier_label,
                         blocked_until = :blocked_until
                     WHERE id = :id'
                );
                $update->execute([
                    'attempts' => $attempts,
                    'identifier_label' => $label,
                    'blocked_until' => $blockedUntilSql,
                    'id' => (int) $row['id'],
                ]);

                return [
                    'allowed' => $attempts <= $maxAttempts,
                    'retry_after' => $attempts <= $maxAttempts ? 0 : $retryAfter,
                    'attempts' => $attempts,
                ];
            }
        }

        $pdo->prepare(
            'INSERT INTO rate_limits (action, identifier_hash, identifier_label, attempts, window_started_at, expires_at, blocked_until)
             VALUES (:action, :identifier_hash, :identifier_label, 1, :window_started_at, :expires_at, NULL)
             ON DUPLICATE KEY UPDATE
                identifier_label = VALUES(identifier_label),
                attempts = 1,
                window_started_at = VALUES(window_started_at),
                expires_at = VALUES(expires_at),
                blocked_until = NULL'
        )->execute([
            'action' => $action,
            'identifier_hash' => $identifierHash,
            'identifier_label' => $label,
            'window_started_at' => $nowSql,
            'expires_at' => $expiresAt,
        ]);

        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) AND (blocked_until IS NULL OR blocked_until < NOW())');
        }

        return [
            'allowed' => true,
            'retry_after' => 0,
            'attempts' => 1,
        ];
    }, [
        'allowed' => true,
        'retry_after' => 0,
        'attempts' => 0,
    ]);
}

function lex_rate_limit_allow(string $action, string $identifier, int $maxAttempts, int $windowSeconds, int $blockSeconds = 0, ?string $label = null): bool
{
    $result = lex_rate_limit_hit($action, $identifier, $maxAttempts, $windowSeconds, $blockSeconds, $label);
    return (bool) ($result['allowed'] ?? true);
}
