<?php

declare(strict_types=1);

/**
 * Case Files feature: filtering, listing, and rendering. Split from
 * config/case_files/core.php (which holds the primitives that
 * files/cases/attachment.php and files/cases/document.php need after only
 * requiring config/bootstrap.php).
 */

require_once __DIR__ . '/core.php';

const LEX_CASE_FILES_SORT_WHITELIST = ['updated_at', 'date_created', 'full_name', 'case_file_title', 'status'];
const LEX_CASE_FILES_SORT_COLUMN_MAP = [
    'updated_at' => 'cf.updated_at',
    'date_created' => 'cf.created_at',
    'full_name' => 'cf.full_name',
    'case_file_title' => 'cf.case_file_title',
    'status' => 'cf.status',
];

if (!function_exists('lex_case_files_seed_from_cases')) {
    function lex_case_files_seed_from_cases(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $rows = $pdo->query(
            'SELECT c.id AS case_id, c.case_number, c.title, c.description, c.status,
                    cl_u.id AS client_user_id, cl_u.full_name AS client_name,
                    lw_u.id AS lawyer_user_id
             FROM cases c
             JOIN clients cl ON cl.id = c.client_id
             JOIN users cl_u ON cl_u.id = cl.user_id
             JOIN lawyers lw ON lw.id = c.lawyer_id
             JOIN users lw_u ON lw_u.id = lw.user_id
             WHERE NOT EXISTS (SELECT 1 FROM case_files cf WHERE cf.case_id = c.id)'
        )->fetchAll();

        if (!$rows) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO case_files (case_id, full_name, case_file_title, description, client_user_id, assigned_lawyer_user_id, created_by_user_id, folder_name, status)
             VALUES (:case_id, :full_name, :case_file_title, :description, :client_user_id, :assigned_lawyer_user_id, :created_by_user_id, :folder_name, :status)'
        );

        foreach ($rows as $row) {
            $status = in_array((string) $row['status'], ['open', 'ongoing', 'closed'], true) ? (string) $row['status'] : 'open';
            try {
                $insert->execute([
                    'case_id' => (int) $row['case_id'],
                    'full_name' => (string) $row['client_name'],
                    'case_file_title' => (string) ($row['title'] ?: $row['case_number']),
                    'description' => $row['description'],
                    'client_user_id' => (int) $row['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $row['lawyer_user_id'],
                    'created_by_user_id' => (int) $row['lawyer_user_id'],
                    'folder_name' => 'CF-' . str_pad((string) $row['case_id'], 6, '0', STR_PAD_LEFT),
                    'status' => $status,
                ]);
            } catch (Throwable $e) {
                // Skip rows that fail (e.g. a duplicate folder_name from a
                // prior partial run) rather than aborting the whole page.
            }
        }
    }
}

if (!function_exists('lex_case_files_ensure_vault_folder')) {
    function lex_case_files_ensure_vault_folder(PDO $pdo, int $caseFileId, string $name, ?int $createdByUserId = null): array
    {
        $slug = lex_case_file_vault_slug($name);
        $stmt = $pdo->prepare('SELECT * FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = :slug LIMIT 1');
        $stmt->execute(['case_file_id' => $caseFileId, 'slug' => $slug]);
        $folder = $stmt->fetch();
        if ($folder) {
            return $folder;
        }

        $pdo->prepare('INSERT INTO case_file_folders (case_file_id, slug, name, created_by_user_id) VALUES (:case_file_id, :slug, :name, :created_by)')
            ->execute(['case_file_id' => $caseFileId, 'slug' => $slug, 'name' => $name, 'created_by' => $createdByUserId]);

        $stmt->execute(['case_file_id' => $caseFileId, 'slug' => $slug]);
        return $stmt->fetch();
    }
}

if (!function_exists('lex_case_files_collect_request_filters')) {
    function lex_case_files_collect_request_filters(array $user): array
    {
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'open', 'ongoing', 'closed'], true)) {
            $status = 'all';
        }

        return [
            'q' => trim(lex_sanitize_text($_GET['q'] ?? '')),
            'status' => $status,
            'sort' => lex_safe_identifier((string) ($_GET['sort'] ?? 'updated_at'), LEX_CASE_FILES_SORT_WHITELIST, 'updated_at'),
            'dir' => lex_safe_direction((string) ($_GET['dir'] ?? 'desc'), 'DESC'),
            'page' => max(1, lex_sanitize_int($_GET['page'] ?? 1, 1)),
            'record' => lex_sanitize_int($_GET['record'] ?? 0),
            'role' => (string) $user['role'],
            'user_id' => (int) $user['id'],
        ];
    }
}

if (!function_exists('lex_case_files_fetch_state')) {
    function lex_case_files_fetch_state(array $filters, int $pageSize): array
    {
        $pdo = lex_pdo();
        if ($filters['role'] === 'lawyer' && function_exists('lex_data_sharing_table_ensure')) {
            lex_data_sharing_table_ensure();
        }
        $where = [];
        $params = [];

        if ($filters['role'] === 'lawyer') {
            $where[] = '(cf.assigned_lawyer_user_id = :uid1 OR cf.created_by_user_id = :uid2
                OR EXISTS (
                    SELECT 1 FROM case_file_shares s
                    WHERE s.case_file_id = cf.id
                      AND s.lawyer_user_id = :uid3
                      AND s.revoked_at IS NULL
                ))';
            $params['uid1'] = $filters['user_id'];
            $params['uid2'] = $filters['user_id'];
            $params['uid3'] = $filters['user_id'];
        } else {
            $where[] = 'cf.client_user_id = :uid1';
            $params['uid1'] = $filters['user_id'];
        }

        if ($filters['status'] !== 'all') {
            $where[] = 'cf.status = :status';
            $params['status'] = $filters['status'];
        }

        if ($filters['q'] !== '') {
            $where[] = '(cf.full_name LIKE :search_name OR cf.case_file_title LIKE :search_title)';
            $params['search_name'] = lex_like_value($filters['q']);
            $params['search_title'] = lex_like_value($filters['q']);
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sortColumn = LEX_CASE_FILES_SORT_COLUMN_MAP[$filters['sort']] ?? 'cf.updated_at';
        $direction = $filters['dir'] === 'ASC' ? 'ASC' : 'DESC';

        $total = lex_stats("SELECT COUNT(*) FROM case_files cf {$whereSql}", $params);
        $bounds = lex_bounded_page_params($filters['page'], $pageSize);
        $pageCount = max(1, (int) ceil($total / $bounds['perPage']));
        $page = min($bounds['page'], $pageCount);
        $offset = ($page - 1) * $bounds['perPage'];

        $stmt = $pdo->prepare(
            "SELECT cf.*, cu.full_name AS client_name, cu.avatar_stored_name AS client_avatar, lu.full_name AS lawyer_name
             FROM case_files cf
             JOIN users cu ON cu.id = cf.client_user_id
             JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
             {$whereSql}
             ORDER BY {$sortColumn} {$direction}
             LIMIT " . (int) $bounds['perPage'] . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        $records = $stmt->fetchAll() ?: [];

        $counts = [
            'total' => $total,
            'open' => lex_stats("SELECT COUNT(*) FROM case_files cf WHERE " . implode(' AND ', array_slice($where, 0, 1)) . " AND cf.status = 'open'", array_intersect_key($params, ['uid1' => 1, 'uid2' => 1, 'uid3' => 1])),
            'ongoing' => lex_stats("SELECT COUNT(*) FROM case_files cf WHERE " . implode(' AND ', array_slice($where, 0, 1)) . " AND cf.status = 'ongoing'", array_intersect_key($params, ['uid1' => 1, 'uid2' => 1, 'uid3' => 1])),
            'closed' => lex_stats("SELECT COUNT(*) FROM case_files cf WHERE " . implode(' AND ', array_slice($where, 0, 1)) . " AND cf.status = 'closed'", array_intersect_key($params, ['uid1' => 1, 'uid2' => 1, 'uid3' => 1])),
        ];

        $selectedId = $filters['record'] > 0 ? $filters['record'] : (int) ($records[0]['id'] ?? 0);
        $selected = null;
        foreach ($records as $record) {
            if ((int) $record['id'] === $selectedId) {
                $selected = $record;
                break;
            }
        }
        if (!$selected && $selectedId > 0) {
            $stmt = $pdo->prepare(
                'SELECT cf.*, cu.full_name AS client_name, cu.avatar_stored_name AS client_avatar, lu.full_name AS lawyer_name
                 FROM case_files cf
                 JOIN users cu ON cu.id = cf.client_user_id
                 JOIN users lu ON lu.id = cf.assigned_lawyer_user_id
                 WHERE cf.id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $selectedId]);
            $candidate = $stmt->fetch();
            if ($candidate && lex_case_file_vault_access([
                'id' => (int) $candidate['id'],
                'client_user_id' => (int) $candidate['client_user_id'],
                'assigned_lawyer_user_id' => (int) $candidate['assigned_lawyer_user_id'],
                'created_by_user_id' => (int) $candidate['created_by_user_id'],
            ], ['id' => $filters['user_id'], 'role' => $filters['role']]) !== 'none') {
                $selected = $candidate;
            }
        }

        return [
            'page' => $page,
            'page_count' => $pageCount,
            'per_page' => $bounds['perPage'],
            'search' => $filters['q'],
            'status' => $filters['status'],
            'sort' => $filters['sort'],
            'dir' => strtolower($direction),
            'records' => $records,
            'counts' => $counts,
            'selected' => $selected,
        ];
    }
}

if (!function_exists('lex_case_files_state_url')) {
    function lex_case_files_state_url(array $state, array $overrides = []): string
    {
        $params = array_merge([
            'q' => $state['search'],
            'status' => $state['status'],
            'sort' => $state['sort'],
            'dir' => $state['dir'],
            'page' => $state['page'],
        ], $overrides);
        $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
        return lex_app_url('case_files.php') . '?' . http_build_query($params);
    }
}

if (!function_exists('lex_case_files_render_summary')) {
    function lex_case_files_render_summary(array $state): string
    {
        ob_start();
        $counts = $state['counts'];
        ?>
        <section class="admin-dashboard-stats" aria-label="Case file summary">
          <article class="admin-dashboard-stat-card"><div class="admin-dashboard-stat-copy"><span>Total</span><strong><?= number_format((int) $counts['total']) ?></strong></div></article>
          <article class="admin-dashboard-stat-card"><div class="admin-dashboard-stat-copy"><span>Open</span><strong class="tone-success"><?= number_format((int) $counts['open']) ?></strong></div></article>
          <article class="admin-dashboard-stat-card"><div class="admin-dashboard-stat-copy"><span>Ongoing</span><strong><?= number_format((int) $counts['ongoing']) ?></strong></div></article>
          <article class="admin-dashboard-stat-card"><div class="admin-dashboard-stat-copy"><span>Closed</span><strong class="tone-danger"><?= number_format((int) $counts['closed']) ?></strong></div></article>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('lex_case_files_status_class')) {
    function lex_case_files_status_class(string $status): string
    {
        return match ($status) {
            'open' => 'is-open',
            'ongoing' => 'is-ongoing',
            'closed' => 'is-closed',
            default => 'is-open',
        };
    }
}

if (!function_exists('lex_case_files_render_list')) {
    function lex_case_files_render_list(array $state, array $filters): string
    {
        ob_start();
        ?>
        <?php foreach ($state['records'] as $record): ?>
          <?php
            $isSelected = $state['selected'] && (int) $state['selected']['id'] === (int) $record['id'];
            $listAccess = lex_case_file_vault_access([
                'id' => (int) $record['id'],
                'client_user_id' => (int) $record['client_user_id'],
                'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                'created_by_user_id' => (int) $record['created_by_user_id'],
            ], ['id' => (int) $filters['user_id'], 'role' => (string) $filters['role']]);
            $isShared = lex_case_file_is_view_only($listAccess);
          ?>
          <article class="card case-list-item<?= $isSelected ? ' is-active' : '' ?>" data-case-select data-case-id="<?= (int) $record['id'] ?>" role="button" tabindex="0">
            <div class="card-head">
              <div>
                <strong><?= lex_e((string) $record['full_name']) ?></strong>
                <p class="muted"><?= lex_e((string) $record['case_file_title']) ?><?= $isShared ? ' · Shared with you' : '' ?></p>
              </div>
              <span class="status-pill <?= $isShared ? 'is-ongoing' : lex_case_files_status_class((string) $record['status']) ?>"><?= $isShared ? 'View only' : lex_e(ucfirst((string) $record['status'])) ?></span>
            </div>
            <p class="muted">Lawyer: <?= lex_e((string) $record['lawyer_name']) ?> &middot; Updated <?= lex_e(lex_message_timestamp((string) $record['updated_at'])) ?></p>
          </article>
        <?php endforeach; ?>
        <?php if (!$state['records']): ?><p class="muted" style="padding:1rem;">No case files match your filters.</p><?php endif; ?>
        <div data-case-pagination-container><?= lex_admin_pagination('case_files.php', ['q' => $state['search'], 'status' => $state['status'], 'sort' => $state['sort'], 'dir' => $state['dir']], (int) $state['counts']['total'], (int) $state['page'], (int) $state['per_page']) ?></div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('lex_case_files_parse_attachments')) {
    function lex_case_files_parse_attachments(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('lex_case_files_render_detail')) {
    function lex_case_files_render_detail(array $state, array $filters, array $user): string
    {
        ob_start();
        $record = $state['selected'];
        $detailAccess = 'none';
        $detailViewOnly = false;
        if ($record) {
            $detailAccess = lex_case_file_vault_access([
                'id' => (int) $record['id'],
                'client_user_id' => (int) $record['client_user_id'],
                'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                'created_by_user_id' => (int) $record['created_by_user_id'],
            ], $user);
            $detailViewOnly = lex_case_file_is_view_only($detailAccess);
        }
        ?>
        <div class="modal-overlay" data-case-detail-modal aria-hidden="true">
          <div class="modal-card">
            <div class="modal-header">
              <h2><?= $record ? lex_e((string) $record['case_file_title']) : 'Case file' ?></h2>
              <button class="icon-button" type="button" data-case-detail-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
              <?php if (!$record): ?>
                <p class="muted">Select a case file from the list to see details.</p>
              <?php else: ?>
                <?php if ($detailViewOnly): ?>
                  <p class="case-file-view-banner">Shared with you. You can see every file. Download, copy, and screenshots are turned off.</p>
                <?php endif; ?>
                <dl class="admin-profile-details">
                  <div><dt>Client</dt><dd><?= lex_e((string) $record['full_name']) ?></dd></div>
                  <div><dt>Lawyer</dt><dd><?= lex_e((string) $record['lawyer_name']) ?></dd></div>
                  <div><dt>Status</dt><dd><span class="status-pill <?= lex_case_files_status_class((string) $record['status']) ?>"><?= lex_e(ucfirst((string) $record['status'])) ?></span></dd></div>
                  <div><dt>Description</dt><dd><?= nl2br(lex_e((string) ($record['description'] ?? 'No description provided.'))) ?></dd></div>
                </dl>
                <div class="inline-actions">
                  <?php if ($filters['role'] === 'lawyer' && !$detailViewOnly): ?>
                    <button class="button button-secondary" type="button" data-case-edit-open data-case-id="<?= (int) $record['id'] ?>" data-full-name="<?= lex_e((string) $record['full_name']) ?>" data-case-file-title="<?= lex_e((string) $record['case_file_title']) ?>" data-description="<?= lex_e((string) $record['description']) ?>" data-status="<?= lex_e((string) $record['status']) ?>">Edit</button>
                    <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/data_sharing.php?case_file_id=' . (int) $record['id'])) ?>">Share case files</a>
                  <?php endif; ?>
                  <button class="button button-accent" type="button" data-case-open-vault data-case-id="<?= (int) $record['id'] ?>">Open secure vault</button>
                </div>
                <h3>Attachments</h3>
                <?php $attachments = lex_case_files_parse_attachments((string) ($record['attachments_json'] ?? '[]')); ?>
                <ul class="admin-audit-list">
                  <?php foreach ($attachments as $attachment): ?>
                    <li class="admin-audit-row">
                      <span><?= lex_e((string) ($attachment['name'] ?? 'Attachment')) ?></span>
                      <?php if ($detailViewOnly): ?>
                        <a class="button button-secondary" href="<?= lex_e(lex_case_file_view_url(['case_file_id' => (int) $record['id'], 'stored_name' => (string) ($attachment['stored_name'] ?? '')])) ?>">View</a>
                      <?php else: ?>
                        <a class="button button-secondary" href="<?= lex_e(lex_app_url('case_file_attachment.php?case_file_id=' . (int) $record['id'] . '&stored_name=' . rawurlencode((string) ($attachment['stored_name'] ?? '')))) ?>">Download</a>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                  <?php if (!$attachments): ?><li class="admin-empty-line">No attachments uploaded yet.</li><?php endif; ?>
                </ul>
                <?php if (!$detailViewOnly): ?>
                <form method="post" enctype="multipart/form-data" class="stack-form">
                  <?= lex_csrf_field() ?>
                  <input type="hidden" name="action" value="upload_attachment">
                  <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
                  <label>Category
                    <select name="category">
                      <?php foreach (LEX_CASE_FILE_CATEGORIES as $category): ?>
                        <option value="<?= lex_e($category) ?>"><?= lex_e(ucwords(strtolower(str_replace('_', ' ', $category)))) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>File <input type="file" name="attachment" required></label>
                  <button class="button button-primary" type="submit">Upload attachment</button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('lex_case_files_render_vault_panel')) {
    function lex_case_files_render_vault_panel(array $state, array $filters, array $user): string
    {
        ob_start();
        $record = $state['selected'];
        if (!$record) {
            echo '<section class="card"><p class="muted">Select a case file to view its secure vault.</p></section>';
            return (string) ob_get_clean();
        }

        $caseFileArr = [
            'id' => (int) $record['id'],
            'client_user_id' => (int) $record['client_user_id'],
            'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
            'created_by_user_id' => (int) $record['created_by_user_id'],
        ];
        $access = lex_case_file_vault_access($caseFileArr, $user);
        $canManage = $access === 'manage';
        $viewOnly = lex_case_file_is_view_only($access);

        lex_case_file_vault_table_ensure();
        $pdo = lex_pdo();
        lex_case_files_ensure_vault_folder($pdo, (int) $record['id'], 'General', (int) $user['id']);
        $folderStmt = $pdo->prepare(
            'SELECT * FROM case_file_folders WHERE case_file_id = :id ORDER BY name ASC'
        );
        $folderStmt->execute(['id' => (int) $record['id']]);
        $folders = $folderStmt->fetchAll() ?: [];
        ?>
        <section class="card">
          <div class="card-head">
            <h2>Secure Vault - <?= lex_e((string) $record['case_file_title']) ?></h2>
            <span class="pill"><?= $viewOnly ? 'View only' : 'AES-256 encrypted' ?></span>
          </div>
          <?php if ($viewOnly): ?>
            <p class="case-file-view-banner">Every approved file in this case is visible. You cannot download, copy, or capture it.</p>
          <?php endif; ?>
          <?php foreach ($folders as $folder): ?>
            <?php
              $stmt = $pdo->prepare(
                  'SELECT d.*, u.full_name AS uploaded_by_name
                   FROM case_file_documents d
                   JOIN users u ON u.id = d.uploaded_by_user_id
                   WHERE d.folder_id = :folder_id
                   ORDER BY d.created_at DESC'
              );
              $stmt->execute(['folder_id' => (int) $folder['id']]);
              $documents = $stmt->fetchAll() ?: [];
              $visible = 0;
              foreach ($documents as $document) {
                  if ($access === 'manage' || (string) $document['upload_status'] === 'approved') {
                      $visible++;
                  }
              }
            ?>
          <details class="case-vault-folder-section" open>
            <summary><?= lex_e((string) $folder['name']) ?> (<?= $visible ?>)</summary>
            <ul class="admin-audit-list">
              <?php foreach ($documents as $document): ?>
                <?php
                  $canSee = $access === 'manage' || (string) $document['upload_status'] === 'approved';
                  if (!$canSee) { continue; }
                ?>
                <li class="admin-audit-row">
                  <span><?= lex_e((string) $document['original_name']) ?></span>
                  <span class="pill payment-status-pill payment-status-<?= lex_e((string) $document['upload_status']) ?>"><?= lex_e(ucfirst((string) $document['upload_status'])) ?></span>
                  <small class="muted">by <?= lex_e((string) $document['uploaded_by_name']) ?> &middot; <?= lex_e(lex_message_timestamp((string) $document['created_at'])) ?></small>
                  <div class="inline-actions">
                    <a class="button button-secondary" href="<?= lex_e(lex_case_file_view_url(['document_id' => (int) $document['id']])) ?>">View</a>
                    <?php if (!$viewOnly): ?>
                      <a class="button button-secondary" href="<?= lex_e(lex_app_url('case_document_file.php?document_id=' . (int) $document['id'])) ?>">Download</a>
                    <?php endif; ?>
                    <?php if ($canManage && (string) $document['upload_status'] === 'pending'): ?>
                      <form method="post" style="display:inline;">
                        <?= lex_csrf_field() ?>
                        <input type="hidden" name="action" value="vault_decision">
                        <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                        <input type="hidden" name="decision" value="approved">
                        <button class="button button-primary" type="submit">Approve</button>
                      </form>
                      <form method="post" style="display:inline;">
                        <?= lex_csrf_field() ?>
                        <input type="hidden" name="action" value="vault_decision">
                        <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                        <input type="hidden" name="decision" value="rejected">
                        <button class="button button-secondary" type="submit">Reject</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
              <?php if ($visible === 0): ?><li class="admin-empty-line">No documents in this folder yet.</li><?php endif; ?>
            </ul>
          </details>
          <?php endforeach; ?>
          <?php if ($access !== 'none' && !$viewOnly): ?>
            <form method="post" enctype="multipart/form-data" class="stack-form">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="vault_upload">
              <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
              <label>Upload an encrypted document <input type="file" name="document" required></label>
              <?php if (!$canManage): ?><p class="muted">Client uploads require your lawyer's approval before they appear as available.</p><?php endif; ?>
              <button class="button button-primary" type="submit">Upload to vault</button>
            </form>
          <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('lex_case_files_render_activity')) {
    function lex_case_files_render_activity(array $state): string
    {
        ob_start();
        $record = $state['selected'];
        $rows = [];
        if ($record) {
            $rows = lex_recent(
                "SELECT action, target_id, performed_at FROM audit_logs
                 WHERE target_table IN ('case_files','case_file_documents') AND target_id = :id
                 ORDER BY performed_at DESC LIMIT 10",
                ['id' => (string) $record['id']]
            );
        }
        ?>
        <section class="card">
          <div class="card-head"><h2>Activity</h2></div>
          <div class="admin-audit-list">
            <?php foreach ($rows as $row): ?>
              <div class="admin-audit-row">
                <span class="admin-audit-action"><?= lex_e(lex_activity_label((string) $row['action'])) ?></span>
                <time><?= lex_e(lex_message_timestamp((string) $row['performed_at'])) ?></time>
              </div>
            <?php endforeach; ?>
            <?php if (!$rows): ?><div class="admin-empty-line">No activity recorded yet.</div><?php endif; ?>
          </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('lex_case_files_render_editor')) {
    function lex_case_files_render_editor(array $state, array $filters, array $clients, array $lawyers, array $user): string
    {
        ob_start();
        if ($filters['role'] !== 'lawyer') {
            return '';
        }
        ?>
        <div class="modal-overlay" data-case-create-modal aria-hidden="true">
          <div class="modal-card">
            <div class="modal-header"><h2>New case file</h2><button class="icon-button" type="button" data-case-create-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-body stack-form" data-casefile-form data-persist-form="create">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="create">
              <div class="alert alert-error" data-form-errors hidden></div>
              <label>Full name <input type="text" name="full_name" data-casefile-fullname required></label>
              <label>Client
                <select name="client_user_id" data-casefile-client-select required>
                  <option value="">Select a client…</option>
                  <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= lex_e((string) $client['full_name']) ?> - Client</option><?php endforeach; ?>
                </select>
              </label>
              <label>Assigned lawyer
                <select name="assigned_lawyer_user_id" required>
                  <?php foreach ($lawyers as $lawyer): ?><option value="<?= (int) $lawyer['id'] ?>" <?= (int) $lawyer['id'] === (int) $user['id'] ? 'selected' : '' ?>><?= lex_e((string) $lawyer['full_name']) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label>Case file title <input type="text" name="case_file_title" required></label>
              <label class="full">Description <textarea name="description" rows="3"></textarea></label>
              <label>Status
                <select name="status"><option value="open">Open</option><option value="ongoing">Ongoing</option><option value="closed">Closed</option></select>
              </label>
              <button class="button button-primary" type="submit">Create case file</button>
            </form>
          </div>
        </div>
        <div class="modal-overlay" data-case-edit-modal aria-hidden="true">
          <div class="modal-card">
            <div class="modal-header"><h2>Edit case file</h2><button class="icon-button" type="button" data-case-edit-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-body stack-form" data-casefile-form>
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="case_id" data-case-edit-id>
              <div class="alert alert-error" data-form-errors hidden></div>
              <label>Full name <input type="text" name="full_name" data-case-edit-full-name required></label>
              <label>Case file title <input type="text" name="case_file_title" data-case-edit-case-title required></label>
              <label class="full">Description <textarea name="description" rows="3" data-case-edit-description></textarea></label>
              <label>Status
                <select name="status" data-case-edit-status><option value="open">Open</option><option value="ongoing">Ongoing</option><option value="closed">Closed</option></select>
              </label>
              <button class="button button-primary" type="submit">Save changes</button>
            </form>
          </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('lex_case_files_send_json')) {
    function lex_case_files_send_json(array $state, array $filters, array $clients, array $lawyers, array $user): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'summaryHtml' => lex_case_files_render_summary($state),
            'listHtml' => lex_case_files_render_list($state, $filters),
            'detailHtml' => lex_case_files_render_detail($state, $filters, $user),
            'vaultHtml' => lex_case_files_render_vault_panel($state, $filters, $user),
            'activityHtml' => lex_case_files_render_activity($state),
            'editorHtml' => lex_case_files_render_editor($state, $filters, $clients, $lawyers, $user),
            'paginationHtml' => '',
            'meta' => [
                'search' => $state['search'],
                'status' => $state['status'],
                'sort' => $state['sort'],
                'dir' => $state['dir'],
                'page' => $state['page'],
                'selectedId' => (int) ($state['selected']['id'] ?? 0),
                'total' => (int) $state['counts']['total'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$lexCaseFilesActions = __DIR__ . DIRECTORY_SEPARATOR . 'actions.php';
if (!function_exists('lex_case_files_handle_post') && is_file($lexCaseFilesActions)) {
    require_once $lexCaseFilesActions;
}
