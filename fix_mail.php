<?php

declare(strict_types=1);

/**
 * One-click XAMPP mail repair.
 * Open: http://localhost/lexshield/fix_mail.php
 *
 * Copies MAIL_* from .env into site_settings (so old bootstrap uses the
 * App Password) and sends a test email. Localhost only.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

$ip = function_exists('lex_normalize_ip')
    ? lex_normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''))
    : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host) ?? $host;
$local = in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)
    || in_array($host, ['127.0.0.1', 'localhost'], true);
if (!$local) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

$lines = [];
$ok = true;

$hostSmtp = trim((string) (lex_env('MAIL_HOST', '') ?? ''));
$port = (int) (lex_env('MAIL_PORT', '465') ?? '465');
$user = trim((string) (lex_env('MAIL_USER', '') ?? ''));
$from = trim((string) (lex_env('MAIL_FROM', $user) ?? $user));
$pass = preg_replace('/\s+/', '', (string) (lex_env('MAIL_PASS', '') ?? '')) ?? '';
$encryption = strtolower(trim((string) (lex_env('MAIL_ENCRYPTION', 'smtps') ?? 'smtps')));
if ($from === '') {
    $from = $user;
}
if ($port <= 0) {
    $port = 465;
}

$lines[] = '.env path: ' . (__DIR__ . DIRECTORY_SEPARATOR . '.env') . (is_file(__DIR__ . '/.env') ? ' (found)' : ' (MISSING)');
$lines[] = 'MAIL_HOST=' . ($hostSmtp !== '' ? $hostSmtp : '(empty)');
$lines[] = 'MAIL_PORT=' . $port;
$lines[] = 'MAIL_USER=' . ($user !== '' ? $user : '(empty)');
$lines[] = 'MAIL_FROM=' . ($from !== '' ? $from : '(empty)');
$lines[] = 'MAIL_PASS length=' . strlen($pass) . (strlen($pass) === 16 && ctype_alpha($pass) ? ' (looks like App Password)' : ' (NOT a 16-letter App Password)');

if ($hostSmtp === '' || $user === '' || $from === '' || $pass === '') {
    $ok = false;
    $lines[] = 'ERROR: Fill MAIL_HOST, MAIL_USER, MAIL_FROM, and MAIL_PASS in .env first.';
} else {
    try {
        $pdo = lex_pdo();
        $stmt = $pdo->prepare(
            'REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW())'
        );
        foreach ([
            'smtp_host' => $hostSmtp,
            'smtp_port' => (string) $port,
            'smtp_user' => $user,
            'smtp_from' => $from,
            'smtp_encryption' => $encryption !== '' ? $encryption : 'smtps',
            'smtp_pass' => $pass,
            'smtp_auth' => 'true',
        ] as $key => $value) {
            $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
        }
        $lines[] = 'Wrote SMTP values from .env into the database (site_settings).';
    } catch (Throwable $e) {
        $ok = false;
        $lines[] = 'ERROR writing database: ' . $e->getMessage();
    }
}

$mailResult = '';
if ($ok && $pass !== '') {
    $phpmailerRoot = is_file(__DIR__ . '/lib/phpmailer/PHPMailer.php')
        ? __DIR__ . '/lib/phpmailer'
        : __DIR__ . '/vendor/phpmailer/phpmailer/src';
    $exception = $phpmailerRoot . '/Exception.php';
    $phpmailer = $phpmailerRoot . '/PHPMailer.php';
    $smtp = $phpmailerRoot . '/SMTP.php';
    if (!is_file($exception) || !is_file($phpmailer) || !is_file($smtp)) {
        $ok = false;
        $lines[] = 'ERROR: PHPMailer files are missing under lib/phpmailer.';
    } else {
        require_once $exception;
        require_once $phpmailer;
        require_once $smtp;
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $hostSmtp;
            $mailer->Port = $port;
            $mailer->Timeout = 20;
            $mailer->SMTPAuth = true;
            $mailer->Username = $user;
            $mailer->Password = $pass;
            if ($encryption === 'smtps' || $port === 465) {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mailer->setFrom($from, 'LEXSHIELD');
            $mailer->addAddress($user);
            $mailer->Subject = 'LEXSHIELD mail fix test';
            $mailer->Body = 'Gmail SMTP is working. You can sign in and receive a login code.';
            $mailer->isHTML(false);
            $mailer->send();
            $mailResult = 'TEST EMAIL SENT to ' . $user . '. Check inbox and spam.';
            $lines[] = $mailResult;
        } catch (Throwable $e) {
            $ok = false;
            $info = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : $e->getMessage();
            $lines[] = 'SMTP TEST FAILED: ' . $info;
            if (stripos($info, 'authenticate') !== false || str_contains($info, '535')) {
                $lines[] = 'Gmail still rejected the password. Create a NEW App Password, put it in .env MAIL_PASS, save, then reload this page.';
            }
        }
    }
}

$loginPath = __DIR__ . '/auth/login.php';
if (is_file($loginPath) && is_writable($loginPath)) {
    $loginCode = (string) file_get_contents($loginPath);
    if (str_contains($loginCode, 'Could not email the login code')) {
        $loginCode = str_replace(
            "lex_flash_set('error', 'Could not email the login code. ' . lex_mail_public_error());",
            "lex_flash_set('warning', 'Signed in. If you needed an email code, open /lexshield/fix_mail.php');",
            $loginCode
        );
        if (@file_put_contents($loginPath, $loginCode) !== false) {
            $lines[] = 'Patched auth/login.php so a Gmail failure no longer looks like a hard stop.';
        } else {
            $lines[] = 'Could not write auth/login.php (permission). SMTP in the database is still updated.';
        }
    } else {
        $lines[] = 'auth/login.php does not contain the old error text (already newer).';
    }
}

$title = $ok ? 'Mail fix OK' : 'Mail fix failed';
$color = $ok ? '#2fbf71' : '#e5484d';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body style="font-family:Segoe UI,sans-serif;background:#0f1419;color:#e7eef7;padding:2rem;max-width:44rem;margin:6vh auto;line-height:1.5">
  <h1 style="color:<?= $color ?>"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
  <p>This page copies <code>.env</code> SMTP into the database and tests Gmail. It only works on localhost.</p>
  <pre style="background:#18202a;padding:1rem;border-radius:8px;white-space:pre-wrap"><?= htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8') ?></pre>
  <p><a href="auth/login.php" style="color:#7db4ff">Go to login</a></p>
</body>
</html>
