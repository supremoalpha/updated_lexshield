<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_current_user();
if ($user) {
    lex_audit('logout', 'users', (string) $user['id'], (int) $user['id']);
}
lex_logout_session();
header('Location: ' . lex_app_url('auth/login.php'));
exit;
