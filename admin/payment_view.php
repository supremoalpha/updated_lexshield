<?php
require_once __DIR__ . '/../config/bootstrap.php';

$admin = lex_require_role('admin');
$pdo = lex_pdo();
$paymentId = lex_sanitize_int($_GET['id'] ?? $_POST['id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit('Payment not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $decision = trim((string) ($_POST['decision'] ?? ''));
    $adminNotes = trim(lex_sanitize_text($_POST['admin_notes'] ?? ''));

    if (!in_array($decision, ['verified', 'rejected'], true)) {
        lex_flash_set('error', 'Choose a valid payment decision.');
        header('Location: ' . lex_app_url('admin/payment_view.php?id=' . $paymentId));
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT mp.id, mp.status, mp.payment_for, cu.id AS client_user_id, cu.full_name AS client_name
         FROM manual_payments mp
         JOIN clients c ON c.id = mp.client_id
         JOIN users cu ON cu.id = c.user_id
         WHERE mp.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $paymentId]);
    $target = $stmt->fetch();

    if (!$target) {
        lex_flash_set('error', 'Payment not found.');
        header('Location: ' . lex_app_url('admin/payments.php'));
        exit;
    }

    $pdo->prepare(
        'UPDATE manual_payments
         SET status = :status,
             admin_notes = :admin_notes,
             reviewed_by_user_id = :reviewed_by_user_id,
             reviewed_at = NOW()
         WHERE id = :id'
    )->execute([
        'status' => $decision,
        'admin_notes' => $adminNotes,
        'reviewed_by_user_id' => (int) $admin['id'],
        'id' => $paymentId,
    ]);

    lex_audit($decision === 'verified' ? 'verify_manual_payment' : 'reject_manual_payment', 'manual_payments', (string) $paymentId);
    $note = $adminNotes !== '' ? ' Note: ' . $adminNotes : '';
    lex_notify((int) $target['client_user_id'], 'payment', 'Your payment for "' . (string) $target['payment_for'] . '" was marked ' . $decision . '.' . $note);
    lex_flash_set('success', $decision === 'verified' ? 'Payment approved.' : 'Payment rejected.');
    header('Location: ' . lex_app_url('admin/payment_view.php?id=' . $paymentId));
    exit;
}

$stmt = $pdo->prepare(
    'SELECT mp.*, cu.full_name AS client_name, cu.email AS client_email, c.contact_number AS client_contact,
            ru.full_name AS reviewed_by_name
     FROM manual_payments mp
     JOIN clients c ON c.id = mp.client_id
     JOIN users cu ON cu.id = c.user_id
     LEFT JOIN users ru ON ru.id = mp.reviewed_by_user_id
     WHERE mp.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Payment not found.');
}

$isPreviewableImage = str_starts_with((string) ($payment['proof_mime_type'] ?? ''), 'image/');
$status = (string) ($payment['status'] ?? 'pending');
$statusLabel = ucfirst($status);
$submittedAt = date('M j, Y g:i A', strtotime((string) $payment['created_at']));
$reviewedAt = !empty($payment['reviewed_at']) ? date('M j, Y g:i A', strtotime((string) $payment['reviewed_at'])) : null;
$proofSizeBytes = (int) ($payment['proof_size'] ?? 0);
$proofSizeLabel = $proofSizeBytes > 0 ? number_format($proofSizeBytes / 1024 / 1024, 2) . ' MB' : 'Unknown';
$decisionVerb = $status === 'verified' ? 'verified' : ($status === 'rejected' ? 'rejected' : 'pending review');

lex_page_header('Payments', 'payments');
?>
<section class="payment-shell payment-admin-shell">
  <section class="card payment-hero-card payment-admin-hero">
    <div class="payment-admin-hero__content">
      <div class="payment-hero-copy">
        <p class="payment-admin-eyebrow">Admin payment review</p>
        <h2>Review payment #<?= (int) $payment['id'] ?></h2>
        <p class="muted">Inspect the proof, confirm the transfer details, and record a clear decision for the client.</p>
      </div>
      <div class="payment-progress" aria-label="Payment review stages">
        <span class="payment-progress-pill is-done"><strong>1</strong> Submitted</span>
        <span class="payment-progress-pill <?= $status === 'pending' ? 'is-active' : 'is-done' ?>"><strong>2</strong> Admin review</span>
        <span class="payment-progress-pill <?= $status === 'verified' ? 'is-done' : '' ?>"><strong>3</strong> <?= $status === 'rejected' ? 'Decision sent' : 'Verification' ?></span>
      </div>
    </div>
    <div class="payment-admin-hero__actions">
      <div class="payment-admin-highlight">
        <span class="payment-admin-highlight__label">Amount under review</span>
        <strong>PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></strong>
        <span class="pill payment-status-pill payment-status-<?= lex_e($status) ?>"><?= lex_e($statusLabel) ?></span>
      </div>
      <div class="inline-actions">
        <a class="button button-secondary" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'] . '&download=1')) ?>">Download proof</a>
        <a class="button button-secondary" href="<?= lex_e(lex_app_url('admin/payments.php')) ?>">Back to queue</a>
      </div>
    </div>
  </section>

  <div class="payment-layout payment-admin-layout">
    <div class="payment-main">
      <section class="card payment-page-card payment-admin-overview">
        <div class="card-head payment-card-head">
          <div>
            <h3>Review overview</h3>
            <p class="muted payment-section-copy">The most important details are surfaced first so you can verify this submission quickly.</p>
          </div>
        </div>

        <div class="payment-admin-metric-grid">
          <article class="payment-admin-metric-card">
            <span>Client</span>
            <strong><?= lex_e((string) $payment['client_name']) ?></strong>
            <small><?= lex_e((string) $payment['client_email']) ?></small>
          </article>
          <article class="payment-admin-metric-card">
            <span>Submitted</span>
            <strong><?= lex_e($submittedAt) ?></strong>
            <small><?= !empty($payment['payment_channel']) ? lex_e(strtoupper((string) $payment['payment_channel'])) : 'Manual payment' ?></small>
          </article>
          <article class="payment-admin-metric-card">
            <span>Reference</span>
            <strong><?= (string) ($payment['reference_number'] ?? '') !== '' ? lex_e((string) $payment['reference_number']) : 'No reference' ?></strong>
            <small><?= lex_e((string) ($payment['proof_original_name'] ?? 'Uploaded file')) ?></small>
          </article>
          <article class="payment-admin-metric-card">
            <span>Last decision</span>
            <strong><?= lex_e($statusLabel) ?></strong>
            <small><?= $reviewedAt ? 'Updated ' . lex_e($reviewedAt) : 'No admin action yet' ?></small>
          </article>
        </div>
      </section>

      <section class="payment-review-grid payment-admin-review-grid">
        <article class="payment-panel payment-admin-panel">
          <div class="card-head payment-card-head">
            <div>
              <h3>Payment details</h3>
              <p class="muted payment-section-copy">Cross-check the payer, purpose, and submission metadata.</p>
            </div>
          </div>

          <dl class="payment-account-list payment-admin-details-list">
            <div><dt>Client</dt><dd><?= lex_e((string) $payment['client_name']) ?></dd></div>
            <div><dt>Email</dt><dd><?= lex_e((string) $payment['client_email']) ?></dd></div>
            <div><dt>Contact</dt><dd><?= (string) ($payment['client_contact'] ?? '') !== '' ? lex_e((string) $payment['client_contact']) : 'Not provided' ?></dd></div>
            <div><dt>Payer name</dt><dd><?= (string) ($payment['payer_name'] ?? '') !== '' ? lex_e((string) $payment['payer_name']) : 'Not provided' ?></dd></div>
            <div><dt>Payer contact</dt><dd><?= (string) ($payment['payer_contact'] ?? '') !== '' ? lex_e((string) $payment['payer_contact']) : 'Not provided' ?></dd></div>
            <div><dt>Payment for</dt><dd><?= lex_e((string) $payment['payment_for']) ?></dd></div>
            <div><dt>Amount</dt><dd>PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></dd></div>
            <div><dt>Reference</dt><dd><?= (string) ($payment['reference_number'] ?? '') !== '' ? lex_e((string) $payment['reference_number']) : 'None' ?></dd></div>
            <div><dt>Status</dt><dd><span class="pill payment-status-pill payment-status-<?= lex_e($status) ?>"><?= lex_e($statusLabel) ?></span></dd></div>
            <div><dt>Submitted</dt><dd><?= lex_e($submittedAt) ?></dd></div>
            <div><dt>Reviewed by</dt><dd><?= !empty($payment['reviewed_by_name']) ? lex_e((string) $payment['reviewed_by_name']) : 'Not reviewed yet' ?></dd></div>
            <div><dt>Reviewed at</dt><dd><?= $reviewedAt ? lex_e($reviewedAt) : 'Not reviewed yet' ?></dd></div>
          </dl>

          <?php if (!empty($payment['notes'])): ?>
            <div class="payment-notes-box payment-admin-notes-box">
              <strong>Client notes</strong>
              <p><?= nl2br(lex_e((string) $payment['notes'])) ?></p>
            </div>
          <?php endif; ?>
        </article>

        <article class="payment-panel payment-admin-panel">
          <div class="card-head payment-card-head">
            <div>
              <h3>Uploaded proof</h3>
              <p class="muted payment-section-copy">Open the full file if you need to zoom in or verify details outside the preview.</p>
            </div>
            <a class="button button-secondary" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'])) ?>" target="_blank" rel="noopener">Open file</a>
          </div>

          <div class="payment-admin-proof-stage">
            <?php if ($isPreviewableImage): ?>
              <a class="payment-proof-preview-link payment-admin-proof-link" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'])) ?>" target="_blank" rel="noopener">
                <img class="payment-proof-preview payment-admin-proof-preview" src="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'])) ?>" alt="Uploaded payment proof">
              </a>
            <?php else: ?>
              <div class="payment-proof-file-box payment-admin-file-box">
                <strong><?= lex_e((string) $payment['proof_original_name']) ?></strong>
                <p class="muted">Preview is unavailable for this file type. Download the proof to inspect it in full.</p>
              </div>
            <?php endif; ?>
          </div>

          <dl class="payment-account-list payment-admin-proof-meta">
            <div><dt>File name</dt><dd><?= lex_e((string) ($payment['proof_original_name'] ?? 'Uploaded file')) ?></dd></div>
            <div><dt>Type</dt><dd><?= !empty($payment['proof_mime_type']) ? lex_e((string) $payment['proof_mime_type']) : 'Unknown' ?></dd></div>
            <div><dt>Size</dt><dd><?= lex_e($proofSizeLabel) ?></dd></div>
          </dl>
        </article>
      </section>

      <section class="card payment-page-card payment-admin-decision-card">
        <div class="card-head payment-card-head">
          <div>
            <h3>Admin decision</h3>
            <p class="muted payment-section-copy">Leave a short note when rejecting, or add context so the client has a clear audit trail.</p>
          </div>
          <span class="payment-history-badge">Current status: <?= lex_e($statusLabel) ?></span>
        </div>

        <form method="post" class="form-grid payment-form payment-admin-form">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $payment['id'] ?>">
          <label>Decision
            <select name="decision" required>
              <option value="verified"<?= $status === 'verified' ? ' selected' : '' ?>>Verify payment</option>
              <option value="rejected"<?= $status === 'rejected' ? ' selected' : '' ?>>Reject payment</option>
            </select>
          </label>
          <div class="payment-admin-status-note">
            <strong>Review state</strong>
            <p>This payment is currently <span class="payment-admin-status-note__value"><?= lex_e($decisionVerb) ?></span>. Saving here will update the client notification and audit log.</p>
          </div>
          <label class="full">Admin notes
            <textarea name="admin_notes" rows="5" placeholder="Optional message for the client or internal audit trail"><?= lex_e((string) ($payment['admin_notes'] ?? '')) ?></textarea>
          </label>
          <div class="alert payment-security-note full">
            Double-check the amount, reference number, and proof clarity before approving. If anything looks incomplete or mismatched, reject it with a short explanation so the client knows what to fix.
          </div>
          <button class="button button-primary payment-submit-button" type="submit">Save decision</button>
        </form>
      </section>
    </div>

    <aside class="payment-sidebar">
      <section class="card payment-side-card payment-admin-side-card">
        <div class="payment-side-top payment-admin-side-top">
          <span class="payment-side-label">Review snapshot</span>
          <strong class="payment-side-amount">PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></strong>
          <p><?= lex_e((string) $payment['payment_for']) ?></p>
        </div>

        <dl class="payment-side-facts">
          <div>
            <dt>Client</dt>
            <dd><?= lex_e((string) $payment['client_name']) ?></dd>
          </div>
          <div>
            <dt>Status</dt>
            <dd><span class="pill payment-status-pill payment-status-<?= lex_e($status) ?>"><?= lex_e($statusLabel) ?></span></dd>
          </div>
          <div>
            <dt>Reference</dt>
            <dd><?= (string) ($payment['reference_number'] ?? '') !== '' ? lex_e((string) $payment['reference_number']) : 'None' ?></dd>
          </div>
          <div>
            <dt>Proof file</dt>
            <dd><?= lex_e((string) ($payment['proof_original_name'] ?? 'Uploaded file')) ?></dd>
          </div>
        </dl>
      </section>

      <section class="card payment-page-card payment-admin-checklist-card">
        <div class="card-head payment-card-head">
          <div>
            <h3>Review checklist</h3>
            <p class="muted payment-section-copy">A quick pass to help make each decision consistent.</p>
          </div>
        </div>

        <div class="payment-step-list">
          <article class="payment-step-card">
            <span class="payment-step-number">1</span>
            <div>
              <strong>Match the amount and purpose</strong>
              <p>Confirm the uploaded proof supports the requested amount and the stated payment purpose.</p>
            </div>
          </article>
          <article class="payment-step-card">
            <span class="payment-step-number">2</span>
            <div>
              <strong>Check payer and reference details</strong>
              <p>Use the reference number, payer name, and contact details to spot mismatches or missing fields.</p>
            </div>
          </article>
          <article class="payment-step-card">
            <span class="payment-step-number">3</span>
            <div>
              <strong>Leave a useful note if needed</strong>
              <p>When rejecting, tell the client exactly what needs correction so the next submission is easier to approve.</p>
            </div>
          </article>
        </div>
      </section>
    </aside>
  </div>
</section>
<?php lex_page_footer(); ?>
