<?php

declare(strict_types=1);

/**
 * Homepage and client lawyer directory match the compact LEXSHIELD layout.
 * php script/test_lawyer_directory.php
 */

$failed = 0;
$passed = 0;

function lex_dir_assert(string $label, bool $ok, string $detail = ''): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}" . ($detail !== '' ? "\n  {$detail}" : '') . "\n";
}

$home = (string) file_get_contents(dirname(__DIR__) . '/config/home/page.php');
$style = (string) file_get_contents(dirname(__DIR__) . '/public/css/style.css');
$baseJs = (string) file_get_contents(dirname(__DIR__) . '/public/js/base.js');
$client = (string) file_get_contents(dirname(__DIR__) . '/client/lawyers.php');

lex_dir_assert('Homepage directory has a search form', str_contains($home, 'pao-directory-search') && str_contains($home, 'name="q"') && str_contains($home, 'name="spec"'));
lex_dir_assert('Homepage search placeholder matches LEXSHIELD', str_contains($home, 'Search by name, specialization, or background'));
lex_dir_assert('Homepage has Search and Clear actions', str_contains($home, '>Search</button>') && str_contains($home, 'type="reset"'));
lex_dir_assert('Homepage cards are compact directory cards', str_contains($home, 'pao-lawyer-card--directory') && str_contains($home, '>View</a>'));
lex_dir_assert('Homepage cards show ratings before View', str_contains($home, 'lex_pao_star_rating($rating, $reviews)'));
lex_dir_assert('Homepage cards show Book next to View', str_contains($home, '>Book</a>') && str_contains($home, 'pao-lawyer-actions'));
lex_dir_assert('Homepage cards no longer use tall media/chips', !str_contains($home, 'pao-lawyer-media') && !str_contains($home, 'pao-lawyer-tags'));
lex_dir_assert('Directory filter JS still works', str_contains($baseJs, "new FormData(directory).get('q')") && str_contains($baseJs, "addEventListener('reset'"));
lex_dir_assert('Compact homepage card CSS exists', str_contains($style, '.pao-lawyer-card--directory') && str_contains($style, 'grid-template-columns: auto minmax(0, 1fr)'));
lex_dir_assert('Client directory uses compact cards', str_contains($client, 'lawyer-card--directory') && str_contains($client, 'Search by name, specialization, or background'));
lex_dir_assert('Client directory shows rating and Book on the card', str_contains($client, 'lawyer-card__rating') && str_contains($client, '>Book'));
lex_dir_assert('Compact CSS shows directory ratings', str_contains($style, '.lawyer-card.lawyer-card--directory .lawyer-card__rating') && str_contains($style, '.pao-lawyer-card--directory .pao-rating'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
