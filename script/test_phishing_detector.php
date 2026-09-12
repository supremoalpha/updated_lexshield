<?php
declare(strict_types=1);

define('LEX_PHISHING_DETECTOR_STANDALONE', true);
require_once __DIR__ . '/../api/phishing/check.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$registrableCases = [
    'https://example.co.uk' => 'example.co.uk',
    'https://subdomain.example.co.uk' => 'example.co.uk',
];

foreach ($registrableCases as $url => $expectedDomain) {
    $host = (string) parse_url($url, PHP_URL_HOST);
    $actualDomain = lex_phishing_registrable_domain($host);
    assert_true($actualDomain === $expectedDomain, "{$url} registrable domain should be {$expectedDomain}, got {$actualDomain}");
}

$safeCases = [
    'https://login.microsoft.com',
    'https://accounts.google.com',
    'https://www.paypal.com',
    'https://secure.lexshield.com',
];

foreach ($safeCases as $url) {
    $response = lex_phishing_response_for_url($url, false);
    assert_true($response['status'] !== 'phishing', "{$url} should not be phishing");
    $joinedFindings = implode(' ', $response['findings'] ?? []);
    assert_true(!str_contains($joinedFindings, 'registrable domain'), "{$url} should not be flagged for brand impersonation");
}

$phishingCases = [
    'https://login.microsoft.com.attacker.com',
    'https://microsoft.com.attacker.com',
    'https://secure-paypal.attacker.com',
    'https://paypal-login.attacker.com',
    'https://google.verify-attacker.com',
    'https://secure-login.example.net.verify-account.com',
    'https://paypa1-attacker.com',
    'https://micr0soft-login-attacker.com',
];

foreach ($phishingCases as $url) {
    $response = lex_phishing_response_for_url($url, false);
    assert_true($response['status'] === 'phishing', "{$url} should be phishing, got {$response['status']} with score {$response['score']}");
}

$checkSource = (string) file_get_contents(__DIR__ . '/../api/phishing/check.php');
assert_true(str_contains($checkSource, 'function lex_phishing_dns_records'), 'Windows-safe DNS helper must exist');
assert_true(!str_contains($checkSource, 'DNS_A + DNS_AAAA'), 'Windows-incompatible DNS_A + DNS_AAAA lookup must be gone');
assert_true(str_contains($checkSource, 'function lex_phishing_json_ok'), 'Scanner must emit JSON through lex_phishing_json_ok');
assert_true(str_contains($checkSource, 'ob_clean()'), 'JSON helper must drop leaked PHP warnings');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['url'] = 'https://example.com';
ob_start();
lex_phishing_handle_request();
$scanOutput = trim((string) ob_get_clean());
$scanJson = json_decode($scanOutput, true);
assert_true(is_array($scanJson), 'Scan endpoint must return JSON, got: ' . substr($scanOutput, 0, 180));
assert_true(isset($scanJson['status']), 'Scan JSON must include status');
assert_true(in_array($scanJson['status'], ['safe', 'suspicious', 'phishing'], true), 'Scan status must be valid');

$wrapper = (string) file_get_contents(__DIR__ . '/../phishing_check.php');
assert_true(str_contains($wrapper, 'LEX_JSON_API'), 'phishing_check.php must force JSON responses');
assert_true(str_contains($wrapper, 'ob_start()'), 'phishing_check.php must buffer output so warnings cannot leak');

$bootstrapSource = (string) file_get_contents(__DIR__ . '/../config/bootstrap.php');
assert_true(str_contains($bootstrapSource, 'lex_phishing_python_binaries'), 'Bootstrap must replace stale Python bridges on XAMPP');
assert_true(str_contains($bootstrapSource, 'public/js/chat.js'), 'Bootstrap must install the phishing JSON parser on XAMPP');
assert_true(str_contains($bootstrapSource, 'function lex_phishing_dispatch_inline'), 'Bootstrap must scan from the current page so phishing_check.php is optional');
assert_true(str_contains($bootstrapSource, 'function lex_phishing_scan_endpoint'), 'Modal must post to a page that already exists');
assert_true(str_contains($bootstrapSource, 'data-fallback-endpoint'), 'Modal must include a fallback scanner URL');

$pythonBridge = (string) file_get_contents(__DIR__ . '/../phishing/python.php');
$pythonScanner = (string) file_get_contents(__DIR__ . '/../phishing/detect.py');
assert_true(str_contains($pythonBridge, 'function lex_phishing_python_scan'), 'PHP must be able to call the Python scanner');
assert_true(str_contains($pythonBridge, 'lex_phishing_python_binaries'), 'Python bridge must only launch interpreters that exist on disk');
assert_true(str_contains($pythonBridge, 'lex_phishing_python_is_real_binary'), 'Python bridge must skip Windows Store stub launchers');
assert_true(str_contains($pythonScanner, 'LEXSHIELD_PYTHON_PHISHING'), 'Python scanner marker must exist');

$merged = lex_phishing_response_for_url('https://paypal-login.attacker.com', false);
assert_true(($merged['status'] ?? '') === 'phishing', 'PHP+Python should still flag a fake PayPal URL');
assert_true(isset($merged['engines']) && in_array('php', (array) $merged['engines'], true), 'Scan result must list the PHP engine');

$pythonBin = trim((string) shell_exec('command -v python3 || command -v python || true'));
if ($pythonBin !== '') {
    $pyOut = [];
    $pyCode = 0;
    exec(escapeshellcmd($pythonBin) . ' ' . escapeshellarg(__DIR__ . '/../phishing/detect.py') . ' ' . escapeshellarg('https://paypal-login.attacker.com'), $pyOut, $pyCode);
    $pyJson = json_decode(implode("\n", $pyOut), true);
    assert_true($pyCode === 0 && is_array($pyJson), 'Python scanner must return JSON');
    assert_true(($pyJson['status'] ?? '') === 'phishing', 'Python scanner must flag a fake PayPal URL');
    $safePy = [];
    exec(escapeshellcmd($pythonBin) . ' ' . escapeshellarg(__DIR__ . '/../phishing/detect.py') . ' ' . escapeshellarg('https://www.paypal.com'), $safePy, $pyCode);
    $safeJson = json_decode(implode("\n", $safePy), true);
    assert_true(($safeJson['status'] ?? '') !== 'phishing', 'Python scanner must not flag real PayPal as phishing');
    assert_true(in_array('python', (array) ($merged['engines'] ?? []), true), 'Merged scan should include the Python engine when Python is installed');
}

$wrapperOut = [];
$wrapperCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg(__DIR__ . '/../phishing_check.php') . ' 2>&1',
    $wrapperOut,
    $wrapperCode
);
$wrapperBody = implode("\n", $wrapperOut);
$wrapperJson = json_decode($wrapperBody, true);
assert_true(is_array($wrapperJson), 'Wrapper must emit JSON only, got: ' . substr($wrapperBody, 0, 220));
assert_true(isset($wrapperJson['status']), 'Wrapper JSON must include status');

$inlineScript = sys_get_temp_dir() . '/lex_phishing_inline_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($inlineScript, '<?php
$_GET["lex_phishing"] = "1";
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST["url"] = "https://example.com";
$_SERVER["SCRIPT_NAME"] = "/lexshield/client/messages.php";
require ' . var_export(dirname(__DIR__) . '/config/bootstrap.php', true) . ';
echo "FAILED_TO_DISPATCH";
');
$inlineOut = [];
$inlineCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($inlineScript) . ' 2>&1',
    $inlineOut,
    $inlineCode
);
@unlink($inlineScript);
$inlineBody = implode("\n", $inlineOut);
$inlineJson = json_decode($inlineBody, true);
assert_true(is_array($inlineJson), 'Current-page scan must emit JSON, got: ' . substr($inlineBody, 0, 220));
assert_true(isset($inlineJson['status']), 'Current-page scan JSON must include status');
assert_true(!str_contains($inlineBody, 'FAILED_TO_DISPATCH'), 'Current-page scan must finish inside bootstrap');

echo "Phishing detector tests passed.\n";
