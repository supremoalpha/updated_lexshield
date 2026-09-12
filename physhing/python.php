<?php

declare(strict_types=1);

/**
 * Optional Python engine. Never probes missing binaries — that leaks PHP warnings on XAMPP.
 */

if (!function_exists('lex_phishing_python_script_path')) {
    function lex_phishing_python_script_path(): string
    {
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'phishing' . DIRECTORY_SEPARATOR . 'detect.py';
        if (is_file($file) && str_contains((string) @file_get_contents($file), 'LEXSHIELD_PYTHON_PHISHING')) {
            return $file;
        }
        if (function_exists('lex_write_packed_app_file')) {
            lex_write_packed_app_file('phishing/detect.py');
        }
        return is_file($file) ? $file : '';
    }
}

if (!function_exists('lex_phishing_python_is_real_binary')) {
    function lex_phishing_python_is_real_binary(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            return false;
        }
        $size = (int) @filesize($path);
        if ($size > 0 && $size < 4096) {
            return false;
        }
        $name = strtolower(basename($path));
        return str_contains($name, 'python') || $name === 'py.exe' || $name === 'py';
    }
}

if (!function_exists('lex_phishing_python_binaries')) {
    /**
     * @return list<string>
     */
    function lex_phishing_python_binaries(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $found = [];
        $pathEnv = (string) getenv('PATH');
        $parts = preg_split(PHP_OS_FAMILY === 'Windows' ? '/;/' : '/:/', $pathEnv) ?: [];
        $names = PHP_OS_FAMILY === 'Windows'
            ? ['python.exe', 'python3.exe', 'py.exe']
            : ['python3', 'python'];

        foreach ($parts as $dir) {
            $dir = trim((string) $dir);
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $name;
                if (lex_phishing_python_is_real_binary($candidate)) {
                    $found[] = $candidate;
                }
            }
        }

        $cached = array_values(array_unique($found));
        return $cached;
    }
}

if (!function_exists('lex_phishing_python_command')) {
    /**
     * @return list<string>
     */
    function lex_phishing_python_command(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        if (!function_exists('proc_open')) {
            $cached = [];
            return $cached;
        }

        foreach (lex_phishing_python_binaries() as $binary) {
            $probe = [$binary, '-c', 'print(3)'];
            $buffer = '';
            try {
                ob_start();
                $process = @proc_open($probe, [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, null, null, ['bypass_shell' => true]);
                $buffer = (string) ob_get_clean();
            } catch (Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                continue;
            }
            if ($buffer !== '' || !is_resource($process)) {
                if (is_resource($process)) {
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                }
                continue;
            }
            $out = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if ($code === 0 && $out === '3') {
                $cached = [$binary];
                return $cached;
            }
        }

        $cached = [];
        return $cached;
    }
}

if (!function_exists('lex_phishing_python_scan')) {
    /**
     * @return array{status?:string,score?:int,risk?:int,message?:string,findings?:list<string>,engine?:string}|null
     */
    function lex_phishing_python_scan(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !function_exists('proc_open')) {
            return null;
        }

        $script = lex_phishing_python_script_path();
        $python = lex_phishing_python_command();
        if ($script === '' || $python === [] || !is_file($script)) {
            return null;
        }

        $cmd = $python;
        $cmd[] = $script;
        $cmd[] = $url;

        $buffer = '';
        try {
            ob_start();
            $process = @proc_open($cmd, [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname($script), null, ['bypass_shell' => true]);
            $buffer = (string) ob_get_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return null;
        }
        if ($buffer !== '' || !is_resource($process)) {
            if (is_resource($process)) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
            return null;
        }

        stream_set_timeout($pipes[1], 3);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $start = strpos($stdout, '{');
        $end = strrpos($stdout, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($stdout, $start, $end - $start + 1), true);
        if (!is_array($decoded) || !isset($decoded['status'])) {
            return null;
        }

        return $decoded;
    }
}

if (!function_exists('lex_phishing_merge_python')) {
    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    function lex_phishing_merge_python(string $url, array $response): array
    {
        $engines = ['php'];
        $python = lex_phishing_python_scan($url);
        if (!is_array($python)) {
            $response['engines'] = $engines;
            return $response;
        }

        $engines[] = 'python';
        $findings = array_values(array_filter(
            array_map('strval', (array) ($response['findings'] ?? [])),
            static fn (string $item): bool => $item !== ''
        ));
        foreach ((array) ($python['findings'] ?? []) as $finding) {
            $finding = trim((string) $finding);
            if ($finding !== '' && !in_array($finding, $findings, true)) {
                $findings[] = $finding;
            }
        }

        $phpStatus = (string) ($response['status'] ?? 'suspicious');
        $pyStatus = (string) ($python['status'] ?? 'suspicious');
        $rank = ['safe' => 0, 'suspicious' => 1, 'phishing' => 2];
        $status = ($rank[$pyStatus] ?? 1) > ($rank[$phpStatus] ?? 1) ? $pyStatus : $phpStatus;

        $phpRisk = (int) ($response['score'] ?? 0);
        if ($phpStatus === 'safe') {
            $phpRisk = max(0, 100 - $phpRisk);
        }
        $pyRisk = (int) ($python['risk'] ?? ($python['score'] ?? 0));
        $risk = max($phpRisk, $pyRisk);

        if ($status === 'phishing') {
            $score = min(99, max(55, $risk));
            $message = (string) ($response['message'] ?? '');
            if ($pyStatus === 'phishing' && ($rank[$phpStatus] ?? 0) < 2) {
                $message = (string) ($python['message'] ?? $message);
            }
        } elseif ($status === 'suspicious') {
            $score = max(25, $risk);
            $message = (string) ($response['message'] ?? $python['message'] ?? 'Some suspicious URL patterns were detected.');
        } else {
            $score = max(90, (int) ($response['score'] ?? 90));
            $message = (string) ($response['message'] ?? 'No strong phishing indicators detected.');
        }

        $response['status'] = $status;
        $response['score'] = $score;
        $response['message'] = $message !== '' ? $message : 'The scan completed.';
        $response['findings'] = $findings;
        $response['engines'] = $engines;
        return $response;
    }
}
