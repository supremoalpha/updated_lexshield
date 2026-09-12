<?php

require_once __DIR__ . '/../../config/bootstrap.php';

if (function_exists('lex_messages_send_attachment')) {
    lex_messages_send_attachment();
}

http_response_code(500);
exit('Message attachment helper is missing. Copy config/messages/core.php.');
