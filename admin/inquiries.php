<?php
require_once __DIR__ . '/../config/bootstrap.php';

lex_require_role('admin');
$pdo = lex_pdo();

function lex_inquiry_status_label(string $status): string
{
    return match ($status) {
        'read' => 'Read',
        'replied' => 'Replied',
        'closed' => 'Closed',
        default => 'New',
    };
}

function lex_inquiry_status_class(string $status): string
{
    return match ($status) {
        'read' => 'is-ongoing',
        'replied' => 'is-open',
        'closed' => 'is-closed',
        default => 'is-new',
    };
}

function lex_inquiry_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'IN';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'IN';
}

function lex_inquiry_tone(string $name): string
{
    $tones = ['blue', 'green', 'purple', 'orange', 'cyan', 'indigo', 'gold'];
    $index = abs(crc32(strtolower(trim($name)))) % count($tones);
    return $tones[$index];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update_status') {
        $inquiryId = lex_sanitize_int($_POST['inquiry_id'] ?? 0);
        $status = strtolower(lex_sanitize_text($_POST['status'] ?? 'new'));
        if (!in_array($status, ['new', 'read', 'replied', 'closed'], true)) {
            $status = 'new';
        }
        $stmt = $pdo->prepare('UPDATE quick_inquiries SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $inquiryId]);
        lex_audit('update_quick_inquiry_status', 'quick_inquiries', (string) $inquiryId);
        lex_flash_set('success', 'Inquiry updated.');
        header('Location: ' . lex_app_url('admin/inquiries.php'));
        exit;
    } elseif ($action === 'delete_inquiry') {
        $inquiryId = lex_sanitize_int($_POST['inquiry_id'] ?? 0);
        if ($inquiryId > 0) {
            $stmt = $pdo->prepare('DELETE FROM quick_inquiries WHERE id = :id');
            $stmt->execute(['id' => $inquiryId]);
            lex_audit('delete_quick_inquiry', 'quick_inquiries', (string) $inquiryId);
            lex_flash_set('success', 'Inquiry deleted.');
        }
        header('Location: ' . lex_app_url('admin/inquiries.php'));
        exit;
    }
}

$statusFilter = strtolower(lex_sanitize_text($_GET['status'] ?? 'all'));
$search = trim(lex_sanitize_text($_GET['q'] ?? ''));
if (!in_array($statusFilter, ['all', 'new', 'read', 'replied', 'closed'], true)) {
    $statusFilter = 'all';
}

$params = [];
$where = ' WHERE 1=1';
if ($statusFilter !== 'all') {
    $where .= ' AND status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where .= ' AND (full_name LIKE :search_name OR email LIKE :search_email OR phone LIKE :search_phone OR topic LIKE :search_topic OR message LIKE :search_message)';
    $searchLike = '%' . $search . '%';
    $params['search_name'] = $searchLike;
    $params['search_email'] = $searchLike;
    $params['search_phone'] = $searchLike;
    $params['search_topic'] = $searchLike;
    $params['search_message'] = $searchLike;
}

$counts = lex_recent(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = "new" THEN 1 ELSE 0 END) AS new_count,
        SUM(CASE WHEN status = "read" THEN 1 ELSE 0 END) AS read_count,
        SUM(CASE WHEN status = "replied" THEN 1 ELSE 0 END) AS replied_count,
        SUM(CASE WHEN status = "closed" THEN 1 ELSE 0 END) AS closed_count
     FROM quick_inquiries'
)[0] ?? ['total' => 0, 'new_count' => 0, 'read_count' => 0, 'replied_count' => 0, 'closed_count' => 0];

$perPage = 10;
$totalInquiries = lex_stats('SELECT COUNT(*) FROM quick_inquiries' . $where, $params);
$totalInquiryPages = max(1, (int) ceil($totalInquiries / $perPage));
$currentPage = min(max(1, lex_sanitize_int($_GET['page'] ?? 1)), $totalInquiryPages);
$offset = ($currentPage - 1) * $perPage;

$stmt = $pdo->prepare('SELECT * FROM quick_inquiries' . $where . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$stmt->execute($params);
$inquiries = $stmt->fetchAll();

lex_page_header('Quick Inquiries', 'inquiries');
?>
<section class="admin-inquiries-stats" aria-label="Inquiry summary">
  <article class="admin-inquiries-stat-card">
    <div class="admin-inquiries-stat-icon tone-blue" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M4.75 5.5h14.5c.69 0 1.25.56 1.25 1.25v10.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25V6.75c0-.69.56-1.25 1.25-1.25Zm.65 1.5 6.6 5.02L18.6 7H5.4Zm13.6 1.4-6.55 4.98a.75.75 0 0 1-.9 0L5 8.4V17h14V8.4Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-inquiries-stat-copy">
      <span>Total Inquiries</span>
      <strong><?= number_format((int) $counts['total']) ?></strong>
    </div>
  </article>
  <article class="admin-inquiries-stat-card">
    <div class="admin-inquiries-stat-icon tone-gold" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.5a8.5 8.5 0 1 1 0 17 8.5 8.5 0 0 1 0-17Zm.75 4.25h-1.5v5l4.2 2.5.77-1.28-3.47-2.06V7.75Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-inquiries-stat-copy">
      <span>New</span>
      <strong class="tone-warning"><?= number_format((int) $counts['new_count']) ?></strong>
    </div>
  </article>
  <article class="admin-inquiries-stat-card">
    <div class="admin-inquiries-stat-icon tone-green" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm4.18 6.38-5.05 5.05-2.31-2.31-1.06 1.06 3.37 3.37 6.11-6.1-1.06-1.07Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-inquiries-stat-copy">
      <span>Replied</span>
      <strong class="tone-success"><?= number_format((int) $counts['replied_count']) ?></strong>
    </div>
  </article>
  <article class="admin-inquiries-stat-card">
    <div class="admin-inquiries-stat-icon tone-purple" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M6.5 4.5h11A1.5 1.5 0 0 1 19 6v12.25a.75.75 0 0 1-1.14.64L12 15.35l-5.86 3.54A.75.75 0 0 1 5 18.25V6a1.5 1.5 0 0 1 1.5-1.5Zm0 1.5v10.92l5.11-3.09a.75.75 0 0 1 .78 0l5.11 3.09V6h-11Z" fill="currentColor"/></svg>
    </div>
    <div class="admin-inquiries-stat-copy">
      <span>Closed</span>
      <strong><?= number_format((int) $counts['closed_count']) ?></strong>
    </div>
  </article>
</section>

<section class="card admin-inquiries-card">
  <div class="admin-inquiries-head">
    <div>
      <h2>Inquiry Inbox</h2>
      <p>Review quick inquiries from the public contact form.</p>
    </div>
    <form method="get" class="admin-inquiries-filter-bar">
      <label class="admin-inquiries-search">
        <span class="sr-only">Search inquiries</span>
        <span class="admin-inquiries-field-icon" aria-hidden="true"></span>
        <input type="search" name="q" value="<?= lex_e($search) ?>" placeholder="Search inquiries...">
      </label>
      <label class="admin-inquiries-status-filter">
        <span class="sr-only">Filter by status</span>
        <select name="status" onchange="this.form.submit()">
          <?php foreach (['all' => 'All Status', 'new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'closed' => 'Closed'] as $value => $label): ?>
            <option value="<?= lex_e($value) ?>"<?= $statusFilter === $value ? ' selected' : '' ?>><?= lex_e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="button button-secondary admin-inquiries-filter-button" type="submit">
        <span><?= number_format(count($inquiries)) ?> Shown</span>
      </button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data-table admin-inquiries-table">
      <thead>
        <tr>
          <th>When</th>
          <th>Contact</th>
          <th>Topic</th>
          <th>Message</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$inquiries): ?>
        <tr><td colspan="6">No inquiries found.</td></tr>
      <?php endif; ?>
      <?php foreach ($inquiries as $inquiry): ?>
        <?php
          $status = (string) ($inquiry['status'] ?? 'new');
          $email = (string) ($inquiry['email'] ?? '');
          $name = (string) ($inquiry['full_name'] ?? '');
          $createdAt = strtotime((string) ($inquiry['created_at'] ?? ''));
        ?>
        <tr>
          <td data-label="When">
            <strong class="admin-inquiries-date"><?= lex_e($createdAt ? date('M j, Y', $createdAt) : (string) $inquiry['created_at']) ?></strong>
            <?php if ($createdAt): ?><span class="admin-inquiries-time"><?= lex_e(date('g:i A', $createdAt)) ?></span><?php endif; ?>
          </td>
          <td data-label="Contact">
            <div class="admin-inquiries-contact-cell">
              <span class="admin-inquiries-avatar admin-inquiries-avatar--<?= lex_e(lex_inquiry_tone($name)) ?>" aria-hidden="true"><?= lex_e(lex_inquiry_initials($name)) ?></span>
              <div>
                <strong><?= lex_e($name) ?></strong>
                <a href="mailto:<?= lex_e($email) ?>"><?= lex_e($email) ?></a>
                <span><?= lex_e((string) ($inquiry['phone'] ?: 'No phone')) ?></span>
              </div>
            </div>
          </td>
          <td data-label="Topic"><span class="admin-inquiries-topic"><?= lex_e((string) $inquiry['topic']) ?></span></td>
          <td data-label="Message">
            <button
              class="admin-inquiries-message-button"
              type="button"
              data-inquiry-message-open
              data-inquiry-name="<?= lex_e($name) ?>"
              data-inquiry-email="<?= lex_e($email) ?>"
              data-inquiry-phone="<?= lex_e((string) ($inquiry['phone'] ?: 'No phone')) ?>"
              data-inquiry-topic="<?= lex_e((string) $inquiry['topic']) ?>"
              data-inquiry-message="<?= lex_e((string) $inquiry['message']) ?>"
              data-inquiry-date="<?= lex_e($createdAt ? date('M j, Y g:i A', $createdAt) : (string) $inquiry['created_at']) ?>"
              aria-label="Open message from <?= lex_e($name !== '' ? $name : 'inquiry sender') ?>"
              title="Open message"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.75 5.5h14.5c.69 0 1.25.56 1.25 1.25v10.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25V6.75c0-.69.56-1.25 1.25-1.25Zm.65 1.5 6.6 5.02L18.6 7H5.4Zm13.6 1.4-6.55 4.98a.75.75 0 0 1-.9 0L5 8.4V17h14V8.4Z" fill="currentColor"/></svg>
            </button>
          </td>
          <td data-label="Status"><span class="pill admin-inquiries-status-pill <?= lex_e(lex_inquiry_status_class($status)) ?>"><?= lex_e(lex_inquiry_status_label($status)) ?></span></td>
          <td data-label="Actions">
            <div class="admin-inquiries-actions">
              <form method="post" class="inline-form admin-inquiry-status-form">
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="inquiry_id" value="<?= (int) $inquiry['id'] ?>">
                <select name="status" onchange="this.form.submit()">
                  <?php foreach (['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'closed' => 'Closed'] as $value => $label): ?>
                    <option value="<?= lex_e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= lex_e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <form method="post" onsubmit="return confirm('Delete this inquiry?');">
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="delete_inquiry">
                <input type="hidden" name="inquiry_id" value="<?= (int) $inquiry['id'] ?>">
                <button class="admin-inquiries-delete-button" type="submit" aria-label="Delete inquiry from <?= lex_e($name !== '' ? $name : 'sender') ?>" title="Delete inquiry">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3.75h6a1.5 1.5 0 0 1 1.5 1.5V6H20a.75.75 0 0 1 0 1.5h-1.02l-.84 11.13A2.25 2.25 0 0 1 15.9 20.7H8.1a2.25 2.25 0 0 1-2.24-2.07L5.02 7.5H4A.75.75 0 0 1 4 6h3.5v-.75A1.5 1.5 0 0 1 9 3.75Zm1.5 2.25h3v-.75h-3V6Zm-2.13 1.5.82 10.98a.75.75 0 0 0 .75.69h5.62a.75.75 0 0 0 .75-.69l.82-10.98H8.37Z" fill="currentColor"/></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= lex_admin_pagination('admin/inquiries.php', ['q' => $search, 'status' => $statusFilter], $totalInquiries, $currentPage, $perPage) ?>
</section>

<div class="modal-overlay" id="inquiryMessageModal" data-inquiry-message-modal aria-hidden="true">
  <div class="modal-card wide admin-inquiry-message-modal-card" role="dialog" aria-modal="true" aria-labelledby="inquiryMessageTitle">
    <div class="modal-header">
      <div>
        <h2 id="inquiryMessageTitle">Inquiry Message</h2>
        <p class="modal-note" data-inquiry-modal-date></p>
      </div>
      <button class="close-button" type="button" data-inquiry-message-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <dl class="admin-inquiry-message-details">
        <div>
          <dt>Name</dt>
          <dd data-inquiry-modal-name></dd>
        </div>
        <div>
          <dt>Email</dt>
          <dd data-inquiry-modal-email></dd>
        </div>
        <div>
          <dt>Phone</dt>
          <dd data-inquiry-modal-phone></dd>
        </div>
        <div>
          <dt>Topic</dt>
          <dd data-inquiry-modal-topic></dd>
        </div>
      </dl>
      <div class="admin-inquiry-message-content">
        <h3>Message</h3>
        <p data-inquiry-modal-message></p>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('inquiryMessageModal');
  if (!modal) return;

  const fields = {
    date: modal.querySelector('[data-inquiry-modal-date]'),
    name: modal.querySelector('[data-inquiry-modal-name]'),
    email: modal.querySelector('[data-inquiry-modal-email]'),
    phone: modal.querySelector('[data-inquiry-modal-phone]'),
    topic: modal.querySelector('[data-inquiry-modal-topic]'),
    message: modal.querySelector('[data-inquiry-modal-message]'),
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  };

  const openModal = (button) => {
    if (fields.date) fields.date.textContent = button.dataset.inquiryDate || '';
    if (fields.name) fields.name.textContent = button.dataset.inquiryName || 'No name provided';
    if (fields.email) fields.email.textContent = button.dataset.inquiryEmail || 'No email provided';
    if (fields.phone) fields.phone.textContent = button.dataset.inquiryPhone || 'No phone';
    if (fields.topic) fields.topic.textContent = button.dataset.inquiryTopic || 'No topic';
    if (fields.message) fields.message.textContent = button.dataset.inquiryMessage || 'No message provided';

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    const closeButton = modal.querySelector('[data-inquiry-message-close]');
    if (closeButton) window.setTimeout(() => closeButton.focus(), 40);
  };

  document.querySelectorAll('[data-inquiry-message-open]').forEach((button) => {
    button.addEventListener('click', () => openModal(button));
  });

  modal.querySelectorAll('[data-inquiry-message-close]').forEach((button) => {
    button.addEventListener('click', closeModal);
  });

  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeModal();
    }
  });
})();
</script>
<?php lex_page_footer(); ?>
