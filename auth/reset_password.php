<?php
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = lex_pdo();
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$tokenRow = null;

function lex_password_reset_find(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT pr.*, u.email, u.full_name
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = :token_hash
           AND pr.is_used = 0
         LIMIT 1'
    );
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        $pdo->prepare('UPDATE password_resets SET is_used = 1, used_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $row['id']]);
        return null;
    }
    return $row;
}

$tokenRow = lex_password_reset_find($pdo, $token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('auth/reset_password.php');
        $error = 'Invalid CSRF token.';
    } elseif (!$tokenRow) {
        $error = 'This reset link is invalid or expired.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (($passwordError = lex_password_policy_error($password, (string) $tokenRow['email'], (string) $tokenRow['full_name'])) !== '') {
            $error = $passwordError;
        } elseif ($password !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'UPDATE users
                     SET password_hash = :password_hash,
                         failed_login_attempts = 0,
                         locked_until = NULL
                     WHERE id = :id'
                )->execute([
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'id' => (int) $tokenRow['user_id'],
                ]);
                $pdo->prepare('UPDATE password_resets SET is_used = 1, used_at = NOW() WHERE id = :id')
                    ->execute(['id' => (int) $tokenRow['id']]);
                lex_audit('password_reset_completed', 'users', (string) $tokenRow['user_id'], (int) $tokenRow['user_id']);
                $pdo->commit();
                lex_send_security_event_notification([
                    'id' => (int) $tokenRow['user_id'],
                    'full_name' => (string) $tokenRow['full_name'],
                    'email' => (string) $tokenRow['email'],
                ], 'LEXSHIELD password reset completed', 'Your LEXSHIELD account password was reset successfully.', 'Your LEXSHIELD password was reset successfully.');
                $success = 'Your password has been reset. You can now sign in.';
                $tokenRow = null;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Unable to reset password. Please request a new link.';
            }
        }
    }
}

lex_pao_page_header('Reset Password', 'login', 'pao-login');
?>
<section class="pao-login-card pao-reset-card">
    <h1>Create a new password</h1>
    <p class="pao-login-lead">Reset links are single-use and expire 10 minutes after being sent.</p>
    <div class="pao-login-switch" aria-label="Authentication pages">
      <a href="<?= lex_e(lex_app_url('auth/login.php')) ?>">Sign in</a>
      <span class="is-active">New password</span>
    </div>

    <?php foreach (lex_flash_consume() as $flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= lex_e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= lex_e($success) ?></div><?php endif; ?>

    <?php if ($tokenRow): ?>
    <form method="post" class="stack-form" autocomplete="off">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="token" value="<?= lex_e($token) ?>">
      <label>New password
        <div class="password-field" data-password-toggle>
          <input type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="10+ chars with upper, lower, number, symbol">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <label>Confirm new password
        <div class="password-field" data-password-toggle>
          <input type="password" name="confirm_password" required minlength="10" autocomplete="new-password" placeholder="Repeat your new password">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <button class="button button-primary auth-submit" type="submit">Reset password</button>
    </form>
    <?php elseif (!$success): ?>
      <div class="alert alert-error">This reset link is invalid or expired.</div>
      <p class="muted auth-footnote"><a href="<?= lex_e(lex_app_url('auth/forgot_password.php')) ?>">Request a new reset link</a></p>
    <?php endif; ?>
    <?php if ($success): ?><p class="muted auth-footnote"><a href="<?= lex_e(lex_app_url('auth/login.php')) ?>">Return to login</a></p><?php endif; ?>
</section>
<?php lex_pao_page_footer(); ?>
