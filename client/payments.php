<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);

$gcashAccountName = trim(lex_site_setting('gcash_account_name'));
$gcashNumber = trim(lex_site_setting('gcash_number'));
$gcashInstructions = trim(lex_site_setting('gcash_instructions'));
$gcashQrStoredName = trim(lex_site_setting('gcash_qr_stored_name'));
$gcashReady = $gcashAccountName !== '' && $gcashNumber !== '' && $gcashQrStoredName !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $proof = null;
    try {
        if (!$gcashReady) {
            throw new RuntimeException('Manual GCash payments are not configured yet. Please contact the admin.');
        }

        $paymentFor = trim(lex_sanitize_text($_POST['payment_for'] ?? ''));
        $amountInput = str_replace(',', '', trim((string) ($_POST['amount'] ?? '0')));
        $amount = is_numeric($amountInput) ? (float) $amountInput : 0.0;
        $payerName = trim(lex_sanitize_text($_POST['payer_name'] ?? ''));
        $payerContact = trim(lex_sanitize_text($_POST['payer_contact'] ?? ''));
        $referenceNumber = trim(lex_sanitize_text($_POST['reference_number'] ?? ''));
        $notes = trim(lex_sanitize_text($_POST['notes'] ?? ''));

        if ($paymentFor === '') {
            throw new RuntimeException('Tell us what this payment is for.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Enter a valid payment amount.');
        }
        if ($payerName === '') {
            $payerName = (string) ($user['full_name'] ?? '');
        }

        $proof = lex_store_payment_proof($_FILES['payment_proof'] ?? []);

        $stmt = $pdo->prepare(
            'INSERT INTO manual_payments
                (client_id, payment_channel, payment_for, amount, currency, payer_name, payer_contact, reference_number, notes, proof_original_name, proof_stored_name, proof_mime_type, proof_size, status)
             VALUES
                (:client_id, "gcash", :payment_for, :amount, "PHP", :payer_name, :payer_contact, :reference_number, :notes, :proof_original_name, :proof_stored_name, :proof_mime_type, :proof_size, "pending")'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'payment_for' => $paymentFor,
            'amount' => number_format($amount, 2, '.', ''),
            'payer_name' => $payerName,
            'payer_contact' => $payerContact,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'proof_original_name' => $proof['original_name'],
            'proof_stored_name' => $proof['stored_name'],
            'proof_mime_type' => $proof['mime_type'],
            'proof_size' => $proof['size'],
        ]);

        $paymentId = (string) $pdo->lastInsertId();
        lex_audit('submit_manual_payment', 'manual_payments', $paymentId);

        foreach (lex_recent('SELECT id FROM users WHERE role = "admin" AND is_active = 1') as $admin) {
            lex_notify((int) $admin['id'], 'payment', 'New GCash payment proof submitted by ' . (string) ($user['full_name'] ?? 'a client') . '.');
        }

        lex_flash_set('success', 'Payment proof submitted. Admin verification is now pending.');
        header('Location: ' . lex_app_url('client/payments.php'));
        exit;
    } catch (Throwable $e) {
        if (!empty($proof['path']) && is_file((string) $proof['path'])) {
            @unlink((string) $proof['path']);
        }
        lex_flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to submit your payment proof right now.');
        header('Location: ' . lex_app_url('client/payments.php'));
        exit;
    }
}

$clientProfile = lex_recent(
    'SELECT contact_number FROM clients WHERE id = :id LIMIT 1',
    ['id' => $clientId]
);
$defaultContact = (string) ($clientProfile[0]['contact_number'] ?? '');

$payments = lex_recent(
    'SELECT mp.*, ru.full_name AS reviewed_by_name
     FROM manual_payments mp
     LEFT JOIN users ru ON ru.id = mp.reviewed_by_user_id
     WHERE mp.client_id = :client_id
     ORDER BY mp.created_at DESC',
    ['client_id' => $clientId]
);
$paymentCount = count($payments);

lex_page_header('Payments', 'payments', $user);
?>
<section class="payment-shell">
  <section class="card payment-hero-card">
    <div class="payment-hero-copy">
      <h2>Submit Your Payment</h2>
      <p class="muted">Scan the GCash QR or send to the listed number, then upload your receipt below.</p>
    </div>
    <div class="payment-progress" aria-label="Payment steps">
      <span class="payment-progress-pill is-done"><strong>1</strong> Order placed</span>
      <span class="payment-progress-pill is-active"><strong>2</strong> Send payment</span>
      <span class="payment-progress-pill"><strong>3</strong> Admin verifies</span>
    </div>
  </section>

  <div class="payment-layout">
    <div class="payment-main">
      <section class="card payment-page-card payment-instructions-card">
        <div class="card-head payment-card-head">
          <div>
            <h3>How it works</h3>
            <p class="muted payment-section-copy">3 simple steps to complete your payment</p>
          </div>
        </div>

        <div class="payment-step-list">
          <article class="payment-step-card">
            <span class="payment-step-number">1</span>
            <div>
              <strong>Scan the QR or send to the GCash number</strong>
              <p>Open your GCash app and scan the QR code on the right, or manually send to the listed number.</p>
            </div>
          </article>
          <article class="payment-step-card">
            <span class="payment-step-number">2</span>
            <div>
              <strong>Complete the payment in your GCash app</strong>
              <p>Enter the exact amount and confirm. Note the reference number shown after success.</p>
            </div>
          </article>
          <article class="payment-step-card">
            <span class="payment-step-number">3</span>
            <div>
              <strong>Upload your screenshot or PDF receipt</strong>
              <p>Use the form below to submit your proof so the admin can verify and confirm your payment.</p>
            </div>
          </article>
        </div>

        <div class="payment-note-box">
          <?php if ($gcashInstructions !== ''): ?>
            <?= nl2br(lex_e($gcashInstructions)) ?>
          <?php else: ?>
            Optional notes for the admin reviewer can be added in the form below if you need to explain the transfer.
          <?php endif; ?>
        </div>
      </section>

      <section class="card payment-page-card payment-form-card">
        <div class="card-head payment-card-head">
          <div>
            <h3>Payment Details</h3>
            <p class="muted payment-section-copy">Fill in payer info and attach your receipt.</p>
          </div>
        </div>

        <?php if ($gcashReady): ?>
          <form method="post" enctype="multipart/form-data" class="form-grid payment-form">
            <?= lex_csrf_field() ?>
            <label class="full">Payment for
              <input type="text" name="payment_for" placeholder="e.g. Consultation fee, retainer, filing fee..." required>
            </label>
            <label>Payer name
              <input type="text" name="payer_name" value="<?= lex_e((string) ($user['full_name'] ?? '')) ?>" required>
            </label>
            <label>Payer contact
              <input type="text" name="payer_contact" value="<?= lex_e($defaultContact) ?>" placeholder="09XXXXXXXXX">
            </label>
            <label>Amount (PHP)
              <input type="number" name="amount" min="0.01" step="0.01" placeholder="1500.00" required>
            </label>
            <label>GCash reference no.
              <input type="text" name="reference_number" placeholder="Optional, from GCash receipt">
            </label>
            <label class="full">Proof of payment
              <div class="payment-upload-box">
                <input class="payment-file-input" type="file" name="payment_proof" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf" required>
                <div class="payment-upload-copy">
                  <strong>Drop file here or click to browse</strong>
                  <span>JPG • PNG • WEBP • PDF • Max 8 MB</span>
                </div>
              </div>
            </label>
            <label class="full">Notes
              <textarea name="notes" rows="3" placeholder="Optional note for the admin reviewer"></textarea>
            </label>
            <div class="alert payment-security-note">
              Security note: Please upload a valid, unedited GCash receipt screenshot. Submitting false or altered proof will result in immediate account restriction.
            </div>
            <button class="button button-primary payment-submit-button" type="submit">Upload a receipt to submit</button>
          </form>
        <?php else: ?>
          <div class="alert alert-error">This form will be enabled once the admin uploads the live GCash QR and account details.</div>
        <?php endif; ?>
      </section>
    </div>

    <aside class="payment-sidebar">
      <section class="card payment-side-card">
        <div class="payment-side-top">
          <span class="payment-side-label">Pay via GCash</span>
          <strong class="payment-side-amount">Manual transfer</strong>
          <p><?= $gcashReady ? 'Scan the QR or send to the account below, then upload your proof.' : 'GCash details will appear here once the admin configures them.' ?></p>
        </div>

        <?php if ($gcashReady): ?>
          <div class="payment-qr-wrap compact">
            <img class="payment-qr-image" src="<?= lex_e(lex_app_url('payment_qr_image.php')) ?>" alt="GCash QR code">
          </div>

          <dl class="payment-side-facts">
            <div>
              <dt>Account name</dt>
              <dd><?= lex_e($gcashAccountName) ?></dd>
            </div>
            <div>
              <dt>GCash number</dt>
              <dd><?= lex_e($gcashNumber) ?></dd>
            </div>
            <div>
              <dt>Channel</dt>
              <dd>GCash manual transfer <span class="payment-inline-badge">Active</span></dd>
            </div>
          </dl>
        <?php else: ?>
          <div class="alert alert-error">GCash payment details have not been configured by the admin yet.</div>
        <?php endif; ?>
      </section>
    </aside>
  </div>

  <section class="card payment-page-card payment-history-card">
    <div class="card-head payment-card-head">
      <div>
        <h3>Payment History</h3>
        <p class="muted payment-section-copy">Track submissions and review status.</p>
      </div>
      <span class="payment-history-badge"><?= (int) $paymentCount ?> <?= $paymentCount === 1 ? 'record' : 'records' ?></span>
    </div>

    <div class="table-wrap payment-history-wrap">
      <table class="data-table payment-history-table">
        <thead>
          <tr>
            <th>Date &amp; time</th>
            <th>For</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Proof</th>
            <th>Admin note</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($payments): ?>
            <?php foreach ($payments as $payment): ?>
              <tr>
                <td data-label="Date &amp; time"><?= lex_e(date('M j, Y g:i A', strtotime((string) $payment['created_at']))) ?></td>
                <td data-label="For"><?= lex_e((string) $payment['payment_for']) ?></td>
                <td data-label="Amount" class="payment-amount-cell">PHP <?= lex_e(number_format((float) $payment['amount'], 2)) ?></td>
                <td data-label="Status"><span class="pill payment-status-pill payment-status-<?= lex_e((string) $payment['status']) ?>"><?= lex_e(ucfirst((string) $payment['status'])) ?></span></td>
                <td data-label="Proof"><a class="payment-download-link" href="<?= lex_e(lex_app_url('payment_proof.php?id=' . (int) $payment['id'] . '&download=1')) ?>">Download</a></td>
                <td data-label="Admin note">
                  <?php if (!empty($payment['admin_notes'])): ?>
                    <?= lex_e((string) $payment['admin_notes']) ?>
                  <?php elseif (!empty($payment['reviewed_by_name'])): ?>
                    Reviewed by <?= lex_e((string) $payment['reviewed_by_name']) ?>
                  <?php else: ?>
                    <span class="muted">Waiting for admin review</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="muted">No payments submitted yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>
<?php lex_page_footer(); ?>
