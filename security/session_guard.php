<?php
declare(strict_types=1);

global $lexSessionTimeout;

function lex_start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    if (!isset($_SESSION['client_ip'])) {
        $_SESSION['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    if (($_SESSION['client_ip'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        lex_logout_session();
    }

    $timeout = $GLOBALS['lexSessionTimeout'] ?? 1800;
    if (!empty($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $timeout) {
        lex_logout_session();
    }
    $_SESSION['last_activity'] = time();
}

function lex_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $_SESSION['last_activity'] = time();
    unset($_SESSION['lex_messages_unlocked_user'], $_SESSION['lex_messages_unlocked_at']);
}

function lex_logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function lex_record_login_attempt(PDO $pdo, int $userId, bool $success): void
{
    if ($success) {
        $stmt = $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE users SET failed_login_attempts = failed_login_attempts + 1, locked_until = CASE WHEN failed_login_attempts + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE) ELSE locked_until END WHERE id = :id');
    $stmt->execute(['id' => $userId]);
}

