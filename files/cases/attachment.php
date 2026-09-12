<?php
require_once __DIR__ . '/../../config/bootstrap.php';

function lex_case_file_attachment_parse_json(?string $json): array
{
    $attachments = json_decode((string) $json, true);
    return is_array($attachments) ? $attachments : [];
}

$user = lex_require_login();
lex_case_files_table_ensure();

$caseFileId = lex_sanitize_int($_GET['case_file_id'] ?? 0);
$storedName = trim((string) ($_GET['stored_name'] ?? ''));
$storedName = basename($storedName);

if (!$caseFileId || $storedName === '') {
    http_response_code(404);
    exit('Attachment not found.');
}

$stmt = lex_pdo()->prepare(
    'SELECT cf.id, cf.full_name, cf.case_file_title, cf.client_user_id, cf.assigned_lawyer_user_id, cf.created_by_user_id, cf.folder_name, cf.attachments_json
     FROM case_files cf
     WHERE cf.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $caseFileId]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    exit('Attachment not found.');
}

$access = function_exists('lex_case_file_vault_access')
    ? lex_case_file_vault_access([
        'id' => (int) $record['id'],
        'client_user_id' => (int) $record['client_user_id'],
        'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
        'created_by_user_id' => (int) $record['created_by_user_id'],
    ], $user)
    : 'none';
$viewOnly = function_exists('lex_case_file_is_view_only') && lex_case_file_is_view_only($access);
$preview = (string) ($_GET['preview'] ?? '') === '1';

if ($access === 'none') {
    lex_audit('denied_case_file_attachment_access', 'case_files', (string) $caseFileId);
    http_response_code(403);
    exit('Access denied.');
}

if ($viewOnly && !$preview) {
    lex_audit('denied_case_file_attachment_download', 'case_files', (string) $caseFileId);
    http_response_code(403);
    exit('This shared case file is view-only. Download is disabled.');
}

if ($viewOnly && !lex_case_file_view_token_ok((string) ($_GET['token'] ?? ''), (int) $user['id'])) {
    lex_audit('denied_case_file_attachment_preview', 'case_files', (string) $caseFileId);
    http_response_code(403);
    exit('Open this file from Case Files to view it.');
}

$attachment = null;
foreach (lex_case_file_attachment_parse_json((string) ($record['attachments_json'] ?? '[]')) as $item) {
    if ((string) ($item['stored_name'] ?? '') === $storedName) {
        $attachment = $item;
        break;
    }
}

if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

$category = strtoupper(trim((string) ($attachment['category'] ?? 'DOCUMENTS')));
$allowedCategories = ['DOCUMENTS', 'PHOTOS', 'EVIDENCE', 'COURT_FILINGS', 'CORRESPONDENCE'];
if (!in_array($category, $allowedCategories, true)) {
    $category = 'DOCUMENTS';
}

$originalName = trim((string) ($attachment['name'] ?? 'Attachment'));
$originalName = $originalName !== '' ? $originalName : 'Attachment';
$path = lex_case_files_folder_path((string) $record['folder_name']) . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $storedName;

if (!is_file($path)) {
    lex_audit('missing_case_file_attachment', 'case_files', (string) $caseFileId);
    http_response_code(404);
    exit('Attachment file missing.');
}

$mime = (string) ($attachment['mime_type'] ?? '');
if ($mime === '' && function_exists('mime_content_type')) {
    $detected = @mime_content_type($path);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}
if ($mime === '') {
    $mime = 'application/octet-stream';
}

$size = (int) ($attachment['size'] ?? filesize($path));

if ($viewOnly && !lex_case_file_previewable_mime($mime)) {
    lex_audit('denied_case_file_attachment_download', 'case_files', (string) $caseFileId);
    http_response_code(403);
    exit('This file type cannot be opened in the view-only viewer.');
}

lex_audit($preview || $viewOnly ? 'preview_case_file_attachment' : 'download_case_file_attachment', 'case_files', (string) $caseFileId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($viewOnly || $preview) {
    lex_case_file_send_view_only_headers($mime, $originalName, $size);
} else {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $originalName) . '"');
}
readfile($path);
exit;
