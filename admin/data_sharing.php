<?php
require_once __DIR__ . '/../config/bootstrap.php';

/**
 * Admin approval queue for lawyer-to-lawyer case file sharing requests.
 * Approving or rejecting a request appends a block to the blockchain
 * ledger (config/blockchain/ledger.php) and, on approval, grants the
 * receiving lawyer read access to that case file's secure vault.
 */

$user = lex_require_role('admin');
$pdo = lex_pdo();

lex_case_files_table_ensure();
lex_data_sharing_table_ensure();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('admin/data_sharing.php');
        $error = 'Invalid CSRF token. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $requestId = lex_sanitize_int($_POST['request_id'] ?? 0);
        $decisionNote = lex_sanitize_multiline_text($_POST['decision_note'] ?? '');

        if (in_array($action, ['approve', 'reject'], true) && $requestId > 0) {
            $decision = $action === 'approve' ? 'approved' : 'rejected';

            $stmt = $pdo->prepare('SELECT * FROM data_sharing_requests WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $requestId]);
            $request = $stmt->fetch();

            if (!$request) {
                $error = 'Request not found.';
            } elseif (lex_data_sharing_decide($requestId, $decision, (int) $user['id'], $decisionNote)) {
                lex_notify((int) $request['from_lawyer_user_id'], 'data_sharing', 'Your data sharing request was ' . $decision . ' by an administrator.');
                lex_notify((int) $request['to_lawyer_user_id'], 'data_sharing', 'A data sharing request involving you was ' . $decision . ' by an administrator.');
                lex_audit($decision === 'approved' ? 'share_approved' : 'share_rejected', 'data_sharing_requests', (string) $requestId);
                lex_flash_set('success', 'Request ' . $decision . ' and recorded on the blockchain ledger.');
                header('Location: ' . lex_app_url('admin/data_sharing.php'));
                exit;
            } else {
                $error = 'That request is no longer pending.';
            }
        }
    }
}

$pending = lex_recent(
    'SELECT r.*, cf.case_file_title, cf.full_name AS client_full_name,
            fu.full_name AS from_lawyer_name, tu.full_name AS to_lawyer_name
     FROM data_sharing_requests r
     JOIN case_files cf ON cf.id = r.case_file_id
     JOIN users fu ON fu.id = r.from_lawyer_user_id
     JOIN users tu ON tu.id = r.to_lawyer_user_id
     WHERE r.status = "pending"
     ORDER BY r.created_at ASC'
);

$decided = lex_recent(
    'SELECT r.*, cf.case_file_title, cf.full_name AS client_full_name,
            fu.full_name AS from_lawyer_name, tu.full_name AS to_lawyer_name, du.full_name AS decided_by_name
     FROM data_sharing_requests r
     JOIN case_files cf ON cf.id = r.case_file_id
     JOIN users fu ON fu.id = r.from_lawyer_user_id
     JOIN users tu ON tu.id = r.to_lawyer_user_id
     LEFT JOIN users du ON du.id = r.decided_by_user_id
     WHERE r.status <> "pending"
     ORDER BY r.decided_at DESC
     LIMIT 25'
);

$statusClass = static fn (string $status): string => match ($status) {
    'approved' => 'is-open',
    'rejected' => 'is-closed',
    'revoked' => 'is-closed',
    default => 'is-ongoing',
};

lex_page_header('Data Sharing Approvals', 'data-sharing', $user);
?>
<div data-admin-sharing-page class="admin-sharing-page">
<?php if ($error !== ''): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>

<section class="admin-dashboard-stats" aria-label="Data sharing summary">
  <article class="admin-dashboard-stat-card"><div class="admin-dashboard-stat-copy"><span>Pending approvals</span><strong><?= number_format(count($pending)) ?></strong></div></article>
  <article class="admin-dashboard-stat-card"><div class="admin-dashboard-stat-copy"><span>Decided (recent)</span><strong><?= number_format(count($decided)) ?></strong></div></article>
  <article class="admin-dashboard-stat-card">
    <div class="admin-dashboard-stat-copy">
      <span>Blockchain ledger</span>
      <a class="admin-sharing-ledger-link" href="<?= lex_e(lex_app_url('admin/blockchain_ledger.php')) ?>">View ledger</a>
    </div>
  </article>
</section>

<section class="card">
  <div class="card-head"><h2>Pending requests</h2></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Case file</th><th>From</th><th>To</th><th>Note</th><th>Requested</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($pending as $request): ?>
          <tr>
            <td data-label="Case file"><strong><?= lex_e((string) $request['case_file_title']) ?></strong><br><span class="muted"><?= lex_e((string) $request['client_full_name']) ?></span></td>
            <td data-label="From"><?= lex_e((string) $request['from_lawyer_name']) ?></td>
            <td data-label="To"><?= lex_e((string) $request['to_lawyer_name']) ?></td>
            <td data-label="Note"><?= lex_e((string) ($request['note'] ?: '—')) ?></td>
            <td data-label="Requested"><?= lex_e(lex_message_timestamp((string) $request['created_at'])) ?></td>
            <td data-label="Actions">
              <div class="inline-actions">
                <form method="post" style="display:inline;">
                  <?= lex_csrf_field() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                  <button class="button button-primary" type="submit">Approve</button>
                </form>
                <form method="post" style="display:inline;">
                  <?= lex_csrf_field() ?>
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                  <button class="button button-secondary" type="submit">Reject</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pending): ?><tr><td colspan="6" class="admin-empty-line">No pending requests.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>Recent decisions</h2></div>
  <div class="admin-audit-list">
    <?php foreach ($decided as $request): ?>
      <div class="admin-audit-row admin-sharing-decision">
        <div>
          <strong><?= lex_e((string) $request['case_file_title']) ?></strong>
          <span class="admin-sharing-meta">From <?= lex_e((string) $request['from_lawyer_name']) ?> to <?= lex_e((string) $request['to_lawyer_name']) ?> · Decided by <?= lex_e((string) ($request['decided_by_name'] ?? 'System')) ?></span>
        </div>
        <span class="status-pill <?= lex_e($statusClass((string) $request['status'])) ?>"><?= lex_e(ucfirst((string) $request['status'])) ?></span>
        <time><?= lex_e(lex_message_timestamp((string) $request['decided_at'])) ?></time>
      </div>
    <?php endforeach; ?>
    <?php if (!$decided): ?><div class="admin-empty-line">No decisions recorded yet.</div><?php endif; ?>
  </div>
</section>
</div>
<?php lex_page_footer(); ?>
