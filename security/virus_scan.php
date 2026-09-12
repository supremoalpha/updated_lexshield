<?php

declare(strict_types=1);

/**
 * ClamAV CVD virus scan for uploads.
 * Uses storage/clamav_db/*.cvd (daily.cvd, bytecode.cvd, main.cvd).
 */

if (!function_exists('lex_clamav_database_dir')) {
    function lex_clamav_database_dir(): string
    {
        $configured = trim((string) (lex_env('CLAMAV_DATABASE', '') ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }
        return lex_storage_path('clamav_db');
    }
}

if (!function_exists('lex_clamscan_binary')) {
    function lex_clamscan_binary(): string
    {
        $configured = trim((string) (lex_env('CLAMSCAN_PATH', '') ?? ''));
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $candidates = [
            '/usr/bin/clamscan',
            '/usr/local/bin/clamscan',
            'C:\\Program Files\\ClamAV\\clamscan.exe',
            'C:\\xampp\\clamav\\clamscan.exe',
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string) shell_exec('command -v clamscan 2>/dev/null || where clamscan 2>nul'));
        $first = strtok($which, "\r\n") ?: '';
        return is_file($first) ? $first : '';
    }
}

if (!function_exists('lex_freshclam_binary')) {
    /**
     * Locate ClamAV's own database updater. Preferred over our raw curl
     * download because it is the exact client the ClamAV CDN expects, and
     * is much less likely to be blocked as a bot by Cloudflare.
     */
    function lex_freshclam_binary(): string
    {
        $configured = trim((string) (lex_env('FRESHCLAM_PATH', '') ?? ''));
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        // If clamscan was found, freshclam is almost always right next to it.
        $clam = lex_clamscan_binary();
        if ($clam !== '') {
            $dir = dirname($clam);
            $sibling = $dir . DIRECTORY_SEPARATOR . (str_ends_with(strtolower($clam), '.exe') ? 'freshclam.exe' : 'freshclam');
            if (is_file($sibling) && is_executable($sibling)) {
                return $sibling;
            }
        }

        $candidates = [
            '/usr/bin/freshclam',
            '/usr/local/bin/freshclam',
            'C:\\Program Files\\ClamAV\\freshclam.exe',
            'C:\\xampp\\clamav\\freshclam.exe',
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string) shell_exec('command -v freshclam 2>/dev/null || where freshclam 2>nul'));
        $first = strtok($which, "\r\n") ?: '';
        return is_file($first) ? $first : '';
    }
}

if (!function_exists('lex_cvd_format_size')) {
    function lex_cvd_format_size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$unit];
    }
}

if (!function_exists('lex_cvd_notes')) {
    /**
     * @return array<string, string>
     */
    function lex_cvd_notes(): array
    {
        return [
            'main.cvd' => 'Core signature set. Large; rarely changes.',
            'main.cld' => 'Core signature set (uncompressed copy).',
            'daily.cvd' => 'Daily signature updates. Small; changes often.',
            'daily.cld' => 'Daily signature updates (uncompressed copy).',
            'bytecode.cvd' => 'Bytecode detection rules for newer threats.',
            'bytecode.cld' => 'Bytecode detection rules (uncompressed copy).',
        ];
    }
}

if (!function_exists('lex_cvd_parse_file')) {
    /**
     * Read a ClamAV .cvd/.cld header and return its metadata.
     * CVD header spec: ClamAV-VDB:build_time:version:sig_count:func_level:md5:dsig:builder:build_unix
     *
     * @return array{name:string, version:string, signatures:string, built:string, size:string, note:string}|null
     */
    function lex_cvd_parse_file(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 512);
        fclose($handle);

        $name = basename($path);
        $notes = lex_cvd_notes();
        $note = $notes[$name] ?? 'ClamAV signature database file.';
        $size = lex_cvd_format_size((int) (filesize($path) ?: 0));

        $fields = explode(':', trim($head));
        if (count($fields) < 4 || $fields[0] !== 'ClamAV-VDB') {
            return [
                'name' => $name,
                'version' => '—',
                'signatures' => '—',
                'built' => '—',
                'size' => $size,
                'note' => $note,
            ];
        }

        return [
            'name' => $name,
            'version' => trim($fields[2] ?? '') !== '' ? trim($fields[2]) : '—',
            'signatures' => trim($fields[3] ?? '') !== '' ? number_format((int) trim($fields[3])) : '—',
            'built' => trim($fields[1] ?? '') !== '' ? trim($fields[1]) : '—',
            'size' => $size,
            'note' => $note,
        ];
    }
}

if (!function_exists('lex_virus_scan_status')) {
    /**
     * @return array{ready:bool, clamscan:string, database:string, has_cvd:bool, message:string, files:array<int, array<string, string>>}
     */
    function lex_virus_scan_status(): array
    {
        $db = lex_clamav_database_dir();
        $clam = lex_clamscan_binary();
        $hasCvd = is_file($db . '/daily.cvd') || is_file($db . '/main.cvd') || is_file($db . '/main.cld');
        $enabled = filter_var(lex_env('VIRUS_SCAN_ENABLED', 'true') ?: 'true', FILTER_VALIDATE_BOOL);
        $ready = $enabled && $clam !== '' && $hasCvd;
        $message = !$enabled
            ? 'Virus scanning is turned off in .env.'
            : ($clam === ''
                ? 'ClamAV (clamscan) is not installed. CVD files are present but cannot be used yet.'
                : ($hasCvd
                    ? 'Uploads are scanned with the ClamAV CVD virus database.'
                    : 'ClamAV is installed, but no .cvd files were found in storage/clamav_db.'));

        $files = [];
        if (is_dir($db)) {
            foreach (['main.cvd', 'main.cld', 'daily.cvd', 'daily.cld', 'bytecode.cvd', 'bytecode.cld'] as $candidate) {
                $parsed = lex_cvd_parse_file($db . '/' . $candidate);
                if ($parsed !== null) {
                    $files[] = $parsed;
                }
            }
        }

        return [
            'ready' => $ready,
            'clamscan' => $clam,
            'database' => $db,
            'has_cvd' => $hasCvd,
            'message' => $message,
            'files' => $files,
        ];
    }
}

if (!function_exists('lex_cvd_write_readable_index')) {
    /**
     * Write a plain-text WHAT_IS_INSIDE.txt summary next to the .cvd files so an
     * admin who double-clicks the database folder isn't confused by binary files.
     */
    function lex_cvd_write_readable_index(): void
    {
        $db = lex_clamav_database_dir();
        if (!is_dir($db)) {
            @mkdir($db, 0775, true);
        }
        if (!is_dir($db) || !is_writable($db)) {
            return;
        }

        $lines = [
            'This folder holds ClamAV virus-signature databases (.cvd files).',
            'They are binary files, not documents - do not open them in Word, Notepad, or VS Code.',
            'LEXSHIELD reads them automatically to scan uploaded files for malware.',
            '',
            'Generated: ' . date('Y-m-d H:i:s'),
            '',
        ];

        $notes = lex_cvd_notes();
        foreach (['main.cvd', 'main.cld', 'daily.cvd', 'daily.cld', 'bytecode.cvd', 'bytecode.cld'] as $name) {
            $parsed = lex_cvd_parse_file($db . '/' . $name);
            if ($parsed === null) {
                continue;
            }
            $lines[] = $name;
            $lines[] = '  Version:    ' . $parsed['version'];
            $lines[] = '  Signatures: ' . $parsed['signatures'];
            $lines[] = '  Built:      ' . $parsed['built'];
            $lines[] = '  Size:       ' . $parsed['size'];
            $lines[] = '  What it is: ' . ($notes[$name] ?? '');
            $lines[] = '';
        }

        @file_put_contents($db . '/WHAT_IS_INSIDE.txt', implode("\n", $lines) . "\n");
    }
}

if (!function_exists('lex_cvd_download_via_freshclam')) {
    /**
     * Try updating the CVD files using ClamAV's own freshclam tool, which is
     * the client database.clamav.net actually expects. Returns the list of
     * files present after the run, or null if freshclam isn't available.
     *
     * @return string[]|null
     */
    function lex_cvd_download_via_freshclam(string $db, bool $includeMain): ?array
    {
        $freshclam = lex_freshclam_binary();
        if ($freshclam === '') {
            return null;
        }

        $before = [];
        foreach (['daily.cvd', 'bytecode.cvd', 'main.cvd'] as $name) {
            $path = $db . '/' . $name;
            $before[$name] = is_file($path) ? filemtime($path) : null;
        }

        $cmd = [
            $freshclam,
            '--datadir=' . $db,
            '--stdout',
            '--no-warnings',
        ];
        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $names = ['daily.cvd', 'bytecode.cvd'];
        if ($includeMain) {
            $names[] = 'main.cvd';
        }
        $updated = [];
        foreach ($names as $name) {
            $path = $db . '/' . $name;
            if (is_file($path)) {
                $updated[] = $name;
            }
        }

        if ($exit !== 0 && $updated === []) {
            $message = trim($stdout . "\n" . $stderr);
            throw new RuntimeException('freshclam failed: ' . ($message !== '' ? $message : 'unknown error (exit ' . $exit . ')'));
        }

        return $updated;
    }
}

if (!function_exists('lex_cvd_download_via_curl')) {
    /**
     * Fallback: download the CVD files directly over HTTPS. Some hosts see
     * database.clamav.net's Cloudflare bot protection block this with a 403 -
     * if that happens, install ClamAV (which includes freshclam) or download
     * the files with a regular browser and place them in storage/clamav_db.
     *
     * @return string[]
     * @throws RuntimeException on network failure or an invalid response
     */
    function lex_cvd_download_via_curl(string $db, bool $includeMain): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP curl extension is required to download the virus database.');
        }

        $mirror = 'https://database.clamav.net/';
        $names = ['daily.cvd', 'bytecode.cvd'];
        if ($includeMain) {
            $names[] = 'main.cvd';
        }

        $saved = [];
        foreach ($names as $name) {
            $tmpPath = $db . '/.' . $name . '.download';
            $handle = @fopen($tmpPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Could not open a temporary file for ' . $name . '.');
            }

            $ch = curl_init($mirror . $name);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 180,
                // A ClamAV-style UA matches what this host actually expects to
                // see; a custom app UA is more likely to be treated as a bot.
                CURLOPT_USERAGENT => 'ClamAV/0.103.11 (OS: Win32, ARCH: x86_64, CPU: x86_64)',
                CURLOPT_HTTPHEADER => ['Accept: */*'],
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);
            $ok = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($handle);

            if ($ok === false || $httpCode !== 200) {
                @unlink($tmpPath);
                $reason = $error !== '' ? $error : 'HTTP ' . $httpCode;
                if ($httpCode === 403) {
                    $reason .= ' - the site is likely blocking automated downloads. Install ClamAV (includes freshclam) or download the file manually with a browser and place it in ' . $db . '.';
                }
                throw new RuntimeException('Download failed for ' . $name . ' (' . $reason . ').');
            }

            $head = (string) file_get_contents($tmpPath, false, null, 0, 32);
            if (strpos($head, 'ClamAV-VDB') !== 0) {
                @unlink($tmpPath);
                throw new RuntimeException('The download for ' . $name . ' did not look like a valid CVD file.');
            }

            if (!@rename($tmpPath, $db . '/' . $name)) {
                @unlink($tmpPath);
                throw new RuntimeException('Could not save ' . $name . ' to the database folder.');
            }
            $saved[] = $name;
        }

        return $saved;
    }
}

if (!function_exists('lex_cvd_download_official')) {
    /**
     * Update the CVD files in storage/clamav_db. Prefers freshclam (the
     * official ClamAV updater) when it is installed, since it is far less
     * likely to be blocked than a raw HTTP download; falls back to a direct
     * HTTPS download otherwise.
     *
     * @return string[] names of the files that were saved/updated
     * @throws RuntimeException on failure
     */
    function lex_cvd_download_official(bool $includeMain = false): array
    {
        $db = lex_clamav_database_dir();
        if (!is_dir($db) && !@mkdir($db, 0775, true) && !is_dir($db)) {
            throw new RuntimeException('Could not create the database folder: ' . $db);
        }
        if (!is_writable($db)) {
            throw new RuntimeException('The database folder is not writable: ' . $db);
        }

        $saved = lex_cvd_download_via_freshclam($db, $includeMain);
        if ($saved === null) {
            $saved = lex_cvd_download_via_curl($db, $includeMain);
        }

        try {
            lex_cvd_write_readable_index();
        } catch (Throwable $e) {
            // Best-effort only.
        }

        return $saved;
    }
}

if (!function_exists('lex_virus_scan_file')) {
    /**
     * Scan a file on disk with ClamAV using the project CVD database.
     * Throws RuntimeException if the file is infected or scanning is required and unavailable.
     */
    function lex_virus_scan_file(string $path, string $originalName = ''): void
    {
        $enabled = filter_var(lex_env('VIRUS_SCAN_ENABLED', 'true') ?: 'true', FILTER_VALIDATE_BOOL);
        if (!$enabled) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The file could not be scanned.');
        }

        $required = filter_var(lex_env('VIRUS_SCAN_REQUIRED', 'false') ?: 'false', FILTER_VALIDATE_BOOL);
        $status = lex_virus_scan_status();
        if (!$status['ready']) {
            if ($required) {
                throw new RuntimeException('Virus scanning is required but ClamAV/CVD is not ready.');
            }
            return;
        }

        $cmd = [
            $status['clamscan'],
            '--no-summary',
            '--infected',
            '--stdout',
            '--database=' . $status['database'],
            '--max-filesize=30M',
            '--max-scansize=30M',
            $path,
        ];
        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            if ($required) {
                throw new RuntimeException('Virus scanning failed to start.');
            }
            return;
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $line = trim($stdout . "\n" . $stderr);
        $label = $originalName !== '' ? $originalName : basename($path);

        if ($exit === 1 || preg_match('/\bFOUND\b/', $line) === 1) {
            $signature = 'malware';
            if (preg_match('/:\s*(.+)\s+FOUND/', $line, $match) === 1) {
                $signature = trim($match[1]);
            }
            try {
                lex_audit('virus_detected', 'uploads', $signature);
            } catch (Throwable $e) {
                // Audit is best-effort.
            }
            throw new RuntimeException('This file is blocked by the virus scanner (' . $signature . ').');
        }

        if ($exit !== 0 && $required) {
            throw new RuntimeException('Virus scanning failed for "' . $label . '". Try again or ask an administrator.');
        }
    }
}

if (!function_exists('lex_virus_scan_upload')) {
    /**
     * @param array<string, mixed> $file $_FILES entry
     */
    function lex_virus_scan_upload(array $file): void
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return;
        }
        lex_virus_scan_file($tmp, lex_sanitize_filename(basename((string) ($file['name'] ?? 'upload'))));
    }
}