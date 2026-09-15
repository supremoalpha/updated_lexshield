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
lex_mmc_assert('Dark theme activates Messenger dark chrome', str_contains($style, 'Messages: activate dark-mode Messenger theme') && str_contains($style, 'html[data-theme="dark"] body:has(.inbox-messenger) .messages-layout.inbox-messenger') && str_contains($style, 'background: #242526 !important'));
lex_mmc_assert('Dark received bubbles use Messenger gray', str_contains($style, 'html[data-theme="dark"] body:has(.inbox-messenger) .inbox-messenger .chat-bubble.received') && str_contains($style, 'background: #3a3b3c !important'));
lex_mmc_assert('Sent bubbles stay Messenger blue', str_contains($style, 'background: #0084ff !important') && str_contains($style, 'border-bottom-right-radius: 4px !important'));
lex_mmc_assert('Received bubbles stay Messenger gray', str_contains($style, 'background: #e4e6eb !important') && str_contains($style, 'border-bottom-left-radius: 4px !important'));
lex_mmc_assert('Composer still uses the Aa pill', str_contains($shared, 'placeholder="Aa"') && str_contains($style, 'border-radius: 20px !important'));
lex_mmc_assert('Thread header uses Messenger actions', str_contains($shared, 'inbox-header-actions') && str_contains($shared, 'inbox-head-phishing') && str_contains($shared, 'inbox-head-important'));
lex_mmc_assert('Thread header shows a Messenger profile photo', str_contains($shared, 'inbox-header-avatar') && str_contains($shared, 'lex_profile_avatar_url'));
lex_mmc_assert('CSS shows the header profile photo', str_contains($style, 'Messages: Messenger profile in the thread header') && str_contains($style, '.inbox-header-avatar'));
lex_mmc_assert('Header profile matches the conversation list circle', str_contains($style, 'background: #0084ff !important') && substr_count($style, 'html[data-theme="dark"] body:has(.inbox-messenger) .inbox-messenger .inbox-header-avatar') >= 1);
lex_mmc_assert('Call icon is phishing detection', str_contains($shared, 'title="Phishing detection"') && !str_contains($shared, 'inbox-head-phone'));
lex_mmc_assert('Info control is mark as important', str_contains($shared, 'Mark as important') && str_contains($shared, 'toggle_important') && !str_contains($shared, 'title="Chat info"'));
lex_mmc_assert('Thread status can show Active now', str_contains($shared, 'is-active-now') && str_contains($shared, 'Active now'));
lex_mmc_assert('CSS draws the Messenger thread header', str_contains($style, 'Messages: Messenger thread header') && str_contains($style, '.inbox-header-actions'));
lex_mmc_assert('CSS stops bubbles from overlapping', str_contains($style, 'Messages: unstick overlapping bubbles') && str_contains($style, 'flex-shrink: 0 !important') && str_contains($style, 'overflow-wrap: anywhere !important'));
lex_mmc_assert('Client header can open phishing detection', str_contains($style, 'html body[data-role="client"] .inbox-messenger .inbox-head-phishing') && str_contains($style, 'dialog#phishingDetectorModal.is-open'));
lex_mmc_assert('Phone keyboard keeps the composer on screen', str_contains($style, '--lex-vv-height') && str_contains($shared, 'enterkeyhint="enter"'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
