<?php

declare(strict_types=1);

/**
 * Inbox PIN for lawyers and admins. One PIN per account unlocks every
 * conversation on Messages. Clients never see a PIN. After logout the
 * session unlock is cleared. Forgot PIN uses the account password;
 * Change PIN uses the current PIN then a new one.
 */

if (!function_exists('lex_messages_lock_applies')) {
    function lex_messages_lock_applies(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), ['lawyer', 'admin'], true);
    }
}

if (!function_exists('lex_messages_lock_url')) {
    /**
     * @param array<string, mixed> $user
     * @param array<string, scalar> $query
     */
    function lex_messages_lock_url(array $user, array $query = []): string
    {
        $path = 'chat.php';
        if ($query === []) {
            return function_exists('lex_nav_href') ? lex_nav_href($path) : lex_app_url($path);
        }
        $suffix = '?' . http_build_query($query);
        return (function_exists('lex_nav_href') ? lex_nav_href($path) : lex_app_url($path)) . $suffix;
    }
}

if (!function_exists('lex_messages_lock_columns_ensure')) {
    function lex_messages_lock_columns_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $pdo = lex_pdo();
        try {
            $pdo->exec('ALTER TABLE `users` ADD COLUMN `messages_pin_hash` VARCHAR(255) DEFAULT NULL');
        } catch (PDOException $e) {
            // Column already exists.
        }
        try {
            $pdo->exec('ALTER TABLE `lawyers` ADD COLUMN `messages_pin_hash` VARCHAR(255) DEFAULT NULL');
        } catch (PDOException $e) {
            // Column already exists.
        }
        try {
            $pdo->exec(
                'UPDATE `users` u
                 JOIN `lawyers` l ON l.user_id = u.id
                 SET u.messages_pin_hash = l.messages_pin_hash
                 WHERE (u.messages_pin_hash IS NULL OR u.messages_pin_hash = "")
                   AND l.messages_pin_hash IS NOT NULL
                   AND l.messages_pin_hash <> ""'
            );
        } catch (PDOException $e) {
            // Best-effort copy of an older lawyer-only PIN.
        }
        $done = true;
    }
}

if (!function_exists('lex_messages_lock_hash')) {
    function lex_messages_lock_hash(int $userId): string
    {
        lex_messages_lock_columns_ensure();
        $stmt = lex_pdo()->prepare('SELECT messages_pin_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $hash = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($hash !== '') {
            return $hash;
        }

        $legacy = lex_pdo()->prepare('SELECT messages_pin_hash FROM lawyers WHERE user_id = :user_id LIMIT 1');
        $legacy->execute(['user_id' => $userId]);
        return trim((string) ($legacy->fetchColumn() ?: ''));
    }
}

if (!function_exists('lex_messages_lock_normalize_code')) {
    function lex_messages_lock_normalize_code(string $code): string
    {
        return preg_replace('/\D+/', '', $code) ?? '';
    }
}

if (!function_exists('lex_messages_lock_code_error')) {
    function lex_messages_lock_code_error(string $code): string
    {
        if (strlen($code) < 4 || strlen($code) > 8) {
            return 'Use a 4 to 8 digit PIN.';
        }
        return '';
    }
}

if (!function_exists('lex_messages_lock_is_unlocked')) {
    function lex_messages_lock_is_unlocked(int $userId): bool
    {
        return (int) ($_SESSION['lex_messages_unlocked_user'] ?? 0) === $userId;
    }
}

if (!function_exists('lex_messages_lock_mark_unlocked')) {
    function lex_messages_lock_mark_unlocked(int $userId): void
    {
        $_SESSION['lex_messages_unlocked_user'] = $userId;
        $_SESSION['lex_messages_unlocked_at'] = time();
    }
}

if (!function_exists('lex_messages_lock_clear')) {
    function lex_messages_lock_clear(): void
    {
        unset($_SESSION['lex_messages_unlocked_user'], $_SESSION['lex_messages_unlocked_at']);
    }
}

if (!function_exists('lex_messages_lock_set_hash')) {
    function lex_messages_lock_set_hash(int $userId, string $code): void
    {
        lex_messages_lock_columns_ensure();
        $hash = password_hash($code, PASSWORD_BCRYPT);
        lex_pdo()->prepare('UPDATE users SET messages_pin_hash = :hash WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $userId]);
        try {
            lex_pdo()->prepare('UPDATE lawyers SET messages_pin_hash = :hash WHERE user_id = :user_id')
                ->execute(['hash' => $hash, 'user_id' => $userId]);
        } catch (PDOException $e) {
            // Admin accounts have no lawyers row.
        }
    }
}

if (!function_exists('lex_messages_lock_verify_password')) {
    function lex_messages_lock_verify_password(int $userId, string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $stmt = lex_pdo()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $hash = (string) ($stmt->fetchColumn() ?: '');
        return $hash !== '' && password_verify($password, $hash);
    }
}

if (!function_exists('lex_messages_lock_handle_post')) {
    /**
     * @param array<string, mixed> $user
     */
    function lex_messages_lock_handle_post(array $user, string &$error): bool
    {
        if (!lex_messages_lock_applies($user)) {
            return false;
        }

        $userId = (int) $user['id'];
        lex_messages_lock_columns_ensure();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string) ($_POST['action'] ?? '');
            $lockActions = ['set_messages_pin', 'unlock_messages', 'reset_messages_pin', 'change_messages_pin', 'lock_messages'];
            if (in_array($action, $lockActions, true)) {
                $auditPage = ((string) $user['role']) . '/messages.php';
                if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
                    lex_audit_csrf_failure($auditPage);
                    $error = 'Invalid CSRF token. Please try again.';
                } elseif ($action === 'lock_messages') {
                    lex_messages_lock_clear();
                    lex_audit('lock_messages', 'messages', (string) $userId, $userId);
                    lex_flash_set('success', 'Messages are locked. Enter your PIN to open them again.');
                    if (function_exists('lex_redirect_app_file')) {
                        lex_redirect_app_file('chat.php');
                    }
                    header('Location: ' . lex_messages_lock_url($user));
                    exit;
                } elseif ($action === 'set_messages_pin') {
                    $code = lex_messages_lock_normalize_code((string) ($_POST['messages_pin'] ?? ''));
                    $confirm = lex_messages_lock_normalize_code((string) ($_POST['messages_pin_confirm'] ?? ''));
                    $codeError = lex_messages_lock_code_error($code);
                    if ($codeError !== '') {
                        $error = $codeError;
                    } elseif ($code !== $confirm) {
                        $error = 'The two PINs do not match.';
                    } else {
                        lex_messages_lock_set_hash($userId, $code);
                        lex_messages_lock_mark_unlocked($userId);
                        lex_audit('set_messages_pin', 'users', (string) $userId, $userId);
                        lex_flash_set('success', 'PIN saved. You will need it on Messages after you sign out.');
                        if (function_exists('lex_redirect_app_file')) {
                            lex_redirect_app_file('chat.php');
                        }
                        header('Location: ' . lex_messages_lock_url($user));
                        exit;
                    }
                } elseif ($action === 'unlock_messages') {
                    $code = lex_messages_lock_normalize_code((string) ($_POST['messages_pin'] ?? ''));
                    $limit = lex_rate_limit_hit(
                        'messages_pin_unlock',
                        lex_rate_limit_key(lex_rate_limit_user_part(), 'messages_pin'),
                        5,
                        900,
                        900,
                        (string) ($user['email'] ?? '')
                    );
                    $hash = lex_messages_lock_hash($userId);
                    if (!$limit['allowed']) {
                        $error = lex_rate_limit_message((int) $limit['retry_after']);
                    } elseif ($hash === '' || !password_verify($code, $hash)) {
                        lex_audit('failed_messages_pin', 'users', (string) $userId, $userId);
                        $error = 'That PIN is incorrect.';
                    } else {
                        lex_messages_lock_mark_unlocked($userId);
                        lex_audit('unlock_messages', 'messages', (string) $userId, $userId);
                        $with = lex_sanitize_int($_GET['with'] ?? 0);
                        if (function_exists('lex_redirect_app_file')) {
                            lex_redirect_app_file($with > 0 ? 'chat.php?with=' . $with : 'chat.php');
                        }
                        header('Location: ' . lex_messages_lock_url($user, $with > 0 ? ['with' => $with] : []));
                        exit;
                    }
                } elseif ($action === 'reset_messages_pin') {
                    $password = (string) ($_POST['current_password'] ?? '');
                    $code = lex_messages_lock_normalize_code((string) ($_POST['messages_pin'] ?? ''));
                    $confirm = lex_messages_lock_normalize_code((string) ($_POST['messages_pin_confirm'] ?? ''));
                    $codeError = lex_messages_lock_code_error($code);
                    $limit = lex_rate_limit_hit(
                        'messages_pin_forgot',
                        lex_rate_limit_key(lex_rate_limit_user_part(), 'messages_pin_forgot'),
                        5,
                        900,
                        900,
                        (string) ($user['email'] ?? '')
                    );
                    if (!$limit['allowed']) {
                        $error = lex_rate_limit_message((int) $limit['retry_after']);
                    } elseif (!lex_messages_lock_verify_password($userId, $password)) {
                        lex_audit('failed_messages_pin_forgot', 'users', (string) $userId, $userId);
                        $error = 'Enter your account password to reset the PIN.';
                    } elseif ($codeError !== '') {
                        $error = $codeError;
                    } elseif ($code !== $confirm) {
                        $error = 'The two PINs do not match.';
                    } else {
                        lex_messages_lock_set_hash($userId, $code);
                        lex_messages_lock_mark_unlocked($userId);
                        lex_audit('reset_messages_pin', 'users', (string) $userId, $userId);
                        lex_flash_set('success', 'PIN reset. Use the new PIN on Messages after you sign out.');
                        if (function_exists('lex_redirect_app_file')) {
                            lex_redirect_app_file('chat.php');
                        }
                        header('Location: ' . lex_messages_lock_url($user));
                        exit;
                    }
                } elseif ($action === 'change_messages_pin') {
                    if (!lex_messages_lock_is_unlocked($userId)) {
                        $error = 'Unlock Messages with your current PIN first.';
                    } else {
                        $current = lex_messages_lock_normalize_code((string) ($_POST['messages_pin_current'] ?? ''));
                        $code = lex_messages_lock_normalize_code((string) ($_POST['messages_pin'] ?? ''));
                        $confirm = lex_messages_lock_normalize_code((string) ($_POST['messages_pin_confirm'] ?? ''));
                        $hash = lex_messages_lock_hash($userId);
                        $codeError = lex_messages_lock_code_error($code);
                        if ($hash === '' || !password_verify($current, $hash)) {
                            $error = 'Current PIN is incorrect.';
                        } elseif ($codeError !== '') {
                            $error = $codeError;
                        } elseif ($code !== $confirm) {
                            $error = 'The two new PINs do not match.';
                        } elseif ($code === $current) {
                            $error = 'Choose a different PIN from the one you use now.';
                        } else {
                            lex_messages_lock_set_hash($userId, $code);
                            lex_messages_lock_mark_unlocked($userId);
                            lex_audit('change_messages_pin', 'users', (string) $userId, $userId);
                            lex_flash_set('success', 'PIN changed. Use the new PIN the next time you unlock Messages.');
                            if (function_exists('lex_redirect_app_file')) {
                                lex_redirect_app_file('chat.php');
                            }
                            header('Location: ' . lex_messages_lock_url($user));
                            exit;
                        }
                    }
                }
            }
        }

        $hasPin = lex_messages_lock_hash($userId) !== '';
        return !$hasPin || !lex_messages_lock_is_unlocked($userId);
    }
}

if (!function_exists('lex_messages_lock_render_pin_pad')) {
    function lex_messages_lock_render_pin_pad(string $name, string $label, bool $autofocus = false, string $enterKeyHint = 'next'): void
    {
        $hint = $enterKeyHint === 'done' ? 'done' : 'next';
        ?>
      <div class="pin-pad" data-pin-pad>
        <p class="pin-pad-label"><?= lex_e($label) ?></p>
        <label class="pin-entry">
          <span class="pin-dots" data-pin-dots aria-hidden="true"></span>
          <input type="password" name="<?= lex_e($name) ?>" data-pin-input inputmode="numeric" enterkeyhint="<?= lex_e($hint) ?>" pattern="[0-9]{4,8}" maxlength="8" minlength="4" autocomplete="off" aria-label="<?= lex_e($label) ?> (type 4 to 8 digits on your keyboard, then Tab or Next)" required<?= $autofocus ? ' autofocus' : '' ?>>
        </label>
        <p class="pin-keyboard-hint muted">Type the PIN with your keyboard, including on a phone. Tab or Next moves to the next field. Backspace deletes. Enter continues.</p>
        <div class="pin-keys" role="group" aria-label="PIN keypad">
          <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'del'] as $key): ?>
            <?php if ($key === ''): ?>
              <span class="pin-key pin-key-empty" aria-hidden="true"></span>
            <?php elseif ($key === 'del'): ?>
              <button class="pin-key pin-key-del" type="button" data-pin-key="del" tabindex="-1" aria-label="Delete">⌫</button>
            <?php else: ?>
              <button class="pin-key" type="button" data-pin-key="<?= lex_e($key) ?>" tabindex="-1"><?= lex_e($key) ?></button>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
        <?php
    }
}

if (!function_exists('lex_messages_lock_render')) {
    /**
     * @param array<string, mixed> $user
     */
    function lex_messages_lock_render(array $user, string $error): void
    {
        $userId = (int) $user['id'];
        $needsSetup = lex_messages_lock_hash($userId) === '';
        $showForgot = !$needsSetup && ((string) ($_GET['forgot'] ?? '') === '1' || (string) ($_GET['reset'] ?? '') === '1');
        $showChange = !$needsSetup && lex_messages_lock_is_unlocked($userId) && (string) ($_GET['change'] ?? '') === '1';
        $who = ((string) ($user['role'] ?? '')) === 'admin' ? 'admin conversations' : 'client chats';
        lex_page_header('Messages', 'messages', $user);
        ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
<section class="messages-lock-card" data-messages-lock>
  <div class="messages-lock-icon" aria-hidden="true">&#128274;</div>
  <?php if ($needsSetup): ?>
    <h2>Set your message PIN</h2>
    <p class="muted">One PIN unlocks all of your <?= lex_e($who) ?>. After you sign out, enter this same PIN on Messages.</p>
    <form method="post" class="stack-form">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="set_messages_pin">
      <?php lex_messages_lock_render_pin_pad('messages_pin', 'Create PIN', true, 'next'); ?>
      <?php lex_messages_lock_render_pin_pad('messages_pin_confirm', 'Confirm PIN', false, 'done'); ?>
      <button class="button button-primary" type="submit">Save PIN</button>
    </form>
  <?php elseif ($showForgot): ?>
    <h2>Forgot PIN</h2>
    <p class="muted">Confirm your account password, then tap a new 4–8 digit PIN. This replaces the old PIN for all conversations.</p>
    <form method="post" class="stack-form">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="reset_messages_pin">
      <label>Account password
        <input type="password" name="current_password" autocomplete="current-password" required>
      </label>
      <?php lex_messages_lock_render_pin_pad('messages_pin', 'New PIN', false, 'next'); ?>
      <?php lex_messages_lock_render_pin_pad('messages_pin_confirm', 'Confirm PIN', false, 'done'); ?>
      <button class="button button-primary" type="submit">Reset PIN</button>
    </form>
    <p class="muted"><a href="<?= lex_e(lex_messages_lock_url($user)) ?>">Back to PIN</a></p>
  <?php elseif ($showChange): ?>
    <h2>Change PIN</h2>
    <p class="muted">Enter your current PIN, then choose a new 4–8 digit PIN. The new PIN still unlocks all conversations.</p>
    <form method="post" class="stack-form">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="change_messages_pin">
      <?php lex_messages_lock_render_pin_pad('messages_pin_current', 'Current PIN', true, 'next'); ?>
      <?php lex_messages_lock_render_pin_pad('messages_pin', 'New PIN', false, 'next'); ?>
      <?php lex_messages_lock_render_pin_pad('messages_pin_confirm', 'Confirm new PIN', false, 'done'); ?>
      <button class="button button-primary" type="submit">Change PIN</button>
    </form>
    <p class="muted"><a href="<?= lex_e(lex_messages_lock_url($user)) ?>">Back to messages</a></p>
  <?php else: ?>
    <h2>Enter PIN</h2>
    <p class="muted">One PIN opens every conversation. Only you enter it on this page.</p>
    <form method="post" class="stack-form">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="unlock_messages">
      <?php lex_messages_lock_render_pin_pad('messages_pin', 'PIN', true, 'done'); ?>
      <button class="button button-primary" type="submit">Unlock</button>
    </form>
    <p class="muted"><a href="<?= lex_e(lex_messages_lock_url($user, ['forgot' => 1])) ?>">Forgot PIN?</a></p>
  <?php endif; ?>
</section>
        <?php
        lex_page_footer();
    }
}