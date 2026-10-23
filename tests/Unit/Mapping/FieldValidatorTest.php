<?php

declare(strict_types=1);

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Mapping\Data\Verdict;
use App\Domain\Mapping\FieldValidator;

/*
 * Do a column's values fit a field? Legacy D8: letters were `[a-zA-ZÀ-ÿ]`, so a Greek, Cyrillic or
 * Japanese title "had no letters" and was refused, and str_word_count counted no words in it.
 */

function verdictFor(string $field, array $values, ?string $locale = null): Verdict
{
    return FieldValidator::validate(CanonicalSchema::fromConfig()->get($field), $values, $locale)->verdict;
}

it('accepts titles in every script (legacy D8)', function (array $titles, string $locale): void {
    expect(verdictFor('title', $titles, $locale))->toBe(Verdict::Pass);
})->with([
    'greek' => [['Αδιάβροχο μπουφάν', 'Ορειβατικά παπούτσια', 'Σακίδιο πλάτης 30L'], 'el'],
    'japanese' => [['防水ジャケット', '登山靴', 'バックパック 30L'], 'ja'],
    'ukrainian' => [['Водонепроникна куртка', 'Туристичні черевики', 'Рюкзак 30L'], 'uk'],
    'arabic' => [['سترة مقاومة للماء', 'حذاء للمشي', 'حقيبة ظهر'], 'ar'],
    'german' => [['Wanderschuh aus Leder', 'Regenjacke Größe M', 'Rucksack 30 l'], 'de-CH'],
]);

it('refuses a text field whose values clearly belong elsewhere', function (string $field, array $values): void {
    expect(verdictFor($field, $values))->toBe(Verdict::Fail);
})->with([
    'sizes in color' => ['color', ['M', 'L', 'XL', '42']],
    'urls in title' => ['title', ['https://shop.example/p/1', 'https://shop.example/p/2', 'www.shop.example/p/3']],
    'barcodes in title' => ['title', ['4006381333931', '5901234123457', '9780201379624']],
    'prices in description' => ['description', ['19.90', '29.90', '1\'299.00']],
    'image files in material' => ['material', ['leather.jpg', 'cotton.png', 'wool.webp']],
    'taxonomy ids in category' => ['category', ['3 | Leisure & wellness', '12 | Home', '7 | Garden']],
    'no letters in title' => ['title', ['---', '###', '***']],
]);

it('warns, without refusing, when only a few values look wrong', function (): void {
    expect(verdictFor('title', ['Wanderschuh aus Leder', 'Regenjacke', 'Rucksack', 'Zelt für 2', 'Schlafsack', 'Isomatte', 'Stirnlampe', 'Trinkflasche', 'Kocher', 'XL']))
        ->toBe(Verdict::Warn);
});

it('warns when a localized field is not written in its locale\'s script', function (): void {
    $validation = FieldValidator::validate(CanonicalSchema::fromConfig()->get('title'), ['Waterproof jacket', 'Hiking boots'], 'el');

    expect($validation->verdict)->toBe(Verdict::Warn)
        ->and($validation->issues[0]['type'])->toBe('wrong_script');
});

it('checks golden fields by what they are', function (string $field, array $values, Verdict $verdict): void {
    expect(verdictFor($field, $values))->toBe($verdict);
})->with([
    'valid gtins' => ['gtin', ['4006381333931', '5901234123457', '96385074'], Verdict::Pass],
    'bad check digits' => ['gtin', ['4006381333932', '5901234123458', '96385075'], Verdict::Fail],
    'prices' => ['price', ['19.90', '29,90 €', 'CHF 1\'299.00', '1.299,00', '5'], Verdict::Pass],
    'not prices' => ['price', ['on request', 'ask us', 'n/a'], Verdict::Fail],
    'currencies' => ['currency', ['CHF', 'eur', '€'], Verdict::Pass],
    'not currencies' => ['currency', ['Swiss francs', 'euros', 'dollars'], Verdict::Fail],
    'stock' => ['stock_quantity', ['3', '0', '120'], Verdict::Pass],
    'not stock' => ['stock_quantity', ['in stock', 'many', '3.5'], Verdict::Fail],
    'links' => ['link', ['https://shop.example/p/1', 'http://shop.example/p/2'], Verdict::Pass],
    'not links' => ['link', ['/p/1', 'shop.example/p/2'], Verdict::Fail],
    'unique skus' => ['sku', ['A-1', 'A-2', 'A-3'], Verdict::Pass],
    'repeating skus' => ['sku', ['A-1', 'A-1', 'A-3'], Verdict::Warn],
]);

it('has nothing to say about an empty column', function (): void {
    expect(verdictFor('title', ['', '  ']))->toBe(Verdict::Empty);
});

it('reads prices in the ways feeds write them', function (string $value, ?string $number): void {
    expect(FieldValidator::money($value))->toBe($number);
})->with([
    ['19.90', '19.90'],
    ['19,90 €', '19,90'],
    ['CHF 1\'299.00', '1\'299.00'],
    ['1.299,00', '1.299,00'],
    ['29.-', '29'],
    ['ask us', null],
    ['19.90.10', null],
]);
