<?php

declare(strict_types=1);

/**
 * Regenerates config/ensure_dashboards.php from the live client/lawyer/admin PHP files.
 * php scripts/build_ensure_dashboards.php
 */

$root = dirname(__DIR__);
$files = [];

$add = static function (string $relative) use ($root, &$files): void {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$relative}\n");
        return;
    }
    $files[$relative] = base64_encode(gzdeflate((string) file_get_contents($path), 9));
};

foreach (['client', 'lawyer', 'admin'] as $role) {
    foreach (glob($root . DIRECTORY_SEPARATOR . $role . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
        $add($role . '/' . basename($path));
    }
}
$add('case_files.php');
$add('chat.php');
$add('go.php');

$mapLines = [];
foreach ($files as $relative => $b64) {
    $mapLines[] = '            ' . var_export($relative, true) . ' => ' . var_export($b64, true) . ',';
}
$mapBody = implode("\n", $mapLines);

$out = <<<PHP
<?php

declare(strict_types=1);

/**
 * Writes portal PHP pages onto disk when they are missing or are not real PHP.
 * Called from bootstrap so XAMPP copies get client/lawyers.php and the rest.
 */
if (!function_exists('lex_dashboard_is_real')) {
    function lex_dashboard_is_real(string \$path): bool
    {
        if (!is_file(\$path) || !is_readable(\$path) || filesize(\$path) < 80) {
            return false;
        }
        \$head = (string) file_get_contents(\$path, false, null, 0, 500);
        return str_contains(\$head, '<?php')
            && (
                str_contains(\$head, 'lex_require_role')
                || str_contains(\$head, 'lex_page_header')
                || str_contains(\$head, 'bootstrap.php')
            );
    }
}

if (!function_exists('lex_portal_payloads')) {
    /**
     * @return array<string, string> relative path => gzdeflate+base64 source
     */
    function lex_portal_payloads(): array
    {
        return [
{$mapBody}
        ];
    }
}

if (!function_exists('lex_dashboard_payload')) {
    function lex_dashboard_payload(string \$role): string
    {
        \$encoded = lex_portal_payloads()[\$role . '/index.php'] ?? '';
        if (\$encoded === '') {
            return '';
        }
        \$raw = base64_decode(\$encoded, true);
        if (!is_string(\$raw) || \$raw === '') {
            return '';
        }
        \$source = @gzinflate(\$raw);
        return is_string(\$source) ? \$source : '';
    }
}

if (!function_exists('lex_ensure_role_dashboards')) {
    function lex_ensure_role_dashboards(): void
    {
        \$root = dirname(__DIR__);
        foreach (lex_portal_payloads() as \$relative => \$encoded) {
            \$relative = str_replace(['\\\\', '..'], ['/', ''], \$relative);
            \$file = \$root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, \$relative);
            if (lex_dashboard_is_real(\$file)) {
                continue;
            }
            \$raw = base64_decode(\$encoded, true);
            \$source = is_string(\$raw) && \$raw !== '' ? @gzinflate(\$raw) : false;
            if (!is_string(\$source) || \$source === '') {
                continue;
            }
            \$dir = dirname(\$file);
            if (!is_dir(\$dir)) {
                @mkdir(\$dir, 0775, true);
            }
            if (is_dir(\$dir)) {
                @file_put_contents(\$file, \$source);
            }
        }
    }
}

lex_ensure_role_dashboards();
PHP;

file_put_contents($root . '/config/ensure_dashboards.php', $out);
echo 'packed ' . count($files) . " files into config/ensure_dashboards.php, " . strlen($out) . " bytes\n";

$pack = <<<PHP
<?php

declare(strict_types=1);

/**
 * Embedded client/lawyer/admin page sources. Keep this next to serve_portal.php.
 */
if (!function_exists('lex_auth_portal_payloads')) {
    /**
     * @return array<string, string>
     */
    function lex_auth_portal_payloads(): array
    {
        return [
{$mapBody}
        ];
    }
}

if (!function_exists('lex_auth_portal_source')) {
    function lex_auth_portal_source(string \$relative): string
    {
        \$relative = str_replace('\\\\', '/', \$relative);
        \$encoded = lex_auth_portal_payloads()[\$relative] ?? '';
        if (\$encoded === '') {
            return '';
        }
        \$raw = base64_decode(\$encoded, true);
        if (!is_string(\$raw) || \$raw === '') {
            return '';
        }
        \$source = @gzinflate(\$raw);
        return is_string(\$source) ? \$source : '';
    }
}
PHP;

file_put_contents($root . '/auth/portal_pack.php', $pack);
echo 'wrote auth/portal_pack.php (' . strlen($pack) . " bytes)\n";
