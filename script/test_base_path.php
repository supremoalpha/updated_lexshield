<?php

declare(strict_types=1);

/**
 * Checks URL prefix + relative post-login redirects used on XAMPP.
 * php scripts/test_base_path.php
 */

require_once dirname(__DIR__) . '/config/app.php';

$failed = 0;
$passed = 0;

function lex_assert_same(string $label, string $expected, string $actual): void
{
    global $failed, $passed;
    if ($expected === $actual) {
        $passed++;
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}\n  expected: {$expected}\n  actual:   {$actual}\n";
}

lex_assert_same(
    'prefix from /lexshield/auth/login.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/auth/login.php')
);
lex_assert_same(
    'prefix ignores query string',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/auth/login.php?next=1')
);
lex_assert_same(
    'prefix from /lexshield/client/index.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/client/index.php')
);
lex_assert_same(
    'no prefix at web-root /auth/login.php',
    '',
    lex_uri_folder_prefix('/auth/login.php')
);
lex_assert_same(
    'go.php at web root has no prefix',
    '',
    lex_uri_folder_prefix('/go.php')
);
lex_assert_same(
    'prefix from /lexshield/portal.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/portal.php')
);
lex_assert_same(
    'prefix from /lexshield/go.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/go.php')
);
lex_assert_same(
    'prefix from /lexshield/chat.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/chat.php')
);
lex_assert_same(
    'prefix from /lexshield/chat_call.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/chat_call.php')
);
lex_assert_same(
    'prefix from /lexshield/chat_call_signal.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/chat_call_signal.php')
);
lex_assert_same(
    'prefix from /lexshield/case_file_view.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/case_file_view.php')
);
lex_assert_same(
    'prefix from /lexshield/case_document_file.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/case_document_file.php')
);
lex_assert_same(
    'prefix from /lexshield/case_file_attachment.php',
    '/lexshield',
    lex_uri_folder_prefix('/lexshield/case_file_attachment.php')
);
lex_assert_same(
    'find-a-lawyer from go.php stays in /lexshield',
    'client/lawyers.php',
    lex_relative_app_location('/lexshield/go.php', 'client/lawyers.php')
);
lex_assert_same(
    'find-a-lawyer from logged_in stays in /lexshield',
    '../client/lawyers.php',
    lex_relative_app_location('/lexshield/auth/logged_in.php', 'client/lawyers.php')
);
lex_assert_same(
    'find-a-lawyer from client dashboard is same folder',
    'lawyers.php',
    lex_relative_app_location('/lexshield/client/index.php', 'client/lawyers.php')
);
lex_assert_same(
    'login finish relative to go.php',
    '../go.php',
    lex_relative_app_location('/lexshield/auth/login.php', 'go.php')
);
lex_assert_same(
    'messages send stays in /lexshield',
    'chat.php',
    lex_relative_app_location('/lexshield/go.php', 'chat.php')
);
lex_assert_same(
    'home redirect into go.php',
    'go.php',
    lex_relative_app_location('/lexshield/index.php', 'go.php')
);
lex_assert_same(
    'home trailing-slash redirect into go.php',
    'go.php',
    lex_relative_app_location('/lexshield/', 'go.php')
);
lex_assert_same(
    'client page back to go.php',
    '../go.php',
    lex_relative_app_location('/lexshield/client/profile.php', 'go.php')
);

$_SERVER['REQUEST_URI'] = '/lexshield/auth/login.php';
$_SERVER['SCRIPT_NAME'] = '/lexshield/auth/login.php';
lex_assert_same(
    'request base path uses REQUEST_URI',
    '/lexshield',
    lex_request_base_path()
);
lex_assert_same(
    'app url keeps /lexshield',
    '/lexshield/client/index.php',
    lex_app_url('client/index.php')
);
lex_assert_same(
    'dashboard url is go.php',
    '/lexshield/go.php',
    lex_dashboard_url()
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
