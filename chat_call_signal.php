<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/messages/core.php';
require_once __DIR__ . '/config/messages/lock.php';
require_once __DIR__ . '/config/messages/shared.php';
require_once __DIR__ . '/config/messages/calls.php';

header('Content-Type: application/json; charset=utf-8');

function lex_inbox_signal_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = lex_require_login();
$userId = (int) $user['id'];
$pdo = lex_pdo();
lex_inbox_call_tables_ensure();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = [];
if ($method === 'GET') {
    $peerId = lex_sanitize_int($_GET['peer_user_id'] ?? $_GET['with'] ?? 0);
    $sinceId = lex_sanitize_int($_GET['since_id'] ?? 0);
} else {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : [];
    $peerId = lex_sanitize_int($body['peer_user_id'] ?? $body['with'] ?? 0);
    if (!lex_csrf_validate($body['csrf_token'] ?? null)) {
        lex_inbox_signal_fail(403, 'Your session expired. Reload and try again.');
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$allowed = lex_messages_allowed_recipients($user);
$peer = lex_messages_can_contact($allowed, $peerId);
if (
    !$peer
    || $peerId === $userId
    || !lex_messages_roles_may_chat((string) ($user['role'] ?? ''), (string) ($peer['role'] ?? ''))
) {
    lex_inbox_signal_fail(404, 'This call is not available.');
}

$session = lex_inbox_call_find($userId, $peerId) ?? [];
$sessionId = (int) ($session['id'] ?? 0);
if ($sessionId <= 0) {
    lex_inbox_signal_fail(404, 'This call is not available.');
}

if ($method === 'GET') {
    if ((string) ($session['status'] ?? '') === 'ended') {
        echo json_encode([
            'ok' => true,
            'sessionStatus' => 'ended',
            'otherPresent' => false,
            'lastId' => $sinceId,
            'signals' => [],
            'endedReason' => 'ended',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $session = lex_inbox_call_touch($session, $userId);
    $stmt = $pdo->prepare(
        'SELECT id, sender_user_id, signal_type, payload
         FROM message_call_signals
         WHERE session_id = :session_id AND sender_user_id = :peer AND id > :since_id
         ORDER BY id ASC
         LIMIT 200'
    );
    $stmt->execute(['session_id' => $sessionId, 'peer' => $peerId, 'since_id' => $sinceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $signals = [];
    foreach ($rows as $row) {
        $signals[] = [
            'id' => (int) $row['id'],
            'type' => (string) $row['signal_type'],
            'payload' => json_decode((string) $row['payload'], true),
        ];
    }
    $lastId = $signals !== [] ? (int) $signals[array_key_last($signals)]['id'] : $sinceId;
    echo json_encode([
        'ok' => true,
        'sessionStatus' => (string) ($session['status'] ?? 'waiting'),
        'otherPresent' => lex_inbox_call_other_present($session, $userId),
        'lastId' => $lastId,
        'signals' => $signals,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$type = lex_sanitize_text($body['type'] ?? '');
$allowedTypes = ['join', 'leave', 'decline', 'offer', 'answer', 'candidate', 'media-state'];
if (!in_array($type, $allowedTypes, true)) {
    lex_inbox_signal_fail(422, 'Unsupported signal type.');
}

if ($type === 'leave' || $type === 'decline') {
    lex_inbox_call_end($sessionId);
}

$payload = $body['payload'] ?? [];
$pdo->prepare(
    'INSERT INTO message_call_signals (session_id, sender_user_id, signal_type, payload)
     VALUES (:session_id, :sender_user_id, :signal_type, :payload)'
)->execute([
    'session_id' => $sessionId,
    'sender_user_id' => $userId,
    'signal_type' => $type,
    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
