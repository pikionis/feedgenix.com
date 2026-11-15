<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * FeedGenix must never re-grow the coupling of the legacy app (Feed Intelligence, built around one
 * customer's Channable and marketplace feeds). These patterns are banned from product code:
 * application, config, migrations, routes, views and the frontend.
 *
 * The legacy customer and supplier names are banned too. This repository is public, so they are
 * kept as SHA-256 digests of the lower-case name rather than spelled out: a file is flagged when
 * any word in it hashes to one of them. To ban another name, add
 * `hash('sha256', strtolower($name))` to the list.
 */
const LEGACY_NAME_DIGESTS = [
    '0763b652c49e388875387249986c7ee066e5f4b61adbfe87d198a48931086690',
    '18225ecd8ff2d0b2e665411a8574b9c1cd5d91defedcc75a1243e8a90d957ba1',
    '1dd4d8466c1dd518765b4d7ab3572580d582899f4cba805845b9eb61185f30cb',
    '334e69db8d7f3e17e6287571b13f454f9ea093c7f094ae9caa0c5bd6d35c908b',
    '48ab7b787476dac9d70012640a750b5df71cfbc090d07c5f84bb8c1e419c8217',
    '83d6a03bef19d3882eea42599d8af4752e00593e6e3a424506e970311b48877c',
    '89117b79571f9fc77c95fb1b3180b7247b89b97ca3f27bf9c0c4fbd493ae79c0',
    'c877f68328b5913ce426fc1a0daad4c1264d16e1a21e38d781786d6caae5f4c2',
    'c8cf4e671008cf20ab3ae729dee11c329ce44708bfc17346141efe3abf967b4b',
    'fca912657767bd4dc63794a7bdddc579612ac9642fad4ab94065878f1a3bba36',
];

dataset('banned patterns', [
    'Channable French column names' => ['/\b(?:titre|categorie|couleur|matiere)_[a-z]{2}\b/i', 'titre_fr'],
    'legacy _meta_ columns' => ['/_meta_/i', 'desc_meta_de'],
    'legacy classification column' => ['/\bid_type_produit\b/i', 'id_type_produit'],
    'hard-coded legacy language set' => ['/[\'"]fr[\'"]\s*,\s*[\'"]de[\'"]\s*,\s*[\'"]en[\'"]\s*,\s*[\'"]it[\'"]/i', "['fr', 'de', 'en', 'it']"],
    // Legacy Support/Http/Tls switched verification off locally; TLS is always verified (ADR 0004).
    'TLS verification turned off' => ['/withoutVerifying\s*\(|[\'"]verify[\'"]\s*=>\s*false|CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*[\'"]?\s*(?:=>|,)\s*(?:false|0)/i', '->withoutVerifying()'],
]);

/**
 * @return list<string>
 */
function productCodeHits(string $pattern): array
{
    $hits = [];
    foreach (productCodeFiles() as $file) {
        if (preg_match($pattern, $file->getContents(), $match) === 1) {
            $hits[] = $file->getRelativePathname().' → '.$match[0];
        }
    }

    return $hits;
}

/**
 * The files product code is made of.
 */
function productCodeFiles(): Finder
{
    $root = dirname(__DIR__, 2);
    $dirs = array_values(array_filter(
        [$root.'/app', $root.'/bootstrap', $root.'/config', $root.'/database', $root.'/routes', $root.'/resources', $root.'/frontend/src'],
        is_dir(...),
    ));

    return Finder::create()->files()->in($dirs)
        ->name(['*.php', '*.ts', '*.tsx', '*.js', '*.json', '*.css', '*.html'])
        ->exclude(['node_modules', 'cache']);
}

/**
 * Whether any whole word of the text, compared case-insensitively, hashes to one of the digests.
 * Words are runs of letters and digits, so `Name_feed` and `name-feed` are caught while a longer
 * word that merely contains a name is not.
 *
 * @param  list<string>  $digests
 */
function namesBannedWord(string $text, array $digests): bool
{
    $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    if ($words === false) {
        return false;
    }

    foreach (array_unique($words) as $word) {
        if (in_array(hash('sha256', $word), $digests, true)) {
            return true;
        }
    }

    return false;
}

it('keeps legacy coupling out of product code', function (string $pattern): void {
    expect(productCodeHits($pattern))->toBe([]);
})->with('banned patterns');

it('really detects each banned pattern (guards against a silently broken scanner)', function (string $pattern, string $sample): void {
    expect(preg_match($pattern, "some code {$sample} more code"))->toBe(1);
})->with('banned patterns');

it('keeps legacy customer and supplier names out of product code', function (): void {
    $hits = [];
    foreach (productCodeFiles() as $file) {
        if (namesBannedWord($file->getContents(), LEGACY_NAME_DIGESTS)) {
            $hits[] = $file->getRelativePathname();
        }
    }

    expect($hits)->toBe([]);
});

it('carries a well-formed digest for every banned legacy name', function (): void {
    expect(LEGACY_NAME_DIGESTS)->toHaveCount(10)
        ->and(array_unique(LEGACY_NAME_DIGESTS))->toHaveCount(10);

    foreach (LEGACY_NAME_DIGESTS as $digest) {
        expect($digest)->toMatch('/\\A[0-9a-f]{64}\\z/');
    }
});

it('really detects a banned name, in any case and next to punctuation or an underscore', function (string $text): void {
    expect(namesBannedWord($text, [hash('sha256', 'acmefeeds')]))->toBeTrue();
})->with([
    'plain' => ['some code acmefeeds more code'],
    'mixed case' => ["\$supplier = 'AcmeFeeds';"],
    'snake case' => ['acmefeeds_category_map'],
    'kebab case' => ['import "./acmefeeds-joiner"'],
]);

it('does not flag ordinary words that merely contain a banned name', function (): void {
    expect(namesBannedWord('acmefeedsy, myacmefeeds, acme feeds', [hash('sha256', 'acmefeeds')]))->toBeFalse();
});
