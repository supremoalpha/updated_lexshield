<?php

declare(strict_types=1);

/**
 * Send appointment reminders 24h and 1h before.
 * Run via cron: php scripts/appointment_reminders.php
 *
 * Safe to run every 15 minutes — it only sends once per window.
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';

$pdo = lex_pdo();

$windows = [
    ['label' => '24h', 'from' => '+23 hours', 'to' => '+25 hours'],
    ['label' => '1h', 'from' => '+45 minutes', 'to' => '+75 minutes'],
];

$sent = 0;
foreach ($windows as $window) {
    $from = (new DateTimeImmutable())->modify($window['from'])->format('Y-m-d H:i:s');
    $to = (new DateTimeImmutable())->modify($window['to'])->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'SELECT a.id, a.scheduled_at, a.appointment_type, a.client_id, a.lawyer_id,
                cl.user_id AS client_user_id, l.user_id AS lawyer_user_id,
                cu.full_name AS client_name, lu.full_name AS lawyer_name
         FROM appointments a
         JOIN clients cl ON cl.id = a.client_id
         JOIN users cu ON cu.id = cl.user_id
         JOIN lawyers l ON l.id = a.lawyer_id
         JOIN users lu ON lu.id = l.user_id
         WHERE a.status = "confirmed"
           AND a.scheduled_at BETWEEN :from AND :to'
    );
    $stmt->execute(['from' => $from, 'to' => $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $appt) {
        $time = (new DateTimeImmutable((string) $appt['scheduled_at']))->format('M j, Y \a\t g:i A');
        $type = (string) ($appt['appointment_type'] ?? 'Appointment');
        $tag = "appt_remind_{$window['label']}_{$appt['id']}";

        $already = $pdo->prepare('SELECT 1 FROM notifications WHERE user_id = :uid AND message LIKE :tag LIMIT 1');
        $already->execute(['uid' => (int) $appt['client_user_id'], 'tag' => "%{$tag}%"]);
        if (!$already->fetchColumn()) {
            $msg = "Reminder: your {$type} with {$appt['lawyer_name']} is in {$window['label']} ({$time}). [{$tag}]";
            lex_notify((int) $appt['client_user_id'], 'appointment', $msg);
            if (function_exists('lex_email_notify_appointment')) {
                lex_email_notify_appointment((int) $appt['client_user_id'], "Appointment reminder — {$window['label']}", $msg);
            }
            $sent++;
        }

        $already->execute(['uid' => (int) $appt['lawyer_user_id'], 'tag' => "%{$tag}%"]);
        if (!$already->fetchColumn()) {
            $msg = "Reminder: {$type} with {$appt['client_name']} is in {$window['label']} ({$time}). [{$tag}]";
            lex_notify((int) $appt['lawyer_user_id'], 'appointment', $msg);
            if (function_exists('lex_email_notify_appointment')) {
                lex_email_notify_appointment((int) $appt['lawyer_user_id'], "Appointment reminder — {$window['label']}", $msg);
            }
            $sent++;
        }
    }
}

echo "{$sent} reminders sent\n";
