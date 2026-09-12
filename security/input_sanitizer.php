<?php
declare(strict_types=1);

function lex_sanitize_text(?string $value): string
{
    return trim((string) filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH));
}

function lex_sanitize_multiline_text(?string $value): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
    return trim($text);
}

function lex_sanitize_email(?string $value): string
{
    return strtolower(trim((string) filter_var($value, FILTER_SANITIZE_EMAIL)));
}

function lex_sanitize_int(mixed $value, int $default = 0): int
{
    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    return $filtered === false ? $default : (int) $filtered;
}

function lex_sanitize_bool(mixed $value): int
{
    return filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0;
}

function lex_sanitize_filename(string $filename): string
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'file';
    return trim($filename, '._') ?: 'file';
}

function lex_password_policy_error(string $password, string $email = '', string $fullName = ''): string
{
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one symbol.';
    }

    $lowerPassword = strtolower($password);
    $commonPasswords = [
        'password',
        'password123',
        'password123!',
        'admin123',
        'admin123!',
        'qwerty123',
        'qwerty123!',
        'letmein123',
        'welcome123',
        'welcome123!',
        'lexshield123',
        'lexshield123!',
        '1234567890',
        '1234567890!',
    ];
    foreach ($commonPasswords as $commonPassword) {
        if ($lowerPassword === $commonPassword) {
            return 'Password is too common. Choose a stronger password.';
        }
    }

    $emailLocal = strtolower(trim(strtok($email, '@') ?: ''));
    if ($emailLocal !== '' && strlen($emailLocal) >= 4 && str_contains($lowerPassword, $emailLocal)) {
        return 'Password must not contain your email username.';
    }

    $nameParts = preg_split('/\s+/', strtolower(trim($fullName))) ?: [];
    foreach ($nameParts as $namePart) {
        $namePart = preg_replace('/[^a-z0-9]/', '', $namePart) ?? '';
        if (strlen($namePart) >= 4 && str_contains($lowerPassword, $namePart)) {
            return 'Password must not contain your name.';
        }
    }

    return '';
}

function lex_password_is_strong(string $password, string $email = '', string $fullName = ''): bool
{
    return lex_password_policy_error($password, $email, $fullName) === '';
}
