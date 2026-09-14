<?php

declare(strict_types=1);

/**
 * Case Files CSS matches the current list, summary, vault, and modal HTML.
 * php script/test_case_files_css.php
 */

$failed = 0;
$passed = 0;

function lex_css_assert(string $label, bool $ok, string $detail = ''): void
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
$style = (string) file_get_contents($root . '/public/css/style.css');
$helpers = (string) file_get_contents($root . '/config/case_files/helpers.php');

lex_css_assert('Repair block is last-wins in style.css', str_contains($style, 'Case Files layout repair: match current HTML, dark vault, usable modal.'));
lex_css_assert('Summary uses case-stats KPI cards, not admin dashboard stats', str_contains($helpers, 'class="case-stats"') && str_contains($helpers, 'class="kpi-card"') && !str_contains($helpers, 'admin-dashboard-stats'));
lex_css_assert('KPI cards are a single content column', str_contains($style, '#case-files-summary .kpi-card') && str_contains($style, 'grid-template-columns: minmax(0, 1fr)'));
lex_css_assert('Case list items have dedicated card styles', str_contains($style, '.case-list-item') && str_contains($helpers, 'class="card case-list-item'));
lex_css_assert('Selected case list items are marked', str_contains($helpers, 'is-active is-selected'));
lex_css_assert('Vault folder cards get a dark-theme override', str_contains($style, '.case-vault-folder-card') && str_contains($style, 'linear-gradient(180deg, #13243f, #0d1a30)'));
lex_css_assert('Create modal sits above the page chrome', str_contains($style, 'z-index: 6200'));
lex_css_assert('New case form uses the compact case-form grid', str_contains($helpers, 'class="modal-body stack-form case-form form-grid"') && str_contains($helpers, 'data-persist-form="create"'));
lex_css_assert('Editor wrapper does not trap the modal', str_contains($style, '#case-files-editor') && str_contains($style, 'display: contents'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
