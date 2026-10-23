<?php

declare(strict_types=1);

use App\Domain\Ingest\Profile\ValueKind;
use App\Domain\Mapping\ColumnName;
use App\Domain\Mapping\LanguageGuess;
use App\Domain\Mapping\ProtectedColumns;

/*
 * Column names as words, and which columns are protected (rule 1). Legacy isProtectedColumn used
 * `\bean\b`; "_" is a word character, so "product_ean" and "EAN_Code" were never protected.
 */

it('splits a column name into lower-case words', function (string $name, array $tokens): void {
    expect(ColumnName::tokens($name))->toBe($tokens);
})->with([
    ['product_ean', ['product', 'ean']],
    ['EAN_Code', ['ean', 'code']],
    ['EANCode', ['ean', 'code']],
    ['productEAN13', ['product', 'ean', '13']],
    ['Art.-Nr. Lieferant', ['art', 'nr', 'lieferant']],
    ['Bild URL (1)', ['bild', 'url', '1']],
    ['Τίτλος προϊόντος', ['τίτλος', 'προϊόντος']],
    ['商品名', ['商品名']],
]);

it('protects the columns the legacy word-boundary guard let through', function (string $name): void {
    expect(ProtectedColumns::byName($name))->toBeTrue();
})->with(['product_ean', 'EAN_Code', 'EANCode', 'Preis_CHF', 'vk_preis', 'Bild_URL_1', 'image_link', 'lagerbestand', 'Art.-Nr.', 'gtin13', 'shipping_weight', 'item id', 'MwSt']);

it('leaves ordinary text columns alone', function (string $name): void {
    expect(ProtectedColumns::byName($name))->toBeFalse();
})->with(['Titel', 'Beschreibung', 'product_name', 'Farbe', 'Material', 'Pflegehinweis', 'Kategorie', 'colour_family', 'Breite']);

it('protects by what the values look like, whatever the name', function (ValueKind $kind, bool $protected): void {
    expect(ProtectedColumns::isProtected('Spalte 7', $kind))->toBe($protected);
})->with([
    [ValueKind::Decimal, true],
    [ValueKind::Integer, true],
    [ValueKind::Gtin, true],
    [ValueKind::Code, true],
    [ValueKind::ImageUrl, true],
    [ValueKind::Url, true],
    [ValueKind::Text, false],
    [ValueKind::Html, false],
]);

it('guesses the language of product texts, and says nothing when unsure', function (string $text, ?string $language): void {
    expect(LanguageGuess::guess($text))->toBe($language);
})->with([
    ['Wasserdichte Jacke für die Berge mit einer Kapuze und der Tasche', 'de'],
    ['A waterproof jacket for the mountains with a hood and the pockets', 'en'],
    ['Veste imperméable pour la montagne avec une capuche et des poches', 'fr'],
    ['Αδιάβροχο μπουφάν για το βουνό με κουκούλα', 'el'],
    ['防水ジャケット、フード付きで山歩きに最適です', 'ja'],
    ['Водонепроникна куртка для гір з капюшоном і кишенями', 'uk'],
    ['XL', null],
    ['Gore-Tex Pro 3L', null],
]);

it('checks that a text is written in its locale\'s script', function (): void {
    expect(LanguageGuess::matchesScript('Αδιάβροχο μπουφάν', 'el'))->toBeTrue()
        ->and(LanguageGuess::matchesScript('Waterproof jacket', 'el'))->toBeFalse()
        ->and(LanguageGuess::matchesScript('Waterproof jacket', 'de-CH'))->toBeTrue()
        ->and(LanguageGuess::matchesScript('防水ジャケット', 'de'))->toBeFalse()
        ->and(LanguageGuess::matchesScript('12345', 'el'))->toBeNull();
});
