<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);

$profile = lex_recent(
    'SELECT c.contact_number, c.address, c.risk_level, u.full_name, u.email, u.password_hash, u.avatar_stored_name, u.created_at
     FROM clients c
     JOIN users u ON u.id = c.user_id
     WHERE c.id = :id
     LIMIT 1',
    ['id' => $clientId]
);
$profile = $profile[0] ?? [
    'contact_number' => '',
    'address' => '',
    'risk_level' => 'low',
    'full_name' => $user['full_name'] ?? 'Client',
    'email' => $user['email'] ?? '',
    'password_hash' => '',
    'avatar_stored_name' => '',
    'created_at' => '',
];

$error = '';

$fullName = (string) ($profile['full_name'] ?? '');
$email = (string) ($profile['email'] ?? '');
$contactNumber = (string) ($profile['contact_number'] ?? '');
$address = (string) ($profile['address'] ?? '');
$avatarStoredName = (string) ($profile['avatar_stored_name'] ?? '');
$avatarUrl = lex_profile_avatar_url($avatarStoredName);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('client/profile.php');
        $error = 'Invalid CSRF token.';
    } else {
        $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
        $email = lex_sanitize_email($_POST['email'] ?? '');
        $contactNumber = lex_sanitize_text($_POST['contact_number'] ?? '');
        $address = lex_sanitize_text($_POST['address'] ?? '');
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $newAvatar = null;

        if ($fullName === '' || $email === '' || $contactNumber === '') {
            $error = 'Please complete the required profile fields.';
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
                        || $contactNumber !== (string) ($profile['contact_number'] ?? '')
                        || $address !== (string) ($profile['address'] ?? '')
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
                            'UPDATE clients
                             SET contact_number = :contact_number, address = :address
                             WHERE id = :id'
                        )->execute([
                            'contact_number' => $contactNumber,
                            'address' => $address,
                            'id' => $clientId,
                        ]);

                        lex_audit('update_client_profile', 'clients', (string) $clientId);
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
                            ], 'LEXSHIELD profile updated', 'Your LEXSHIELD client profile details were updated.', 'Your LEXSHIELD profile details were updated.');
                        }
                        if ($newAvatar && $oldAvatar !== '' && $oldAvatar !== $newAvatar['stored_name']) {
                            lex_profile_avatar_remove($oldAvatar);
                        }
                        lex_flash_set('success', 'Client profile updated successfully.');
                        header('Location: ' . lex_app_url('client/profile.php'));
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

$profileInitials = strtoupper(substr(preg_replace('/\s+/', '', (string) ($fullName ?: 'CL')) ?: 'CL', 0, 2));
$memberSince = 'Recently joined';
try {
    if (!empty($profile['created_at'])) {
        $memberSince = (new DateTimeImmutable((string) $profile['created_at']))->format('M j, Y');
    }
} catch (Throwable $e) {
    $memberSince = (string) ($profile['created_at'] ?? 'Recently joined');
}
$clientCode = 'CLI-' . str_pad((string) $clientId, 6, '0', STR_PAD_LEFT);
$riskLevel = ucfirst((string) ($profile['risk_level'] ?? 'low'));

lex_page_header('Client Profile', 'profile', $user);
?>
<style>
  .client-profile-layout {
    display: grid !important;
    place-items: start center !important;
    padding: 0.35rem 0 0 !important;
  }

  .client-profile-layout .client-profile-card {
    width: min(100%, 560px) !important;
    max-width: 560px !important;
    margin-inline: auto !important;
    display: grid !important;
    gap: 0.8rem !important;
    padding: 1.2rem !important;
    border-radius: 24px !important;
    border: 1px solid var(--border) !important;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 249, 253, 0.99)) !important;
    box-shadow: var(--shadow) !important;
  }

  html[data-theme="dark"] .client-profile-layout .client-profile-card {
    background: linear-gradient(180deg, rgba(12, 20, 34, 0.98), rgba(10, 16, 28, 0.99)) !important;
  }

  .client-profile-layout .profile-card-demo__avatar-wrap {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
  }

  .client-profile-layout img.profile-card-demo__avatar,
  .client-profile-layout .profile-card-demo__avatar {
    width: 104px !important;
    height: 104px !important;
    min-width: 104px !important;
    max-width: 104px !important;
    border-radius: 26px !important;
    object-fit: cover !important;
    display: block !important;
    margin-inline: auto !important;
  }

  .client-profile-layout .profile-card-demo__avatar:not(img) {
    display: grid !important;
    place-items: center !important;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
    color: #1d4ed8 !important;
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    letter-spacing: 0.06em !important;
  }

  .client-profile-layout .profile-card-demo__header {
    display: grid !important;
    justify-items: center !important;
    text-align: center !important;
    gap: 0.4rem !important;
  }

  .client-profile-layout .profile-card-demo__name {
    margin: 0 !important;
    font-size: clamp(1.55rem, 2vw, 2rem) !important;
    line-height: 1.08 !important;
  }

  .client-profile-layout .profile-card-demo__title {
    margin: 0 !important;
    text-align: center !important;
    padding: 0.38rem 0.78rem !important;
    border-radius: 999px !important;
    background: rgba(10, 22, 40, 0.05) !important;
    font-size: 0.84rem !important;
    letter-spacing: 0.06em !important;
    text-transform: uppercase !important;
  }

  .client-profile-layout .profile-card-demo__bio {
    margin: 0 !important;
    text-align: center !important;
    padding: 0.95rem 1rem !important;
    border-radius: 18px !important;
    background: rgba(10, 22, 40, 0.03) !important;
    line-height: 1.6 !important;
  }

  .client-profile-layout .profile-card-demo__badge {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: fit-content !important;
    min-height: 26px !important;
    padding: 0.22rem 0.62rem !important;
    border-radius: 999px !important;
    font-size: 0.72rem !important;
    line-height: 1 !important;
  }

  .client-profile-layout .profile-card-demo__background,
  .client-profile-layout .profile-card-demo__row {
    display: grid !important;
    gap: 0.35rem !important;
    padding: 0.95rem 1rem !important;
    border: 1px solid var(--border) !important;
    border-radius: 18px !important;
    background: rgba(255,255,255,0.72) !important;
  }

  html[data-theme="dark"] .client-profile-layout .profile-card-demo__background,
  html[data-theme="dark"] .client-profile-layout .profile-card-demo__row {
    background: rgba(255,255,255,0.03) !important;
  }

  .client-profile-layout .profile-card-demo__info {
    display: grid !important;
    gap: 0.75rem !important;
  }

  .client-profile-layout .profile-card-demo__row {
    grid-template-columns: 1.2rem minmax(0, 1fr) !important;
    align-items: center !important;
    gap: 0.85rem !important;
  }

  .client-profile-layout svg.profile-card-demo__icon {
    width: 1.2rem !important;
    height: 1.2rem !important;
    min-width: 1.2rem !important;
    max-width: 1.2rem !important;
    display: block !important;
  }

  .client-profile-layout .profile-card-demo__row-body {
    display: grid !important;
    gap: 0.18rem !important;
    min-width: 0 !important;
  }

  .client-profile-layout .profile-card-demo__actions {
    display: flex !important;
    justify-content: center !important;
    gap: 0.7rem !important;
    flex-wrap: wrap !important;
    margin-top: 0.1rem !important;
  }

  .client-profile-layout .profile-card-demo__actions .button {
    min-width: 180px !important;
  }

  @media (max-width: 480px) {
    .client-profile-layout .client-profile-card {
      padding: 1rem !important;
    }

    .client-profile-layout img.profile-card-demo__avatar,
    .client-profile-layout .profile-card-demo__avatar {
      width: 92px !important;
      height: 92px !important;
      min-width: 92px !important;
      max-width: 92px !important;
      border-radius: 24px !important;
    }

    .client-profile-layout .profile-card-demo__actions .button {
      width: 100% !important;
      min-width: 0 !important;
    }
  }
</style>
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
          <h3>Personal Information</h3>
        </div>
        <button class="law-profile-edit-button" type="button" data-profile-edit-open>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16.6V19h2.4l8.85-8.85-2.4-2.4L5 16.6Zm12.1-7.6 1.15-1.15a1.2 1.2 0 0 0 0-1.7l-.4-.4a1.2 1.2 0 0 0-1.7 0L15 6.9 17.1 9Z" fill="currentColor"/></svg>
          Edit Profile
        </button>
      </div>

      <div class="law-profile-detail-list">
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2.75a7.25 7.25 0 0 1 7.25 7.25c0 5.15-7.25 11.25-7.25 11.25S4.75 15.15 4.75 10A7.25 7.25 0 0 1 12 2.75Zm0 4.5a2.75 2.75 0 1 0 0 5.5 2.75 2.75 0 0 0 0-5.5Z" fill="currentColor"/></svg></span>
          <div><span>Address</span><strong><?= lex_e($address !== '' ? $address : 'Not provided') ?></strong></div>
        </div>
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75A1.75 1.75 0 0 1 4.75 5Zm.45 2 6.8 5.1L18.8 7H5.2Z" fill="currentColor"/></svg></span>
          <div><span>Email Address</span><strong><?= lex_e($email) ?></strong></div>
        </div>
        <div class="law-profile-detail-item">
          <span class="law-profile-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6.62 3.75h2.3c.58 0 1.08.4 1.21.96l.55 2.38c.11.47-.06.96-.44 1.26l-1.25.98a10.1 10.1 0 0 0 5.68 5.68l.98-1.25c.3-.38.79-.55 1.26-.44l2.38.55c.56.13.96.63.96 1.21v2.3c0 1.03-.83 1.87-1.86 1.87C10.86 19.25 4.75 13.14 4.75 5.61c0-1.03.84-1.86 1.87-1.86Z" fill="currentColor"/></svg></span>
          <div><span>Phone Number</span><strong><?= lex_e($contactNumber !== '' ? $contactNumber : 'Not provided') ?></strong></div>
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
      <a class="law-profile-action" href="<?= lex_e(lex_nav_href('chat.php')) ?>"><span class="tone-purple"><svg viewBox="0 0 24 24"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v8.5A1.75 1.75 0 0 1 18.25 16H9.9l-3.68 3.08A.75.75 0 0 1 5 18.5V16.1a1.75 1.75 0 0 1-1-1.6V5.75A1.75 1.75 0 0 1 5.75 4Z" fill="currentColor"/></svg></span><div><strong>Messages</strong><small>View your conversations</small></div><b>&rsaquo;</b></a>
      <a class="law-profile-action" href="<?= lex_e(lex_app_url('client/appointment.php')) ?>"><span class="tone-gold"><svg viewBox="0 0 24 24"><path d="M7 3.5a.75.75 0 0 1 .75.75V5h8.5v-.75a.75.75 0 0 1 1.5 0V5h.5A2.25 2.25 0 0 1 20.5 7.25v10.5A2.25 2.25 0 0 1 18.25 20h-12.5A2.25 2.25 0 0 1 3.5 17.75V7.25A2.25 2.25 0 0 1 5.75 5h.5v-.75A.75.75 0 0 1 7 3.5Z" fill="currentColor"/></svg></span><div><strong>Appointments</strong><small>Manage your schedule</small></div><b>&rsaquo;</b></a>
      <a class="law-profile-action" href="<?= lex_e(lex_app_url('client/payments.php')) ?>"><span class="tone-green"><svg viewBox="0 0 24 24"><path d="M12 3.5a8.5 8.5 0 1 1 0 17 8.5 8.5 0 0 1 0-17Zm.75 3.25h-1.5v1.18c-1.6.24-2.75 1.17-2.75 2.57 0 1.53 1.3 2.2 3.15 2.65 1.35.32 1.85.65 1.85 1.25 0 .68-.67 1.1-1.65 1.1-1.05 0-1.9-.38-2.62-1.05L8.2 15.58c.72.75 1.78 1.25 3.05 1.42v1.25h1.5v-1.27c1.72-.25 2.9-1.22 2.9-2.72 0-1.48-.95-2.24-3.32-2.82-1.37-.33-1.72-.68-1.72-1.13 0-.5.48-.93 1.42-.93.9 0 1.52.28 2.08.78l.98-1.13a4.02 4.02 0 0 0-2.34-1.08V6.75Z" fill="currentColor"/></svg></span><div><strong>Payments</strong><small>View payment history</small></div><b>&rsaquo;</b></a>
    </div>
  </article>
</section>

<div class="modal-overlay" id="profileEditModal" data-modal aria-hidden="true">
  <div class="modal-card wide profile-edit-modal client-profile-edit-modal" role="dialog" aria-modal="true" aria-labelledby="profileEditTitle">
    <div class="modal-header client-profile-edit-modal__header">
      <div>
        <h2 id="profileEditTitle">Edit Client Profile</h2>
        <p class="modal-note">Update your account details and login credentials. Current password is required for email or password changes.</p>
      </div>
      <button class="close-button" type="button" data-profile-edit-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
      <form method="post" class="form-grid client-profile-edit-form" enctype="multipart/form-data">
        <?= lex_csrf_field() ?>
        <section class="client-profile-edit-section full">
          <div class="client-profile-edit-section__head">
            <h3>Profile Photo</h3>
            <p>Use a clear image for easier identification across messages, billing, and appointments.</p>
          </div>
          <label class="full">Profile avatar
            <div class="profile-upload-row client-profile-upload-row">
              <div class="profile-upload-thumb client-profile-upload-thumb" data-profile-upload-thumb>
                <?php if ($avatarUrl !== ''): ?>
                  <img src="<?= lex_e($avatarUrl) ?>" alt="Current avatar for <?= lex_e($fullName) ?>" data-profile-upload-image>
                <?php else: ?>
                  <span data-profile-upload-fallback><?= lex_e($profileInitials) ?></span>
                <?php endif; ?>
              </div>
              <div class="profile-upload-field client-profile-upload-field">
                <strong class="client-profile-upload-name"><?= lex_e($fullName) ?></strong>
                <p class="client-profile-upload-copy" data-profile-upload-copy>Accepted formats: JPG, PNG, GIF, WEBP. Maximum file size: 5 MB.</p>
                <input type="file" name="avatar_image" accept="image/png,image/jpeg,image/gif,image/webp" data-profile-upload-input>
              </div>
            </div>
          </label>
        </section>

        <section class="client-profile-edit-section full">
          <div class="client-profile-edit-section__head">
            <h3>Account Information</h3>
          </div>
          <div class="client-profile-edit-grid">
            <label>Full name
              <input type="text" name="full_name" required value="<?= lex_e($fullName) ?>">
            </label>
            <label>Email
              <input type="email" name="email" required value="<?= lex_e($email) ?>">
            </label>
            <label>Contact number
              <input type="text" name="contact_number" required value="<?= lex_e($contactNumber) ?>">
            </label>
            <label class="full">Address
              <textarea name="address" rows="4" placeholder="Add your address for billing and records."><?= lex_e($address) ?></textarea>
            </label>
          </div>
        </section>

        <section class="client-profile-edit-section full">
          <div class="client-profile-edit-section__head">
            <h3>Security</h3>
            <p>Current password is required before changing your email address or password.</p>
          </div>
          <div class="client-profile-edit-grid">
            <label>Current password
              <div class="password-field" data-password-toggle>
                <input type="password" name="current_password" autocomplete="current-password" placeholder="Required for email or password changes">
                <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
              </div>
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
          </div>
        </section>

        <div class="profile-card-demo__actions full client-profile-edit-actions">
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
  const uploadInput = modal.querySelector('[data-profile-upload-input]');
  const uploadThumb = modal.querySelector('[data-profile-upload-thumb]');
  const uploadImage = modal.querySelector('[data-profile-upload-image]');
  const uploadFallback = modal.querySelector('[data-profile-upload-fallback]');
  const uploadCopy = modal.querySelector('[data-profile-upload-copy]');
  const defaultCopy = uploadCopy ? uploadCopy.textContent : '';
  let previewUrl = null;
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
  if (uploadInput && uploadThumb) {
    uploadInput.addEventListener('change', () => {
      const file = uploadInput.files && uploadInput.files[0] ? uploadInput.files[0] : null;
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
      }
      if (!file) {
        if (uploadCopy && defaultCopy) uploadCopy.textContent = defaultCopy;
        return;
      }
      previewUrl = URL.createObjectURL(file);
      let imageNode = uploadThumb.querySelector('[data-profile-upload-image]');
      if (!imageNode) {
        imageNode = document.createElement('img');
        imageNode.setAttribute('data-profile-upload-image', '');
        imageNode.alt = 'Selected avatar preview';
        uploadThumb.innerHTML = '';
        uploadThumb.appendChild(imageNode);
      }
      imageNode.src = previewUrl;
      imageNode.alt = 'Selected avatar preview';
      if (uploadFallback) {
        uploadFallback.remove();
      }
      if (uploadCopy) {
        uploadCopy.textContent = file.name + ' selected';
      }
    });
  }
  <?php if ($error !== ''): ?>
  open();
  <?php endif; ?>
})();
</script>
<?php lex_page_footer(); ?>
