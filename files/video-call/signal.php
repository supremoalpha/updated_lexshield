<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/video_call/helpers.php';

header('Content-Type: application/json; charset=utf-8');

function lex_video_call_signal_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = lex_require_role(['client', 'lawyer']);
$role = (string) $user['role'];
$pdo = lex_pdo();
lex_video_call_tables_ensure($pdo);

$identifier = 'user:' . (int) $user['id'];
if (function_exists('lex_rate_limit_allow') && !lex_rate_limit_allow('video_call_signal', $identifier, 180, 30)) {
    lex_video_call_signal_fail(429, 'Slow down - too many requests.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $appointmentId = lex_sanitize_int($_GET['appointment_id'] ?? 0);
    $sinceId = lex_sanitize_int($_GET['since_id'] ?? 0);
} else {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        lex_video_call_signal_fail(400, 'Invalid request body.');
    }
    $appointmentId = lex_sanitize_int($body['appointment_id'] ?? 0);
    if (!lex_csrf_validate($body['csrf_token'] ?? null)) {
        lex_video_call_signal_fail(403, 'Your session expired. Reload the page and try again.');
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$appointment = lex_video_call_load_appointment($pdo, $appointmentId, $user);
if (!$appointment || !lex_video_call_is_joinable($appointment)) {
    lex_video_call_signal_fail(404, 'This call is not available.');
}

$session = lex_video_call_get_or_create_session($pdo, $appointment);
$sessionId = (int) $session['id'];

if ($method === 'GET') {
    $session = lex_video_call_touch_presence($pdo, $sessionId, $role);
    $rows = lex_video_call_fetch_signals($pdo, $sessionId, $role, $sinceId);

    $signals = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'senderRole' => (string) $row['sender_role'],
            'type' => (string) $row['signal_type'],
            'payload' => json_decode((string) $row['payload'], true),
        ];
    }, $rows);

    $lastId = $signals ? (int) end($signals)['id'] : $sinceId;

    echo json_encode([
        'ok' => true,
        'sessionStatus' => (string) $session['status'],
        'otherPresent' => lex_video_call_other_present($session, $role),
        'lastId' => $lastId,
        'signals' => $signals,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $type = lex_sanitize_text($body['type'] ?? '');
    $allowedTypes = ['join', 'leave', 'offer', 'answer', 'candidate', 'media-state'];
    if (!in_array($type, $allowedTypes, true)) {
        lex_video_call_signal_fail(422, 'Unsupported signal type.');
    }

    $payloadRaw = $body['payload'] ?? [];
    $payloadJson = json_encode($payloadRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false || strlen($payloadJson) > 131072) {
        lex_video_call_signal_fail(422, 'Signal payload is invalid or too large.');
    }

    lex_video_call_touch_presence($pdo, $sessionId, $role);
    $id = lex_video_call_record_signal($pdo, $sessionId, $role, $type, $payloadJson);

    if ($type === 'leave') {
        lex_video_call_mark_left($pdo, $sessionId);
    }

    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

lex_video_call_signal_fail(405, 'Method not allowed.');
