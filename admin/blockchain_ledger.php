<?php
require_once __DIR__ . '/../config/bootstrap.php';

/**
 * Blockchain ledger explorer: shows every block recorded by the data
 * sharing workflow (and any future high-value event) and re-verifies the
 * whole hash chain on every page load so tampering is always visible.
 */

$user = lex_require_role('admin');

lex_blockchain_table_ensure();

$verification = lex_blockchain_verify_chain();

$perPage = 20;
$page = max(1, lex_sanitize_int($_GET['page'] ?? 1));
$total = $verification['blocks_checked'];
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$blocks = lex_blockchain_recent_blocks($perPage, $offset);

lex_page_header('Blockchain Ledger', 'blockchain', $user);
?>
<section class="card">
  <div class="card-head">
    <div>
      <h2>Chain integrity</h2>
      <p class="muted">Every block's hash is derived from its own data plus the previous block's hash, so editing or deleting any historical record breaks every hash after it.</p>
    </div>
  </div>
  <?php if ($verification['valid']): ?>
    <div class="alert alert-success">✅ Chain intact - <?= number_format((int) $verification['blocks_checked']) ?> block(s) verified, no tampering detected.</div>
  <?php else: ?>
    <div class="alert alert-error">
      🚨 Chain integrity check FAILED - <?= count($verification['broken_at']) ?> issue(s) found:
      <ul>
        <?php foreach ($verification['broken_at'] as $issue): ?>
          <li>Block #<?= (int) $issue['index'] ?>: <?= lex_e($issue['reason']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h2>Ledger blocks</h2><span class="pill"><?= number_format($total) ?> total</span></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>#</th><th>Event</th><th>Actor</th><th>Recorded</th><th>Hash</th><th>Previous hash</th></tr></thead>
      <tbody>
        <?php foreach ($blocks as $block): ?>
          <tr>
            <td data-label="#"><?= (int) $block['block_index'] ?></td>
            <td data-label="Event"><span class="pill"><?= lex_e(lex_blockchain_event_label((string) $block['event_type'])) ?></span></td>
            <td data-label="Actor"><?= lex_e((string) ($block['actor_name'] ?? 'System')) ?></td>
            <td data-label="Recorded"><?= lex_e(lex_message_timestamp((string) $block['created_at'])) ?></td>
            <td data-label="Hash"><code title="<?= lex_e((string) $block['hash']) ?>"><?= lex_e(substr((string) $block['hash'], 0, 12)) ?>&hellip;</code></td>
            <td data-label="Previous hash"><code title="<?= lex_e((string) $block['previous_hash']) ?>"><?= lex_e(substr((string) $block['previous_hash'], 0, 12)) ?>&hellip;</code></td>
          </tr>
          <tr>
            <td colspan="6"><details><summary class="muted">Payload</summary><pre style="white-space:pre-wrap;word-break:break-word;"><?= lex_e((string) $block['data_json']) ?></pre></details></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$blocks): ?><tr><td colspan="6" class="admin-empty-line">No blocks recorded yet. Requesting a data share will create the first one.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= lex_admin_pagination('admin/blockchain_ledger.php', [], $total, $page, $perPage) ?>
</section>
<?php lex_page_footer(); ?>
