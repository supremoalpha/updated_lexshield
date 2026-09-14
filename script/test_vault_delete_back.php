<?php

declare(strict_types=1);

/**
 * Vault folders have a Back button and files can be deleted.
 * php script/test_vault_delete_back.php
 */

$failed = 0;
$passed = 0;

function lex_vdb_assert(string $label, bool $ok, string $detail = ''): void
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
$ledger = (string) file_get_contents($root . '/config/blockchain/ledger.php');
$style = (string) file_get_contents($root . '/public/css/style.css');

lex_vdb_assert('Delete action is handled in core.php', str_contains($core, "\$action === 'vault_delete'"));
lex_vdb_assert('Delete removes the encrypted file', str_contains($core, '@unlink($path)'));
lex_vdb_assert('Delete removes the document row', str_contains($core, 'DELETE FROM case_file_documents'));
lex_vdb_assert('Clients can only delete their own uploads', str_contains($core, "\$access === 'client' && \$ownsUpload"));
lex_vdb_assert('View-only sharing cannot delete', str_contains($core, 'lex_case_file_is_view_only') && str_contains($core, 'Unable to delete that file.'));
lex_vdb_assert('Deletes are sealed on the ledger', str_contains($core, 'vault_file_deleted') && str_contains($ledger, "'vault_file_deleted'"));
lex_vdb_assert('Vault shows a Back button', str_contains($helpers, 'case-vault-back') && str_contains($helpers, '>Back</a>'));
lex_vdb_assert('Back uses folder navigation', str_contains($helpers, 'data-vault-folder="<?= $backFolderId ?>"'));
lex_vdb_assert('Back is hidden at the vault root', str_contains($helpers, 'if ($currentFolder):') && str_contains($helpers, 'case-vault-back'));
lex_vdb_assert('Each file has a Delete button', str_contains($helpers, 'name="action" value="vault_delete"') && str_contains($helpers, 'case-vault-delete'));
lex_vdb_assert('Delete asks for confirmation', str_contains($helpers, 'Delete this file from the vault?'));
lex_vdb_assert('CSS styles the Back and Delete controls', str_contains($style, 'Vault back button and file delete') && str_contains($style, '.case-vault-back'));

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'vault-delete-test-token';
$_POST = [
    'csrf_token' => 'vault-delete-test-token',
    'action' => 'vault_delete',
    'document_id' => '0',
    'folder' => '0',
];
$denied = lex_case_files_handle_post(
    lex_pdo(),
    ['id' => 24, 'role' => 'lawyer'],
    ['q' => '', 'status' => 'all', 'sort' => 'updated_at', 'dir' => 'DESC', 'page' => 1, 'folder' => 0, 'role' => 'lawyer'],
    [],
    []
);
lex_vdb_assert(
    'Missing documents are rejected without redirect',
    ($denied['failed_action'] ?? '') === 'vault_delete' && str_contains((string) ($denied['error'] ?? ''), 'Unable to delete')
);

try {
    $pdo = lex_pdo();
    lex_case_files_table_ensure();
    lex_case_file_vault_table_ensure();

    $client = $pdo->query("SELECT id, role, full_name FROM users WHERE role = 'client' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch() ?: null;
    $lawyer = $pdo->query("SELECT id, role, full_name FROM users WHERE role = 'lawyer' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch() ?: null;
    $otherLawyer = $pdo->query("SELECT id, role FROM users WHERE role = 'lawyer' AND is_active = 1 AND id <> " . (int) ($lawyer['id'] ?? 0) . ' ORDER BY id ASC LIMIT 1')->fetch() ?: null;

    if ($client && $lawyer) {
        $folderName = 'CF-DEL-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO case_files (full_name, case_file_title, description, client_user_id, assigned_lawyer_user_id, created_by_user_id, folder_name, status)
             VALUES (:full_name, :title, :description, :client_user_id, :lawyer_user_id, :created_by, :folder_name, "open")'
        )->execute([
            'full_name' => (string) $client['full_name'],
            'title' => 'Vault delete example',
            'description' => 'Temporary vault delete test',
            'client_user_id' => (int) $client['id'],
            'lawyer_user_id' => (int) $lawyer['id'],
            'created_by' => (int) $lawyer['id'],
            'folder_name' => $folderName,
        ]);
        $caseFileId = (int) $pdo->lastInsertId();
        $record = [
            'id' => $caseFileId,
            'full_name' => (string) $client['full_name'],
            'client_user_id' => (int) $client['id'],
        ];
        $clientFolder = lex_case_files_ensure_client_vault_tree($pdo, $record, (int) $lawyer['id']);
        $docsFolder = lex_case_files_ensure_vault_folder($pdo, $caseFileId, 'Documents', (int) $lawyer['id'], (int) $clientFolder['id']);

        $storedName = bin2hex(random_bytes(8)) . '.enc';
        $absDir = lex_case_files_folder_path($folderName) . DIRECTORY_SEPARATOR . lex_case_file_vault_relative_dir($docsFolder);
        lex_storage_ensure_dir($absDir);
        $absPath = $absDir . DIRECTORY_SEPARATOR . $storedName;
        file_put_contents($absPath, 'encrypted-placeholder', LOCK_EX);

        $pdo->prepare(
            'INSERT INTO case_file_documents (case_file_id, folder_id, original_name, stored_name, mime_type, file_size, encryption_algorithm, encryption_iv, encryption_tag, upload_status, uploaded_by_user_id)
             VALUES (:case_file_id, :folder_id, :original_name, :stored_name, :mime_type, :file_size, :algorithm, :iv, :tag, :status, :uploaded_by)'
        )->execute([
            'case_file_id' => $caseFileId,
            'folder_id' => (int) $docsFolder['id'],
            'original_name' => 'delete-me.txt',
            'stored_name' => $storedName,
            'mime_type' => 'text/plain',
            'file_size' => 21,
            'algorithm' => 'aes-256-gcm',
            'iv' => bin2hex(random_bytes(6)),
            'tag' => bin2hex(random_bytes(8)),
            'status' => 'approved',
            'uploaded_by' => (int) $lawyer['id'],
        ]);
        $documentId = (int) $pdo->lastInsertId();

        $panelState = [
            'selected' => [
                'id' => $caseFileId,
                'case_file_title' => 'Vault delete example',
                'full_name' => (string) $client['full_name'],
                'client_user_id' => (int) $client['id'],
                'assigned_lawyer_user_id' => (int) $lawyer['id'],
                'created_by_user_id' => (int) $lawyer['id'],
                'folder_name' => $folderName,
            ],
            'folder' => (int) $docsFolder['id'],
            'search' => '',
            'status' => 'all',
            'sort' => 'updated_at',
            'dir' => 'desc',
            'page' => 1,
        ];
        $panel = lex_case_files_render_vault_panel(
            $panelState,
            ['folder' => (int) $docsFolder['id']],
            ['id' => (int) $lawyer['id'], 'role' => 'lawyer']
        );
        lex_vdb_assert('Rendered vault includes Back', str_contains($panel, 'case-vault-back') && str_contains($panel, '>Back</a>'));
        lex_vdb_assert('Rendered vault includes Delete', str_contains($panel, 'vault_delete') && str_contains($panel, '>Delete</button>'));
        lex_vdb_assert(
            'Back points at the parent folder',
            preg_match('/case-vault-back"[^>]*data-vault-folder="' . (int) $clientFolder['id'] . '"/', $panel) === 1
        );

        $rootPanel = lex_case_files_render_vault_panel(
            array_merge($panelState, ['folder' => 0]),
            ['folder' => 0],
            ['id' => (int) $lawyer['id'], 'role' => 'lawyer']
        );
        lex_vdb_assert('Vault root has no Back button', !str_contains($rootPanel, 'case-vault-back'));

        if ($otherLawyer) {
            $_POST = [
                'csrf_token' => 'vault-delete-test-token',
                'action' => 'vault_delete',
                'document_id' => (string) $documentId,
                'folder' => (string) (int) $docsFolder['id'],
            ];
            $sharedDenied = lex_case_files_handle_post(
                $pdo,
                ['id' => (int) $otherLawyer['id'], 'role' => 'lawyer'],
                ['q' => '', 'status' => 'all', 'sort' => 'updated_at', 'dir' => 'DESC', 'page' => 1, 'folder' => (int) $docsFolder['id'], 'role' => 'lawyer'],
                [],
                []
            );
            lex_vdb_assert(
                'A lawyer without vault access cannot delete',
                ($sharedDenied['failed_action'] ?? '') === 'vault_delete'
            );
        } else {
            lex_vdb_assert('A lawyer without vault access cannot delete', false, 'No second lawyer in the database');
        }

        $_POST = [
            'csrf_token' => 'vault-delete-test-token',
            'action' => 'vault_delete',
            'document_id' => (string) $documentId,
            'folder' => (string) (int) $docsFolder['id'],
        ];
        $clientDenied = lex_case_files_handle_post(
            $pdo,
            ['id' => (int) $client['id'], 'role' => 'client'],
            ['q' => '', 'status' => 'all', 'sort' => 'updated_at', 'dir' => 'DESC', 'page' => 1, 'folder' => (int) $docsFolder['id'], 'role' => 'client'],
            [],
            []
        );
        lex_vdb_assert(
            'A client cannot delete a lawyer-uploaded file',
            ($clientDenied['failed_action'] ?? '') === 'vault_delete'
        );

        $runner = sys_get_temp_dir() . '/lex_vault_delete_run.php';
        $runnerPhp = '<?php
declare(strict_types=1);
$_SERVER["REQUEST_METHOD"] = "POST";
require_once ' . var_export($root . '/config/bootstrap.php', true) . ';
require_once ' . var_export($root . '/config/case_files/helpers.php', true) . ';
$_SESSION["csrf_token"] = "vault-delete-test-token";
$_POST = [
    "csrf_token" => "vault-delete-test-token",
    "action" => "vault_delete",
    "document_id" => ' . var_export((string) $documentId, true) . ',
    "folder" => ' . var_export((string) (int) $docsFolder['id'], true) . ',
];
lex_case_files_handle_post(
    lex_pdo(),
    ["id" => ' . (int) $lawyer['id'] . ', "role" => "lawyer"],
    ["q" => "", "status" => "all", "sort" => "updated_at", "dir" => "DESC", "page" => 1, "folder" => ' . (int) $docsFolder['id'] . ', "role" => "lawyer"],
    [],
    []
);
';
        file_put_contents($runner, $runnerPhp);
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>/dev/null', $output, $exitCode);
        @unlink($runner);

        $stillThere = $pdo->prepare('SELECT id FROM case_file_documents WHERE id = :id LIMIT 1');
        $stillThere->execute(['id' => $documentId]);
        lex_vdb_assert('Lawyer delete removes the document row', $stillThere->fetch() === false);
        lex_vdb_assert('Lawyer delete removes the encrypted file', !is_file($absPath));

        $pdo->prepare('DELETE FROM case_file_documents WHERE case_file_id = :id')->execute(['id' => $caseFileId]);
        $pdo->prepare('DELETE FROM case_file_folders WHERE case_file_id = :id')->execute(['id' => $caseFileId]);
        $pdo->prepare('DELETE FROM case_files WHERE id = :id')->execute(['id' => $caseFileId]);
    } else {
        lex_vdb_assert('Database has a client and lawyer for the delete example', false, 'Skipped live delete');
    }
} catch (Throwable $e) {
    lex_vdb_assert('Live vault delete example ran', false, $e->getMessage());
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
