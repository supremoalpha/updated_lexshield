<?php

declare(strict_types=1);

/**
 * Send one SMTP test message using the same mailer as the app.
 *
 * Usage:
 *   php scripts/test_smtp.php you@example.com
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';

$to = lex_sanitize_email((string) ($argv[1] ?? lex_env('MAIL_USER', '') ?? ''));
if ($to === '') {
    fwrite(STDERR, "Usage: php scripts/test_smtp.php you@example.com\n");
    exit(1);
}

$ok = lex_send_email(
    $to,
    'LEXSHIELD SMTP test',
    "SMTP is configured and LEXSHIELD can send mail.\n\nSent at " . date('c') . '.'
);

if ($ok) {
    fwrite(STDOUT, "Sent test message to {$to}\n");
    exit(0);
}

fwrite(STDERR, 'SMTP failed: ' . (lex_mail_error() ?: 'unknown error') . PHP_EOL);
exit(1);
