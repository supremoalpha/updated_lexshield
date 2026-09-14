<?php

declare(strict_types=1);

/**
 * New case opens because the case-files script is on the page.
 * php script/test_new_case_modal.php
 */

$failed = 0;
$passed = 0;

function lex_new_case_assert(string $label, bool $ok, string $detail = ''): void
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
$index = (string) file_get_contents($root . '/files/cases/index.php');
$helpers = (string) file_get_contents($root . '/config/case_files/helpers.php');
$js = (string) file_get_contents($root . '/public/js/case-files.js');

lex_new_case_assert('Case Files page loads case-files.js', str_contains($index, 'public/js/case-files.js'));
lex_new_case_assert('New case has an inline opener if the script file is missing', str_contains($index, "closest('[data-case-create-open]')") && str_contains($index, "classList.add('is-open')"));
lex_new_case_assert('New case opens the create modal', str_contains($index, 'href="#case-create-modal"') && str_contains($index, 'data-case-create-open'));
lex_new_case_assert('Create modal has a target id', str_contains($helpers, 'id="case-create-modal"') && str_contains($helpers, 'data-case-create-modal'));
lex_new_case_assert('Script still opens the create modal', str_contains($js, 'data-case-create-open') && str_contains($js, 'openCreateModal'));
lex_new_case_assert('Attorney role is treated as lawyer for case files', str_contains($helpers, "\$role === 'attorney'"));
lex_new_case_assert('New case has no assigned-lawyer picker', !str_contains($helpers, 'name="assigned_lawyer_user_id"'));
lex_new_case_assert('Case list is limited to the assigned attorney', str_contains($helpers, 'cf.assigned_lawyer_user_id = :uid1') && !str_contains($helpers, 'created_by_user_id = :uid2'));
lex_new_case_assert('Create form uses case-form so compact modal CSS applies', str_contains($helpers, 'data-persist-form="create"') && str_contains($helpers, 'case-form form-grid'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
