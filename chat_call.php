<?php

declare(strict_types=1);

/**
 * Video call started from Messages.
 * http://localhost/lexshield/chat_call.php?with=USER_ID
 */
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/messages/core.php';
require_once __DIR__ . '/config/messages/lock.php';
require_once __DIR__ . '/config/messages/shared.php';
require_once __DIR__ . '/config/messages/calls.php';

$user = lex_require_login();
$peerId = lex_sanitize_int($_GET['with'] ?? 0);
$allowed = lex_messages_allowed_recipients($user);
$peer = lex_messages_can_contact($allowed, $peerId);

if (
    !$peer
    || $peerId === (int) $user['id']
    || !lex_messages_roles_may_chat((string) ($user['role'] ?? ''), (string) ($peer['role'] ?? ''))
) {
    http_response_code(404);
    lex_page_header('Video call', 'messages', $user);
    echo '<section class="card video-call-card video-call-card--empty"><h2>Video call unavailable</h2>'
        . '<p class="muted">You can only call someone you are allowed to message.</p>'
        . '<a class="button button-primary" href="' . lex_e(function_exists('lex_nav_href') ? lex_nav_href('chat.php') : lex_app_url('chat.php')) . '">Back to messages</a></section>';
    lex_page_footer();
    exit;
}

$join = lex_inbox_call_start_or_join((int) $user['id'], $peerId);
$session = $join['session'];
$startedRing = !empty($join['started']);
if ($startedRing) {
    lex_notify($peerId, 'call', (string) ($user['full_name'] ?? 'Someone') . ' is calling you.');
    if (function_exists('lex_email_notify_call')) {
        lex_email_notify_call($peerId, (string) ($user['full_name'] ?? 'Someone'));
    }
}
lex_audit('inbox_video_call', 'message_call_sessions', (string) ($session['id'] ?? ''), (int) $user['id']);

$counterpartName = (string) ($peer['full_name'] ?? 'User');
$viewerName = (string) ($user['full_name'] ?? 'You');
$isInitiator = (int) $user['id'] < $peerId;
$sinceId = lex_inbox_call_latest_signal_id((int) ($session['id'] ?? 0));
$pageData = [
    'peerUserId' => $peerId,
    'isInitiator' => $isInitiator,
    'isCaller' => $startedRing,
    'sinceId' => $sinceId,
    'role' => (string) ($user['role'] ?? ''),
    'csrfToken' => lex_csrf_token(),
    'signalEndpoint' => lex_app_url('chat_call_signal.php'),
    'backUrl' => function_exists('lex_nav_href') ? lex_nav_href('chat.php?with=' . $peerId) : lex_app_url('chat.php?with=' . $peerId),
    'counterpartName' => $counterpartName,
    'viewerLabel' => $viewerName . ' (You)',
    'appointmentTitle' => 'Video call',
    'appointmentId' => 0,
    'iceServers' => function_exists('lex_webrtc_ice_servers') ? lex_webrtc_ice_servers() : [],
];

lex_page_header('Video call', 'messages', $user);
?>
<section class="video-call-page" data-video-call-page>
  <div class="card video-call-card">
    <div class="video-call-head">
      <div>
        <h2>Video call</h2>
        <p class="muted">With <?= lex_e($counterpartName) ?> · <?= lex_e(ucfirst((string) ($peer['role'] ?? ''))) ?></p>
      </div>
      <span class="pill" data-video-call-status-pill>Connecting&hellip;</span>
    </div>
    <script type="application/json" id="video-call-data"><?= json_encode($pageData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
    <div class="video-call-stage" data-video-call-stage>
      <div class="video-call-tile video-call-tile--remote">
        <video data-video-call-remote-video autoplay playsinline></video>
        <div class="video-call-tile-empty" data-video-call-remote-empty>
          <span class="video-call-avatar"><?= lex_e(lex_inbox_call_initial($counterpartName)) ?></span>
          <p data-video-call-remote-empty-text><?= $startedRing ? 'Calling ' . lex_e($counterpartName) . '…' : 'Connecting…' ?></p>
        </div>
        <span class="video-call-name-badge"><?= lex_e($counterpartName) ?></span>
      </div>
      <div class="video-call-tile video-call-tile--local">
        <video data-video-call-local-video autoplay playsinline muted></video>
        <div class="video-call-tile-empty" data-video-call-local-empty>
          <span class="video-call-avatar"><?= lex_e(lex_inbox_call_initial($viewerName !== '' ? $viewerName : 'You')) ?></span>
        </div>
        <span class="video-call-name-badge"><?= lex_e($pageData['viewerLabel']) ?></span>
        <span class="video-call-mic-live" data-video-call-mic-live hidden>Mic live</span>
      </div>
    </div>
    <p class="video-call-error" data-video-call-error hidden></p>
    <p class="muted video-call-audio-hint">You will not hear your own voice. The other person hears you. If they sound silent, tap Hear audio.</p>
    <button class="button button-primary video-call-enable-camera" type="button" data-video-call-enable-camera hidden>Turn on camera</button>
    <button class="button button-primary video-call-hear-audio" type="button" data-video-call-hear-audio hidden>Hear audio</button>
    <div class="video-call-controls">
      <button type="button" class="video-call-control-btn" data-video-call-toggle-mic aria-pressed="true" title="Mute microphone">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 0 0-7 0v6a3.5 3.5 0 0 0 3.5 3.5Z" fill="currentColor"/></svg>
      </button>
      <button type="button" class="video-call-control-btn" data-video-call-toggle-camera aria-pressed="true" title="Turn off camera">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 10.5 21 7v10l-4-3.5V16a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 3 16V8a1.5 1.5 0 0 1 1.5-1.5h11A1.5 1.5 0 0 1 17 8v2.5Z" fill="currentColor"/></svg>
      </button>
      <button type="button" class="video-call-control-btn video-call-control-btn--danger" data-video-call-leave title="Leave call">Leave</button>
    </div>
  </div>
</section>
<script defer src="<?= lex_e(function_exists('lex_asset_url') ? lex_asset_url('public/js/video-call.js') : lex_app_url('public/js/video-call.js')) ?>"></script>
<?php
lex_page_footer();
