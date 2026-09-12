<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$clientId = lex_user_client_id((int) $user['id']);

$summary = lex_recent(
    'SELECT
        COUNT(*) AS total_payments,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_payments,
        SUM(CASE WHEN status = "verified" THEN 1 ELSE 0 END) AS verified_payments,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_payments,
        SUM(CASE WHEN status = "verified" THEN amount ELSE 0 END) AS verified_total
     FROM manual_payments
     WHERE client_id = :client_id',
    ['client_id' => $clientId]
);
$totals = $summary[0] ?? [
    'total_payments' => 0,
    'pending_payments' => 0,
    'verified_payments' => 0,
    'rejected_payments' => 0,
    'verified_total' => 0,
];

$recentPayments = lex_recent(
    'SELECT payment_for, amount, reference_number, status, created_at, reviewed_at
     FROM manual_payments
     WHERE client_id = :client_id
     ORDER BY created_at DESC
     LIMIT 8',
    ['client_id' => $clientId]
);

function lex_billing_summary_icon(string $type): string
{
    return match ($type) {
        'pending' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm.75 4.25h-1.5v5.25l4.1 2.46.78-1.29-3.38-2.02V7.5Z" fill="currentColor"/></svg>',
        'verified' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.5 19 6v5.25c0 4.45-2.96 7.55-7 9.25-4.04-1.7-7-4.8-7-9.25V6l7-2.5Zm3.45 6.4-4.25 4.25-1.65-1.65-1.06 1.06 2.71 2.71 5.31-5.31-1.06-1.06Z" fill="currentColor"/></svg>',
        'amount' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M4.75 6h14.5A1.75 1.75 0 0 1 21 7.75v8.5A1.75 1.75 0 0 1 19.25 18H4.75A1.75 1.75 0 0 1 3 16.25v-8.5A1.75 1.75 0 0 1 4.75 6Zm0 2.5v1h14.5v-1Zm9.75 5.25a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5Z" fill="currentColor"/></svg>',
        default => '<svg viewBox="0 0 24 24" focusable="false"><path d="M5.75 4h12.5A1.75 1.75 0 0 1 20 5.75v12.5A1.75 1.75 0 0 1 18.25 20H5.75A1.75 1.75 0 0 1 4 18.25V5.75A1.75 1.75 0 0 1 5.75 4Zm1.5 3v2.75h9.5V7h-9.5Zm0 4.25v5.5h3.5v-5.5h-3.5Zm5 0v5.5h4.5v-5.5h-4.5Z" fill="currentColor"/></svg>',
    };
}

lex_page_header('Billing', 'billing', $user);
?>
<section class="billing-page-stack billing-case-page" data-client-billing-page>
  <section class="payment-billing-summary-card">
    <div class="payment-summary-grid billing-case-stats" aria-label="Billing summary">
      <article class="kpi-card billing-case-stat-card is-total">
        <span class="billing-case-stat-label"><i class="billing-case-stat-icon" aria-hidden="true"><?= lex_billing_summary_icon('total') ?></i><em>Total submissions</em></span>
        <strong><?= (int) ($totals['total_payments'] ?? 0) ?></strong>
      </article>
      <article class="kpi-card billing-case-stat-card is-pending">
        <span class="billing-case-stat-label"><i class="billing-case-stat-icon" aria-hidden="true"><?= lex_billing_summary_icon('pending') ?></i><em>Pending review</em></span>
        <strong><?= (int) ($totals['pending_payments'] ?? 0) ?></strong>
      </article>
      <article class="kpi-card billing-case-stat-card is-verified">
        <span class="billing-case-stat-label"><i class="billing-case-stat-icon" aria-hidden="true"><?= lex_billing_summary_icon('verified') ?></i><em>Verified</em></span>
        <strong><?= (int) ($totals['verified_payments'] ?? 0) ?></strong>
      </article>
      <article class="kpi-card billing-case-stat-card is-amount">
        <span class="billing-case-stat-label"><i class="billing-case-stat-icon" aria-hidden="true"><?= lex_billing_summary_icon('amount') ?></i><em>Verified amount</em></span>
        <strong>PHP <?= lex_e(number_format((float) ($totals['verified_total'] ?? 0), 2)) ?></strong>
      </article>
    </div>
  </section>

  <section class="card payment-page-card payment-billing-history-card billing-case-panel">
    <div class="card-head billing-case-panel-header">
      <div>
        <h2>Billing Activity List</h2>
      </div>
      <span class="pill"><?= number_format((int) ($totals['total_payments'] ?? 0)) ?> records</span>
      <a class="button button-primary" href="<?= lex_e(lex_app_url('client/payments.php')) ?>">Open Payments</a>
    </div>

    <div class="table-wrap billing-case-table-wrap">
      <table class="data-table payment-history-table billing-case-list-table">
        <thead>
          <tr>
            <th>Submitted</th>
            <th>Payment for</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Reference</th>
            <th>Reviewed</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recentPayments): ?>
            <?php foreach ($recentPayments as $payment): ?>
              <tr>
                <td data-label="Submitted">
                  <strong><?= lex_e(date('M j, Y', strtotime((string) $payment['created_at']))) ?></strong>
                  <span class="muted"><?= lex_e(date('g:i A', strtotime((string) $payment['created_at']))) ?></span>
                </td>
                <td data-label="Payment for">
                  <div class="billing-case-file-cell">
                    <strong><?= lex_e((string) $payment['payment_for']) ?></strong>
                    <span class="muted">Manual payment proof</span>
                  </div>
                </td>
                <td data-label="Amount">PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></td>
                <td data-label="Status"><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></td>
                <td data-label="Reference">
                  <?php if ((string) ($payment['reference_number'] ?? '') !== ''): ?>
                    <?= lex_e((string) $payment['reference_number']) ?>
                  <?php else: ?>
                    <span class="muted">None</span>
                  <?php endif; ?>
                </td>
                <td data-label="Reviewed">
                  <?php if (!empty($payment['reviewed_at'])): ?>
                    <?= lex_e(date('M j, Y g:i A', strtotime((string) $payment['reviewed_at']))) ?>
                  <?php else: ?>
                    <span class="muted">Pending</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6">
                <div class="billing-case-empty-state">
                  <strong>No billing records yet.</strong>
                  <p class="muted">Submitted payment proofs will appear here for review tracking.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>
<?php lex_page_footer(); ?>
