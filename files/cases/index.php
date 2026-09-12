<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/case_files/helpers.php';
require_once __DIR__ . '/../../config/case_files/actions.php';

lex_case_files_table_ensure();
lex_case_file_vault_table_ensure();

$pdo = lex_pdo();
lex_case_files_seed_from_cases($pdo);

$user = lex_require_role(['lawyer', 'client']);
$clients = lex_recent('SELECT u.id, u.full_name, u.email FROM users u JOIN clients c ON c.user_id = u.id WHERE u.role = "client" AND u.is_active = 1 ORDER BY u.full_name ASC');
$lawyers = lex_recent('SELECT u.id, u.full_name, u.email FROM users u JOIN lawyers l ON l.user_id = u.id WHERE u.role = "lawyer" AND u.is_active = 1 AND l.status = "active" ORDER BY u.full_name ASC');

$error = '';
$failedAction = '';
$filters = lex_case_files_collect_request_filters($user);
$pageSize = 8;

$postResult = lex_case_files_handle_post($pdo, $user, $filters, $clients, $lawyers);
$error = (string) ($postResult['error'] ?? '');
$failedAction = (string) ($postResult['failed_action'] ?? '');

$state = lex_case_files_fetch_state($filters, $pageSize);
$requestFormat = strtolower((string) ($_GET['format'] ?? ''));
if ($requestFormat === 'json') {
    lex_case_files_send_json($state, $filters, $clients, $lawyers, $user);
}

lex_page_header('Case Files', 'case-files', $user);
?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= lex_e($error) ?></div>
<?php endif; ?>
<div id="case-files-summary" data-case-summary-container>
  <?= lex_case_files_render_summary($state) ?>
</div>

<section class="card case-hero case-compact-hero" id="case-overview">
  <form method="get" class="case-search-bar" data-case-filter-form data-no-loading novalidate>
    <input type="hidden" name="page" value="<?= (int) $state['page'] ?>">
    <label class="case-search-field case-search-field-wide case-search-field-search">
      <span>Search</span>
      <input type="search" name="q" placeholder="Name, case file" value="<?= lex_e((string) $state['search']) ?>" autocomplete="off">
    </label>
    <label class="case-search-field">
      <span>Status</span>
      <select name="status">
        <option value="all"<?= $state['status'] === 'all' ? ' selected' : '' ?>>All</option>
        <option value="open"<?= $state['status'] === 'open' ? ' selected' : '' ?>>Open</option>
        <option value="ongoing"<?= $state['status'] === 'ongoing' ? ' selected' : '' ?>>Ongoing</option>
        <option value="closed"<?= $state['status'] === 'closed' ? ' selected' : '' ?>>Closed</option>
      </select>
    </label>
    <label class="case-search-field">
      <span>Sort by</span>
      <?php
        $sortView = match (($state['sort'] ?? 'updated_at') . ':' . ($state['dir'] ?? 'desc')) {
            'updated_at:asc' => 'updated_at_asc',
            'date_created:asc' => 'date_created_asc',
            'date_created:desc' => 'date_created_desc',
            'full_name:asc' => 'full_name_asc',
            'full_name:desc' => 'full_name_desc',
            'case_file_title:asc' => 'case_file_title_asc',
            'case_file_title:desc' => 'case_file_title_desc',
            'status:asc' => 'status_asc',
            'status:desc' => 'status_desc',
            default => 'updated_at_desc',
        };
      ?>
      <select name="sort_view" data-case-sort-view>
        <option value="updated_at_desc"<?= $sortView === 'updated_at_desc' ? ' selected' : '' ?>>Recently updated</option>
        <option value="updated_at_asc"<?= $sortView === 'updated_at_asc' ? ' selected' : '' ?>>Least recently updated</option>
        <option value="date_created_desc"<?= $sortView === 'date_created_desc' ? ' selected' : '' ?>>Date created</option>
        <option value="date_created_asc"<?= $sortView === 'date_created_asc' ? ' selected' : '' ?>>Date created oldest</option>
        <option value="full_name_asc"<?= $sortView === 'full_name_asc' ? ' selected' : '' ?>>Full name A-Z</option>
        <option value="full_name_desc"<?= $sortView === 'full_name_desc' ? ' selected' : '' ?>>Full name Z-A</option>
        <option value="case_file_title_asc"<?= $sortView === 'case_file_title_asc' ? ' selected' : '' ?>>Case file A-Z</option>
        <option value="case_file_title_desc"<?= $sortView === 'case_file_title_desc' ? ' selected' : '' ?>>Case file Z-A</option>
        <option value="status_asc"<?= $sortView === 'status_asc' ? ' selected' : '' ?>>Status A-Z</option>
        <option value="status_desc"<?= $sortView === 'status_desc' ? ' selected' : '' ?>>Status Z-A</option>
      </select>
      <input type="hidden" name="sort" value="<?= lex_e((string) $state['sort']) ?>" data-case-sort-hidden>
      <input type="hidden" name="dir" value="<?= lex_e((string) $state['dir']) ?>" data-case-dir-hidden>
    </label>
    <div class="case-search-actions">
      <button class="button button-primary" type="submit">Apply</button>
      <a class="button button-secondary" href="case_files.php" data-case-reset>Reset</a>
      <?php if ($user['role'] === 'lawyer'): ?>
        <button class="button button-accent" type="button" data-case-create-open>New case</button>
      <?php endif; ?>
    </div>
  </form>
  <p class="case-search-status" data-case-search-status aria-live="polite">Showing all <?= number_format((int) $state['counts']['total']) ?> case files.</p>
</section>

<section class="case-grid case-compact-grid" id="case-list" data-case-files-app data-endpoint="<?= lex_e(lex_app_url('case_files.php')) ?>" data-role="<?= lex_e($user['role']) ?>">
  <div id="case-files-list" data-case-list-container>
    <?= lex_case_files_render_list($state, $filters) ?>
  </div>

  <div id="case-files-detail" data-case-detail-container>
    <?= lex_case_files_render_detail($state, $filters, $user) ?>
  </div>
</section>

<section class="case-grid case-vault-section case-compact-grid">
  <div id="case-files-vault" data-case-vault-container>
    <?= lex_case_files_render_vault_panel($state, $filters, $user) ?>
  </div>
</section>

<section class="case-grid case-grid-bottom case-compact-grid">
  <div id="case-files-activity" data-case-activity-container>
    <?= lex_case_files_render_activity($state) ?>
  </div>
</section>

<div id="case-files-editor" data-case-editor-container>
  <?= lex_case_files_render_editor($state, $filters, $clients, $lawyers, $user) ?>
</div>

<script>
window.LEX_CASE_FILES_STATE = <?= json_encode([
  'page' => (int) $state['page'],
  'pageCount' => (int) $state['page_count'],
  'search' => (string) $state['search'],
  'status' => (string) $state['status'],
  'sort' => (string) $state['sort'],
  'dir' => (string) $state['dir'],
  'record' => (int) (($state['selected']['id'] ?? 0) ?: 0),
  'failedAction' => $failedAction,
  'error' => $error,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php lex_page_footer(); ?>
