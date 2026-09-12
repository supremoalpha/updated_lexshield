<?php

require_once __DIR__ . '/config/bootstrap.php';

if (function_exists('lex_require_message_attachment_file')) {
    lex_require_message_attachment_file();
}

if (function_exists('lex_messages_send_attachment')) {
    lex_messages_send_attachment();
}

$nested = __DIR__ . '/files/messages/attachment.php';
$legacy = __DIR__ . '/files/messages/attachment .php';
if (!is_file($nested) && is_file($legacy)) {
    @mkdir(dirname($nested), 0775, true);
    @copy($legacy, $nested);
}
if (is_file($nested)) {
    require $nested;
}

http_response_code(500);
exit('Message attachment helper is missing. Copy config/messages/core.php and open /lexshield/go.php first.');
