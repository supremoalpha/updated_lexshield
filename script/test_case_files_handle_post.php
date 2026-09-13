<?php

declare(strict_types=1);

/**
 * Case Files POST handler is always available after bootstrap (XAMPP).
 * php script/test_case_files_handle_post.php
 */

$failed = 0;
$passed = 0;

function lex_hp_assert(string $label, bool $ok, string $detail = ''): void
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
$index = (string) file_get_contents($root . '/files/cases/index.php');
$bootstrap = (string) file_get_contents($root . '/config/bootstrap.php');

lex_hp_assert('handle_post exists after bootstrap', function_exists('lex_case_files_handle_post'));
lex_hp_assert('handle_post is defined in core.php', str_contains($core, 'function lex_case_files_handle_post'));
lex_hp_assert('Case Files page does not hard-require actions.php', !str_contains($index, "require_once __DIR__ . '/../../config/case_files/actions.php'"));
lex_hp_assert('Case Files page still calls handle_post', str_contains($index, 'lex_case_files_handle_post('));
lex_hp_assert('Bootstrap recreates a missing actions.php', str_contains($bootstrap, 'lex_require_case_files_actions_file'));

$_SERVER['REQUEST_METHOD'] = 'GET';
$result = lex_case_files_handle_post(
    lex_pdo(),
    ['id' => 1, 'role' => 'lawyer'],
    ['q' => '', 'status' => 'all', 'sort' => 'updated_at', 'dir' => 'DESC', 'page' => 1, 'folder' => 0, 'role' => 'lawyer'],
    [],
    []
);
lex_hp_assert('GET handle_post is a no-op', ($result['error'] ?? 'x') === '' && ($result['failed_action'] ?? 'x') === '');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
