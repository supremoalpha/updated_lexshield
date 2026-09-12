<?php

declare(strict_types=1);

/**
 * Post-login door for XAMPP.
 *
 * After the email code, open:
 *   http://localhost/lexshield/go.php
 *
 * This file includes the admin / attorney (lawyer) / client dashboard in
 * place. It does not redirect to /client/index.php, which Apache often
 * 404s when the app lives in a folder.
 */
if (defined('LEX_GO_DASHBOARD')) {
    return;
}
define('LEX_GO_DASHBOARD', true);

require_once __DIR__ . '/config/bootstrap.php';

if (function_exists('lex_ensure_role_dashboards')) {
    lex_ensure_role_dashboards();
}

$user = lex_require_login();
$role = strtolower(trim((string) ($user['role'] ?? 'client')));
if ($role === 'attorney') {
    $role = 'lawyer';
}
if (!in_array($role, ['admin', 'lawyer', 'client'], true)) {
    $role = 'client';
}

$findDashboard = static function (string $role): string {
    $root = __DIR__;
    $paths = [
        $root . DIRECTORY_SEPARATOR . $role . DIRECTORY_SEPARATOR . 'index.php',
        $root . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . $role . '_home.php',
    ];
    foreach ($paths as $path) {
        if (function_exists('lex_dashboard_is_real')) {
            if (lex_dashboard_is_real($path)) {
                return $path;
            }
            continue;
        }
        if (is_file($path) && is_readable($path) && filesize($path) > 80) {
            return $path;
        }
    }
    return '';
};

$found = $findDashboard($role);
if ($found === '' && function_exists('lex_dashboard_payload')) {
    $source = lex_dashboard_payload($role);
    if ($source !== '') {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . $role;
        $file = $dir . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && @file_put_contents($file, $source) !== false) {
            $found = $file;
        }
    }
}
if ($found === '') {
    $fallback = __DIR__ . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . $role . '_home.php';
    if (is_file($fallback) && is_readable($fallback)) {
        $found = $fallback;
    }
}

if ($found !== '') {
    require $found;
    exit;
}

http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
$name = htmlspecialchars((string) ($user['full_name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
$roleSafe = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>LEXSHIELD</title></head>'
    . '<body style="font-family:Segoe UI,sans-serif;background:#0f1419;color:#e7eef7;padding:2rem;max-width:40rem;margin:8vh auto;line-height:1.5">'
    . '<h1>You are signed in</h1>'
    . '<p>Hello ' . $name . ' (' . $roleSafe . '). Copy the <code>client</code>, <code>lawyer</code>, and <code>admin</code> folders into <code>htdocs\\lexshield</code>, then open this page again.</p>'
    . '</body></html>';
