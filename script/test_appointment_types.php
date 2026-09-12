<?php

declare(strict_types=1);

/**
 * Notary + Custom types on attorney booking and quick inquiry.
 * php script/test_appointment_types.php
 */

require_once dirname(__DIR__) . '/config/appointments/types.php';

$failed = 0;
$passed = 0;

function lex_type_assert(string $label, bool $ok, string $detail = ''): void
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

$bookingTypes = lex_appointment_booking_types();
$inquiryTopics = lex_quick_inquiry_topics();
$appointmentPhp = (string) file_get_contents(dirname(__DIR__) . '/client/appointment.php');
$homePhp = (string) file_get_contents(dirname(__DIR__) . '/config/home/page.php');
$baseJs = (string) file_get_contents(dirname(__DIR__) . '/public/js/base.js');
$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/config/bootstrap.php');

lex_type_assert('Booking types include Notary', in_array('Notary', $bookingTypes, true));
lex_type_assert('Booking types include Custom', in_array('Custom', $bookingTypes, true));
lex_type_assert('Inquiry topics include Notary', in_array('Notary', $inquiryTopics, true));
lex_type_assert('Inquiry topics include Custom', in_array('Custom', $inquiryTopics, true));
lex_type_assert(
    'Existing booking types stay available',
    in_array('Client Intake Consultation', $bookingTypes, true)
    && in_array('Document Review', $bookingTypes, true)
    && in_array('Legal Advice', $bookingTypes, true)
);

$notary = lex_resolve_typed_choice('Notary', '', $bookingTypes, 120);
lex_type_assert('Notary booking type stores as Notary', $notary['ok'] && $notary['value'] === 'Notary');

$custom = lex_resolve_typed_choice('Custom', 'Deed signing', $bookingTypes, 120);
lex_type_assert('Custom booking type stores the typed label', $custom['ok'] && $custom['value'] === 'Deed signing');

$emptyCustom = lex_resolve_typed_choice('Custom', '   ', $bookingTypes, 120);
lex_type_assert('Empty custom booking type is rejected', !$emptyCustom['ok'] && $emptyCustom['value'] === '');

$inquiryNotary = lex_resolve_typed_choice('Notary', '', $inquiryTopics, 190);
lex_type_assert('Notary inquiry topic stores as Notary', $inquiryNotary['ok'] && $inquiryNotary['value'] === 'Notary');

$inquiryCustom = lex_resolve_typed_choice('Custom', 'Barangay mediation', $inquiryTopics, 190);
lex_type_assert('Custom inquiry topic stores the typed label', $inquiryCustom['ok'] && $inquiryCustom['value'] === 'Barangay mediation');

lex_type_assert('Attorney booking form uses shared type list', str_contains($appointmentPhp, 'lex_appointment_booking_types'));
lex_type_assert('Attorney booking form has a custom type field', str_contains($appointmentPhp, 'data-custom-type') && str_contains($appointmentPhp, 'custom_appointment_type'));
lex_type_assert('Quick inquiry form uses shared topic list', str_contains($homePhp, 'lex_quick_inquiry_topics'));
lex_type_assert('Quick inquiry form has a custom type field', str_contains($homePhp, 'data-custom-type') && str_contains($homePhp, 'custom_topic'));
lex_type_assert('Custom type field toggles in JS', str_contains($baseJs, 'bindCustomTypeField') && str_contains($baseJs, "select.value === 'Custom'"));
lex_type_assert('Bootstrap loads appointment types helper', str_contains($bootstrap, '/appointments/types.php'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
