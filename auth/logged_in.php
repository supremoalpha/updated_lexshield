<?php

declare(strict_types=1);

/**
 * Older post-login URL. Prefer root go.php so Apache never redirects
 * into /client/index.php after OTP (that path 404s on XAMPP).
 */
$go = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'go.php';
if (is_file($go)) {
    require $go;
    exit;
}

require_once __DIR__ . '/../config/bootstrap.php';
$user = lex_require_login();
$role = strtolower(trim((string) ($user['role'] ?? 'client')));
if ($role === 'attorney') {
    $role = 'lawyer';
}
if (!in_array($role, ['admin', 'lawyer', 'client'], true)) {
    $role = 'client';
}
$home = dirname(__DIR__) . DIRECTORY_SEPARATOR . $role . DIRECTORY_SEPARATOR . 'index.php';
$bundle = __DIR__ . DIRECTORY_SEPARATOR . $role . '_home.php';
if (is_file($home)) {
    require $home;
    exit;
}
if (is_file($bundle)) {
    require $bundle;
    exit;
}

http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
echo '<p>Copy <code>go.php</code> into the lexshield folder, then open <code>/lexshield/go.php</code>.</p>';
