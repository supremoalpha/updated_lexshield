<?php

require_once __DIR__ . '/config/bootstrap.php';

$user = lex_require_login();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();

$lockFile = __DIR__ . '/config/messages/lock.php';
if (is_file($lockFile)) {
    require_once $lockFile;
}
if (function_exists('lex_messages_lock_applies') && lex_messages_lock_applies($user)
    && function_exists('lex_messages_lock_is_unlocked') && !lex_messages_lock_is_unlocked((int) $user['id'])) {
    header('Location: ' . (function_exists('lex_messages_lock_url') ? lex_messages_lock_url($user) : lex_app_url('chat.php')));
    exit;
}

$messageId = lex_sanitize_int($_GET['id'] ?? 0);
if ($messageId <= 0) {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = lex_pdo()->prepare(
    'SELECT m.id, m.sender_id, m.receiver_id, m.attachment_original_name, m.attachment_stored_name,
            m.attachment_mime_type, m.attachment_encryption_algorithm
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

$userId = (int) $user['id'];
if ($userId !== (int) $message['sender_id'] && $userId !== (int) $message['receiver_id']) {
    http_response_code(403);
    exit('Access denied.');
}

$fileName = trim((string) ($message['attachment_original_name'] ?: 'Attachment'));
$mime = strtolower((string) ($message['attachment_mime_type'] ?? ''));
$canPreview = function_exists('lex_messages_previewable_mime') && lex_messages_previewable_mime($mime, $fileName);
$previewUrl = function_exists('lex_messages_attachment_url')
    ? lex_messages_attachment_url($messageId, true)
    : lex_app_url('message_attachment.php?id=' . $messageId . '&preview=1');
$downloadUrl = function_exists('lex_messages_attachment_url')
    ? lex_messages_attachment_url($messageId, false)
    : lex_app_url('message_attachment.php?id=' . $messageId);
$peerId = $userId === (int) $message['sender_id'] ? (int) $message['receiver_id'] : (int) $message['sender_id'];
$backHref = function_exists('lex_nav_href')
    ? lex_nav_href('chat.php?with=' . $peerId)
    : lex_app_url('chat.php?with=' . $peerId);

$isImage = str_starts_with($mime, 'image/');
$isVideo = str_starts_with($mime, 'video/');
$isPdf = $mime === 'application/pdf' || preg_match('/\.pdf$/i', $fileName) === 1;
$isText = str_starts_with($mime, 'text/') || preg_match('/\.(txt|log|md|csv)$/i', $fileName) === 1;
$textBody = '';
if ($isText && function_exists('lex_messages_attachment_path') && !empty($message['attachment_stored_name'])) {
    $path = lex_messages_attachment_path((string) $message['attachment_stored_name']);
    if (is_file($path)) {
        $bytes = (string) file_get_contents($path);
        if ((string) ($message['attachment_encryption_algorithm'] ?? '') !== '' && function_exists('lex_messages_decrypt')) {
            $row = lex_pdo()->prepare('SELECT attachment_encryption_algorithm, attachment_encryption_iv, attachment_encryption_tag FROM messages WHERE id = :id LIMIT 1');
            $row->execute(['id' => $messageId]);
            $enc = $row->fetch() ?: [];
            try {
                $bytes = lex_messages_decrypt(
                    $bytes,
                    (string) ($enc['attachment_encryption_algorithm'] ?? ''),
                    (string) ($enc['attachment_encryption_iv'] ?? ''),
                    (string) ($enc['attachment_encryption_tag'] ?? '')
                );
            } catch (Throwable $e) {
                $bytes = '';
            }
        }
        if ($bytes !== '' && strlen($bytes) > 250000) {
            $bytes = substr($bytes, 0, 250000) . "\n\n[Preview truncated]";
        }
        $textBody = $bytes;
    }
}

lex_page_header('View message file', 'messages', $user);
?>
<section class="card case-file-view-only">
  <div class="card-head case-file-view-head">
    <div>
      <h2>Message file</h2>
      <p class="muted"><?= lex_e($fileName) ?></p>
    </div>
    <div class="case-file-view-actions">
      <a class="button button-secondary" href="<?= lex_e($downloadUrl) ?>">Download</a>
      <a class="button button-secondary" href="<?= lex_e($backHref) ?>">Back to messages</a>
    </div>
  </div>
  <div class="case-file-view-stage is-fit-screen">
    <?php if ($canPreview && $isImage): ?>
      <img class="case-file-view-media" src="<?= lex_e($previewUrl) ?>" alt="<?= lex_e($fileName) ?>">
    <?php elseif ($canPreview && $isVideo): ?>
      <video class="case-file-view-media case-file-view-video" src="<?= lex_e($previewUrl) ?>" controls playsinline></video>
    <?php elseif ($canPreview && $isText && $textBody !== ''): ?>
      <pre class="case-file-view-text"><?= lex_e($textBody) ?></pre>
    <?php elseif ($canPreview && $isPdf): ?>
      <iframe class="case-file-view-frame" src="<?= lex_e($previewUrl) ?>" title="<?= lex_e($fileName) ?>"></iframe>
    <?php else: ?>
      <div class="case-file-view-unavailable">
        <strong><?= lex_e($fileName) ?></strong>
        <p>This file cannot be opened on screen. Use Download if you need a copy.</p>
      </div>
    <?php endif; ?>
  </div>
</section>
<style>
.case-file-view-head h2, .case-file-view-head p { overflow-wrap: anywhere; margin: 0; }
.case-file-view-actions { display: flex; flex-wrap: wrap; gap: 0.55rem; justify-content: flex-end; }
.case-file-view-stage { position: relative; height: calc(100dvh - 13.5rem); min-height: 70vh; overflow: hidden; }
.case-file-view-media, .case-file-view-frame, .case-file-view-text { display: block; width: 100%; height: 100%; border: 0; }
.case-file-view-video { object-fit: contain; background: #061225; }
</style>
<?php lex_page_footer(); ?>
