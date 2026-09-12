<?php

declare(strict_types=1);

/**
 * Application bootstrap. Every entry-point PHP file in this app starts
 * with `require_once __DIR__ . '/../config/bootstrap.php';` (or a relative
 * equivalent) and then relies on the functions defined here for: session +
 * authentication, role-based access control, the shared page chrome
 * (sidebar/topbar/toasts), CSRF-protected forms, audit logging, email,
 * in-app notifications, site settings, and secure file storage (avatars,
 * GCash payment proof/QR, case vault documents).
 *
 * Security posture (see also security/*.php and SECURITY.md):
 *  - All database access goes through PDO prepared statements with bound
 *    parameters (PDO::ATTR_EMULATE_PREPARES is disabled in config/db.php),
 *    which is what actually defeats SQL injection - the DB server parses
 *    the query text before any value is substituted in.
 *  - Sessions are hardened (httponly/secure/samesite cookies, strict mode,
 *    IP pinning, idle timeout) in security/session_guard.php.
 *  - All state-changing forms are protected with a per-session CSRF token
 *    (security/csrf.php) validated via lex_csrf_validate().
 *  - All output is escaped with lex_e() (htmlspecialchars) by default.
 *  - Sensitive uploads (payment proofs, case vault documents, message
 *    attachments) are stored outside the web root under storage/ and can
 *    only be retrieved through an authenticated, access-checked PHP
 *    "gate" script - never a direct static URL.
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../security/input_sanitizer.php';
require_once __DIR__ . '/../security/rate_limiter.php';
require_once __DIR__ . '/../security/session_guard.php';
require_once __DIR__ . '/../security/db_security.php';

/**
 * Feature modules. Skip files that were not copied onto XAMPP so login
 * still works; copy the whole config\ folder to enable every feature.
 */
$lexOptionalBootFiles = [
    __DIR__ . '/../security/virus_scan.php',
    __DIR__ . '/messages/core.php',
    __DIR__ . '/messages/calls.php',
    __DIR__ . '/email_notifications.php',
    __DIR__ . '/case_files/core.php',
    __DIR__ . '/blockchain/ledger.php',
];
foreach ($lexOptionalBootFiles as $lexOptionalBootFile) {
    if (is_file($lexOptionalBootFile)) {
        require_once $lexOptionalBootFile;
    }
}

$lexEnsureDashboards = __DIR__ . '/ensure_dashboards.php';
if (is_file($lexEnsureDashboards)) {
    require_once $lexEnsureDashboards;
}

if (!function_exists('lex_require_availability')) {
    /**
     * Load lawyer schedule helpers. On a partial XAMPP copy the
     * config/appointments folder is often missing even when
     * lawyer/schedule.php was auto-created — write it from the packed
     * payload, then require it. Never fatal on a missing file.
     */
    function lex_require_availability(): bool
    {
        if (function_exists('lex_availability_tables_ensure') && function_exists('lex_availability_save_month')) {
            return true;
        }

        $file = __DIR__ . DIRECTORY_SEPARATOR . 'appointments' . DIRECTORY_SEPARATOR . 'availability.php';
        $existing = is_file($file) ? (string) file_get_contents($file) : '';
        $needsWrite = $existing === '' || !str_contains($existing, 'lex_availability_save_month');
        $source = '';
        if ($needsWrite) {
            if (function_exists('lex_portal_payloads')) {
                $encoded = lex_portal_payloads()['config/appointments/availability.php'] ?? '';
                if ($encoded !== '') {
                    $raw = base64_decode($encoded, true);
                    $decoded = is_string($raw) && $raw !== '' ? @gzinflate($raw) : false;
                    if (is_string($decoded) && str_contains($decoded, 'lex_availability_save_month')) {
                        $source = $decoded;
                    }
                }
            }
            if ($source === '' && function_exists('lex_schedule_availability_source')) {
                $candidate = lex_schedule_availability_source();
                if (str_contains($candidate, 'lex_availability_save_month')) {
                    $source = $candidate;
                }
            }
            if ($source !== '') {
                $dir = dirname($file);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                if (is_dir($dir)) {
                    @file_put_contents($file, $source);
                }
            }
        }

        if (is_file($file)) {
            require_once $file;
        }

        return function_exists('lex_availability_tables_ensure');
    }
}

lex_require_availability();

if (!function_exists('lex_write_packed_app_file')) {
    function lex_write_packed_app_file(string $relative): bool
    {
        $relative = str_replace(['\\', '..'], ['/', ''], $relative);
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $freshMarkers = [
            'phishing_check.php' => ['LEX_JSON_API', 'ob_start()'],
            'api/phishing/check.php' => ['lex_phishing_dns_records', 'ob_clean()'],
            'phishing/check.php' => ['lex_phishing_dns_records', 'ob_clean()'],
            'phishing/python.php' => ['lex_phishing_python_binaries', 'lex_phishing_python_is_real_binary'],
            'phishing/detect.py' => ['LEXSHIELD_PYTHON_PHISHING'],
            'public/js/chat.js' => ['parsePhishingResponse', 'phishingScanEndpoints', 'X-Lex-Phishing'],
            'public/js/video-call.js' => ['startOrUpgradeCamera', 'playRemote', 'Hear audio'],
        ];
        if (isset($freshMarkers[$relative]) && is_file($file)) {
            $existing = (string) file_get_contents($file);
            $fresh = true;
            foreach ($freshMarkers[$relative] as $marker) {
                if (!str_contains($existing, $marker)) {
                    $fresh = false;
                    break;
                }
            }
            if ($fresh) {
                return true;
            }
        } elseif (is_file($file) && filesize($file) >= 80) {
            $head = (string) file_get_contents($file, false, null, 0, 400);
            if (str_contains($head, '<?php')) {
                return true;
            }
        }
        if (!function_exists('lex_portal_payloads')) {
            return is_file($file);
        }
        $encoded = lex_portal_payloads()[$relative] ?? '';
        if ($encoded === '') {
            return is_file($file);
        }
        $raw = base64_decode($encoded, true);
        $source = is_string($raw) && $raw !== '' ? @gzinflate($raw) : false;
        if (!is_string($source) || $source === '') {
            return is_file($file);
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir)) {
            return false;
        }
        return @file_put_contents($file, $source) !== false;
    }
}

lex_write_packed_app_file('notifications_api.php');
lex_write_packed_app_file('phishing_check.php');
lex_write_packed_app_file('api/phishing/check.php');
lex_write_packed_app_file('phishing/check.php');
lex_write_packed_app_file('phishing/python.php');
lex_write_packed_app_file('phishing/detect.py');
lex_write_packed_app_file('public/js/chat.js');
lex_write_packed_app_file('public/js/video-call.js');

$lexPhishingInline = (
    (isset($_GET['lex_phishing']) && (string) $_GET['lex_phishing'] !== '' && (string) $_GET['lex_phishing'] !== '0')
    || (isset($_POST['lex_phishing']) && (string) $_POST['lex_phishing'] !== '' && (string) $_POST['lex_phishing'] !== '0')
    || (isset($_SERVER['HTTP_X_LEX_PHISHING']) && trim((string) $_SERVER['HTTP_X_LEX_PHISHING']) !== '' && trim((string) $_SERVER['HTTP_X_LEX_PHISHING']) !== '0')
);
if ($lexPhishingInline) {
    if (!defined('LEX_JSON_API')) {
        define('LEX_JSON_API', true);
    }
    if (!defined('LEX_PHISHING_DETECTOR_STANDALONE')) {
        define('LEX_PHISHING_DETECTOR_STANDALONE', true);
    }
    ini_set('display_errors', '0');
}

/* -----------------------------------------------------------------------
 * Environment / error handling
 * ---------------------------------------------------------------------*/

$lexAppEnv = strtolower((string) lex_env('APP_ENV', 'production'));
if (defined('LEX_JSON_API') && LEX_JSON_API) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
} elseif ($lexAppEnv === 'local' || $lexAppEnv === 'development' || $lexAppEnv === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
ini_set('log_errors', '1');
date_default_timezone_set(lex_env('APP_TIMEZONE', 'UTC') ?? 'UTC');
mb_internal_encoding('UTF-8');

/* -----------------------------------------------------------------------
 * Security headers (defense in depth - do not rely on these alone)
 * ---------------------------------------------------------------------*/

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self' blob:; "
        . "connect-src 'self' stun: turn: turns:; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* -----------------------------------------------------------------------
 * Global secrets / session bootstrap
 * ---------------------------------------------------------------------*/

$lexEncryptionKey = lex_app_encryption_key();
$lexSessionTimeout = (int) (lex_env('SESSION_TIMEOUT', '1800') ?? '1800');

// Must run before security/csrf.php is loaded: csrf.php falls back to a
// plain session_start() if no session is active yet, which would lock in
// PHP's default (non-hardened) cookie flags for the rest of the request.
// Starting the hardened session here first means csrf.php's own check
// (`session_status() !== PHP_SESSION_ACTIVE`) is already satisfied.
lex_start_secure_session();
require_once __DIR__ . '/../security/csrf.php';

if (!empty($_SESSION['user_id']) && empty($_SESSION['lex_online_touched'])) {
    try {
        lex_pdo()->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
            ->execute(['id' => (int) $_SESSION['user_id']]);
    } catch (Throwable $e) {}
    $_SESSION['lex_online_touched'] = time();
} elseif (!empty($_SESSION['user_id']) && !empty($_SESSION['lex_online_touched']) && (time() - (int) $_SESSION['lex_online_touched']) > 120) {
    try {
        lex_pdo()->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
            ->execute(['id' => (int) $_SESSION['user_id']]);
    } catch (Throwable $e) {}
    $_SESSION['lex_online_touched'] = time();
}

// Best-effort schema self-heal so the app works even if `php
// scripts/migrate.php` has not been run yet against a fresh database.
// Several dashboards query messages/case_files/notifications directly
// (COUNT(*) widgets etc.) without calling an explicit *_table_ensure()
// first, so those self-creating tables are also brought up here, once,
// up front - the same pattern rate_limits/video_call_* already use.
if (!defined('LEX_SKIP_CORE_TABLES_ENSURE')) {
    try {
        lex_core_tables_ensure();
        foreach ([
            'lex_messages_table_ensure',
            'lex_message_deletions_table_ensure',
            'lex_case_files_table_ensure',
            'lex_case_file_vault_table_ensure',
            'lex_blockchain_table_ensure',
            'lex_data_sharing_table_ensure',
            'lex_notifications_table_ensure',
        ] as $lexEnsureFn) {
            if (function_exists($lexEnsureFn)) {
                $lexEnsureFn();
            }
        }
    } catch (Throwable $e) {
        lex_db_last_error($e->getMessage());
    }
}

if (PHP_SAPI !== 'cli' && !defined('LEX_ALLOW_OFFLINE')) {
    $lexMissingExt = [];
    foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl'] as $lexExt) {
        if (!extension_loaded($lexExt)) {
            $lexMissingExt[] = $lexExt;
        }
    }
    if ($lexMissingExt !== []) {
        if (defined('LEX_JSON_API') && LEX_JSON_API) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'status' => 'suspicious',
                'score' => 0,
                'message' => 'PHP is missing: ' . implode(', ', $lexMissingExt) . '.',
                'findings' => [],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        lex_render_database_help_page(
            'PHP is missing: ' . implode(', ', $lexMissingExt)
            . '. In XAMPP, open php.ini and enable those extensions, then restart Apache.'
        );
        exit;
    }
    if (!lex_db_available()) {
        if (defined('LEX_JSON_API') && LEX_JSON_API) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'status' => 'suspicious',
                'score' => 0,
                'message' => 'The scanner could not reach the database. Start MySQL in XAMPP and try again.',
                'findings' => [],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        lex_render_database_help_page();
        exit;
    }
}

set_exception_handler(static function (Throwable $e): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (defined('LEX_JSON_API') && LEX_JSON_API) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'suspicious',
            'score' => 0,
            'message' => 'Unable to finish the scan. Please try again.',
            'findings' => [],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($e instanceof PDOException) {
        lex_render_database_help_page($e->getMessage());
        exit;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $isLocal = function_exists('lex_is_local_http_host') && lex_is_local_http_host();
    $env = strtolower((string) (lex_env('APP_ENV', 'production') ?? 'production'));
    if ($isLocal || in_array($env, ['local', 'development', 'dev'], true)) {
        echo '<!doctype html><html><head><meta charset="utf-8"><title>LEXSHIELD error</title></head><body style="font-family:sans-serif;padding:2rem;background:#0f1419;color:#e7eef7;">'
            . '<h1>Page error</h1><pre style="white-space:pre-wrap;background:#18202a;padding:1rem;border-radius:8px;">'
            . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8')
            . '</pre><p>On XAMPP, also check <code>C:\\xampp\\apache\\logs\\error.log</code>.</p></body></html>';
        exit;
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Unavailable</title></head><body style="font-family:sans-serif;padding:2rem;">'
        . '<h1>This page is temporarily unavailable</h1>'
        . '<p>If you are using XAMPP, start MySQL and open <a href="setup.php">setup.php</a>.</p></body></html>';
    exit;
});

register_shutdown_function(static function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $err = error_get_last();
    if (!is_array($err) || !in_array((int) $err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    $message = (string) ($err['message'] ?? 'Fatal error');
    $file = (string) ($err['file'] ?? '');
    $line = (string) ($err['line'] ?? '');
    if (defined('LEX_JSON_API') && LEX_JSON_API) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'suspicious',
            'score' => 0,
            'message' => 'Unable to finish the scan. Please try again.',
            'findings' => [],
        ], JSON_UNESCAPED_SLASHES);
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>LEXSHIELD error</title></head>'
        . '<body style="font-family:sans-serif;padding:2rem;background:#0f1419;color:#e7eef7;">'
        . '<h1>PHP stopped this page</h1>'
        . '<pre style="white-space:pre-wrap;background:#18202a;padding:1rem;border-radius:8px;">'
        . htmlspecialchars($message . "\n" . $file . ':' . $line, ENT_QUOTES, 'UTF-8')
        . '</pre>'
        . '<p>On XAMPP copy the latest <code>config/db.php</code>, <code>config/app.php</code>, and <code>setup.php</code> into <code>C:\\xampp\\htdocs\\lexshield</code>, then open <a href="setup.php" style="color:#7db4ff">setup.php</a>.</p>'
        . '</body></html>';
});

/* -----------------------------------------------------------------------
 * Output escaping
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_e')) {
    function lex_e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/* -----------------------------------------------------------------------
 * Authentication / authorization
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_current_user')) {
    function lex_current_user(): ?array
    {
        static $user = null;
        static $resolved = false;

        if ($resolved) {
            return $user;
        }
        $resolved = true;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        try {
            $stmt = lex_pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return null;
        }

        if (!$row || (int) $row['is_active'] !== 1) {
            return null;
        }

        if (($row['role'] ?? '') !== ($_SESSION['role'] ?? '')) {
            return null;
        }

        $user = $row;
        return $user;
    }
}

if (!function_exists('lex_require_login')) {
    function lex_require_login(): array
    {
        $user = lex_current_user();
        if (!$user) {
            $redirect = urlencode((string) ($_SERVER['REQUEST_URI'] ?? ''));
            if (function_exists('lex_redirect_app_file')) {
                lex_redirect_app_file('auth/login.php?redirect=' . $redirect);
            }
            header('Location: ' . lex_app_url('auth/login.php?redirect=' . $redirect));
            exit;
        }
        return $user;
    }
}

if (!function_exists('lex_require_role')) {
    /**
     * @param string|array<int, string> $role
     */
    function lex_require_role(string|array $role): array
    {
        $user = lex_require_login();
        $allowed = is_array($role) ? $role : [$role];

        if (!in_array((string) $user['role'], $allowed, true)) {
            http_response_code(403);
            $home = lex_dashboard_url();
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Access denied</title></head>'
                . '<body style="font-family:sans-serif;padding:3rem;text-align:center;">'
                . '<h1>403 - Access denied</h1><p>You do not have permission to view this page.</p>'
                . '<p><a href="' . lex_e($home) . '">Go to your dashboard</a></p></body></html>';
            exit;
        }

        return $user;
    }
}

if (!function_exists('lex_user_client_id')) {
    function lex_user_client_id(int $userId): int
    {
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $stmt = lex_pdo()->prepare('SELECT id FROM clients WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        $cache[$userId] = $id;
        return $id;
    }
}

if (!function_exists('lex_user_lawyer_id')) {
    function lex_user_lawyer_id(int $userId): int
    {
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $stmt = lex_pdo()->prepare('SELECT id FROM lawyers WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        $cache[$userId] = $id;
        return $id;
    }
}

/* -----------------------------------------------------------------------
 * Flash messages (toast notifications shown after a redirect)
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_flash_set')) {
    function lex_flash_set(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('lex_flash_consume')) {
    /**
     * @return array<int, array{type:string, message:string}>
     */
    function lex_flash_consume(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }
}

/* -----------------------------------------------------------------------
 * Audit trail
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_audit')) {
    function lex_audit(string $action, ?string $targetTable = null, ?string $targetId = null, ?int $userId = null): void
    {
        try {
            $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
            $stmt = lex_pdo()->prepare(
                'INSERT INTO audit_logs (user_id, action, target_table, target_id, ip_address, user_agent)
                 VALUES (:user_id, :action, :target_table, :target_id, :ip_address, :user_agent)'
            );
            $stmt->execute([
                'user_id' => $userId > 0 ? $userId : null,
                'action' => substr($action, 0, 128),
                'target_table' => $targetTable !== null ? substr($targetTable, 0, 64) : null,
                'target_id' => $targetId !== null ? substr($targetId, 0, 64) : null,
                'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the request it is auditing.
        }
    }
}

if (!function_exists('lex_audit_csrf_failure')) {
    function lex_audit_csrf_failure(string $context): void
    {
        lex_audit('csrf_failure', null, substr($context, 0, 64));
    }
}

if (!function_exists('lex_activity_label')) {
    function lex_activity_label(string $action): string
    {
        static $labels = [
            'login' => 'Signed in',
            'admin_login' => 'Admin signed in',
            'failed_login' => 'Failed login',
            'failed_admin_login' => 'Failed admin login',
            'new_browser_login' => 'New browser login',
            'logout' => 'Signed out',
            'csrf_failure' => 'CSRF token rejected',
            'login_otp_requested' => 'Login code requested',
            'expired_otp' => 'Verification code expired',
            'failed_otp' => 'Incorrect verification code',
            'failed_otp_locked' => 'Verification code locked',
            'password_reset_requested' => 'Password reset requested',
            'update_settings' => 'Updated system settings',
            'clear_phishing_scans' => 'Cleared phishing scans',
            'share_requested' => 'Data share requested',
            'share_approved' => 'Data share approved',
            'share_rejected' => 'Data share rejected',
            'virus_detected' => 'Blocked infected upload',
        ];

        return $labels[$action] ?? ucwords(str_replace(['_', '-'], ' ', $action));
    }
}

/* -----------------------------------------------------------------------
 * In-app notifications
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_notifications_table_ensure')) {
    function lex_notifications_table_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            lex_pdo()->exec(
                "CREATE TABLE IF NOT EXISTS `notifications` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `user_id` INT NOT NULL,
                    `type` VARCHAR(40) NOT NULL DEFAULT 'general',
                    `message` TEXT NOT NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_notifications_user` (`user_id`, `is_read`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        } catch (Throwable $e) {
            $done = false;
        }
    }
}

if (!function_exists('lex_notifications_unread_count')) {
    function lex_notifications_unread_count(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        lex_notifications_table_ensure();
        try {
            $stmt = lex_pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0');
            $stmt->execute(['id' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('lex_notifications_recent')) {
    /**
     * @return list<array{id:int|string,type:string,message:string,is_read:int|string,created_at:string}>
     */
    function lex_notifications_recent(int $userId, int $limit = 20): array
    {
        if ($userId <= 0) {
            return [];
        }
        lex_notifications_table_ensure();
        $limit = min(30, max(5, $limit));
        try {
            $stmt = lex_pdo()->prepare(
                'SELECT id, type, message, is_read, created_at
                 FROM notifications
                 WHERE user_id = :id
                 ORDER BY created_at DESC
                 LIMIT ' . $limit
            );
            $stmt->execute(['id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('lex_notify')) {
    function lex_notify(int $userId, string $type, string $message): void
    {
        if ($userId <= 0 || trim($message) === '') {
            return;
        }

        lex_notifications_table_ensure();
        try {
            $stmt = lex_pdo()->prepare(
                'INSERT INTO notifications (user_id, type, message) VALUES (:user_id, :type, :message)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'type' => substr($type, 0, 40),
                'message' => $message,
            ]);
        } catch (Throwable $e) {
            // Notifications are best-effort.
        }
    }
}

if (!function_exists('lex_notifications_unread_of_types')) {
    /**
     * @param list<string> $types
     */
    function lex_notifications_unread_of_types(int $userId, array $types): int
    {
        if ($userId <= 0 || $types === []) {
            return 0;
        }

        lex_notifications_table_ensure();
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        try {
            $stmt = lex_pdo()->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE user_id = ? AND is_read = 0 AND type IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$userId], array_values($types)));

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('lex_notifications_mark_types_read')) {
    /**
     * @param list<string> $types
     */
    function lex_notifications_mark_types_read(int $userId, array $types): void
    {
        if ($userId <= 0 || $types === []) {
            return;
        }

        lex_notifications_table_ensure();
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        try {
            $stmt = lex_pdo()->prepare(
                "UPDATE notifications
                 SET is_read = 1
                 WHERE user_id = ? AND is_read = 0 AND type IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$userId], array_values($types)));
        } catch (Throwable $e) {
            // Best-effort.
        }
    }
}

if (!function_exists('lex_nav_unread_messages')) {
    function lex_nav_unread_messages(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            if (function_exists('lex_message_deletions_table_ensure')) {
                lex_message_deletions_table_ensure();
            }
            $stmt = lex_pdo()->prepare(
                'SELECT COUNT(*) FROM messages m
                 WHERE m.receiver_id = :uid
                   AND m.is_read = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM message_deletions d
                       WHERE d.message_id = m.id AND d.user_id = :uid2
                   )'
            );
            $stmt->execute(['uid' => $userId, 'uid2' => $userId]);

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            try {
                $stmt = lex_pdo()->prepare(
                    'SELECT COUNT(*) FROM messages WHERE receiver_id = :uid AND is_read = 0'
                );
                $stmt->execute(['uid' => $userId]);

                return (int) $stmt->fetchColumn();
            } catch (Throwable $e2) {
                return 0;
            }
        }
    }
}

if (!function_exists('lex_notify_role_users')) {
    function lex_notify_role_users(string $role, string $type, string $message): void
    {
        if ($role === '' || trim($message) === '') {
            return;
        }

        try {
            $stmt = lex_pdo()->prepare(
                'SELECT id FROM users WHERE role = :role AND is_active = 1'
            );
            $stmt->execute(['role' => $role]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                lex_notify((int) ($row['id'] ?? 0), $type, $message);
            }
        } catch (Throwable $e) {
            // Best-effort.
        }
    }
}

if (!function_exists('lex_require_phishing')) {
    function lex_require_phishing(): void
    {
        if (function_exists('lex_phishing_response_for_url')) {
            return;
        }
        if (!defined('LEX_PHISHING_DETECTOR_STANDALONE')) {
            define('LEX_PHISHING_DETECTOR_STANDALONE', true);
        }
        if (function_exists('lex_write_packed_app_file')) {
            lex_write_packed_app_file('api/phishing/check.php');
            lex_write_packed_app_file('phishing/check.php');
            lex_write_packed_app_file('phishing/python.php');
            lex_write_packed_app_file('phishing_check.php');
        }
        $root = dirname(__DIR__);
        foreach ([
            $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'phishing' . DIRECTORY_SEPARATOR . 'check.php',
            $root . DIRECTORY_SEPARATOR . 'phishing' . DIRECTORY_SEPARATOR . 'check.php',
            $root . DIRECTORY_SEPARATOR . 'phishing_check.php',
        ] as $file) {
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
}

if (!function_exists('lex_phishing_scan_endpoint')) {
    function lex_phishing_scan_endpoint(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== '' && !str_ends_with($script, '/phishing_check.php')) {
            return $script . '?lex_phishing=1';
        }
        if (function_exists('lex_app_url')) {
            return lex_app_url('notifications_api.php') . '?lex_phishing=1';
        }
        return '?lex_phishing=1';
    }
}

if (!function_exists('lex_phishing_dispatch_inline')) {
    function lex_phishing_dispatch_inline(): void
    {
        lex_require_phishing();
        if (!function_exists('lex_phishing_handle_request')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'status' => 'suspicious',
                'score' => 0,
                'message' => 'The phishing scanner could not start. Refresh this page and try again.',
                'findings' => [],
                'engines' => ['php'],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (ob_get_level() === 0) {
            ob_start();
        }
        lex_phishing_handle_request();
        if (ob_get_level() === 0) {
            exit;
        }
        $buffered = (string) ob_get_clean();
        $start = strpos($buffered, '{');
        $end = strrpos($buffered, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($buffered, $start, $end - $start + 1);
            if (is_array(json_decode($json, true))) {
                echo $json;
                exit;
            }
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'suspicious',
            'score' => 0,
            'message' => 'Unable to finish the scan. Please try again.',
            'findings' => [],
            'engines' => ['php'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('lex_phishing_urls_in_text')) {
    /**
     * @return list<string>
     */
    function lex_phishing_urls_in_text(string $text): array
    {
        if (!preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }
}

if (!function_exists('lex_phishing_scan_message_body')) {
    function lex_phishing_scan_message_body(string $body): ?string
    {
        $urls = lex_phishing_urls_in_text($body);
        if ($urls === []) {
            return null;
        }

        lex_require_phishing();
        if (!function_exists('lex_phishing_response_for_url')) {
            return null;
        }

        foreach ($urls as $url) {
            try {
                $result = lex_phishing_response_for_url($url, false);
            } catch (Throwable $e) {
                continue;
            }
            if ((string) ($result['status'] ?? '') === 'phishing') {
                return 'This message was blocked because it contains a phishing link.';
            }
        }

        return null;
    }
}

if (!function_exists('lex_phishing_open_onclick')) {
    function lex_phishing_open_onclick(): string
    {
        return 'if (event) { event.preventDefault(); event.stopPropagation(); } var d=document.getElementById("phishingDetectorModal"); if (!d) { return false; } d.classList.add("is-open"); d.setAttribute("aria-hidden","false"); d.inert=false; if (d.showModal && !d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open",""); } } else if (!d.open) { d.setAttribute("open",""); } var i=d.querySelector("[data-phishing-input]"); if (i) { try { i.focus(); } catch (e) {} } return false;';
    }
}

if (!function_exists('lex_phishing_close_onclick')) {
    function lex_phishing_close_onclick(): string
    {
        return 'if (event) { event.preventDefault(); event.stopPropagation(); } var d=document.getElementById("phishingDetectorModal"); if (!d) { return false; } d.classList.remove("is-open"); d.setAttribute("aria-hidden","true"); if (d.close && d.open) { try { d.close(); } catch (e) { d.removeAttribute("open"); } } else { d.removeAttribute("open"); } return false;';
    }
}

if (!function_exists('lex_phishing_modal_markup')) {
    function lex_phishing_modal_markup(): void
    {
        $endpoint = function_exists('lex_phishing_scan_endpoint')
            ? lex_phishing_scan_endpoint()
            : ((function_exists('lex_app_url') ? lex_app_url('notifications_api.php') : 'notifications_api.php') . '?lex_phishing=1');
        $fallback = function_exists('lex_app_url')
            ? lex_app_url('notifications_api.php') . '?lex_phishing=1'
            : 'notifications_api.php?lex_phishing=1';
        ?>
<dialog class="inbox-new-message-modal phishing-detector-modal" id="phishingDetectorModal" data-modal closedby="any" aria-labelledby="phishingDetectorTitle">
  <article class="modal-card phishing-detector-card">
    <div class="modal-header">
      <h2 id="phishingDetectorTitle">Phishing detector</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Close" onclick="<?= lex_e(lex_phishing_close_onclick()) ?>">&times;</button>
    </div>
    <form class="modal-body phishing-detector-form" data-phishing-form data-endpoint="<?= lex_e($endpoint) ?>" data-fallback-endpoint="<?= lex_e($fallback) ?>" data-no-loading>
      <label class="phishing-detector-field">Paste a link to scan
        <input type="url" data-phishing-input placeholder="https://example.com" autocomplete="off" required>
      </label>
      <p class="phishing-detector-error" data-phishing-error hidden></p>
      <div class="phishing-detector-result" data-phishing-result hidden></div>
      <button class="button button-primary" type="submit" data-phishing-submit>
        <span class="phishing-spinner" hidden aria-hidden="true"></span>
        <span data-phishing-submit-label>Scan</span>
      </button>
    </form>
  </article>
</dialog>
        <?php
    }
}

if (!empty($lexPhishingInline) && function_exists('lex_phishing_dispatch_inline')) {
    lex_phishing_dispatch_inline();
}

if (!function_exists('lex_nav_badge_type_map')) {
    /**
     * @return array<string, list<string>>
     */
    function lex_nav_badge_type_map(): array
    {
        return [
            'messages' => ['message', 'call'],
            'appointments' => ['appointment'],
            'payments' => ['payment'],
            'billing' => ['payment'],
            'data-sharing' => ['data_sharing'],
            'profile' => ['security'],
            'settings' => ['security'],
            'inquiries' => ['inquiry'],
            'lawyers' => ['lawyer'],
            'clients' => ['client'],
            'case-files' => ['case_file'],
            'blockchain' => ['blockchain'],
            'phishing' => ['phishing'],
            'audit' => ['audit'],
            'schedule' => ['schedule'],
            'dashboard' => ['dashboard', 'general'],
        ];
    }
}

if (!function_exists('lex_nav_key_for_type')) {
    function lex_nav_key_for_type(string $type, string $role = ''): string
    {
        if ($type === 'security') {
            return $role === 'admin' ? 'settings' : 'profile';
        }
        if ($type === 'payment' && $role === 'client') {
            return 'payments';
        }

        foreach (lex_nav_badge_type_map() as $key => $types) {
            if (in_array($type, $types, true)) {
                return $key;
            }
        }

        return 'dashboard';
    }
}

if (!function_exists('lex_nav_mark_active_read')) {
    function lex_nav_mark_active_read(int $userId, string $activeNav): void
    {
        if ($userId <= 0 || $activeNav === '' || $activeNav === 'dashboard') {
            return;
        }

        $types = lex_nav_badge_type_map()[$activeNav] ?? [];
        if ($types !== []) {
            lex_notifications_mark_types_read($userId, $types);
        }
    }
}

if (!function_exists('lex_nav_appointment_count')) {
    function lex_nav_appointment_count(int $userId, string $role): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $role = $role === 'attorney' ? 'lawyer' : $role;

        try {
            if ($role === 'lawyer') {
                $lawyerId = function_exists('lex_user_lawyer_id') ? lex_user_lawyer_id($userId) : 0;
                $count = 0;
                if ($lawyerId > 0) {
                    $stmt = lex_pdo()->prepare(
                        'SELECT COUNT(*) FROM appointments
                         WHERE lawyer_id = :id
                           AND LOWER(status) = :status'
                    );
                    $stmt->execute(['id' => $lawyerId, 'status' => 'pending']);
                    $count = (int) $stmt->fetchColumn();
                }
                if ($count < 1) {
                    $stmt = lex_pdo()->prepare(
                        'SELECT COUNT(*) FROM appointments
                         WHERE lawyer_id = :id
                           AND LOWER(status) = :status'
                    );
                    $stmt->execute(['id' => $userId, 'status' => 'pending']);
                    $count = (int) $stmt->fetchColumn();
                }

                return $count;
            }

            if ($role === 'client') {
                $clientId = lex_user_client_id($userId);
                if ($clientId < 1) {
                    return 0;
                }
                $stmt = lex_pdo()->prepare(
                    'SELECT COUNT(*) FROM appointments
                     WHERE client_id = :id
                       AND status = :status'
                );
                $stmt->execute(['id' => $clientId, 'status' => 'pending']);

                return (int) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            return 0;
        }

        return 0;
    }
}

if (!function_exists('lex_nav_badge_words')) {
    function lex_nav_badge_words(string $key, int $count): string
    {
        if ($count < 1) {
            return '';
        }

        $word = match ($key) {
            'appointments' => $count === 1 ? 'appointment' : 'appointments',
            'messages' => $count === 1 ? 'new message' : 'new messages',
            'payments', 'billing' => $count === 1 ? 'payment' : 'payments',
            'inquiries' => $count === 1 ? 'inquiry' : 'inquiries',
            'data-sharing' => $count === 1 ? 'share request' : 'share requests',
            'case-files' => $count === 1 ? 'case file' : 'case files',
            'clients' => $count === 1 ? 'new client' : 'new clients',
            'lawyers' => $count === 1 ? 'lawyer update' : 'lawyer updates',
            default => $count === 1 ? 'update' : 'updates',
        };

        return $count . ' ' . $word;
    }
}

if (!function_exists('lex_nav_badge_counts')) {
    /**
     * @return array<string, int>
     */
    function lex_nav_badge_counts(int $userId, string $role = ''): array
    {
        $counts = [];
        foreach (array_keys(lex_nav_badge_type_map()) as $key) {
            $counts[$key] = 0;
        }

        if ($userId <= 0) {
            return $counts;
        }

        if ($role === '') {
            $current = function_exists('lex_current_user') ? lex_current_user() : null;
            if (is_array($current) && (int) ($current['id'] ?? 0) === $userId) {
                $role = (string) ($current['role'] ?? '');
            }
        }
        if ($role === 'attorney') {
            $role = 'lawyer';
        }

        $counts['messages'] = lex_nav_unread_messages($userId);
        $messageNotifs = 0;
        lex_notifications_table_ensure();
        try {
            $stmt = lex_pdo()->prepare(
                'SELECT type, COUNT(*) AS total
                 FROM notifications
                 WHERE user_id = :uid AND is_read = 0
                 GROUP BY type'
            );
            $stmt->execute(['uid' => $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = lex_nav_key_for_type((string) ($row['type'] ?? ''), $role);
                if ($key === 'messages') {
                    $messageNotifs += (int) ($row['total'] ?? 0);
                    continue;
                }
                $counts[$key] = (int) ($counts[$key] ?? 0) + (int) ($row['total'] ?? 0);
            }
        } catch (Throwable $e) {
            // Keep the live message count even if notifications are unavailable.
        }
        $counts['messages'] = max((int) $counts['messages'], $messageNotifs);

        if ($role === 'client') {
            $counts['billing'] = max((int) ($counts['billing'] ?? 0), (int) ($counts['payments'] ?? 0));
        }

        if ($role === 'admin') {
            try {
                $counts['inquiries'] = max(
                    (int) ($counts['inquiries'] ?? 0),
                    (int) lex_pdo()->query("SELECT COUNT(*) FROM quick_inquiries WHERE status = 'new'")->fetchColumn()
                );
            } catch (Throwable $e) {
            }
            try {
                $counts['payments'] = max(
                    (int) ($counts['payments'] ?? 0),
                    (int) lex_pdo()->query("SELECT COUNT(*) FROM manual_payments WHERE status = 'pending'")->fetchColumn()
                );
            } catch (Throwable $e) {
            }
            try {
                $counts['data-sharing'] = max(
                    (int) ($counts['data-sharing'] ?? 0),
                    (int) lex_pdo()->query("SELECT COUNT(*) FROM data_sharing_requests WHERE status = 'pending'")->fetchColumn()
                );
            } catch (Throwable $e) {
            }
        }

        $counts['appointments'] = max(
            (int) ($counts['appointments'] ?? 0),
            lex_nav_appointment_count($userId, $role)
        );

        $counts['dashboard'] = max(
            (int) ($counts['dashboard'] ?? 0),
            function_exists('lex_notifications_unread_count') ? lex_notifications_unread_count($userId) : 0
        );

        return $counts;
    }
}

/* -----------------------------------------------------------------------
 * Site settings (site_settings table: setting_key / setting_value)
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_site_setting')) {
    function lex_site_setting(string $key, string $default = '', bool $flush = false): string
    {
        static $cache = null;
        if ($flush) {
            $cache = null;
            return $default;
        }
        if ($cache === null) {
            $cache = [];
            try {
                foreach (lex_recent('SELECT setting_key, setting_value FROM site_settings') as $row) {
                    $cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
                }
            } catch (Throwable $e) {
                $cache = [];
            }
        }

        $value = trim((string) ($cache[$key] ?? ''));
        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('lex_site_setting_flush')) {
    function lex_site_setting_flush(): void
    {
        lex_site_setting('', '', true);
    }
}

if (!function_exists('lex_config_value')) {
    function lex_config_value(string $settingKey, string $envKey, string $default = ''): string
    {
        $fromEnv = trim((string) (lex_env($envKey, '') ?? ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        $fromSettings = trim(lex_site_setting($settingKey, ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }
        return $default;
    }
}

/* -----------------------------------------------------------------------
 * Mail (PHPMailer, configured from site_settings with env var fallback)
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_phpmailer_loaded')) {
    function lex_phpmailer_loaded(): bool
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) {
            $loaded = true;
            return true;
        }
        $roots = [
            dirname(__DIR__) . '/lib/phpmailer',
            dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src',
        ];
        foreach ($roots as $root) {
            $exception = $root . DIRECTORY_SEPARATOR . 'Exception.php';
            $phpmailer = $root . DIRECTORY_SEPARATOR . 'PHPMailer.php';
            $smtp = $root . DIRECTORY_SEPARATOR . 'SMTP.php';
            if (is_file($exception) && is_file($phpmailer) && is_file($smtp)) {
                require_once $exception;
                require_once $phpmailer;
                require_once $smtp;
                $loaded = class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false);
                return $loaded;
            }
        }
        $loaded = false;
        return false;
    }
}

lex_phpmailer_loaded();

if (!function_exists('lex_mail_error')) {
    function lex_mail_error(?string $message = null): ?string
    {
        static $lastError = null;
        if ($message !== null) {
            $lastError = $message;
            return null;
        }
        return $lastError;
    }
}

if (!function_exists('lex_mail_config')) {
    /**
     * @return array{host:string,port:int,user:string,pass:string,from:string,from_name:string,encryption:string,auth:bool}
     */
    function lex_mail_config(): array
    {
        $port = (int) lex_config_value('smtp_port', 'MAIL_PORT', '465');
        if ($port <= 0) {
            $port = 465;
        }
        $encryption = strtolower(lex_config_value('smtp_encryption', 'MAIL_ENCRYPTION', ''));
        if ($encryption === '') {
            $encryption = $port === 465 ? 'smtps' : 'tls';
        }
        $user = trim(lex_config_value('smtp_user', 'MAIL_USER', ''));
        $from = trim(lex_config_value('smtp_from', 'MAIL_FROM', $user));
        $pass = preg_replace('/\s+/', '', lex_config_value('smtp_pass', 'MAIL_PASS', '')) ?? '';
        $authRaw = strtolower(lex_config_value('smtp_auth', 'MAIL_SMTP_AUTH', 'true'));
        $auth = !in_array($authRaw, ['0', 'false', 'off', 'no'], true);

        return [
            'host' => lex_config_value('smtp_host', 'MAIL_HOST', ''),
            'port' => $port,
            'user' => $user,
            'pass' => $pass,
            'from' => $from !== '' ? $from : $user,
            'from_name' => lex_config_value('site_name', 'MAIL_FROM_NAME', 'LEXSHIELD'),
            'encryption' => $encryption,
            'auth' => $auth,
        ];
    }
}

if (!function_exists('lex_mail_is_configured')) {
    function lex_mail_is_configured(): bool
    {
        $config = lex_mail_config();
        if ($config['host'] === '' || $config['from'] === '' || !filter_var($config['from'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($config['auth'] && ($config['user'] === '' || $config['pass'] === '')) {
            return false;
        }
        return lex_phpmailer_loaded();
    }
}

if (!function_exists('lex_gmail_app_password_ready')) {
    /**
     * Gmail SMTP rejects the normal account password. App Passwords are
     * 16 letters. If the saved password is anything else, do not attempt
     * login OTP (XAMPP users can still sign in with email + password).
     */
    function lex_gmail_app_password_ready(): bool
    {
        $config = lex_mail_config();
        $host = strtolower((string) ($config['host'] ?? ''));
        if ($host === '' || (!str_contains($host, 'gmail.com') && !str_contains($host, 'googlemail.com'))) {
            return true;
        }
        $pass = preg_replace('/\s+/', '', (string) ($config['pass'] ?? '')) ?? '';
        return strlen($pass) === 16 && ctype_alpha($pass);
    }
}

if (!function_exists('lex_mail_can_send_otp')) {
    function lex_mail_can_send_otp(): bool
    {
        return lex_mail_is_configured() && lex_gmail_app_password_ready();
    }
}

if (!function_exists('lex_mail_log')) {
    function lex_mail_log(string $message): void
    {
        try {
            $dir = lex_storage_ensure_dir(lex_storage_path('logs'));
            file_put_contents($dir . '/mail.log', date('c') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Logging must never break a request.
        }
    }
}

if (!function_exists('lex_mail_public_error')) {
    function lex_mail_public_error(): string
    {
        $detail = strtolower((string) (lex_mail_error() ?? ''));
        if (str_contains($detail, 'authenticate') || str_contains($detail, '535')) {
            return 'Gmail rejected the SMTP password. Put the 16-character App Password in .env as MAIL_PASS (same Gmail in MAIL_USER and MAIL_FROM). Copy the latest config/bootstrap.php so .env is used. Create an App Password at https://myaccount.google.com/apppasswords';
        }
        $env = strtolower((string) lex_env('APP_ENV', 'production'));
        $raw = lex_mail_error();
        if (in_array($env, ['local', 'development', 'dev'], true) && $raw) {
            return $raw;
        }
        return 'Ask an administrator to confirm SMTP under System Settings.';
    }
}

if (!function_exists('lex_smtp_local_bypass')) {
    function lex_smtp_local_bypass(): bool
    {
        $env = strtolower((string) (lex_env('APP_ENV', 'production') ?? 'production'));
        return in_array($env, ['local', 'development', 'dev'], true)
            || (function_exists('lex_is_local_http_host') && lex_is_local_http_host());
    }
}

if (!function_exists('lex_apply_mail_env_to_settings')) {
    /**
     * Copy MAIL_* from .env into site_settings so a leftover System Settings
     * Gmail password cannot keep winning on XAMPP.
     */
    function lex_apply_mail_env_to_settings(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $map = [
            'smtp_host' => 'MAIL_HOST',
            'smtp_port' => 'MAIL_PORT',
            'smtp_user' => 'MAIL_USER',
            'smtp_from' => 'MAIL_FROM',
            'smtp_encryption' => 'MAIL_ENCRYPTION',
            'smtp_pass' => 'MAIL_PASS',
        ];
        $updates = [];
        foreach ($map as $settingKey => $envKey) {
            $fromEnv = trim((string) (lex_env($envKey, '') ?? ''));
            if ($settingKey === 'smtp_pass') {
                $fromEnv = preg_replace('/\s+/', '', $fromEnv) ?? '';
            }
            if ($fromEnv === '') {
                continue;
            }
            $current = trim(lex_site_setting($settingKey, ''));
            if ($settingKey === 'smtp_pass') {
                $current = preg_replace('/\s+/', '', $current) ?? '';
            }
            if ($current !== $fromEnv) {
                $updates[$settingKey] = $fromEnv;
            }
        }
        if ($updates === []) {
            return;
        }

        try {
            $stmt = lex_pdo()->prepare(
                'REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW())'
            );
            foreach ($updates as $key => $value) {
                $stmt->execute([
                    'setting_key' => $key,
                    'setting_value' => $value,
                ]);
            }
            lex_site_setting_flush();
        } catch (Throwable $e) {
            // Settings sync must never break login.
        }
    }
}

lex_apply_mail_env_to_settings();

if (!function_exists('lex_send_email')) {
    function lex_send_email(string $to, string $subject, string $body): bool
    {
        if (function_exists('lex_apply_mail_env_to_settings')) {
            lex_apply_mail_env_to_settings();
        }

        $to = lex_sanitize_email($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            lex_mail_error('Invalid recipient email address.');
            return false;
        }

        $config = lex_mail_config();
        if ($config['host'] === '') {
            lex_mail_error('SMTP host is not configured. Set MAIL_HOST in .env or Admin > System Settings.');
            lex_mail_log('send failed to ' . $to . ': SMTP host missing');
            return false;
        }
        if ($config['from'] === '' || !filter_var($config['from'], FILTER_VALIDATE_EMAIL)) {
            lex_mail_error('SMTP from address is not configured. Set MAIL_FROM or MAIL_USER.');
            lex_mail_log('send failed to ' . $to . ': SMTP from missing');
            return false;
        }
        if ($config['auth'] && ($config['user'] === '' || $config['pass'] === '')) {
            lex_mail_error('SMTP username or password is missing. Set MAIL_USER and MAIL_PASS (Gmail needs an App Password).');
            lex_mail_log('send failed to ' . $to . ': SMTP auth missing');
            return false;
        }

        if (!lex_phpmailer_loaded()) {
            lex_mail_error('PHPMailer is missing. Copy the lib/phpmailer folder into C:\\xampp\\htdocs\\lexshield\\lib\\phpmailer.');
            lex_mail_log('send failed to ' . $to . ': PHPMailer missing');
            return false;
        }

        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $config['host'];
            $mailer->Port = $config['port'];
            $mailer->Timeout = 20;
            $mailer->CharSet = 'UTF-8';
            $mailer->SMTPAuth = $config['auth'];
            if ($config['auth']) {
                $mailer->Username = $config['user'];
                $mailer->Password = $config['pass'];
            }
            if ($config['encryption'] === 'smtps' || $config['port'] === 465) {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['encryption'] === 'tls' || $config['encryption'] === 'starttls') {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mailer->SMTPSecure = false;
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->setFrom($config['from'], $config['from_name'] !== '' ? $config['from_name'] : 'LEXSHIELD');
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->isHTML(false);
            $mailer->Body = $body;

            $mailer->send();
            lex_mail_log('sent to ' . $to . ' subject=' . $subject);
            return true;
        } catch (Throwable $e) {
            $info = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : $e->getMessage();
            lex_mail_error($info);
            lex_mail_log('send failed to ' . $to . ': ' . $info);
            return false;
        }
    }
}

if (!function_exists('lex_email_otps_ensure')) {
    function lex_email_otps_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            lex_pdo()->exec('ALTER TABLE `email_otps` ADD COLUMN `attempts` INT NOT NULL DEFAULT 0');
        } catch (PDOException $e) {
            // Column already exists.
        }
        $done = true;
    }
}

if (!defined('LEX_SKIP_CORE_TABLES_ENSURE')) {
    try {
        lex_email_otps_ensure();
    } catch (Throwable $e) {
        // Schema self-heal is best-effort.
    }
}

if (!function_exists('lex_otp_enabled_flags')) {
    /**
     * @return array{login:bool,admin:bool,registration:bool}
     */
    function lex_otp_enabled_flags(): array
    {
        $bool = static function (string $key, string $default): bool {
            return filter_var(lex_env($key, $default) ?: $default, FILTER_VALIDATE_BOOL);
        };
        return [
            'login' => $bool('LOGIN_OTP_ENABLED', 'true'),
            'admin' => $bool('ADMIN_OTP_ENABLED', 'true'),
            'registration' => $bool('CLIENT_REGISTRATION_OTP_ENABLED', 'true'),
        ];
    }
}

if (!function_exists('lex_send_account_activity_alert')) {
    /**
     * @param array{email?:string, full_name?:string} $user
     */
    function lex_send_account_activity_alert(array $user, string $subject, string $body): bool
    {
        $email = lex_sanitize_email((string) ($user['email'] ?? ''));
        if ($email === '') {
            return false;
        }

        $greeting = trim((string) ($user['full_name'] ?? '')) !== ''
            ? 'Hi ' . $user['full_name'] . ",\n\n"
            : "Hi,\n\n";

        return lex_send_email($email, $subject, $greeting . $body . "\n\nIf this wasn't you, please secure your account immediately.");
    }
}

if (!function_exists('lex_send_security_event_notification')) {
    /**
     * @param array{id?:int, email?:string, full_name?:string} $user
     */
    function lex_send_security_event_notification(array $user, string $subject, string $body, string $notifyMessage): bool
    {
        $sent = lex_send_account_activity_alert($user, $subject, $body);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            lex_notify($userId, 'security', $notifyMessage);
        }
        return $sent;
    }
}

/* -----------------------------------------------------------------------
 * Timestamps
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_message_timestamp')) {
    function lex_message_timestamp(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($datetime);
        } catch (Throwable $e) {
            return $datetime;
        }

        $now = new DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $minutes = (int) floor($diff / 60);
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }
        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'Today at ' . $date->format('g:i A');
        }
        if ($date->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            return 'Yesterday at ' . $date->format('g:i A');
        }

        return $date->format('M j, Y g:i A');
    }
}

/* -----------------------------------------------------------------------
 * Admin pagination component
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_admin_pagination')) {
    /**
     * @param array<string, string|int> $params extra query params to preserve
     */
    function lex_admin_pagination(string $baseUrl, array $params, int $total, int $currentPage, int $perPage): string
    {
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $currentPage = max(1, min($currentPage, $totalPages));
        if ($totalPages <= 1) {
            return '';
        }

        $buildUrl = static function (int $page) use ($baseUrl, $params): string {
            $query = $params;
            $query['page'] = $page;
            return lex_app_url($baseUrl) . '?' . http_build_query($query);
        };

        $html = '<nav class="pagination" aria-label="Pagination">';
        $html .= '<a class="pagination-box' . ($currentPage <= 1 ? ' is-disabled' : '') . '" href="' . lex_e($buildUrl(max(1, $currentPage - 1))) . '"' . ($currentPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '') . '>&lsaquo;</a>';

        $window = 2;
        for ($page = 1; $page <= $totalPages; $page++) {
            if ($page === 1 || $page === $totalPages || ($page >= $currentPage - $window && $page <= $currentPage + $window)) {
                $html .= '<a class="pagination-box' . ($page === $currentPage ? ' is-active' : '') . '" href="' . lex_e($buildUrl($page)) . '"' . ($page === $currentPage ? ' aria-current="page"' : '') . '>' . $page . '</a>';
            } elseif ($page === $currentPage - $window - 1 || $page === $currentPage + $window + 1) {
                $html .= '<span class="pagination-box is-ellipsis" aria-hidden="true">&hellip;</span>';
            }
        }

        $html .= '<a class="pagination-box' . ($currentPage >= $totalPages ? ' is-disabled' : '') . '" href="' . lex_e($buildUrl(min($totalPages, $currentPage + 1))) . '"' . ($currentPage >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '') . '>&rsaquo;</a>';
        $html .= '</nav>';

        return $html;
    }
}

/* -----------------------------------------------------------------------
 * Admin: change a user's password on their behalf
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_admin_change_user_password')) {
    function lex_admin_change_user_password(int $userId, string $role, string $newPassword): bool
    {
        $stmt = lex_pdo()->prepare('SELECT id, email, full_name, role FROM users WHERE id = :id AND role = :role LIMIT 1');
        $stmt->execute(['id' => $userId, 'role' => $role]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        lex_pdo()->prepare('UPDATE users SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $userId]);

        lex_audit('admin_reset_password', 'users', (string) $userId);
        lex_notify($userId, 'security', 'An administrator changed your LEXSHIELD account password.');

        return lex_send_account_activity_alert(
            $user,
            'Your LEXSHIELD password was changed',
            'An administrator changed the password on your LEXSHIELD account. If you did not expect this, contact support immediately.'
        );
    }
}

/* -----------------------------------------------------------------------
 * File storage: profile avatars (public, non-sensitive)
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_avatar_upload_dir')) {
    function lex_avatar_upload_dir(): string
    {
        return lex_storage_ensure_dir(dirname(__DIR__) . '/public/uploads/avatars');
    }
}

if (!function_exists('lex_profile_avatar_url')) {
    function lex_profile_avatar_url(string $storedName): string
    {
        $storedName = trim($storedName);
        if ($storedName === '') {
            return '';
        }
        return lex_app_url('public/uploads/avatars/' . rawurlencode(basename($storedName)));
    }
}

if (!function_exists('lex_profile_avatar_remove')) {
    function lex_profile_avatar_remove(string $storedName): void
    {
        $storedName = basename(trim($storedName));
        if ($storedName === '') {
            return;
        }
        $path = lex_avatar_upload_dir() . '/' . $storedName;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

if (!function_exists('lex_validate_image_upload')) {
    /**
     * @param array<string, mixed> $file
     * @return array{mime:string, extension:string}
     */
    function lex_validate_image_upload(array $file, int $maxBytes = 5 * 1024 * 1024): array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No file was uploaded.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The file failed to upload.');
        }
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new RuntimeException('The file is too large.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid file upload.');
        }

        if (function_exists('lex_virus_scan_upload')) {
            lex_virus_scan_upload($file);
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
        }

        if (!isset($allowed[$mime]) || @getimagesize($tmpPath) === false) {
            throw new RuntimeException('Only JPG, PNG, GIF, or WEBP images are allowed.');
        }

        return ['mime' => $mime, 'extension' => $allowed[$mime]];
    }
}

if (!function_exists('lex_store_profile_avatar')) {
    /**
     * @param array<string, mixed> $file
     * @return array{stored_name:string, path:string}|null
     */
    function lex_store_profile_avatar(array $file): ?array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        ['extension' => $extension] = lex_validate_image_upload($file);
        $storedName = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = lex_avatar_upload_dir() . '/' . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to save the uploaded image.');
        }
        @chmod($destination, 0644);

        return ['stored_name' => $storedName, 'path' => $destination];
    }
}

/* -----------------------------------------------------------------------
 * File storage: GCash payment proofs + QR (private, access-gated)
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_payment_proof_path')) {
    function lex_payment_proof_path(string $storedName): string
    {
        return lex_storage_ensure_dir(lex_storage_path('payments/proofs')) . '/' . basename($storedName);
    }
}

if (!function_exists('lex_payment_qr_path')) {
    function lex_payment_qr_path(string $storedName): string
    {
        return lex_storage_ensure_dir(lex_storage_path('payments/qr')) . '/' . basename($storedName);
    }
}

if (!function_exists('lex_store_payment_proof')) {
    /**
     * @param array<string, mixed> $file
     * @return array{stored_name:string, original_name:string, mime_type:string, size:int, path:string}
     */
    function lex_store_payment_proof(array $file): array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Please attach a payment proof (screenshot or receipt).');
        }

        ['mime' => $mime, 'extension' => $extension] = lex_validate_image_upload($file, 8 * 1024 * 1024);
        $storedName = 'proof_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = lex_payment_proof_path($storedName);

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to save the payment proof.');
        }
        @chmod($destination, 0640);

        return [
            'stored_name' => $storedName,
            'original_name' => lex_sanitize_filename(basename((string) $file['name'])),
            'mime_type' => $mime,
            'size' => (int) filesize($destination),
            'path' => $destination,
        ];
    }
}

if (!function_exists('lex_store_payment_qr')) {
    /**
     * @param array<string, mixed> $file
     * @return array{stored_name:string, path:string}|null
     */
    function lex_store_payment_qr(array $file): ?array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        ['extension' => $extension] = lex_validate_image_upload($file, 5 * 1024 * 1024);
        $storedName = 'qr_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = lex_payment_qr_path($storedName);

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to save the QR image.');
        }
        @chmod($destination, 0640);

        return ['stored_name' => $storedName, 'path' => $destination];
    }
}

/* -----------------------------------------------------------------------
 * Page chrome: sidebar + topbar (authenticated pages) and a lighter
 * header/footer for public auth pages (login/register/reset password).
 * ---------------------------------------------------------------------*/

if (!function_exists('lex_nav_items_for_role')) {
    /**
     * @return array<int, array{key:string, label:string, href:string, icon:string}>
     */
    function lex_nav_items_for_role(string $role): array
    {
        $icon = static fn (string $d): string => '<svg class="nav-link-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="' . $d . '" fill="currentColor"/></svg>';

        $dashboardIcon = $icon('M4 4h7v7H4V4Zm9 0h7v4h-7V4Zm0 7h7v9h-7v-9ZM4 14h7v6H4v-6Z');
        $usersIcon = $icon('M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-3.3 0-9 1.6-9 5v2h18v-2c0-3.4-5.7-5-9-5Z');
        $calendarIcon = $icon('M7 2v2H5.5A2.5 2.5 0 0 0 3 6.5V19a2.5 2.5 0 0 0 2.5 2.5h13A2.5 2.5 0 0 0 21 19V6.5A2.5 2.5 0 0 0 18.5 4H17V2h-2v2H9V2H7Zm-2 8h14v9.5a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5V10Z');
        $folderIcon = $icon('M4 5.75A1.75 1.75 0 0 1 5.75 4h4.12c.5 0 .98.2 1.33.56l1.09 1.11c.07.07.17.11.27.11h5.69A1.75 1.75 0 0 1 20 7.53v10.72A1.75 1.75 0 0 1 18.25 20h-12.5A1.75 1.75 0 0 1 4 18.25V5.75Z');
        $chatIcon = $icon('M4 4h16v12H7l-3 3V4Z');
        $cashIcon = $icon('M3 6h18v12H3V6Zm9 3a3 3 0 1 0 3 3 3 3 0 0 0-3-3ZM6 9v6M18 9v6');
        $shieldIcon = $icon('M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5Z');
        $gearIcon = $icon('M12 8.5A3.5 3.5 0 1 0 15.5 12 3.5 3.5 0 0 0 12 8.5Zm8.4 2.2-1.8-.4a6.9 6.9 0 0 0-.6-1.5l1-1.6a1 1 0 0 0-.1-1.2l-1.5-1.5a1 1 0 0 0-1.2-.1l-1.6 1a6.9 6.9 0 0 0-1.5-.6l-.4-1.8a1 1 0 0 0-1-.8h-2a1 1 0 0 0-1 .8l-.4 1.8a6.9 6.9 0 0 0-1.5.6l-1.6-1a1 1 0 0 0-1.2.1L3.5 6a1 1 0 0 0-.1 1.2l1 1.6a6.9 6.9 0 0 0-.6 1.5l-1.8.4a1 1 0 0 0-.8 1v2a1 1 0 0 0 .8 1l1.8.4a6.9 6.9 0 0 0 .6 1.5l-1 1.6a1 1 0 0 0 .1 1.2l1.5 1.5a1 1 0 0 0 1.2.1l1.6-1a6.9 6.9 0 0 0 1.5.6l.4 1.8a1 1 0 0 0 1 .8h2a1 1 0 0 0 1-.8l.4-1.8a6.9 6.9 0 0 0 1.5-.6l1.6 1a1 1 0 0 0 1.2-.1l1.5-1.5a1 1 0 0 0 .1-1.2l-1-1.6a6.9 6.9 0 0 0 .6-1.5l1.8-.4a1 1 0 0 0 .8-1v-2a1 1 0 0 0-.8-1Z');
        $auditIcon = $icon('M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 9H8v2h6v-2Zm2 4H8v2h8v-2Z');
        $inboxIcon = $icon('M4 4h16v9l-3 3H8l-3-3H4V4Zm3 12v2h10v-2H7Z');
        $shareIcon = $icon('M18 8a3 3 0 1 0-2.83-4H15.1a3 3 0 0 0 0 2l-6.2 3.62a3 3 0 1 0 0 4.76l6.2 3.62a3 3 0 1 0 1-1.72l-6.2-3.62a3 3 0 0 0 0-1.32l6.2-3.62c.51.32 1.11.5 1.75.5Z');
        $chainIcon = $icon('M10.5 13.5 13.5 10.5M8.5 15.5 6 18a3 3 0 0 1-4.24-4.24l3-3a3 3 0 0 1 4.1-.15M15.5 8.5 18 6a3 3 0 1 1 4.24 4.24l-3 3a3 3 0 0 1-4.1.15');

        $items = match ($role) {
            'admin' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'go.php', 'icon' => $dashboardIcon],
                ['key' => 'lawyers', 'label' => 'Manage Lawyers', 'href' => 'admin/manage_lawyers.php', 'icon' => $usersIcon],
                ['key' => 'clients', 'label' => 'Manage Clients', 'href' => 'admin/manage_clients.php', 'icon' => $usersIcon],
                ['key' => 'payments', 'label' => 'Payments', 'href' => 'admin/payments.php', 'icon' => $cashIcon],
                ['key' => 'inquiries', 'label' => 'Quick Inquiries', 'href' => 'admin/inquiries.php', 'icon' => $inboxIcon],
                ['key' => 'messages', 'label' => 'Messages', 'href' => 'chat.php', 'icon' => $chatIcon],
                ['key' => 'data-sharing', 'label' => 'Data Sharing', 'href' => 'admin/data_sharing.php', 'icon' => $shareIcon],
                ['key' => 'blockchain', 'label' => 'Blockchain Ledger', 'href' => 'admin/blockchain_ledger.php', 'icon' => $chainIcon],
                ['key' => 'phishing', 'label' => 'Phishing Scans', 'href' => 'admin/phishing_scans.php', 'icon' => $shieldIcon],
                ['key' => 'audit', 'label' => 'Audit Logs', 'href' => 'admin/audit_logs.php', 'icon' => $auditIcon],
                ['key' => 'settings', 'label' => 'System Settings', 'href' => 'admin/system_settings.php', 'icon' => $gearIcon],
            ],
            'lawyer' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'go.php', 'icon' => $dashboardIcon],
                ['key' => 'appointments', 'label' => 'Appointments', 'href' => 'lawyer/appointment.php', 'icon' => $calendarIcon],
                ['key' => 'case-files', 'label' => 'Case Files', 'href' => 'case_files.php', 'icon' => $folderIcon],
                ['key' => 'messages', 'label' => 'Messages', 'href' => 'chat.php', 'icon' => $chatIcon],
                ['key' => 'schedule', 'label' => 'My Schedule', 'href' => 'lawyer/schedule.php', 'icon' => $calendarIcon],
                ['key' => 'data-sharing', 'label' => 'Data Sharing', 'href' => 'lawyer/data_sharing.php', 'icon' => $shareIcon],
                ['key' => 'profile', 'label' => 'Profile', 'href' => 'lawyer/profile.php', 'icon' => $gearIcon],
            ],
            'client' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'go.php', 'icon' => $dashboardIcon],
                ['key' => 'appointments', 'label' => 'Appointments', 'href' => 'client/appointment.php', 'icon' => $calendarIcon],
                ['key' => 'lawyers', 'label' => 'Find a Lawyer', 'href' => 'client/lawyers.php', 'icon' => $usersIcon],
                ['key' => 'messages', 'label' => 'Messages', 'href' => 'chat.php', 'icon' => $chatIcon],
                ['key' => 'billing', 'label' => 'Billing', 'href' => 'client/billing.php', 'icon' => $cashIcon],
                ['key' => 'payments', 'label' => 'Payments', 'href' => 'client/payments.php', 'icon' => $cashIcon],
                ['key' => 'profile', 'label' => 'Profile', 'href' => 'client/profile.php', 'icon' => $gearIcon],
            ],
            default => [],
        };

        return $items;
    }
}

if (!function_exists('lex_page_header')) {
    function lex_page_header(string $title, string $activeNav = '', ?array $user = null): void
    {
        $user = $user ?? lex_current_user();
        $role = (string) ($user['role'] ?? '');
        $siteName = lex_site_setting('site_name', 'LEXSHIELD');
        $navItems = lex_nav_items_for_role($role);
        $fullName = (string) ($user['full_name'] ?? 'User');
        $lexNotifUserId = (int) ($user['id'] ?? 0);
        if (function_exists('lex_nav_mark_active_read')) {
            lex_nav_mark_active_read($lexNotifUserId, $activeNav);
        }
        $navBadges = function_exists('lex_nav_badge_counts')
            ? lex_nav_badge_counts($lexNotifUserId, $role)
            : [];
        $navHrefsByKey = [];
        $notifHrefsByType = [];
        foreach ($navItems as $navItem) {
            $itemKey = (string) ($navItem['key'] ?? '');
            $itemHref = function_exists('lex_nav_href') ? lex_nav_href((string) $navItem['href']) : lex_app_url((string) $navItem['href']);
            $navHrefsByKey[$itemKey] = $itemHref;
        }
        if (function_exists('lex_nav_badge_type_map')) {
            foreach (lex_nav_badge_type_map() as $typeKey => $types) {
                $targetHref = (string) ($navHrefsByKey[$typeKey] ?? '');
                if ($targetHref === '' && $typeKey === 'billing') {
                    $targetHref = (string) ($navHrefsByKey['payments'] ?? '');
                }
                if ($targetHref === '') {
                    $targetHref = (string) ($navHrefsByKey['dashboard'] ?? '');
                }
                foreach ($types as $typeName) {
                    $mappedKey = function_exists('lex_nav_key_for_type') ? lex_nav_key_for_type($typeName, $role) : $typeKey;
                    $mappedHref = (string) ($navHrefsByKey[$mappedKey] ?? $targetHref);
                    $notifHrefsByType[$typeName] = $mappedHref !== '' ? $mappedHref : $targetHref;
                }
            }
        }
        ?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, shrink-to-fit=no">
<meta name="theme-color" content="#070b14">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= lex_e($title) ?> · <?= lex_e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= lex_e(lex_asset_url('public/css/style.css')) ?>">
<link rel="manifest" href="<?= lex_e(lex_app_url('public/manifest.json')) ?>">
</head>
<body class="app-workspace" data-role="<?= lex_e($role) ?>" data-api-base="<?= lex_e(lex_env('API_BASE_URL', '') ?? '') ?>" data-call-ring-url="<?= lex_e(lex_app_url('chat_call_ring.php')) ?>">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand-block">
      <div class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5Z" fill="#c9a84c"/></svg></div>
      <div>
        <strong class="brand-wordmark"><span><?= lex_e($siteName) ?></span></strong>
        <span>Legal case management</span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($navItems as $item): ?>
        <?php
          $navHref = function_exists('lex_nav_href') ? lex_nav_href($item['href']) : lex_app_url($item['href']);
          $navKey = (string) ($item['key'] ?? '');
          $navBadge = (int) ($navBadges[$navKey] ?? 0);
          $navBadgeLabel = $navBadge > 99 ? '99+' : (string) $navBadge;
          $navPopup = function_exists('lex_nav_badge_words') ? lex_nav_badge_words($navKey, $navBadge) : '';
          $navBadgeStyle = $navBadge > 0
              ? 'display:inline-flex;align-items:center;justify-content:center;margin-left:auto;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#e11d48;color:#fff;font-size:11px;font-weight:800;line-height:20px;flex:0 0 auto;'
              : 'display:none;';
        ?>
        <a class="nav-link<?= $item['key'] === $activeNav ? ' active' : '' ?>" href="<?= lex_e($navHref) ?>"<?= $navPopup !== '' ? ' title="' . lex_e($navPopup) . '"' : '' ?><?= $navPopup !== '' ? ' aria-label="' . lex_e((string) $item['label'] . ', ' . $navPopup) . '"' : '' ?>>
          <?= $item['icon'] ?>
          <span class="nav-link-label" data-nav-label="<?= lex_e((string) $item['label']) ?>"><?= lex_e((string) $item['label']) ?></span>
          <span class="nav-badge<?= $navBadge > 0 ? '' : ' is-empty' ?>" data-nav-badge="<?= lex_e($navKey) ?>" style="<?= lex_e($navBadgeStyle) ?>"><?= lex_e($navBadgeLabel) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <form method="post" action="<?= lex_e(function_exists('lex_nav_href') ? lex_nav_href('auth/logout.php') : lex_app_url('auth/logout.php')) ?>">
        <button class="button button-secondary" type="submit" style="width:100%;">Sign out</button>
      </form>
    </div>
  </aside>
  <main class="main-content" id="main-content">
    <header class="topbar">
      <button class="icon-button" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-expanded="false">&#9776;</button>
      <div class="topbar-title"><h1><?= lex_e($title) ?></h1></div>
      <div class="topbar-actions">
        <button class="icon-button phishing-detector-trigger" id="topbarPhishingBtn" type="button" aria-label="Check a link for phishing" title="Check a link for phishing" style="position:relative;z-index:6;cursor:pointer;pointer-events:auto;touch-action:manipulation;width:44px;min-width:44px;height:44px;min-height:44px;flex:0 0 44px;" onclick="<?= lex_e(function_exists('lex_phishing_open_onclick') ? lex_phishing_open_onclick() : 'return false;') ?>">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5Z" fill="currentColor"/></svg>
        </button>
        <button class="icon-button" id="themeToggle" type="button" aria-label="Switch to light mode" title="Switch to light mode">&#9681;</button>
        <?php
        $lexNotifUserId = (int) ($user['id'] ?? 0);
        $lexNotifCount = function_exists('lex_notifications_unread_count') ? lex_notifications_unread_count($lexNotifUserId) : 0;
        $lexNotifRows = function_exists('lex_notifications_recent') ? lex_notifications_recent($lexNotifUserId, 20) : [];
        ?>
        <div class="notif-bell-wrap" id="notifBellWrap">
          <button class="icon-button notif-bell-btn" id="notifBellBtn" type="button" aria-label="Notifications" title="Notifications" aria-haspopup="true" aria-expanded="false">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 2a7 7 0 0 1 7 7v3.17l1.53 3.06A1 1 0 0 1 19.64 17H4.36a1 1 0 0 1-.89-1.77L5 12.17V9a7 7 0 0 1 7-7Zm0 19a3 3 0 0 1-2.83-2h5.66A3 3 0 0 1 12 21Z" fill="currentColor"/></svg>
            <span class="notif-bell-badge"<?= $lexNotifCount > 0 ? '' : ' style="display:none"' ?>><?= $lexNotifCount > 99 ? '99+' : (string) $lexNotifCount ?></span>
          </button>
          <div class="notif-bell-dropdown" id="notifBellDropdown" hidden>
            <div class="notif-bell-header"><strong>Notifications</strong><button class="notif-bell-mark-read" id="notifMarkAllRead" type="button">Mark all read</button></div>
            <div class="notif-bell-list" id="notifBellList">
              <?php if (!$lexNotifRows): ?>
                <p class="muted" style="padding:0.75rem;text-align:center;">No notifications yet.</p>
              <?php else: ?>
                <?php foreach ($lexNotifRows as $lexNotifRow): ?>
                  <?php $lexNotifType = (string) ($lexNotifRow['type'] ?? ''); ?>
                  <div class="notif-bell-item<?= (int) ($lexNotifRow['is_read'] ?? 0) === 0 ? ' is-unread' : '' ?>" data-notif-id="<?= (int) $lexNotifRow['id'] ?>" data-notif-type="<?= lex_e($lexNotifType) ?>" data-notif-href="<?= lex_e((string) ($notifHrefsByType[$lexNotifType] ?? ($navHrefsByKey['dashboard'] ?? ''))) ?>">
                    <span class="notif-bell-item-dot"></span>
                    <div>
                      <div><?= lex_e((string) ($lexNotifRow['message'] ?? '')) ?></div>
                      <div class="notif-bell-item-time"><?= lex_e((string) ($lexNotifRow['created_at'] ?? '')) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="user-chip">
          <strong><?= lex_e($fullName) ?></strong>
          <span><?= lex_e(ucfirst($role)) ?></span>
        </div>
      </div>
    </header>
    <?php foreach (lex_flash_consume() as $flash): ?>
      <?php
        $flashClass = match ((string) ($flash['type'] ?? '')) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'success',
        };
      ?>
      <div class="alert alert-<?= $flashClass ?>"><?= lex_e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php
    }
}

if (!function_exists('lex_page_footer')) {
    function lex_page_footer(): void
    {
        $ringUser = function_exists('lex_current_user') ? lex_current_user() : null;
        $ringUserId = is_array($ringUser) ? (int) ($ringUser['id'] ?? 0) : 0;
        $incomingCall = null;
        if ($ringUserId > 0 && function_exists('lex_inbox_call_incoming_for')) {
            try {
                $incomingCall = lex_inbox_call_incoming_for($ringUserId);
            } catch (Throwable $e) {
                $incomingCall = null;
            }
        }
        ?>
  </main>
</div>
<?php if (function_exists('lex_phishing_modal_markup')) { lex_phishing_modal_markup(); } ?>
<?php if ($ringUserId > 0): ?>
<?php if (function_exists('lex_inbox_call_overlay_markup')) { lex_inbox_call_overlay_markup($incomingCall); } ?>
<script type="application/json" id="lex-call-ring-data"><?= json_encode([
    'ringEndpoint' => lex_app_url('chat_call_ring.php'),
    'csrfToken' => lex_csrf_token(),
    'incoming' => $incomingCall,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<script>
window.lexOpenPhishingDetector = function (event) {
  <?= function_exists('lex_phishing_open_onclick') ? lex_phishing_open_onclick() : 'return false;' ?>
};
window.lexClosePhishingDetector = function (event) {
  <?= function_exists('lex_phishing_close_onclick') ? lex_phishing_close_onclick() : 'return false;' ?>
};
</script>
<script src="<?= lex_e(lex_asset_url('public/js/base.js')) ?>"></script>
<script src="<?= lex_e(lex_asset_url('public/js/chat.js')) ?>"></script>
<script src="<?= lex_e(lex_asset_url('public/js/main.js')) ?>"></script>
<script>
(function () {
  window.lexOpenPhishingDetector = function (event) {
    <?= function_exists('lex_phishing_open_onclick') ? lex_phishing_open_onclick() : 'return false;' ?>
  };
  window.lexClosePhishingDetector = function (event) {
    <?= function_exists('lex_phishing_close_onclick') ? lex_phishing_close_onclick() : 'return false;' ?>
  };
  document.querySelectorAll('.phishing-detector-trigger, #topbarPhishingBtn').forEach(function (button) {
    if (button.dataset.phishingBound === '1') return;
    button.dataset.phishingBound = '1';
    button.style.pointerEvents = 'auto';
    button.style.cursor = 'pointer';
    button.addEventListener('click', window.lexOpenPhishingDetector);
  });
})();
</script>
<?php if ($ringUserId > 0): ?>
<script src="<?= lex_e(lex_asset_url('public/js/call-ring.js')) ?>"></script>
<script>
(function () {
  if (window.lexCallRingStarted) return;
  if (document.querySelector('[data-video-call-page]')) return;
  var dataNode = document.getElementById('lex-call-ring-data');
  var overlay = document.getElementById('lexCallRingOverlay');
  if (!dataNode || !overlay) return;
  var config = {};
  try { config = JSON.parse(dataNode.textContent || '{}'); } catch (e) { return; }
  var endpoint = config.ringEndpoint || document.body.getAttribute('data-call-ring-url') || '';
  if (!endpoint) return;
  window.lexCallRingStarted = true;
  var currentPeer = 0;
  var originalTitle = document.title;
  var titleTimer = null;
  function fill(incoming) {
    var name = (incoming && incoming.peerName) || 'Someone';
    var href = (incoming && incoming.callHref) || '#';
    overlay.querySelectorAll('[data-call-ring-name]').forEach(function (n) { n.textContent = name; });
    overlay.querySelectorAll('[data-call-ring-role]').forEach(function (n) { n.textContent = incoming && incoming.peerRole ? incoming.peerRole : ''; });
    overlay.querySelectorAll('[data-call-ring-avatar]').forEach(function (n) { n.textContent = (incoming && incoming.peerInitial) || name.slice(0, 1).toUpperCase(); });
    overlay.querySelectorAll('[data-call-ring-accept]').forEach(function (n) { n.setAttribute('href', href); });
    var toast = document.getElementById('lexCallRingToast');
    if (toast) {
      var t = toast.querySelector('[data-call-ring-toast-text]');
      if (t) t.textContent = name + ' is calling you';
      var a = toast.querySelector('[data-call-ring-toast-accept]');
      if (a) a.setAttribute('href', href);
      toast.hidden = false;
    }
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    if (!titleTimer) {
      titleTimer = setInterval(function () {
        document.title = document.title === originalTitle ? 'Incoming call — ' + name : originalTitle;
      }, 900);
    }
    if (typeof window.showNotification === 'function' && currentPeer !== Number(incoming.peerUserId || 0)) {
      window.showNotification('info', name + ' is calling you');
    }
    currentPeer = Number(incoming.peerUserId || 0);
  }
  function hide() {
    currentPeer = 0;
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    var toast = document.getElementById('lexCallRingToast');
    if (toast) toast.hidden = true;
    if (titleTimer) { clearInterval(titleTimer); titleTimer = null; document.title = originalTitle; }
  }
  overlay.querySelectorAll('[data-call-ring-decline]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var peerId = currentPeer;
      hide();
      if (!peerId) return;
      fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ action: 'decline', peer_user_id: peerId, csrf_token: config.csrfToken })
      }).catch(function () {});
    });
  });
  function poll() {
    fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || data.ok !== true) return;
        if (data.incoming && data.incoming.peerUserId) fill(data.incoming);
        else if (currentPeer) hide();
      })
      .catch(function () {});
  }
  if (config.incoming && config.incoming.peerUserId) fill(config.incoming);
  poll();
  setInterval(poll, 2000);
})();
</script>
<?php endif; ?>
<script>
(function () {
  var wrap = document.getElementById('notifBellWrap');
  var bellBtn = document.getElementById('notifBellBtn');
  var dropdown = document.getElementById('notifBellDropdown');
  var listEl = document.getElementById('notifBellList');
  var markAllBtn = document.getElementById('notifMarkAllRead');
  var badge = bellBtn ? bellBtn.querySelector('.notif-bell-badge') : null;
  var notifUrl = <?= json_encode(lex_app_url('notifications_api.php')) ?>;
  var csrf = <?= json_encode(lex_csrf_token()) ?>;
  var notifHrefs = <?= json_encode($notifHrefsByType, JSON_UNESCAPED_SLASHES) ?>;
  if (!bellBtn || !dropdown || !listEl) return;
  var open = false;
  function timeAgo(d) {
    var s = Math.floor((Date.now() - new Date(d + (d.indexOf('Z') < 0 && d.indexOf('+') < 0 ? 'Z' : '')).getTime()) / 1000);
    if (s < 60) return 'Just now';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
  }
  function render(data) {
    if (!data.notifications || !data.notifications.length) {
      listEl.innerHTML = '<p class="muted" style="padding:0.75rem;text-align:center;">No notifications yet.</p>';
    } else {
      listEl.innerHTML = data.notifications.map(function (n) {
        var href = (notifHrefs && n.type && notifHrefs[n.type]) ? notifHrefs[n.type] : '';
        return '<div class="notif-bell-item' + (Number(n.is_read) === 0 ? ' is-unread' : '') + '" data-notif-id="' + n.id + '" data-notif-type="' + String(n.type || '').replace(/"/g, '') + '" data-notif-href="' + String(href).replace(/"/g, '') + '">'
          + '<span class="notif-bell-item-dot"></span>'
          + '<div><div>' + (n.message || '').replace(/</g, '&lt;') + '</div><div class="notif-bell-item-time">' + timeAgo(n.created_at) + '</div></div></div>';
      }).join('');
    }
    if (badge) {
      if (data.unread > 0) {
        badge.textContent = data.unread > 99 ? '99+' : String(data.unread);
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
    }
    var navCounts = data.nav_badges && typeof data.nav_badges === 'object' ? data.nav_badges : null;
    if (!navCounts) return;
    document.querySelectorAll('[data-nav-badge]').forEach(function (el) {
      var key = el.getAttribute('data-nav-badge') || '';
      if (!Object.prototype.hasOwnProperty.call(navCounts, key)) return;
      var n = Number(navCounts[key]) || 0;
      var label = key === 'appointments'
        ? (n === 1 ? '1 appointment' : n + ' appointments')
        : (n === 1 ? '1 update' : n + ' updates');
      var link = el.closest('.nav-link');
      var labelEl = link ? link.querySelector('[data-nav-label]') : null;
      var baseLabel = labelEl ? (labelEl.getAttribute('data-nav-label') || '').trim() : '';
      var shown = n > 99 ? '99+' : String(n);
      if (labelEl && baseLabel) {
        labelEl.textContent = baseLabel;
      }
      if (n > 0) {
        el.textContent = shown;
        el.classList.remove('is-empty');
        el.style.display = 'inline-flex';
        el.style.alignItems = 'center';
        el.style.justifyContent = 'center';
        el.style.marginLeft = 'auto';
        el.style.minWidth = '20px';
        el.style.height = '20px';
        el.style.padding = '0 6px';
        el.style.borderRadius = '999px';
        el.style.background = '#e11d48';
        el.style.color = '#fff';
        el.style.fontSize = '11px';
        el.style.fontWeight = '800';
        el.style.lineHeight = '20px';
        el.style.flex = '0 0 auto';
        if (link) {
          var name = ((link.querySelector('.nav-link-label') || {}).textContent || key).trim();
          link.setAttribute('title', label);
          link.setAttribute('aria-label', name + ', ' + label);
        }
      } else {
        el.textContent = '0';
        el.classList.add('is-empty');
        el.style.display = 'none';
        if (link) {
          link.removeAttribute('title');
          link.removeAttribute('aria-label');
        }
      }
    });
  }
  function load() {
    fetch(notifUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.ok) render(d); })
      .catch(function () {});
  }
  function setOpen(next) {
    open = next;
    dropdown.hidden = !open;
    bellBtn.setAttribute('aria-expanded', String(open));
    if (wrap) wrap.classList.toggle('is-open', open);
    if (open) load();
  }
  bellBtn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    setOpen(!open);
  });
  document.addEventListener('click', function (e) {
    if (!open || !wrap) return;
    if (wrap.contains(e.target)) return;
    setOpen(false);
  });
  document.addEventListener('keydown', function (e) {
    if (open && e.key === 'Escape') setOpen(false);
  });
  listEl.addEventListener('click', function (e) {
    var item = e.target.closest('[data-notif-id]');
    if (!item) return;
    var id = item.getAttribute('data-notif-id');
    var href = item.getAttribute('data-notif-href') || '';
    item.classList.remove('is-unread');
    fetch(notifUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_read', id: Number(id), csrf_token: csrf }) }).catch(function () {});
    if (href) {
      window.location.href = href;
      return;
    }
    load();
  });
  if (markAllBtn) {
    markAllBtn.addEventListener('click', function () {
      fetch(notifUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_all_read', csrf_token: csrf }) }).then(function () { load(); }).catch(function () {});
    });
  }
  setInterval(function () { if (!open) load(); }, 15000);
  load();
})();
</script>
</body>
</html>
        <?php
    }
}

if (!function_exists('lex_auth_page_header')) {
    function lex_auth_page_header(string $title): void
    {
        $siteName = lex_site_setting('site_name', 'LEXSHIELD');
        ?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0a1628">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= lex_e($title) ?> · <?= lex_e($siteName) ?></title>
<link rel="stylesheet" href="<?= lex_e(lex_asset_url('public/css/style.css')) ?>">
</head>
<body class="auth-page">
<a class="skip-link" href="#main-content">Skip to content</a>
<main id="main-content">
<?php foreach (lex_flash_consume() as $flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= lex_e($flash['message']) ?></div>
<?php endforeach; ?>
        <?php
    }
}

if (!function_exists('lex_auth_page_footer')) {
    function lex_auth_page_footer(): void
    {
        ?>
</main>
<script src="<?= lex_e(lex_asset_url('public/js/base.js')) ?>"></script>
</body>
</html>
        <?php
    }
}

if (!function_exists('lex_pao_public_name')) {
    function lex_pao_public_name(): string
    {
        return 'PAO Iloilo';
    }
}

if (!function_exists('lex_pao_public_title')) {
    function lex_pao_public_title(): string
    {
        return 'Reservation System';
    }
}

if (!function_exists('lex_pao_nav_items')) {
    /**
     * @return array<int, array{id:string, label:string, href:string}>
     */
    function lex_pao_nav_items(): array
    {
        return [
            ['id' => 'home', 'label' => 'Home', 'href' => lex_app_url('#home')],
            ['id' => 'attorneys', 'label' => 'Lawyers', 'href' => lex_app_url('#attorneys')],
            ['id' => 'services', 'label' => 'Services', 'href' => lex_app_url('#services')],
            ['id' => 'about', 'label' => 'About', 'href' => lex_app_url('#about')],
            ['id' => 'contact', 'label' => 'Contact', 'href' => lex_app_url('#contact')],
        ];
    }
}

if (!function_exists('lex_pao_logo_url')) {
    function lex_pao_logo_url(): string
    {
        $logo = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'lexshield-logo.png';
        return is_file($logo) ? lex_asset_url('public/assets/lexshield-logo.png') : '';
    }
}

if (!function_exists('lex_pao_hero_url')) {
    function lex_pao_hero_url(): string
    {
        $justice = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'lady-justice.png';
        if (is_file($justice)) {
            return lex_asset_url('public/assets/lady-justice.png');
        }
        return lex_asset_url('public/assets/lexshield-auth-visual.png');
    }
}

if (!function_exists('lex_pao_star_rating')) {
    function lex_pao_star_rating(?float $rating, int $reviews = 0): string
    {
        $score = $rating === null ? 0.0 : max(0.0, min(5.0, $rating));
        $aria = $score > 0.0
            ? number_format($score, 1) . ' out of 5 stars'
            : 'Not yet rated';
        $path = 'M12 2.15 14.7 8.4l6.8.6-5.2 4.5 1.6 6.7L12 16.7 6.1 20.2l1.6-6.7-5.2-4.5 6.8-.6L12 2.15Z';
        $html = '<div class="pao-rating"><span class="pao-stars" role="img" aria-label="' . lex_e($aria) . '">';
        for ($i = 1; $i <= 5; $i++) {
            $fill = max(0.0, min(1.0, $score - ($i - 1)));
            $pct = (int) round($fill * 100);
            $html .= '<span class="pao-star">';
            $html .= '<svg class="pao-star-bg" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $path . '"/></svg>';
            if ($pct > 0) {
                $html .= '<span class="pao-star-fill" style="width:' . $pct . '%"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $path . '"/></svg></span>';
            }
            $html .= '</span>';
        }
        $html .= '</span>';
        if ($score > 0.0) {
            $meta = number_format($score, 1);
            if ($reviews > 0) {
                $meta .= ' · ' . $reviews . ($reviews === 1 ? ' review' : ' reviews');
            }
            $html .= '<span class="pao-rating-meta">' . lex_e($meta) . '</span>';
        } else {
            $html .= '<span class="pao-rating-meta">Public attorney</span>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('lex_pao_page_header')) {
    function lex_pao_page_header(string $title, string $active = 'home', string $bodyClass = 'pao-home'): void
    {
        $siteName = lex_pao_public_title();
        $shortName = lex_pao_public_name();
        $logoUrl = lex_pao_logo_url();
        $heroUrl = lex_pao_hero_url();
        $headerUser = function_exists('lex_current_user') ? lex_current_user() : null;
        $accountHref = $headerUser ? lex_app_url('go.php') : lex_app_url('auth/login.php');
        $accountLabel = $headerUser ? 'Dashboard' : 'Log In';
        $registerHref = lex_app_url('auth/register.php');
        ?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover, shrink-to-fit=no">
<meta name="theme-color" content="#070b14">
<title><?= lex_e($title) ?> · <?= lex_e($shortName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= lex_e(lex_asset_url('public/css/style.css')) ?>">
</head>
<body class="pao-site <?= lex_e($bodyClass) ?>" style="--pao-hero-image: url('<?= lex_e($heroUrl) ?>')">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="pao-page">
  <div class="pao-govbar">Republic of the Philippines · Department of Justice · Public Attorney's Office — Iloilo</div>
  <header class="pao-header">
    <a class="pao-brand" href="<?= lex_e(lex_app_url('#home')) ?>">
      <span class="pao-logo-mark" aria-hidden="true">
        <?php if ($logoUrl !== ''): ?>
          <img src="<?= lex_e($logoUrl) ?>" alt="Public Attorney's Office">
        <?php else: ?>
          <svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="30" fill="#d4af37"/><path d="M32 14 18 20v10c0 10 6.2 16.8 14 21 7.8-4.2 14-11 14-21V20Z" fill="#0a192f"/></svg>
        <?php endif; ?>
      </span>
      <span class="pao-brand-copy">
        <strong><?= lex_e($shortName) ?></strong>
        <span><?= lex_e($siteName) ?></span>
      </span>
    </a>
    <nav class="pao-nav" aria-label="Primary">
      <?php foreach (lex_pao_nav_items() as $item): ?>
        <a class="<?= $item['id'] === $active ? 'is-active' : '' ?>" href="<?= lex_e($item['href']) ?>"><?= lex_e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="pao-header-actions">
      <a class="pao-login-btn<?= $active === 'login' && !$headerUser ? ' is-current' : '' ?>" href="<?= lex_e($accountHref) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-3.4 0-8 1.6-8 4.8V21h16v-2.2c0-3.2-4.6-4.8-8-4.8Z" fill="currentColor"/></svg>
        <?= lex_e($accountLabel) ?>
      </a>
      <?php if (!$headerUser): ?>
        <a class="pao-register-btn" href="<?= lex_e($registerHref) ?>">Register</a>
      <?php endif; ?>
    </div>
  </header>
  <main id="main-content">
        <?php
    }
}

if (!function_exists('lex_pao_page_footer')) {
    function lex_pao_page_footer(bool $withHomeScript = false): void
    {
        $year = date('Y');
        ?>
  </main>
  <footer class="pao-footer">
    <div class="pao-contact-bar">
      <div>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 14.5 9 2.5 2.5 0 0 1 12 11.5Z" fill="currentColor"/></svg>
        <div>
          <strong>Visit Us</strong>
          <span>Hall of Justice, Bonifacio Drive, Iloilo City · Iloilo City Hall, Plaza Libertad, Iloilo City, Philippines 5000</span>
        </div>
      </div>
      <div>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.4 11.4 0 0 0 .57 3.6 1 1 0 0 1-.25 1Z" fill="currentColor"/></svg>
        <div>
          <strong>Call Us</strong>
          <span>(033) 333-2618 · (033) 508-6989</span>
        </div>
      </div>
      <div>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5L4 8V6l8 5 8-5Z" fill="currentColor"/></svg>
        <div>
          <strong>Email Us</strong>
          <span>paoregion6@yahoo.com</span>
        </div>
      </div>
      <div>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 11h4v2h-6V7h2Z" fill="currentColor"/></svg>
        <div>
          <strong>Office Hours</strong>
          <span>Monday – Friday 8:00 AM – 5:00 PM</span>
        </div>
      </div>
      <div class="pao-social">
        <strong>Follow Us</strong>
        <div>
          <a href="https://www.facebook.com" target="_blank" rel="noopener" aria-label="Facebook">f</a>
          <a href="https://x.com" target="_blank" rel="noopener" aria-label="X">x</a>
          <a href="https://www.youtube.com" target="_blank" rel="noopener" aria-label="YouTube">▶</a>
        </div>
      </div>
    </div>
    <div class="pao-legal">© <?= lex_e($year) ?> Public Attorney's Office — Iloilo District. All rights reserved.</div>
  </footer>
</div>
<script src="<?= lex_e(lex_asset_url('public/js/base.js')) ?>"></script>
</body>
</html>
        <?php
    }
}
