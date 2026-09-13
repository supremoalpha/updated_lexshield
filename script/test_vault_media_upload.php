<?php

declare(strict_types=1);

/**
 * Vault accepts pictures and videos and can preview them.
 * php script/test_vault_media_upload.php
 */

$failed = 0;
$passed = 0;

function lex_vault_assert(string $label, bool $ok, string $detail = ''): void
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

$core = (string) file_get_contents($root . '/config/case_files/core.php');
$actions = (string) file_get_contents($root . '/config/case_files/actions.php');
$helpers = (string) file_get_contents($root . '/config/case_files/helpers.php');
$view = (string) file_get_contents($root . '/files/cases/view.php')
    . (string) file_get_contents($root . '/config/case_files/viewer.php');
$document = (string) file_get_contents($root . '/files/cases/document.php');
$userIni = (string) file_get_contents($root . '/.user.ini');

lex_vault_assert('Guessed MP4 mime is video/mp4', lex_case_file_guess_mime('', 'clip.mp4') === 'video/mp4');
lex_vault_assert('Guessed JPG mime is image/jpeg', lex_case_file_guess_mime('', 'photo.jpg') === 'image/jpeg');
lex_vault_assert('Videos are previewable', lex_case_file_previewable_mime('video/mp4', 'clip.mp4'));
lex_vault_assert('Pictures are previewable', lex_case_file_previewable_mime('image/png', 'shot.png'));
lex_vault_assert('MP4 uploads are allowed', lex_case_file_is_allowed_upload('video/mp4', 'clip.mp4'));
lex_vault_assert('PNG uploads are allowed', lex_case_file_is_allowed_upload('image/png', 'shot.png'));
lex_vault_assert('EXE uploads are blocked', !lex_case_file_is_allowed_upload('application/x-msdownload', 'setup.exe'));
lex_vault_assert('Vault upload limit is 80 MB', lex_case_file_upload_max_bytes() === 80 * 1024 * 1024);
lex_vault_assert('File picker accepts images and videos', str_contains(lex_case_file_upload_accept(), 'image/*') && str_contains(lex_case_file_upload_accept(), 'video/*'));
lex_vault_assert('Vault form mentions picture and video', str_contains($helpers, 'Upload a picture, video, or document') && str_contains($helpers, 'accept='));
lex_vault_assert('Vault upload action validates media types', str_contains($core, 'lex_case_file_is_allowed_upload') && (str_contains($core, '80 MB') || str_contains($actions, '80 MB')));
lex_vault_assert('Viewer renders a video player', str_contains($view, 'case-file-view-video') && str_contains($view, '<video'));
lex_vault_assert('Preview headers include video', str_contains($document, 'video\\/') || str_contains($document, 'video/'));
lex_vault_assert('PHP upload limit file allows 80M', str_contains($userIni, 'upload_max_filesize = 80M') && str_contains($userIni, 'post_max_size = 80M'));
lex_vault_assert('Core helpers expose media upload APIs', str_contains($core, 'function lex_case_file_is_allowed_upload'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
