<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$lawyerId = lex_user_lawyer_id((int) $user['id']);
$caseId = lex_sanitize_int($_GET['case_id'] ?? 0);
$appBaseUrl = lex_app_url('');
$returnToInput = trim(lex_sanitize_text($_GET['return_to'] ?? ''));
$returnTo = str_starts_with($returnToInput, $appBaseUrl)
    ? $returnToInput
    : lex_app_url('lawyer/messages.php');

$profileRows = $caseId > 0
    ? lex_recent(
        'SELECT c.id AS case_id, c.case_number, c.title, c.status, c.priority, c.filed_date, c.closed_date,
                cl.id AS client_id, cl.user_id AS client_user_id, cl.contact_number, cl.address, cl.risk_level,
                u.full_name, u.email, u.avatar_stored_name, u.created_at
         FROM cases c
         JOIN clients cl ON cl.id = c.client_id
         JOIN users u ON u.id = cl.user_id
         WHERE c.id = :case_id
           AND c.lawyer_id = :lawyer_id
         LIMIT 1',
        ['case_id' => $caseId, 'lawyer_id' => $lawyerId]
    )
    : [];

$profile = $profileRows[0] ?? null;

if (!$profile) {
    http_response_code(404);
    lex_page_header('Client Profile', 'messages', $user);
    ?>
    <section class="card">
      <div class="empty-state">
        <h2>Client profile unavailable</h2>
        <p class="muted">The selected client could not be found, or this case is not assigned to your account.</p>
        <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/messages.php')) ?>">Back to Messages</a>
      </div>
    </section>
    <?php
    lex_page_footer();
    exit;
}

$clientId = (int) $profile['client_id'];
$clientUserId = (int) $profile['client_user_id'];
$fullName = (string) ($profile['full_name'] ?? 'Client');
$email = (string) ($profile['email'] ?? '');
$contactNumber = (string) ($profile['contact_number'] ?? '');
$address = (string) ($profile['address'] ?? '');
$riskLevel = ucfirst((string) ($profile['risk_level'] ?? 'low'));
$avatarUrl = lex_profile_avatar_url((string) ($profile['avatar_stored_name'] ?? ''));
$profileInitials = strtoupper(substr(preg_replace('/\s+/', '', $fullName) ?: 'CL', 0, 2));
$clientCode = 'CLI-' . str_pad((string) $clientId, 6, '0', STR_PAD_LEFT);

try {
    $memberSince = !empty($profile['created_at'])
        ? (new DateTimeImmutable((string) $profile['created_at']))->format('M j, Y')
        : 'Recently joined';
} catch (Throwable $e) {
    $memberSince = 'Recently joined';
}

$relatedCases = lex_recent(
    'SELECT id, case_number, title, status, priority, filed_date
     FROM cases
     WHERE client_id = :client_id
       AND lawyer_id = :lawyer_id
     ORDER BY filed_date DESC, id DESC
     LIMIT 6',
    ['client_id' => $clientId, 'lawyer_id' => $lawyerId]
);

$caseCount = lex_stats(
    'SELECT COUNT(*) FROM cases WHERE client_id = :client_id AND lawyer_id = :lawyer_id',
    ['client_id' => $clientId, 'lawyer_id' => $lawyerId]
);
$appointmentCount = lex_stats(
    'SELECT COUNT(*)
     FROM appointments
     WHERE client_id = :client_id
       AND lawyer_id = :lawyer_id
       AND status <> "deleted"',
    ['client_id' => $clientId, 'lawyer_id' => $lawyerId]
);
$upcomingAppointmentCount = lex_stats(
    'SELECT COUNT(*)
     FROM appointments
     WHERE client_id = :client_id
       AND lawyer_id = :lawyer_id
       AND scheduled_at >= NOW()
       AND status IN ("pending", "confirmed")',
    ['client_id' => $clientId, 'lawyer_id' => $lawyerId]
);

lex_page_header('Client Profile', 'messages', $user);
?>
<section class="law-profile-shell law-profile-shell--client">
  <div class="law-profile-grid">
    <article class="law-profile-summary-card">
      <div class="law-profile-avatar-wrap">
        <?php if ($avatarUrl !== ''): ?>
          <img class="law-profile-avatar" src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e($fullName) ?>">
        <?php else: ?>
          <div class="law-profile-avatar law-profile-avatar--fallback"><?= lex_e($profileInitials) ?></div>
        <?php endif; ?>
      </div>
      <h3><?= lex_e($fullName) ?></h3>
      <p>Client</p>
      <span class="law-profile-pill law-profile-pill--risk">Risk: <?= lex_e($riskLevel) ?></span>

      <div class="law-profile-divider" aria-hidden="true"></div>

      <div class="law-profile-mini-list">
        <div class="law-profile-mini-item">
          <span class="law-profile-mini-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Zm11.25 6h-12v8.25c0 .41.34.75.75.75h10.5c.41 0 .75-.34.75-.75V9.5Z" fill="currentColor"/></svg>
          </span>
          <div><span>Member Since</span><strong><?= lex_e($memberSince) ?></strong></div>
        </div>
        <div class="law-profile-mini-item">
          <span class="law-profile-mini-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M6.75 3.5h10.5A1.75 1.75 0 0 1 19 5.25v13.5A1.75 1.75 0 0 1 17.25 20H6.75A1.75 1.75 0 0 1 5 18.75V5.25A1.75 1.75 0 0 1 6.75 3.5Zm2 4.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 4a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 4a.75.75 0 0 0 0 1.5h3.5a.75.75 0 0 0 0-1.5h-3.5Z" fill="currentColor"/></svg>
          </span>
          <div><span>Client ID</span><strong><?= lex_e($clientCode) ?></strong></div>
        </div>
      </div>
    </article>

    <article class="law-profile-info-card">
      <div class="law-profile-section-head">
        <div>
          <span class="law-profile-section-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 3.5a3.75 3.75 0 1 1 0 7.5 3.75 3.75 0 0 1 0-7.5Zm0 9c3.45 0 6.25 1.95 6.25 4.35a.75.75 0 0 1-1.5 0c0-1.33-2.05-2.85-4.75-2.85s-4.75 1.52-4.75 2.85a.75.75 0 0 1-1.5 0c0-2.4 2.8-4.35 6.25-4.35Z" fill="currentColor"/></svg>
          </span>
          <h3>Client Verification</h3>
        </div>
        <a class="law-profile-edit-button" href="<?= lex_e($returnTo) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.6 5.4a.75.75 0 0 1 0 1.06L6.56 10.5H19a.75.75 0 0 1 0 1.5H6.56l4.04 4.04a.75.75 0 1 1-1.06 1.06l-5.32-5.32a.75.75 0 0 1 0-1.06L9.54 5.4a.75.75 0 0 1 1.06 0Z" fill="currentColor"/></svg>
          Back to Conversation
        </a>
      </div>

      <div class="law-profile-detail-list">
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75A1.75 1.75 0 0 1 4.75 5Zm.45 2 6.8 5.1L18.8 7H5.2Z" fill="currentColor"/></svg></span>
          <div><span>Email Address</span><strong><?= lex_e($email !== '' ? $email : 'Not provided') ?></strong></div>
        </div>
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6.62 3.75h2.3c.58 0 1.08.4 1.21.96l.55 2.38c.11.47-.06.96-.44 1.26l-1.25.98a10.1 10.1 0 0 0 5.68 5.68l.98-1.25c.3-.38.79-.55 1.26-.44l2.38.55c.56.13.96.63.96 1.21v2.3c0 1.03-.83 1.87-1.86 1.87C10.86 19.25 4.75 13.14 4.75 5.61c0-1.03.84-1.86 1.87-1.86Z" fill="currentColor"/></svg></span>
          <div><span>Contact Number</span><strong><?= lex_e($contactNumber !== '' ? $contactNumber : 'Not provided') ?></strong></div>
        </div>
        <div class="law-profile-detail-item law-profile-detail-item--wide">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2.75a7.25 7.25 0 0 1 7.25 7.25c0 5.15-7.25 11.25-7.25 11.25S4.75 15.15 4.75 10A7.25 7.25 0 0 1 12 2.75Zm0 4.5a2.75 2.75 0 1 0 0 5.5 2.75 2.75 0 0 0 0-5.5Z" fill="currentColor"/></svg></span>
          <div><span>Address</span><strong><?= lex_e($address !== '' ? $address : 'Not provided') ?></strong></div>
        </div>
      </div>
    </article>
  </div>

  <article class="law-profile-actions-card">
    <div class="law-profile-actions-head">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75A1.75 1.75 0 0 1 4.75 5Z" fill="currentColor"/></svg>
      <h3>Case Relationship</h3>
    </div>
    <div class="law-profile-action-grid">
      <div class="law-profile-action">
        <span class="tone-blue"><svg viewBox="0 0 24 24"><path d="M4.75 5h5l2 2h7.5A1.75 1.75 0 0 1 21 8.75v8.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75A1.75 1.75 0 0 1 4.75 5Z" fill="currentColor"/></svg></span>
        <div><strong><?= number_format($caseCount) ?> Case<?= $caseCount === 1 ? '' : 's' ?></strong><small>Assigned to your lawyer account</small></div>
      </div>
      <div class="law-profile-action">
        <span class="tone-gold"><svg viewBox="0 0 24 24"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Z" fill="currentColor"/></svg></span>
        <div><strong><?= number_format($appointmentCount) ?> Appointment<?= $appointmentCount === 1 ? '' : 's' ?></strong><small><?= number_format($upcomingAppointmentCount) ?> upcoming or pending</small></div>
      </div>
    </div>
  </article>

  <article class="law-profile-info-card">
    <div class="law-profile-section-head">
      <div>
        <span class="law-profile-section-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M4.75 5h5l2 2h7.5A1.75 1.75 0 0 1 21 8.75v8.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75A1.75 1.75 0 0 1 4.75 5Z" fill="currentColor"/></svg>
        </span>
        <h3>Related Cases</h3>
      </div>
    </div>
    <div class="law-profile-detail-list">
      <?php foreach ($relatedCases as $case): ?>
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v12.5A1.75 1.75 0 0 1 18.25 20H5.75A1.75 1.75 0 0 1 4 18.25V5.75A1.75 1.75 0 0 1 5.75 4Zm2.5 4.25a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 4a.75.75 0 0 0 0 1.5h5.5a.75.75 0 0 0 0-1.5h-5.5Z" fill="currentColor"/></svg></span>
          <div>
            <span><?= lex_e((string) $case['case_number']) ?> - <?= lex_e(ucfirst((string) $case['status'])) ?></span>
            <strong><?= lex_e((string) $case['title']) ?></strong>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </article>
</section>

<?php lex_page_footer(); ?>
