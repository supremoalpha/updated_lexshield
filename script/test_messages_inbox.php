<?php

declare(strict_types=1);

/**
 * Messages inbox: client↔attorney and admin↔attorney only, New message button, delete/unsend, video call.
 * php scripts/test_messages_inbox.php
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/messages/core.php';
require_once dirname(__DIR__) . '/config/messages/lock.php';
require_once dirname(__DIR__) . '/config/messages/shared.php';
require_once dirname(__DIR__) . '/config/messages/calls.php';

$failed = 0;
$passed = 0;

function lex_inbox_assert(string $label, bool $ok, string $detail = ''): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}" . ($detail !== '' ? "\n  {$detail}" : '') . "\n";
}

$shared = (string) file_get_contents(dirname(__DIR__) . '/config/messages/shared.php');
$chatCall = (string) file_get_contents(dirname(__DIR__) . '/chat_call.php');
$style = (string) file_get_contents(dirname(__DIR__) . '/public/css/style.css');
$chatJs = (string) file_get_contents(dirname(__DIR__) . '/public/js/chat.js');
$videoJs = (string) file_get_contents(dirname(__DIR__) . '/public/js/video-call.js');
$ringJs = (string) file_get_contents(dirname(__DIR__) . '/public/js/call-ring.js');
$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/config/bootstrap.php');
$callsPhp = (string) file_get_contents(dirname(__DIR__) . '/config/messages/calls.php');
$ringPhp = (string) file_get_contents(dirname(__DIR__) . '/chat_call_ring.php');

lex_inbox_assert('New message is a button, not a hash link', str_contains($shared, 'data-modal-open="newMessageModal"') && !str_contains($shared, 'href="#newMessageModal"'));
lex_inbox_assert('Closed New message dialog cannot steal clicks', str_contains($style, 'dialog#newMessageModal:not([open])') && str_contains($style, 'display: none'));
lex_inbox_assert('New message button is a full-width control', str_contains($shared, 'inbox-new-btn-wide') && str_contains($shared, 'id="inboxComposeBtn"'));
lex_inbox_assert('Inbox collapses to one column on phones', str_contains($style, 'grid-template-columns: minmax(0, 1fr)') && str_contains($style, 'html[data-theme="dark"] body:has(.inbox-messenger) .messages-layout.inbox-messenger'));
lex_inbox_assert('New message dialog is marked for mobile layout', str_contains($shared, 'inbox-new-message-modal'));
lex_inbox_assert('New message uses a native dialog', str_contains($shared, '<dialog') && str_contains($shared, 'id="newMessageModal"') && str_contains($chatJs, 'showModal'));
lex_inbox_assert('New message opener is exposed globally', str_contains($chatJs, 'window.lexOpenNewMessage') && str_contains($chatJs, 'openNewMessageModal'));
lex_inbox_assert('New message closer is exposed globally', str_contains($chatJs, 'window.lexCloseNewMessage'));
lex_inbox_assert('New message compose button has a direct click handler', str_contains($chatJs, 'data-modal-open="newMessageModal"') && str_contains($chatJs, 'openModal(modal)'));
lex_inbox_assert('Closing New message does not focus a null return target', str_contains($chatJs, 'const returnFocus = genericModalReturnFocus') && str_contains($chatJs, 'returnFocus.focus()'));
lex_inbox_assert('Inbox rows have a delete-chat button', str_contains($shared, 'name="action" value="delete_conversation"') && str_contains($shared, 'inbox-delete-btn'));
lex_inbox_assert('Inbox delete is a 44px tappable control', str_contains($shared, 'min-height:44px') && str_contains($shared, 'event.stopPropagation()'));
lex_inbox_assert('Open thread has a Delete chat control', str_contains($shared, 'inbox-header-delete'));
lex_inbox_assert('Single-message delete posts delete_message', str_contains($shared, 'name="action" value="delete_message"'));
lex_inbox_assert('Unsend is available on sent messages', str_contains($shared, 'name="unsend" value="1"'));
lex_inbox_assert('New message picker still groups admins for attorneys', str_contains($shared, "'admin' => 'Admins'"));
lex_inbox_assert(
    'Admin recipient query excludes clients',
    str_contains($shared, "IN (\\'lawyer\\', \\'attorney\\')")
    && !str_contains($shared, "IN (\\'lawyer\\', \\'attorney\\', \\'client\\')")
);
lex_inbox_assert('Composer posts to chat.php', str_contains($shared, "lex_nav_href('chat.php')"));
lex_inbox_assert('Inbox call page exists', str_contains($chatCall, 'chat_call_signal.php') && str_contains($chatCall, 'peerUserId'));
lex_inbox_assert('Messenger blue bubbles are styled', str_contains($style, '#0084ff') && str_contains($style, 'inbox-messenger'));
lex_inbox_assert('Enter sends from the composer', str_contains($chatJs, 'data-inbox-composer') && str_contains($chatJs, "event.key !== 'Enter'"));
lex_inbox_assert('Video-call JS polls peer_user_id for inbox calls', str_contains($videoJs, 'peer_user_id') && str_contains($videoJs, 'peerUserId'));
lex_inbox_assert('Video-call JS starts after leftover signals', str_contains($videoJs, 'pageData.sinceId') && str_contains($chatCall, "'sinceId'"));
lex_inbox_assert('Video-call JS shows camera and browser errors', str_contains($videoJs, 'This browser cannot start a video call') && str_contains($videoJs, 'Camera and microphone access is required'));
lex_inbox_assert('Video-call JS waits for ICE gathering', str_contains($videoJs, 'iceGatheringState') && str_contains($videoJs, 'waitForIceGathering'));
lex_inbox_assert('Inbox Video call is a 44px control', str_contains($shared, 'inbox-vc-btn') && str_contains($shared, 'min-height:44px'));
lex_inbox_assert('CSP allows STUN for WebRTC', str_contains($bootstrap, 'stun:'));
lex_inbox_assert('Ended inbox calls can drop leftover signals', str_contains($callsPhp, 'lex_inbox_call_clear_signals') && str_contains($callsPhp, 'DELETE FROM message_call_signals'));
lex_inbox_assert('Incoming ring page exists', str_contains($ringPhp, 'lex_inbox_call_incoming_for') && str_contains($ringPhp, 'decline'));
lex_inbox_assert('Notification bell is in the top bar', str_contains($bootstrap, 'notifBellBtn') && str_contains($bootstrap, 'lex_notifications_recent'));
lex_inbox_assert('Notification dropdown stays on screen on phones', str_contains($style, '.notif-bell-dropdown') && str_contains($style, 'position: fixed') && str_contains($bootstrap, 'wrap.contains(e.target)'));
// The panel is anchored with top: calc(100% + 8px), which only means "just below the bell"
// while it is position: absolute. Any rule that switches it to position: fixed re-resolves
// that percentage against the viewport and throws it a whole screen below the fold, so such
// a rule has to bring its own offsets.
$lexBellFixedNoOffset = [];
preg_match_all('/([^{}]*)\{([^{}]*)\}/', $style, $lexCssBlocks, PREG_SET_ORDER);
foreach ($lexCssBlocks as $lexCssBlock) {
    $lexSelector = trim((string) $lexCssBlock[1]);
    $lexBody = (string) $lexCssBlock[2];
    if (!str_contains($lexSelector, '.notif-bell-dropdown')) {
        continue;
    }
    if (!preg_match('/position:\s*fixed/', $lexBody)) {
        continue;
    }
    if (!preg_match('/(^|[;\s])top:/', $lexBody)) {
        $lexBellFixedNoOffset[] = preg_replace('/\s+/', ' ', $lexSelector);
    }
}
lex_inbox_assert(
    'Notification dropdown stays on screen on desktop',
    (bool) preg_match('/\.notif-bell-dropdown\s*\{[^}]*position:\s*absolute[^}]*top:\s*calc\(100% \+ 8px\)/', $style)
        && $lexBellFixedNoOffset === [],
    $lexBellFixedNoOffset === []
        ? 'The .notif-bell-dropdown desktop anchor (position: absolute; top: calc(100% + 8px)) is missing.'
        : 'position: fixed without a top offset in: ' . implode(' | ', $lexBellFixedNoOffset)
);
lex_inbox_assert(
    'Notification panel outranks the hamburger on phones',
    (bool) preg_match('/body\.app-workspace\s+\.topbar-actions\s*\{\s*position:\s*relative;\s*z-index:\s*(\d+);/', $style, $lexTopbarActionsZ)
        && (int) $lexTopbarActionsZ[1] > 60,
    '.topbar-actions is a stacking context, so it must outrank the z-index:60 on #sidebarToggle or the panel nested inside it cannot paint over the hamburger.'
);
lex_inbox_assert('Footer loads the incoming-call ringer', str_contains($bootstrap, 'call-ring.js') && str_contains($bootstrap, 'lex-call-ring-data'));
lex_inbox_assert('Incoming ring overlay is in the page footer', str_contains($bootstrap, 'lexCallRingOverlay') && str_contains($bootstrap, 'lexCallRingToast'));
lex_inbox_assert('Incoming ring uses SQL presence so timezones cannot hide it', str_contains($callsPhp, 'DATE_SUB(NOW(), INTERVAL 90 SECOND)'));
lex_inbox_assert('Incoming ring overlay is styled', str_contains($style, '.inbox-call-ring') && str_contains($style, 'inbox-call-ring-accept'));
lex_inbox_assert('Ringer polls for incoming calls', str_contains($ringJs, 'Incoming call') && str_contains($ringJs, 'ringEndpoint'));
lex_inbox_assert('Video-call JS ends both sides on hangup', str_contains($videoJs, 'sessionStatus === \'ended\'') && str_contains($videoJs, 'finishCall'));
lex_inbox_assert('Caller sees a ringing state', str_contains($videoJs, 'Ringing') && str_contains($chatCall, 'isCaller'));
$schedulePhp = (string) file_get_contents(dirname(__DIR__) . '/lawyer/schedule.php');
$availPhp = (string) file_get_contents(dirname(__DIR__) . '/config/appointments/availability.php');
$ensurePhp = (string) file_get_contents(dirname(__DIR__) . '/config/ensure_dashboards.php');
lex_inbox_assert('Schedule page does not hard-require a missing availability.php', !str_contains($schedulePhp, "require_once __DIR__ . '/../config/appointments/availability.php'"));
lex_inbox_assert('Schedule page can recreate availability.php on XAMPP', str_contains($schedulePhp, 'lex_schedule_availability_source') && str_contains($schedulePhp, 'file_put_contents'));
lex_inbox_assert('Availability helpers exist', str_contains($availPhp, 'lex_availability_tables_ensure') && str_contains($availPhp, 'lex_appointment_has_conflict'));
lex_inbox_assert('Lawyer schedule is a month calendar', str_contains($schedulePhp, 'Monthly duty calendar') && str_contains($availPhp, 'lex_availability_save_month'));
lex_inbox_assert('Month calendar can mark duty days', str_contains($schedulePhp, 'name="duty[]"') && str_contains($availPhp, 'lawyer_duty_day'));
lex_inbox_assert('XAMPP pack includes availability.php', str_contains($ensurePhp, 'config/appointments/availability.php'));
lex_inbox_assert('Notification API is packed for XAMPP', str_contains($ensurePhp, 'notifications_api.php'));
lex_inbox_assert('Phishing detector is in the top bar', str_contains($bootstrap, 'phishingDetectorModal') && str_contains($bootstrap, 'id="topbarPhishingBtn"') && str_contains($bootstrap, 'lex_phishing_open_onclick'));
lex_inbox_assert('Shield button has a direct click handler', str_contains($bootstrap, 'function lex_phishing_open_onclick') && str_contains($bootstrap, 'showModal') && str_contains($bootstrap, 'onclick='));
lex_inbox_assert('Phishing dialog is opened with showModal', str_contains($chatJs, 'isPhishingDialog') && str_contains($chatJs, 'openNativeDialog') && str_contains($chatJs, 'showModal'));
lex_inbox_assert('Closed phishing dialog cannot steal clicks', str_contains($style, 'dialog#phishingDetectorModal:not([open])') && str_contains($style, 'pointer-events: none'));
lex_inbox_assert('Phishing detector scans chat links', str_contains($shared, 'lex_phishing_scan_message_body') && str_contains($shared, 'Check link') && str_contains($shared, 'lex_phishing_open_onclick'));
lex_inbox_assert('Phishing check endpoint is packed for XAMPP', str_contains($ensurePhp, 'phishing_check.php'));
lex_inbox_assert('Phishing scan JS can parse JSON after PHP warnings', str_contains($chatJs, 'parsePhishingResponse') && str_contains($chatJs, 'parsePhishingJson') && str_contains($chatJs, 'phishingScanEndpoints') && str_contains($chatJs, 'fetchPhishingScan'));
lex_inbox_assert('Phishing scan JS posts to the current page when phishing_check.php is missing', str_contains($chatJs, 'X-Lex-Phishing') && str_contains($chatJs, 'lex_phishing') && str_contains($bootstrap, 'lex_phishing_dispatch_inline'));
lex_inbox_assert('Chat JS is packed so XAMPP copies get the JSON parser', str_contains($ensurePhp, 'public/js/chat.js'));
lex_inbox_assert('Phishing result can show the Python engine', str_contains($chatJs, 'Engines: PHP + Python') && is_file(dirname(__DIR__) . '/phishing/detect.py'));
lex_inbox_assert('Bootstrap installs missing availability helpers', str_contains($bootstrap, 'lex_require_availability') && str_contains($bootstrap, "appointments' . DIRECTORY_SEPARATOR . 'availability.php"));
$lawyerAppt = (string) file_get_contents(dirname(__DIR__) . '/lawyer/appointment.php');
$clientAppt = (string) file_get_contents(dirname(__DIR__) . '/client/appointment.php');
$notifApi = (string) file_get_contents(dirname(__DIR__) . '/notifications_api.php');
lex_inbox_assert('Sidebar nav can show unread badges', str_contains($bootstrap, 'data-nav-badge') && str_contains($bootstrap, 'lex_nav_badge_counts'));
lex_inbox_assert('Every sidebar button can show a notify badge', str_contains($bootstrap, 'data-nav-badge="<?= lex_e($navKey) ?>"') && str_contains($bootstrap, 'function lex_nav_badge_type_map'));
lex_inbox_assert('Messages badge uses unread message count', str_contains($bootstrap, 'function lex_nav_unread_messages') && str_contains($bootstrap, 'receiver_id'));
lex_inbox_assert(
    'Messages badge matches inbox partners, not leftover notifications',
    str_contains($bootstrap, 'function lex_nav_message_partner_roles')
    && str_contains($bootstrap, "lex_nav_unread_messages(\$userId, \$role)")
    && !preg_match("/counts\\['messages'\\]\\s*=\\s*max\\(/", $bootstrap)
    && str_contains($bootstrap, "'admin' => ['lawyer', 'attorney']"),
    'Admin Messages must count unread lawyer threads only. Leftover message/call notification rows belong on the bell, not the sidebar button.'
);
lex_inbox_assert(
    'Notification poll uses the role-aware unread count',
    str_contains($notifApi, 'lex_nav_unread_messages($userId, $role)'),
    'The footer poller would put the ghost badge back if it counted every unread row.'
);
lex_inbox_assert('Appointments badge uses unread appointment notifications', str_contains($bootstrap, "['appointment']") && str_contains($bootstrap, 'lex_nav_appointment_count'));
lex_inbox_assert('Appointments button shows how many appointments are waiting', str_contains($bootstrap, 'function lex_nav_appointment_count') && str_contains($bootstrap, "baseLabel + ' (' + shown + ')'") && str_contains($bootstrap, 'background:#e11d48'));
lex_inbox_assert('Notification API returns sidebar badge counts', str_contains($notifApi, 'unread_messages') && str_contains($notifApi, 'nav_badges'));
lex_inbox_assert(
    'Notification API badges come from the same helper the sidebar renders from',
    str_contains($notifApi, 'lex_nav_badge_counts(') && str_contains($notifApi, 'lex_nav_unread_messages('),
    'Recomputing the counts a different way lets the polled badges drift from the server-rendered ones until the next full page load.'
);
// lex_page_footer() is a different scope from lex_page_header(). Reaching for the
// header's $notifHrefsByType there emits a PHP warning straight into the inline
// <script> (fatal to the whole bell on any install with display_errors on) and
// otherwise serialises null, so every polled notification loses its click target.
$lexFooterAt = strpos($bootstrap, 'function lex_page_footer(): void');
$lexFooterEnd = $lexFooterAt === false ? false : strpos($bootstrap, "\nif (!function_exists(", $lexFooterAt);
$lexFooterBody = $lexFooterAt === false
    ? ''
    : substr($bootstrap, $lexFooterAt, ($lexFooterEnd === false ? strlen($bootstrap) : $lexFooterEnd) - $lexFooterAt);
$lexFooterAssigns = strpos($lexFooterBody, '$notifHrefsByType =');
$lexFooterUses = strpos($lexFooterBody, 'json_encode($notifHrefsByType');
lex_inbox_assert(
    'Footer builds its own notification href map',
    $lexFooterBody !== '' && $lexFooterUses !== false && $lexFooterAssigns !== false && $lexFooterAssigns < $lexFooterUses,
    'lex_page_footer() must assign $notifHrefsByType before serialising it; it cannot inherit the header\'s copy.'
);
lex_inbox_assert(
    'Header and footer share one notification href map',
    str_contains($bootstrap, 'function lex_notif_href_map') && substr_count($bootstrap, 'lex_notif_href_map(') >= 3
);
lex_inbox_assert(
    'Notification times are human-readable on first paint',
    str_contains($bootstrap, 'lex_message_timestamp') && str_contains($bootstrap, 'notif-bell-item-time')
);
lex_inbox_assert(
    'Notification rows show a type label and icon',
    str_contains($bootstrap, 'function lex_notification_type_label') && str_contains($bootstrap, 'notif-bell-item-kind') && str_contains($bootstrap, 'notif-bell-icon')
);
lex_inbox_assert(
    'Notification panel uses a professional empty state',
    str_contains($bootstrap, 'You\'re all caught up') && str_contains($bootstrap, 'Mark all as read')
);
$sharingPhp = (string) file_get_contents(dirname(__DIR__) . '/admin/data_sharing.php');
lex_inbox_assert(
    'Data sharing approvals page marks its layout',
    str_contains($sharingPhp, 'data-admin-sharing-page') && str_contains($sharingPhp, 'From ') && str_contains($sharingPhp, 'Decided by')
);
lex_inbox_assert(
    'Sidebar calls the feature Case File Sharing',
    str_contains($bootstrap, "'label' => 'Case File Sharing'")
    && !str_contains($bootstrap, "'label' => 'Data Sharing'"),
    'Admin and lawyer nav must say Case File Sharing.'
);
$lawyerSharing = (string) file_get_contents(dirname(__DIR__) . '/lawyer/data_sharing.php');
$helpersPhp = (string) file_get_contents(dirname(__DIR__) . '/config/case_files/helpers.php');
$actionsPhp = (string) file_get_contents(dirname(__DIR__) . '/config/case_files/actions.php');
$documentPhp = (string) file_get_contents(dirname(__DIR__) . '/files/cases/document.php');
$viewPhp = (string) file_get_contents(dirname(__DIR__) . '/files/cases/view.php');
lex_inbox_assert(
    'Shared case files are view-only',
    str_contains($helpersPhp, 'function lex_case_file_is_view_only') === false
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/config/case_files/core.php'), 'function lex_case_file_is_view_only')
    && str_contains($helpersPhp, 'Shared with you')
    && str_contains($actionsPhp, 'Shared case files are view-only')
    && str_contains($documentPhp, 'This shared case file is view-only')
    && str_contains($viewPhp, 'data-case-file-view-only')
    && is_file(dirname(__DIR__) . '/case_file_view.php'),
    'Shared lawyers must open an in-app viewer, not a download.'
);
lex_inbox_assert(
    'View-only viewer blocks copy and print',
    str_contains($style, '.case-file-view-watermark')
    && str_contains($style, '@media print')
    && str_contains($viewPhp, "['copy', 'cut', 'paste'")
    && str_contains($lawyerSharing, 'cannot download, copy, print')
);
$caseFilesIndex = (string) file_get_contents(dirname(__DIR__) . '/files/cases/index.php');
lex_inbox_assert(
    'Case Files page does not hard-require a missing actions.php',
    !str_contains($caseFilesIndex, "require_once __DIR__ . '/../../config/case_files/actions.php'")
    && str_contains($caseFilesIndex, 'is_file($lexCaseFilesPath)')
    && str_contains($caseFilesIndex, 'function lex_case_files_handle_post'),
    'A missing config/case_files/actions.php on XAMPP must not fatal Case Files.'
);
lex_inbox_assert(
    'Bootstrap can recreate case-file POST helpers on XAMPP',
    str_contains($bootstrap, 'lex_require_case_files')
    && str_contains($bootstrap, 'lex_case_files_actions_source')
    && str_contains($bootstrap, 'function lex_case_files_handle_post')
    && str_contains($ensurePhp, '/config/case_files/actions.php'),
    'Partial XAMPP copies must be able to rewrite a stale actions.php that lacks lex_case_files_handle_post.'
);
lex_inbox_assert(
    'Packed case-file actions source includes the POST handler',
    function_exists('lex_case_files_actions_source')
    && str_contains(lex_case_files_actions_source(), 'function lex_case_files_handle_post')
);
lex_inbox_assert(
    'Data sharing text stays whole words',
    str_contains($style, '[data-admin-sharing-page]') && str_contains($style, 'html[data-theme="light"] body.app-workspace .card-head h2')
);
$adminDash = (string) file_get_contents(dirname(__DIR__) . '/admin/index.php');
$adminHome = (string) file_get_contents(dirname(__DIR__) . '/auth/admin_home.php');
lex_inbox_assert(
    'Audit Feed View all opens admin/audit_logs.php from go.php',
    str_contains($adminDash, "lex_nav_href('admin/audit_logs.php')") && str_contains($adminHome, "lex_nav_href('admin/audit_logs.php')")
    && !str_contains($adminDash, 'href="audit_logs.php"') && !str_contains($adminHome, 'href="audit_logs.php"'),
    'A bare audit_logs.php href from /lexshield/go.php 404s because that file lives in admin/.'
);
lex_inbox_assert(
    'Notification panel is pinned to the bell in JavaScript',
    str_contains($bootstrap, 'function placePanel') && str_contains($bootstrap, 'getBoundingClientRect') && str_contains($bootstrap, 'document.body.appendChild(dropdown)')
);
lex_inbox_assert('Footer JS refreshes sidebar badges', str_contains($bootstrap, 'nav_badges') && str_contains($bootstrap, 'data-nav-badge'));
lex_inbox_assert('Clicking a top-bar notification opens the matching page', str_contains($bootstrap, 'data-notif-href') && str_contains($bootstrap, 'window.location.href = href'));
lex_inbox_assert('Nav badge styles exist', str_contains($style, '.nav-badge') && str_contains($style, 'display: none'));
lex_inbox_assert('Lawyer approve/reschedule/cancel send distinct notifies', str_contains($lawyerAppt, 'approved and rescheduled') && str_contains($lawyerAppt, 'has been cancelled') && str_contains($lawyerAppt, 'has been approved'));
lex_inbox_assert('Opening appointment pages clears appointment badges', str_contains($lawyerAppt, 'lex_notifications_mark_types_read') && str_contains($clientAppt, 'lex_notifications_mark_types_read'));

lex_inbox_assert('Client cannot role-chat admin', !lex_messages_roles_may_chat('client', 'admin'));
lex_inbox_assert('Admin cannot role-chat client', !lex_messages_roles_may_chat('admin', 'client'));
lex_inbox_assert('Admin can role-chat attorney', lex_messages_roles_may_chat('admin', 'attorney'));
lex_inbox_assert('Lawyer can role-chat admin', lex_messages_roles_may_chat('lawyer', 'admin'));
lex_inbox_assert('Client can role-chat lawyer', lex_messages_roles_may_chat('client', 'lawyer'));
lex_inbox_assert('Lawyer can role-chat client', lex_messages_roles_may_chat('lawyer', 'client'));

$clientNavKeys = array_column(lex_nav_items_for_role('client'), 'key');
$lawyerNavKeys = array_column(lex_nav_items_for_role('lawyer'), 'key');
lex_inbox_assert('Client nav does not include Case Files', !in_array('case-files', $clientNavKeys, true));
lex_inbox_assert('Lawyer nav still includes Case Files', in_array('case-files', $lawyerNavKeys, true));

$clientDash = (string) file_get_contents(dirname(__DIR__) . '/client/index.php');
$clientHome = (string) file_get_contents(dirname(__DIR__) . '/auth/client_home.php');
lex_inbox_assert(
    'Client dashboard has no case-file metric cards',
    !str_contains($clientDash, 'Active cases')
    && !str_contains($clientDash, 'Total cases')
    && !str_contains($clientDash, 'Case ID')
);
lex_inbox_assert('Client home dashboard matches index', $clientDash === $clientHome);

$lockPhp = (string) file_get_contents(dirname(__DIR__) . '/config/messages/lock.php');
$baseJs = (string) file_get_contents(dirname(__DIR__) . '/public/js/base.js');
lex_inbox_assert('PIN pad tells laptop users to type', str_contains($lockPhp, 'type the PIN with your keyboard'));
lex_inbox_assert('PIN input is a real keyboard field', str_contains($lockPhp, 'class="pin-entry"') && str_contains($lockPhp, 'data-pin-input'));
lex_inbox_assert('PIN keyboard handler accepts digits and Backspace', str_contains($baseJs, "event.key === 'Backspace'") && str_contains($baseJs, '/^[0-9]$/.test(event.key)'));
lex_inbox_assert('PIN keypad click returns focus for typing', str_contains($baseJs, 'focusPin()'));

lex_inbox_assert(
    'chat_call.php keeps /lexshield prefix',
    lex_uri_folder_prefix('/lexshield/chat_call.php') === '/lexshield'
);

$seeded = lex_messages_seed_conversations(
    [],
    [
        ['id' => 4, 'full_name' => 'Lawyer User', 'role' => 'lawyer', 'avatar_stored_name' => '', 'case_id' => 0],
        ['id' => 9, 'full_name' => 'Self', 'role' => 'client', 'avatar_stored_name' => '', 'case_id' => 0],
    ],
    9
);
lex_inbox_assert('Empty inbox does not keep placeholder names after delete', $seeded === []);

try {
    $pdo = lex_pdo();
    lex_messages_table_ensure();
    lex_message_deletions_table_ensure();
    lex_inbox_call_tables_ensure();

    $admin = $pdo->query(
        "SELECT id, full_name, role FROM users WHERE is_active = 1 AND LOWER(TRIM(role)) = 'admin' ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $client = $pdo->query(
        "SELECT id, full_name, role FROM users WHERE is_active = 1 AND LOWER(TRIM(role)) = 'client' ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $lawyer = $pdo->query(
        "SELECT id, full_name, role FROM users WHERE is_active = 1 AND LOWER(TRIM(role)) IN ('lawyer', 'attorney') ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    lex_inbox_assert('Database has an active admin', is_array($admin) && (int) ($admin['id'] ?? 0) > 0);
    lex_inbox_assert('Database has an active client', is_array($client) && (int) ($client['id'] ?? 0) > 0);
    lex_inbox_assert('Database has an active lawyer', is_array($lawyer) && (int) ($lawyer['id'] ?? 0) > 0);

    if (is_array($admin) && is_array($client)) {
        $adminId = (int) $admin['id'];
        $clientId = (int) $client['id'];
        $allowed = lex_messages_allowed_recipients($client);
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $allowed);
        lex_inbox_assert('Client allowed recipients exclude the admin', !in_array($adminId, $ids, true));

        $reachable = lex_messages_can_contact($allowed, $adminId);
        lex_inbox_assert('Client cannot contact the admin', $reachable === null);

        $conversations = lex_messages_seed_conversations([], $allowed, $clientId);
        $seedIds = array_map(static fn (array $row): int => (int) ($row['other_id'] ?? 0), $conversations);
        lex_inbox_assert('Client inbox does not list the admin before any messages', !in_array($adminId, $seedIds, true));
    }

    if (is_array($admin) && is_array($lawyer)) {
        $adminId = (int) $admin['id'];
        $lawyerId = (int) $lawyer['id'];

        $adminAllowed = lex_messages_allowed_recipients($admin);
        $adminIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $adminAllowed);
        lex_inbox_assert('Admin recipient list includes the lawyer', in_array($lawyerId, $adminIds, true));
        if (is_array($client)) {
            lex_inbox_assert('Admin recipient list excludes the client', !in_array((int) $client['id'], $adminIds, true));
        }

        $lawyerAllowed = lex_messages_allowed_recipients($lawyer);
        $lawyerIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $lawyerAllowed);
        lex_inbox_assert('Lawyer recipient list includes the admin', in_array($adminId, $lawyerIds, true));
        lex_inbox_assert('Lawyer can_contact the admin', is_array(lex_messages_can_contact($lawyerAllowed, $adminId)));
        lex_inbox_assert('Admin can_contact the lawyer', is_array(lex_messages_can_contact($adminAllowed, $lawyerId)));

        $encrypted = lex_messages_encrypt('Lawyer to admin from inbox test');
        $insert = $pdo->prepare(
            'INSERT INTO messages (sender_id, receiver_id, body, body_encryption_algorithm, body_encryption_iv, body_encryption_tag, is_important)
             VALUES (:sender_id, :receiver_id, :body, :alg, :iv, :tag, 0)'
        );
        $insert->execute([
            'sender_id' => $lawyerId,
            'receiver_id' => $adminId,
            'body' => base64_encode($encrypted['ciphertext']),
            'alg' => $encrypted['algorithm'],
            'iv' => $encrypted['iv'],
            'tag' => $encrypted['tag'],
        ]);
        $messageId = (int) $pdo->lastInsertId();
        lex_inbox_assert('Lawyer can insert a message to the admin', $messageId > 0);

        $thread = lex_messages_thread($lawyerId, $adminId);
        $found = false;
        foreach ($thread as $row) {
            if ((int) ($row['id'] ?? 0) === $messageId) {
                $found = true;
                break;
            }
        }
        lex_inbox_assert('Sent message appears in the lawyer-admin thread', $found);

        $hide = $pdo->prepare('INSERT IGNORE INTO message_deletions (message_id, user_id) VALUES (:message_id, :user_id)');
        $hide->execute(['message_id' => $messageId, 'user_id' => $lawyerId]);
        $afterDelete = lex_messages_thread($lawyerId, $adminId);
        $stillThere = false;
        foreach ($afterDelete as $row) {
            if ((int) ($row['id'] ?? 0) === $messageId) {
                $stillThere = true;
                break;
            }
        }
        lex_inbox_assert('Delete for you hides the message from the lawyer', !$stillThere);

        $adminStillSees = false;
        foreach (lex_messages_thread($adminId, $lawyerId) as $row) {
            if ((int) ($row['id'] ?? 0) === $messageId) {
                $adminStillSees = true;
                break;
            }
        }
        lex_inbox_assert('Delete for you leaves the message in the admin thread', $adminStillSees);

        $hide->execute(['message_id' => $messageId, 'user_id' => $adminId]);
        $unsent = false;
        foreach (lex_messages_thread($adminId, $lawyerId) as $row) {
            if ((int) ($row['id'] ?? 0) === $messageId) {
                $unsent = true;
                break;
            }
        }
        lex_inbox_assert('Unsend hides the message for the admin too', !$unsent);

        $href = lex_inbox_call_href($adminId);
        lex_inbox_assert('Video call href points at chat_call.php', str_contains($href, 'chat_call.php') && str_contains($href, 'with=' . $adminId));

        $encryptedChat = lex_messages_encrypt('Inbox delete chat should drop the name');
        $insert->execute([
            'sender_id' => $lawyerId,
            'receiver_id' => $adminId,
            'body' => base64_encode($encryptedChat['ciphertext']),
            'alg' => $encryptedChat['algorithm'],
            'iv' => $encryptedChat['iv'],
            'tag' => $encryptedChat['tag'],
        ]);
        $chatMessageId = (int) $pdo->lastInsertId();
        lex_inbox_assert('A follow-up inbox message can be inserted', $chatMessageId > 0);

        $listed = false;
        foreach (lex_messages_conversations($lawyerId) as $row) {
            if ((int) ($row['other_id'] ?? 0) === $adminId) {
                $listed = true;
                break;
            }
        }
        lex_inbox_assert('Inbox lists the name while messages remain', $listed);

        lex_messages_hide_conversation($lawyerId, $adminId);
        $listedAfter = false;
        foreach (lex_messages_conversations($lawyerId) as $row) {
            if ((int) ($row['other_id'] ?? 0) === $adminId) {
                $listedAfter = true;
                break;
            }
        }
        lex_inbox_assert('Deleting the chat removes the name from the inbox', !$listedAfter);

        $adminStillHasChat = false;
        foreach (lex_messages_conversations($adminId) as $row) {
            if ((int) ($row['other_id'] ?? 0) === $lawyerId) {
                $adminStillHasChat = true;
                break;
            }
        }
        lex_inbox_assert('The other person still has the chat after inbox delete', $adminStillHasChat);

        $session = lex_inbox_call_get_or_create($lawyerId, $adminId);
        lex_inbox_assert('Inbox call session can be created', (int) ($session['id'] ?? 0) > 0);

        $callSessionId = (int) ($session['id'] ?? 0);
        if ($callSessionId > 0) {
            $pdo->prepare(
                'INSERT INTO message_call_signals (session_id, sender_user_id, signal_type, payload)
                 VALUES (:session_id, :sender, "offer", :payload)'
            )->execute([
                'session_id' => $callSessionId,
                'sender' => $lawyerId,
                'payload' => '{"type":"offer","sdp":"v=0"}',
            ]);
            $pdo->prepare(
                "UPDATE message_call_sessions
                 SET status = 'ended', ended_at = NOW(), low_last_seen_at = NULL, high_last_seen_at = NULL
                 WHERE id = :id"
            )->execute(['id' => $callSessionId]);
            $reopened = lex_inbox_call_get_or_create($lawyerId, $adminId);
            $leftover = $pdo->prepare('SELECT COUNT(*) FROM message_call_signals WHERE session_id = :id');
            $leftover->execute(['id' => $callSessionId]);
            lex_inbox_assert('Reopening an ended inbox call clears leftover signals', (int) $leftover->fetchColumn() === 0);
            lex_inbox_assert('Reopened inbox call is waiting again', (string) ($reopened['status'] ?? '') === 'waiting');
        }

        $ringStart = lex_inbox_call_start_or_join($lawyerId, $adminId);
        lex_inbox_assert('Starting a call marks the session ringing', (string) ($ringStart['session']['status'] ?? '') === 'ringing');
        lex_inbox_assert('The caller is flagged as starting the ring', !empty($ringStart['started']));
        $incoming = lex_inbox_call_incoming_for($adminId);
        lex_inbox_assert('The other person sees an incoming ring', is_array($incoming) && (int) ($incoming['peerUserId'] ?? 0) === $lawyerId);
        lex_inbox_assert('Caller does not see their own ring', lex_inbox_call_incoming_for($lawyerId) === null);

        $joined = lex_inbox_call_start_or_join($adminId, $lawyerId);
        lex_inbox_assert('Answering a ring joins instead of starting a new one', empty($joined['started']));
        lex_inbox_assert('Answered call is active', (string) ($joined['session']['status'] ?? '') === 'active');
        lex_inbox_assert('Incoming ring stops after answer', lex_inbox_call_incoming_for($lawyerId) === null && lex_inbox_call_incoming_for($adminId) === null);

        lex_inbox_call_end((int) ($joined['session']['id'] ?? 0));
        $endedRow = lex_inbox_call_find($lawyerId, $adminId);
        lex_inbox_assert('Ending the call marks the session ended', (string) ($endedRow['status'] ?? '') === 'ended');
        lex_inbox_assert('Ended call no longer rings', lex_inbox_call_incoming_for($adminId) === null);
    }
} catch (Throwable $e) {
    lex_inbox_assert('Database messaging checks ran', false, $e->getMessage());
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
