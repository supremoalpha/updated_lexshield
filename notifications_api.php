<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = lex_require_login();
$userId = (int) $user['id'];
$pdo = lex_pdo();
if (function_exists('lex_notifications_table_ensure')) {
    lex_notifications_table_ensure();
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $limit = min(30, max(5, lex_sanitize_int($_GET['limit'] ?? 20)));
    $rows = function_exists('lex_notifications_recent')
        ? lex_notifications_recent($userId, $limit)
        : [];
    $unread = function_exists('lex_notifications_unread_count')
        ? lex_notifications_unread_count($userId)
        : 0;

    echo json_encode(['ok' => true, 'notifications' => $rows, 'unread' => $unread], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body) || !lex_csrf_validate($body['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Session expired.']);
        exit;
    }

    $action = (string) ($body['action'] ?? '');
    if ($action === 'mark_read') {
        $notifId = lex_sanitize_int($body['id'] ?? 0);
        if ($notifId > 0) {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid')
                ->execute(['id' => $notifId, 'uid' => $userId]);
        }
    } elseif ($action === 'mark_all_read') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0')
            ->execute(['uid' => $userId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false]);
