<?php

declare(strict_types=1);

/**
 * Video Call feature helpers.
 *
 * These helpers assume the following are already defined by config/bootstrap.php
 * (loaded by every entry file before this one): lex_pdo(), lex_require_role(),
 * lex_user_client_id(), lex_user_lawyer_id(), lex_sanitize_int(), lex_e(),
 * lex_app_url(), lex_csrf_token(), lex_csrf_validate(), lex_audit(),
 * lex_page_header(), lex_page_footer(), and (from security/rate_limiter.php)
 * lex_rate_limit_allow(), lex_rate_limit_message().
 *
 * If any function name differs in your actual bootstrap, update the calls
 * below to match - the logic itself does not otherwise depend on bootstrap
 * internals.
 */

function lex_video_call_tables_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `video_call_sessions` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `appointment_id` INT NOT NULL,
            `case_id` INT NOT NULL,
            `client_id` INT NOT NULL,
            `lawyer_id` INT NOT NULL,
            `status` ENUM('waiting','active','ended') NOT NULL DEFAULT 'waiting',
            `client_last_seen_at` DATETIME DEFAULT NULL,
            `lawyer_last_seen_at` DATETIME DEFAULT NULL,
            `started_at` DATETIME DEFAULT NULL,
            `ended_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_video_call_sessions_appointment` (`appointment_id`),
            KEY `idx_video_call_sessions_case` (`case_id`),
            KEY `idx_video_call_sessions_client` (`client_id`),
            KEY `idx_video_call_sessions_lawyer` (`lawyer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `video_call_signals` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `session_id` INT NOT NULL,
            `sender_role` ENUM('client','lawyer') NOT NULL,
            `signal_type` ENUM('join','leave','offer','answer','candidate','media-state') NOT NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_video_call_signals_session` (`session_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

/**
 * Loads the appointment for the given id and verifies the current user is
 * one of its two participants. Returns null if the appointment does not
 * exist, does not belong to the user, or is not confirmed.
 */
function lex_video_call_load_appointment(PDO $pdo, int $appointmentId, array $user): ?array
{
    if ($appointmentId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.case_id, a.client_id, a.lawyer_id, a.scheduled_at, a.status,
                COALESCE(NULLIF(a.appointment_type, ""), NULLIF(c.title, ""), "Video Consultation") AS appointment_title,
                cu.full_name AS client_name, lu.full_name AS lawyer_name
         FROM appointments a
         JOIN cases c ON c.id = a.case_id
         JOIN clients cl ON cl.id = a.client_id
         JOIN users cu ON cu.id = cl.user_id
         JOIN lawyers l ON l.id = a.lawyer_id
         JOIN users lu ON lu.id = l.user_id
         WHERE a.id = :id
           AND a.status <> "deleted"
         LIMIT 1'
    );
    $stmt->execute(['id' => $appointmentId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        return null;
    }

    $role = (string) ($user['role'] ?? '');
    if ($role === 'client') {
        $clientId = lex_user_client_id((int) $user['id']);
        if ($clientId <= 0 || (int) $appointment['client_id'] !== $clientId) {
            return null;
        }
    } elseif ($role === 'lawyer') {
        $lawyerId = lex_user_lawyer_id((int) $user['id']);
        if ($lawyerId <= 0 || (int) $appointment['lawyer_id'] !== $lawyerId) {
            return null;
        }
    } else {
        return null;
    }

    return $appointment;
}

function lex_video_call_is_joinable(array $appointment): bool
{
    return (string) ($appointment['status'] ?? '') === 'confirmed';
}

function lex_video_call_get_or_create_session(PDO $pdo, array $appointment): array
{
    $stmt = $pdo->prepare('SELECT * FROM video_call_sessions WHERE appointment_id = :appointment_id LIMIT 1');
    $stmt->execute(['appointment_id' => (int) $appointment['id']]);
    $session = $stmt->fetch();

    if ($session && (string) ($session['status'] ?? '') === 'ended' && !lex_video_call_anyone_present($session)) {
        $sessionId = (int) $session['id'];
        $pdo->prepare(
            "UPDATE video_call_sessions
             SET status = 'waiting', ended_at = NULL, started_at = NULL,
                 client_last_seen_at = NULL, lawyer_last_seen_at = NULL
             WHERE id = :id"
        )->execute(['id' => $sessionId]);
        $pdo->prepare('DELETE FROM video_call_signals WHERE session_id = :id')->execute(['id' => $sessionId]);
        $stmt->execute(['appointment_id' => (int) $appointment['id']]);
        $session = $stmt->fetch() ?: $session;
    }

    if ($session) {
        return $session;
    }

    $pdo->prepare(
        'INSERT INTO video_call_sessions (appointment_id, case_id, client_id, lawyer_id, status)
         VALUES (:appointment_id, :case_id, :client_id, :lawyer_id, "waiting")'
    )->execute([
        'appointment_id' => (int) $appointment['id'],
        'case_id' => (int) $appointment['case_id'],
        'client_id' => (int) $appointment['client_id'],
        'lawyer_id' => (int) $appointment['lawyer_id'],
    ]);

    $stmt->execute(['appointment_id' => (int) $appointment['id']]);
    return $stmt->fetch();
}

/**
 * Records that $role is present right now, flips the session into "active"
 * on first contact from either side, and returns the refreshed row.
 */
function lex_video_call_touch_presence(PDO $pdo, int $sessionId, string $role): array
{
    $column = $role === 'lawyer' ? 'lawyer_last_seen_at' : 'client_last_seen_at';

    $pdo->prepare(
        "UPDATE video_call_sessions
            SET {$column} = NOW(),
                status = 'active',
                started_at = COALESCE(started_at, NOW()),
                ended_at = NULL
          WHERE id = :id"
    )->execute(['id' => $sessionId]);

    $stmt = $pdo->prepare('SELECT * FROM video_call_sessions WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    return $stmt->fetch();
}

function lex_video_call_mark_left(PDO $pdo, int $sessionId): void
{
    $pdo->prepare(
        "UPDATE video_call_sessions SET status = 'ended', ended_at = NOW() WHERE id = :id"
    )->execute(['id' => $sessionId]);
}

function lex_video_call_seen_recently($lastSeen): bool
{
    if (!$lastSeen) {
        return false;
    }
    try {
        $seenAt = new DateTimeImmutable((string) $lastSeen);
    } catch (Throwable $e) {
        return false;
    }
    return $seenAt >= (new DateTimeImmutable())->modify('-12 seconds');
}

function lex_video_call_anyone_present(array $session): bool
{
    return lex_video_call_seen_recently($session['client_last_seen_at'] ?? null)
        || lex_video_call_seen_recently($session['lawyer_last_seen_at'] ?? null);
}

function lex_video_call_latest_signal_id(PDO $pdo, int $sessionId): int
{
    if ($sessionId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT MAX(id) FROM video_call_signals WHERE session_id = :id');
    $stmt->execute(['id' => $sessionId]);
    return (int) $stmt->fetchColumn();
}

/**
 * A participant counts as "present" if they have been seen in the last
 * 12 seconds (the client polls roughly every second).
 */
function lex_video_call_other_present(array $session, string $role): bool
{
    $column = $role === 'lawyer' ? 'client_last_seen_at' : 'lawyer_last_seen_at';
    return lex_video_call_seen_recently($session[$column] ?? null);
}

function lex_video_call_record_signal(PDO $pdo, int $sessionId, string $role, string $type, string $payload): int
{
    $pdo->prepare(
        'INSERT INTO video_call_signals (session_id, sender_role, signal_type, payload)
         VALUES (:session_id, :sender_role, :signal_type, :payload)'
    )->execute([
        'session_id' => $sessionId,
        'sender_role' => $role,
        'signal_type' => $type,
        'payload' => $payload,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Fetches signals sent by the *other* role with id greater than $sinceId.
 */
function lex_video_call_fetch_signals(PDO $pdo, int $sessionId, string $role, int $sinceId): array
{
    $otherRole = $role === 'lawyer' ? 'client' : 'lawyer';

    $stmt = $pdo->prepare(
        'SELECT id, sender_role, signal_type, payload
         FROM video_call_signals
         WHERE session_id = :session_id
           AND sender_role = :other_role
           AND id > :since_id
         ORDER BY id ASC
         LIMIT 200'
    );
    $stmt->execute([
        'session_id' => $sessionId,
        'other_role' => $otherRole,
        'since_id' => $sinceId,
    ]);

    return $stmt->fetchAll() ?: [];
}

/**
 * Renders the shared call-room markup for either role. $viewerRole must be
 * "client" or "lawyer"; the other participant's name is shown in the UI.
 */
function lex_video_call_render_room(array $appointment, array $session, string $viewerRole, string $viewerName): void
{
    $counterpartName = $viewerRole === 'client'
        ? (string) ($appointment['lawyer_name'] ?? 'Lawyer')
        : (string) ($appointment['client_name'] ?? 'Client');
    $viewerLabel = trim($viewerName) !== '' ? $viewerName . ' (You)' : 'You';

    $scheduledAt = '';
    try {
        $scheduledAt = (new DateTimeImmutable((string) $appointment['scheduled_at']))->format('M j, Y \a\t g:i A');
    } catch (Throwable $e) {
        $scheduledAt = (string) $appointment['scheduled_at'];
    }

    $pageData = [
        'appointmentId' => (int) $appointment['id'],
        'sessionId' => (int) $session['id'],
        'role' => $viewerRole,
        'csrfToken' => lex_csrf_token(),
        'signalEndpoint' => lex_app_url('video_call_signal.php'),
        'backUrl' => lex_app_url($viewerRole . '/appointment.php'),
        'counterpartName' => $counterpartName,
        'viewerLabel' => $viewerLabel,
        'appointmentTitle' => (string) ($appointment['appointment_title'] ?? 'Video Consultation'),
        'sinceId' => lex_video_call_latest_signal_id(lex_pdo(), (int) ($session['id'] ?? 0)),
        'isInitiator' => $viewerRole === 'lawyer',
    ];
    ?>
    <section class="video-call-page" data-video-call-page>
      <div class="card video-call-card">
        <div class="video-call-head">
          <div>
            <h2>Video Consultation</h2>
            <p class="muted"><?= lex_e($pageData['appointmentTitle']) ?> &middot; Scheduled for <?= lex_e($scheduledAt) ?></p>
          </div>
          <span class="pill" data-video-call-status-pill>Connecting&hellip;</span>
        </div>

        <script type="application/json" id="video-call-data"><?= json_encode($pageData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

        <div class="video-call-stage" data-video-call-stage>
          <div class="video-call-tile video-call-tile--remote" data-video-call-remote-tile>
            <video data-video-call-remote-video autoplay playsinline></video>
            <div class="video-call-tile-empty" data-video-call-remote-empty>
              <span class="video-call-avatar"><?= lex_e(mb_strtoupper(mb_substr($counterpartName, 0, 1))) ?></span>
              <p data-video-call-remote-empty-text>Waiting for <?= lex_e($counterpartName) ?> to join&hellip;</p>
            </div>
            <span class="video-call-name-badge"><?= lex_e($counterpartName) ?></span>
          </div>

          <div class="video-call-tile video-call-tile--local" data-video-call-local-tile>
            <video data-video-call-local-video autoplay playsinline muted></video>
            <div class="video-call-tile-empty" data-video-call-local-empty>
              <span class="video-call-avatar"><?= lex_e(mb_strtoupper(mb_substr(trim($viewerName) !== '' ? $viewerName : 'You', 0, 1))) ?></span>
            </div>
            <span class="video-call-name-badge"><?= lex_e($viewerLabel) ?></span>
          </div>
        </div>

        <p class="video-call-error" data-video-call-error hidden></p>

        <div class="video-call-controls" data-video-call-controls>
          <button type="button" class="video-call-control-btn" data-video-call-toggle-mic aria-pressed="true" title="Mute microphone">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 15.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 0 0-7 0v6a3.5 3.5 0 0 0 3.5 3.5Zm5.5-3.5a5.5 5.5 0 0 1-11 0H4.75a7.25 7.25 0 0 0 6.25 7.17V22h2v-2.83A7.25 7.25 0 0 0 19.25 12H17.5Z" fill="currentColor"/></svg>
          </button>
          <button type="button" class="video-call-control-btn" data-video-call-toggle-camera aria-pressed="true" title="Turn off camera">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17 10.5 21 7v10l-4-3.5V16a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 3 16V8a1.5 1.5 0 0 1 1.5-1.5h11A1.5 1.5 0 0 1 17 8v2.5Z" fill="currentColor"/></svg>
          </button>
          <button type="button" class="video-call-control-btn video-call-control-btn--danger" data-video-call-leave title="Leave call">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3C7 3 2.7 4.9.4 7.6a1.3 1.3 0 0 0 .1 1.8l3.2 3.1c.5.5 1.3.5 1.9.1l2-1.5c.4-.3.9-.3 1.3 0 .8.5 1.8.8 3.1.8s2.3-.3 3.1-.8c.4-.3.9-.3 1.3 0l2 1.5c.6.4 1.4.4 1.9-.1l3.2-3.1c.5-.5.6-1.3.1-1.8C21.3 4.9 17 3 12 3Z" fill="currentColor"/></svg>
            Leave
          </button>
        </div>
      </div>
    </section>
    <script defer src="<?= lex_e(function_exists('lex_asset_url') ? lex_asset_url('public/js/video-call.js') : lex_app_url('public/js/video-call.js')) ?>"></script>
    <?php
}
