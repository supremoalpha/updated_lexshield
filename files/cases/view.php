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
$textBody = '';
$previewPath = '';
$previewDocument = [];
$watermark = trim((string) ($user['full_name'] ?? 'Viewer')) . ' · view only · ' . date('M j, Y g:i A');
$watermarkUri = function_exists('lex_case_file_watermark_data_uri')
    ? lex_case_file_watermark_data_uri($watermark)
    : 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="520" height="200">'
        . '<text x="260" y="100" fill="rgba(148,163,184,0.28)" font-size="16" text-anchor="middle" '
        . 'transform="rotate(-18 260 100)">' . htmlspecialchars($watermark, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text></svg>'
    );

if ($documentId > 0) {
    $stmt = lex_pdo()->prepare(
        'SELECT d.*, f.slug AS folder_slug, cf.id AS case_file_id, cf.case_file_title,
                cf.folder_name AS case_folder_name, cf.client_user_id,
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
    $mime = function_exists('lex_case_file_guess_mime')
        ? lex_case_file_guess_mime((string) ($document['mime_type'] ?? ''), $fileName)
        : (string) ($document['mime_type'] ?: 'application/octet-stream');
    $canPreview = lex_case_file_previewable_mime($mime, $fileName);
    $previewDocument = $document;
    $previewPath = function_exists('lex_case_file_document_abs_path')
        ? lex_case_file_document_abs_path((string) $document['case_folder_name'], $document, (string) $document['stored_name'])
        : (lex_case_files_folder_path((string) $document['case_folder_name'])
            . DIRECTORY_SEPARATOR
            . lex_case_file_vault_slug((string) ($document['folder_slug'] ?? ''))
            . DIRECTORY_SEPARATOR
            . basename((string) $document['stored_name']));
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
                cf.created_by_user_id, cf.folder_name, cf.attachments_json
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
    $mime = function_exists('lex_case_file_guess_mime')
        ? lex_case_file_guess_mime((string) ($attachment['mime_type'] ?? ''), $fileName)
        : (string) ($attachment['mime_type'] ?? 'application/octet-stream');
    $canPreview = lex_case_file_previewable_mime($mime, $fileName);
    $category = strtoupper(trim((string) ($attachment['category'] ?? 'DOCUMENTS')));
    $allowedCategories = ['DOCUMENTS', 'PHOTOS', 'EVIDENCE', 'COURT_FILINGS', 'CORRESPONDENCE'];
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'DOCUMENTS';
    }
    $previewPath = lex_case_files_folder_path((string) $record['folder_name'])
        . DIRECTORY_SEPARATOR
        . $category
        . DIRECTORY_SEPARATOR
        . $storedName;
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

if (($mime === '' || $mime === 'application/octet-stream') && preg_match('/\.(txt|log|md|csv)$/i', $fileName) === 1) {
    $mime = 'text/plain';
    $canPreview = true;
}

if (str_starts_with($mime, 'text/') && $previewPath !== '' && is_file($previewPath)) {
    $bytes = false;
    if ($previewDocument !== [] && (string) ($previewDocument['encryption_algorithm'] ?? '') !== '' && function_exists('lex_case_file_document_decrypt')) {
        $cipherData = file_get_contents($previewPath);
        if ($cipherData !== false) {
            try {
                $bytes = lex_case_file_document_decrypt($cipherData, $previewDocument);
            } catch (Throwable $e) {
                $bytes = false;
            }
        }
    } else {
        $bytes = file_get_contents($previewPath);
    }
    if (is_string($bytes) && $bytes !== '') {
        if (strlen($bytes) > 250000) {
            $bytes = substr($bytes, 0, 250000) . "\n\n[Preview truncated]";
        }
        $textBody = $bytes;
    }
}

if (($mime === '' || $mime === 'application/octet-stream') && preg_match('/\.pdf$/i', $fileName) === 1) {
    $mime = 'application/pdf';
    $canPreview = true;
}

if (($mime === '' || $mime === 'application/octet-stream') && preg_match('/\.(mp4|m4v|webm|mov|3gp)$/i', $fileName) === 1) {
    $mime = function_exists('lex_case_file_guess_mime') ? lex_case_file_guess_mime($mime, $fileName) : 'video/mp4';
    $canPreview = true;
}

$isImage = str_starts_with($mime, 'image/');
$isVideo = str_starts_with($mime, 'video/');
$kind = $textBody !== '' ? 'text' : ($isImage ? 'image' : ($isVideo ? 'video' : 'frame'));
if ($kind === 'frame' && $sourceUrl !== '' && (str_contains($mime, 'pdf') || preg_match('/\.pdf$/i', $fileName) === 1)) {
    $sourceUrl .= '#toolbar=1&navpanes=0&scrollbar=1&view=FitH&zoom=page-fit';
}
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
    <div class="case-file-view-actions">
      <button class="button button-secondary" type="button" data-case-file-fit>Fit to screen</button>
      <button class="button button-secondary" type="button" data-case-file-fullscreen>Full screen</button>
      <a class="button button-secondary" href="<?= lex_e($backHref) ?>">Back to case files</a>
    </div>
  </div>
  <?php if ($viewOnly): ?>
    <p class="case-file-view-banner">You can see every file in this shared case. Download, copy, print, and screenshots are blocked in the portal.</p>
  <?php endif; ?>
  <div class="case-file-view-stage is-fit-screen" data-case-file-stage oncontextmenu="return false;">
    <?php if ($viewOnly): ?>
      <div class="case-file-view-watermark" aria-hidden="true" style="background-image:url('<?= lex_e($watermarkUri) ?>')"></div>
    <?php endif; ?>
    <div class="case-file-view-shield" aria-hidden="true"></div>
    <?php if ($kind === 'text' && $textBody !== ''): ?>
      <pre class="case-file-view-text"><?= lex_e($textBody) ?></pre>
    <?php elseif ($canPreview && $sourceUrl !== ''): ?>
      <?php if ($kind === 'image'): ?>
        <img class="case-file-view-media" src="<?= lex_e($sourceUrl) ?>" alt="" draggable="false" data-case-file-preview>
      <?php elseif ($kind === 'video'): ?>
        <video class="case-file-view-media case-file-view-video" src="<?= lex_e($sourceUrl) ?>" controls playsinline controlslist="nodownload noremoteplayback" disablepictureinpicture data-case-file-preview></video>
      <?php else: ?>
        <iframe class="case-file-view-frame" src="<?= lex_e($sourceUrl) ?>" data-src="<?= lex_e($sourceUrl) ?>" title="<?= lex_e($fileName) ?>" allowfullscreen data-case-file-preview></iframe>
      <?php endif; ?>
    <?php else: ?>
      <div class="case-file-view-unavailable">
        <strong><?= lex_e($fileName) ?></strong>
        <p>This file type cannot be opened on screen. Shared case files cannot be downloaded.</p>
      </div>
    <?php endif; ?>
  </div>
</section>
<style>
.case-file-view-head h2,
.case-file-view-head p { overflow-wrap: anywhere; margin: 0; }
.case-file-view-actions { display: flex; flex-wrap: wrap; gap: 0.55rem; justify-content: flex-end; }
.case-file-view-stage {
  position: relative !important;
  height: calc(100dvh - 13.5rem);
  min-height: 70vh;
  overflow: hidden !important;
}
.case-file-view-stage.is-fit-screen,
.case-file-view-stage:fullscreen,
.case-file-view-stage:-webkit-full-screen { height: 100vh; min-height: 100vh; border-radius: 0; }
.case-file-view-media,
.case-file-view-frame,
.case-file-view-text {
  display: block;
  width: 100%;
  height: 100%;
  min-height: 100%;
  border: 0;
  pointer-events: auto;
}
.case-file-view-watermark {
  position: absolute !important;
  inset: -12% !important;
  z-index: 2 !important;
  pointer-events: none !important;
  overflow: hidden !important;
  background-repeat: repeat !important;
  background-size: 520px 200px !important;
}
.case-file-view-shield { position: absolute; inset: 0; z-index: 3; pointer-events: none; }
</style>
<script>
(function () {
  var root = document.querySelector('[data-case-file-view-only]');
  if (!root) return;
  var stage = root.querySelector('[data-case-file-stage]');
  var frame = root.querySelector('.case-file-view-frame');
  var fitBtn = root.querySelector('[data-case-file-fit]');
  var fullBtn = root.querySelector('[data-case-file-fullscreen]');
  var block = function (event) { event.preventDefault(); event.stopPropagation(); return false; };
  ['copy', 'cut', 'paste', 'dragstart', 'selectstart', 'contextmenu'].forEach(function (name) {
    document.addEventListener(name, block, true);
  });
  document.addEventListener('keydown', function (event) {
    var key = String(event.key || '').toLowerCase();
    var blocked = (event.ctrlKey || event.metaKey) && ['c', 'x', 's', 'p', 'a'].indexOf(key) !== -1;
    if (blocked || key === 'printscreen') {
      event.preventDefault();
      root.classList.add('is-capture-blocked');
      window.setTimeout(function () { root.classList.remove('is-capture-blocked'); }, 1200);
    }
  }, true);
  window.addEventListener('beforeprint', function (event) {
    event.preventDefault();
    document.body.classList.add('case-file-print-blocked');
  });
  var applyFit = function () {
    if (!stage) return;
    stage.classList.add('is-fit-screen');
    if (!frame) return;
    var raw = frame.getAttribute('data-src') || frame.src || '';
    var base = raw.split('#')[0];
    var next = base + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH&zoom=page-fit';
    if (frame.src !== next) {
      frame.src = next;
    }
  };
  var toggleFullscreen = function () {
    if (!stage) return;
    var active = document.fullscreenElement || document.webkitFullscreenElement;
    if (active) {
      if (document.exitFullscreen) document.exitFullscreen();
      else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
      return;
    }
    if (stage.requestFullscreen) stage.requestFullscreen();
    else if (stage.webkitRequestFullscreen) stage.webkitRequestFullscreen();
  };
  if (fitBtn) fitBtn.addEventListener('click', applyFit);
  if (fullBtn) fullBtn.addEventListener('click', toggleFullscreen);
  root.addEventListener('dblclick', function (event) {
    if (event.target && event.target.closest('[data-case-file-preview], [data-case-file-stage]')) {
      toggleFullscreen();
    }
  });
  document.addEventListener('fullscreenchange', function () {
    if (fullBtn) {
      fullBtn.textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
    }
  });
})();
</script>
<?php lex_page_footer(); ?>
