<?php

declare(strict_types=1);

/**
 * Message attachment download does not depend on a missing nested file.
 * php script/test_message_attachment_path.php
 */

$failed = 0;
$passed = 0;

function lex_msg_attach_assert(string $label, bool $ok, string $detail = ''): void
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

$root = dirname(__DIR__);
$wrapper = (string) file_get_contents($root . '/message_attachment.php');
$nested = (string) file_get_contents($root . '/files/messages/attachment.php');
$boot = (string) file_get_contents($root . '/config/bootstrap.php');
$core = (string) file_get_contents($root . '/config/messages/core.php');

lex_msg_attach_assert('Root wrapper loads bootstrap first', str_contains($wrapper, "require_once __DIR__ . '/config/bootstrap.php'"));
lex_msg_attach_assert('Root wrapper calls the send helper', str_contains($wrapper, 'lex_messages_send_attachment()'));
lex_msg_attach_assert('Root wrapper no longer fatals on a missing nested file', !preg_match('/^require_once __DIR__ \. \'\/files\/messages\/attachment\.php\';/m', $wrapper));
lex_msg_attach_assert('Nested attachment.php exists with the correct name', is_file($root . '/files/messages/attachment.php'));
lex_msg_attach_assert('Nested file uses the send helper', str_contains($nested, 'lex_messages_send_attachment()'));
lex_msg_attach_assert('Core defines lex_messages_send_attachment', str_contains($core, 'function lex_messages_send_attachment'));
lex_msg_attach_assert('Bootstrap recreates the missing nested file', str_contains($boot, 'function lex_require_message_attachment_file') && str_contains($boot, 'lex_require_message_attachment_file();'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
