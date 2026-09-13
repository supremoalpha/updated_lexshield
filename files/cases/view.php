<?php

require_once __DIR__ . '/../../config/bootstrap.php';

$user = lex_require_login();

$viewer = __DIR__ . '/../../config/case_files/viewer.php';
if (!is_file($viewer)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Case file viewer is missing. Open /lexshield/go.php once to restore it, then refresh.';
    exit;
}

require $viewer;
