<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$lawyerId = lex_user_lawyer_id((int) $user['id']);

$assignedCases = lex_stats('SELECT COUNT(*) FROM cases WHERE lawyer_id = :id', ['id' => $lawyerId]);
$upcomingAppointments = lex_stats("SELECT COUNT(*) FROM appointments WHERE lawyer_id = :id AND scheduled_at >= NOW() AND status IN ('pending','confirmed') AND status <> 'deleted'", ['id' => $lawyerId]);
$unreadMessages = lex_stats('SELECT COUNT(*) FROM messages WHERE receiver_id = :uid AND is_read = 0', ['uid' => (int) $user['id']]);
$totalClients = lex_stats('SELECT COUNT(DISTINCT client_id) FROM cases WHERE lawyer_id = :id', ['id' => $lawyerId]);

$lawyerProfile = lex_recent(
    'SELECT l.bar_number, l.specialization, l.status, l.bio, l.background, u.full_name, u.email, u.avatar_stored_name, u.created_at
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE l.id = :id
     LIMIT 1',
    ['id' => $lawyerId]
);
$lawyerProfile = $lawyerProfile[0] ?? [
    'bar_number' => '',
    'specialization' => '',
    'status' => 'active',
    'bio' => '',
    'background' => '',
    'full_name' => $user['full_name'] ?? 'Lawyer',
    'email' => $user['email'] ?? '',
    'avatar_stored_name' => '',
    'created_at' => '',
];

$cases = lex_recent(
    'SELECT c.case_number, c.title, c.status, c.priority, u.full_name AS client_name
     FROM cases c
     JOIN clients cl ON cl.id = c.client_id
     JOIN users u ON u.id = cl.user_id
     WHERE c.lawyer_id = :id
     ORDER BY c.id DESC
     LIMIT 4',
    ['id' => $lawyerId]
);

$appointments = lex_recent(
    'SELECT a.scheduled_at, a.status, a.appointment_type, u.full_name AS client_name, c.case_number
     FROM appointments a
     JOIN clients cl ON cl.id = a.client_id
     JOIN users u ON u.id = cl.user_id
     JOIN cases c ON c.id = a.case_id
     WHERE a.lawyer_id = :id
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at DESC, a.id DESC
     LIMIT 3',
    ['id' => $lawyerId]
);

$nextAppointment = null;
foreach ($appointments as $appointment) {
    try {
        $when = new DateTimeImmutable((string) $appointment['scheduled_at']);
        if ($when >= new DateTimeImmutable()) {
            $nextAppointment = $appointment;
            break;
        }
    } catch (Throwable $e) {
        continue;
    }
}

$formatDate = static function (?string $value, string $fallback = 'Not scheduled'): string {
    if (!$value) {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
};

$formatDateTime = static function (?string $value, string $fallback = 'Not scheduled'): string {
    if (!$value) {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $value;
    }
};

$formatTime = static function (?string $value, string $fallback = '--:--'): string {
    if (!$value) {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($value))->format('g:i A');
    } catch (Throwable $e) {
        return $value;
    }
};

$buildInitials = static function (string $value, string $fallback): string {
    $clean = preg_replace('/[^A-Za-z0-9]+/', ' ', trim($value));
    $parts = array_values(array_filter(explode(' ', (string) $clean)));
    if (!$parts) {
        return $fallback;
    }

    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }

    return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
};

$statusLabel = static function (string $status): string {
    $normalized = trim(strtolower($status));
    if ($normalized === '') {
        return 'Unknown';
    }

    return ucwords(str_replace(['_', '-'], ' ', $normalized));
};

$statusClass = static function (string $status): string {
    $normalized = trim(strtolower($status));
    return match ($normalized) {
        'open', 'active', 'confirmed' => 'is-success',
        'ongoing', 'pending' => 'is-warning',
        'closed', 'inactive', 'cancelled', 'deleted' => 'is-danger',
        default => '',
    };
};

$profileName = (string) ($lawyerProfile['full_name'] ?? 'Lawyer');
$profileAvatarUrl = lex_profile_avatar_url((string) ($lawyerProfile['avatar_stored_name'] ?? ''));
$profileInitials = $buildInitials($profileName, 'LW');
$memberSince = $formatDate((string) ($lawyerProfile['created_at'] ?? ''), 'Recently joined');
$nextAppointmentDate = $nextAppointment ? $formatDate((string) $nextAppointment['scheduled_at']) : 'All clear';
$nextAppointmentDateTime = $nextAppointment ? $formatDateTime((string) $nextAppointment['scheduled_at']) : 'No upcoming appointment';

lex_page_header('Lawyer Dashboard', 'dashboard', $user);
?>
<div class="lawyer-dashboard-shell">
  <section class="lawyer-dashboard-metrics" aria-label="Dashboard metrics">
    <article class="lawyer-metric-card theme-success">
      <span class="lawyer-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v8A2.5 2.5 0 0 1 17.5 18h-11A2.5 2.5 0 0 1 4 15.5v-8Zm2.5-1a1 1 0 0 0-1 1v1h13v-1a1 1 0 0 0-1-1h-11Zm12 3.5h-13v5.5a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V10Z" fill="currentColor"/></svg>
      </span>
      <span class="lawyer-metric-label">Assigned cases</span>
      <strong class="lawyer-metric-value"><?= number_format($assignedCases) ?></strong>
      <span class="lawyer-metric-meta"><?= $assignedCases > 0 ? 'Active workload' : 'No assigned matters' ?></span>
    </article>

    <article class="lawyer-metric-card theme-info">
      <span class="lawyer-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M7 2a.75.75 0 0 1 .75.75V4h8.5V2.75a.75.75 0 0 1 1.5 0V4h.75A2.5 2.5 0 0 1 21 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11A2.5 2.5 0 0 1 5.5 4h.75V2.75A.75.75 0 0 1 7 2Zm11.5 7h-13v8.5a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V9Z" fill="currentColor"/></svg>
      </span>
      <span class="lawyer-metric-label">Upcoming appointments</span>
      <strong class="lawyer-metric-value"><?= number_format($upcomingAppointments) ?></strong>
      <span class="lawyer-metric-meta"><?= lex_e($nextAppointmentDate) ?></span>
    </article>

    <article class="lawyer-metric-card theme-gold">
      <span class="lawyer-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8A2.5 2.5 0 0 1 17.5 16H9.81l-3.905 3.515A.75.75 0 0 1 4 18.957V16.22A2.5 2.5 0 0 1 4 16V5.5Zm4.25 3.75a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" fill="currentColor"/></svg>
      </span>
      <span class="lawyer-metric-label">Unread messages</span>
      <strong class="lawyer-metric-value"><?= number_format($unreadMessages) ?></strong>
      <span class="lawyer-metric-meta"><?= $unreadMessages > 0 ? 'Needs review' : 'All caught up' ?></span>
    </article>

    <article class="lawyer-metric-card theme-neutral">
      <span class="lawyer-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.418 0-8 2.015-8 4.5a.75.75 0 0 0 1.5 0c0-1.33 2.63-3 6.5-3s6.5 1.67 6.5 3a.75.75 0 0 0 1.5 0c0-2.485-3.582-4.5-8-4.5Z" fill="currentColor"/></svg>
      </span>
      <span class="lawyer-metric-label">Unique clients</span>
      <strong class="lawyer-metric-value"><?= number_format($totalClients) ?></strong>
      <span class="lawyer-metric-meta">Since <?= lex_e($memberSince) ?></span>
    </article>
  </section>

  <section class="lawyer-dashboard-grid lawyer-dashboard-grid--primary">
    <article class="card lawyer-dashboard-card">
      <div class="lawyer-dashboard-card-head">
        <div>
          <p class="lawyer-dashboard-card-kicker">Identity</p>
          <h3>Profile details</h3>
        </div>
        <a class="lawyer-dashboard-cta" href="<?= lex_e(lex_app_url('lawyer/profile.php')) ?>">View profile</a>
      </div>

      <div class="lawyer-dashboard-profile-row">
        <?php if ($profileAvatarUrl !== ''): ?>
          <img class="lawyer-dashboard-avatar lawyer-dashboard-avatar--profile" src="<?= lex_e($profileAvatarUrl) ?>" alt="Avatar for <?= lex_e($profileName) ?>">
        <?php else: ?>
          <div class="lawyer-dashboard-avatar lawyer-dashboard-avatar--profile" aria-hidden="true"><?= lex_e($profileInitials) ?></div>
        <?php endif; ?>
        <div class="lawyer-dashboard-profile-meta">
          <strong><?= lex_e($profileName) ?></strong>
          <span><?= lex_e((string) ($lawyerProfile['specialization'] ?? 'Specialization pending')) ?></span>
        </div>
        <span class="pill <?= lex_e($statusClass((string) ($lawyerProfile['status'] ?? ''))) ?>"><?= lex_e($statusLabel((string) ($lawyerProfile['status'] ?? ''))) ?></span>
      </div>

      <div class="lawyer-dashboard-detail-list">
        <div class="lawyer-dashboard-detail-row">
          <span>Email</span>
          <strong><?= lex_e((string) ($lawyerProfile['email'] ?? 'Not provided')) ?></strong>
        </div>
        <div class="lawyer-dashboard-detail-row">
          <span>Bar number</span>
          <strong class="lawyer-dashboard-code"><?= lex_e((string) ($lawyerProfile['bar_number'] ?? 'Pending')) ?></strong>
        </div>
        <div class="lawyer-dashboard-detail-row">
          <span>Member since</span>
          <strong><?= lex_e($memberSince) ?></strong>
        </div>
      </div>
    </article>

    <article class="card lawyer-dashboard-card">
      <div class="lawyer-dashboard-card-head">
        <div>
          <p class="lawyer-dashboard-card-kicker">Schedule</p>
          <h3>Recent appointments</h3>
        </div>
        <a class="lawyer-dashboard-cta" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">Open appointments</a>
      </div>

      <div class="lawyer-dashboard-appointment-list">
        <?php foreach ($appointments as $appointment): ?>
          <?php
          $appointmentStatus = $statusLabel((string) ($appointment['status'] ?? ''));
          $appointmentStatusClass = $statusClass((string) ($appointment['status'] ?? ''));
          ?>
          <div class="lawyer-dashboard-appointment-row">
            <span class="lawyer-dashboard-appointment-dot" aria-hidden="true"></span>
            <div class="lawyer-dashboard-appointment-main">
              <strong><?= lex_e((string) $appointment['case_number']) ?></strong>
              <span><?= lex_e((string) $appointment['client_name']) ?></span>
            </div>
            <div class="lawyer-dashboard-appointment-side">
              <span class="pill <?= lex_e($appointmentStatusClass) ?>"><?= lex_e($appointmentStatus) ?></span>
              <strong><?= lex_e($formatDate((string) $appointment['scheduled_at'])) ?></strong>
              <span><?= lex_e($formatTime((string) $appointment['scheduled_at'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$appointments): ?>
          <div class="lawyer-dashboard-empty lawyer-dashboard-empty--soft">
            <p>No appointments yet.</p>
            <a class="button button-primary" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">Open schedule</a>
          </div>
        <?php endif; ?>
      </div>
    </article>
  </section>

  <section class="lawyer-dashboard-grid lawyer-dashboard-grid--secondary">
    <article class="card lawyer-dashboard-card">
      <div class="lawyer-dashboard-card-head">
        <div>
          <p class="lawyer-dashboard-card-kicker">Workload</p>
          <h3>Assigned cases</h3>
        </div>
        <a class="lawyer-dashboard-cta" href="<?= lex_e(lex_app_url('case_files.php')) ?>">Open cases</a>
      </div>

      <div class="lawyer-dashboard-case-list">
        <?php foreach ($cases as $case): ?>
          <div class="lawyer-dashboard-case-row">
            <div class="lawyer-dashboard-case-main">
              <strong><?= lex_e((string) $case['case_number']) ?></strong>
              <span><?= lex_e((string) $case['title']) ?></span>
              <small><?= lex_e((string) $case['client_name']) ?></small>
            </div>
            <div class="lawyer-dashboard-case-side">
              <span class="pill <?= lex_e($statusClass((string) ($case['status'] ?? ''))) ?>"><?= lex_e($statusLabel((string) ($case['status'] ?? ''))) ?></span>
              <strong><?= lex_e(ucfirst((string) ($case['priority'] ?? 'normal'))) ?></strong>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$cases): ?>
          <div class="lawyer-dashboard-empty lawyer-dashboard-empty--soft">
            <p>No assigned cases yet.</p>
            <a class="button button-primary" href="<?= lex_e(lex_app_url('case_files.php')) ?>">Open case files</a>
          </div>
        <?php endif; ?>
      </div>
    </article>

    <article class="lawyer-dashboard-next card">
      <div class="lawyer-dashboard-next-label">Next appointment</div>
      <?php if ($nextAppointment): ?>
        <h3><?= lex_e((string) $nextAppointment['case_number']) ?></h3>
        <div class="lawyer-dashboard-next-details">
          <div>
            <span>Client</span>
            <strong><?= lex_e((string) $nextAppointment['client_name']) ?></strong>
          </div>
          <div>
            <span>Scheduled</span>
            <strong><?= lex_e($nextAppointmentDateTime) ?></strong>
          </div>
          <div>
            <span>Status</span>
            <strong><span class="pill <?= lex_e($statusClass((string) ($nextAppointment['status'] ?? ''))) ?>"><?= lex_e($statusLabel((string) ($nextAppointment['status'] ?? ''))) ?></span></strong>
          </div>
          <div>
            <span>Case</span>
            <strong><?= lex_e((string) $nextAppointment['case_number']) ?></strong>
          </div>
        </div>
        <div class="lawyer-dashboard-next-actions">
          <a class="button button-primary" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">Open appointments</a>
          <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/messages.php')) ?>">Message client</a>
        </div>
      <?php else: ?>
        <h3>No upcoming appointments</h3>
        <p class="lawyer-dashboard-next-copy">Your schedule is clear right now. Review messages or assigned cases while you wait for the next booking.</p>
        <div class="lawyer-dashboard-next-actions">
          <a class="button button-primary" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">Open schedule</a>
          <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/messages.php')) ?>">Open messages</a>
        </div>
      <?php endif; ?>
    </article>
  </section>
</div>
<?php lex_page_footer(); ?>
