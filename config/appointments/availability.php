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
     */
    function lex_availability_save_month(
        int $lawyerId,
        int $year,
        int $month,
        array $onDates,
        string $startTime = '09:00',
        string $endTime = '17:00'
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
                    $insertOn->execute([
                        'id' => $lawyerId,
                        'd' => $date,
                        'start' => $start,
                        'end' => $end,
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
