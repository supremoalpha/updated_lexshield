<?php

declare(strict_types=1);

/**
 * XAMPP / localhost installer. Open http://localhost/lexshield/setup.php
 * This file does not load bootstrap.php, so it still works when MySQL is down.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/security/input_sanitizer.php';

if (!function_exists('lex_sql_statements')) {
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

function lex_setup_local_only(): void
{
    $ip = function_exists('lex_normalize_ip')
        ? lex_normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''))
        : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $allowed = function_exists('lex_is_local_http_host')
        ? lex_is_local_http_host()
        : in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
    if (!$allowed) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';
        exit;
    }
}

function lex_setup_env_upsert(array $values): void
{
    $path = __DIR__ . '/.env';
    $example = __DIR__ . '/.env.example';
    $text = is_file($path)
        ? (string) file_get_contents($path)
        : (is_file($example) ? (string) file_get_contents($example) : '');
    if ($text === '') {
        $text = '';
    }
    foreach ($values as $key => $value) {
        $value = str_replace(["\r", "\n"], '', (string) $value);
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote((string) $key, '/') . '=.*$/m';
        if (preg_match($pattern, $text) === 1) {
            $text = (string) preg_replace($pattern, $line, $text, 1);
        } else {
            $text = rtrim($text) . "\n" . $line . "\n";
        }
    }
    if (file_put_contents($path, $text) === false) {
        throw new RuntimeException('Could not write .env. Check that the lexshield folder is writable.');
    }
}

function lex_setup_connect_mysql(string $host, string $port, string $user, string $pass, ?string $dbname = null): PDO
{
    $dsn = $dbname
        ? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname)
        : sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $hosts = array_values(array_unique([$host, 'localhost', '127.0.0.1']));
    $last = null;
    foreach ($hosts as $tryHost) {
        try {
            $tryDsn = str_replace('host=' . $host, 'host=' . $tryHost, $dsn);
            return new PDO($tryDsn, $user, $pass, $options);
        } catch (Throwable $e) {
            $last = $e;
        }
    }
    throw $last instanceof Throwable ? $last : new RuntimeException('Could not connect to MySQL.');
}

function lex_setup_quote_ident(string $name): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        throw new RuntimeException('Database name may only contain letters, numbers, and underscores.');
    }
    return '`' . $name . '`';
}

function lex_setup_apply_migrations(PDO $pdo): void
{
    $migrationsDir = __DIR__ . '/sql/migrations';
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `version` VARCHAR(120) NOT NULL,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $appliedMap = array_fill_keys(array_map('strval', $applied), true);
    $files = glob($migrationsDir . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $version = basename($file);
        $sql = trim((string) file_get_contents($file));
        if ($sql === '') {
            continue;
        }
        lex_exec_sql_script($pdo, $sql);
        if (!isset($appliedMap[$version])) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (:version)');
            $stmt->execute(['version' => $version]);
        }
    }
}

function lex_setup_seed_admin(PDO $pdo, string $email, string $name, string $password): void
{
    $exists = $pdo->query('SELECT id FROM users WHERE role = "admin" LIMIT 1')->fetchColumn();
    if ($exists) {
        return;
    }
    $error = lex_password_policy_error($password, $email, $name);
    if ($error !== '') {
        throw new RuntimeException($error);
    }
    $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, is_active, created_at)
         VALUES (:full_name, :email, :password_hash, "admin", 1, NOW())'
    )->execute([
        'full_name' => $name !== '' ? $name : 'Administrator',
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
    ]);
}

lex_setup_local_only();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['lex_setup_csrf'])) {
    $_SESSION['lex_setup_csrf'] = bin2hex(random_bytes(16));
}

$error = '';
$success = '';
$done = false;

$defaults = [
    'mysql_user' => 'root',
    'mysql_pass' => '',
    'db_host' => 'localhost',
    'db_name' => (string) (lex_env('DB_NAME', 'updated_lexshield') ?: 'updated_lexshield'),
    'admin_email' => (string) (lex_env('ADMIN_EMAIL', '') ?: ''),
    'admin_name' => 'Administrator',
    'admin_password' => '',
];

if (lex_db_available()) {
    try {
        $adminCount = (int) lex_make_pdo()->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
        if ($adminCount > 0) {
            $done = true;
            $success = 'LEXSHIELD is already connected to MySQL. You can sign in.';
        }
    } catch (Throwable $e) {
        // Schema still needs to be installed.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done) {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['lex_setup_csrf'], $token)) {
        $error = 'Invalid form token. Refresh the page and try again.';
    } else {
        $defaults['mysql_user'] = trim((string) ($_POST['mysql_user'] ?? 'root')) ?: 'root';
        $defaults['mysql_pass'] = (string) ($_POST['mysql_pass'] ?? '');
        $defaults['db_host'] = trim((string) ($_POST['db_host'] ?? 'localhost')) ?: 'localhost';
        $defaults['db_name'] = trim((string) ($_POST['db_name'] ?? 'updated_lexshield')) ?: 'updated_lexshield';
        $defaults['admin_email'] = lex_sanitize_email((string) ($_POST['admin_email'] ?? ''));
        $defaults['admin_name'] = lex_sanitize_text((string) ($_POST['admin_name'] ?? 'Administrator')) ?: 'Administrator';
        $defaults['admin_password'] = (string) ($_POST['admin_password'] ?? '');
        try {
            $server = lex_setup_connect_mysql($defaults['db_host'], '3306', $defaults['mysql_user'], $defaults['mysql_pass']);
            $dbIdent = lex_setup_quote_ident($defaults['db_name']);
            $server->exec('CREATE DATABASE IF NOT EXISTS ' . $dbIdent . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = lex_setup_connect_mysql($defaults['db_host'], '3306', $defaults['mysql_user'], $defaults['mysql_pass'], $defaults['db_name']);
            lex_setup_apply_migrations($pdo);
            $appKey = (string) (lex_env('APP_KEY', '') ?? '');
            if ($appKey === '') {
                $appKey = bin2hex(random_bytes(32));
            }
            $basePath = lex_app_base_path();
            lex_setup_env_upsert([
                'APP_ENV' => 'local',
                'APP_URL' => lex_app_base_url(),
                'APP_BASE_PATH' => $basePath === '' ? '/' : $basePath,
                'APP_KEY' => $appKey,
                'DB_HOST' => $defaults['db_host'],
                'DB_PORT' => '3306',
                'DB_NAME' => $defaults['db_name'],
                'DB_USER' => $defaults['mysql_user'],
                'DB_PASS' => $defaults['mysql_pass'],
            ]);
            if ($defaults['admin_email'] !== '' && $defaults['admin_password'] !== '') {
                lex_setup_seed_admin($pdo, $defaults['admin_email'], $defaults['admin_name'], $defaults['admin_password']);
            }
            $done = true;
            $success = 'Database ready. Open the home page and sign in.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$csrf = htmlspecialchars((string) $_SESSION['lex_setup_csrf'], ENT_QUOTES, 'UTF-8');
$home = htmlspecialchars(lex_app_url(''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LEXSHIELD XAMPP setup</title>
<style>
body{margin:0;font-family:Segoe UI,system-ui,sans-serif;background:#0f1419;color:#e7eef7;line-height:1.45}
main{max-width:32rem;margin:6vh auto;padding:0 1.25rem 3rem}
h1{font-size:1.5rem;margin:0 0 .4rem}
.muted{color:#9aa8b6}
.card{background:#18202a;border:1px solid #2a3644;border-radius:12px;padding:1.2rem}
label{display:block;margin:.7rem 0;font-size:.9rem}
input{width:100%;box-sizing:border-box;margin-top:.25rem;padding:.55rem .65rem;border-radius:8px;border:1px solid #3a4a5c;background:#0c1116;color:#e7eef7}
button,.btn{display:inline-block;margin-top:1rem;background:#2f6fed;color:#fff;border:0;padding:.65rem 1.1rem;border-radius:8px;font-weight:600;cursor:pointer;text-decoration:none}
.err{background:#3a1820;border:1px solid #8a3040;color:#ffd0d6;padding:.8rem 1rem;border-radius:8px;margin:1rem 0}
.ok{background:#16321f;border:1px solid #2f7a45;color:#c8f0d4;padding:.8rem 1rem;border-radius:8px;margin:1rem 0}
</style>
</head>
<body>
<main>
  <h1>LEXSHIELD XAMPP setup</h1>
  <p class="muted">This creates the MySQL database on this computer. Start <strong>Apache</strong> and <strong>MySQL</strong> in the XAMPP Control Panel first.</p>
  <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="ok"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($done): ?>
    <p><a class="btn" href="<?= $home ?>">Open LEXSHIELD</a></p>
  <?php else: ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <label>MySQL host
      <input name="db_host" value="<?= htmlspecialchars($defaults['db_host'], ENT_QUOTES, 'UTF-8') ?>" required>
    </label>
    <label>MySQL admin user (XAMPP default is root)
      <input name="mysql_user" value="<?= htmlspecialchars($defaults['mysql_user'], ENT_QUOTES, 'UTF-8') ?>" required>
    </label>
    <label>MySQL admin password (leave blank if XAMPP root has no password)
      <input type="password" name="mysql_pass" value="" autocomplete="new-password">
    </label>
    <label>Database name
      <input name="db_name" value="<?= htmlspecialchars($defaults['db_name'], ENT_QUOTES, 'UTF-8') ?>" required>
    </label>
    <label>Admin email (first login)
      <input type="email" name="admin_email" value="<?= htmlspecialchars($defaults['admin_email'], ENT_QUOTES, 'UTF-8') ?>" required>
    </label>
    <label>Admin name
      <input name="admin_name" value="<?= htmlspecialchars($defaults['admin_name'], ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label>Admin password (10+ characters, upper, lower, number, symbol)
      <input type="password" name="admin_password" required autocomplete="new-password">
    </label>
    <button type="submit">Create database and admin</button>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
