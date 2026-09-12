<?php

declare(strict_types=1);

/**
 * Shared appointment / inquiry type lists used by client booking
 * (attorney booked) and the public quick-inquiry form.
 */

if (!function_exists('lex_appointment_custom_choice')) {
    function lex_appointment_custom_choice(): string
    {
        return 'Custom';
    }
}

if (!function_exists('lex_appointment_booking_types')) {
    /**
     * @return list<string>
     */
    function lex_appointment_booking_types(): array
    {
        return [
            'Client Intake Consultation',
            'Document Review',
            'Case Follow-up',
            'Legal Advice',
            'Notary',
            lex_appointment_custom_choice(),
        ];
    }
}

if (!function_exists('lex_quick_inquiry_topics')) {
    /**
     * @return list<string>
     */
    function lex_quick_inquiry_topics(): array
    {
        return [
            'Appointment',
            'Legal assistance',
            'Directory',
            'Notary',
            'Other',
            lex_appointment_custom_choice(),
        ];
    }
}

if (!function_exists('lex_appointment_type_max_length')) {
    function lex_appointment_type_max_length(): int
    {
        return 120;
    }
}

if (!function_exists('lex_quick_inquiry_topic_max_length')) {
    function lex_quick_inquiry_topic_max_length(): int
    {
        return 190;
    }
}

if (!function_exists('lex_resolve_typed_choice')) {
    /**
     * Resolve a dropdown choice plus optional custom text into the stored label.
     *
     * @param list<string> $allowed
     * @return array{choice: string, custom: string, value: string, ok: bool}
     */
    function lex_resolve_typed_choice(string $choice, string $customText, array $allowed, int $maxLen = 120): array
    {
        $customLabel = lex_appointment_custom_choice();
        $choice = trim($choice);
        $customText = trim($customText);
        if ($maxLen > 0 && function_exists('mb_substr')) {
            $customText = mb_substr($customText, 0, $maxLen);
        } elseif ($maxLen > 0) {
            $customText = substr($customText, 0, $maxLen);
        }

        if ($choice === $customLabel) {
            if ($customText === '' || $customText === $customLabel) {
                return [
                    'choice' => $customLabel,
                    'custom' => $customText,
                    'value' => '',
                    'ok' => false,
                ];
            }

            return [
                'choice' => $customLabel,
                'custom' => $customText,
                'value' => $customText,
                'ok' => true,
            ];
        }

        if ($choice !== '' && in_array($choice, $allowed, true)) {
            return [
                'choice' => $choice,
                'custom' => '',
                'value' => $choice,
                'ok' => true,
            ];
        }

        return [
            'choice' => $choice,
            'custom' => $customText,
            'value' => '',
            'ok' => false,
        ];
    }
}
