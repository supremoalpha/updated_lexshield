<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();

$statusFilter = lex_sanitize_text($_GET['status'] ?? '');
$searchFilter = lex_sanitize_text($_GET['q'] ?? '');
$dateFilter = lex_sanitize_text($_GET['date'] ?? '');
$exportCsv = (string) ($_GET['export'] ?? '') === 'csv';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('admin/phishing_scans.php');
        lex_flash_set('error', 'Invalid CSRF token.');
    } elseif ((string) ($_POST['action'] ?? '') === 'clear_all') {
        $deleted = $pdo->exec('DELETE FROM phishing_scans');
        lex_audit('clear_phishing_scans', 'phishing_scans', (string) ((int) $deleted));
        lex_flash_set('success', 'Phishing scan records cleared.');
    }
    header('Location: ' . lex_app_url('admin/phishing_scans.php'));
    exit;
}

$buildScanQuery = static function (bool $withLimit) use ($statusFilter, $searchFilter, $dateFilter): array {
    $sql = 'SELECT ps.*, u.full_name
            FROM phishing_scans ps
            LEFT JOIN users u ON u.id = ps.user_id
            WHERE 1=1';
    $params = [];
    if (in_array($statusFilter, ['suspicious', 'phishing'], true)) {
        $sql .= ' AND ps.status = :status';
        $params['status'] = $statusFilter;
    }
    if ($searchFilter !== '') {
        $sql .= ' AND (ps.submitted_url LIKE :search OR ps.final_url LIKE :search OR ps.ip_address LIKE :search)';
        $params['search'] = '%' . $searchFilter . '%';
    }
    if ($dateFilter !== '') {
        $sql .= ' AND DATE(ps.created_at) = :date';
        $params['date'] = $dateFilter;
    }
    $sql .= ' ORDER BY ps.created_at DESC';
    if ($withLimit) {
        $sql .= ' LIMIT 250';
    }

    return [$sql, $params];
};

if ($exportCsv) {
    [$exportSql, $exportParams] = $buildScanQuery(false);
    $stmt = $pdo->prepare($exportSql);
    $stmt->execute($exportParams);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = 'phishing-scans-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'w');
    if ($output !== false) {
        fputcsv($output, ['When', 'Status', 'Submitted URL', 'Final URL', 'Score', 'Findings', 'Redirects', 'User', 'IP Address', 'User Agent']);
        while ($scan = $stmt->fetch()) {
            $findings = json_decode((string) ($scan['findings_json'] ?? '[]'), true);
            $findingsText = is_array($findings) ? implode(' | ', array_map('strval', $findings)) : '';
            fputcsv($output, [
                (string) $scan['created_at'],
                (string) $scan['status'],
                (string) $scan['submitted_url'],
                (string) $scan['final_url'],
                (int) $scan['score'],
                $findingsText,
                (int) $scan['redirect_count'],
                (string) ($scan['full_name'] ?? 'Guest'),
                (string) $scan['ip_address'],
                (string) $scan['user_agent'],
            ]);
        }
        fclose($output);
    }
    lex_audit('export_phishing_scans_csv', 'phishing_scans');
    exit;
}

[$sql, $params] = $buildScanQuery(true);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$scans = $stmt->fetchAll();
$exportQuery = array_filter([
    'status' => $statusFilter,
    'q' => $searchFilter,
    'date' => $dateFilter,
    'export' => 'csv',
], static fn ($value): bool => $value !== '');
$exportUrl = lex_app_url('admin/phishing_scans.php') . '?' . http_build_query($exportQuery);

lex_page_header('Phishing Scans', 'phishing');
?>
<section class="card admin-audit-card">
  <div class="card-head"><h2>Filter Scans</h2></div>
  <form method="get" class="form-grid admin-audit-form">
    <label>Status
      <select name="status">
        <option value="">All</option>
        <option value="suspicious"<?= $statusFilter === 'suspicious' ? ' selected' : '' ?>>Suspicious</option>
        <option value="phishing"<?= $statusFilter === 'phishing' ? ' selected' : '' ?>>Phishing</option>
      </select>
    </label>
    <label>URL or IP <input type="text" name="q" value="<?= lex_e($searchFilter) ?>"></label>
    <label>Date <input type="date" name="date" value="<?= lex_e($dateFilter) ?>"></label>
    <button class="button button-primary admin-audit-submit" type="submit">Apply Filters</button>
  </form>
</section>

<section class="card admin-audit-card">
  <div class="card-head">
    <h2>Flagged Scans</h2>
    <div class="admin-phishing-actions">
      <span class="muted"><?= count($scans) ?> result<?= count($scans) === 1 ? '' : 's' ?></span>
      <a class="button button-secondary" href="<?= lex_e($exportUrl) ?>">Export CSV</a>
      <form method="post" data-no-loading>
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="clear_all">
        <button class="button button-danger" type="submit" data-confirm="Clear all phishing scan records? This cannot be undone.">Clear All</button>
      </form>
    </div>
  </div>
  <div class="table-wrap admin-audit-table-wrap">
    <table class="data-table admin-audit-table admin-phishing-table">
      <thead>
        <tr>
          <th>When</th>
          <th>Status</th>
          <th>URL</th>
          <th>Score</th>
          <th>Findings</th>
          <th>User/IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($scans as $scan): ?>
        <?php
          $findings = json_decode((string) ($scan['findings_json'] ?? '[]'), true);
          $findings = is_array($findings) ? array_slice($findings, 0, 4) : [];
          $submittedUrl = (string) ($scan['submitted_url'] ?? '');
          $finalUrl = (string) ($scan['final_url'] ?? '');
        ?>
        <tr>
          <td data-label="When"><?= lex_e((string) $scan['created_at']) ?></td>
          <td data-label="Status"><span class="pill vault-status vault-status-<?= lex_e((string) $scan['status']) ?>"><?= lex_e(ucfirst((string) $scan['status'])) ?></span></td>
          <td data-label="URL">
            <strong><?= lex_e($submittedUrl) ?></strong>
            <?php if ($finalUrl !== '' && $finalUrl !== $submittedUrl): ?>
              <div class="muted">Final: <?= lex_e($finalUrl) ?></div>
            <?php endif; ?>
            <?php if ((int) ($scan['redirect_count'] ?? 0) > 0): ?>
              <div class="muted">Redirects: <?= (int) $scan['redirect_count'] ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Score"><?= (int) $scan['score'] ?></td>
          <td data-label="Findings">
            <?php if ($findings): ?>
              <ul class="admin-phishing-findings">
                <?php foreach ($findings as $finding): ?>
                  <li><?= lex_e((string) $finding) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <span class="muted">No details</span>
            <?php endif; ?>
          </td>
          <td data-label="User / IP">
            <?= lex_e((string) ($scan['full_name'] ?? 'Guest')) ?>
            <div class="muted"><?= lex_e((string) $scan['ip_address']) ?></div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$scans): ?>
        <tr><td colspan="6">No suspicious or phishing scans found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php lex_page_footer(); ?>
