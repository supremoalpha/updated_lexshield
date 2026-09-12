<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
$lawyerId = lex_user_lawyer_id((int) $user['id']);

$profile = lex_recent(
    'SELECT l.bar_number, l.specialization, l.status, l.bio, l.background, l.contact_number, u.full_name, u.email, u.password_hash, u.avatar_stored_name, u.created_at
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE l.id = :id
     LIMIT 1',
    ['id' => $lawyerId]
);
$profile = $profile[0] ?? [
    'bar_number' => '',
    'specialization' => '',
    'status' => 'active',
    'bio' => '',
    'background' => '',
    'contact_number' => '',
    'full_name' => $user['full_name'] ?? 'Lawyer',
    'email' => $user['email'] ?? '',
    'password_hash' => '',
    'avatar_stored_name' => '',
    'created_at' => '',
];

$error = '';

$fullName = (string) ($profile['full_name'] ?? '');
$email = (string) ($profile['email'] ?? '');
$specialization = (string) ($profile['specialization'] ?? '');
$status = (string) ($profile['status'] ?? 'active');
$bio = (string) ($profile['bio'] ?? '');
$background = (string) ($profile['background'] ?? '');
$contactNumber = (string) ($profile['contact_number'] ?? '');
$avatarStoredName = (string) ($profile['avatar_stored_name'] ?? '');
$avatarUrl = lex_profile_avatar_url($avatarStoredName);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('lawyer/profile.php');
        $error = 'Invalid CSRF token.';
    } else {
        $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
        $email = lex_sanitize_email($_POST['email'] ?? '');
        $specialization = lex_sanitize_text($_POST['specialization'] ?? '');
        $status = strtolower(lex_sanitize_text($_POST['status'] ?? 'active'));
        $bio = lex_sanitize_multiline_text($_POST['bio'] ?? '');
        $background = lex_sanitize_multiline_text($_POST['background'] ?? '');
        $contactNumber = lex_sanitize_text($_POST['contact_number'] ?? '');
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $newAvatar = null;

        if ($fullName === '' || $email === '' || $specialization === '') {
            $error = 'Please complete the required profile fields.';
        } elseif (!in_array($status, ['active', 'busy'], true)) {
            $error = 'Choose a valid availability status.';
        } elseif (($newPassword !== '' || $confirmPassword !== '' || $email !== (string) ($profile['email'] ?? '')) && $currentPassword === '') {
            $error = 'Enter your current password to change your email or password.';
        } elseif ($newPassword !== '' && ($passwordError = lex_password_policy_error($newPassword, $email, $fullName)) !== '') {
            $error = $passwordError;
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $stmt->execute(['email' => $email, 'id' => (int) $user['id']]);
            if ($stmt->fetchColumn()) {
                $error = 'That email address is already in use.';
            } elseif (($newPassword !== '' || $email !== (string) ($profile['email'] ?? '')) && !password_verify($currentPassword, (string) ($profile['password_hash'] ?? ''))) {
                $error = 'Current password is incorrect.';
            } else {
                try {
                    $newAvatar = lex_store_profile_avatar($_FILES['avatar_image'] ?? []);
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                }

                if ($error === '') {
                    $oldAvatar = $avatarStoredName;
                    $oldEmail = (string) ($profile['email'] ?? '');
                    $emailChanged = $email !== $oldEmail;
                    $passwordChanged = $newPassword !== '';
                    $profileChanged = $fullName !== (string) ($profile['full_name'] ?? '')
                        || $specialization !== (string) ($profile['specialization'] ?? '')
                        || $status !== (string) ($profile['status'] ?? 'active')
                        || $bio !== (string) ($profile['bio'] ?? '')
                        || $background !== (string) ($profile['background'] ?? '')
                        || $contactNumber !== (string) ($profile['contact_number'] ?? '')
                        || (bool) $newAvatar;
                    $pdo->beginTransaction();
                    try {
                    $updateUsers = 'UPDATE users SET full_name = :full_name, email = :email';
                    $params = [
                        'full_name' => $fullName,
                        'email' => $email,
                        'id' => (int) $user['id'],
                    ];
                    if ($newPassword !== '') {
                        $updateUsers .= ', password_hash = :password_hash';
                        $params['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                    }
                    if ($newAvatar) {
                        $updateUsers .= ', avatar_stored_name = :avatar_stored_name';
                        $params['avatar_stored_name'] = $newAvatar['stored_name'];
                    }
                    $updateUsers .= ' WHERE id = :id';
                    $pdo->prepare($updateUsers)->execute($params);

                    $pdo->prepare(
                        'UPDATE lawyers
                         SET specialization = :specialization, status = :status, bio = :bio, background = :background, contact_number = :contact_number
                         WHERE id = :id'
                    )->execute([
                        'specialization' => $specialization,
                        'status' => $status,
                        'bio' => $bio,
                        'background' => $background,
                        'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                        'id' => $lawyerId,
                    ]);

                    lex_audit('update_lawyer_profile', 'lawyers', (string) $lawyerId);
                    if ($emailChanged) {
                        lex_audit('changed_email', 'users', (string) $user['id'], (int) $user['id']);
                    }
                    if ($passwordChanged) {
                        lex_audit('changed_password', 'users', (string) $user['id'], (int) $user['id']);
                    }
                    $pdo->commit();
                    if ($emailChanged) {
                        lex_send_security_event_notification([
                            'full_name' => $fullName,
                            'email' => $oldEmail,
                        ], 'LEXSHIELD email address changed', 'The email address on your LEXSHIELD account was changed to ' . $email . '.', 'Your LEXSHIELD account email address was changed.');
                        lex_send_security_event_notification([
                            'id' => (int) $user['id'],
                            'full_name' => $fullName,
                            'email' => $email,
                        ], 'LEXSHIELD email address changed', 'This email address is now used for your LEXSHIELD account.', 'Your LEXSHIELD account email address was changed.');
                    }
                    if ($passwordChanged) {
                        lex_send_security_event_notification([
                            'id' => (int) $user['id'],
                            'full_name' => $fullName,
                            'email' => $email,
                        ], 'LEXSHIELD password changed', 'Your LEXSHIELD account password was changed successfully.', 'Your LEXSHIELD password was changed.');
                    }
                    if ($profileChanged) {
                        lex_send_security_event_notification([
                            'id' => (int) $user['id'],
                            'full_name' => $fullName,
                            'email' => $email,
                        ], 'LEXSHIELD profile updated', 'Your LEXSHIELD lawyer profile details were updated.', 'Your LEXSHIELD profile details were updated.');
                    }
                    if ($newAvatar && $oldAvatar !== '' && $oldAvatar !== $newAvatar['stored_name']) {
                        lex_profile_avatar_remove($oldAvatar);
                    }
                    lex_flash_set('success', 'Lawyer profile updated successfully.');
                    header('Location: ' . lex_app_url('lawyer/profile.php'));
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($newAvatar && is_file($newAvatar['path'])) {
                        @unlink($newAvatar['path']);
                    }
                    $error = 'Unable to update the profile right now.';
                }
                }
            }
        }
    }
}

$profileInitials = strtoupper(substr(preg_replace('/\s+/', '', (string) ($fullName ?: 'LW')) ?: 'LW', 0, 2));
$memberSince = 'Recently joined';
try {
    if (!empty($profile['created_at'])) {
        $memberSince = (new DateTimeImmutable((string) $profile['created_at']))->format('M j, Y');
    }
} catch (Throwable $e) {
    $memberSince = (string) ($profile['created_at'] ?? 'Recently joined');
}
$lawyerCode = 'LW-' . str_pad((string) $lawyerId, 6, '0', STR_PAD_LEFT);
$barNumber = (string) ($profile['bar_number'] ?? '');

lex_page_header('Lawyer Profile', 'profile', $user);
?>
<section class="law-profile-shell law-profile-shell--lawyer">
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
      <p><?= lex_e($specialization !== '' ? $specialization : 'Lawyer') ?></p>
      <span class="law-profile-pill <?= $status === 'busy' ? 'law-profile-pill--busy' : 'law-profile-pill--active' ?>"><?= lex_e(ucfirst($status)) ?></span>

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
          <div><span>Lawyer ID</span><strong><?= lex_e($lawyerCode) ?></strong></div>
        </div>
      </div>
    </article>

    <article class="law-profile-info-card">
      <div class="law-profile-section-head">
        <div>
          <span class="law-profile-section-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 3.5a3.75 3.75 0 1 1 0 7.5 3.75 3.75 0 0 1 0-7.5Zm0 9c3.45 0 6.25 1.95 6.25 4.35a.75.75 0 0 1-1.5 0c0-1.33-2.05-2.85-4.75-2.85s-4.75 1.52-4.75 2.85a.75.75 0 0 1-1.5 0c0-2.4 2.8-4.35 6.25-4.35Z" fill="currentColor"/></svg>
          </span>
          <h3>Professional Information</h3>
        </div>
        <button class="law-profile-edit-button" type="button" data-profile-edit-open>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16.6V19h2.4l8.85-8.85-2.4-2.4L5 16.6Zm12.1-7.6 1.15-1.15a1.2 1.2 0 0 0 0-1.7l-.4-.4a1.2 1.2 0 0 0-1.7 0L15 6.9 17.1 9Z" fill="currentColor"/></svg>
          Edit Profile
        </button>
      </div>

      <div class="law-profile-detail-list">
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75A1.75 1.75 0 0 1 4.75 5Zm.45 2 6.8 5.1L18.8 7H5.2Z" fill="currentColor"/></svg></span>
          <div><span>Email Address</span><strong><?= lex_e($email) ?></strong></div>
        </div>
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v12.5A1.75 1.75 0 0 1 18.25 20H5.75A1.75 1.75 0 0 1 4 18.25V5.75A1.75 1.75 0 0 1 5.75 4Zm2.5 4.25a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 4a.75.75 0 0 0 0 1.5h5.5a.75.75 0 0 0 0-1.5h-5.5Z" fill="currentColor"/></svg></span>
          <div><span>Bar Number</span><strong><?= lex_e($barNumber !== '' ? $barNumber : 'Not provided') ?></strong></div>
        </div>
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6.62 3.75h2.3c.58 0 1.08.4 1.21.96l.55 2.38c.11.47-.06.96-.44 1.26l-1.25.98a10.1 10.1 0 0 0 5.68 5.68l.98-1.25c.3-.38.79-.55 1.26-.44l2.38.55c.56.13.96.63.96 1.21v2.3c0 1.03-.83 1.87-1.86 1.87C10.86 19.25 4.75 13.14 4.75 5.61c0-1.03.84-1.86 1.87-1.86Z" fill="currentColor"/></svg></span>
          <div><span>Phone Number</span><strong><?= lex_e($contactNumber !== '' ? $contactNumber : 'Not provided') ?></strong></div>
        </div>
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3.25 3.75 7.5 12 11.75l8.25-4.25L12 3.25Zm-6.75 7.1v5.15L12 19.25l6.75-3.75v-5.15L12 14.1 5.25 10.35Z" fill="currentColor"/></svg></span>
          <div><span>Specialization</span><strong><?= lex_e($specialization !== '' ? $specialization : 'Not provided') ?></strong></div>
        </div>
        <div class="law-profile-detail-item law-profile-detail-item--wide">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v8.5A1.75 1.75 0 0 1 18.25 16H9.9l-3.68 3.08A.75.75 0 0 1 5 18.5V16.1a1.75 1.75 0 0 1-1-1.6V5.75A1.75 1.75 0 0 1 5.75 4Z" fill="currentColor"/></svg></span>
          <div><span>Bio</span><strong><?= lex_e($bio !== '' ? $bio : 'Experienced counsel available for consultation and matter updates.') ?></strong></div>
        </div>
      </div>
    </article>
  </div>

  <article class="law-profile-actions-card">
    <div class="law-profile-actions-head">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m13 2-8 11h6l-1 9 9-12h-6l1-8Z" fill="currentColor"/></svg>
      <h3>Quick Actions</h3>
    </div>
    <div class="law-profile-action-grid">
      <a class="law-profile-action" href="<?= lex_e(lex_app_url('case_files.php')) ?>"><span class="tone-blue"><svg viewBox="0 0 24 24"><path d="M4.75 5h5l2 2h7.5A1.75 1.75 0 0 1 21 8.75v8.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75A1.75 1.75 0 0 1 4.75 5Z" fill="currentColor"/></svg></span><div><strong>Case Files</strong><small>Manage assigned matters</small></div><b>&rsaquo;</b></a>
      <a class="law-profile-action" href="<?= lex_e(lex_app_url('lawyer/messages.php')) ?>"><span class="tone-purple"><svg viewBox="0 0 24 24"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v8.5A1.75 1.75 0 0 1 18.25 16H9.9l-3.68 3.08A.75.75 0 0 1 5 18.5V16.1a1.75 1.75 0 0 1-1-1.6V5.75A1.75 1.75 0 0 1 5.75 4Z" fill="currentColor"/></svg></span><div><strong>Messages</strong><small>View client conversations</small></div><b>&rsaquo;</b></a>
      <a class="law-profile-action" href="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>"><span class="tone-gold"><svg viewBox="0 0 24 24"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Z" fill="currentColor"/></svg></span><div><strong>Appointments</strong><small>Manage your schedule</small></div><b>&rsaquo;</b></a>
      <a class="law-profile-action" href="#edit-profile" data-profile-edit-open><span class="tone-green"><svg viewBox="0 0 24 24"><path d="M5 16.6V19h2.4l8.85-8.85-2.4-2.4L5 16.6Zm12.1-7.6 1.15-1.15a1.2 1.2 0 0 0 0-1.7l-.4-.4a1.2 1.2 0 0 0-1.7 0L15 6.9 17.1 9Z" fill="currentColor"/></svg></span><div><strong>Edit Details</strong><small>Update professional info</small></div><b>&rsaquo;</b></a>
    </div>
  </article>
</section>

<div class="modal-overlay" id="profileEditModal" data-modal aria-hidden="true">
  <div class="modal-card wide profile-edit-modal" role="dialog" aria-modal="true" aria-labelledby="profileEditTitle">
    <div class="modal-header">
      <div>
        <h2 id="profileEditTitle">Edit Lawyer Profile</h2>
        <p class="modal-note">Update your professional details and login credentials. Current password is required for email or password changes.</p>
      </div>
      <button class="close-button" type="button" data-profile-edit-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
      <form method="post" class="form-grid" enctype="multipart/form-data">
        <?= lex_csrf_field() ?>
        <label class="full">Profile avatar
          <div class="profile-upload-row">
            <div class="profile-upload-thumb">
              <?php if ($avatarUrl !== ''): ?>
                <img src="<?= lex_e($avatarUrl) ?>" alt="Current avatar for <?= lex_e($fullName) ?>">
              <?php else: ?>
                <span><?= lex_e($profileInitials) ?></span>
              <?php endif; ?>
            </div>
            <div class="profile-upload-field">
              <input type="file" name="avatar_image" accept="image/png,image/jpeg,image/gif,image/webp">
              <p class="modal-note">JPG, PNG, GIF, or WEBP. Max 5 MB.</p>
            </div>
          </div>
        </label>
        <label>Full name
          <input type="text" name="full_name" required value="<?= lex_e($fullName) ?>">
        </label>
        <label>Email
          <input type="email" name="email" required value="<?= lex_e($email) ?>">
        </label>
        <label>Phone number
          <input type="text" name="contact_number" inputmode="tel" autocomplete="tel" placeholder="09xxxxxxxxx" value="<?= lex_e($contactNumber) ?>">
        </label>
        <label>Specialization
          <input type="text" name="specialization" required value="<?= lex_e($specialization) ?>">
        </label>
        <label>Availability
          <select name="status" required>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="busy"<?= $status === 'busy' ? ' selected' : '' ?>>Busy</option>
          </select>
        </label>
        <label>Current password
          <div class="password-field" data-password-toggle>
            <input type="password" name="current_password" autocomplete="current-password" placeholder="Required for email or password changes">
            <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
          </div>
        </label>
        <label class="full">Bio
          <textarea name="bio" rows="4"><?= lex_e($bio) ?></textarea>
        </label>
        <label class="full">Background
          <textarea name="background" rows="4" placeholder="Add your professional background, specialties, or experience."><?= lex_e($background) ?></textarea>
        </label>
        <label>New password
          <div class="password-field" data-password-toggle>
            <input type="password" name="new_password" minlength="10" autocomplete="new-password" placeholder="Leave blank, or use 10+ chars with symbol">
            <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
          </div>
        </label>
        <label>Confirm new password
          <div class="password-field" data-password-toggle>
            <input type="password" name="confirm_password" autocomplete="new-password" placeholder="Repeat new password">
            <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
          </div>
        </label>
        <div class="profile-card-demo__actions full">
          <button class="button button-primary" type="submit">Save changes</button>
          <button class="button button-secondary" type="button" data-profile-edit-close>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('profileEditModal');
  if (!modal) return;
  const openButtons = document.querySelectorAll('[data-profile-edit-open]');
  const closeButtons = modal.querySelectorAll('[data-profile-edit-close]');
  const open = () => {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  };
  const close = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  };
  openButtons.forEach((button) => button.addEventListener('click', open));
  closeButtons.forEach((button) => button.addEventListener('click', close));
  modal.addEventListener('click', (event) => {
    if (event.target === modal) close();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) close();
  });
  if (window.location.hash === '#edit-profile') {
    open();
  }
  <?php if ($error !== ''): ?>
  open();
  <?php endif; ?>
})();
</script>
<?php lex_page_footer(); ?>
