<?php

declare(strict_types=1);

/**
 * Messages inbox uses Facebook Messenger chrome.
 * php script/test_messages_messenger_css.php
 */

$failed = 0;
$passed = 0;

function lex_mmc_assert(string $label, bool $ok, string $detail = ''): void
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
$shared = (string) file_get_contents($root . '/config/messages/shared.php');
$style = (string) file_get_contents($root . '/public/css/style.css');

lex_mmc_assert('Composer send is an icon button', str_contains($shared, 'inbox-send-btn') && str_contains($shared, 'aria-label="Send"') && !str_contains($shared, '>Send</button>'));
lex_mmc_assert('Composer attach uses a plus icon', str_contains($shared, 'inbox-attach-btn') && str_contains($shared, 'data-attachment-input'));
lex_mmc_assert('Video call label is screen-reader text', str_contains($shared, 'inbox-vc-label') && str_contains($shared, 'Video call'));
lex_mmc_assert('Last-wins CSS forces Messenger white chrome', str_contains($style, 'Messages: Facebook Messenger chrome') && str_contains($style, 'background: #fff !important'));
lex_mmc_assert('Sent bubbles stay Messenger blue', str_contains($style, 'background: #0084ff !important') && str_contains($style, 'border-bottom-right-radius: 4px !important'));
lex_mmc_assert('Received bubbles stay Messenger gray', str_contains($style, 'background: #e4e6eb !important') && str_contains($style, 'border-bottom-left-radius: 4px !important'));
lex_mmc_assert('Composer still uses the Aa pill', str_contains($shared, 'placeholder="Aa"') && str_contains($style, 'border-radius: 20px !important'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
