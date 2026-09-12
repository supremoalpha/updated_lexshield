<?php
declare(strict_types=1);

/**
 * Defense-in-depth helpers for safe dynamic SQL.
 *
 * The app already uses PDO prepared statements with bound parameters
 * everywhere (PDO::ATTR_EMULATE_PREPARES is disabled in config/db.php, so
 * MySQL itself parses the query text before any value is substituted -
 * this is what actually defeats classic SQL injection). Bound parameters
 * can only ever be used for *values*, never for identifiers such as
 * column/table names or the ASC/DESC keyword, so any code that needs to
 * build an ORDER BY / column list from user input (e.g. a `?sort=` query
 * string) must validate that input against an explicit whitelist first.
 * These helpers centralize that pattern so every feature does it the
 * same, safe way instead of re-inventing ad-hoc validation.
 */

if (!function_exists('lex_safe_identifier')) {
    /**
     * Picks $value out of $allowed if present, otherwise returns $default.
     * Use this before ever concatenating a column name into SQL.
     *
     * @param array<int, string> $allowed
     */
    function lex_safe_identifier(?string $value, array $allowed, string $default): string
    {
        $value = (string) $value;
        return in_array($value, $allowed, true) ? $value : $default;
    }
}

if (!function_exists('lex_safe_direction')) {
    function lex_safe_direction(?string $value, string $default = 'DESC'): string
    {
        $value = strtoupper(trim((string) $value));
        return $value === 'ASC' ? 'ASC' : ($value === 'DESC' ? 'DESC' : strtoupper($default));
    }
}

if (!function_exists('lex_like_escape')) {
    /**
     * Escapes LIKE metacharacters (% and _) in a value that will still be
     * bound as a parameter (never concatenated). Without this, a search
     * term containing `%` or `_` behaves like a wildcard, which is a minor
     * information-disclosure / DoS surface (e.g. `%` matches everything),
     * not a classic injection - but it is still user input shaping query
     * semantics, so it is escaped before being wrapped in `%...%`.
     */
    function lex_like_escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

if (!function_exists('lex_like_value')) {
    function lex_like_value(string $value): string
    {
        return '%' . lex_like_escape($value) . '%';
    }
}

if (!function_exists('lex_safe_int_list')) {
    /**
     * Sanitizes an array of values into a de-duplicated list of positive
     * integers, for safe use in `IN (...)` placeholders built at runtime.
     *
     * @param array<mixed> $values
     * @return array<int, int>
     */
    function lex_safe_int_list(array $values): array
    {
        $ints = array_map(static fn ($v): int => (int) $v, $values);
        $ints = array_values(array_unique(array_filter($ints, static fn (int $v): bool => $v > 0)));
        return $ints;
    }
}

if (!function_exists('lex_bounded_page_params')) {
    /**
     * Clamps page/perPage into safe positive integer bounds. LIMIT/OFFSET
     * cannot be bound as PDO parameters on every driver reliably, so this
     * app follows the existing codebase convention of casting to (int) and
     * clamping before concatenating them - never using raw request input.
     *
     * @return array{page:int, perPage:int, offset:int}
     */
    function lex_bounded_page_params(mixed $page, mixed $perPage, int $maxPerPage = 100): array
    {
        $perPage = max(1, min($maxPerPage, (int) $perPage));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $perPage;
        return ['page' => $page, 'perPage' => $perPage, 'offset' => $offset];
    }
}
