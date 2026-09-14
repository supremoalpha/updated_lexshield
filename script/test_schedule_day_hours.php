<?php

declare(strict_types=1);

/**
 * Attorneys can set available hours per calendar day.
 * php script/test_schedule_day_hours.php
 */

$root = dirname(__DIR__);
ob_start();
require_once $root . '/config/bootstrap.php';
ob_end_clean();

$failed = 0;
$passed = 0;

function lex_sched_assert(string $label, bool $ok, string $detail = ''): void
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

$schedule = (string) file_get_contents($root . '/lawyer/schedule.php');
$avail = (string) file_get_contents($root . '/config/appointments/availability.php');

lex_sched_assert('Save month accepts per-day hours', str_contains($avail, 'array $dayHours = []') && str_contains($avail, 'function lex_availability_collect_day_hours'));
lex_sched_assert('Schedule form posts per-day start and end', str_contains($schedule, 'name="duty_start[') && str_contains($schedule, 'name="duty_end['));
lex_sched_assert('Schedule copy explains per-day hours', str_contains($schedule, "that day's start and end time"));
lex_sched_assert('Packed XAMPP source includes per-day hours', str_contains($schedule, 'lex_availability_collect_day_hours') && str_contains($schedule, 'array $dayHours = []'));
lex_sched_assert('Time inputs are not globally hidden', str_contains((string) file_get_contents($root . '/public/css/style.css'), '.schedule-month-day input[type="time"]'));

lex_availability_tables_ensure();
$lawyerId = (int) (lex_pdo()->query('SELECT id FROM lawyers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($lawyerId > 0) {
    $year = 2031;
    $month = 3;
    lex_availability_save_month($lawyerId, $year, $month, ['2031-03-03', '2031-03-04'], '09:00', '17:00', [
        '2031-03-03' => ['start' => '08:00', 'end' => '12:00'],
        '2031-03-04' => ['start' => '13:00', 'end' => '18:00'],
    ]);
    $monthData = lex_availability_get_month($lawyerId, $year, $month);
    lex_sched_assert(
        'Monday can be 8am-12pm while Tuesday is 1pm-6pm',
        substr((string) ($monthData['on']['2031-03-03']['start_time'] ?? ''), 0, 5) === '08:00'
            && substr((string) ($monthData['on']['2031-03-03']['end_time'] ?? ''), 0, 5) === '12:00'
            && substr((string) ($monthData['on']['2031-03-04']['start_time'] ?? ''), 0, 5) === '13:00'
            && substr((string) ($monthData['on']['2031-03-04']['end_time'] ?? ''), 0, 5) === '18:00'
    );
    lex_availability_save_month($lawyerId, $year, $month, [], '09:00', '17:00');
} else {
    lex_sched_assert('Monday can be 8am-12pm while Tuesday is 1pm-6pm', false, 'No lawyers row in the database');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
