<?php

declare(strict_types=1);

/**
 * Front controller for `php -S`. Blocks secrets and private trees that
 * would otherwise be served as static files (notably `.env`).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';
if ($path === '') {
    $path = '/';
}

$blockedExact = [
    '/.env',
    '/.env.local',
    '/.env.example',
    '/.gitignore',
    '/router.php',
    '/composer.json',
    '/composer.lock',
    '/package.json',
    '/package-lock.json',
    '/server.js',
];

$blockedPrefixes = [
    '/.env.',
    '/.git',
    '/.cursor',
    '/sql/',
    '/storage/',
    '/vendor/',
    '/config/',
    '/security/',
    '/scripts/',
    '/node_modules/',
];

$isBlocked = in_array($path, $blockedExact, true);
if (!$isBlocked) {
    foreach ($blockedPrefixes as $prefix) {
        if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
            $isBlocked = true;
            break;
        }
    }
}
if (!$isBlocked && preg_match('/(?:^|\/)\.env(?:\.|$)/', $path)) {
    $isBlocked = true;
}

if ($isBlocked) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Not found';
    return true;
}

$folderName = basename(__DIR__);
$candidates = [$path];
if ($folderName !== '' && $folderName !== '.' && (str_starts_with($path, '/' . $folderName . '/') || $path === '/' . $folderName)) {
    $candidates[] = $path === '/' . $folderName ? '/' : substr($path, strlen($folderName) + 1);
}

foreach ($candidates as $candidate) {
    $requested = __DIR__ . $candidate;
    if (is_file($requested)) {
        if (str_ends_with(strtolower($requested), '.php')) {
            require $requested;
            return true;
        }
        return false;
    }
    if (is_dir($requested)) {
        $index = rtrim($requested, '/') . '/index.php';
        if (is_file($index)) {
            require $index;
            return true;
        }
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not found';
return true;
