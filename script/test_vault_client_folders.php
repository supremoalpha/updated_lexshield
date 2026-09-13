<?php

declare(strict_types=1);

/**
 * Client booking creates a client vault folder with Documents / Pictures / Videos inside.
 * php script/test_vault_client_folders.php
 */

$failed = 0;
$passed = 0;

function lex_vcf_assert(string $label, bool $ok, string $detail = ''): void
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

$core = (string) file_get_contents($root . '/config/case_files/core.php');
$helpers = (string) file_get_contents($root . '/config/case_files/helpers.php');
$actions = (string) file_get_contents($root . '/config/case_files/core.php');
$appointment = (string) file_get_contents($root . '/client/appointment.php');
$js = (string) file_get_contents($root . '/public/js/case-files.js');

lex_vcf_assert('Folders store a parent id', str_contains($core, '`parent_id` INT NOT NULL DEFAULT 0') && str_contains($core, 'lex_case_file_vault_folder_parent_ensure'));
lex_vcf_assert('Default inner folders stay Documents, Pictures, Videos', lex_case_file_default_vault_folder_names() === ['Documents', 'Pictures', 'Videos']);
lex_vcf_assert('Client folder uses the client name', lex_case_file_client_folder_name(['full_name' => 'Client A']) === 'Client A');
lex_vcf_assert('Type-name clients get a distinct folder title', lex_case_file_client_folder_name(['full_name' => 'Documents']) === 'Documents folder');
lex_vcf_assert('Booking creates the client vault', str_contains($appointment, 'lex_case_files_ensure_for_case'));
lex_vcf_assert('Vault UI opens the client folder', str_contains($helpers, 'data-vault-folder') && str_contains($helpers, 'Client folders'));
lex_vcf_assert('Vault does not mention a pre-made booking folder', !str_contains($helpers, 'When a client books an appointment'));
lex_vcf_assert('Inner folders are created under the client folder', str_contains($helpers, 'lex_case_files_ensure_client_vault_tree'));
lex_vcf_assert('Custom folders are created inside the open folder', str_contains($actions, 'parent_folder_id') && str_contains($helpers, 'parent_folder_id'));
lex_vcf_assert('Folder navigation is in the case files script', str_contains($js, 'data-vault-folder') && str_contains($js, 'folder:'));
lex_vcf_assert('Auto-sort still targets Documents, Pictures, or Videos', str_contains($helpers, 'Auto (Documents, Pictures, or Videos)'));

try {
    $pdo = lex_pdo();
    lex_case_files_table_ensure();
    lex_case_file_vault_table_ensure();

    $userStmt = $pdo->query('SELECT id, role, full_name FROM users WHERE is_active = 1 ORDER BY id ASC');
    $users = $userStmt ? ($userStmt->fetchAll() ?: []) : [];
    $client = null;
    $lawyer = null;
    foreach ($users as $user) {
        if (!$client && ($user['role'] ?? '') === 'client') {
            $client = $user;
        }
        if (!$lawyer && ($user['role'] ?? '') === 'lawyer') {
            $lawyer = $user;
        }
    }

    if ($client && $lawyer) {
        $folderName = 'CF-VCF-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO case_files (full_name, case_file_title, description, client_user_id, assigned_lawyer_user_id, created_by_user_id, folder_name, status)
             VALUES (:full_name, :title, :description, :client_user_id, :lawyer_user_id, :created_by, :folder_name, "open")'
        )->execute([
            'full_name' => 'Client A',
            'title' => 'Nested vault example',
            'description' => 'Temporary vault folder test',
            'client_user_id' => (int) $client['id'],
            'lawyer_user_id' => (int) $lawyer['id'],
            'created_by' => (int) $lawyer['id'],
            'folder_name' => $folderName,
        ]);
        $caseFileId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO case_file_folders (case_file_id, parent_id, slug, name, created_by_user_id)
             VALUES (:case_file_id, 0, :slug, :name, :created_by)'
        )->execute([
            'case_file_id' => $caseFileId,
            'slug' => 'documents',
            'name' => 'Documents',
            'created_by' => (int) $lawyer['id'],
        ]);

        $record = ['id' => $caseFileId, 'full_name' => 'Client A', 'client_user_id' => (int) $client['id']];
        $clientFolder = lex_case_files_ensure_client_vault_tree($pdo, $record, (int) $lawyer['id']);
        $root = lex_case_files_list_vault_folders($pdo, $caseFileId, 0);
        $inner = lex_case_files_list_vault_folders($pdo, $caseFileId, (int) $clientFolder['id']);
        $innerNames = array_values(array_map(static fn (array $folder): string => (string) $folder['name'], $inner));

        lex_vcf_assert('Client folder is named after the client', (string) ($clientFolder['name'] ?? '') === 'Client A');
        lex_vcf_assert('Vault root shows the client folder', count($root) === 1 && (string) ($root[0]['name'] ?? '') === 'Client A');
        lex_vcf_assert('Client folder contains Documents', in_array('Documents', $innerNames, true));
        lex_vcf_assert('Client folder contains Pictures', in_array('Pictures', $innerNames, true));
        lex_vcf_assert('Client folder contains Videos', in_array('Videos', $innerNames, true));
        lex_vcf_assert('Existing Documents folder was moved inside the client folder', in_array('Documents', $innerNames, true) && count($root) === 1);

        $pdo->prepare('DELETE FROM case_files WHERE id = :id')->execute(['id' => $caseFileId]);
    } else {
        lex_vcf_assert('Database has a client and lawyer for the nested-folder example', false, 'Skipped live folder create');
    }
} catch (Throwable $e) {
    lex_vcf_assert('Live nested-folder example ran', false, $e->getMessage());
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
