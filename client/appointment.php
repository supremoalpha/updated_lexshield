<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);
$message = '';
$error = '';
$selectedLawyerId = lex_sanitize_int($_GET['lawyer_id'] ?? 0);
$selectedDate = '';
$selectedTime = '';
$selectedAppointmentType = 'Client Intake Consultation';
$selectedNotes = '';

$formatAppointmentDate = static function (?string $value): string {
    if (!$value) {
        return 'Not scheduled';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
};

$formatAppointmentTime = static function (?string $value): string {
    if (!$value) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('g:i A');
    } catch (Throwable $e) {
        return '';
    }
};

$appointmentStatusClass = static function (string $status): string {
    return match (strtolower(trim($status))) {
        'confirmed' => 'is-confirmed',
        'pending' => 'is-pending',
        'cancelled' => 'is-cancelled',
        default => 'is-neutral',
    };
};

$appointmentStatusLabel = static function (string $status): string {
    $normalized = strtolower(trim($status));
    return $normalized !== '' ? ucwords(str_replace(['_', '-'], ' ', $normalized)) : 'Unknown';
};

$availableLawyers = lex_recent(
     'SELECT l.id, u.full_name, l.specialization
      FROM lawyers l
      JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.status = "active"
      ORDER BY u.full_name ASC'
  );

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $lawyerId = lex_sanitize_int($_POST['lawyer_id'] ?? 0);
    $selectedLawyerId = $lawyerId;
    $scheduledDate = lex_sanitize_text($_POST['scheduled_date'] ?? '');
    $scheduledTime = lex_sanitize_text($_POST['scheduled_time'] ?? '');
    $selectedDate = $scheduledDate;
    $selectedTime = $scheduledTime;
    $scheduledAt = ($scheduledDate !== '' && $scheduledTime !== '') ? $scheduledDate . ' ' . $scheduledTime : '';
    $appointmentType = lex_sanitize_text($_POST['appointment_type'] ?? 'Client Intake Consultation');
    $allowedAppointmentTypes = ['Client Intake Consultation', 'Document Review', 'Case Follow-up', 'Legal Advice'];
    if (!in_array($appointmentType, $allowedAppointmentTypes, true)) {
        $appointmentType = 'Client Intake Consultation';
    }
    $selectedAppointmentType = $appointmentType;
    $notes = lex_sanitize_multiline_text($_POST['notes'] ?? '');
    $selectedNotes = $notes;

    $stmt = $pdo->prepare('
        SELECT l.id
        FROM lawyers l
        JOIN users u ON u.id = l.user_id
        WHERE l.id = :lawyer_id
          AND u.is_active = 1
          AND l.status = "active"
        LIMIT 1
    ');
    $stmt->execute(['lawyer_id' => $lawyerId]);
    $lawyerExists = (int) ($stmt->fetchColumn() ?: 0);

    $scheduledDateTime = null;
    if ($scheduledAt !== '') {
        try {
            $scheduledDateTime = new DateTimeImmutable($scheduledAt);
        } catch (Throwable $e) {
            $scheduledDateTime = null;
        }
    }

    $availConflict = false;
    $doubleBooked = false;
    if ($lawyerExists && $scheduledDateTime && $scheduledDateTime > new DateTimeImmutable()) {
        $lawyerOpen = true;
        if (function_exists('lex_availability_date_is_open')) {
            $lawyerOpen = lex_availability_date_is_open($lawyerId, $scheduledDateTime);
        } elseif (function_exists('lex_availability_is_available')) {
            $lawyerOpen = lex_availability_is_available($lawyerId, $scheduledDateTime);
        }
        if (!$lawyerOpen) {
            $availConflict = true;
        }
        if (function_exists('lex_appointment_has_conflict') && lex_appointment_has_conflict($lawyerId, $scheduledDateTime)) {
            $doubleBooked = true;
        }
    }

    if (!$lawyerExists) {
        $error = 'Select an available lawyer.';
    } elseif (!$scheduledDateTime) {
        $error = 'Select a valid appointment date and time.';
    } elseif ($scheduledDateTime <= new DateTimeImmutable()) {
        $error = 'Choose a future date and time for the appointment.';
    } elseif ($availConflict) {
        $error = 'The lawyer is not available on that day or time. Please choose a different schedule.';
    } elseif ($doubleBooked) {
        $error = 'That time slot is already booked. Please choose a different time.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                SELECT id
                FROM cases
                WHERE client_id = :client_id
                  AND lawyer_id = :lawyer_id
                ORDER BY CASE WHEN status IN ("open", "ongoing") THEN 0 ELSE 1 END, id DESC
                LIMIT 1
            ');
            $stmt->execute([
                'client_id' => $clientId,
                'lawyer_id' => $lawyerId,
            ]);
            $caseId = (int) ($stmt->fetchColumn() ?: 0);

            if (!$caseId) {
                do {
                    $caseNumber = 'INTAKE-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
                    $stmt = $pdo->prepare('SELECT 1 FROM cases WHERE case_number = :case_number LIMIT 1');
                    $stmt->execute(['case_number' => $caseNumber]);
                } while ($stmt->fetchColumn());

                $pdo->prepare(
                    'INSERT INTO cases (case_number, title, description, lawyer_id, client_id, status, priority, filed_date, closed_date)
                     VALUES (:case_number, :title, :description, :lawyer_id, :client_id, "open", "normal", CURDATE(), NULL)'
                )->execute([
                    'case_number' => $caseNumber,
                    'title' => $appointmentType !== '' ? $appointmentType : 'Client Intake Consultation',
                    'description' => $notes !== '' ? $notes : 'Client appointment request created automatically.',
                    'lawyer_id' => $lawyerId,
                    'client_id' => $clientId,
                ]);
                $caseId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'INSERT INTO appointments (case_id, client_id, lawyer_id, scheduled_at, appointment_type, status, notes)
                 VALUES (:case_id, :client_id, :lawyer_id, :scheduled_at, :appointment_type, "pending", :notes)'
            )->execute([
                'case_id' => $caseId,
                'client_id' => $clientId,
                'lawyer_id' => $lawyerId,
                'scheduled_at' => $scheduledAt,
                'appointment_type' => $appointmentType,
                'notes' => $notes,
            ]);
            $appointmentId = (string) $pdo->lastInsertId();
            lex_audit('book_appointment', 'appointments', $appointmentId);
            $pdo->commit();
            try {
                $lawyerUserStmt = $pdo->prepare('SELECT user_id FROM lawyers WHERE id = :id LIMIT 1');
                $lawyerUserStmt->execute(['id' => $lawyerId]);
                $lawyerUserId = (int) $lawyerUserStmt->fetchColumn();
                if ($lawyerUserId > 0) {
                    $whenLabel = $scheduledDateTime->format('M j, Y g:i A');
                    lex_notify(
                        $lawyerUserId,
                        'appointment',
                        'New appointment request from ' . (string) ($user['full_name'] ?? 'a client') . ' on ' . $whenLabel . '.'
                    );
                    if (function_exists('lex_email_notify_appointment')) {
                        lex_email_notify_appointment($lawyerUserId, 'New appointment request', 'You have a new appointment request from ' . (string) ($user['full_name'] ?? 'a client') . '. Log in to review and confirm.');
                    }
                }
            } catch (Throwable $e) {}
            $message = 'Appointment request submitted. Your lawyer will review it and confirm the schedule.';
            $selectedDate = '';
            $selectedTime = '';
            $selectedAppointmentType = 'Client Intake Consultation';
            $selectedNotes = '';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Client appointment booking failed: ' . $e->getMessage());
            $error = 'Unable to book the appointment. Please try again.';
        }
    }
}

$appointments = lex_recent(
    'SELECT a.*, c.case_number, COALESCE(NULLIF(a.appointment_type, ""), NULLIF(c.title, ""), "Appointment Request") AS appointment_title, u.full_name AS lawyer_name, u.avatar_stored_name AS lawyer_avatar_stored_name
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     JOIN lawyers l ON l.id = a.lawyer_id
     JOIN users u ON u.id = l.user_id
     WHERE a.client_id = :id
       AND a.status <> "deleted"
     ORDER BY a.scheduled_at DESC',
    ['id' => $clientId]
);

lex_page_header('Appointments', 'appointments', $user);
?>
<section class="client-appointment-page" data-client-appointment-page>
<section class="card client-appointment-card">
  <div class="client-appointment-panel-head">
    <div>
      <h2>Schedule Consultation</h2>
      <p>Book an appointment with your lawyer.</p>
    </div>
  </div>
  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
  <?php if (!empty($availableLawyers)): ?>
    <form method="post" class="client-appointment-form" data-client-appointment-form data-loading-text="Requesting...">
      <?= lex_csrf_field() ?>
      <div class="client-appointment-form-main">
        <label class="client-appointment-field client-appointment-field--full">Choose Lawyer
          <span class="client-appointment-control">
            <span class="client-appointment-control-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M10.5 4a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Zm0 1.5a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm4.2 9.26 4.02 4.02-1.06 1.06-4.02-4.02 1.06-1.06Z" fill="currentColor"/></svg>
            </span>
            <select name="lawyer_id" required data-appointment-lawyer-select>
              <option value="">Choose a lawyer</option>
              <?php foreach ($availableLawyers as $lawyer): ?>
                <?php $lawyerSpecialization = trim((string) ($lawyer['specialization'] ?? '')); ?>
                <option value="<?= (int) $lawyer['id'] ?>" data-specialization="<?= lex_e($lawyerSpecialization !== '' ? $lawyerSpecialization : 'General Practice') ?>"<?= (int) $lawyer['id'] === $selectedLawyerId ? ' selected' : '' ?>><?= lex_e($lawyer['full_name'] . ($lawyerSpecialization !== '' ? ' - ' . $lawyerSpecialization : '')) ?></option>
              <?php endforeach; ?>
            </select>
          </span>
        </label>
        <label class="client-appointment-field">Scheduled Date
          <span class="client-appointment-control">
            <span class="client-appointment-control-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Zm11.25 6h-12v8.25c0 .41.34.75.75.75h10.5c.41 0 .75-.34.75-.75V9.5Z" fill="currentColor"/></svg>
            </span>
            <input type="date" name="scheduled_date" min="<?= lex_e(date('Y-m-d')) ?>" value="<?= lex_e($selectedDate) ?>" required data-appointment-date>
          </span>
        </label>
        <label class="client-appointment-field">Time
          <span class="client-appointment-control">
            <span class="client-appointment-control-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm.75 4.25h-1.5v5.25l4.1 2.46.78-1.29-3.38-2.02V7.5Z" fill="currentColor"/></svg>
            </span>
            <input type="time" name="scheduled_time" value="<?= lex_e($selectedTime) ?>" required data-appointment-time>
          </span>
        </label>
        <label class="client-appointment-field client-appointment-field--full">Appointment Type
          <span class="client-appointment-control">
            <span class="client-appointment-control-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M6.75 4h10.5A1.75 1.75 0 0 1 19 5.75v12.5A1.75 1.75 0 0 1 17.25 20H6.75A1.75 1.75 0 0 1 5 18.25V5.75A1.75 1.75 0 0 1 6.75 4Zm2 4.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 4a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" fill="currentColor"/></svg>
            </span>
            <select name="appointment_type" required data-appointment-type>
              <?php foreach (['Client Intake Consultation', 'Document Review', 'Case Follow-up', 'Legal Advice'] as $type): ?>
                <option value="<?= lex_e($type) ?>"<?= $selectedAppointmentType === $type ? ' selected' : '' ?>><?= lex_e($type) ?></option>
              <?php endforeach; ?>
            </select>
          </span>
        </label>
      </div>
      <div class="client-appointment-form-side">
        <label class="client-appointment-field">Notes <span>(Optional)</span>
          <textarea name="notes" rows="6" maxlength="500" placeholder="Add any notes or details about your appointment..."><?= lex_e($selectedNotes) ?></textarea>
        </label>
        <div class="client-appointment-summary" aria-live="polite">
          <span>Request summary</span>
          <strong data-appointment-summary-lawyer>Choose a lawyer</strong>
          <small data-appointment-summary-meta>Select a date and time to preview this request.</small>
        </div>
        <button class="button button-primary client-appointment-submit" type="submit">
          <span aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Zm11.25 6h-12v8.25c0 .41.34.75.75.75h10.5c.41 0 .75-.34.75-.75V9.5Z" fill="currentColor"/></svg>
          </span>
          Request Appointment
        </button>
      </div>
    </form>
  <?php else: ?>
    <p class="muted">No active lawyers are available right now.</p>
  <?php endif; ?>
</section>

<section class="card client-appointment-requests-card">
  <div class="card-head client-appointment-requests-head">
    <div>
      <h2>Appointment Requests</h2>
      <p class="muted client-appointment-requests-note">This page shows your appointment requests and their current status.</p>
    </div>
    <span class="pill"><?= count($appointments) ?> Total</span>
  </div>
  <div class="table-wrap">
    <table class="data-table client-appointment-requests-table">
      <thead><tr><th>Case</th><th>Lawyer</th><th>Scheduled</th><th>Status</th><th>Notes</th><th>Video Call</th></tr></thead>
      <tbody>
      <?php foreach ($appointments as $appointment): ?>
        <?php
          $lawyerName = (string) ($appointment['lawyer_name'] ?? 'Lawyer');
          $lawyerAvatarUrl = lex_profile_avatar_url((string) ($appointment['lawyer_avatar_stored_name'] ?? ''));
          $appointmentNotes = trim((string) ($appointment['notes'] ?? ''));
        ?>
        <tr>
          <td data-label="Case">
            <strong class="client-appointment-case-title"><?= lex_e((string) $appointment['appointment_title']) ?></strong>
          </td>
          <td data-label="Lawyer">
            <div class="client-appointment-lawyer-cell">
              <?php if ($lawyerAvatarUrl !== ''): ?>
                <img class="client-appointment-lawyer-avatar" src="<?= lex_e($lawyerAvatarUrl) ?>" alt="Avatar for <?= lex_e($lawyerName) ?>">
              <?php else: ?>
                <span class="client-appointment-lawyer-avatar client-appointment-lawyer-avatar--fallback" aria-hidden="true"><?= lex_e(strtoupper(substr($lawyerName, 0, 1))) ?></span>
              <?php endif; ?>
              <strong><?= lex_e($lawyerName) ?></strong>
            </div>
          </td>
          <td data-label="Scheduled">
            <strong class="client-appointment-date"><?= lex_e($formatAppointmentDate((string) $appointment['scheduled_at'])) ?></strong>
            <span class="client-appointment-time"><?= lex_e($formatAppointmentTime((string) $appointment['scheduled_at'])) ?></span>
          </td>
          <td data-label="Status"><span class="appointment-badge <?= lex_e($appointmentStatusClass((string) $appointment['status'])) ?>"><?= lex_e($appointmentStatusLabel((string) $appointment['status'])) ?></span></td>
          <td data-label="Notes">
            <button
              class="client-appointment-note-button"
              type="button"
              data-client-note-open
              data-note="<?= lex_e($appointmentNotes !== '' ? $appointmentNotes : 'No notes were added for this appointment.') ?>"
              aria-label="Read appointment notes"
              title="Read notes"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M6.75 3.75h10.5A2.25 2.25 0 0 1 19.5 6v12a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 18V6a2.25 2.25 0 0 1 2.25-2.25Zm1.5 4.5a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.25a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.25a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" fill="currentColor"/>
              </svg>
            </button>
          </td>
          <td data-label="Video Call">
            <?php if (strtolower((string) $appointment['status']) === 'confirmed'): ?>
              <a class="button button-primary" href="<?= lex_e(lex_app_url('client/video-call.php') . '?appointment_id=' . (int) $appointment['id']) ?>">
                Join call
              </a>
            <?php else: ?>
              <span class="muted">Available once confirmed</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$appointments): ?>
        <tr><td colspan="6" class="muted">No appointment requests yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="modal-overlay client-appointment-note-modal" data-client-note-modal aria-hidden="true">
  <article class="modal-card client-appointment-note-card" role="dialog" aria-modal="true" aria-labelledby="clientAppointmentNoteTitle">
    <header class="modal-header">
      <h2 id="clientAppointmentNoteTitle">Appointment Notes</h2>
      <button class="close-button" type="button" data-client-note-close aria-label="Close notes">&times;</button>
    </header>
    <div class="modal-body">
      <p class="client-appointment-note-text" data-client-note-text></p>
    </div>
  </article>
</div>
</section>
<?php lex_page_footer(); ?>
