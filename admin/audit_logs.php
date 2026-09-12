<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'clear_all') {
        try {
            $deleted = (int) $pdo->exec('DELETE FROM audit_logs');
            lex_flash_set('success', $deleted > 0
                ? $deleted . ' audit log entries were deleted.'
                : 'No audit log entries were found to delete.');
        } catch (Throwable $e) {
            lex_flash_set('error', 'Could not clear audit logs.');
        }
        header('Location: ' . lex_app_url('admin/audit_logs.php'));
        exit;
    }
}

$userFilter = lex_sanitize_text($_GET['user'] ?? '');
$actionFilter = lex_sanitize_text($_GET['action'] ?? '');
$dateFilter = lex_sanitize_text($_GET['date'] ?? '');

$sql = 'SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE 1=1';
$params = [];
if ($userFilter !== '') {
    $sql .= ' AND u.full_name LIKE :user';
    $params['user'] = '%' . $userFilter . '%';
}
if ($actionFilter !== '') {
    $sql .= ' AND a.action LIKE :action';
    $params['action'] = '%' . $actionFilter . '%';
}
if ($dateFilter !== '') {
    $sql .= ' AND DATE(a.performed_at) = :date';
    $params['date'] = $dateFilter;
}
$sql .= ' ORDER BY a.performed_at DESC LIMIT 250';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

lex_page_header('Audit Logs', 'audit');
?>
<section class="card admin-audit-card">
  <div class="card-head"><h2>Filter Logs</h2></div>
  <form method="get" class="form-grid admin-audit-form">
    <label>User <input type="text" name="user" value="<?= lex_e($userFilter) ?>"></label>
    <label>Action <input type="text" name="action" value="<?= lex_e($actionFilter) ?>"></label>
    <label>Date <input type="date" name="date" value="<?= lex_e($dateFilter) ?>"></label>
    <button class="button button-primary admin-audit-submit" type="submit">Apply Filters</button>
  </form>
</section>

<section class="card admin-audit-card">
  <div class="card-head">
    <h2>Entries</h2>
    <div class="inline-actions">
      <a class="button button-secondary" href="export_audit.php">Export CSV</a>
      <form method="post" class="inline-form" style="display:inline-flex;gap:0.5rem;align-items:center;">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="clear_all">
        <button class="button button-danger" type="submit">Clear All</button>
      </form>
    </div>
  </div>
  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
  <div class="table-wrap admin-audit-table-wrap">
    <table class="data-table admin-audit-table">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>UA</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td data-label="When"><?= lex_e($log['performed_at']) ?></td>
          <td data-label="User"><?= lex_e($log['full_name'] ?? 'System') ?></td>
          <td data-label="Action"><?= lex_e($log['action']) ?></td>
          <td data-label="Target"><?= lex_e($log['target_table'] . ':' . $log['target_id']) ?></td>
          <td data-label="IP"><?= lex_e($log['ip_address']) ?></td>
          <td data-label="Details"><?= lex_e($log['user_agent']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php lex_page_footer(); ?>
