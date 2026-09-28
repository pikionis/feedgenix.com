<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * FeedGenix must never re-grow the coupling of the legacy app (Feed Intelligence, built for
 * DeinDeal / Channable / Galaxus). These patterns are banned from product code: application,
 * config, migrations, routes, views and the frontend. Docs may name them; code may not.
 */
dataset('banned patterns', [
    'Channable French column names' => ['/\b(?:titre|categorie|couleur|matiere)_[a-z]{2}\b/i', 'titre_fr'],
    'legacy _meta_ columns' => ['/_meta_/i', 'desc_meta_de'],
    'legacy classification column' => ['/\bid_type_produit\b/i', 'id_type_produit'],
    'legacy supplier names' => ['/\b(?:deindeal|galaxus|pertemba|competec|kuchinox|xinnomix|mathier|vidaxl|beliani|vonsalis)\b/i', 'Galaxus'],
    'hard-coded legacy language set' => ['/[\'"]fr[\'"]\s*,\s*[\'"]de[\'"]\s*,\s*[\'"]en[\'"]\s*,\s*[\'"]it[\'"]/i', "['fr', 'de', 'en', 'it']"],
]);

/**
 * @return list<string>
 */
function productCodeHits(string $pattern): array
{
    $root = dirname(__DIR__, 2);
    $dirs = array_values(array_filter(
        [$root.'/app', $root.'/bootstrap', $root.'/config', $root.'/database', $root.'/routes', $root.'/resources', $root.'/frontend/src'],
        is_dir(...),
    ));

    $files = Finder::create()->files()->in($dirs)
        ->name(['*.php', '*.ts', '*.tsx', '*.js', '*.json', '*.css', '*.html'])
        ->exclude(['node_modules', 'cache']);

    $hits = [];
    foreach ($files as $file) {
        if (preg_match($pattern, $file->getContents(), $match) === 1) {
            $hits[] = $file->getRelativePathname().' → '.$match[0];
        }
    }

    return $hits;
}

it('keeps legacy coupling out of product code', function (string $pattern): void {
    expect(productCodeHits($pattern))->toBe([]);
})->with('banned patterns');

it('really detects each banned pattern (guards against a silently broken scanner)', function (string $pattern, string $sample): void {
    expect(preg_match($pattern, "some code {$sample} more code"))->toBe(1);
})->with('banned patterns');

it('does not flag ordinary words that merely contain a banned name', function (): void {
    expect(preg_match('/\b(?:deindeal|galaxus|pertemba|competec|kuchinox|xinnomix|mathier|vidaxl|beliani|vonsalis)\b/i', 'mathieson, belianis, competecy'))->toBe(0);
});
