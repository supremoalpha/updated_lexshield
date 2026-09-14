<?php

declare(strict_types=1);

/**
 * Login page shows a warning for a wrong email or password.
 * php script/test_login_warning.php
 */

$failed = 0;
$passed = 0;

function lex_login_assert(string $label, bool $ok, string $detail = ''): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}" . ($detail !== '' ? "\n  {$detail}" : '') . "\n";
}

$login = (string) file_get_contents(dirname(__DIR__) . '/auth/login.php');
$style = (string) file_get_contents(dirname(__DIR__) . '/public/css/style.css');

lex_login_assert('Wrong password sets a credential warning', str_contains($login, '$credentialError = true') && str_contains($login, 'Incorrect email or password. Please try again.'));
lex_login_assert('Unknown email uses the same warning', substr_count($login, 'Incorrect email or password. Please try again.') >= 2);
lex_login_assert('Login warning markup is visible', str_contains($login, 'pao-login-warning') && str_contains($login, 'Incorrect email or password'));
lex_login_assert('Warning uses a visible icon and copy block', str_contains($login, 'pao-login-warning-icon') && str_contains($login, 'pao-login-warning-copy'));
lex_login_assert('Failed login keeps the typed email', str_contains($login, 'value="<?= lex_e($loginEmail) ?>"'));
lex_login_assert('Password field is marked invalid after a failed attempt', str_contains($login, 'aria-invalid="true"'));
lex_login_assert('Card marks the failed attempt', str_contains($login, 'pao-login-card--error'));
lex_login_assert('Warning banner is styled on the login card', str_contains($style, 'Login page: professional responsive card') && str_contains($style, '.pao-login-warning'));
lex_login_assert('Login card is responsive', str_contains($style, 'body.pao-login .pao-login-card') && str_contains($style, 'min(100%, 28.5rem)'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
