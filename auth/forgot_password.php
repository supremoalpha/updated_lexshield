<?php
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = lex_pdo();
$email = '';
$error = '';
$success = '';

function lex_password_reset_create(PDO $pdo, array $user): bool
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);

    $pdo->prepare('UPDATE password_resets SET is_used = 1, used_at = NOW() WHERE user_id = :user_id AND is_used = 0')
        ->execute(['user_id' => (int) $user['id']]);

    $stmt = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );
    $stmt->execute([
        'user_id' => (int) $user['id'],
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    $resetLink = lex_absolute_url('auth/reset_password.php?token=' . rawurlencode($token));
    $body = "We received a request to reset your LEXSHIELD password.\n\n"
        . "Open this link to set a new password:\n{$resetLink}\n\n"
        . "This link expires in 10 minutes.\n\n"
        . "If you did not request this, ignore this email.";

    if (lex_send_email((string) $user['email'], 'Reset your LEXSHIELD password', $body)) {
        lex_audit('password_reset_requested', 'users', (string) $user['id'], (int) $user['id']);
        lex_notify((int) $user['id'], 'security', 'A password reset link was requested for your LEXSHIELD account.');
        return true;
    }

    $pdo->prepare('UPDATE password_resets SET is_used = 1, used_at = NOW() WHERE token_hash = :token_hash')
        ->execute(['token_hash' => $tokenHash]);
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('auth/forgot_password.php');
        $error = 'Invalid CSRF token.';
    } else {
        $email = lex_sanitize_email($_POST['email'] ?? '');
        $limit = lex_rate_limit_hit(
            'forgot_password',
            lex_rate_limit_key(lex_rate_limit_client_ip(), $email),
            5,
            900,
            900,
            $email !== '' ? $email : lex_rate_limit_client_ip()
        );

        if (!$limit['allowed']) {
            $error = lex_rate_limit_message((int) $limit['retry_after']);
        } elseif ($email === '') {
            $error = 'Enter your email address.';
        } else {
            $stmt = $pdo->prepare('SELECT id, full_name, email, is_active FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && (int) ($user['is_active'] ?? 0) === 1) {
                lex_password_reset_create($pdo, $user);
            }

            $success = 'If an account exists, we sent password reset instructions.';
        }
    }
}

lex_auth_page_header('Forgot Password');
?>
<section class="auth-card auth-card-split">
  <div class="auth-copy auth-copy-dark">
    <a class="auth-home-link" href="<?= lex_e(lex_app_url('#home')) ?>" aria-label="Back to home" title="Back to home">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M4.75 10.95 12 4.6l7.25 6.35v8.3a.75.75 0 0 1-.75.75h-4.25v-5.25h-4.5V20H5.5a.75.75 0 0 1-.75-.75v-8.3Z" fill="currentColor"/>
      </svg>
    </a>
    <h1>Reset access securely.</h1>
    <p>We will send a one-time password reset link that expires in 10 minutes.</p>
    <ul class="feature-list">
      <li>Single-use reset links</li>
      <li>10-minute expiration</li>
      <li>Audit logged recovery</li>
    </ul>
  </div>
  <div class="auth-panel auth-panel-form">
    <div class="auth-switch" aria-label="Authentication pages">
      <a class="auth-switch__item" href="<?= lex_e(lex_app_url('auth/login.php')) ?>">Sign in</a>
      <span class="auth-switch__item is-active">Reset password</span>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= lex_e($success) ?></div><?php endif; ?>
    <form method="post" class="stack-form" autocomplete="off">
      <?= lex_csrf_field() ?>
      <label>Email
        <input type="email" name="email" required placeholder="user@gmail.com" value="<?= lex_e($email) ?>">
      </label>
      <button class="button button-primary auth-submit" type="submit">Send reset link</button>
    </form>
    <p class="muted auth-footnote">Remembered your password? <a href="<?= lex_e(lex_app_url('auth/login.php')) ?>">Sign in</a></p>
  </div>
</section>
<?php lex_auth_page_footer(); ?>
