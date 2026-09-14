<?php
require_once __DIR__ . '/../config/bootstrap.php';

if (!function_exists('lex_schedule_availability_source')) {
    function lex_schedule_availability_source(): string
    {
        return <<<'LEX_AVAIL_PHP'
<?php

declare(strict_types=1);

/**
 * Lawyer availability and appointment conflict checking.
 *
 * Tables:
 *   lawyer_duty_day     — specific calendar dates the lawyer is on duty
 *   lawyer_day_off      — specific dates marked unavailable
 *   lawyer_availability — optional weekly fallback (older installs)
 */

if (!function_exists('lex_availability_tables_ensure')) {
    function lex_availability_tables_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $pdo = lex_pdo();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lawyer_availability` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `lawyer_id` INT NOT NULL,
                `day_of_week` TINYINT NOT NULL COMMENT '0=Sun,1=Mon,...,6=Sat',
                `start_time` TIME NOT NULL DEFAULT '09:00:00',
                `end_time` TIME NOT NULL DEFAULT '17:00:00',
                `is_available` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_lawyer_avail_day` (`lawyer_id`, `day_of_week`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lawyer_day_off` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `lawyer_id` INT NOT NULL,
                `off_date` DATE NOT NULL,
                `reason` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_lawyer_day_off` (`lawyer_id`, `off_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lawyer_duty_day` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `lawyer_id` INT NOT NULL,
                `duty_date` DATE NOT NULL,
                `start_time` TIME NOT NULL DEFAULT '09:00:00',
                `end_time` TIME NOT NULL DEFAULT '17:00:00',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_lawyer_duty_day` (`lawyer_id`, `duty_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }
}

if (!function_exists('lex_availability_normalize_time')) {
    function lex_availability_normalize_time(string $value, string $fallback = '09:00'): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m) !== 1) {
            $value = $fallback;
            preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m);
        }
        $hour = max(0, min(23, (int) ($m[1] ?? 9)));
        $minute = max(0, min(59, (int) ($m[2] ?? 0)));
        $second = max(0, min(59, (int) ($m[3] ?? 0)));
        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }
}

if (!function_exists('lex_availability_hm')) {
    function lex_availability_hm(string $value, string $fallback = '09:00'): string
    {
        return substr(lex_availability_normalize_time($value, $fallback), 0, 5);
    }
}

if (!function_exists('lex_availability_collect_day_hours')) {
    /**
     * @param array<string, mixed> $starts
     * @param array<string, mixed> $ends
     * @return array<string, array{start:string,end:string}>
     */
    function lex_availability_collect_day_hours(array $starts, array $ends): array
    {
        $hours = [];
        foreach ($starts as $date => $start) {
            $date = (string) $date;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            $hours[$date] = [
                'start' => (string) $start,
                'end' => (string) ($ends[$date] ?? ''),
            ];
        }
        foreach ($ends as $date => $end) {
            $date = (string) $date;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || isset($hours[$date])) {
                continue;
            }
            $hours[$date] = [
                'start' => '',
                'end' => (string) $end,
            ];
        }

        return $hours;
    }
}

if (!function_exists('lex_availability_parse_month')) {
    /**
     * @return array{0:int,1:int}
     */
    function lex_availability_parse_month(?string $value): array
    {
        $now = new DateTimeImmutable('first day of this month');
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})$/', $value, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            if ($year >= 2020 && $year <= 2100 && $month >= 1 && $month <= 12) {
                return [$year, $month];
            }
        }
        return [(int) $now->format('Y'), (int) $now->format('n')];
    }
}

if (!function_exists('lex_availability_month_dates')) {
    /**
     * @return list<string> Y-m-d dates in the month
     */
    function lex_availability_month_dates(int $year, int $month): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $days = (int) $start->format('t');
        $out = [];
        for ($d = 1; $d <= $days; $d++) {
            $out[] = sprintf('%04d-%02d-%02d', $year, $month, $d);
        }
        return $out;
    }
}

if (!function_exists('lex_availability_get_month')) {
    /**
     * @return array{
     *   on: array<string, array{duty_date:string,start_time:string,end_time:string}>,
     *   off: array<string, string>,
     *   booked: array<string, int>,
     *   start: string,
     *   end: string
     * }
     */
    function lex_availability_get_month(int $lawyerId, int $year, int $month): array
    {
        lex_availability_tables_ensure();
        $pdo = lex_pdo();
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');

        $on = [];
        $stmt = $pdo->prepare(
            'SELECT duty_date, start_time, end_time
             FROM lawyer_duty_day
             WHERE lawyer_id = :id AND duty_date BETWEEN :from AND :to
             ORDER BY duty_date'
        );
        $stmt->execute(['id' => $lawyerId, 'from' => $from, 'to' => $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $on[(string) $row['duty_date']] = $row;
        }

        $off = [];
        $stmt = $pdo->prepare(
            'SELECT off_date, reason FROM lawyer_day_off
             WHERE lawyer_id = :id AND off_date BETWEEN :from AND :to
             ORDER BY off_date'
        );
        $stmt->execute(['id' => $lawyerId, 'from' => $from, 'to' => $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $off[(string) $row['off_date']] = (string) ($row['reason'] ?? '');
        }

        $booked = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT DATE(scheduled_at) AS booked_date, COUNT(*) AS cnt
                 FROM appointments
                 WHERE lawyer_id = :id
                   AND scheduled_at >= :from
                   AND scheduled_at < DATE_ADD(:to, INTERVAL 1 DAY)
                   AND status IN ("pending", "confirmed")
                 GROUP BY DATE(scheduled_at)'
            );
            $stmt->execute(['id' => $lawyerId, 'from' => $from . ' 00:00:00', 'to' => $to]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $booked[(string) $row['booked_date']] = (int) $row['cnt'];
            }
        } catch (Throwable $e) {
            $booked = [];
        }

        $firstOn = reset($on);
        $start = $firstOn ? substr((string) $firstOn['start_time'], 0, 5) : '09:00';
        $end = $firstOn ? substr((string) $firstOn['end_time'], 0, 5) : '17:00';

        return [
            'on' => $on,
            'off' => $off,
            'booked' => $booked,
            'start' => $start,
            'end' => $end,
        ];
    }
}

if (!function_exists('lex_availability_save_month')) {
    /**
     * @param list<string> $onDates Y-m-d dates that are on duty
     * @param array<string, array{start?:string,end?:string}> $dayHours
     */
    function lex_availability_save_month(
        int $lawyerId,
        int $year,
        int $month,
        array $onDates,
        string $startTime = '09:00',
        string $endTime = '17:00',
        array $dayHours = []
    ): void {
        lex_availability_tables_ensure();
        $pdo = lex_pdo();
        $allDates = lex_availability_month_dates($year, $month);
        $onSet = [];
        foreach ($onDates as $date) {
            if (in_array($date, $allDates, true)) {
                $onSet[$date] = true;
            }
        }
        $start = lex_availability_normalize_time($startTime, '09:00');
        $end = lex_availability_normalize_time($endTime, '17:00');
        if ($start >= $end) {
            $end = '17:00:00';
            if ($start >= $end) {
                $end = '23:59:00';
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM lawyer_duty_day
                 WHERE lawyer_id = :id AND duty_date BETWEEN :from AND :to'
            )->execute([
                'id' => $lawyerId,
                'from' => $allDates[0],
                'to' => $allDates[count($allDates) - 1],
            ]);
            $pdo->prepare(
                'DELETE FROM lawyer_day_off
                 WHERE lawyer_id = :id AND off_date BETWEEN :from AND :to'
            )->execute([
                'id' => $lawyerId,
                'from' => $allDates[0],
                'to' => $allDates[count($allDates) - 1],
            ]);

            $insertOn = $pdo->prepare(
                'INSERT INTO lawyer_duty_day (lawyer_id, duty_date, start_time, end_time)
                 VALUES (:id, :d, :start, :end)'
            );
            $insertOff = $pdo->prepare(
                'INSERT INTO lawyer_day_off (lawyer_id, off_date, reason)
                 VALUES (:id, :d, :r)'
            );
            foreach ($allDates as $date) {
                if (isset($onSet[$date])) {
                    $dayStart = $start;
                    $dayEnd = $end;
                    if (isset($dayHours[$date]) && is_array($dayHours[$date])) {
                        $dayStart = lex_availability_normalize_time((string) ($dayHours[$date]['start'] ?? $startTime), $startTime);
                        $dayEnd = lex_availability_normalize_time((string) ($dayHours[$date]['end'] ?? $endTime), $endTime);
                    }
                    if ($dayStart >= $dayEnd) {
                        $dayEnd = $end;
                    }
                    if ($dayStart >= $dayEnd) {
                        $dayEnd = '23:59:00';
                    }
                    $insertOn->execute([
                        'id' => $lawyerId,
                        'd' => $date,
                        'start' => $dayStart,
                        'end' => $dayEnd,
                    ]);
                } else {
                    $insertOff->execute([
                        'id' => $lawyerId,
                        'd' => $date,
                        'r' => 'Off duty',
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('lex_availability_month_configured')) {
    function lex_availability_month_configured(int $lawyerId, int $year, int $month): bool
    {
        lex_availability_tables_ensure();
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        $stmt = lex_pdo()->prepare(
            'SELECT 1 FROM lawyer_duty_day
             WHERE lawyer_id = :id AND duty_date BETWEEN :from AND :to
             LIMIT 1'
        );
        $stmt->execute(['id' => $lawyerId, 'from' => $from, 'to' => $to]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('lex_availability_date_is_open')) {
    function lex_availability_date_is_open(int $lawyerId, DateTimeImmutable $dt): bool
    {
        lex_availability_tables_ensure();
        $pdo = lex_pdo();
        $dateStr = $dt->format('Y-m-d');
        $timeStr = $dt->format('H:i:s');

        $stmt = $pdo->prepare(
            'SELECT 1 FROM lawyer_day_off WHERE lawyer_id = :id AND off_date = :d LIMIT 1'
        );
        $stmt->execute(['id' => $lawyerId, 'd' => $dateStr]);
        if ($stmt->fetchColumn()) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT start_time, end_time FROM lawyer_duty_day
             WHERE lawyer_id = :id AND duty_date = :d LIMIT 1'
        );
        $stmt->execute(['id' => $lawyerId, 'd' => $dateStr]);
        $duty = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($duty) {
            return $timeStr >= $duty['start_time'] && $timeStr < $duty['end_time'];
        }

        if (lex_availability_month_configured($lawyerId, (int) $dt->format('Y'), (int) $dt->format('n'))) {
            return false;
        }

        $dow = (int) $dt->format('w');
        $stmt = $pdo->prepare(
            'SELECT is_available, start_time, end_time FROM lawyer_availability
             WHERE lawyer_id = :id AND day_of_week = :dow LIMIT 1'
        );
        $stmt->execute(['id' => $lawyerId, 'dow' => $dow]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }
        if (!(int) $row['is_available']) {
            return false;
        }
        return $timeStr >= $row['start_time'] && $timeStr < $row['end_time'];
    }
}

if (!function_exists('lex_availability_get_schedule')) {
    /**
     * @return array<int, array{day_of_week:int, start_time:string, end_time:string, is_available:int}>
     */
    function lex_availability_get_schedule(int $lawyerId): array
    {
        lex_availability_tables_ensure();
        $stmt = lex_pdo()->prepare(
            'SELECT day_of_week, start_time, end_time, is_available
             FROM lawyer_availability
             WHERE lawyer_id = :id
             ORDER BY day_of_week'
        );
        $stmt->execute(['id' => $lawyerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(int) $row['day_of_week']] = $row;
        }
        return $byDay;
    }
}

if (!function_exists('lex_availability_save_schedule')) {
    /**
     * @param array<int, array{is_available: bool, start_time: string, end_time: string}> $days
     */
    function lex_availability_save_schedule(int $lawyerId, array $days): void
    {
        lex_availability_tables_ensure();
        $pdo = lex_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, is_available)
             VALUES (:lawyer_id, :day, :start, :end, :avail)
             ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), is_available = VALUES(is_available)'
        );
        foreach ($days as $dow => $slot) {
            if ($dow < 0 || $dow > 6) {
                continue;
            }
            $stmt->execute([
                'lawyer_id' => $lawyerId,
                'day' => $dow,
                'start' => $slot['start_time'] ?? '09:00',
                'end' => $slot['end_time'] ?? '17:00',
                'avail' => !empty($slot['is_available']) ? 1 : 0,
            ]);
        }
    }
}

if (!function_exists('lex_availability_get_days_off')) {
    function lex_availability_get_days_off(int $lawyerId, ?string $fromDate = null): array
    {
        lex_availability_tables_ensure();
        $from = $fromDate ?? date('Y-m-d');
        $stmt = lex_pdo()->prepare(
            'SELECT off_date, reason FROM lawyer_day_off
             WHERE lawyer_id = :id AND off_date >= :from
             ORDER BY off_date'
        );
        $stmt->execute(['id' => $lawyerId, 'from' => $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lex_availability_add_day_off')) {
    function lex_availability_add_day_off(int $lawyerId, string $date, string $reason = ''): bool
    {
        lex_availability_tables_ensure();
        try {
            lex_pdo()->prepare(
                'DELETE FROM lawyer_duty_day WHERE lawyer_id = :id AND duty_date = :d'
            )->execute(['id' => $lawyerId, 'd' => $date]);
            lex_pdo()->prepare(
                'INSERT INTO lawyer_day_off (lawyer_id, off_date, reason) VALUES (:id, :d, :r)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason)'
            )->execute(['id' => $lawyerId, 'd' => $date, 'r' => $reason]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('lex_availability_remove_day_off')) {
    function lex_availability_remove_day_off(int $lawyerId, string $date): void
    {
        lex_availability_tables_ensure();
        lex_pdo()->prepare(
            'DELETE FROM lawyer_day_off WHERE lawyer_id = :id AND off_date = :d'
        )->execute(['id' => $lawyerId, 'd' => $date]);
    }
}

if (!function_exists('lex_availability_is_available')) {
    function lex_availability_is_available(int $lawyerId, DateTimeImmutable $dt): bool
    {
        return lex_availability_date_is_open($lawyerId, $dt);
    }
}

if (!function_exists('lex_appointment_has_conflict')) {
    function lex_appointment_has_conflict(int $lawyerId, DateTimeImmutable $dt, int $excludeAppointmentId = 0): bool
    {
        $pdo = lex_pdo();
        $start = $dt->modify('-59 minutes')->format('Y-m-d H:i:s');
        $end = $dt->modify('+59 minutes')->format('Y-m-d H:i:s');
        $sql = 'SELECT 1 FROM appointments
                WHERE lawyer_id = :lawyer_id
                  AND scheduled_at > :start AND scheduled_at < :end_dt
                  AND status IN ("pending", "confirmed")';
        $params = [
            'lawyer_id' => $lawyerId,
            'start' => $start,
            'end_dt' => $end,
        ];
        if ($excludeAppointmentId > 0) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeAppointmentId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('lex_availability_day_labels')) {
    function lex_availability_day_labels(): array
    {
        return [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
    }
}

LEX_AVAIL_PHP;
    }
}

if (function_exists('lex_require_availability')) {
    lex_require_availability();
}

if (!function_exists('lex_availability_save_month')) {
    $lexAvailFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR
        . 'appointments' . DIRECTORY_SEPARATOR . 'availability.php';
    $lexAvailDir = dirname($lexAvailFile);
    if (!is_dir($lexAvailDir)) {
        @mkdir($lexAvailDir, 0775, true);
    }
    @file_put_contents($lexAvailFile, lex_schedule_availability_source());
    if (is_file($lexAvailFile)) {
        require_once $lexAvailFile;
    }
}

if (!function_exists('lex_availability_save_month')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Schedule setup</title>'
        . '<body style="font-family:sans-serif;max-width:40rem;margin:2rem auto;line-height:1.5">'
        . '<h1>Missing schedule file</h1>'
        . '<p>Copy this folder onto XAMPP:</p>'
        . '<pre>config\\appointments\\availability.php</pre>'
        . '</body>';
    exit;
}

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
$lawyerId = lex_user_lawyer_id((int) $user['id']);
lex_availability_tables_ensure();

$message = '';
$error = '';

[$viewYear, $viewMonth] = lex_availability_parse_month(isset($_POST['month']) ? (string) $_POST['month'] : (string) ($_GET['month'] ?? ''));
$monthKey = sprintf('%04d-%02d', $viewYear, $viewMonth);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? 'save_month');
    if ($action === 'save_month') {
        $onDates = $_POST['duty'] ?? [];
        if (!is_array($onDates)) {
            $onDates = [];
        }
        $onDates = array_values(array_filter(array_map('strval', $onDates), static function (string $date): bool {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
        }));
        $start = lex_sanitize_text($_POST['start_time'] ?? '09:00');
        $end = lex_sanitize_text($_POST['end_time'] ?? '17:00');
        $dayHours = function_exists('lex_availability_collect_day_hours')
            ? lex_availability_collect_day_hours(
                is_array($_POST['duty_start'] ?? null) ? $_POST['duty_start'] : [],
                is_array($_POST['duty_end'] ?? null) ? $_POST['duty_end'] : []
            )
            : [];
        lex_availability_save_month($lawyerId, $viewYear, $viewMonth, $onDates, $start, $end, $dayHours);
        lex_audit('update_month_schedule', 'lawyer_duty_day', $monthKey);
        $message = 'Your month schedule has been saved. Clients can book only the days and hours you marked On Duty.';
    }
}

$monthData = lex_availability_get_month($lawyerId, $viewYear, $viewMonth);
$dates = lex_availability_month_dates($viewYear, $viewMonth);
$monthStart = new DateTimeImmutable($dates[0]);
$lead = (int) $monthStart->format('w');
$prev = $monthStart->modify('-1 month');
$next = $monthStart->modify('+1 month');
$configured = lex_availability_month_configured($lawyerId, $viewYear, $viewMonth);
$today = date('Y-m-d');

lex_page_header('My Schedule', 'appointments', $user);
?>
<section class="card schedule-month-card">
  <div class="card-head">
    <div>
      <h2>Monthly duty calendar</h2>
      <p class="muted">Turn a day on or off, then set that day's start and end time. Clients can book only those hours.</p>
    </div>
  </div>
  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>

  <div class="schedule-month-nav">
    <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/schedule.php') . '?month=' . $prev->format('Y-m')) ?>">Previous</a>
    <strong><?= lex_e($monthStart->format('F Y')) ?></strong>
    <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/schedule.php') . '?month=' . $next->format('Y-m')) ?>">Next</a>
  </div>

  <form method="post">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="save_month">
    <input type="hidden" name="month" value="<?= lex_e($monthKey) ?>">
    <div class="schedule-month-hours">
      <label>Default start <input type="time" name="start_time" value="<?= lex_e($monthData['start']) ?>" required data-schedule-default-start></label>
      <label>Default end <input type="time" name="end_time" value="<?= lex_e($monthData['end']) ?>" required data-schedule-default-end></label>
      <p class="muted schedule-month-hours-note">Used when you turn a day on. You can still change any day's hours below.</p>
    </div>
    <div class="schedule-month-legend">
      <span class="is-on">On duty</span>
      <span class="is-off">Off / not available</span>
      <span class="is-booked">Already booked</span>
    </div>
    <div class="schedule-month-grid" role="grid" aria-label="Duty calendar">
      <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $label): ?>
        <div class="schedule-month-dow"><?= $label ?></div>
      <?php endforeach; ?>
      <?php for ($i = 0; $i < $lead; $i++): ?>
        <div class="schedule-month-empty"></div>
      <?php endfor; ?>
      <?php foreach ($dates as $date): ?>
        <?php
          $isOn = isset($monthData['on'][$date]) || (!$configured && ((int) date('w', strtotime($date)) >= 1 && (int) date('w', strtotime($date)) <= 5));
          $bookedCount = (int) ($monthData['booked'][$date] ?? 0);
          $isToday = $date === $today;
          $dayStart = isset($monthData['on'][$date]['start_time'])
              ? substr((string) $monthData['on'][$date]['start_time'], 0, 5)
              : $monthData['start'];
          $dayEnd = isset($monthData['on'][$date]['end_time'])
              ? substr((string) $monthData['on'][$date]['end_time'], 0, 5)
              : $monthData['end'];
          $weekday = date('D', strtotime($date));
        ?>
        <div class="schedule-month-day<?= $isOn ? ' is-on' : ' is-off' ?><?= $bookedCount > 0 ? ' is-booked' : '' ?><?= $isToday ? ' is-today' : '' ?>">
          <label class="schedule-month-day-toggle">
            <input type="checkbox" name="duty[]" value="<?= lex_e($date) ?>"<?= $isOn ? ' checked' : '' ?>>
            <span class="schedule-month-dow-name"><?= lex_e($weekday) ?></span>
            <span class="schedule-month-num"><?= (int) substr($date, 8, 2) ?></span>
            <span class="schedule-month-state"><?= $isOn ? 'On duty' : 'Off' ?></span>
          </label>
          <?php if ($bookedCount > 0): ?>
            <span class="schedule-month-booked"><?= $bookedCount === 1 ? '1 booked' : $bookedCount . ' booked' ?></span>
          <?php endif; ?>
          <div class="schedule-month-day-hours">
            <label>From <input type="time" name="duty_start[<?= lex_e($date) ?>]" value="<?= lex_e($dayStart) ?>"<?= $isOn ? '' : ' disabled' ?>></label>
            <label>To <input type="time" name="duty_end[<?= lex_e($date) ?>]" value="<?= lex_e($dayEnd) ?>"<?= $isOn ? '' : ' disabled' ?>></label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="schedule-month-actions">
      <button class="button button-primary" type="submit">Save this month</button>
      <p class="muted">Unchecked days are saved as not available. Booked appointments stay booked.</p>
    </div>
  </form>
</section>
<script>
(function () {
  var defaultStart = document.querySelector('[data-schedule-default-start]');
  var defaultEnd = document.querySelector('[data-schedule-default-end]');
  document.querySelectorAll('.schedule-month-day').forEach(function (day) {
    var box = day.querySelector('input[type="checkbox"]');
    if (!box) return;
    var times = day.querySelectorAll('.schedule-month-day-hours input[type="time"]');
    box.addEventListener('change', function () {
      day.classList.toggle('is-on', box.checked);
      day.classList.toggle('is-off', !box.checked);
      var state = day.querySelector('.schedule-month-state');
      if (state) state.textContent = box.checked ? 'On duty' : 'Off';
      times.forEach(function (input) {
        input.disabled = !box.checked;
        if (box.checked && defaultStart && input.name.indexOf('duty_start') === 0 && !input.value) {
          input.value = defaultStart.value;
        }
        if (box.checked && defaultEnd && input.name.indexOf('duty_end') === 0 && !input.value) {
          input.value = defaultEnd.value;
        }
      });
    });
  });
  var form = document.querySelector('.schedule-month-card form');
  if (form) {
    form.addEventListener('submit', function () {
      document.querySelectorAll('.schedule-month-day.is-on .schedule-month-day-hours input[type="time"]').forEach(function (input) {
        input.disabled = false;
      });
    });
  }
})();
</script>
<?php lex_page_footer(); ?>
