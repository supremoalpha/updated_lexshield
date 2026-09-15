<?php

declare(strict_types=1);

/**
 * Homepage uses a photo hero on solid navy, with a glass compliance card.
 * php script/test_homepage_hero.php
 */

$failed = 0;
$passed = 0;

function lex_hh_assert(string $label, bool $ok, string $detail = ''): void
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

$root = dirname(__DIR__);
$home = (string) file_get_contents($root . '/config/home/page.php');
$style = (string) file_get_contents($root . '/public/css/style.css');

lex_hh_assert('Hero can show the City Hall photo', str_contains($home, 'pao-hero--photo') && str_contains($home, 'lex_pao_hero_url'));
lex_hh_assert('Hero photo is not a separate image card', !str_contains($home, 'pao-hero-visual'));
lex_hh_assert('Compliance card sits in the hero', str_contains($home, 'pao-hero-panel') && str_contains($home, 'Legal compliance'));
lex_hh_assert('Directory snapshot rows are present', str_contains($home, 'Directory coverage') && str_contains($home, 'Client trust signal') && str_contains($home, 'Review participation'));
lex_hh_assert('Hero still shows walk-in hours', str_contains($home, 'Mon–Fri') && str_contains($home, '8:00–5:00'));
lex_hh_assert('CSS puts the photo only in the hero', str_contains($style, 'Homepage: navy page, photo only in the hero') && str_contains($style, '.pao-hero--photo::before'));
lex_hh_assert('Homepage drops the tech grid', str_contains($style, 'body.pao-home.pao-site') && str_contains($style, 'body.pao-home .pao-page') && str_contains($style, 'background-image: none !important'));
lex_hh_assert('CSS shows the glass compliance card', str_contains($style, 'body.pao-home .pao-hero-panel') && str_contains($style, 'backdrop-filter: blur(16px)'));
lex_hh_assert('Services still use the PAO delivers heading', str_contains($home, 'What PAO delivers') && str_contains($home, 'Our Services') && str_contains($home, 'qualified clients in Iloilo'));
lex_hh_assert('FAQ questions each sit in their own box', str_contains($home, 'id="faq"') && substr_count($home, 'pao-faq-item') === 3 && str_contains($home, 'Walk-in or online?'));
lex_hh_assert('FAQ is not left loose under Legal Resources', !preg_match('/id="resources"[\s\S]*class="pao-faq"/', $home));
lex_hh_assert('CSS spaces Our Services and boxes each FAQ question', str_contains($style, 'Homepage: spaced Our Services and boxed FAQ questions') && str_contains($style, 'body.pao-home .pao-faq-item'));
lex_hh_assert('Our Services sits tight on the Iloilo line', str_contains($style, 'body.pao-home .pao-services-main > h2') && str_contains($style, 'letter-spacing: 0 !important') && str_contains($style, 'margin: 0.15rem 0 1.85rem !important'));
lex_hh_assert('Contact title sits tight on its line', str_contains($style, 'Homepage: sit Contact title tight on its line') && str_contains($style, 'body.pao-home .pao-inquiry-copy > h2') && str_contains($home, 'id="contact"'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
