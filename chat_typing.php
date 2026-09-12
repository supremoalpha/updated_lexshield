<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/messages/core.php';

header('Content-Type: application/json; charset=utf-8');

$user = lex_require_login();
$userId = (int) $user['id'];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$pdo = lex_pdo();

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `message_typing` (
            `user_id` INT NOT NULL,
            `peer_id` INT NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `peer_id`)
        ) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `message_typing` (
                `user_id` INT NOT NULL,
                `peer_id` INT NOT NULL,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `peer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e2) {}
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $peerId = lex_sanitize_int($_GET['with'] ?? 0);
    if ($peerId <= 0) {
        echo json_encode(['ok' => true, 'typing' => false]);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM message_typing WHERE user_id = :peer AND peer_id = :me AND updated_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND) LIMIT 1'
    );
    $stmt->execute(['peer' => $peerId, 'me' => $userId]);
    echo json_encode(['ok' => true, 'typing' => (bool) $stmt->fetchColumn()]);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $peerId = lex_sanitize_int($body['with'] ?? 0);
    $typing = !empty($body['typing']);
    if ($peerId <= 0) {
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($typing) {
        try {
            $pdo->prepare(
                'INSERT INTO message_typing (user_id, peer_id, updated_at) VALUES (:me, :peer, NOW())
                 ON DUPLICATE KEY UPDATE updated_at = NOW()'
            )->execute(['me' => $userId, 'peer' => $peerId]);
        } catch (Throwable $e) {}
    } else {
        $pdo->prepare('DELETE FROM message_typing WHERE user_id = :me AND peer_id = :peer')
            ->execute(['me' => $userId, 'peer' => $peerId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false]);
