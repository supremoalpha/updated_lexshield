<?php

declare(strict_types=1);

/**
 * Lawyer appointment dashboard no longer has a Join call / VC button.
 * php script/test_appointment_no_join_call.php
 */

$failed = 0;
$passed = 0;

function lex_vc_assert(string $label, bool $ok, string $detail = ''): void
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
$lawyer = (string) file_get_contents($root . '/lawyer/appointment.php');
$client = (string) file_get_contents($root . '/client/appointment.php');

lex_vc_assert('Lawyer dashboard has no Join call button', !str_contains($lawyer, 'Join call') && !str_contains($lawyer, 'lawyer/video-call.php'));
lex_vc_assert('Lawyer dashboard still updates appointment status', str_contains($lawyer, 'name="status"') && str_contains($lawyer, 'data-appointment-board'));
lex_vc_assert('Client appointments still keep Join call', str_contains($client, 'Join call') && str_contains($client, 'client/video-call.php'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
