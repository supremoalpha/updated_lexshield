<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');

$statusFilter = trim(lex_sanitize_text($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'pending', 'verified', 'rejected'], true)) {
    $statusFilter = 'all';
}
$search = trim(lex_sanitize_text($_GET['q'] ?? ''));

function lex_admin_payment_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'CL';
}

$summary = lex_recent(
    'SELECT
        COUNT(*) AS total_payments,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_payments,
        SUM(CASE WHEN status = "verified" THEN 1 ELSE 0 END) AS verified_payments,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_payments,
        SUM(CASE WHEN status = "verified" THEN amount ELSE 0 END) AS verified_total
     FROM manual_payments'
);
$totals = $summary[0] ?? [
    'total_payments' => 0,
    'pending_payments' => 0,
    'verified_payments' => 0,
    'rejected_payments' => 0,
    'verified_total' => 0,
];

$query = 'SELECT mp.id, mp.payment_for, mp.amount, mp.reference_number, mp.status, mp.created_at, mp.reviewed_at,
                 cu.full_name AS client_name, cu.email AS client_email, cu.avatar_stored_name AS client_avatar_stored_name, ru.full_name AS reviewed_by_name
          FROM manual_payments mp
          JOIN clients c ON c.id = mp.client_id
          JOIN users cu ON cu.id = c.user_id
          LEFT JOIN users ru ON ru.id = mp.reviewed_by_user_id';
$countQuery = 'SELECT COUNT(*)
          FROM manual_payments mp
          JOIN clients c ON c.id = mp.client_id
          JOIN users cu ON cu.id = c.user_id
          LEFT JOIN users ru ON ru.id = mp.reviewed_by_user_id';
$params = [];
$where = [];
if ($statusFilter !== 'all') {
    $where[] = 'mp.status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(cu.full_name LIKE :search_name OR cu.email LIKE :search_email OR mp.payment_for LIKE :search_payment_for OR mp.reference_number LIKE :search_reference)';
    $searchValue = '%' . $search . '%';
    $params['search_name'] = $searchValue;
    $params['search_email'] = $searchValue;
    $params['search_payment_for'] = $searchValue;
    $params['search_reference'] = $searchValue;
}
if ($where) {
    $filterSql = ' WHERE ' . implode(' AND ', $where);
    $query .= $filterSql;
    $countQuery .= $filterSql;
}
$perPage = 10;
$totalPayments = lex_stats($countQuery, $params);
$totalPaymentPages = max(1, (int) ceil($totalPayments / $perPage));
$currentPage = min(max(1, lex_sanitize_int($_GET['page'] ?? 1)), $totalPaymentPages);
$offset = ($currentPage - 1) * $perPage;

$query .= ' ORDER BY mp.status = "pending" DESC, mp.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$payments = lex_recent($query, $params);

lex_page_header('Payments', 'payments');
?>
<section class="admin-payments-stats" aria-label="Payment summary">
  <article class="admin-payments-stat-card">
    <div class="admin-payments-stat-icon tone-blue" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M4.75 6h14.5A1.75 1.75 0 0 1 21 7.75v8.5A1.75 1.75 0 0 1 19.25 18H4.75A1.75 1.75 0 0 1 3 16.25v-8.5A1.75 1.75 0 0 1 4.75 6Zm0 2.5v1h14.5v-1Zm9.75 5.25a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-payments-stat-copy"><span>Total</span><strong><?= (int) ($totals['total_payments'] ?? 0) ?></strong></div>
  </article>
  <article class="admin-payments-stat-card">
    <div class="admin-payments-stat-icon tone-green" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm.75 4.25h-1.5v5.25l4.1 2.46.78-1.29-3.38-2.02V7.5Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-payments-stat-copy"><span>Pending</span><strong><?= (int) ($totals['pending_payments'] ?? 0) ?></strong></div>
  </article>
  <article class="admin-payments-stat-card">
    <div class="admin-payments-stat-icon tone-purple" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.5 19 6v5.25c0 4.45-2.96 7.55-7 9.25-4.04-1.7-7-4.8-7-9.25V6l7-2.5Zm3.45 6.4-4.25 4.25-1.65-1.65-1.06 1.06 2.71 2.71 5.31-5.31-1.06-1.06Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-payments-stat-copy"><span>Verified</span><strong><?= (int) ($totals['verified_payments'] ?? 0) ?></strong></div>
  </article>
  <article class="admin-payments-stat-card">
    <div class="admin-payments-stat-icon tone-gold" aria-hidden="true">PHP</div>
    <div class="admin-payments-stat-copy"><span>Verified amount</span><strong>PHP <?= lex_e(number_format((float) ($totals['verified_total'] ?? 0), 2)) ?></strong></div>
  </article>
</section>

<section class="card payment-page-card admin-payments-review-card">
  <div class="card-head admin-payments-review-head">
    <div>
      <h2>Review Payments</h2>
      <p class="muted payment-section-copy">Pending items stay at the top so admins can work the queue quickly.</p>
    </div>
  </div>

  <form method="get" class="payment-filter-bar">
    <label class="admin-payments-search">
      <span class="sr-only">Search payments</span>
      <span class="admin-payments-field-icon" aria-hidden="true"></span>
      <input type="search" name="q" value="<?= lex_e($search) ?>" placeholder="Search payments...">
    </label>
    <label class="admin-payments-status-filter">
      <span class="sr-only">Payment status</span>
      <select name="status">
        <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All Status</option>
        <option value="pending"<?= $statusFilter === 'pending' ? ' selected' : '' ?>>Pending</option>
        <option value="verified"<?= $statusFilter === 'verified' ? ' selected' : '' ?>>Verified</option>
        <option value="rejected"<?= $statusFilter === 'rejected' ? ' selected' : '' ?>>Rejected</option>
      </select>
    </label>
    <button class="button button-secondary" type="submit">Apply Filter</button>
  </form>

  <div class="table-wrap">
    <table class="data-table payment-history-table">
      <thead>
        <tr>
          <th>Submitted</th>
          <th>Client</th>
          <th>Payment for</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Reference</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($payments): ?>
          <?php foreach ($payments as $payment): ?>
            <?php
              $clientName = (string) $payment['client_name'];
              $clientAvatarUrl = lex_profile_avatar_url((string) ($payment['client_avatar_stored_name'] ?? ''));
            ?>
            <tr>
              <td data-label="Submitted">
                <strong class="admin-payments-date"><?= lex_e(date('M j, Y', strtotime((string) $payment['created_at']))) ?></strong>
                <span class="admin-payments-time"><?= lex_e(date('g:i A', strtotime((string) $payment['created_at']))) ?></span>
              </td>
              <td data-label="Client">
                <div class="admin-payments-client-cell">
                  <?php if ($clientAvatarUrl !== ''): ?>
                    <img class="admin-payments-client-avatar" src="<?= lex_e($clientAvatarUrl) ?>" alt="Avatar for <?= lex_e($clientName) ?>">
                  <?php else: ?>
                    <span class="admin-payments-client-avatar" aria-hidden="true"><?= lex_e(lex_admin_payment_initials($clientName)) ?></span>
                  <?php endif; ?>
                  <div>
                    <strong><?= lex_e($clientName) ?></strong>
                    <span><?= lex_e((string) $payment['client_email']) ?></span>
                  </div>
                </div>
              </td>
              <td data-label="Payment for"><?= lex_e((string) $payment['payment_for']) ?></td>
              <td data-label="Amount">PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></td>
              <td data-label="Status"><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></td>
              <td data-label="Reference">
                <?php if ((string) ($payment['reference_number'] ?? '') !== ''): ?>
                  <?= lex_e((string) $payment['reference_number']) ?>
                <?php else: ?>
                  <span class="muted">None</span>
                <?php endif; ?>
              </td>
              <td data-label="Action"><a class="button button-secondary admin-payments-review-button" href="<?= lex_e(lex_app_url('admin/payment_view.php?id=' . (int) $payment['id'])) ?>">Review</a></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="7" class="muted">No payments matched this filter.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= lex_admin_pagination('admin/payments.php', ['q' => $search, 'status' => $statusFilter], $totalPayments, $currentPage, $perPage) ?>
</section>
<?php lex_page_footer(); ?>
