<?php
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = lex_pdo();
$error = '';
$success = '';
$fullName = '';
$email = '';
$contactNumber = '';
$address = '';
$city = '';
$passwordMismatch = false;
$passwordMismatchMessage = 'Those password doesn\'t match. Try again.';
$registrationOtpEnabled = lex_otp_enabled_flags()['registration'] && lex_mail_is_configured();
$otpStep = !empty($_SESSION['pending_client_registration']);
$otpEmail = $otpStep ? (string) ($_SESSION['pending_client_registration']['email'] ?? '') : '';

if (!$registrationOtpEnabled && $otpStep) {
    lex_registration_clear_pending();
    $otpStep = false;
    $otpEmail = '';
}

function lex_registration_clear_pending(): void
{
    unset($_SESSION['pending_client_registration']);
}

function lex_registration_send_otp(PDO $pdo, string $email): bool
{
    $otp = (string) random_int(100000, 999999);
    $otpHash = password_hash($otp, PASSWORD_BCRYPT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);

    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE email = :email AND purpose = "client_registration" AND is_used = 0')
        ->execute(['email' => $email]);
    $stmt = $pdo->prepare(
        'INSERT INTO email_otps (email, otp_hash, purpose, expires_at)
         VALUES (:email, :otp_hash, "client_registration", :expires_at)'
    );
    $stmt->execute([
        'email' => $email,
        'otp_hash' => $otpHash,
        'expires_at' => $expiresAt,
    ]);
    $otpId = (int) $pdo->lastInsertId();

    $body = "Your LEXSHIELD verification code is: {$otp}\n\n"
        . "This code expires in 10 minutes. If you did not request this account, you can ignore this email.";

    if (lex_send_email($email, 'Your LEXSHIELD verification code', $body)) {
        return true;
    }

    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => $otpId]);
    return false;
}

function lex_registration_latest_otp(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM email_otps
         WHERE email = :email
           AND purpose = "client_registration"
           AND is_used = 0
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lex_registration_create_client(PDO $pdo, string $fullName, string $email, string $passwordHash, string $contactNumber, string $address): int
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, created_at) VALUES (:full_name, :email, :password_hash, "client", 1, NOW())');
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
        $userId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO clients (user_id, contact_number, address, risk_level) VALUES (:user_id, :contact_number, :address, "low")');
        $stmt->execute([
            'user_id' => $userId,
            'contact_number' => $contactNumber,
            'address' => $address,
        ]);
        lex_audit('register', 'users', (string) $userId, $userId);
        $pdo->commit();
        if (function_exists('lex_notify_role_users')) {
            lex_notify_role_users('admin', 'client', $fullName . ' registered a new client account.');
        }
        return $userId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('auth/register.php');
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string) ($_POST['action'] ?? 'send_otp');

        if ($action === 'verify_otp') {
            $pending = $_SESSION['pending_client_registration'] ?? null;
            $submittedOtp = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? '')) ?? '';
            $pendingEmail = is_array($pending) ? lex_sanitize_email((string) ($pending['email'] ?? '')) : '';
            $limit = lex_rate_limit_hit(
                'registration_otp_verify',
                lex_rate_limit_key(lex_rate_limit_client_ip(), $pendingEmail),
                8,
                600,
                600,
                $pendingEmail !== '' ? $pendingEmail : lex_rate_limit_client_ip()
            );

            if (!$limit['allowed']) {
                $error = lex_rate_limit_message((int) $limit['retry_after']);
                $otpStep = is_array($pending);
            } elseif (!is_array($pending)) {
                $error = 'Registration session expired. Please start again.';
                lex_registration_clear_pending();
            } elseif ($submittedOtp === '' || strlen($submittedOtp) !== 6) {
                $error = 'Enter the 6-digit verification code.';
                $otpStep = true;
            } else {
                $email = lex_sanitize_email((string) ($pending['email'] ?? ''));
                $fullName = lex_sanitize_text((string) ($pending['full_name'] ?? ''));
                $contactNumber = lex_sanitize_text((string) ($pending['contact_number'] ?? ''));
                $city = lex_sanitize_text((string) ($pending['city'] ?? ''));
                $address = $city;
                $otpRow = lex_registration_latest_otp($pdo, $email);

                if (!$otpRow) {
                    lex_audit('expired_otp', 'email_otps', 'client_registration:' . $email);
                    $error = 'Verification code expired. Please request a new code.';
                    $otpStep = true;
                } elseif (strtotime((string) $otpRow['expires_at']) < time()) {
                    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                    lex_audit('expired_otp', 'email_otps', (string) $otpRow['id']);
                    $error = 'Verification code expired. Please request a new code.';
                    $otpStep = true;
                } elseif ((int) $otpRow['attempts'] >= 5) {
                    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                    lex_audit('failed_otp_locked', 'email_otps', (string) $otpRow['id']);
                    $error = 'Too many incorrect attempts. Please request a new code.';
                    $otpStep = true;
                } elseif (!password_verify($submittedOtp, (string) $otpRow['otp_hash'])) {
                    $pdo->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                    lex_audit('failed_otp', 'email_otps', (string) $otpRow['id']);
                    $error = 'Invalid verification code.';
                    $otpStep = true;
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                    $stmt->execute(['email' => $email]);
                    if ($stmt->fetchColumn()) {
                        $error = 'An account already exists with that email.';
                        lex_registration_clear_pending();
                        $otpStep = false;
                    } else {
                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active, created_at) VALUES (:full_name, :email, :password_hash, "client", 1, NOW())');
                            $stmt->execute([
                                'full_name' => $fullName,
                                'email' => $email,
                                'password_hash' => (string) ($pending['password_hash'] ?? ''),
                            ]);
                            $userId = (int) $pdo->lastInsertId();
                            $stmt = $pdo->prepare('INSERT INTO clients (user_id, contact_number, address, risk_level) VALUES (:user_id, :contact_number, :address, "low")');
                            $stmt->execute([
                                'user_id' => $userId,
                                'contact_number' => $contactNumber,
                                'address' => $address,
                            ]);
                            $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                            lex_audit('register', 'users', (string) $userId, $userId);
                            $pdo->commit();
                            if (function_exists('lex_notify_role_users')) {
                                lex_notify_role_users('admin', 'client', $fullName . ' registered a new client account.');
                            }
                            lex_registration_clear_pending();
                            $otpStep = false;
                            $success = 'Your client account has been created. You can now sign in.';
                            $fullName = '';
                            $email = '';
                            $contactNumber = '';
                            $address = '';
                            $city = '';
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $error = 'Registration failed. Please try again.';
                            $otpStep = true;
                        }
                    }
                }
            }
        } elseif ($action === 'resend_otp') {
            $pending = $_SESSION['pending_client_registration'] ?? null;
            if (!is_array($pending)) {
                $error = 'Registration session expired. Please start again.';
                lex_registration_clear_pending();
                $otpStep = false;
            } else {
                $email = lex_sanitize_email((string) ($pending['email'] ?? ''));
                $limit = lex_rate_limit_hit(
                    'registration_otp_resend',
                    lex_rate_limit_key(lex_rate_limit_client_ip(), $email),
                    3,
                    600,
                    600,
                    $email
                );
                if (!$limit['allowed']) {
                    $error = lex_rate_limit_message((int) $limit['retry_after']);
                } elseif (lex_registration_send_otp($pdo, $email)) {
                    $success = 'A new verification code was sent to your Gmail.';
                } else {
                    $error = 'Unable to send the verification code. ' . lex_mail_public_error();
                }
                $otpStep = true;
            }
        } elseif ($action === 'change_email') {
            lex_registration_clear_pending();
            $otpStep = false;
        } else {
            $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
            $email = lex_sanitize_email($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            $contactNumber = lex_sanitize_text($_POST['contact_number'] ?? '');
            $city = lex_sanitize_text($_POST['city'] ?? '');
            $address = $city;
            $limit = lex_rate_limit_hit(
                'registration_start',
                lex_rate_limit_key(lex_rate_limit_client_ip(), $email),
                5,
                900,
                900,
                $email !== '' ? $email : lex_rate_limit_client_ip()
            );

            if (!$limit['allowed']) {
                $error = lex_rate_limit_message((int) $limit['retry_after']);
            } elseif ($password !== $confirmPassword) {
                $passwordMismatch = true;
                $error = $passwordMismatchMessage;
            } elseif (($passwordError = lex_password_policy_error($password, $email, $fullName)) !== '') {
                $error = $passwordError;
            } elseif ($fullName === '' || $email === '' || $contactNumber === '' || $city === '') {
                $error = 'Please complete all required fields.';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $stmt->execute(['email' => $email]);
                if ($stmt->fetchColumn()) {
                    $error = 'An account already exists with that email.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                    if (!$registrationOtpEnabled) {
                        try {
                            lex_registration_create_client($pdo, $fullName, $email, $passwordHash, $contactNumber, $address);
                            $success = 'Your client account has been created. You can now sign in.';
                            $fullName = '';
                            $email = '';
                            $contactNumber = '';
                            $address = '';
                            $city = '';
                        } catch (Throwable $e) {
                            $error = 'Registration failed. Please try again.';
                        }
                    } else {
                        $_SESSION['pending_client_registration'] = [
                            'full_name' => $fullName,
                            'email' => $email,
                            'password_hash' => $passwordHash,
                            'contact_number' => $contactNumber,
                            'city' => $city,
                        ];
                        if (lex_registration_send_otp($pdo, $email)) {
                            $success = 'We sent a verification code to your Gmail.';
                            $otpStep = true;
                            $otpEmail = $email;
                        } elseif (lex_smtp_local_bypass()) {
                            try {
                                lex_registration_create_client($pdo, $fullName, $email, $passwordHash, $contactNumber, $address);
                                lex_registration_clear_pending();
                                $success = 'Your client account was created. Email could not be sent, so no code was required. You can sign in now. ' . lex_mail_public_error();
                                $fullName = '';
                                $email = '';
                                $contactNumber = '';
                                $address = '';
                                $city = '';
                            } catch (Throwable $e) {
                                $error = 'Registration failed. Please try again.';
                            }
                        } else {
                            lex_registration_clear_pending();
                            $error = 'Unable to send the verification code. ' . lex_mail_public_error();
                        }
                    }
                }
            }
        }
    }
}

if ($otpStep) {
    $pending = $_SESSION['pending_client_registration'] ?? [];
    if (is_array($pending)) {
        $fullName = (string) ($pending['full_name'] ?? $fullName);
        $email = (string) ($pending['email'] ?? $email);
        $contactNumber = (string) ($pending['contact_number'] ?? $contactNumber);
        $city = (string) ($pending['city'] ?? $city);
        $address = $city;
        $otpEmail = $email;
    }
}

lex_pao_page_header('Client Registration', 'login', 'pao-login');
?>
<section class="pao-login-card pao-register-card">
    <p class="pao-login-kicker">PAO Iloilo · Client Portal</p>
    <h1>Create a client account</h1>
    <p class="pao-login-lead">Register with the Public Attorney's Office in Iloilo to reserve an appointment, message counsel, and follow your case.</p>
    <div class="pao-login-switch" aria-label="Authentication pages">
      <a href="<?= lex_e(lex_app_url('auth/login.php')) ?>">Sign in</a>
      <span class="is-active">New client</span>
    </div>

    <?php foreach (lex_flash_consume() as $flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= lex_e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($error && !$passwordMismatch): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= lex_e($success) ?></div><?php endif; ?>
    <?php if ($otpStep): ?>
    <form method="post" class="form-grid" autocomplete="off">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="verify_otp">
      <div class="full">
        <p class="pao-login-lead">Enter the 6-digit code sent to <?= lex_e($otpEmail) ?>. The code expires in 10 minutes.</p>
      </div>
      <label class="full">Verification code
        <input type="text" name="otp_code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" autocomplete="one-time-code">
      </label>
      <button class="button button-primary auth-submit full" type="submit">Verify and create account</button>
    </form>
    <form method="post" class="form-grid" autocomplete="off">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="resend_otp">
      <button class="button button-secondary auth-submit full" type="submit">Send new code</button>
    </form>
    <form method="post" class="form-grid" autocomplete="off">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="change_email">
      <button class="button button-secondary auth-submit full" type="submit">Change registration details</button>
    </form>
    <?php else: ?>
    <form method="post" class="form-grid" autocomplete="off">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="send_otp">
      <label class="full">Full name
        <input type="text" name="full_name" required placeholder="FirstName LastName" value="<?= lex_e($fullName) ?>">
      </label>
      <label class="full">Email
        <input type="email" name="email" required placeholder="user@example.com" value="<?= lex_e($email) ?>">
      </label>
      <label>Contact number
        <input type="text" name="contact_number" required placeholder="09xxxxxxxxx" value="<?= lex_e($contactNumber) ?>">
      </label>
      <label>City
        <input type="text" name="city" required placeholder="Iloilo City" value="<?= lex_e($city !== '' ? $city : $address) ?>">
      </label>
      <label class="full">Password
        <div class="password-field" data-password-toggle>
          <input type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="10+ chars with upper, lower, number, symbol">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <label class="full">Confirm password
        <div class="password-field<?= $passwordMismatch ? ' is-invalid' : '' ?>" data-password-toggle>
          <input type="password" name="confirm_password" required minlength="10" autocomplete="new-password" placeholder="Repeat your password"<?= $passwordMismatch ? ' aria-invalid="true" aria-describedby="confirm-password-error"' : '' ?>>
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
        <?php if ($passwordMismatch): ?>
          <span class="auth-field-error" id="confirm-password-error"><?= lex_e($passwordMismatchMessage) ?></span>
        <?php endif; ?>
      </label>
      <button class="button button-primary auth-submit full" type="submit"><?= $registrationOtpEnabled ? 'Send verification code' : 'Create account' ?></button>
    </form>
    <?php endif; ?>
    <p class="muted auth-footnote">Already have access? <a href="<?= lex_e(lex_app_url('auth/login.php')) ?>">Sign in</a></p>
</section>
<?php lex_pao_page_footer(); ?>
