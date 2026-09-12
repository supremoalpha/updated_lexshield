<?php

declare(strict_types=1);

/**
 * Email notification helpers — sends best-effort email for key events.
 * Each function is fire-and-forget (logs errors, never throws).
 */

if (!function_exists('lex_email_notify_appointment')) {
    function lex_email_notify_appointment(int $userId, string $subject, string $message): void
    {
        if (!function_exists('lex_send_email') || !function_exists('lex_mail_is_configured') || !lex_mail_is_configured()) {
            return;
        }
        try {
            $stmt = lex_pdo()->prepare('SELECT email, full_name FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['email'])) {
                return;
            }
            $name = (string) ($user['full_name'] ?? 'User');
            $html = "<p>Hi {$name},</p><p>{$message}</p><p>— " . lex_site_setting('site_name', 'LEXSHIELD') . "</p>";
            lex_send_email((string) $user['email'], $subject, $html);
        } catch (Throwable $e) {
            // Best-effort.
        }
    }
}

if (!function_exists('lex_email_notify_message')) {
    function lex_email_notify_message(int $receiverId, string $senderName): void
    {
        lex_email_notify_appointment(
            $receiverId,
            'New message from ' . $senderName,
            "{$senderName} sent you a message on " . lex_site_setting('site_name', 'LEXSHIELD') . ". Log in to read and reply."
        );
    }
}

if (!function_exists('lex_email_notify_call')) {
    function lex_email_notify_call(int $receiverId, string $callerName): void
    {
        lex_email_notify_appointment(
            $receiverId,
            $callerName . ' is calling you',
            "{$callerName} started a video call. Open " . lex_site_setting('site_name', 'LEXSHIELD') . " to answer."
        );
    }
}

if (!function_exists('lex_email_notify_payment')) {
    function lex_email_notify_payment(int $userId, string $status, string $paymentFor): void
    {
        $verb = $status === 'verified' ? 'approved' : ($status === 'rejected' ? 'rejected' : 'updated');
        lex_email_notify_appointment(
            $userId,
            "Payment {$verb}: {$paymentFor}",
            "Your payment for \"{$paymentFor}\" has been {$verb}."
        );
    }
}
