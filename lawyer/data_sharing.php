<?php
require_once __DIR__ . '/../config/bootstrap.php';

/**
 * Lawyer-to-lawyer data sharing, gated by admin approval and recorded on
 * the tamper-evident blockchain ledger (config/blockchain/ledger.php).
 * Flow: Lawyer A picks one of their own case files + Lawyer B -> a
 * "share_requested" block is appended and an admin notified -> an admin
 * approves or rejects on admin/data_sharing.php ("share_approved" /
 * "share_rejected" block) -> on approval, Lawyer B gets read access to
 * that case file's secure vault (case_file_shares) and can open it from
 * their own Case Files page.
 */

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
$lawyerUserId = (int) $user['id'];

lex_case_files_table_ensure();
lex_data_sharing_table_ensure();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('lawyer/data_sharing.php');
        $error = 'Invalid CSRF token. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'request_share') {
            $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
            $toLawyerUserId = lex_sanitize_int($_POST['to_lawyer_user_id'] ?? 0);
            $note = lex_sanitize_multiline_text($_POST['note'] ?? '');

            $stmt = $pdo->prepare(
                'SELECT id FROM case_files WHERE id = :id AND (assigned_lawyer_user_id = :uid OR created_by_user_id = :uid2) LIMIT 1'
            );
            $stmt->execute(['id' => $caseFileId, 'uid' => $lawyerUserId, 'uid2' => $lawyerUserId]);
            $ownsCaseFile = (bool) $stmt->fetchColumn();

            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "lawyer" AND is_active = 1 LIMIT 1');
            $stmt->execute(['id' => $toLawyerUserId]);
            $validTarget = (bool) $stmt->fetchColumn();

            $limit = lex_rate_limit_hit('data_share_request', lex_rate_limit_key(lex_rate_limit_user_part()), 20, 3600, 0);

            if (!$limit['allowed']) {
                $error = lex_rate_limit_message((int) $limit['retry_after']);
            } elseif (!$ownsCaseFile) {
                $error = 'Choose one of your own case files to share.';
            } elseif (!$validTarget || $toLawyerUserId === $lawyerUserId) {
                $error = 'Choose another active lawyer to share with.';
            } else {
                $existing = $pdo->prepare('SELECT id FROM data_sharing_requests WHERE case_file_id = :cf AND to_lawyer_user_id = :to AND status = "pending" LIMIT 1');
                $existing->execute(['cf' => $caseFileId, 'to' => $toLawyerUserId]);
                if ($existing->fetchColumn()) {
                    $error = 'A pending request for this case file and lawyer already exists.';
                } else {
                    lex_data_sharing_create_request($caseFileId, $lawyerUserId, $toLawyerUserId, $note);
                    foreach (lex_recent('SELECT id FROM users WHERE role = "admin" AND is_active = 1') as $admin) {
                        lex_notify((int) $admin['id'], 'data_sharing', (string) $user['full_name'] . ' requested to share a case file with another lawyer.');
                    }
                    lex_notify($toLawyerUserId, 'data_sharing', (string) $user['full_name'] . ' requested to share a case file with you (pending admin approval).');
                    lex_audit('request_data_share', 'data_sharing_requests', (string) $caseFileId);
                    lex_flash_set('success', 'Share request submitted for admin approval and recorded on the blockchain ledger.');
                    header('Location: ' . lex_app_url('lawyer/data_sharing.php'));
                    exit;
                }
            }
        } elseif ($action === 'cancel_request') {
            $requestId = lex_sanitize_int($_POST['request_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM data_sharing_requests WHERE id = :id AND from_lawyer_user_id = :uid AND status = "pending"');
            $stmt->execute(['id' => $requestId, 'uid' => $lawyerUserId]);
            if ($stmt->rowCount() > 0) {
                lex_blockchain_add_block('share_revoked', ['request_id' => $requestId, 'from_lawyer_user_id' => $lawyerUserId], $lawyerUserId);
                lex_audit('cancel_data_share_request', 'data_sharing_requests', (string) $requestId);
                lex_flash_set('success', 'Request cancelled.');
            }
            header('Location: ' . lex_app_url('lawyer/data_sharing.php'));
            exit;
        }
    }
}

$myCaseFiles = lex_recent(
    'SELECT id, full_name, case_file_title FROM case_files WHERE assigned_lawyer_user_id = :uid OR created_by_user_id = :uid2 ORDER BY case_file_title ASC',
    ['uid' => $lawyerUserId, 'uid2' => $lawyerUserId]
);
$otherLawyers = lex_recent(
    'SELECT id, full_name FROM users WHERE role = "lawyer" AND is_active = 1 AND id <> :uid ORDER BY full_name ASC',
    ['uid' => $lawyerUserId]
);

$preselectCaseFileId = lex_sanitize_int($_GET['case_file_id'] ?? 0);

$outgoing = lex_recent(
    'SELECT r.*, cf.case_file_title, cf.full_name AS client_full_name, u.full_name AS to_lawyer_name, du.full_name AS decided_by_name
     FROM data_sharing_requests r
     JOIN case_files cf ON cf.id = r.case_file_id
     JOIN users u ON u.id = r.to_lawyer_user_id
     LEFT JOIN users du ON du.id = r.decided_by_user_id
     WHERE r.from_lawyer_user_id = :uid
     ORDER BY r.created_at DESC',
    ['uid' => $lawyerUserId]
);

$incoming = lex_recent(
    'SELECT r.*, cf.case_file_title, cf.full_name AS client_full_name, u.full_name AS from_lawyer_name
     FROM data_sharing_requests r
     JOIN case_files cf ON cf.id = r.case_file_id
     JOIN users u ON u.id = r.from_lawyer_user_id
     WHERE r.to_lawyer_user_id = :uid
     ORDER BY r.created_at DESC',
    ['uid' => $lawyerUserId]
);

$sharedWithMe = lex_recent(
    'SELECT s.*, cf.case_file_title, cf.full_name AS client_full_name, cf.id AS case_file_id
     FROM case_file_shares s
     JOIN case_files cf ON cf.id = s.case_file_id
     WHERE s.lawyer_user_id = :uid AND s.revoked_at IS NULL
     ORDER BY s.granted_at DESC',
    ['uid' => $lawyerUserId]
);

$statusClass = static fn (string $status): string => match ($status) {
    'approved' => 'is-open',
    'rejected' => 'is-closed',
    'revoked' => 'is-closed',
    default => 'is-ongoing',
};

lex_page_header('Data Sharing', 'data-sharing', $user);
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>

<section class="card">
  <div class="card-head">
    <div>
      <h2>Request to share a case file</h2>
      <p class="muted">Every request is recorded on an append-only, hash-chained ledger and must be approved by an administrator before the other lawyer gains access.</p>
    </div>
  </div>
  <form method="post" class="form-grid">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="request_share">
    <label>Case file to share
      <select name="case_file_id" required>
        <option value="">Select one of your case files…</option>
        <?php foreach ($myCaseFiles as $caseFile): ?>
          <option value="<?= (int) $caseFile['id'] ?>" <?= (int) $caseFile['id'] === $preselectCaseFileId ? 'selected' : '' ?>><?= lex_e((string) $caseFile['case_file_title']) ?> - <?= lex_e((string) $caseFile['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Share with lawyer
      <select name="to_lawyer_user_id" required>
        <option value="">Select a lawyer…</option>
        <?php foreach ($otherLawyers as $lawyer): ?>
          <option value="<?= (int) $lawyer['id'] ?>"><?= lex_e((string) $lawyer['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="full">Note for the admin (optional)
      <textarea name="note" rows="3" placeholder="Why does this case file need to be shared?"></textarea>
    </label>
    <button class="button button-primary" type="submit">Submit for admin approval</button>
  </form>
</section>

<section class="content-grid two-col">
  <article class="card">
    <div class="card-head"><h2>My outgoing requests</h2></div>
    <div class="admin-audit-list">
      <?php foreach ($outgoing as $request): ?>
        <div class="admin-audit-row">
          <div>
            <strong><?= lex_e((string) $request['case_file_title']) ?></strong>
            <span>to <?= lex_e((string) $request['to_lawyer_name']) ?></span>
          </div>
          <span class="status-pill <?= lex_e($statusClass((string) $request['status'])) ?>"><?= lex_e(ucfirst((string) $request['status'])) ?></span>
          <?php if ((string) $request['status'] === 'pending'): ?>
            <form method="post" style="display:inline;">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="cancel_request">
              <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
              <button class="button button-secondary" type="submit">Cancel</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$outgoing): ?><div class="admin-empty-line">No outgoing share requests yet.</div><?php endif; ?>
    </div>
  </article>
  <article class="card">
    <div class="card-head"><h2>Requests to me</h2></div>
    <div class="admin-audit-list">
      <?php foreach ($incoming as $request): ?>
        <div class="admin-audit-row">
          <div>
            <strong><?= lex_e((string) $request['case_file_title']) ?></strong>
            <span>from <?= lex_e((string) $request['from_lawyer_name']) ?></span>
          </div>
          <span class="status-pill <?= lex_e($statusClass((string) $request['status'])) ?>"><?= lex_e(ucfirst((string) $request['status'])) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$incoming): ?><div class="admin-empty-line">No incoming share requests yet.</div><?php endif; ?>
    </div>
  </article>
</section>

<section class="card">
  <div class="card-head"><h2>Case files shared with me</h2></div>
  <div class="admin-audit-list">
    <?php foreach ($sharedWithMe as $share): ?>
      <div class="admin-audit-row">
        <div>
          <strong><?= lex_e((string) $share['case_file_title']) ?></strong>
          <span><?= lex_e((string) $share['client_full_name']) ?> &middot; granted <?= lex_e(lex_message_timestamp((string) $share['granted_at'])) ?></span>
        </div>
        <a class="button button-secondary" href="<?= lex_e(lex_app_url('case_files.php?record=' . (int) $share['case_file_id'])) ?>">Open vault</a>
      </div>
    <?php endforeach; ?>
    <?php if (!$sharedWithMe): ?><div class="admin-empty-line">No case files have been shared with you yet.</div><?php endif; ?>
  </div>
</section>
<?php lex_page_footer(); ?>
