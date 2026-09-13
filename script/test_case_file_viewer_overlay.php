<?php

declare(strict_types=1);

/**
 * View-only case file stamps stay on the preview and PDFs can be framed.
 * php script/test_case_file_viewer_overlay.php
 */

$failed = 0;
$passed = 0;

function lex_viewer_assert(string $label, bool $ok, string $detail = ''): void
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
$view = (string) file_get_contents($root . '/files/cases/view.php')
    . (string) file_get_contents($root . '/config/case_files/viewer.php');
$style = (string) file_get_contents($root . '/public/css/style.css');
$boot = (string) file_get_contents($root . '/config/bootstrap.php');
$core = (string) file_get_contents($root . '/config/case_files/core.php');
$document = (string) file_get_contents($root . '/files/cases/document.php');

lex_viewer_assert('Viewer no longer dumps 24 watermark text spans', !str_contains($view, 'for ($i = 0; $i < 24; $i++)'));
lex_viewer_assert('Viewer uses a CSS tiled watermark', str_contains($view, 'background-image:url') && str_contains($view, 'case-file-view-watermark'));
lex_viewer_assert('Watermark is view-only only', str_contains($view, 'if ($viewOnly):') && str_contains($view, 'case-file-view-watermark'));
lex_viewer_assert('Inline CSS pins the watermark over the stage', str_contains($view, 'position: absolute !important') && str_contains($view, 'overflow: hidden !important'));
lex_viewer_assert('Stylesheet also contains the tiled watermark rules', str_contains($style, '.case-file-view-watermark') && str_contains($style, 'background-repeat: repeat'));
lex_viewer_assert('Preview embeds are allowed in the same origin', str_contains($boot, '$lexCaseFilePreviewEmbed') && str_contains($boot, "\"'self'\""));
lex_viewer_assert('Viewer page may embed same-origin PDFs', str_contains($boot, "frame-src 'self'"));
lex_viewer_assert('Core can build a watermark data URI', str_contains($core, 'function lex_case_file_watermark_data_uri'));
lex_viewer_assert('Preview responses replace frame-deny headers', str_contains($document, 'lex_case_file_send_view_only_headers') || str_contains($document, "X-Frame-Options: SAMEORIGIN"));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
