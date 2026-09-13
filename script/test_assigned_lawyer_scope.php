<?php

declare(strict_types=1);

/**
 * New cases stay with the logged-in attorney and only their booked clients.
 * php script/test_assigned_lawyer_scope.php
 */

$failed = 0;
$passed = 0;

function lex_als_assert(string $label, bool $ok, string $detail = ''): void
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
$helpers = (string) file_get_contents($root . '/config/case_files/helpers.php');
$index = (string) file_get_contents($root . '/files/cases/index.php');
$js = (string) file_get_contents($root . '/public/js/case-files.js');

lex_als_assert('Create ignores a posted assigned lawyer', str_contains($core, '$lawyerUserId = (int) $user[\'id\']'));
lex_als_assert('Create requires a client booked with this attorney', str_contains($core, 'lex_case_files_client_booked_with_lawyer'));
lex_als_assert('Case Files loads booked clients only', str_contains($index, 'lex_case_files_clients_for_lawyer'));
lex_als_assert('Create form has no assigned-lawyer field', !str_contains($helpers, 'name="assigned_lawyer_user_id"'));
lex_als_assert('Script does not require an assigned lawyer', !str_contains($js, 'Select an assigned lawyer'));

$alphamel = 24;
$juan = 26;
$clients = lex_case_files_clients_for_lawyer($alphamel);
$ids = array_map(static fn (array $row): int => (int) $row['id'], $clients);
lex_als_assert('Alphamel sees booked client Juan', in_array($juan, $ids, true));
lex_als_assert('Juan is marked booked with alphamel', lex_case_files_client_booked_with_lawyer($juan, $alphamel));
lex_als_assert('Unknown client is not booked with alphamel', !lex_case_files_client_booked_with_lawyer(999999, $alphamel));

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'csrf_token' => 'invalid',
    'action' => 'create',
    'full_name' => 'Nobody',
    'client_user_id' => '999999',
    'assigned_lawyer_user_id' => '23',
    'case_file_title' => 'Should fail',
    'status' => 'open',
];
$result = lex_case_files_handle_post(
    lex_pdo(),
    ['id' => $alphamel, 'role' => 'lawyer'],
    ['q' => '', 'status' => 'all', 'sort' => 'updated_at', 'dir' => 'DESC', 'page' => 1, 'folder' => 0, 'role' => 'lawyer'],
    [],
    []
);
lex_als_assert('Invalid CSRF is rejected first', ($result['failed_action'] ?? '') === 'create' && str_contains((string) ($result['error'] ?? ''), 'CSRF'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
