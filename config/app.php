<?php

declare(strict_types=1);

/**
 * Minimal application/environment bootstrap: loads a .env file (if present)
 * and provides the URL helpers used throughout the app (lex_app_url(),
 * lex_asset_url()). This does not attempt to reconstruct the rest of
 * config/bootstrap.php (session/auth, page header/footer, mailer, etc.) -
 * see DATABASE.md for what is and isn't covered.
 */

if (!function_exists('lex_load_env_file')) {
    function lex_load_env_file(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, "\"'");

            if ($key !== '') {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}

lex_load_env_file(dirname(__DIR__) . '/.env');

if (!function_exists('lex_env')) {
    function lex_env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? false;
        }
        return $value !== false ? (string) $value : $default;
    }
}

if (!function_exists('lex_fs_norm')) {
    function lex_fs_norm(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return rtrim($path, '/');
    }
}

if (!function_exists('lex_path_is_under')) {
    function lex_path_is_under(string $child, string $parent): bool
    {
        $child = lex_fs_norm($child);
        $parent = lex_fs_norm($parent);
        if ($parent === '') {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $child = strtolower($child);
            $parent = strtolower($parent);
        }
        return $child === $parent || str_starts_with($child, $parent . '/');
    }
}

if (!function_exists('lex_uri_folder_prefix')) {
    /**
     * /lexshield/auth/login.php → "/lexshield"
     * /client/index.php at the web root → ""
     */
    function lex_uri_folder_prefix(string $uri): string
    {
        $uri = str_replace('\\', '/', $uri);
        $cut = strpos($uri, '?');
        if ($cut !== false) {
            $uri = substr($uri, 0, $cut);
        }
        $uri = rawurldecode($uri);
        if ($uri === '' || $uri === '/') {
            return '';
        }

        $nested = 'auth|admin|lawyer|client|files|public|api|config|security|scripts|sql|storage|vendor|lib';
        if (preg_match('#^(/[^/]+)/(?:' . $nested . ')(?:/|$)#', $uri, $match) === 1) {
            return $match[1];
        }

        $rootFiles = 'index|portal|go|open|chat|chat_call|chat_call_signal|chat_call_ring|chat_typing|notifications_api|lexshield|setup|client|lawyer|admin|attorney|fix_mail|case_files|case_file_view|case_document_file|case_file_attachment|message_attachment|payment_proof|payment_qr_image|video_call_signal';
        if (preg_match('#^(/[^/]+)/(?:' . $rootFiles . ')\.php#', $uri, $match) === 1) {
            return $match[1];
        }

        $appFolder = basename(dirname(__DIR__));
        if ($appFolder !== '' && $appFolder !== '.' && preg_match('#^(/' . preg_quote($appFolder, '#') . ')(?:/|$)#i', $uri, $match) === 1) {
            return $match[1];
        }

        $rootFolders = ['auth', 'admin', 'lawyer', 'client', 'files', 'public', 'api', 'config', 'security', 'scripts', 'sql', 'storage', 'vendor', 'lib'];
        if (preg_match('#^(/[^/]+)/?$#', $uri, $match) === 1) {
            $segment = strtolower(trim($match[1], '/'));
            if ($segment !== '' && !str_contains($segment, '.') && !in_array($segment, $rootFolders, true)) {
                return $match[1];
            }
        }

        return '';
    }
}

if (!function_exists('lex_request_base_path')) {
    /**
     * Folder prefix from the current Apache request.
     * /lexshield/auth/login.php → "/lexshield"
     */
    function lex_request_base_path(): string
    {
        foreach ([
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            (string) ($_SERVER['PHP_SELF'] ?? ''),
        ] as $candidate) {
            $prefix = lex_uri_folder_prefix($candidate);
            if ($prefix !== '') {
                return $prefix;
            }
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptName === '') {
            $scriptName = str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? ''));
        }
        $appRoot = realpath(dirname(__DIR__));
        $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $realScript = $scriptFile !== '' ? realpath($scriptFile) : false;
        $scriptFileNorm = is_string($realScript) && $realScript !== ''
            ? lex_fs_norm($realScript)
            : lex_fs_norm($scriptFile);
        $appRootNorm = is_string($appRoot) ? lex_fs_norm($appRoot) : '';
        if ($appRootNorm !== '' && $scriptFileNorm !== '' && $scriptName !== '' && lex_path_is_under($scriptFileNorm, $appRootNorm)) {
            $relFile = ltrim(substr($scriptFileNorm, strlen($appRootNorm)), '/');
            if (PHP_OS_FAMILY === 'Windows') {
                $relFile = ltrim(substr(strtolower($scriptFileNorm), strlen(strtolower($appRootNorm))), '/');
            }
            if ($relFile !== '' && $scriptName !== '' && preg_match('#/' . preg_quote($relFile, '#') . '$#i', $scriptName) === 1) {
                return rtrim(substr($scriptName, 0, -strlen($relFile)), '/');
            }
        }

        return '';
    }
}

if (!function_exists('lex_app_base_path')) {
    /**
     * URL prefix when the app is not at the web root.
     * XAMPP: C:\xampp\htdocs\lexshield → "/lexshield"
     * so pages are http://localhost/lexshield/ and http://localhost/lexshield/lexshield.php
     */
    function lex_app_base_path(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $fromRequest = lex_request_base_path();
        if ($fromRequest !== '') {
            $resolved = $fromRequest;
            return $resolved;
        }

        $fromEnv = trim((string) (lex_env('APP_BASE_PATH', '') ?? ''), '/');
        if ($fromEnv !== '') {
            $resolved = '/' . $fromEnv;
            return $resolved;
        }

        $configured = trim((string) (lex_env('APP_URL', '') ?? ''));
        if ($configured !== '') {
            $urlPath = trim((string) (parse_url($configured, PHP_URL_PATH) ?? ''), '/');
            if ($urlPath !== '') {
                $resolved = '/' . $urlPath;
                return $resolved;
            }
        }

        $appRoot = realpath(dirname(__DIR__));
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        if (is_string($appRoot) && is_string($docRoot) && $docRoot !== '' && lex_path_is_under($appRoot, $docRoot) && lex_fs_norm($appRoot) !== lex_fs_norm($docRoot)) {
            $appNorm = lex_fs_norm($appRoot);
            $docNorm = lex_fs_norm($docRoot);
            if (PHP_OS_FAMILY === 'Windows') {
                $appNorm = strtolower($appNorm);
                $docNorm = strtolower($docNorm);
            }
            $rel = substr($appNorm, strlen($docNorm));
            $resolved = '/' . trim(str_replace('\\', '/', $rel), '/');
            return $resolved;
        }

        $resolved = '';
        return $resolved;
    }
}

if (!function_exists('lex_cookie_path')) {
    function lex_cookie_path(): string
    {
        $base = lex_app_base_path();
        return $base === '' ? '/' : $base;
    }
}

if (!function_exists('lex_app_origin')) {
    function lex_app_origin(): string
    {
        $configured = trim((string) (lex_env('APP_URL', '') ?? ''));
        if ($configured !== '') {
            $parts = parse_url($configured);
            if (is_array($parts) && !empty($parts['host'])) {
                $scheme = $parts['scheme'] ?? 'http';
                $origin = $scheme . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                return $origin;
            }
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }
}

if (!function_exists('lex_app_base_url')) {
    function lex_app_base_url(): string
    {
        return rtrim(lex_app_origin() . lex_app_base_path(), '/');
    }
}

if (!function_exists('lex_app_url')) {
    /**
     * App-relative URL that works at the web root and in a folder
     * (XAMPP http://localhost/lexshield/...). Same-origin so CSP still
     * allows CSS/JS on phones, iPads, and PCs.
     */
    function lex_app_url(string $path = ''): string
    {
        $base = lex_app_base_path();
        $prefix = $base === '' ? '' : $base;

        if ($path === '' || $path === '/') {
            return $prefix === '' ? '/' : $prefix . '/';
        }
        if (str_starts_with($path, '#')) {
            return ($prefix === '' ? '/' : $prefix . '/') . $path;
        }
        if (str_starts_with($path, '?')) {
            return ($prefix === '' ? '/' : $prefix . '/') . $path;
        }

        $path = ltrim($path, '/');
        return ($prefix === '' ? '/' : $prefix . '/') . $path;
    }
}

if (!function_exists('lex_dashboard_url')) {
    function lex_dashboard_url(): string
    {
        return lex_app_url('go.php');
    }
}

if (!function_exists('lex_relative_app_location')) {
    /**
     * Relative Location from the current request URI to a file under the app root.
     * /lexshield/auth/login.php + go.php → "../go.php"
     */
    function lex_relative_app_location(string $currentUri, string $appRelativeFile): string
    {
        $appRelativeFile = ltrim(str_replace('\\', '/', $appRelativeFile), '/');
        $current = str_replace('\\', '/', $currentUri);
        $cut = strpos($current, '?');
        if ($cut !== false) {
            $current = substr($current, 0, $cut);
        }
        if ($current === '' || $current === '/') {
            return lex_app_url($appRelativeFile);
        }

        $currentDir = str_ends_with($current, '/') ? rtrim($current, '/') : dirname($current);
        if ($currentDir === '\\' || $currentDir === '.') {
            $currentDir = '/';
        }

        $base = lex_uri_folder_prefix($current);
        if ($base === '') {
            $base = lex_app_base_path();
        }
        $target = ($base === '' ? '' : $base) . '/' . $appRelativeFile;

        $fromParts = array_values(array_filter(
            explode('/', trim($currentDir, '/')),
            static fn ($part) => $part !== '' && $part !== '.'
        ));
        $toParts = array_values(array_filter(
            explode('/', trim($target, '/')),
            static fn ($part) => $part !== '' && $part !== '.'
        ));

        $i = 0;
        $limit = min(count($fromParts), count($toParts));
        while ($i < $limit && strcasecmp((string) $fromParts[$i], (string) $toParts[$i]) === 0) {
            $i++;
        }
        $relative = str_repeat('../', max(0, count($fromParts) - $i)) . implode('/', array_slice($toParts, $i));
        if ($relative === '') {
            $relative = basename($appRelativeFile);
        }
        return $relative;
    }
}

if (!function_exists('lex_nav_href')) {
    /**
     * In-page link that stays under /lexshield (relative to the current URL).
     * Sidebar "Find a Lawyer" from /lexshield/go.php → client/lawyers.php
     */
    function lex_nav_href(string $appRelativeFile): string
    {
        $hash = '';
        $query = '';
        $cutHash = strpos($appRelativeFile, '#');
        if ($cutHash !== false) {
            $hash = substr($appRelativeFile, $cutHash);
            $appRelativeFile = substr($appRelativeFile, 0, $cutHash);
        }
        $cutQuery = strpos($appRelativeFile, '?');
        if ($cutQuery !== false) {
            $query = substr($appRelativeFile, $cutQuery);
            $appRelativeFile = substr($appRelativeFile, 0, $cutQuery);
        }

        $raw = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($raw === '') {
            $raw = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        }
        if ($raw === '' || $raw === '/') {
            return lex_app_url($appRelativeFile) . $query . $hash;
        }
        return lex_relative_app_location($raw, $appRelativeFile) . $query . $hash;
    }
}

if (!function_exists('lex_redirect_app_file')) {
    /**
     * HTTP redirect to a file under the app root using a relative Location.
     * Apache then stays in /lexshield even when APP_BASE_PATH is empty.
     * From /lexshield/auth/login.php → ../go.php
     * which the browser resolves to /lexshield/go.php.
     */
    function lex_redirect_app_file(string $appRelativeFile): void
    {
        $raw = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($raw === '') {
            $raw = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        }
        if ($raw === '' || $raw === '/') {
            header('Location: ' . lex_app_url($appRelativeFile));
            exit;
        }
        header('Location: ' . lex_relative_app_location($raw, $appRelativeFile));
        exit;
    }
}

if (!function_exists('lex_absolute_url')) {
    /**
     * Fully-qualified URL for emails and other off-site contexts.
     */
    function lex_absolute_url(string $path = ''): string
    {
        return rtrim(lex_app_origin(), '/') . lex_app_url($path);
    }
}

if (!function_exists('lex_asset_url')) {
    function lex_asset_url(string $path): string
    {
        $absolute = dirname(__DIR__) . '/' . ltrim($path, '/');
        $version = is_file($absolute) ? (string) filemtime($absolute) : '1';
        return lex_app_url($path) . '?v=' . $version;
    }
}

if (!function_exists('lex_storage_path')) {
    /**
     * Filesystem path under the app's private storage directory (never
     * served directly by the web server). Used for encryption keys and any
     * uploaded file that must go through an access-controlled PHP gate
     * (payment proofs, case file vault documents, message attachments).
     */
    function lex_storage_path(string $path = ''): string
    {
        $root = dirname(__DIR__) . '/storage';
        $path = ltrim($path, '/');
        return $path === '' ? $root : $root . '/' . $path;
    }
}

if (!function_exists('lex_storage_ensure_dir')) {
    function lex_storage_ensure_dir(string $path): string
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create folder: ' . $path);
            }
        }
        return $path;
    }
}

if (!function_exists('lex_app_encryption_key')) {
    /**
     * A stable, per-install secret used for HMAC signing (login OTP/browser
     * cookies) and for AES-256-GCM encryption of case file vault documents.
     *
     * Resolution order: APP_KEY env var (recommended for production) -> a
     * previously generated key persisted at storage/app/encryption.key ->
     * a freshly generated 256-bit random key written to that file (0600).
     * This means the app is secure by default even if an operator forgets
     * to set APP_KEY, while still allowing production deployments to pin
     * an explicit key via the environment.
     */
    function lex_app_encryption_key(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }

        $envKey = lex_env('APP_KEY', '');
        if ($envKey !== null && $envKey !== '') {
            $key = $envKey;
            return $key;
        }

        $keyFile = lex_storage_path('app/encryption.key');
        if (is_file($keyFile)) {
            $stored = trim((string) file_get_contents($keyFile));
            if ($stored !== '') {
                $key = $stored;
                return $key;
            }
        }

        $generated = bin2hex(random_bytes(32));
        lex_storage_ensure_dir(dirname($keyFile));
        file_put_contents($keyFile, $generated, LOCK_EX);
        @chmod($keyFile, 0600);
        $key = $generated;
        return $key;
    }
}

if (!function_exists('lex_sql_statements')) {
    /**
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
