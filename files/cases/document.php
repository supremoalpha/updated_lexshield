<?php
require_once __DIR__ . '/../../config/bootstrap.php';

$user = lex_require_login();
lex_case_file_vault_table_ensure();

$documentId = lex_sanitize_int($_GET['document_id'] ?? 0);
$preview = (string) ($_GET['preview'] ?? '') === '1';

if ($documentId <= 0) {
    http_response_code(404);
    exit('Document not found.');
}

$stmt = lex_pdo()->prepare(
    'SELECT d.*, f.slug AS folder_slug, f.name AS folder_name,
            cf.id AS case_file_id, cf.folder_name AS case_folder_name, cf.client_user_id,
            cf.assigned_lawyer_user_id, cf.created_by_user_id
     FROM case_file_documents d
     JOIN case_file_folders f ON f.id = d.folder_id
     JOIN case_files cf ON cf.id = d.case_file_id
     WHERE d.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $documentId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$caseFile = [
    'id' => (int) $document['case_file_id'],
    'folder_name' => (string) $document['case_folder_name'],
    'client_user_id' => (int) $document['client_user_id'],
    'assigned_lawyer_user_id' => (int) $document['assigned_lawyer_user_id'],
    'created_by_user_id' => (int) $document['created_by_user_id'],
];
$access = lex_case_file_vault_access($caseFile, $user);
$viewOnly = function_exists('lex_case_file_is_view_only') && lex_case_file_is_view_only($access);
if ($access === 'none' || ($access === 'client' && (string) $document['upload_status'] !== 'approved')) {
    lex_audit('denied_case_file_document_access', 'case_file_documents', (string) $documentId);
    http_response_code(403);
    exit('Access denied.');
}

if ((string) $document['upload_status'] !== 'approved' && $access !== 'manage') {
    lex_audit('denied_case_file_document_access', 'case_file_documents', (string) $documentId);
    http_response_code(403);
    exit('Access denied.');
}

if ($viewOnly && !$preview) {
    lex_audit('denied_case_file_document_download', 'case_file_documents', (string) $documentId);
    http_response_code(403);
    exit('This shared case file is view-only. Download is disabled.');
}

if ($viewOnly && !lex_case_file_view_token_ok((string) ($_GET['token'] ?? ''), (int) $user['id'])) {
    lex_audit('denied_case_file_document_preview', 'case_file_documents', (string) $documentId);
    http_response_code(403);
    exit('Open this file from Case Files to view it.');
}

$path = lex_case_files_folder_path((string) $document['case_folder_name'])
    . DIRECTORY_SEPARATOR
    . lex_case_file_vault_slug((string) $document['folder_slug'])
    . DIRECTORY_SEPARATOR
    . basename((string) $document['stored_name']);

if (!is_file($path)) {
    lex_audit('missing_case_file_document', 'case_file_documents', (string) $documentId);
    http_response_code(404);
    exit('Document file missing.');
}

$mime = (string) ($document['mime_type'] ?: 'application/octet-stream');
$name = trim((string) ($document['original_name'] ?: 'document'));
$size = (int) ($document['file_size'] ?: filesize($path));
$outputData = null;

if ((string) ($document['encryption_algorithm'] ?? '') !== '') {
    $cipherData = file_get_contents($path);
    if ($cipherData === false) {
        lex_audit('failed_read_encrypted_case_file_document', 'case_file_documents', (string) $documentId);
        http_response_code(500);
        exit('Unable to read encrypted document.');
    }

    try {
        $outputData = lex_case_file_document_decrypt($cipherData, $document);
    } catch (Throwable $e) {
        lex_audit('failed_decrypt_case_file_document', 'case_file_documents', (string) $documentId);
        http_response_code(500);
        exit('Unable to decrypt document.');
    }

    $size = strlen($outputData);
}

if ($viewOnly && !lex_case_file_previewable_mime($mime)) {
    lex_audit('denied_case_file_document_download', 'case_file_documents', (string) $documentId);
    http_response_code(403);
    exit('This file type cannot be opened in the view-only viewer.');
}

lex_audit($preview || $viewOnly ? 'preview_case_file_document' : 'download_case_file_document', 'case_file_documents', (string) $documentId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($viewOnly || ($preview && lex_case_file_previewable_mime($mime))) {
    lex_case_file_send_view_only_headers($mime, $name, $size);
} else {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $name) . '"');
}
if ($outputData !== null) {
    echo $outputData;
} else {
    readfile($path);
}
exit;
