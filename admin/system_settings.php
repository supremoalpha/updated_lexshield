<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('admin');
$pdo = lex_pdo();
$currentSettings = [];
$rows = $pdo->query('SELECT setting_key, setting_value FROM site_settings ORDER BY setting_key')->fetchAll();
foreach ($rows as $row) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('admin/system_settings.php');
        lex_flash_set('error', 'Invalid CSRF token.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'download_cvd') {
        try {
            $saved = lex_cvd_download_official(false);
            lex_audit('cvd_database_updated', 'clamav_db', implode(',', $saved), (int) $user['id']);
            lex_flash_set('success', 'Virus database updated: ' . implode(', ', $saved) . '. Do not open the .cvd files in Word or Notepad.');
        } catch (Throwable $e) {
            lex_flash_set('error', 'Could not download the virus database. ' . $e->getMessage());
        }
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }

    if ($action === 'test_smtp') {
        $to = lex_sanitize_email((string) ($_POST['test_email'] ?? $user['email'] ?? ''));
        if ($to === '') {
            lex_flash_set('error', 'Enter an email address for the SMTP test.');
        } elseif (lex_send_email($to, 'LEXSHIELD SMTP test', 'SMTP is configured. LEXSHIELD can send OTP and password-reset email.')) {
            lex_audit('smtp_test_sent', 'site_settings', $to, (int) $user['id']);
            lex_flash_set('success', 'Test email sent to ' . $to . '. Check the inbox (and spam).');
        } else {
            lex_flash_set('error', 'Unable to send the test email. ' . lex_mail_public_error());
        }
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }

    $qrUpload = null;
    try {
        $qrUpload = lex_store_payment_qr($_FILES['gcash_qr_file'] ?? []);
        $settings = [
            'site_name' => lex_sanitize_text($_POST['site_name'] ?? ''),
            'session_timeout' => lex_sanitize_text($_POST['session_timeout'] ?? ''),
            'smtp_host' => lex_sanitize_text($_POST['smtp_host'] ?? ''),
            'smtp_port' => lex_sanitize_text($_POST['smtp_port'] ?? ''),
            'smtp_user' => lex_sanitize_email($_POST['smtp_user'] ?? ''),
            'smtp_from' => lex_sanitize_email($_POST['smtp_from'] ?? ''),
            'smtp_encryption' => lex_sanitize_text($_POST['smtp_encryption'] ?? 'smtps'),
            'smtp_pass' => trim((string) ($_POST['smtp_pass'] ?? '')) !== ''
                ? (string) ($_POST['smtp_pass'] ?? '')
                : (string) ($currentSettings['smtp_pass'] ?? ''),
            'gcash_account_name' => lex_sanitize_text($_POST['gcash_account_name'] ?? ''),
            'gcash_number' => preg_replace('/[^0-9+]/', '', (string) ($_POST['gcash_number'] ?? '')),
            'gcash_instructions' => trim((string) ($_POST['gcash_instructions'] ?? '')),
            'gcash_qr_stored_name' => (string) ($qrUpload['stored_name'] ?? ($currentSettings['gcash_qr_stored_name'] ?? '')),
        ];
        if (!in_array($settings['smtp_encryption'], ['smtps', 'tls', 'none'], true)) {
            $settings['smtp_encryption'] = 'smtps';
        }
        $stmt = $pdo->prepare('REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW())');
        foreach ($settings as $key => $value) {
            $stmt->execute(['setting_key' => $key, 'setting_value' => (string) $value]);
        }
        lex_site_setting_flush();
        lex_audit('update_settings', 'site_settings', 'global');
        lex_flash_set('success', 'System settings updated.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    } catch (Throwable $e) {
        if (!empty($qrUpload['path']) && is_file((string) $qrUpload['path'])) {
            @unlink((string) $qrUpload['path']);
        }
        lex_flash_set('error', 'Could not save settings.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }
}

$mail = lex_mail_config();
$otpFlags = lex_otp_enabled_flags();
$smtpReady = lex_mail_is_configured();
$virus = lex_virus_scan_status();
try {
    lex_cvd_write_readable_index();
} catch (Throwable $e) {
    // Index files are optional.
}

lex_page_header('System Settings', 'settings');
?>
<section class="card admin-settings-card" data-system-settings-page>
  <div class="card-head"><h2>Deployment status</h2></div>
  <p class="muted">
    Login OTP: <strong><?= $otpFlags['login'] ? 'on' : 'off' ?></strong>
    · Admin OTP: <strong><?= $otpFlags['admin'] ? 'on' : 'off' ?></strong>
    · Registration OTP: <strong><?= $otpFlags['registration'] ? 'on' : 'off' ?></strong>
    · SMTP: <strong><?= $smtpReady ? 'ready' : 'needs host, from address, and password' ?></strong>
    · Virus scan (CVD): <strong><?= $virus['ready'] ? 'on' : 'off' ?></strong>
  </p>
  <p class="muted"><?= lex_e($virus['message']) ?> Database folder: <code><?= lex_e($virus['database']) ?></code></p>
  <p class="muted">Do not open <code>.cvd</code> files in Word, Notepad, or VS Code. They are binary virus databases, so Windows says “not supported.” Open <code>WHAT_IS_INSIDE.txt</code> in that folder, or read the table below.</p>
  <?php if (!empty($virus['files'])): ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>File</th>
          <th>Version</th>
          <th>Signatures</th>
          <th>Built</th>
          <th>Size</th>
          <th>What it is</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($virus['files'] as $cvdFile): ?>
          <tr>
            <td><code><?= lex_e($cvdFile['name']) ?></code></td>
            <td><?= lex_e($cvdFile['version']) ?></td>
            <td><?= lex_e($cvdFile['signatures']) ?></td>
            <td><?= lex_e($cvdFile['built']) ?></td>
            <td><?= lex_e($cvdFile['size']) ?></td>
            <td><?= lex_e($cvdFile['note']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <form method="post" class="form-grid admin-settings-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="download_cvd">
    <p class="muted">Download <code>daily.cvd</code> and <code>bytecode.cvd</code> from ClamAV into this folder. Keep the filenames. Do not open the downloaded files.</p>
    <button class="button button-secondary" type="submit">Download virus database</button>
  </form>
  <p class="muted">Gmail: use smtp.gmail.com, port 465, encryption SMTPS, and a Google App Password (not your normal Gmail password).</p>
</section>

<section class="card admin-settings-card" data-system-settings-page>
  <div class="card-head"><h2>Platform Settings</h2></div>
  <form method="post" enctype="multipart/form-data" class="form-grid admin-settings-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <label>Site name <input type="text" name="site_name" value="<?= lex_e($mail['from_name'] !== '' ? $mail['from_name'] : 'LEXSHIELD') ?>"></label>
    <label>Session timeout (seconds) <input type="number" name="session_timeout" value="<?= lex_e((string) ($currentSettings['session_timeout'] ?? (lex_env('SESSION_TIMEOUT', '1800') ?? '1800'))) ?>"></label>
    <label>SMTP host <input type="text" name="smtp_host" value="<?= lex_e($mail['host']) ?>" placeholder="smtp.gmail.com"></label>
    <label>SMTP port <input type="number" name="smtp_port" value="<?= lex_e((string) $mail['port']) ?>"></label>
    <label>SMTP encryption
      <select name="smtp_encryption">
        <option value="smtps"<?= $mail['encryption'] === 'smtps' ? ' selected' : '' ?>>SMTPS (465)</option>
        <option value="tls"<?= in_array($mail['encryption'], ['tls', 'starttls'], true) ? ' selected' : '' ?>>STARTTLS (587)</option>
        <option value="none"<?= $mail['encryption'] === 'none' ? ' selected' : '' ?>>None</option>
      </select>
    </label>
    <label>SMTP username <input type="email" name="smtp_user" value="<?= lex_e($mail['user']) ?>" placeholder="you@gmail.com"></label>
    <label>From address <input type="email" name="smtp_from" value="<?= lex_e($mail['from']) ?>" placeholder="you@gmail.com"></label>
    <label>SMTP password
      <div class="password-field" data-password-toggle>
        <input type="password" name="smtp_pass" value="" placeholder="<?= $mail['pass'] !== '' ? 'Leave blank to keep the saved password' : 'Gmail App Password' ?>" autocomplete="new-password">
        <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
      </div>
    </label>
    <label>GCash account name <input type="text" name="gcash_account_name" value="<?= lex_e($currentSettings['gcash_account_name'] ?? '') ?>" placeholder="Juan Dela Cruz"></label>
    <label>GCash mobile number <input type="text" name="gcash_number" value="<?= lex_e($currentSettings['gcash_number'] ?? '') ?>" placeholder="09XXXXXXXXX"></label>
    <label class="full">GCash instructions
      <textarea name="gcash_instructions" rows="4" placeholder="Add any reminders for clients before they upload proof."><?= lex_e($currentSettings['gcash_instructions'] ?? '') ?></textarea>
    </label>
    <label class="full">GCash QR image
      <input type="file" name="gcash_qr_file" accept="image/png,image/jpeg,image/webp,image/gif">
    </label>
    <?php if (!empty($currentSettings['gcash_qr_stored_name'])): ?>
      <div class="full profile-upload-row">
        <div class="profile-upload-thumb">
          <img src="<?= lex_e(lex_app_url('payment_qr_image.php')) ?>" alt="Current GCash QR">
        </div>
        <div class="profile-upload-field">
          <strong>Current GCash QR is active</strong>
          <p class="profile-upload-copy">Upload a new image only when you want to replace the current code.</p>
        </div>
      </div>
    <?php endif; ?>
    <button class="button button-primary admin-settings-submit" type="submit">Save Settings</button>
  </form>
</section>

<section class="card admin-settings-card">
  <div class="card-head"><h2>Send a test email</h2></div>
  <form method="post" class="form-grid admin-settings-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="test_smtp">
    <label>Recipient
      <input type="email" name="test_email" required value="<?= lex_e((string) ($user['email'] ?? '')) ?>" placeholder="you@gmail.com">
    </label>
    <button class="button button-secondary" type="submit">Send test email</button>
  </form>
</section>
<?php lex_page_footer(); ?>
