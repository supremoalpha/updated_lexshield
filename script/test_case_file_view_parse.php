<?php

declare(strict_types=1);

/**
 * Broken XAMPP view.php copies are rewritten to a parse-safe stub.
 * php script/test_case_file_view_parse.php
 */

$failed = 0;
$passed = 0;

function lex_view_parse_assert(string $label, bool $ok, string $detail = ''): void
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

$view = (string) file_get_contents($root . '/files/cases/view.php');
$viewer = (string) file_get_contents($root . '/config/case_files/viewer.php');
$boot = (string) file_get_contents($root . '/config/bootstrap.php');

lex_view_parse_assert('view.php parses', lex_php_source_parses($view));
lex_view_parse_assert('view.php is a stub that loads viewer.php', str_contains($view, 'config/case_files/viewer.php') && !str_contains($view, '$canPreview'));
lex_view_parse_assert('viewer.php parses', lex_php_source_parses($viewer));
lex_view_parse_assert('viewer.php still renders video', str_contains($viewer, 'case-file-view-video') && str_contains($viewer, '<video'));
lex_view_parse_assert('viewer.php still tiles the watermark', str_contains($viewer, 'background-image:url') && str_contains($viewer, 'case-file-view-watermark'));
lex_view_parse_assert('Bootstrap restores a broken viewer copy', str_contains($boot, 'lex_require_case_file_viewer_files'));
lex_view_parse_assert('Packed viewer source matches the live file markers', str_contains(lex_case_file_viewer_packed_source(), 'case-file-view-watermark'));

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lex-view-heal-' . bin2hex(random_bytes(4));
$cases = $tmp . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'cases';
$cfg = $tmp . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'case_files';
mkdir($cases, 0775, true);
mkdir($cfg, 0775, true);

$broken = <<<'PHP'
<?php
require_once __DIR__ . '/../../config/bootstrap.php';

$user = lex_require_login();
lex_case_files_table_ensure();

$title = 'View case file';
$fileName = 'File';
$mime = '';
$sourceUrl = ''
$canPreview = false;
PHP;
file_put_contents($cases . DIRECTORY_SEPARATOR . 'view.php', $broken);

lex_view_parse_assert(
    'Truncated XAMPP view.php does not parse',
    !lex_php_source_parses($broken)
);

$healed = lex_require_case_file_viewer_files($tmp);
$restoredView = (string) file_get_contents($cases . DIRECTORY_SEPARATOR . 'view.php');
$restoredViewer = (string) file_get_contents($cfg . DIRECTORY_SEPARATOR . 'viewer.php');

lex_view_parse_assert('Self-heal reports success', $healed);
lex_view_parse_assert('Broken view.php was rewritten', lex_php_source_parses($restoredView) && str_contains($restoredView, 'config/case_files/viewer.php'));
lex_view_parse_assert('Rewritten view.php no longer declares $canPreview', !str_contains($restoredView, '$canPreview'));
lex_view_parse_assert('Missing viewer.php was unpacked', lex_php_source_parses($restoredViewer) && str_contains($restoredViewer, '$canPreview'));

$purge = static function (string $dir) use (&$purge): void {
    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $purge($path);
            @rmdir($path);
            continue;
        }
        @unlink($path);
    }
};
$purge($tmp);
@rmdir($tmp);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
