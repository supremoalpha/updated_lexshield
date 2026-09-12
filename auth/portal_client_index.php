<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$clientId = lex_user_client_id((int) $user['id']);

$activeCases = lex_stats("SELECT COUNT(*) FROM cases WHERE client_id = :id AND status IN ('open','ongoing')", ['id' => $clientId]);
$upcomingAppointments = lex_stats("SELECT COUNT(*) FROM appointments WHERE client_id = :id AND scheduled_at >= NOW() AND status IN ('pending','confirmed') AND status <> 'deleted'", ['id' => $clientId]);
$unreadMessages = lex_stats('SELECT COUNT(*) FROM messages WHERE receiver_id = :id AND is_read = 0', ['id' => (int) $user['id']]);
$totalCases = lex_stats('SELECT COUNT(*) FROM cases WHERE client_id = :id', ['id' => $clientId]);

$clientProfile = lex_recent(
    'SELECT c.contact_number, c.address, c.risk_level, u.full_name, u.email, u.avatar_stored_name, u.created_at
     FROM clients c
     JOIN users u ON u.id = c.user_id
     WHERE c.id = :id
     LIMIT 1',
    ['id' => $clientId]
);
$clientProfile = $clientProfile[0] ?? [
    'contact_number' => '',
    'address' => '',
    'risk_level' => 'low',
    'full_name' => $user['full_name'] ?? 'Client',
    'email' => $user['email'] ?? '',
    'avatar_stored_name' => '',
    'created_at' => '',
];

$assignedLawyer = lex_recent(
    'SELECT u.full_name, u.email, u.avatar_stored_name, c.case_number, c.title, c.status
     FROM cases c
     JOIN lawyers l ON l.id = c.lawyer_id
     JOIN users u ON u.id = l.user_id
     WHERE c.client_id = :id
     ORDER BY FIELD(c.status, "ongoing", "open", "closed", "archived"), c.id DESC
     LIMIT 1',
    ['id' => $clientId]
);
$assignedLawyer = $assignedLawyer[0] ?? null;

$nextAppointment = lex_recent(
    'SELECT a.scheduled_at, a.status, COALESCE(NULLIF(a.appointment_type, ""), NULLIF(c.title, ""), "Appointment Request") AS appointment_title, u.full_name AS lawyer_name
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     LEFT JOIN lawyers l ON l.id = a.lawyer_id
     LEFT JOIN users u ON u.id = l.user_id
     WHERE a.client_id = :id
       AND a.scheduled_at >= NOW()
       AND a.status IN ("pending", "confirmed")
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at ASC
     LIMIT 1',
    ['id' => $clientId]
);
$nextAppointment = $nextAppointment[0] ?? null;

$appointments = lex_recent(
    'SELECT a.scheduled_at, a.status, COALESCE(NULLIF(a.appointment_type, ""), NULLIF(c.title, ""), "Appointment Request") AS appointment_title, u.full_name AS lawyer_name
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     LEFT JOIN lawyers l ON l.id = a.lawyer_id
     LEFT JOIN users u ON u.id = l.user_id
     WHERE a.client_id = :id
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at DESC
     LIMIT 2',
    ['id' => $clientId]
);

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

$profileName = (string) ($clientProfile['full_name'] ?? 'Client');
$profileAvatarUrl = lex_profile_avatar_url((string) ($clientProfile['avatar_stored_name'] ?? ''));
$profileInitials = $buildInitials($profileName, 'CL');
$memberSince = $formatDate((string) ($clientProfile['created_at'] ?? ''), 'Recently joined');
$nextAppointmentDate = $nextAppointment ? $formatDate((string) $nextAppointment['scheduled_at']) : 'All clear';
$nextAppointmentDateTime = $nextAppointment ? $formatDateTime((string) $nextAppointment['scheduled_at']) : 'No upcoming appointment';

lex_page_header('Client Dashboard', 'dashboard', $user);
?>
<div class="client-dashboard-shell">
  <section class="client-dashboard-metrics" aria-label="Dashboard metrics">
    <article class="client-metric-card theme-success">
      <span class="client-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v8A2.5 2.5 0 0 1 17.5 18h-11A2.5 2.5 0 0 1 4 15.5v-8Zm2.5-1a1 1 0 0 0-1 1v1h13v-1a1 1 0 0 0-1-1h-11Zm12 3.5h-13v5.5a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V10Z" fill="currentColor"/></svg>
      </span>
      <span class="client-metric-label">Active cases</span>
      <strong class="client-metric-value"><?= number_format($activeCases) ?></strong>
      <span class="client-metric-meta"><?= $activeCases > 0 ? 'In progress' : 'No active matters' ?></span>
    </article>

    <article class="client-metric-card theme-info">
      <span class="client-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M7 2a.75.75 0 0 1 .75.75V4h8.5V2.75a.75.75 0 0 1 1.5 0V4h.75A2.5 2.5 0 0 1 21 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5v-11A2.5 2.5 0 0 1 5.5 4h.75V2.75A.75.75 0 0 1 7 2Zm11.5 7h-13v8.5a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V9Z" fill="currentColor"/></svg>
      </span>
      <span class="client-metric-label">Upcoming appointments</span>
      <strong class="client-metric-value"><?= number_format($upcomingAppointments) ?></strong>
      <span class="client-metric-meta"><?= lex_e($nextAppointmentDate) ?></span>
    </article>

    <article class="client-metric-card theme-gold">
      <span class="client-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8A2.5 2.5 0 0 1 17.5 16H9.81l-3.905 3.515A.75.75 0 0 1 4 18.957V16.22A2.5 2.5 0 0 1 4 16V5.5Zm4.25 3.75a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" fill="currentColor"/></svg>
      </span>
      <span class="client-metric-label">Unread messages</span>
      <strong class="client-metric-value"><?= number_format($unreadMessages) ?></strong>
      <span class="client-metric-meta"><?= $unreadMessages > 0 ? 'Needs review' : 'All caught up' ?></span>
    </article>

    <article class="client-metric-card theme-neutral">
      <span class="client-metric-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false"><path d="M6.5 4A2.5 2.5 0 0 0 4 6.5v9A2.5 2.5 0 0 0 6.5 18H8v1.25a.75.75 0 0 0 1.28.53L11.06 18H17.5A2.5 2.5 0 0 0 20 15.5v-9A2.5 2.5 0 0 0 17.5 4h-11Zm1.75 4.25a.75.75 0 0 1 .75-.75h6a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1-.75-.75Zm0 3.5A.75.75 0 0 1 9 11h6a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1-.75-.75Z" fill="currentColor"/></svg>
      </span>
      <span class="client-metric-label">Total cases</span>
      <strong class="client-metric-value"><?= number_format($totalCases) ?></strong>
      <span class="client-metric-meta">Since <?= lex_e($memberSince) ?></span>
    </article>
  </section>

  <section class="client-dashboard-grid client-dashboard-grid--primary">
    <article class="card client-dashboard-card">
      <div class="client-dashboard-card-head">
        <div>
          <p class="client-dashboard-card-kicker">Primary contact</p>
          <h3>Assigned lawyer</h3>
        </div>
        <a class="client-dashboard-cta" href="<?= lex_e(lex_nav_href('client/lawyers.php')) ?>">View lawyers</a>
      </div>

      <?php if ($assignedLawyer): ?>
        <?php
        $lawyerName = (string) ($assignedLawyer['full_name'] ?? 'Assigned lawyer');
        $lawyerAvatarUrl = lex_profile_avatar_url((string) ($assignedLawyer['avatar_stored_name'] ?? ''));
        $lawyerInitials = $buildInitials($lawyerName, 'LW');
        $lawyerStatus = $statusLabel((string) ($assignedLawyer['status'] ?? ''));
        $lawyerStatusClass = $statusClass((string) ($assignedLawyer['status'] ?? ''));
        ?>
        <div class="client-dashboard-profile-row">
          <?php if ($lawyerAvatarUrl !== ''): ?>
            <img class="client-dashboard-avatar client-dashboard-avatar--lawyer" src="<?= lex_e($lawyerAvatarUrl) ?>" alt="Avatar for <?= lex_e($lawyerName) ?>">
          <?php else: ?>
            <div class="client-dashboard-avatar client-dashboard-avatar--lawyer" aria-hidden="true"><?= lex_e($lawyerInitials) ?></div>
          <?php endif; ?>
          <div class="client-dashboard-profile-meta">
            <strong><?= lex_e($lawyerName) ?></strong>
            <span><?= lex_e((string) ($assignedLawyer['email'] ?? 'No email available')) ?></span>
          </div>
          <span class="pill <?= lex_e($lawyerStatusClass) ?>"><?= lex_e($lawyerStatus) ?></span>
        </div>
        <div class="client-dashboard-detail-list">
          <div class="client-dashboard-detail-row">
            <span>Case ID</span>
            <strong class="client-dashboard-code"><?= lex_e((string) ($assignedLawyer['case_number'] ?? 'Not assigned')) ?></strong>
          </div>
          <div class="client-dashboard-detail-row">
            <span>Case title</span>
            <strong><?= lex_e((string) ($assignedLawyer['title'] ?? 'No title yet')) ?></strong>
          </div>
        </div>
      <?php else: ?>
        <div class="client-dashboard-empty">
          <p>No lawyer is linked to this account yet.</p>
          <a class="button button-primary" href="<?= lex_e(lex_nav_href('client/lawyers.php')) ?>">Browse lawyers</a>
        </div>
      <?php endif; ?>
    </article>

    <article class="card client-dashboard-card">
      <div class="client-dashboard-card-head">
        <div>
          <p class="client-dashboard-card-kicker">Identity</p>
          <h3>Profile details</h3>
        </div>
        <a class="client-dashboard-cta" href="<?= lex_e(lex_nav_href('client/profile.php#edit-profile')) ?>">Edit profile</a>
      </div>

      <div class="client-dashboard-profile-row">
        <?php if ($profileAvatarUrl !== ''): ?>
          <img class="client-dashboard-avatar client-dashboard-avatar--client" src="<?= lex_e($profileAvatarUrl) ?>" alt="Avatar for <?= lex_e($profileName) ?>">
        <?php else: ?>
          <div class="client-dashboard-avatar client-dashboard-avatar--client" aria-hidden="true"><?= lex_e($profileInitials) ?></div>
        <?php endif; ?>
        <div class="client-dashboard-profile-meta">
          <strong><?= lex_e($profileName) ?></strong>
          <span>Member since <?= lex_e($memberSince) ?></span>
        </div>
      </div>

      <div class="client-dashboard-detail-list">
        <div class="client-dashboard-detail-row">
          <span>Email</span>
          <strong><?= lex_e((string) ($clientProfile['email'] ?? 'Not provided')) ?></strong>
        </div>
        <div class="client-dashboard-detail-row">
          <span>Phone</span>
          <strong><?= lex_e((string) ($clientProfile['contact_number'] ?? 'Not provided')) ?></strong>
        </div>
        <div class="client-dashboard-detail-row">
          <span>Address</span>
          <strong><?= lex_e((string) ($clientProfile['address'] ?? 'Not provided')) ?></strong>
        </div>
      </div>
    </article>
  </section>

  <section class="client-dashboard-grid client-dashboard-grid--secondary">
    <article class="card client-dashboard-card">
      <div class="client-dashboard-card-head">
        <div>
          <p class="client-dashboard-card-kicker">Schedule</p>
          <h3>Recent appointments</h3>
        </div>
        <a class="client-dashboard-cta" href="<?= lex_e(lex_nav_href('client/appointment.php')) ?>">Book new</a>
      </div>

      <div class="client-dashboard-appointment-list">
        <?php foreach ($appointments as $appointment): ?>
          <?php
          $appointmentStatus = $statusLabel((string) ($appointment['status'] ?? ''));
          $appointmentStatusClass = $statusClass((string) ($appointment['status'] ?? ''));
          ?>
          <div class="client-dashboard-appointment-row">
            <span class="client-dashboard-appointment-dot" aria-hidden="true"></span>
            <div class="client-dashboard-appointment-main">
              <strong><?= lex_e((string) $appointment['appointment_title']) ?></strong>
              <span><?= lex_e((string) (($appointment['lawyer_name'] ?? '') !== '' ? $appointment['lawyer_name'] : $appointmentStatus)) ?></span>
            </div>
            <div class="client-dashboard-appointment-side">
              <span class="pill <?= lex_e($appointmentStatusClass) ?>"><?= lex_e($appointmentStatus) ?></span>
              <strong><?= lex_e($formatDate((string) $appointment['scheduled_at'])) ?></strong>
              <span><?= lex_e($formatTime((string) $appointment['scheduled_at'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if (!$appointments): ?>
          <div class="client-dashboard-empty client-dashboard-empty--soft">
            <p>No appointments yet.</p>
            <a class="button button-primary" href="<?= lex_e(lex_nav_href('client/appointment.php')) ?>">Schedule your first one</a>
          </div>
        <?php endif; ?>
      </div>
    </article>

    <article class="client-dashboard-next card">
      <div class="client-dashboard-next-label">Next appointment</div>
      <?php if ($nextAppointment): ?>
        <h3><?= lex_e((string) $nextAppointment['appointment_title']) ?></h3>
        <div class="client-dashboard-next-details">
          <div>
            <span>Appointment</span>
            <strong><?= lex_e((string) $nextAppointment['appointment_title']) ?></strong>
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
            <span>Lawyer</span>
            <strong><?= lex_e((string) (($nextAppointment['lawyer_name'] ?? '') !== '' ? $nextAppointment['lawyer_name'] : 'To be assigned')) ?></strong>
          </div>
        </div>
        <div class="client-dashboard-next-actions">
          <a class="button button-primary" href="<?= lex_e(lex_nav_href('client/appointment.php')) ?>">Open appointments</a>
          <a class="button button-secondary" href="<?= lex_e(lex_nav_href('client/messages.php')) ?>">Message lawyer</a>
        </div>
      <?php else: ?>
        <h3>No upcoming appointments</h3>
        <p class="client-dashboard-next-copy">You are clear for now. Book a new consultation when you are ready to move the next matter forward.</p>
        <div class="client-dashboard-next-actions">
          <a class="button button-primary" href="<?= lex_e(lex_nav_href('client/appointment.php')) ?>">Book appointment</a>
          <a class="button button-secondary" href="<?= lex_e(lex_nav_href('client/lawyers.php')) ?>">View lawyers</a>
        </div>
      <?php endif; ?>
    </article>
  </section>
</div>
<?php lex_page_footer(); ?>
