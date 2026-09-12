<?php

declare(strict_types=1);

/**
 * Create a dedicated application database and user, apply migrations,
 * and seed SMTP / site settings from .env.
 *
 * Usage:
 *   php scripts/setup_database.php
 *   php scripts/setup_database.php --admin-email=you@example.com
 *
 * Admin SQL (CREATE DATABASE / USER) uses MYSQL_ADMIN_USER / MYSQL_ADMIN_PASS.
 * On Windows XAMPP it defaults to root with an empty password.
 * Otherwise it tries `sudo mysql`.
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/db.php';

if (!function_exists('lex_exec_sql_script')) {
    function lex_exec_sql_script(PDO $pdo, string $sql): void
    {
        foreach (preg_split('/;\s*(?:\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with(ltrim($statement), '--')) {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $message = $e->getMessage();
                if (stripos($message, 'already exists') === false && stripos($message, 'Duplicate') === false) {
                    throw $e;
                }
            }
        }
    }
}

function lex_setup_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
        if ($arg === '--' . $name) {
            return '1';
        }
    }
    return $default;
}

function lex_setup_out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function lex_setup_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function lex_setup_admin_sql(string $sql): void
{
    $host = lex_env('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
    $port = lex_env('DB_PORT', '3306') ?? '3306';
    $adminUser = trim((string) (lex_env('MYSQL_ADMIN_USER', '') ?? ''));
    $adminPass = (string) (lex_env('MYSQL_ADMIN_PASS', '') ?? '');
    if ($adminUser === '' && PHP_OS_FAMILY === 'Windows') {
        $adminUser = 'root';
    }

    if ($adminUser !== '') {
        $last = null;
        foreach (array_unique([$host, '127.0.0.1', 'localhost']) as $tryHost) {
            try {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $tryHost, $port),
                    $adminUser,
                    $adminPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                lex_exec_sql_script($pdo, $sql);
                return;
            } catch (Throwable $e) {
                $last = $e;
            }
        }
        lex_setup_fail($last instanceof Throwable ? $last->getMessage() : 'Could not connect as MySQL admin.');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        lex_setup_fail('Set MYSQL_ADMIN_USER=root (XAMPP default) or open http://localhost/lexshield/setup.php');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open('sudo -n mysql', $descriptors, $pipes);
    if (!is_resource($process)) {
        lex_setup_fail('Could not run sudo mysql. Set MYSQL_ADMIN_USER and MYSQL_ADMIN_PASS.');
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        lex_setup_fail(trim($stderr !== '' ? $stderr : $stdout) ?: 'sudo mysql failed.');
    }
}

$host = lex_env('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
$port = lex_env('DB_PORT', '3306') ?? '3306';
$name = lex_env('DB_NAME', 'updated_lexshield') ?? 'updated_lexshield';
$user = lex_env('DB_USER', 'updated_lexshield') ?? 'updated_lexshield';
$pass = lex_env('DB_PASS', '');
$charset = lex_env('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4';

if ($pass === null || $pass === '') {
    if ($user !== 'root') {
        lex_setup_fail('DB_PASS is empty. For XAMPP, set DB_USER=root and leave DB_PASS blank, or open http://localhost/lexshield/setup.php');
    }
}

if (!preg_match('/^[A-Za-z0-9_]+$/', $name) || !preg_match('/^[A-Za-z0-9_]+$/', $user)) {
    lex_setup_fail('DB_NAME and DB_USER may only contain letters, numbers, and underscores.');
}

$quotedPass = str_replace("'", "''", (string) $pass);
lex_setup_out("Creating database `{$name}` and user `{$user}`...");
$adminSql = "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci;\n";
if ($user !== 'root') {
    $adminSql .= "CREATE USER IF NOT EXISTS '{$user}'@'127.0.0.1' IDENTIFIED BY '{$quotedPass}';\n"
        . "CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '{$quotedPass}';\n"
        . "ALTER USER '{$user}'@'127.0.0.1' IDENTIFIED BY '{$quotedPass}';\n"
        . "ALTER USER '{$user}'@'localhost' IDENTIFIED BY '{$quotedPass}';\n"
        . "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'127.0.0.1';\n"
        . "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'localhost';\n"
        . "FLUSH PRIVILEGES;\n";
}
lex_setup_admin_sql($adminSql);

lex_setup_out('Applying migrations...');
require dirname(__DIR__) . '/scripts/migrate.php';

require_once dirname(__DIR__) . '/config/bootstrap.php';

$pdo = lex_pdo();
$seedSettings = [
    'site_name' => lex_env('MAIL_FROM_NAME', 'LEXSHIELD') ?: 'LEXSHIELD',
    'session_timeout' => lex_env('SESSION_TIMEOUT', '1800') ?: '1800',
    'smtp_host' => lex_env('MAIL_HOST', '') ?: '',
    'smtp_port' => lex_env('MAIL_PORT', '465') ?: '465',
    'smtp_user' => lex_env('MAIL_USER', '') ?: '',
    'smtp_pass' => lex_env('MAIL_PASS', '') ?: '',
    'smtp_from' => lex_env('MAIL_FROM', '') ?: '',
    'smtp_encryption' => lex_env('MAIL_ENCRYPTION', 'smtps') ?: 'smtps',
];
$stmt = $pdo->prepare(
    'INSERT INTO site_settings (setting_key, setting_value, updated_at)
     VALUES (:setting_key, :setting_value, NOW())
     ON DUPLICATE KEY UPDATE setting_value = IF(setting_value IS NULL OR setting_value = "", VALUES(setting_value), setting_value)'
);
foreach ($seedSettings as $key => $value) {
    if ($value === '') {
        continue;
    }
    $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
}

$adminEmail = lex_sanitize_email((string) (lex_setup_arg('admin-email', lex_env('ADMIN_EMAIL', 'admin@example.com')) ?? 'admin@example.com'));
$adminName = trim((string) (lex_setup_arg('admin-name', lex_env('ADMIN_NAME', 'Administrator')) ?? 'Administrator'));
$adminPassword = (string) (lex_setup_arg('admin-password', lex_env('ADMIN_PASSWORD', '')) ?? '');

$exists = $pdo->prepare('SELECT id FROM users WHERE role = "admin" LIMIT 1');
$exists->execute();
if (!$exists->fetchColumn() && $adminEmail !== '' && $adminPassword !== '') {
    $passwordError = lex_password_policy_error($adminPassword, $adminEmail, $adminName);
    if ($passwordError !== '') {
        lex_setup_fail($passwordError);
    }
    $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, is_active, created_at)
         VALUES (:full_name, :email, :password_hash, "admin", 1, NOW())'
    )->execute([
        'full_name' => $adminName !== '' ? $adminName : 'Administrator',
        'email' => $adminEmail,
        'password_hash' => password_hash($adminPassword, PASSWORD_BCRYPT),
    ]);
    lex_setup_out("Seeded admin {$adminEmail}");
}

lex_setup_out('Database setup complete.');
