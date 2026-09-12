<?php

declare(strict_types=1);

/**
 * User-to-user video calls started from Messages (client, lawyer, or admin).
 */

if (!function_exists('lex_inbox_call_tables_ensure')) {
    function lex_inbox_call_tables_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $pdo = lex_pdo();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `message_call_sessions` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_low_id` INT NOT NULL,
                `user_high_id` INT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'waiting',
                `low_last_seen_at` DATETIME DEFAULT NULL,
                `high_last_seen_at` DATETIME DEFAULT NULL,
                `started_at` DATETIME DEFAULT NULL,
                `ended_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_message_call_pair` (`user_low_id`, `user_high_id`),
                KEY `idx_message_call_high` (`user_high_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `message_call_signals` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `session_id` INT NOT NULL,
                `sender_user_id` INT NOT NULL,
                `signal_type` VARCHAR(32) NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_message_call_signals_session` (`session_id`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $pdo->exec('ALTER TABLE `message_call_sessions` ADD COLUMN `caller_user_id` INT NULL');
        } catch (Throwable $e) {
            // Column already exists on later loads.
        }
        $done = true;
    }
}

if (!function_exists('lex_inbox_call_pair')) {
    /**
     * @return array{0:int,1:int}
     */
    function lex_inbox_call_pair(int $userA, int $userB): array
    {
        return $userA <= $userB ? [$userA, $userB] : [$userB, $userA];
    }
}

if (!function_exists('lex_inbox_call_get_or_create')) {
    function lex_inbox_call_get_or_create(int $userA, int $userB): array
    {
        lex_inbox_call_tables_ensure();
        [$low, $high] = lex_inbox_call_pair($userA, $userB);
        $pdo = lex_pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM message_call_sessions WHERE user_low_id = :low AND user_high_id = :high LIMIT 1'
        );
        $stmt->execute(['low' => $low, 'high' => $high]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((string) ($row['status'] ?? '') === 'ended' && !lex_inbox_call_anyone_present($row)) {
                $sessionId = (int) $row['id'];
                $pdo->prepare(
                    "UPDATE message_call_sessions
                     SET status = 'waiting', ended_at = NULL, started_at = NULL,
                         low_last_seen_at = NULL, high_last_seen_at = NULL
                     WHERE id = :id"
                )->execute(['id' => $sessionId]);
                lex_inbox_call_clear_signals($sessionId);
                $stmt->execute(['low' => $low, 'high' => $high]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
            }
            return $row;
        }

        try {
            $pdo->prepare(
                'INSERT INTO message_call_sessions (user_low_id, user_high_id, status)
                 VALUES (:low, :high, "waiting")'
            )->execute(['low' => $low, 'high' => $high]);
        } catch (PDOException $e) {
            // Unique pair already created by the other participant.
        }
        $stmt->execute(['low' => $low, 'high' => $high]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lex_inbox_call_seen_recently')) {
    function lex_inbox_call_seen_recently($lastSeen, int $seconds = 12): bool
    {
        if (!$lastSeen) {
            return false;
        }
        try {
            $seenAt = new DateTimeImmutable((string) $lastSeen);
        } catch (Throwable $e) {
            return false;
        }
        $window = max(3, $seconds);
        return $seenAt >= (new DateTimeImmutable())->modify('-' . $window . ' seconds');
    }
}

if (!function_exists('lex_inbox_call_anyone_present')) {
    function lex_inbox_call_anyone_present(array $session): bool
    {
        return lex_inbox_call_seen_recently($session['low_last_seen_at'] ?? null)
            || lex_inbox_call_seen_recently($session['high_last_seen_at'] ?? null);
    }
}

if (!function_exists('lex_inbox_call_clear_signals')) {
    function lex_inbox_call_clear_signals(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }
        lex_pdo()->prepare('DELETE FROM message_call_signals WHERE session_id = :id')->execute(['id' => $sessionId]);
    }
}

if (!function_exists('lex_inbox_call_latest_signal_id')) {
    function lex_inbox_call_latest_signal_id(int $sessionId): int
    {
        if ($sessionId <= 0) {
            return 0;
        }
        $stmt = lex_pdo()->prepare('SELECT MAX(id) FROM message_call_signals WHERE session_id = :id');
        $stmt->execute(['id' => $sessionId]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('lex_inbox_call_self_present')) {
    function lex_inbox_call_self_present(array $session, int $userId): bool
    {
        $low = (int) ($session['user_low_id'] ?? 0);
        $column = $userId === $low ? 'low_last_seen_at' : 'high_last_seen_at';
        return lex_inbox_call_seen_recently($session[$column] ?? null);
    }
}

if (!function_exists('lex_inbox_call_find')) {
    function lex_inbox_call_find(int $userA, int $userB): ?array
    {
        lex_inbox_call_tables_ensure();
        [$low, $high] = lex_inbox_call_pair($userA, $userB);
        $stmt = lex_pdo()->prepare(
            'SELECT * FROM message_call_sessions WHERE user_low_id = :low AND user_high_id = :high LIMIT 1'
        );
        $stmt->execute(['low' => $low, 'high' => $high]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('lex_inbox_call_end')) {
    function lex_inbox_call_end(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }
        lex_pdo()->prepare(
            "UPDATE message_call_sessions
             SET status = 'ended', ended_at = NOW(),
                 low_last_seen_at = NULL, high_last_seen_at = NULL
             WHERE id = :id"
        )->execute(['id' => $sessionId]);
    }
}

if (!function_exists('lex_inbox_call_touch')) {
    function lex_inbox_call_touch(array $session, int $userId): array
    {
        $status = (string) ($session['status'] ?? 'waiting');
        if ($status === 'ended') {
            return $session;
        }

        $low = (int) ($session['user_low_id'] ?? 0);
        $column = $userId === $low ? 'low_last_seen_at' : 'high_last_seen_at';
        $otherPresent = lex_inbox_call_other_present($session, $userId);
        $nextStatus = $status;
        if ($otherPresent || $status === 'active') {
            $nextStatus = 'active';
        } elseif ($status === 'ringing') {
            $nextStatus = 'ringing';
        }

        lex_pdo()->prepare(
            "UPDATE message_call_sessions
                SET {$column} = NOW(),
                    status = :status,
                    started_at = COALESCE(started_at, NOW()),
                    ended_at = NULL
              WHERE id = :id"
        )->execute([
            'status' => $nextStatus,
            'id' => (int) $session['id'],
        ]);

        $stmt = lex_pdo()->prepare('SELECT * FROM message_call_sessions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $session['id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: $session;
    }
}

if (!function_exists('lex_inbox_call_peer_live')) {
    function lex_inbox_call_peer_live(int $sessionId, int $userId, int $seconds = 20): bool
    {
        if ($sessionId <= 0 || $userId <= 0) {
            return false;
        }
        $seconds = max(5, min(180, $seconds));
        $stmt = lex_pdo()->prepare(
            "SELECT 1
             FROM message_call_sessions
             WHERE id = :id
               AND (
                    (user_low_id = :low AND high_last_seen_at >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND))
                 OR (user_high_id = :high AND low_last_seen_at >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND))
               )
             LIMIT 1"
        );
        $stmt->execute(['id' => $sessionId, 'low' => $userId, 'high' => $userId]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('lex_inbox_call_start_or_join')) {
    /**
     * @return array{session: array, started: bool}
     */
    function lex_inbox_call_start_or_join(int $userId, int $peerId): array
    {
        $session = lex_inbox_call_get_or_create($userId, $peerId);
        $sessionId = (int) ($session['id'] ?? 0);
        $status = (string) ($session['status'] ?? 'waiting');
        $otherLive = $sessionId > 0 && lex_inbox_call_peer_live($sessionId, $userId, 20);

        if ($otherLive && in_array($status, ['ringing', 'active'], true)) {
            return [
                'session' => lex_inbox_call_touch($session, $userId),
                'started' => false,
            ];
        }

        $low = (int) ($session['user_low_id'] ?? 0);
        $callerColumn = $userId === $low ? 'low_last_seen_at' : 'high_last_seen_at';
        $otherColumn = $userId === $low ? 'high_last_seen_at' : 'low_last_seen_at';
        try {
            lex_pdo()->prepare(
                "UPDATE message_call_sessions
                 SET status = 'ringing',
                     caller_user_id = :caller,
                     {$callerColumn} = NOW(),
                     {$otherColumn} = NULL,
                     started_at = NOW(),
                     ended_at = NULL
                 WHERE id = :id"
            )->execute([
                'caller' => $userId,
                'id' => $sessionId,
            ]);
        } catch (Throwable $e) {
            lex_pdo()->prepare(
                "UPDATE message_call_sessions
                 SET status = 'ringing',
                     {$callerColumn} = NOW(),
                     {$otherColumn} = NULL,
                     started_at = NOW(),
                     ended_at = NULL
                 WHERE id = :id"
            )->execute(['id' => $sessionId]);
        }
        if ($status === 'ended' || $status === 'waiting' || $status === '') {
            lex_inbox_call_clear_signals($sessionId);
        }

        $stmt = lex_pdo()->prepare('SELECT * FROM message_call_sessions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $sessionId]);
        return [
            'session' => $stmt->fetch(PDO::FETCH_ASSOC) ?: $session,
            'started' => true,
        ];
    }
}

if (!function_exists('lex_inbox_call_incoming_for')) {
    function lex_inbox_call_incoming_for(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        lex_inbox_call_tables_ensure();
        $stmt = lex_pdo()->prepare(
            'SELECT s.*,
                    CASE WHEN s.user_low_id = :peer_me THEN s.user_high_id ELSE s.user_low_id END AS peer_id
             FROM message_call_sessions s
             WHERE (s.user_low_id = :low_me OR s.user_high_id = :high_me)
               AND s.status = "ringing"
               AND (
                    (s.user_low_id = :low_live AND s.high_last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
                        AND (s.low_last_seen_at IS NULL OR s.low_last_seen_at < DATE_SUB(NOW(), INTERVAL 8 SECOND)))
                 OR (s.user_high_id = :high_live AND s.low_last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
                        AND (s.high_last_seen_at IS NULL OR s.high_last_seen_at < DATE_SUB(NOW(), INTERVAL 8 SECOND)))
               )
             ORDER BY s.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'peer_me' => $userId,
            'low_me' => $userId,
            'high_me' => $userId,
            'low_live' => $userId,
            'high_live' => $userId,
        ]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return null;
        }
        $peerId = (int) ($session['peer_id'] ?? 0);
        if ($peerId <= 0) {
            return null;
        }
        $peerStmt = lex_pdo()->prepare(
            'SELECT id, full_name, role, avatar_stored_name FROM users WHERE id = :id LIMIT 1'
        );
        $peerStmt->execute(['id' => $peerId]);
        $peer = $peerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$peer) {
            return null;
        }
        $name = (string) ($peer['full_name'] ?? 'Someone');
        return [
            'peerUserId' => $peerId,
            'peerName' => $name,
            'peerRole' => (string) ($peer['role'] ?? ''),
            'peerInitial' => lex_inbox_call_initial($name),
            'peerAvatar' => function_exists('lex_profile_avatar_url')
                ? lex_profile_avatar_url((string) ($peer['avatar_stored_name'] ?? ''))
                : '',
            'callHref' => lex_app_url('chat_call.php?with=' . $peerId),
            'sessionId' => (int) ($session['id'] ?? 0),
        ];
    }
}

if (!function_exists('lex_inbox_call_other_present')) {
    function lex_inbox_call_other_present(array $session, int $userId): bool
    {
        $low = (int) ($session['user_low_id'] ?? 0);
        $column = $userId === $low ? 'high_last_seen_at' : 'low_last_seen_at';
        return lex_inbox_call_seen_recently($session[$column] ?? null);
    }
}

if (!function_exists('lex_inbox_call_initial')) {
    function lex_inbox_call_initial(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($name, 0, 1));
        }
        return strtoupper(substr($name, 0, 1));
    }
}

if (!function_exists('lex_inbox_call_href')) {
    function lex_inbox_call_href(int $peerUserId): string
    {
        $path = 'chat_call.php?with=' . $peerUserId;
        return function_exists('lex_nav_href') ? lex_nav_href($path) : lex_app_url($path);
    }
}

if (!function_exists('lex_inbox_call_overlay_markup')) {
    /**
     * Messenger-style incoming call overlay. $incoming may be null (hidden until JS finds a ring).
     *
     * @param array<string,mixed>|null $incoming
     */
    function lex_inbox_call_overlay_markup(?array $incoming): void
    {
        $open = is_array($incoming) && (int) ($incoming['peerUserId'] ?? 0) > 0;
        $name = $open ? (string) ($incoming['peerName'] ?? 'Someone') : 'Someone';
        $role = $open ? ucfirst((string) ($incoming['peerRole'] ?? '')) : '';
        $initial = $open ? (string) ($incoming['peerInitial'] ?? '?') : '?';
        $href = $open ? (string) ($incoming['callHref'] ?? '#') : '#';
        ?>
<style>
.inbox-call-ring{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;padding:1.25rem;background:rgba(6,12,22,.72)}
.inbox-call-ring[hidden]{display:none!important}
.inbox-call-ring-card{position:relative;width:min(100%,22rem);padding:2.1rem 1.4rem 1.4rem;border-radius:28px;background:#111b2b;color:#f5f7fb;text-align:center;box-shadow:0 28px 70px rgba(0,0,0,.4)}
.inbox-call-ring-avatar{width:84px;height:84px;margin:0 auto .85rem;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#0084ff;color:#fff;font-size:1.8rem;font-weight:800}
.inbox-call-ring-kicker{margin:0 0 .2rem;color:#9eb0c8;font-size:.82rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.inbox-call-ring-name{margin:0;font-size:1.35rem}
.inbox-call-ring-role{margin:.2rem 0 1.2rem;color:#8ea0b8;font-size:.92rem}
.inbox-call-ring-actions{display:flex;gap:.75rem}
.inbox-call-ring-decline,.inbox-call-ring-accept{flex:1 1 0;min-height:48px;border:0;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;text-decoration:none;cursor:pointer;color:#fff}
.inbox-call-ring-decline{background:#e74c4c}
.inbox-call-ring-accept{background:#31a24c}
.inbox-call-toast{position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:2147483001;display:flex;align-items:center;gap:.75rem;min-width:min(92vw,26rem);padding:.7rem .85rem;border-radius:14px;background:#0084ff;color:#fff;box-shadow:0 16px 40px rgba(0,0,0,.28);font-weight:700}
.inbox-call-toast[hidden]{display:none!important}
.inbox-call-toast a{color:#fff;text-decoration:underline}
</style>
<div class="inbox-call-toast" id="lexCallRingToast" <?= $open ? '' : 'hidden' ?> role="status">
  <span data-call-ring-toast-text><?= $open ? lex_e($name) . ' is calling you' : 'Incoming video call' ?></span>
  <a data-call-ring-toast-accept href="<?= lex_e($href) ?>">Answer</a>
</div>
<div class="inbox-call-ring" id="lexCallRingOverlay" <?= $open ? '' : 'hidden' ?> role="dialog" aria-modal="true" aria-hidden="<?= $open ? 'false' : 'true' ?>">
  <div class="inbox-call-ring-card">
    <div class="inbox-call-ring-avatar" data-call-ring-avatar><?= lex_e($initial) ?></div>
    <p class="inbox-call-ring-kicker">Incoming video call</p>
    <h2 class="inbox-call-ring-name" data-call-ring-name><?= lex_e($name) ?></h2>
    <p class="inbox-call-ring-role" data-call-ring-role><?= lex_e($role) ?></p>
    <div class="inbox-call-ring-actions">
      <button type="button" class="inbox-call-ring-decline" data-call-ring-decline>Decline</button>
      <a class="inbox-call-ring-accept" data-call-ring-accept href="<?= lex_e($href) ?>">Accept</a>
    </div>
  </div>
</div>
        <?php
    }
}
