<?php

declare(strict_types=1);

/**
 * Messages for the signed-in admin, attorney, or client.
 *
 * XAMPP: http://localhost/lexshield/chat.php
 * After login this file stays under /lexshield instead of redirecting
 * to /client/messages.php (Apache 404).
 */
if (defined('LEX_CHAT_PAGE')) {
    return;
}
define('LEX_CHAT_PAGE', true);

require_once __DIR__ . '/config/bootstrap.php';

$messagesFiles = [
    __DIR__ . '/config/messages/core.php',
    __DIR__ . '/config/messages/lock.php',
    __DIR__ . '/config/messages/shared.php',
];
foreach ($messagesFiles as $messagesFile) {
    if (!is_file($messagesFile)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<p>Copy the <code>config\\messages</code> folder into <code>htdocs\\lexshield\\config\\messages</code>, then open <a href="chat.php">chat.php</a> again.</p>';
        exit;
    }
    require_once $messagesFile;
}

$user = lex_require_login();
$role = strtolower(trim((string) ($user['role'] ?? 'client')));
if ($role === 'attorney') {
    $role = 'lawyer';
}
if (!in_array($role, ['admin', 'lawyer', 'client'], true)) {
    $role = 'client';
}

lex_messages_render_page($role);
