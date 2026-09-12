<?php

declare(strict_types=1);

/**
 * Incoming video-call ring for whoever is logged in.
 * GET  → { ok, incoming: null | { peerUserId, peerName, callHref, ... } }
 * POST → { action: "decline", peer_user_id, csrf_token }
 */
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/messages/core.php';
require_once __DIR__ . '/config/messages/lock.php';
require_once __DIR__ . '/config/messages/shared.php';
require_once __DIR__ . '/config/messages/calls.php';

header('Content-Type: application/json; charset=utf-8');

function lex_inbox_ring_fail(int $status, string $message): void
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
if ($method !== 'GET') {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : [];
    if (!lex_csrf_validate($body['csrf_token'] ?? null)) {
        lex_inbox_ring_fail(403, 'Your session expired. Reload and try again.');
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($method === 'GET') {
    echo json_encode([
        'ok' => true,
        'incoming' => lex_inbox_call_incoming_for($userId),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = lex_sanitize_text($body['action'] ?? 'decline');
$peerId = lex_sanitize_int($body['peer_user_id'] ?? $body['with'] ?? 0);
$allowed = lex_messages_allowed_recipients($user);
$peer = lex_messages_can_contact($allowed, $peerId);
if (
    !$peer
    || $peerId === $userId
    || !lex_messages_roles_may_chat((string) ($user['role'] ?? ''), (string) ($peer['role'] ?? ''))
) {
    lex_inbox_ring_fail(404, 'This call is not available.');
}

$session = lex_inbox_call_find($userId, $peerId);
$sessionId = (int) ($session['id'] ?? 0);
if ($sessionId <= 0) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'decline') {
    lex_inbox_call_end($sessionId);
    $pdo->prepare(
        'INSERT INTO message_call_signals (session_id, sender_user_id, signal_type, payload)
         VALUES (:session_id, :sender_user_id, "decline", :payload)'
    )->execute([
        'session_id' => $sessionId,
        'sender_user_id' => $userId,
        'payload' => '{}',
    ]);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
