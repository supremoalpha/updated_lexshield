<?php

declare(strict_types=1);

/**
 * POST handling for case_files.php (files/cases/index.php). All writes go
 * through prepared statements; every action re-checks the current user's
 * permission on the target row before touching it (never trusts a
 * `case_file_id` from the request alone).
 */

require_once __DIR__ . '/helpers.php';

if (!function_exists('lex_case_files_owns_or_manages')) {
    function lex_case_files_owns_or_manages(PDO $pdo, int $caseFileId, array $user): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $caseFileId]);
        $record = $stmt->fetch();
        if (!$record) {
            return null;
        }

        $access = lex_case_file_vault_access([
            'id' => (int) $record['id'],
            'client_user_id' => (int) $record['client_user_id'],
            'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
            'created_by_user_id' => (int) $record['created_by_user_id'],
        ], $user);

        return $access === 'manage' ? $record : null;
    }
}

if (!function_exists('lex_case_files_handle_post')) {
    /**
     * @return array{error:string, failed_action:string}
     */
    function lex_case_files_handle_post(PDO $pdo, array $user, array $filters, array $clients, array $lawyers): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['error' => '', 'failed_action' => ''];
        }

        $action = (string) ($_POST['action'] ?? '');

        if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
            lex_audit_csrf_failure('case_files.php:' . $action);
            return ['error' => 'Invalid CSRF token. Please try again.', 'failed_action' => $action];
        }

        $redirect = static function (array $overrides = []) use ($filters): never {
            $params = array_filter([
                'q' => $filters['q'],
                'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
                'sort' => $filters['sort'] !== 'updated_at' ? $filters['sort'] : null,
                'dir' => strtoupper($filters['dir']) !== 'DESC' ? $filters['dir'] : null,
                'page' => $filters['page'] > 1 ? $filters['page'] : null,
                'folder' => (($overrides['folder'] ?? $filters['folder'] ?? 0) > 0)
                    ? (int) ($overrides['folder'] ?? $filters['folder'])
                    : null,
            ] + $overrides, static fn ($v) => $v !== null && $v !== '');
            header('Location: ' . lex_app_url('case_files.php') . (($params) ? ('?' . http_build_query($params)) : ''));
            exit;
        };

        try {
            if ($action === 'create' && $filters['role'] === 'lawyer') {
                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $title = lex_sanitize_text($_POST['case_file_title'] ?? '');
                $clientUserId = lex_sanitize_int($_POST['client_user_id'] ?? 0);
                $lawyerUserId = lex_sanitize_int($_POST['assigned_lawyer_user_id'] ?? $user['id']);
                $status = lex_safe_identifier((string) ($_POST['status'] ?? 'open'), ['open', 'ongoing', 'closed'], 'open');
                $description = lex_sanitize_multiline_text($_POST['description'] ?? '');

                if ($fullName === '' || $title === '' || $clientUserId <= 0) {
                    return ['error' => 'Full name, client, and case file title are required.', 'failed_action' => 'create'];
                }

                $folderName = 'CF-' . bin2hex(random_bytes(6));
                $stmt = $pdo->prepare(
                    'INSERT INTO case_files (full_name, case_file_title, description, client_user_id, assigned_lawyer_user_id, created_by_user_id, folder_name, status)
                     VALUES (:full_name, :title, :description, :client_user_id, :lawyer_user_id, :created_by, :folder_name, :status)'
                );
                $stmt->execute([
                    'full_name' => $fullName,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'client_user_id' => $clientUserId,
                    'lawyer_user_id' => $lawyerUserId,
                    'created_by' => (int) $user['id'],
                    'folder_name' => $folderName,
                    'status' => $status,
                ]);
                $newId = (int) $pdo->lastInsertId();
                if (function_exists('lex_case_files_ensure_client_vault_tree')) {
                    lex_case_file_vault_table_ensure();
                    lex_case_files_ensure_client_vault_tree($pdo, [
                        'id' => $newId,
                        'full_name' => $fullName,
                        'client_user_id' => $clientUserId,
                    ], (int) $user['id']);
                } elseif (function_exists('lex_case_files_ensure_default_vault_folders')) {
                    lex_case_file_vault_table_ensure();
                    lex_case_files_ensure_default_vault_folders($pdo, $newId, (int) $user['id']);
                }
                lex_audit('create_case_file', 'case_files', (string) $newId);
                lex_flash_set('success', 'Case file created.');
                $redirect(['record' => $newId]);
            }

            if ($action === 'update') {
                $caseFileId = lex_sanitize_int($_POST['case_id'] ?? 0);
                $record = lex_case_files_owns_or_manages($pdo, $caseFileId, $user);
                if (!$record) {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'update'];
                }

                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
                $title = lex_sanitize_text($_POST['case_file_title'] ?? '');
                $status = lex_safe_identifier((string) ($_POST['status'] ?? 'open'), ['open', 'ongoing', 'closed'], (string) $record['status']);
                $description = lex_sanitize_multiline_text($_POST['description'] ?? '');

                if ($fullName === '' || $title === '') {
                    return ['error' => 'Full name and case file title are required.', 'failed_action' => 'update'];
                }

                $pdo->prepare('UPDATE case_files SET full_name = :full_name, case_file_title = :title, description = :description, status = :status WHERE id = :id')
                    ->execute([
                        'full_name' => $fullName,
                        'title' => $title,
                        'description' => $description !== '' ? $description : null,
                        'status' => $status,
                        'id' => $caseFileId,
                    ]);
                lex_audit('update_case_file', 'case_files', (string) $caseFileId);
                lex_flash_set('success', 'Case file updated.');
                $redirect(['record' => $caseFileId]);
            }

            if ($action === 'upload_attachment') {
                $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $caseFileId]);
                $record = $stmt->fetch();
                $access = $record ? lex_case_file_vault_access([
                    'id' => (int) $record['id'],
                    'client_user_id' => (int) $record['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $record['created_by_user_id'],
                ], $user) : 'none';

                if (!$record || $access === 'none') {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'upload_attachment'];
                }

                $category = lex_safe_identifier((string) ($_POST['category'] ?? 'DOCUMENTS'), LEX_CASE_FILE_CATEGORIES, 'DOCUMENTS');
                $file = $_FILES['attachment'] ?? [];
                if (empty($file['name'])) {
                    return ['error' => 'Choose a file to upload.', 'failed_action' => 'upload_attachment'];
                }
                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
                    return ['error' => 'The file failed to upload or is larger than 20 MB.', 'failed_action' => 'upload_attachment'];
                }

                try {
                    lex_virus_scan_upload($file);
                } catch (RuntimeException $e) {
                    return ['error' => $e->getMessage(), 'failed_action' => 'upload_attachment'];
                }

                $folderPath = lex_case_files_folder_path((string) $record['folder_name']) . DIRECTORY_SEPARATOR . $category;
                lex_storage_ensure_dir($folderPath);
                $storedName = bin2hex(random_bytes(16));
                $originalName = lex_sanitize_filename(basename((string) $file['name']));
                $mime = 'application/octet-stream';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $mime = (string) finfo_file($finfo, (string) $file['tmp_name']);
                        finfo_close($finfo);
                    }
                }
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $storedFileName = $storedName . ($extension !== '' ? ('.' . $extension) : '');

                if (!move_uploaded_file((string) $file['tmp_name'], $folderPath . DIRECTORY_SEPARATOR . $storedFileName)) {
                    return ['error' => 'Unable to save the file.', 'failed_action' => 'upload_attachment'];
                }

                $attachments = lex_case_files_parse_attachments((string) ($record['attachments_json'] ?? '[]'));
                $attachments[] = [
                    'name' => $originalName,
                    'stored_name' => $storedFileName,
                    'mime_type' => $mime,
                    'size' => (int) filesize($folderPath . DIRECTORY_SEPARATOR . $storedFileName),
                    'category' => $category,
                    'uploaded_at' => date('c'),
                    'uploaded_by' => (int) $user['id'],
                ];
                $pdo->prepare('UPDATE case_files SET attachments_json = :json WHERE id = :id')
                    ->execute(['json' => json_encode($attachments, JSON_UNESCAPED_SLASHES), 'id' => $caseFileId]);
                lex_audit('upload_case_file_attachment', 'case_files', (string) $caseFileId);
                lex_flash_set('success', 'Attachment uploaded.');
                $redirect(['record' => $caseFileId]);
            }

            if ($action === 'vault_upload') {
                $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $caseFileId]);
                $record = $stmt->fetch();
                $access = $record ? lex_case_file_vault_access([
                    'id' => (int) $record['id'],
                    'client_user_id' => (int) $record['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $record['created_by_user_id'],
                ], $user) : 'none';

                if (!$record || $access === 'none' || (function_exists('lex_case_file_is_view_only') && lex_case_file_is_view_only($access))) {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'vault_upload'];
                }

                $file = $_FILES['document'] ?? [];
                if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    return ['error' => 'Choose a picture, video, or document to upload.', 'failed_action' => 'vault_upload'];
                }
                $maxBytes = function_exists('lex_case_file_upload_max_bytes') ? lex_case_file_upload_max_bytes() : (80 * 1024 * 1024);
                if ((int) ($file['size'] ?? 0) > $maxBytes) {
                    return ['error' => 'Files must be smaller than 80 MB.', 'failed_action' => 'vault_upload'];
                }

                $mime = function_exists('lex_case_file_detect_upload_mime')
                    ? lex_case_file_detect_upload_mime($file)
                    : 'application/octet-stream';
                $originalName = lex_sanitize_filename(basename((string) $file['name']));
                if (function_exists('lex_case_file_is_allowed_upload') && !lex_case_file_is_allowed_upload($mime, $originalName)) {
                    return ['error' => 'Upload a picture (JPG, PNG, GIF, WEBP), a video (MP4, WEBM, MOV), or a document (PDF, TXT).', 'failed_action' => 'vault_upload'];
                }

                try {
                    lex_virus_scan_upload($file);
                } catch (RuntimeException $e) {
                    return ['error' => $e->getMessage(), 'failed_action' => 'vault_upload'];
                }

                lex_case_file_vault_table_ensure();
                $folder = null;
                $postedFolderId = lex_sanitize_int($_POST['folder_id'] ?? 0);
                $stayFolderId = lex_sanitize_int($_POST['folder'] ?? $filters['folder'] ?? 0);
                $clientFolder = function_exists('lex_case_files_ensure_client_vault_tree')
                    ? lex_case_files_ensure_client_vault_tree($pdo, $record, (int) $user['id'])
                    : null;
                if ($postedFolderId > 0) {
                    $folderStmt = $pdo->prepare('SELECT * FROM case_file_folders WHERE id = :id AND case_file_id = :case_file_id LIMIT 1');
                    $folderStmt->execute(['id' => $postedFolderId, 'case_file_id' => $caseFileId]);
                    $folder = $folderStmt->fetch() ?: null;
                }
                if (!$folder) {
                    $suggested = function_exists('lex_case_file_suggested_folder_name')
                        ? lex_case_file_suggested_folder_name($mime, $originalName)
                        : 'Documents';
                    $typeParentId = $clientFolder ? (int) $clientFolder['id'] : 0;
                    $folder = lex_case_files_ensure_vault_folder($pdo, $caseFileId, $suggested, (int) $user['id'], $typeParentId);
                }

                $plaintext = file_get_contents((string) $file['tmp_name']);
                if ($plaintext === false) {
                    return ['error' => 'Unable to read the uploaded file.', 'failed_action' => 'vault_upload'];
                }
                $encrypted = lex_case_file_document_encrypt($plaintext);

                $storedName = bin2hex(random_bytes(16)) . '.enc';
                $relativeDir = function_exists('lex_case_file_vault_relative_dir')
                    ? lex_case_file_vault_relative_dir($folder)
                    : lex_case_file_vault_slug((string) $folder['slug']);
                $folderPath = lex_case_files_folder_path((string) $record['folder_name']) . DIRECTORY_SEPARATOR . $relativeDir;
                lex_storage_ensure_dir($folderPath);
                file_put_contents($folderPath . DIRECTORY_SEPARATOR . $storedName, $encrypted['ciphertext'], LOCK_EX);

                $status = $access === 'manage' ? 'approved' : 'pending';
                $kindLabel = str_starts_with($mime, 'image/') ? 'Picture' : (str_starts_with($mime, 'video/') ? 'Video' : 'File');
                $pdo->prepare(
                    'INSERT INTO case_file_documents (case_file_id, folder_id, original_name, stored_name, mime_type, file_size, encryption_algorithm, encryption_iv, encryption_tag, upload_status, uploaded_by_user_id)
                     VALUES (:case_file_id, :folder_id, :original_name, :stored_name, :mime_type, :file_size, :algorithm, :iv, :tag, :status, :uploaded_by)'
                )->execute([
                    'case_file_id' => $caseFileId,
                    'folder_id' => (int) $folder['id'],
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'mime_type' => $mime,
                    'file_size' => strlen($plaintext),
                    'algorithm' => $encrypted['algorithm'],
                    'iv' => $encrypted['iv'],
                    'tag' => $encrypted['tag'],
                    'status' => $status,
                    'uploaded_by' => (int) $user['id'],
                ]);

                lex_audit('vault_upload_case_file_document', 'case_files', (string) $caseFileId);
                lex_flash_set('success', $status === 'approved' ? ($kindLabel . ' uploaded to the ' . (string) $folder['name'] . ' folder.') : ($kindLabel . ' uploaded and is pending your lawyer\'s approval.'));
                $redirect(['record' => $caseFileId, 'folder' => $stayFolderId > 0 ? $stayFolderId : (int) $folder['id']]);
            }

            if ($action === 'vault_folder_create') {
                $caseFileId = lex_sanitize_int($_POST['case_file_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM case_files WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $caseFileId]);
                $record = $stmt->fetch();
                $access = $record ? lex_case_file_vault_access([
                    'id' => (int) $record['id'],
                    'client_user_id' => (int) $record['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $record['created_by_user_id'],
                ], $user) : 'none';

                if (!$record || $access === 'none' || (function_exists('lex_case_file_is_view_only') && lex_case_file_is_view_only($access))) {
                    return ['error' => 'Case file not found or access denied.', 'failed_action' => 'vault_folder_create'];
                }

                $folderName = trim(lex_sanitize_text($_POST['folder_name'] ?? ''));
                if ($folderName === '' || strlen($folderName) > 80) {
                    return ['error' => 'Enter a folder name up to 80 characters.', 'failed_action' => 'vault_folder_create'];
                }
                $slug = lex_case_file_vault_slug($folderName);
                if ($slug === '') {
                    return ['error' => 'Enter a folder name that includes letters or numbers.', 'failed_action' => 'vault_folder_create'];
                }

                lex_case_file_vault_table_ensure();
                $clientFolder = function_exists('lex_case_files_ensure_client_vault_tree')
                    ? lex_case_files_ensure_client_vault_tree($pdo, $record, (int) $user['id'])
                    : null;
                $parentId = lex_sanitize_int($_POST['parent_folder_id'] ?? 0);
                $stayFolderId = lex_sanitize_int($_POST['folder'] ?? $filters['folder'] ?? 0);
                if ($parentId > 0) {
                    $parent = lex_case_files_get_vault_folder($pdo, $caseFileId, $parentId);
                    $parentId = $parent ? (int) $parent['id'] : ($clientFolder ? (int) $clientFolder['id'] : 0);
                } else {
                    $parentId = $clientFolder ? (int) $clientFolder['id'] : 0;
                }

                $exists = $pdo->prepare(
                    'SELECT id FROM case_file_folders
                     WHERE case_file_id = :case_file_id AND slug = :slug AND parent_id = :parent_id
                     LIMIT 1'
                );
                $exists->execute(['case_file_id' => $caseFileId, 'slug' => $slug, 'parent_id' => $parentId]);
                if ($exists->fetch()) {
                    return ['error' => 'That folder already exists.', 'failed_action' => 'vault_folder_create'];
                }

                $created = lex_case_files_ensure_vault_folder($pdo, $caseFileId, $folderName, (int) $user['id'], $parentId);
                lex_audit('create_case_file_vault_folder', 'case_files', (string) $caseFileId);
                lex_flash_set('success', 'Folder created.');
                $redirect(['record' => $caseFileId, 'folder' => $stayFolderId > 0 ? $stayFolderId : (int) ($created['parent_id'] ?? $parentId)]);
            }

            if ($action === 'vault_decision') {
                $documentId = lex_sanitize_int($_POST['document_id'] ?? 0);
                $decision = lex_safe_identifier((string) ($_POST['decision'] ?? ''), ['approved', 'rejected'], '');

                $stmt = $pdo->prepare(
                    'SELECT d.id, d.case_file_id, cf.client_user_id, cf.assigned_lawyer_user_id, cf.created_by_user_id
                     FROM case_file_documents d JOIN case_files cf ON cf.id = d.case_file_id
                     WHERE d.id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $documentId]);
                $document = $stmt->fetch();
                $access = $document ? lex_case_file_vault_access([
                    'id' => (int) $document['case_file_id'],
                    'client_user_id' => (int) $document['client_user_id'],
                    'assigned_lawyer_user_id' => (int) $document['assigned_lawyer_user_id'],
                    'created_by_user_id' => (int) $document['created_by_user_id'],
                ], $user) : 'none';

                if (!$document || $decision === '' || $access !== 'manage') {
                    return ['error' => 'Unable to update that document.', 'failed_action' => 'vault_decision'];
                }

                $pdo->prepare('UPDATE case_file_documents SET upload_status = :status, reviewed_by_user_id = :reviewer, reviewed_at = NOW() WHERE id = :id')
                    ->execute(['status' => $decision, 'reviewer' => (int) $user['id'], 'id' => $documentId]);
                lex_audit('vault_' . $decision . '_case_file_document', 'case_file_documents', (string) $documentId);
                lex_flash_set('success', 'Document ' . $decision . '.');
                $redirect([
                    'record' => (int) $document['case_file_id'],
                    'folder' => lex_sanitize_int($_POST['folder'] ?? $filters['folder'] ?? 0),
                ]);
            }
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage(), 'failed_action' => $action];
        }

        return ['error' => '', 'failed_action' => ''];
    }
}
