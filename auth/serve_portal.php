<?php

declare(strict_types=1);

/**
 * Serves a portal page Apache could not find on disk.
 * Copy this file AND auth/portal_pack.php into htdocs\lexshield\auth\
 */
$pack = __DIR__ . DIRECTORY_SEPARATOR . 'portal_pack.php';
if (is_file($pack)) {
    require_once $pack;
}

require_once __DIR__ . '/../config/bootstrap.php';

$role = strtolower((string) preg_replace('/[^a-z]/', '', (string) ($_GET['role'] ?? '')));
$page = basename((string) ($_GET['page'] ?? 'index.php'));
if (!str_ends_with(strtolower($page), '.php')) {
    $page = 'index.php';
}

if (!in_array($role, ['admin', 'lawyer', 'client'], true)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$relative = $role . '/' . $page;
$root = dirname(__DIR__);
$targets = [
    $root . DIRECTORY_SEPARATOR . $role . DIRECTORY_SEPARATOR . $page,
    __DIR__ . DIRECTORY_SEPARATOR . 'portal_' . $role . '_' . $page,
];

$source = function_exists('lex_auth_portal_source') ? lex_auth_portal_source($relative) : '';
if ($source === '' && function_exists('lex_portal_payloads')) {
    $encoded = lex_portal_payloads()[$relative] ?? '';
    if (is_string($encoded) && $encoded !== '') {
        $raw = base64_decode($encoded, true);
        $inflated = is_string($raw) && $raw !== '' ? @gzinflate($raw) : false;
        $source = is_string($inflated) ? $inflated : '';
    }
}

$isRealPortal = static function (string $path): bool {
    if (!is_file($path) || !is_readable($path) || filesize($path) < 80) {
        return false;
    }
    $head = (string) file_get_contents($path, false, null, 0, 400);
    return str_contains($head, '<?php') && str_contains($head, 'bootstrap.php');
};

if ($source !== '') {
    foreach ($targets as $target) {
        if ($isRealPortal($target)) {
            continue;
        }
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir)) {
            @file_put_contents($target, $source);
        }
    }
}

foreach ($targets as $target) {
    if ($isRealPortal($target)) {
        require $target;
        exit;
    }
}

if ($source !== '') {
    $tmp = __DIR__ . DIRECTORY_SEPARATOR . '._run_' . $role . '_' . $page;
    if (@file_put_contents($tmp, $source) !== false) {
        require $tmp;
        exit;
    }
}

http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
$needPack = !is_file($pack);
echo '<!doctype html><html><head><meta charset="utf-8"><title>Missing page</title></head>'
    . '<body style="font-family:Segoe UI,sans-serif;background:#0f1419;color:#e7eef7;padding:2rem;max-width:42rem;margin:8vh auto;line-height:1.5">'
    . '<h1>Find a Lawyer needs one more file</h1>'
    . '<p>Copy <strong>both</strong> of these into <code>C:\\Users\\trioa\\Desktop\\xampp\\htdocs\\lexshield\\auth\\</code>:</p>'
    . '<pre style="background:#18202a;padding:1rem;border-radius:8px">serve_portal.php' . "\n" . 'portal_pack.php</pre>'
    . ($needPack
        ? '<p><code>portal_pack.php</code> is missing. That file contains lawyers.php.</p>'
        : '<p>PHP could not write <code>client\\lawyers.php</code>. Check that <code>client</code> is a folder, not a file, and is writable.</p>')
    . '</body></html>';
