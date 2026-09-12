<?php
require_once __DIR__ . '/../../config/bootstrap.php';

$user = lex_require_login();
lex_case_files_table_ensure();
lex_case_file_vault_table_ensure();

$documentId = lex_sanitize_int($_GET['document_id'] ?? 0);
$caseFileId = lex_sanitize_int($_GET['case_file_id'] ?? 0);
$storedName = basename(trim((string) ($_GET['stored_name'] ?? '')));

$title = 'View case file';
$fileName = 'File';
$mime = '';
$sourceUrl = '';
$canPreview = false;
$viewOnly = false;
$watermark = trim((string) ($user['full_name'] ?? 'Viewer')) . ' · view only · ' . date('M j, Y g:i A');

if ($documentId > 0) {
    $stmt = lex_pdo()->prepare(
        'SELECT d.*, cf.id AS case_file_id, cf.case_file_title, cf.client_user_id,
                cf.assigned_lawyer_user_id, cf.created_by_user_id
         FROM case_file_documents d
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
    $access = lex_case_file_vault_access([
        'id' => (int) $document['case_file_id'],
        'client_user_id' => (int) $document['client_user_id'],
        'assigned_lawyer_user_id' => (int) $document['assigned_lawyer_user_id'],
        'created_by_user_id' => (int) $document['created_by_user_id'],
    ], $user);
    if ($access === 'none' || ((string) $document['upload_status'] !== 'approved' && $access !== 'manage')) {
        lex_audit('denied_case_file_document_access', 'case_file_documents', (string) $documentId);
        http_response_code(403);
        exit('Access denied.');
    }
    $viewOnly = lex_case_file_is_view_only($access);
    $fileName = trim((string) ($document['original_name'] ?: 'document'));
    $title = (string) ($document['case_file_title'] ?? $title);
    $mime = (string) ($document['mime_type'] ?: 'application/octet-stream');
    $canPreview = lex_case_file_previewable_mime($mime);
    $query = ['document_id' => $documentId, 'preview' => 1];
    if ($viewOnly) {
        $query['token'] = lex_case_file_view_token_issue((int) $user['id']);
    }
    $sourceUrl = function_exists('lex_nav_href')
        ? lex_nav_href('case_document_file.php?' . http_build_query($query))
        : lex_app_url('case_document_file.php?' . http_build_query($query));
    lex_audit('open_case_file_document_viewer', 'case_file_documents', (string) $documentId);
} elseif ($caseFileId > 0 && $storedName !== '') {
    $stmt = lex_pdo()->prepare(
        'SELECT cf.id, cf.case_file_title, cf.client_user_id, cf.assigned_lawyer_user_id,
                cf.created_by_user_id, cf.attachments_json
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
    $access = lex_case_file_vault_access([
        'id' => (int) $record['id'],
        'client_user_id' => (int) $record['client_user_id'],
        'assigned_lawyer_user_id' => (int) $record['assigned_lawyer_user_id'],
        'created_by_user_id' => (int) $record['created_by_user_id'],
    ], $user);
    if ($access === 'none') {
        lex_audit('denied_case_file_attachment_access', 'case_files', (string) $caseFileId);
        http_response_code(403);
        exit('Access denied.');
    }
    $attachment = null;
    $decoded = json_decode((string) ($record['attachments_json'] ?? '[]'), true);
    foreach (is_array($decoded) ? $decoded : [] as $item) {
        if ((string) ($item['stored_name'] ?? '') === $storedName) {
            $attachment = $item;
            break;
        }
    }
    if (!$attachment) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $viewOnly = lex_case_file_is_view_only($access);
    $fileName = trim((string) ($attachment['name'] ?? 'Attachment'));
    $title = (string) ($record['case_file_title'] ?? $title);
    $mime = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
    $canPreview = lex_case_file_previewable_mime($mime);
    $query = [
        'case_file_id' => $caseFileId,
        'stored_name' => $storedName,
        'preview' => 1,
    ];
    if ($viewOnly) {
        $query['token'] = lex_case_file_view_token_issue((int) $user['id']);
    }
    $sourceUrl = function_exists('lex_nav_href')
        ? lex_nav_href('case_file_attachment.php?' . http_build_query($query))
        : lex_app_url('case_file_attachment.php?' . http_build_query($query));
    lex_audit('open_case_file_attachment_viewer', 'case_files', (string) $caseFileId);
} else {
    http_response_code(404);
    exit('File not found.');
}

$isImage = str_starts_with($mime, 'image/');
$kind = $isImage ? 'image' : (str_starts_with($mime, 'text/') ? 'text' : 'frame');
$backHref = function_exists('lex_nav_href') ? lex_nav_href('case_files.php?record=' . max($caseFileId, 0)) : lex_app_url('case_files.php');
if ($documentId > 0) {
    $backHref = function_exists('lex_nav_href')
        ? lex_nav_href('case_files.php?record=' . (int) ($document['case_file_id'] ?? 0))
        : lex_app_url('case_files.php?record=' . (int) ($document['case_file_id'] ?? 0));
} elseif ($caseFileId > 0) {
    $backHref = function_exists('lex_nav_href')
        ? lex_nav_href('case_files.php?record=' . $caseFileId)
        : lex_app_url('case_files.php?record=' . $caseFileId);
}

lex_page_header('View case file', 'case-files', $user);
?>
<section class="card case-file-view-only" data-case-file-view-only<?= $viewOnly ? ' data-shared-view-only="1"' : '' ?>>
  <div class="card-head case-file-view-head">
    <div>
      <h2><?= lex_e($title) ?></h2>
      <p class="muted"><?= lex_e($fileName) ?><?= $viewOnly ? ' · Shared view only' : '' ?></p>
    </div>
    <a class="button button-secondary" href="<?= lex_e($backHref) ?>">Back to case files</a>
  </div>
  <?php if ($viewOnly): ?>
    <p class="case-file-view-banner">You can see every file in this shared case. Download, copy, print, and screenshots are blocked in the portal.</p>
  <?php endif; ?>
  <div class="case-file-view-stage" oncontextmenu="return false;">
    <div class="case-file-view-watermark" aria-hidden="true"><?php for ($i = 0; $i < 24; $i++): ?><span><?= lex_e($watermark) ?></span><?php endfor; ?></div>
    <div class="case-file-view-shield" aria-hidden="true"></div>
    <?php if ($canPreview && $sourceUrl !== ''): ?>
      <?php if ($kind === 'image'): ?>
        <img class="case-file-view-media" src="<?= lex_e($sourceUrl) ?>" alt="" draggable="false">
      <?php else: ?>
        <iframe class="case-file-view-frame" src="<?= lex_e($sourceUrl) ?>" title="<?= lex_e($fileName) ?>"></iframe>
      <?php endif; ?>
    <?php else: ?>
      <div class="case-file-view-unavailable">
        <strong><?= lex_e($fileName) ?></strong>
        <p>This file type cannot be opened on screen. Shared case files cannot be downloaded.</p>
      </div>
    <?php endif; ?>
  </div>
</section>
<script>
(function () {
  var root = document.querySelector('[data-case-file-view-only]');
  if (!root) return;
  var block = function (event) { event.preventDefault(); event.stopPropagation(); return false; };
  ['copy', 'cut', 'paste', 'dragstart', 'selectstart', 'contextmenu'].forEach(function (name) {
    document.addEventListener(name, block, true);
  });
  document.addEventListener('keydown', function (event) {
    var key = String(event.key || '').toLowerCase();
    var blocked = (event.ctrlKey || event.metaKey) && ['c', 'x', 's', 'p', 'a'].indexOf(key) !== -1;
    if (blocked || key === 'printscreen' || key === 'f12') {
      event.preventDefault();
      root.classList.add('is-capture-blocked');
      window.setTimeout(function () { root.classList.remove('is-capture-blocked'); }, 1200);
    }
  }, true);
  window.addEventListener('beforeprint', function (event) {
    event.preventDefault();
    document.body.classList.add('case-file-print-blocked');
  });
})();
</script>
<?php lex_page_footer(); ?>
