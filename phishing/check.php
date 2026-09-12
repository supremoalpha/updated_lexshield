<?php
declare(strict_types=1);

if (!defined('LEX_JSON_API')) {
    define('LEX_JSON_API', true);
}
if (!defined('LEX_PHISHING_DETECTOR_STANDALONE')) {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../config/bootstrap.php';
}

$lexPhishingPython = __DIR__ . DIRECTORY_SEPARATOR . 'python.php';
if (is_file($lexPhishingPython)) {
    require_once $lexPhishingPython;
}

function lex_phishing_confusable_skeleton(string $value): string
{
    return strtr(strtolower($value), [
        '0' => 'o',
        '1' => 'l',
        '3' => 'e',
        '4' => 'a',
        '5' => 's',
        '7' => 't',
        '8' => 'b',
        'i' => 'l',
    ]);
}

function lex_phishing_normalize_host(string $host): string
{
    $host = trim(strtolower($host));
    $host = trim($host, " \t\n\r\0\x0B.");
    if ($host === '') {
        return '';
    }
    if (function_exists('idn_to_ascii') && !filter_var($host, FILTER_VALIDATE_IP)) {
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii) && $ascii !== '') {
            $host = strtolower($ascii);
        }
    }

    return $host;
}

function lex_phishing_public_suffixes(): array
{
    return [
        'ac.uk', 'co.uk', 'gov.uk', 'ltd.uk', 'me.uk', 'net.uk', 'nhs.uk', 'org.uk', 'plc.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
        'co.nz', 'org.nz', 'net.nz', 'ac.nz', 'govt.nz',
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp',
        'com.ph', 'net.ph', 'org.ph', 'gov.ph', 'edu.ph',
        'com.br', 'com.cn', 'com.hk', 'com.sg', 'com.my', 'co.in', 'co.kr', 'com.mx', 'com.tr',
    ];
}

function lex_phishing_registrable_domain(string $host): string
{
    $host = lex_phishing_normalize_host($host);
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }

    $labels = array_values(array_filter(explode('.', $host), static fn (string $label): bool => $label !== ''));
    $labelCount = count($labels);
    if ($labelCount <= 2) {
        return implode('.', $labels);
    }

    $publicSuffixes = lex_phishing_public_suffixes();
    for ($length = min(3, $labelCount); $length >= 2; $length--) {
        $suffix = implode('.', array_slice($labels, -$length));
        if (in_array($suffix, $publicSuffixes, true)) {
            $registrableLabels = array_slice($labels, -($length + 1));
            return implode('.', $registrableLabels);
        }
    }

    return implode('.', array_slice($labels, -2));
}

function lex_phishing_subdomain_part(string $host, string $registrableDomain): string
{
    if ($registrableDomain === '' || $host === $registrableDomain || !str_ends_with($host, '.' . $registrableDomain)) {
        return '';
    }

    return substr($host, 0, -(strlen($registrableDomain) + 1));
}

function lex_phishing_brand_domains(): array
{
    return [
        'paypal' => 'paypal.com',
        'google' => 'google.com',
        'microsoft' => 'microsoft.com',
        'facebook' => 'facebook.com',
        'apple' => 'apple.com',
        'gcash' => 'gcash.com',
        'lexshield' => 'lexshield.com',
        'netflix' => 'netflix.com',
    ];
}

function lex_phishing_tokenize_host_part(string $value): array
{
    $tokens = preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique($tokens));
}

function lex_phishing_brand_impersonation(string $host, string $registrableDomain, array $brandDomains): array
{
    $signals = [];
    if ($host === '' || $registrableDomain === '') {
        return $signals;
    }

    $subdomain = lex_phishing_subdomain_part($host, $registrableDomain);
    $subdomainTokens = lex_phishing_tokenize_host_part($subdomain);
    $hostLabels = explode('.', $host);

    foreach ($brandDomains as $brand => $trustedDomain) {
        if ($registrableDomain === $trustedDomain) {
            continue;
        }

        $trustedLabels = explode('.', $trustedDomain);
        $trustedLabelCount = count($trustedLabels);
        $containsTrustedDomainSequence = false;
        for ($i = 0, $max = count($hostLabels) - $trustedLabelCount; $i <= $max; $i++) {
            if (array_slice($hostLabels, $i, $trustedLabelCount) === $trustedLabels) {
                $containsTrustedDomainSequence = true;
                break;
            }
        }

        if ($containsTrustedDomainSequence || in_array($brand, $subdomainTokens, true)) {
            $signals[] = [
                'brand' => $brand,
                'trusted_domain' => $trustedDomain,
                'registrable_domain' => $registrableDomain,
            ];
        }
    }

    return $signals;
}

function lex_phishing_lookalike_brand(string $host, array $brands, array $trustedDomains): string
{
    $host = lex_phishing_normalize_host($host);
    $registrableDomain = lex_phishing_registrable_domain($host);
    foreach ($trustedDomains as $trustedDomain) {
        if ($registrableDomain === $trustedDomain) {
            return '';
        }
    }

    $labels = [];
    $subdomain = lex_phishing_subdomain_part($host, $registrableDomain);
    if ($subdomain !== '') {
        $labels = array_merge($labels, explode('.', $subdomain));
    }
    $registrableLabels = explode('.', $registrableDomain);
    if ($registrableLabels !== []) {
        $labels[] = (string) $registrableLabels[0];
    }
    $candidates = [];
    foreach ($labels as $label) {
        foreach (preg_split('/[^a-z0-9]+/', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $candidates[] = $token;
        }
        $candidates[] = preg_replace('/[^a-z0-9]/', '', $label) ?? '';
    }

    foreach (array_unique(array_filter($candidates)) as $candidate) {
        $skeleton = lex_phishing_confusable_skeleton($candidate);
        foreach ($brands as $brand) {
            if ($candidate === $brand) {
                continue;
            }
            $distance = levenshtein($skeleton, lex_phishing_confusable_skeleton($brand));
            $limit = strlen($brand) <= 6 ? 1 : 2;
            if ($skeleton === $brand || $distance <= $limit) {
                return $brand;
            }
        }
    }

    return '';
}

function lex_phishing_dns_records(string $host): array
{
    $records = [];
    $types = [];
    if (defined('DNS_A')) {
        $types[] = (int) DNS_A;
    }
    if (defined('DNS_AAAA')) {
        $types[] = (int) DNS_AAAA;
    }
    foreach ($types as $type) {
        try {
            $chunk = @dns_get_record($host, $type);
            if (is_array($chunk) && $chunk !== []) {
                $records = array_merge($records, $chunk);
            }
        } catch (Throwable $e) {
            // Windows PHP cannot combine some DNS types and may throw.
        }
    }

    return $records;
}

function lex_phishing_ip_is_public(string $ip): bool
{
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

function lex_phishing_url_publicly_fetchable(string $url): array
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = lex_phishing_normalize_host((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return ['allowed' => false, 'error' => 'Redirect checking skipped an invalid URL.'];
    }

    if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.localhost')) {
        return ['allowed' => false, 'error' => 'Redirect checking blocked an internal hostname.'];
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return lex_phishing_ip_is_public($host)
            ? ['allowed' => true, 'error' => '']
            : ['allowed' => false, 'error' => 'Redirect checking blocked an internal or reserved IP address.'];
    }

    $records = lex_phishing_dns_records($host);
    if (!is_array($records) || $records === []) {
        return ['allowed' => false, 'error' => 'Unable to resolve the host for redirect checking.'];
    }

    foreach ($records as $record) {
        $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip !== '' && !lex_phishing_ip_is_public($ip)) {
            return ['allowed' => false, 'error' => 'Redirect checking blocked an internal or reserved network destination.'];
        }
    }

    return ['allowed' => true, 'error' => ''];
}

function lex_phishing_resolve_redirects(string $url): array
{
    if (!function_exists('curl_init')) {
        return [
            'final_url' => $url,
            'redirect_count' => 0,
            'redirect_chain' => [$url],
            'redirect_error' => 'Redirect checking is unavailable because cURL is not enabled.',
        ];
    }

    $visited = [$url];
    $currentUrl = $url;
    $maxRedirects = 5;
    $error = '';

    for ($i = 0; $i < $maxRedirects; $i++) {
        $fetchCheck = lex_phishing_url_publicly_fetchable($currentUrl);
        if (!$fetchCheck['allowed']) {
            $error = (string) $fetchCheck['error'];
            break;
        }

        $ch = curl_init($currentUrl);
        if ($ch === false) {
            $error = 'Unable to initialize redirect check.';
            break;
        }

        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'LEXSHIELD-Phishing-Detector/1.0',
        ]);
        $headers = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($headers === false || $curlError !== '') {
            $error = 'Unable to resolve redirects for this URL.';
            break;
        }

        if ($statusCode < 300 || $statusCode >= 400) {
            break;
        }

        if (!preg_match('/^Location:\s*(.+)$/im', (string) $headers, $matches)) {
            break;
        }

        $location = trim($matches[1]);
        $nextUrl = lex_phishing_absolute_url($currentUrl, $location);
        if ($nextUrl === '' || !filter_var($nextUrl, FILTER_VALIDATE_URL)) {
            $error = 'The URL redirects to an invalid destination.';
            break;
        }
        $nextFetchCheck = lex_phishing_url_publicly_fetchable($nextUrl);
        if (!$nextFetchCheck['allowed']) {
            $error = (string) $nextFetchCheck['error'];
            break;
        }

        if (in_array($nextUrl, $visited, true)) {
            $error = 'The URL has a redirect loop.';
            break;
        }

        $visited[] = $nextUrl;
        $currentUrl = $nextUrl;
    }

    if (count($visited) > $maxRedirects && $error === '') {
        $error = 'The URL has too many redirects.';
    }

    return [
        'final_url' => $currentUrl,
        'redirect_count' => max(0, count($visited) - 1),
        'redirect_chain' => $visited,
        'redirect_error' => $error,
    ];
}

function lex_phishing_absolute_url(string $baseUrl, string $location): string
{
    if (preg_match('/^https?:\/\//i', $location)) {
        return $location;
    }

    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return '';
    }

    $scheme = (string) $base['scheme'];
    $host = (string) $base['host'];
    $port = isset($base['port']) ? ':' . (int) $base['port'] : '';
    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $scheme . '://' . $host . $port . $location;
    }

    $path = (string) ($base['path'] ?? '/');
    $directory = preg_replace('#/[^/]*$#', '/', $path) ?? '/';
    return $scheme . '://' . $host . $port . $directory . $location;
}

function lex_phishing_evaluate_url(string $url): array
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = lex_phishing_normalize_host((string) ($parts['host'] ?? ''));

    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
        return [
            'error' => 'Enter a valid http or https URL.',
        ];
    }

    $labels = explode('.', $host);
    $tld = end($labels) ?: '';
    $fullUrl = strtolower($url);
    $registrableDomain = lex_phishing_registrable_domain($host);
    $subdomainPart = lex_phishing_subdomain_part($host, $registrableDomain);
    $risk = 0;
    $findings = [];
    $suspiciousTlds = ['zip', 'mov', 'click', 'country', 'gq', 'tk', 'ml', 'cf', 'example', 'invalid', 'test', 'localhost'];
    $brandDomains = lex_phishing_brand_domains();
    $brandTerms = array_merge(array_keys($brandDomains), ['bank']);
    $lookalikeBrands = array_keys($brandDomains);
    $trustedDomains = array_values($brandDomains);
    $financialTerms = ['bank', 'billing', 'invoice', 'payment', 'pay', 'wallet', 'gcash', 'paypal', 'card', 'credit', 'loan'];
    $riskyTerms = ['login', 'verify', 'password', 'update', 'secure', 'account', 'wallet', 'payment', 'confirm', 'unlock', 'suspend', 'limited'];
    $urlShorteners = ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'cutt.ly', 'rebrand.ly', 'shorturl.at'];

    if ($scheme === 'http') {
        $risk += 28;
        $findings[] = 'The URL does not use HTTPS.';
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $risk += 28;
        $findings[] = 'The host uses a raw IP address.';
    }
    if (str_contains($host, 'xn--')) {
        $risk += 18;
        $findings[] = 'The domain contains punycode characters.';
    }
    if (strlen($host) > 45 || count($labels) > 4) {
        $risk += 12;
        $findings[] = 'The domain is unusually long or deeply nested.';
    }
    if (in_array($tld, $suspiciousTlds, true)) {
        $risk += 18;
        $findings[] = $tld === 'example'
            ? 'The .example domain is reserved for examples, not real account activity.'
            : 'The top-level domain is commonly abused in scams.';
    }
    if (preg_match('/[_.-]{2,}/', $host) || substr_count($host, '-') > 2) {
        $risk += 10;
        $findings[] = 'The domain uses unusual separator patterns.';
    }
    if (in_array($host, $urlShorteners, true)) {
        $risk += 18;
        $findings[] = 'The URL uses a link shortener that hides the final destination.';
    }
    if (str_contains($url, '@')) {
        $risk += 22;
        $findings[] = 'The URL contains an @ symbol, which can hide the real destination.';
    }

    $brandSignals = lex_phishing_brand_impersonation($host, $registrableDomain, $brandDomains);
    foreach ($brandSignals as $signal) {
        $brandLabel = ucfirst((string) $signal['brand']);
        $risk += 56;
        $findings[] = "Trusted brand '{$brandLabel}' appears in the hostname, but the registrable domain is '" . $signal['registrable_domain'] . "' rather than '" . $signal['trusted_domain'] . "'.";
    }

    $lookalikeBrand = lex_phishing_lookalike_brand($host, $lookalikeBrands, $trustedDomains);
    if ($lookalikeBrand !== '') {
        $risk += 56;
        $findings[] = 'The domain looks like an impersonation of ' . ucfirst($lookalikeBrand) . '.';
    }

    $hostTokens = lex_phishing_tokenize_host_part($host);
    $hasBrandTerm = false;
    foreach ($brandTerms as $term) {
        if (in_array($term, $hostTokens, true)) {
            $hasBrandTerm = true;
            break;
        }
    }
    $hasFinancialTerm = false;
    foreach ($financialTerms as $term) {
        if (str_contains($host, $term)) {
            $hasFinancialTerm = true;
            break;
        }
    }
    $riskyTermCount = 0;
    $hasRiskyTerm = false;
    foreach ($riskyTerms as $term) {
        if (str_contains($fullUrl, $term)) {
            $hasRiskyTerm = true;
            $riskyTermCount++;
        }
    }
    if ($hasBrandTerm && $hasRiskyTerm) {
        $risk += 20;
        $findings[] = 'The URL combines brand-like and account-action terms.';
    }
    if ($brandSignals !== [] && $hasRiskyTerm) {
        $risk += 20;
        $findings[] = 'Brand impersonation appears together with login, verification, or account-action language.';
    }
    if ($hasFinancialTerm && $hasRiskyTerm) {
        $risk += 22;
        $findings[] = 'The URL combines financial terms with urgent account-action wording.';
    }
    if ($riskyTermCount >= 2) {
        $risk += 10;
        $findings[] = 'The URL contains multiple urgent account-action terms.';
    }
    if ($hasFinancialTerm && preg_match('/[-_.](login|verify|update|secure|account|confirm|password)[-_.]?/', $host)) {
        $risk += 12;
        $findings[] = 'The domain is shaped like a financial security or account-update page.';
    }
    if ($subdomainPart !== '' && count(explode('.', $subdomainPart)) >= 2 && $hasRiskyTerm) {
        $risk += 36;
        $findings[] = "Trusted-looking labels appear in the subdomain, but the actual registrable domain is '{$registrableDomain}'.";
    }
    if (preg_match('/\/(login|signin|verify|reset|password|account|secure|update)(\/|$|\?)/', (string) ($parts['path'] ?? ''))) {
        $risk += 8;
        $findings[] = 'The path asks for login or account-verification activity.';
    }
    if (strlen((string) ($parts['query'] ?? '')) > 90) {
        $risk += 8;
        $findings[] = 'The query string is unusually long.';
    }

    return [
        'risk' => $risk,
        'findings' => $findings,
        'registrable_domain' => $registrableDomain,
    ];
}

function lex_phishing_scans_table_ensure(): void
{
    static $done = false;
    if ($done || !function_exists('lex_pdo')) {
        return;
    }
    try {
        lex_pdo()->exec(
            "CREATE TABLE IF NOT EXISTS `phishing_scans` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT DEFAULT NULL,
                `submitted_url` TEXT NOT NULL,
                `final_url` TEXT,
                `status` VARCHAR(20) NOT NULL DEFAULT 'safe',
                `score` INT NOT NULL DEFAULT 0,
                `findings_json` TEXT,
                `redirect_count` INT NOT NULL DEFAULT 0,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_phishing_scans_user` (`user_id`),
                KEY `idx_phishing_scans_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    } catch (Throwable $e) {
        // Scan results still return even if the log table cannot be created.
    }
}

function lex_phishing_json_ok(array $payload, int $code = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    echo is_string($json) && $json !== ''
        ? $json
        : '{"status":"suspicious","score":0,"message":"Unable to encode the scan result.","findings":[]}';
}

function lex_phishing_log_scan(string $submittedUrl, array $response): void
{
    if (!in_array((string) ($response['status'] ?? ''), ['suspicious', 'phishing'], true)) {
        return;
    }

    try {
        lex_phishing_scans_table_ensure();
        if (!function_exists('lex_pdo')) {
            return;
        }
        $writer = static function () use ($submittedUrl, $response): void {
            $findingsJson = json_encode($response['findings'] ?? [], JSON_UNESCAPED_SLASHES);
            lex_pdo()->prepare(
                'INSERT INTO phishing_scans
                    (user_id, submitted_url, final_url, status, score, findings_json, redirect_count, ip_address, user_agent)
                 VALUES
                    (:user_id, :submitted_url, :final_url, :status, :score, :findings_json, :redirect_count, :ip_address, :user_agent)'
            )->execute([
                'user_id' => !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                'submitted_url' => $submittedUrl,
                'final_url' => (string) ($response['final_url'] ?? ''),
                'status' => (string) $response['status'],
                'score' => (int) ($response['score'] ?? 0),
                'findings_json' => $findingsJson !== false ? $findingsJson : '[]',
                'redirect_count' => (int) ($response['redirect_count'] ?? 0),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        };
        if (function_exists('lex_db_retry')) {
            lex_db_retry($writer, null);
        } else {
            $writer();
        }
    } catch (Throwable $e) {
        // Never fail a scan because logging failed.
    }

    if ((string) ($response['status'] ?? '') === 'phishing' && function_exists('lex_notify_role_users')) {
        try {
            lex_notify_role_users('admin', 'phishing', 'Phishing link detected: ' . $submittedUrl);
        } catch (Throwable $e) {
        }
    }
}

function lex_phishing_response_for_url(string $url, bool $resolveRedirects = true): array
{
    $initialScan = lex_phishing_evaluate_url($url);
    if (isset($initialScan['error'])) {
        $failed = [
            'status' => 'suspicious',
            'score' => 0,
            'message' => $initialScan['error'],
            'findings' => [],
            'final_url' => $url,
            'redirect_count' => 0,
            'redirect_chain' => [$url],
            'engines' => ['php'],
        ];
        return function_exists('lex_phishing_merge_python')
            ? lex_phishing_merge_python($url, $failed)
            : $failed;
    }

    $redirect = [
        'final_url' => $url,
        'redirect_count' => 0,
        'redirect_chain' => [$url],
        'redirect_error' => '',
    ];
    if ($resolveRedirects) {
        try {
            $redirect = lex_phishing_resolve_redirects($url);
        } catch (Throwable $e) {
            $redirect['redirect_error'] = 'Redirect checking was skipped.';
        }
    }

    $risk = (int) $initialScan['risk'];
    $findings = $initialScan['findings'];
    $finalUrl = (string) $redirect['final_url'];
    if ($finalUrl !== $url) {
        $findings[] = 'The URL redirects to: ' . $finalUrl;
        $finalScan = lex_phishing_evaluate_url($finalUrl);
        if (!isset($finalScan['error'])) {
            $risk += min(60, (int) $finalScan['risk']);
            foreach ($finalScan['findings'] as $finding) {
                $findings[] = 'Final URL: ' . $finding;
            }
        }
    }
    if ((int) $redirect['redirect_count'] > 0) {
        $risk += min(12, (int) $redirect['redirect_count'] * 4);
    }
    if ((string) $redirect['redirect_error'] !== '') {
        $findings[] = (string) $redirect['redirect_error'];
    }

    if ($risk >= 55) {
        $built = [
            'status' => 'phishing',
            'score' => min(99, $risk),
            'message' => $findings[0] ?? 'Multiple phishing indicators were detected.',
            'findings' => $findings,
            'final_url' => $finalUrl,
            'redirect_count' => (int) $redirect['redirect_count'],
            'redirect_chain' => $redirect['redirect_chain'],
            'engines' => ['php'],
        ];
    } elseif ($risk >= 25) {
        $built = [
            'status' => 'suspicious',
            'score' => $risk,
            'message' => $findings[0] ?? 'Some suspicious URL patterns were detected.',
            'findings' => $findings,
            'final_url' => $finalUrl,
            'redirect_count' => (int) $redirect['redirect_count'],
            'redirect_chain' => $redirect['redirect_chain'],
            'engines' => ['php'],
        ];
    } else {
        $built = [
            'status' => 'safe',
            'score' => max(90, 100 - $risk),
            'message' => 'No strong phishing indicators detected.',
            'findings' => $findings,
            'final_url' => $finalUrl,
            'redirect_count' => (int) $redirect['redirect_count'],
            'redirect_chain' => $redirect['redirect_chain'],
            'engines' => ['php'],
        ];
    }

    return function_exists('lex_phishing_merge_python')
        ? lex_phishing_merge_python($url, $built)
        : $built;
}

function lex_phishing_handle_request(): void
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            lex_phishing_json_ok([
                'status' => 'suspicious',
                'score' => 0,
                'message' => 'Use POST to scan a URL.',
            ], 405);
            return;
        }

        $limit = ['allowed' => true, 'retry_after' => 0];
        if (function_exists('lex_rate_limit_hit') && function_exists('lex_rate_limit_client_ip')) {
            try {
                $limit = lex_rate_limit_hit(
                    'phishing_check',
                    lex_rate_limit_client_ip(),
                    60,
                    3600,
                    900,
                    lex_rate_limit_client_ip()
                );
            } catch (Throwable $e) {
                $limit = ['allowed' => true, 'retry_after' => 0];
            }
        }
        if (empty($limit['allowed'])) {
            $retry = function_exists('lex_rate_limit_message')
                ? lex_rate_limit_message((int) ($limit['retry_after'] ?? 0))
                : 'Too many attempts. Please try again later.';
            lex_phishing_json_ok([
                'status' => 'suspicious',
                'score' => 0,
                'message' => $retry,
            ], 429);
            return;
        }

        $rawInput = (string) file_get_contents('php://input');
        if ($rawInput === '' && PHP_SAPI === 'cli') {
            $rawInput = (string) stream_get_contents(STDIN);
        }
        $payload = json_decode($rawInput, true);
        $url = trim((string) ($payload['url'] ?? $_POST['url'] ?? ''));

        if ($url === '') {
            lex_phishing_json_ok([
                'status' => 'suspicious',
                'score' => 0,
                'message' => 'URL is required.',
            ], 400);
            return;
        }

        $response = lex_phishing_response_for_url($url);
        $code = 200;
        if (($response['score'] ?? 0) === 0 && ($response['message'] ?? '') === 'Enter a valid http or https URL.') {
            $code = 422;
        }

        lex_phishing_log_scan($url, $response);
        lex_phishing_json_ok($response, $code);
    } catch (Throwable $e) {
        lex_phishing_json_ok([
            'status' => 'suspicious',
            'score' => 0,
            'message' => 'Unable to finish the scan. Please try again.',
            'findings' => [],
        ]);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    lex_phishing_handle_request();
}
