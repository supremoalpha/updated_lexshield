<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/messages/lock.php';

$user = lex_require_login();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();

if (lex_messages_lock_applies($user) && !lex_messages_lock_is_unlocked((int) $user['id'])) {
    header('Location: ' . lex_messages_lock_url($user));
    exit;
}

$messageId = lex_sanitize_int($_GET['id'] ?? 0);
if (!$messageId) {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = lex_pdo()->prepare(
    'SELECT m.id, m.sender_id, m.receiver_id, m.attachment_original_name, m.attachment_stored_name, m.attachment_mime_type, m.attachment_size,
            m.attachment_encryption_algorithm, m.attachment_encryption_iv, m.attachment_encryption_tag
     FROM messages m
     WHERE m.id = :id
       AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
     LIMIT 1'
);
$stmt->execute(['id' => $messageId, 'viewer_id' => (int) $user['id']]);
$message = $stmt->fetch();

if (!$message) {
    http_response_code(404);
    exit('Attachment not found.');
}

$currentUserId = (int) $user['id'];
if ($currentUserId !== (int) $message['sender_id'] && $currentUserId !== (int) $message['receiver_id']) {
    http_response_code(403);
    exit('Access denied.');
}

$storedName = (string) ($message['attachment_stored_name'] ?? '');
$originalName = (string) ($message['attachment_original_name'] ?? '');
if ($storedName === '') {
    http_response_code(404);
    exit('Attachment not found.');
}

if ($originalName === '') {
    $originalName = basename($storedName);
}

$path = lex_messages_attachment_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file missing.');
}

$mime = (string) ($message['attachment_mime_type'] ?? 'application/octet-stream');
$cipherData = (string) file_get_contents($path);
$algorithm = (string) ($message['attachment_encryption_algorithm'] ?? '');
if ($algorithm !== '') {
    try {
        $outputData = lex_messages_decrypt(
            $cipherData,
            $algorithm,
            (string) ($message['attachment_encryption_iv'] ?? ''),
            (string) ($message['attachment_encryption_tag'] ?? '')
        );
    } catch (Throwable $e) {
        lex_audit('failed_decrypt_message_attachment', 'messages', (string) $messageId);
        http_response_code(500);
        exit('Unable to decrypt attachment.');
    }
} else {
    $outputData = $cipherData;
}

lex_audit('download_message_attachment', 'messages', (string) $messageId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) strlen($outputData));
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $originalName) . '"');
echo $outputData;
exit;
