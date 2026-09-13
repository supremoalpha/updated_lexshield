<?php

declare(strict_types=1);

/**
 * Chat attachments open in a viewer first; vaults have organized folders.
 * php script/test_message_view_vault_folders.php
 */

$failed = 0;
$passed = 0;

function lex_mvf_assert(string $label, bool $ok, string $detail = ''): void
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
require_once $root . '/config/bootstrap.php';
require_once $root . '/config/case_files/helpers.php';

$shared = (string) file_get_contents($root . '/config/messages/shared.php');
$core = (string) file_get_contents($root . '/config/messages/core.php');
$helpers = (string) file_get_contents($root . '/config/case_files/helpers.php');
$actions = (string) file_get_contents($root . '/config/case_files/core.php');
$view = (string) file_get_contents($root . '/message_view.php');

lex_mvf_assert('Images are previewable in chat', lex_messages_previewable_mime('image/jpeg', 'photo.jpg'));
lex_mvf_assert('Chat preview URL uses preview=1', str_contains(lex_messages_attachment_url(9, true), 'preview=1'));
lex_mvf_assert('Chat view page URL exists', str_contains(lex_messages_view_url(9), 'message_view.php?id=9'));
lex_mvf_assert('Thread shows View before Download', str_contains($shared, '>View</a>') && str_contains($shared, 'lex_messages_view_url'));
lex_mvf_assert('Inline images use the preview URL', str_contains($shared, 'lex_messages_attachment_url($attachId, true)'));
lex_mvf_assert('Download is no longer the only attachment action', str_contains($core, 'Content-Disposition: inline'));
lex_mvf_assert('Message viewer page exists', str_contains($view, 'Back to messages') && str_contains($view, 'previewUrl'));
lex_mvf_assert('Default vault folders are Documents, Pictures, Videos', lex_case_file_default_vault_folder_names() === ['Documents', 'Pictures', 'Videos']);
lex_mvf_assert('Pictures go to the Pictures folder', lex_case_file_suggested_folder_name('image/png', 'a.png') === 'Pictures');
lex_mvf_assert('Videos go to the Videos folder', lex_case_file_suggested_folder_name('video/mp4', 'a.mp4') === 'Videos');
lex_mvf_assert('PDFs go to the Documents folder', lex_case_file_suggested_folder_name('application/pdf', 'a.pdf') === 'Documents');
lex_mvf_assert('Vault can create a custom folder', str_contains($actions, 'vault_folder_create') && str_contains($helpers, 'Create folder'));
lex_mvf_assert('Vault upload can choose a folder', str_contains($actions, "\$_POST['folder_id']") && str_contains($helpers, 'Auto (Documents, Pictures, or Videos)'));
lex_mvf_assert('New case files get client vault folders', str_contains($actions, 'lex_case_files_ensure_client_vault_tree') || str_contains($actions, 'lex_case_files_ensure_default_vault_folders'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
