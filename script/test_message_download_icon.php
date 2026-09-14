<?php

declare(strict_types=1);

/**
 * Chat pictures, videos, and files use a Messenger-style download icon.
 * php script/test_message_download_icon.php
 */

$failed = 0;
$passed = 0;

function lex_mdi_assert(string $label, bool $ok, string $detail = ''): void
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

lex_mdi_assert('Pictures have a download overlay', str_contains($shared, 'inbox-media--image') && str_contains($shared, 'inbox-media-download'));
lex_mdi_assert('Videos have a download overlay', str_contains($shared, 'inbox-media--video') && str_contains($shared, '<video'));
lex_mdi_assert('Files have a download icon', str_contains($shared, 'inbox-file') && str_contains($shared, 'inbox-file-icon'));
lex_mdi_assert('Download still uses the attachment endpoint', str_contains($shared, 'lex_messages_attachment_url($attachId, false)'));
lex_mdi_assert('Pictures still open in the viewer', str_contains($shared, 'inbox-media-open') && str_contains($shared, 'lex_messages_view_url'));
lex_mdi_assert('Old View/Download buttons are gone from the thread', !str_contains($shared, '>View</a>') && !str_contains($shared, '>Download</a>'));
lex_mdi_assert('CSS draws a circular download control', str_contains($style, 'Messenger-style chat download icon') && str_contains($style, '.inbox-media-download'));
lex_mdi_assert('Image tiles stay large enough for the overlay', str_contains($style, 'min-width: 168px') && str_contains($style, '.inbox-media--image'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
