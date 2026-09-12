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

$currentUserId = (int) $user['id'];
$userRole = (string) ($user['role'] ?? '');
$isClientOwner = $userRole === 'client' && $currentUserId === (int) $record['client_user_id'];
$isLawyerOwner = $userRole === 'lawyer' && $currentUserId === (int) $record['created_by_user_id'];

if (!$isClientOwner && !$isLawyerOwner) {
    lex_audit('denied_case_file_attachment_access', 'case_files', (string) $caseFileId);
    http_response_code(403);
    exit('Access denied.');
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

lex_audit('download_case_file_attachment', 'case_files', (string) $caseFileId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $originalName) . '"');
readfile($path);
exit;
