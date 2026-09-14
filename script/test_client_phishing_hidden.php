<?php

declare(strict_types=1);

/**
 * Clients do not get the phishing detector; lawyers and admins do.
 * php script/test_client_phishing_hidden.php
 */

$failed = 0;
$passed = 0;

function lex_phish_ui_assert(string $label, bool $ok, string $detail = ''): void
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

$root = dirname(__DIR__);
$bootstrap = (string) file_get_contents($root . '/config/bootstrap.php');
$shared = (string) file_get_contents($root . '/config/messages/shared.php');
$api = (string) file_get_contents($root . '/api/phishing/check.php');
$style = (string) file_get_contents($root . '/public/css/style.css');

lex_phish_ui_assert('Role helper exists', str_contains($bootstrap, 'function lex_phishing_ui_enabled') && str_contains($bootstrap, "in_array(\$role, ['admin', 'lawyer'], true)"));
lex_phish_ui_assert('Topbar shield is gated', str_contains($bootstrap, 'lex_phishing_ui_enabled($user)') && str_contains($bootstrap, 'id="topbarPhishingBtn"'));
lex_phish_ui_assert('Footer modal is gated', str_contains($bootstrap, 'lex_phishing_ui_enabled() && function_exists(\'lex_phishing_modal_markup\')'));
lex_phish_ui_assert('Messages Check link is gated', str_contains($shared, 'lex_phishing_ui_enabled($user)') && str_contains($shared, 'Check link'));
lex_phish_ui_assert('Scan API rejects clients on the web', str_contains($api, 'lex_phishing_ui_enabled') && str_contains($api, '403'));
lex_phish_ui_assert('CSS hides client phishing chrome', str_contains($style, 'body[data-role="client"] .phishing-detector-trigger'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
