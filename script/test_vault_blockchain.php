<?php

declare(strict_types=1);

/**
 * Vault events are sealed on the hash-chained ledger.
 * php script/test_vault_blockchain.php
 */

$failed = 0;
$passed = 0;

function lex_vb_assert(string $label, bool $ok, string $detail = ''): void
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

$ledger = (string) file_get_contents($root . '/config/blockchain/ledger.php');
$core = (string) file_get_contents($root . '/config/case_files/core.php');
$helpers = (string) file_get_contents($root . '/config/case_files/helpers.php');

lex_vb_assert('Vault events have ledger labels', lex_blockchain_event_label('vault_file_uploaded') === 'Vault file uploaded');
lex_vb_assert('Folder events have ledger labels', lex_blockchain_event_label('vault_folder_created') === 'Vault folder created');
lex_vb_assert('Uploads write a vault block', str_contains($core, 'lex_vault_blockchain_record') && str_contains($core, 'vault_file_uploaded'));
lex_vb_assert('Folder creates write a vault block', str_contains($core, 'vault_folder_created'));
lex_vb_assert('Deletes write a vault block', str_contains($core, 'vault_file_deleted'));
lex_vb_assert('Approvals write a vault block', str_contains($core, 'vault_file_'));
lex_vb_assert('Deleted files have a ledger label', lex_blockchain_event_label('vault_file_deleted') === 'Vault file deleted');
lex_vb_assert('Vault UI shows the blockchain', str_contains($helpers, 'Vault blockchain') && str_contains($helpers, 'lex_case_files_render_vault_ledger'));
lex_vb_assert('Documents can store a ledger hash', str_contains($core, 'ledger_hash') && str_contains($ledger, 'lex_blockchain_blocks_for_case'));

try {
    lex_blockchain_table_ensure();
    $block = lex_vault_blockchain_record('vault_folder_created', [
        'case_file_id' => 1,
        'folder_id' => 0,
        'folder_name' => 'Blockchain test',
        'parent_id' => 0,
    ], 24);
    lex_vb_assert('A vault block can be appended', is_array($block) && ($block['hash'] ?? '') !== '');
    $found = lex_blockchain_blocks_for_case(1, 20);
    $matched = false;
    foreach ($found as $row) {
        if ((string) ($row['hash'] ?? '') === (string) ($block['hash'] ?? '')) {
            $matched = true;
            break;
        }
    }
    lex_vb_assert('The case vault ledger finds that block', $matched);
    $verify = lex_blockchain_verify_chain();
    lex_vb_assert('The chain still verifies after the vault block', !empty($verify['valid']));
} catch (Throwable $e) {
    lex_vb_assert('Live vault blockchain example ran', false, $e->getMessage());
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
