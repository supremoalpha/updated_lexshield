<?php

declare(strict_types=1);

/**
 * JSON-only phishing scanner. Markers: LEX_JSON_API lex_phishing_json_ok
 */

if (!defined('LEX_JSON_API')) {
    define('LEX_JSON_API', true);
}
if (!defined('LEX_PHISHING_DETECTOR_STANDALONE')) {
    define('LEX_PHISHING_DETECTOR_STANDALONE', true);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$lexPhishingJsonFail = static function (string $message, int $code = 200): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'status' => 'suspicious',
        'score' => 0,
        'message' => $message,
        'findings' => [],
        'engines' => ['php'],
    ], JSON_UNESCAPED_SLASHES);
};

try {
    $bootstrap = __DIR__ . '/config/bootstrap.php';
    if (is_file($bootstrap)) {
        require_once $bootstrap;
        ini_set('display_errors', '0');
    }

    $candidates = [
        __DIR__ . '/api/phishing/check.php',
        __DIR__ . '/phishing/check.php',
    ];
    $loaded = false;
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            break;
        }
    }

    if (!$loaded || !function_exists('lex_phishing_handle_request')) {
        $lexPhishingJsonFail('The phishing scanner is not installed on this copy. Copy api/phishing/check.php into your lexshield folder.');
        exit;
    }

    lex_phishing_handle_request();
    if (ob_get_level() === 0) {
        exit;
    }
    $buffered = (string) ob_get_clean();
    $start = strpos($buffered, '{');
    $end = strrpos($buffered, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $json = substr($buffered, $start, $end - $start + 1);
        if (is_array(json_decode($json, true))) {
            echo $json;
            exit;
        }
    }
    $lexPhishingJsonFail('Unable to finish the scan. Please try again.');
} catch (Throwable $e) {
    $lexPhishingJsonFail('Unable to finish the scan. Please try again.');
}
