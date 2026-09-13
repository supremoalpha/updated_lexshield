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
                $newId = (int) $pdo->lastInsertId();
                if ($newId > 0 && function_exists('lex_case_files_ensure_client_vault_tree')) {
                    lex_case_file_vault_table_ensure();
                    lex_case_files_ensure_client_vault_tree($pdo, [
                        'id' => $newId,
                        'full_name' => (string) $row['client_name'],
                        'client_user_id' => (int) $row['client_user_id'],
                    ], (int) $row['lawyer_user_id']);
                }
            } catch (Throwable $e) {
                // Skip rows that fail (e.g. a duplicate folder_name from a
                // prior partial run) rather than aborting the whole page.
            }
        }
    }
}

if (!function_exists('lex_case_files_ensure_vault_folder')) {
    function lex_case_files_ensure_vault_folder(PDO $pdo, int $caseFileId, string $name, ?int $createdByUserId = null, int $parentId = 0): array
    {
        lex_case_file_vault_table_ensure();
        $slug = lex_case_file_vault_slug($name);
        $stmt = $pdo->prepare(
            'SELECT * FROM case_file_folders
             WHERE case_file_id = :case_file_id AND slug = :slug AND parent_id = :parent_id
             LIMIT 1'
        );
        $stmt->execute([
            'case_file_id' => $caseFileId,
            'slug' => $slug,
            'parent_id' => $parentId,
        ]);
        $folder = $stmt->fetch();
        if ($folder) {
            return $folder;
        }

        $pdo->prepare(
            'INSERT INTO case_file_folders (case_file_id, parent_id, slug, name, created_by_user_id)
             VALUES (:case_file_id, :parent_id, :slug, :name, :created_by)'
        )->execute([
            'case_file_id' => $caseFileId,
            'parent_id' => $parentId,
            'slug' => $slug,
            'name' => $name,
            'created_by' => $createdByUserId,
        ]);

        $stmt->execute([
            'case_file_id' => $caseFileId,
            'slug' => $slug,
            'parent_id' => $parentId,
        ]);
        return $stmt->fetch();
    }
}

if (!function_exists('lex_case_file_default_vault_folder_names')) {
    function lex_case_file_default_vault_folder_names(): array
    {
        return ['Documents', 'Pictures', 'Videos'];
    }
}

if (!function_exists('lex_case_file_suggested_folder_name')) {
    function lex_case_file_suggested_folder_name(string $mime, string $name = ''): string
    {
        if (function_exists('lex_case_file_guess_mime')) {
            $mime = lex_case_file_guess_mime($mime, $name);
        }
        if (str_starts_with($mime, 'image/')) {
            return 'Pictures';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'Videos';
        }

        return 'Documents';
    }
}

if (!function_exists('lex_case_file_is_default_vault_type_slug')) {
    function lex_case_file_is_default_vault_type_slug(string $slug): bool
    {
        return in_array($slug, ['documents', 'pictures', 'videos'], true);
    }
}

if (!function_exists('lex_case_file_client_folder_name')) {
    function lex_case_file_client_folder_name(array $record): string
    {
        $name = trim((string) ($record['full_name'] ?? $record['client_name'] ?? ''));
        if ($name === '') {
            $name = 'Client';
        }
        if (lex_case_file_is_default_vault_type_slug(lex_case_file_vault_slug($name))) {
            $name .= ' folder';
        }

        return $name;
    }
}

if (!function_exists('lex_case_files_sort_vault_folders')) {
    function lex_case_files_sort_vault_folders(array $folders): array
    {
        $rank = ['documents' => 0, 'pictures' => 1, 'videos' => 2, 'general' => 90];
        usort($folders, static function (array $a, array $b) use ($rank): int {
            $as = $rank[(string) ($a['slug'] ?? '')] ?? 10;
            $bs = $rank[(string) ($b['slug'] ?? '')] ?? 10;
            if ($as !== $bs) {
                return $as <=> $bs;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $folders;
    }
}

if (!function_exists('lex_case_files_list_vault_folders')) {
    function lex_case_files_list_vault_folders(PDO $pdo, int $caseFileId, int $parentId = 0): array
    {
        lex_case_file_vault_table_ensure();
        $stmt = $pdo->prepare(
            'SELECT * FROM case_file_folders
             WHERE case_file_id = :case_file_id AND parent_id = :parent_id
             ORDER BY name ASC'
        );
        $stmt->execute(['case_file_id' => $caseFileId, 'parent_id' => $parentId]);

        return lex_case_files_sort_vault_folders($stmt->fetchAll() ?: []);
    }
}

if (!function_exists('lex_case_files_get_vault_folder')) {
    function lex_case_files_get_vault_folder(PDO $pdo, int $caseFileId, int $folderId): ?array
    {
        if ($folderId <= 0) {
            return null;
        }

        lex_case_file_vault_table_ensure();
        $stmt = $pdo->prepare(
            'SELECT * FROM case_file_folders WHERE id = :id AND case_file_id = :case_file_id LIMIT 1'
        );
        $stmt->execute(['id' => $folderId, 'case_file_id' => $caseFileId]);
        $folder = $stmt->fetch();

        return $folder ?: null;
    }
}

if (!function_exists('lex_case_files_vault_folder_ancestors')) {
    function lex_case_files_vault_folder_ancestors(PDO $pdo, int $caseFileId, int $folderId): array
    {
        $trail = [];
        $guard = 0;
        $currentId = $folderId;
        while ($currentId > 0 && $guard < 20) {
            $folder = lex_case_files_get_vault_folder($pdo, $caseFileId, $currentId);
            if (!$folder) {
                break;
            }
            array_unshift($trail, $folder);
            $currentId = (int) ($folder['parent_id'] ?? 0);
            $guard++;
        }

        return $trail;
    }
}

if (!function_exists('lex_case_files_ensure_client_vault_tree')) {
    /**
     * Vault root is the client folder. Documents, Pictures, and Videos live inside it.
     *
     * @return array<string,mixed> The client folder row
     */
    function lex_case_files_ensure_client_vault_tree(PDO $pdo, array $record, ?int $createdByUserId = null): array
    {
        lex_case_file_vault_table_ensure();
        $caseFileId = (int) ($record['id'] ?? 0);
        $clientName = lex_case_file_client_folder_name($record);
        $clientSlug = lex_case_file_vault_slug($clientName);

        $rootFolders = lex_case_files_list_vault_folders($pdo, $caseFileId, 0);
        $clientFolder = null;
        foreach ($rootFolders as $folder) {
            $slug = (string) ($folder['slug'] ?? '');
            if ($slug === $clientSlug && !lex_case_file_is_default_vault_type_slug($slug)) {
                $clientFolder = $folder;
                break;
            }
        }
        if (!$clientFolder) {
            foreach ($rootFolders as $folder) {
                if (!lex_case_file_is_default_vault_type_slug((string) ($folder['slug'] ?? ''))) {
                    $clientFolder = $folder;
                    break;
                }
            }
        }
        if (!$clientFolder) {
            $clientFolder = lex_case_files_ensure_vault_folder($pdo, $caseFileId, $clientName, $createdByUserId, 0);
        }

        $clientId = (int) $clientFolder['id'];
        $move = $pdo->prepare(
            'UPDATE case_file_folders SET parent_id = :parent_id
             WHERE id = :id AND case_file_id = :case_file_id AND parent_id = 0'
        );
        foreach ($rootFolders as $folder) {
            $folderId = (int) ($folder['id'] ?? 0);
            if ($folderId === $clientId) {
                continue;
            }
            try {
                $move->execute([
                    'parent_id' => $clientId,
                    'id' => $folderId,
                    'case_file_id' => $caseFileId,
                ]);
            } catch (Throwable $e) {
                // Keep going if a slug already exists under the client folder.
            }
        }

        foreach (lex_case_file_default_vault_folder_names() as $name) {
            lex_case_files_ensure_vault_folder($pdo, $caseFileId, $name, $createdByUserId, $clientId);
        }

        $fresh = lex_case_files_get_vault_folder($pdo, $caseFileId, $clientId);

        return $fresh ?: $clientFolder;
    }
}

if (!function_exists('lex_case_files_ensure_for_case')) {
    function lex_case_files_ensure_for_case(PDO $pdo, int $caseId, ?int $createdByUserId = null): ?array
    {
        if ($caseId <= 0) {
            return null;
        }

        lex_case_files_table_ensure();
        lex_case_file_vault_table_ensure();

        $stmt = $pdo->prepare('SELECT * FROM case_files WHERE case_id = :case_id LIMIT 1');
        $stmt->execute(['case_id' => $caseId]);
        $record = $stmt->fetch() ?: null;

        if (!$record) {
            $caseStmt = $pdo->prepare(
                'SELECT c.id AS case_id, c.case_number, c.title, c.description, c.status,
                        cl_u.id AS client_user_id, cl_u.full_name AS client_name,
                        lw_u.id AS lawyer_user_id
                 FROM cases c
                 JOIN clients cl ON cl.id = c.client_id
                 JOIN users cl_u ON cl_u.id = cl.user_id
                 JOIN lawyers lw ON lw.id = c.lawyer_id
                 JOIN users lw_u ON lw_u.id = lw.user_id
                 WHERE c.id = :case_id
                 LIMIT 1'
            );
            $caseStmt->execute(['case_id' => $caseId]);
            $row = $caseStmt->fetch();
            if (!$row) {
                return null;
            }

            $status = in_array((string) $row['status'], ['open', 'ongoing', 'closed'], true)
                ? (string) $row['status']
                : 'open';
            $folderName = 'CF-' . str_pad((string) $row['case_id'], 6, '0', STR_PAD_LEFT);
            try {
                $pdo->prepare(
                    'INSERT INTO case_files (case_id, full_name, case_file_title, description, client_user_id, assigned_lawyer_user_id, created_by_user_id, folder_name, status)
                     VALUES (:case_id, :full_name, :case_file_title, :description, :client_user_id, :assigned_lawyer_user_id, :created_by_user_id, :folder_name, :status)'
                )->execute([
                    'case_id' => (int) $row['case_id'],
                    'full_name' => (string) $row['client_name'],
                    'case_file_title' => (string) ($row['title'] ?: $row['case_number']),
                    'description' => $row['description'],
                    'client_user_id' => (int) $row['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $row['lawyer_user_id'],
                    'created_by_user_id' => $createdByUserId ?: (int) $row['lawyer_user_id'],
                    'folder_name' => $folderName,
                    'status' => $status,
                ]);
                $stmt->execute(['case_id' => $caseId]);
                $record = $stmt->fetch() ?: null;
            } catch (Throwable $e) {
                $stmt->execute(['case_id' => $caseId]);
                $record = $stmt->fetch() ?: null;
                if (!$record) {
                    $fallback = $pdo->prepare(
                        'SELECT * FROM case_files WHERE client_user_id = :client AND assigned_lawyer_user_id = :lawyer ORDER BY id DESC LIMIT 1'
                    );
                    $fallback->execute([
                        'client' => (int) $row['client_user_id'],
                        'lawyer' => (int) $row['lawyer_user_id'],
                    ]);
                    $record = $fallback->fetch() ?: null;
                }
            }
        }

        if (!$record) {
            return null;
        }

        lex_case_files_ensure_client_vault_tree($pdo, $record, $createdByUserId ?? (int) ($record['created_by_user_id'] ?? 0));

        return $record;
    }
}

if (!function_exists('lex_case_files_ensure_default_vault_folders')) {
    function lex_case_files_ensure_default_vault_folders(PDO $pdo, int $caseFileId, ?int $createdByUserId = null): array
    {
        $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $caseFileId]);
        $record = $stmt->fetch();
        if ($record) {
            $clientFolder = lex_case_files_ensure_client_vault_tree($pdo, $record, $createdByUserId);

            return lex_case_files_list_vault_folders($pdo, $caseFileId, (int) $clientFolder['id']);
        }

        foreach (lex_case_file_default_vault_folder_names() as $name) {
            lex_case_files_ensure_vault_folder($pdo, $caseFileId, $name, $createdByUserId, 0);
        }

        return lex_case_files_list_vault_folders($pdo, $caseFileId, 0);
    }
}

if (!function_exists('lex_case_files_list_vault_folder_options')) {
    /**
     * Flattened folder picker labels, e.g. "Juan Client / Documents".
     *
     * @return list<array{id:int,name:string}>
     */
    function lex_case_files_list_vault_folder_options(PDO $pdo, int $caseFileId): array
    {
        lex_case_file_vault_table_ensure();
        $stmt = $pdo->prepare('SELECT * FROM case_file_folders WHERE case_file_id = :case_file_id ORDER BY name ASC');
        $stmt->execute(['case_file_id' => $caseFileId]);
        $all = $stmt->fetchAll() ?: [];
        $byParent = [];
        foreach ($all as $folder) {
            $byParent[(int) ($folder['parent_id'] ?? 0)][] = $folder;
        }
        foreach ($byParent as $parentId => $folders) {
            $byParent[$parentId] = lex_case_files_sort_vault_folders($folders);
        }

        $options = [];
        $walk = static function (int $parentId, string $prefix) use (&$walk, &$options, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $folder) {
                $label = $prefix !== ''
                    ? $prefix . ' / ' . (string) $folder['name']
                    : (string) $folder['name'];
                $options[] = ['id' => (int) $folder['id'], 'name' => $label];
                $walk((int) $folder['id'], $label);
            }
        };
        $walk(0, '');

        return $options;
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
            'folder' => lex_sanitize_int($_GET['folder'] ?? 0),
            'role' => (string) $user['role'],
            'user_id' => (int) $user['id'],
        ];
    }
}

if (!function_exists('lex_case_files_fetch_state')) {
    function lex_case_files_fetch_state(array $filters, int $pageSize): array
    {
        $pdo = lex_pdo();
        $where = [];
        $params = [];

        if ($filters['role'] === 'lawyer') {
            $where[] = '(cf.assigned_lawyer_user_id = :uid1 OR cf.created_by_user_id = :uid2)';
            $params['uid1'] = $filters['user_id'];
            $params['uid2'] = $filters['user_id'];
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
            'open' => lex_stats("SELECT COUNT(*) FROM case_files cf WHERE " . implode(' AND ', array_slice($where, 0, 1)) . " AND cf.status = 'open'", array_intersect_key($params, ['uid1' => 1, 'uid2' => 1])),
            'ongoing' => lex_stats("SELECT COUNT(*) FROM case_files cf WHERE " . implode(' AND ', array_slice($where, 0, 1)) . " AND cf.status = 'ongoing'", array_intersect_key($params, ['uid1' => 1, 'uid2' => 1])),
            'closed' => lex_stats("SELECT COUNT(*) FROM case_files cf WHERE " . implode(' AND ', array_slice($where, 0, 1)) . " AND cf.status = 'closed'", array_intersect_key($params, ['uid1' => 1, 'uid2' => 1])),
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
            'folder' => (int) ($filters['folder'] ?? 0),
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
            'folder' => (int) ($state['folder'] ?? 0),
        ], $overrides);
        if ((int) ($params['folder'] ?? 0) <= 0) {
            unset($params['folder']);
        }
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
          <?php $isSelected = $state['selected'] && (int) $state['selected']['id'] === (int) $record['id']; ?>
          <article class="card case-list-item<?= $isSelected ? ' is-active' : '' ?>" data-case-select data-case-id="<?= (int) $record['id'] ?>" role="button" tabindex="0">
            <div class="card-head">
              <div>
                <strong><?= lex_e((string) $record['full_name']) ?></strong>
                <p class="muted"><?= lex_e((string) $record['case_file_title']) ?></p>
              </div>
              <span class="status-pill <?= lex_case_files_status_class((string) $record['status']) ?>"><?= lex_e(ucfirst((string) $record['status'])) ?></span>
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
                <dl class="admin-profile-details">
                  <div><dt>Client</dt><dd><?= lex_e((string) $record['full_name']) ?></dd></div>
                  <div><dt>Lawyer</dt><dd><?= lex_e((string) $record['lawyer_name']) ?></dd></div>
                  <div><dt>Status</dt><dd><span class="status-pill <?= lex_case_files_status_class((string) $record['status']) ?>"><?= lex_e(ucfirst((string) $record['status'])) ?></span></dd></div>
                  <div><dt>Description</dt><dd><?= nl2br(lex_e((string) ($record['description'] ?? 'No description provided.'))) ?></dd></div>
                </dl>
                <div class="inline-actions">
                  <?php if ($filters['role'] === 'lawyer'): ?>
                    <button class="button button-secondary" type="button" data-case-edit-open data-case-id="<?= (int) $record['id'] ?>" data-full-name="<?= lex_e((string) $record['full_name']) ?>" data-case-file-title="<?= lex_e((string) $record['case_file_title']) ?>" data-description="<?= lex_e((string) $record['description']) ?>" data-status="<?= lex_e((string) $record['status']) ?>">Edit</button>
                    <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/data_sharing.php?case_file_id=' . (int) $record['id'])) ?>">Share with another lawyer</a>
                  <?php endif; ?>
                  <button class="button button-accent" type="button" data-case-open-vault data-case-id="<?= (int) $record['id'] ?>">Open secure vault</button>
                </div>
                <h3>Attachments</h3>
                <?php $attachments = lex_case_files_parse_attachments((string) ($record['attachments_json'] ?? '[]')); ?>
                <ul class="admin-audit-list">
                  <?php foreach ($attachments as $attachment): ?>
                    <li class="admin-audit-row">
                      <span><?= lex_e((string) ($attachment['name'] ?? 'Attachment')) ?></span>
                      <a class="button button-secondary" href="<?= lex_e(lex_app_url('case_file_attachment.php?case_file_id=' . (int) $record['id'] . '&stored_name=' . rawurlencode((string) ($attachment['stored_name'] ?? '')))) ?>">Download</a>
                    </li>
                  <?php endforeach; ?>
                  <?php if (!$attachments): ?><li class="admin-empty-line">No attachments uploaded yet.</li><?php endif; ?>
                </ul>
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

        lex_case_file_vault_table_ensure();
        $pdo = lex_pdo();
        $clientFolder = lex_case_files_ensure_client_vault_tree($pdo, $record, (int) $user['id']);
        $openFolderId = (int) ($state['folder'] ?? $filters['folder'] ?? 0);
        $currentFolder = $openFolderId > 0
            ? lex_case_files_get_vault_folder($pdo, (int) $record['id'], $openFolderId)
            : null;
        if (!$currentFolder) {
            $openFolderId = 0;
        }
        $viewOnly = function_exists('lex_case_file_is_view_only') && lex_case_file_is_view_only($access);
        $canUpload = $access !== 'none' && !$viewOnly;
        $childFolders = lex_case_files_list_vault_folders($pdo, (int) $record['id'], $openFolderId);
        $folderOptions = lex_case_files_list_vault_folder_options($pdo, (int) $record['id']);
        $ancestors = $currentFolder
            ? lex_case_files_vault_folder_ancestors($pdo, (int) $record['id'], (int) $currentFolder['id'])
            : [];

        $docStmt = $pdo->prepare(
            'SELECT d.*, u.full_name AS uploaded_by_name
             FROM case_file_documents d
             JOIN users u ON u.id = d.uploaded_by_user_id
             WHERE d.case_file_id = :case_file_id AND d.folder_id = :folder_id
             ORDER BY d.created_at DESC'
        );
        $documents = [];
        if ($currentFolder) {
            $docStmt->execute(['case_file_id' => (int) $record['id'], 'folder_id' => (int) $currentFolder['id']]);
            $documents = $docStmt->fetchAll() ?: [];
        }

        $childCounts = [];
        if ($childFolders) {
            $ids = array_map(static fn (array $folder): int => (int) $folder['id'], $childFolders);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $countStmt = $pdo->prepare(
                "SELECT folder_id, COUNT(*) AS total FROM case_file_documents
                 WHERE case_file_id = ? AND folder_id IN ({$placeholders})
                 GROUP BY folder_id"
            );
            $countStmt->execute(array_merge([(int) $record['id']], $ids));
            foreach ($countStmt->fetchAll() ?: [] as $row) {
                $childCounts[(int) $row['folder_id']] = (int) $row['total'];
            }
            $subStmt = $pdo->prepare(
                "SELECT parent_id, COUNT(*) AS total FROM case_file_folders
                 WHERE case_file_id = ? AND parent_id IN ({$placeholders})
                 GROUP BY parent_id"
            );
            $subStmt->execute(array_merge([(int) $record['id']], $ids));
            $childFolderCounts = [];
            foreach ($subStmt->fetchAll() ?: [] as $row) {
                $childFolderCounts[(int) $row['parent_id']] = (int) $row['total'];
            }
        } else {
            $childFolderCounts = [];
        }

        $vaultUrl = static function (int $folderId = 0) use ($state, $record): string {
            return lex_case_files_state_url($state, [
                'record' => (int) $record['id'],
                'folder' => $folderId,
            ]);
        };
        $createParentId = $currentFolder ? (int) $currentFolder['id'] : (int) $clientFolder['id'];
        $currentTitle = $currentFolder ? (string) $currentFolder['name'] : 'Client folders';
        ?>
        <section class="card case-vault-panel">
          <div class="card-head">
            <h2>Secure Vault - <?= lex_e((string) $record['case_file_title']) ?></h2>
            <span class="pill">AES-256 encrypted</span>
          </div>
          <p class="muted">When a client books an appointment, a folder for that client is already here. Open it to use Documents, Pictures, Videos, or folders you add inside.</p>
          <nav class="case-vault-crumbs" aria-label="Vault folders">
            <a href="<?= lex_e($vaultUrl(0)) ?>" data-vault-folder="0">Vault</a>
            <?php foreach ($ancestors as $crumb): ?>
              <span aria-hidden="true">/</span>
              <?php if ((int) $crumb['id'] === $openFolderId): ?>
                <span><?= lex_e((string) $crumb['name']) ?></span>
              <?php else: ?>
                <a href="<?= lex_e($vaultUrl((int) $crumb['id'])) ?>" data-vault-folder="<?= (int) $crumb['id'] ?>"><?= lex_e((string) $crumb['name']) ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </nav>
          <?php if ($childFolders): ?>
            <div class="case-vault-folder-grid">
              <?php foreach ($childFolders as $folder): ?>
                <?php
                  $fileCount = $childCounts[(int) $folder['id']] ?? 0;
                  $folderCount = $childFolderCounts[(int) $folder['id']] ?? 0;
                  $meta = [];
                  if ($folderCount > 0) {
                      $meta[] = $folderCount === 1 ? '1 folder' : ($folderCount . ' folders');
                  }
                  if ($fileCount > 0) {
                      $meta[] = $fileCount === 1 ? '1 file' : ($fileCount . ' files');
                  }
                  if (!$meta) {
                      $meta[] = 'Empty';
                  }
                ?>
                <a class="case-vault-folder-card" href="<?= lex_e($vaultUrl((int) $folder['id'])) ?>" data-vault-folder="<?= (int) $folder['id'] ?>">
                  <span class="case-vault-folder-icon" aria-hidden="true"></span>
                  <strong><?= lex_e((string) $folder['name']) ?></strong>
                  <small class="muted"><?= lex_e(implode(' · ', $meta)) ?></small>
                </a>
              <?php endforeach; ?>
            </div>
          <?php elseif (!$currentFolder): ?>
            <p class="muted">No client folder is available yet.</p>
          <?php endif; ?>
          <?php if ($currentFolder): ?>
            <h3 class="case-vault-current-name"><?= lex_e($currentTitle) ?></h3>
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
                    <?php
                      $docMime = function_exists('lex_case_file_guess_mime')
                          ? lex_case_file_guess_mime((string) ($document['mime_type'] ?? ''), (string) $document['original_name'])
                          : (string) ($document['mime_type'] ?? '');
                      $canPreview = function_exists('lex_case_file_previewable_mime') && lex_case_file_previewable_mime($docMime, (string) $document['original_name']);
                    ?>
                    <?php if ($canPreview && function_exists('lex_case_file_view_url')): ?>
                      <a class="button button-secondary" href="<?= lex_e(lex_case_file_view_url(['document_id' => (int) $document['id']])) ?>">View</a>
                    <?php endif; ?>
                    <?php if (!$viewOnly): ?>
                      <a class="button button-secondary" href="<?= lex_e(lex_app_url('case_document_file.php?document_id=' . (int) $document['id'])) ?>">Download</a>
                    <?php endif; ?>
                    <?php if ($canManage && (string) $document['upload_status'] === 'pending'): ?>
                      <form method="post" style="display:inline;">
                        <?= lex_csrf_field() ?>
                        <input type="hidden" name="action" value="vault_decision">
                        <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                        <input type="hidden" name="decision" value="approved">
                        <input type="hidden" name="folder" value="<?= (int) $openFolderId ?>">
                        <button class="button button-primary" type="submit">Approve</button>
                      </form>
                      <form method="post" style="display:inline;">
                        <?= lex_csrf_field() ?>
                        <input type="hidden" name="action" value="vault_decision">
                        <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                        <input type="hidden" name="decision" value="rejected">
                        <input type="hidden" name="folder" value="<?= (int) $openFolderId ?>">
                        <button class="button button-secondary" type="submit">Reject</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
              <?php if (!$documents && !$childFolders): ?><li class="admin-empty-line">This folder is empty.</li><?php endif; ?>
            </ul>
          <?php endif; ?>
          <?php if ($canUpload): ?>
            <form method="post" class="stack-form case-vault-folder-form" style="margin-top:1rem;">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="vault_folder_create">
              <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
              <input type="hidden" name="parent_folder_id" value="<?= (int) $createParentId ?>">
              <input type="hidden" name="folder" value="<?= (int) $openFolderId ?>">
              <label>New folder inside <?= lex_e($currentFolder ? (string) $currentFolder['name'] : (string) $clientFolder['name']) ?>
                <input type="text" name="folder_name" maxlength="80" placeholder="Example: Court filings" required>
              </label>
              <button class="button button-secondary" type="submit">Create folder</button>
            </form>
            <form method="post" enctype="multipart/form-data" class="stack-form">
              <?= lex_csrf_field() ?>
              <input type="hidden" name="action" value="vault_upload">
              <input type="hidden" name="case_file_id" value="<?= (int) $record['id'] ?>">
              <input type="hidden" name="folder" value="<?= (int) $openFolderId ?>">
              <label>Folder
                <select name="folder_id">
                  <option value="0">Auto (Documents, Pictures, or Videos)</option>
                  <?php foreach ($folderOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= $currentFolder && (int) $option['id'] === (int) $currentFolder['id'] ? ' selected' : '' ?>><?= lex_e((string) $option['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Upload a picture, video, or document
                <input type="file" name="document" accept="<?= lex_e(function_exists('lex_case_file_upload_accept') ? lex_case_file_upload_accept() : 'image/*,video/*,.pdf,.txt') ?>" required>
              </label>
              <p class="muted">JPG, PNG, GIF, WEBP pictures and MP4, WEBM, or MOV videos, plus PDF or text documents, up to 80 MB. Auto puts pictures in Pictures, videos in Videos, and other files in Documents inside the client folder.</p>
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
                'folder' => (int) ($state['folder'] ?? $filters['folder'] ?? 0),
                'total' => (int) $state['counts']['total'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
