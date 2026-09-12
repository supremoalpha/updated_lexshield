<?php

declare(strict_types=1);

/**
 * Database connection layer. Reads credentials from environment variables
 * (see .env.example), so the same code works locally, in CI, and in
 * production without hardcoding secrets.
 */

if (!function_exists('lex_sql_statements')) {
    /**
     * Split a migration file into individual statements. PDO MySQL often
     * runs only the first statement when MYSQL_ATTR_MULTI_STATEMENTS is off,
     * which left XAMPP installs half-migrated and then HTTP 500 on the home page.
     *
     * @return list<string>
     */
    function lex_sql_statements(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $kept = [];
        foreach (explode("\n", $sql) as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $kept[] = $line;
        }
        $joined = trim(implode("\n", $kept));
        if ($joined === '') {
            return [];
        }
        $parts = preg_split('/;\s*(?:\n|$)/', $joined) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B;");
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}

if (!function_exists('lex_exec_sql_script')) {
    function lex_exec_sql_script(PDO $pdo, string $sql): void
    {
        foreach (lex_sql_statements($sql) as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $message = $e->getMessage();
                $ignorable = stripos($message, 'Duplicate column') !== false
                    || stripos($message, 'Duplicate key') !== false
                    || stripos($message, 'already exists') !== false;
                if (!$ignorable) {
                    throw $e;
                }
            }
        }
    }
}

if (!function_exists('lex_make_pdo')) {
    function lex_make_pdo(): PDO
    {
        $configuredHost = (string) (lex_env('DB_HOST', 'localhost') ?? 'localhost');
        $port = (string) (lex_env('DB_PORT', '3306') ?? '3306');
        $name = (string) (lex_env('DB_NAME', 'updated_lexshield') ?? 'updated_lexshield');
        $user = (string) (lex_env('DB_USER', 'root') ?? 'root');
        $pass = (string) (lex_env('DB_PASS', '') ?? '');
        $charset = (string) (lex_env('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4');

        $hosts = [];
        foreach ([$configuredHost, 'localhost', '127.0.0.1'] as $host) {
            $host = trim($host);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        $attempts = [];
        foreach ($hosts as $host) {
            $attempts[] = ['host' => $host, 'user' => $user, 'pass' => $pass];
        }

        $local = PHP_OS_FAMILY === 'Windows'
            || in_array(strtolower((string) (lex_env('APP_ENV', '') ?? '')), ['local', 'development', 'dev'], true)
            || (function_exists('lex_is_local_http_host') && lex_is_local_http_host());
        if ($local && $user !== 'root') {
            foreach ($hosts as $host) {
                $attempts[] = ['host' => $host, 'user' => 'root', 'pass' => ''];
            }
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ];
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
        }
        if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
            $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 3;
        }

        $last = null;
        foreach ($attempts as $attempt) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $attempt['host'],
                $port,
                $name,
                $charset
            );
            try {
                return new PDO($dsn, $attempt['user'], $attempt['pass'], $options);
            } catch (PDOException $e) {
                $last = $e;
            }
        }

        throw $last instanceof PDOException
            ? $last
            : new PDOException('Could not connect to MySQL database ' . $name);
    }
}

if (!function_exists('lex_pdo')) {
    function lex_pdo(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = lex_make_pdo();
        }
        return $pdo;
    }
}

if (!function_exists('lex_db_retry')) {
    /**
     * Retries a DB operation up to 3 times (used for lock/timeout hiccups).
     * Returns $fallback if all attempts fail and a fallback was given,
     * otherwise re-throws the last exception.
     */
    function lex_db_retry(callable $callback, mixed $fallback = null)
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (PDOException $e) {
                $attempt++;
                if ($attempt >= 3) {
                    if (func_num_args() >= 2) {
                        return $fallback;
                    }
                    throw $e;
                }
                usleep(150000 * $attempt);
            }
        }
    }
}

if (!function_exists('lex_recent')) {
    function lex_recent(string $sql, array $params = []): array
    {
        try {
            $stmt = lex_pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            if (preg_match("/Unknown (table|column)|doesn't exist/i", $e->getMessage()) === 1) {
                lex_db_last_error($e->getMessage());
                return [];
            }
            throw $e;
        }
    }
}

if (!function_exists('lex_stats')) {
    function lex_stats(string $sql, array $params = []): int
    {
        try {
            $stmt = lex_pdo()->prepare($sql);
            $stmt->execute($params);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            if (preg_match("/Unknown (table|column)|doesn't exist/i", $e->getMessage()) === 1) {
                lex_db_last_error($e->getMessage());
                return 0;
            }
            throw $e;
        }
    }
}

if (!function_exists('lex_core_tables_ensure')) {
    /**
     * Defensive schema self-heal: creates the "core" tables this app
     * depends on if they do not already exist, using the exact same DDL as
     * sql/migrations/2026_08_29_000001_create_core_schema.sql. This keeps
     * the codebase's own established pattern (rate_limits, video_call_*
     * already self-create) so the app boots correctly even on a fresh
     * database where `php scripts/migrate.php` has not been run yet.
     * Running scripts/migrate.php remains the recommended path (it also
     * tracks applied versions), this is purely a safety net.
     */
    function lex_core_tables_ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $migrationsDir = dirname(__DIR__) . '/sql/migrations';
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $pdo = lex_pdo();
        foreach ($files as $file) {
            $sql = trim((string) file_get_contents($file));
            if ($sql === '') {
                continue;
            }
            try {
                lex_exec_sql_script($pdo, $sql);
            } catch (PDOException $e) {
                // Non-fatal: a partially different schema may already exist.
                // The app should keep running with whatever schema is present.
            }
        }

        $done = true;
    }
}

if (!function_exists('lex_db_last_error')) {
    function lex_db_last_error(?string $message = null): string
    {
        static $last = '';
        if ($message !== null) {
            $last = $message;
        }
        return $last;
    }
}

if (!function_exists('lex_db_available')) {
    /**
     * Non-throwing connectivity probe. Used to show a friendly maintenance
     * page instead of a raw fatal error when the database is unreachable.
     */
    function lex_db_available(): bool
    {
        try {
            lex_pdo();
            lex_db_last_error('');
            return true;
        } catch (Throwable $e) {
            lex_db_last_error($e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lex_normalize_ip')) {
    function lex_normalize_ip(string $ip): string
    {
        $ip = strtolower(trim($ip));
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        return $ip;
    }
}

if (!function_exists('lex_is_loopback_ip')) {
    function lex_is_loopback_ip(?string $ip): bool
    {
        $ip = lex_normalize_ip((string) $ip);
        return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
    }
}

if (!function_exists('lex_is_local_http_host')) {
    function lex_is_local_http_host(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return lex_is_loopback_ip($host)
            || lex_is_loopback_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''))
            || lex_is_loopback_ip((string) ($_SERVER['SERVER_ADDR'] ?? ''));
    }
}

if (!function_exists('lex_render_database_help_page')) {
    /**
     * XAMPP-friendly page shown instead of Chrome's blank HTTP 500.
     */
    function lex_render_database_help_page(string $detail = ''): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $detail = trim($detail);
        if ($detail === '') {
            $detail = lex_db_last_error();
        }
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
        $dbName = htmlspecialchars((string) (lex_env('DB_NAME', 'updated_lexshield') ?? 'updated_lexshield'), ENT_QUOTES, 'UTF-8');
        $dbUser = htmlspecialchars((string) (lex_env('DB_USER', 'root') ?? 'root'), ENT_QUOTES, 'UTF-8');
        $dbHost = htmlspecialchars((string) (lex_env('DB_HOST', 'localhost') ?? 'localhost'), ENT_QUOTES, 'UTF-8');
        $setup = htmlspecialchars(
            (function_exists('lex_app_url') ? lex_app_url('setup.php') : '/lexshield/setup.php'),
            ENT_QUOTES,
            'UTF-8'
        );
        $phpmyadmin = 'http://localhost/phpmyadmin';

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>LEXSHIELD is not connected to MySQL</title>'
            . '<style>
body{margin:0;font-family:Segoe UI,system-ui,sans-serif;background:#0f1419;color:#e7eef7;line-height:1.5}
main{max-width:40rem;margin:8vh auto;padding:0 1.5rem}
h1{font-size:1.6rem;margin:0 0 .5rem}
.card{background:#18202a;border:1px solid #2a3644;border-radius:12px;padding:1.25rem 1.4rem;margin:1rem 0}
code,pre{font-family:Consolas,monospace;background:#0c1116;padding:.15rem .35rem;border-radius:4px}
pre{padding:.8rem 1rem;overflow:auto;white-space:pre-wrap}
ol{padding-left:1.2rem}
a{color:#7db4ff}
.btn{display:inline-block;margin-top:.6rem;background:#2f6fed;color:#fff;text-decoration:none;padding:.6rem 1rem;border-radius:8px;font-weight:600}
.muted{color:#9aa8b6}
</style></head><body><main>'
            . '<h1>MySQL is not ready (this is the XAMPP 500)</h1>'
            . '<p class="muted">Chrome says “HTTP ERROR 500” when PHP cannot open the database. Start MySQL, then run the local installer.</p>'
            . '<div class="card"><ol>'
            . '<li>Open the <strong>XAMPP Control Panel</strong> and click <strong>Start</strong> on both <strong>Apache</strong> and <strong>MySQL</strong>. MySQL must be green.</li>'
            . '<li>Open <a href="' . $setup . '">http://localhost/lexshield/setup.php</a> and finish the installer (this creates the database and an admin login).</li>'
            . '<li>Or in phpMyAdmin (<a href="' . $phpmyadmin . '">' . $phpmyadmin . '</a>) create a database named <code>' . $dbName . '</code>, then put this in <code>.env</code>:</li>'
            . '</ol><pre>APP_ENV=local
APP_URL=http://localhost/lexshield
APP_BASE_PATH=/lexshield
DB_HOST=localhost
DB_NAME=' . $dbName . '
DB_USER=root
DB_PASS=</pre>'
            . '<p>XAMPP’s default MySQL user is <code>root</code> with an empty password. Do not use <code>' . $dbUser . '</code>@<code>' . $dbHost . '</code> unless you created that user in phpMyAdmin.</p>'
            . '<p>Open the app at <code>http://localhost/lexshield/</code> — not <code>http://localhost/</code>.</p>'
            . '<a class="btn" href="' . $setup . '">Open XAMPP setup</a></div>';

        if ($safeDetail !== '') {
            echo '<div class="card"><p class="muted">MySQL said:</p><pre>' . $safeDetail . '</pre></div>';
        }

        echo '</main></body></html>';
    }
}
