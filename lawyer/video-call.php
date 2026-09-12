<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/video_call/helpers.php';

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
lex_video_call_tables_ensure($pdo);

$appointmentId = lex_sanitize_int($_GET['appointment_id'] ?? 0);
$appointment = lex_video_call_load_appointment($pdo, $appointmentId, $user);

lex_page_header('Video Call', 'appointments', $user);

if (!$appointment) {
    ?>
    <section class="card video-call-card video-call-card--empty">
      <h2>Video call unavailable</h2>
      <p class="muted">We couldn&rsquo;t find that appointment, or it doesn&rsquo;t belong to your account.</p>
      <a class="button button-primary" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">Back to appointments</a>
    </section>
    <?php
} elseif (!lex_video_call_is_joinable($appointment)) {
    ?>
    <section class="card video-call-card video-call-card--empty">
      <h2>This appointment isn&rsquo;t confirmed yet</h2>
      <p class="muted">Confirm the appointment to open the video call. Current status: <strong><?= lex_e((string) $appointment['status']) ?></strong>.</p>
      <a class="button button-primary" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">Back to appointments</a>
    </section>
    <?php
} else {
    $session = lex_video_call_get_or_create_session($pdo, $appointment);
    lex_audit('video_call_open', 'video_call_sessions', (string) $session['id']);
    lex_video_call_render_room($appointment, $session, 'lawyer', (string) ($user['full_name'] ?? 'Lawyer'));
}

lex_page_footer();
