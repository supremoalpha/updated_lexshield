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
        lex_audit_csrf_failure('client/edit_profile.php');
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
                        lex_send_account_activity_alert([
                            'full_name' => $fullName,
                            'email' => $oldEmail,
                        ], 'LEXSHIELD email address changed', 'The email address on your LEXSHIELD account was changed to ' . $email . '.');
                        lex_send_account_activity_alert([
                            'full_name' => $fullName,
                            'email' => $email,
                        ], 'LEXSHIELD email address changed', 'This email address is now used for your LEXSHIELD account.');
                    }
                    if ($passwordChanged) {
                        lex_send_account_activity_alert([
                            'full_name' => $fullName,
                            'email' => $email,
                        ], 'LEXSHIELD password changed', 'Your LEXSHIELD account password was changed successfully.');
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

lex_page_header('Edit Client Profile', 'profile', $user);
?>
<section class="profile-card-layout">
  <article class="profile-card-demo profile-card-live">
    <div class="profile-card-demo__avatar-wrap">
      <?php if ($avatarUrl !== ''): ?>
        <img class="profile-card-demo__avatar" src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e($fullName) ?>">
      <?php else: ?>
        <div class="profile-card-demo__avatar"><?= lex_e($profileInitials) ?></div>
      <?php endif; ?>
    </div>
    <header class="profile-card-demo__header">
      <h2 class="profile-card-demo__name"><?= lex_e($fullName) ?></h2>
      <p class="profile-card-demo__title">Client</p>
      <span class="profile-card-demo__badge">Edit Profile</span>
    </header>
      <p class="profile-card-demo__bio">Update the client identity, contact details, login credentials, and avatar.</p>
    <div class="profile-card-demo__divider" aria-hidden="true"></div>
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
      <label>Contact number
        <input type="text" name="contact_number" required value="<?= lex_e($contactNumber) ?>">
      </label>
      <label class="full">Address
        <textarea name="address" rows="4"><?= lex_e($address) ?></textarea>
      </label>
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
      <div class="profile-card-demo__actions">
        <button class="button button-primary" type="submit">Save changes</button>
        <a class="button button-secondary" href="<?= lex_e(lex_app_url('client/profile.php')) ?>">Cancel</a>
      </div>
    </form>
  </article>
</section>
<?php lex_page_footer(); ?>
