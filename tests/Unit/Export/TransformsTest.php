<?php

declare(strict_types=1);

use App\Domain\Catalog\Formatters\DescriptionFormatter;
use App\Domain\Catalog\Formatters\ShortDescription;
use App\Domain\Export\Transforms;

/*
 * How presets present values: the formatters ported from the legacy app and the transforms. A
 * golden value is only ever written byte for byte (with_currency appends, never rewrites).
 */

it('writes a price byte for byte with its currency, and alone when there is none', function (): void {
    expect(Transforms::apply('with_currency', '189.90', ['currency' => 'CHF']))->toBe('189.90 CHF')
        ->and(Transforms::apply('with_currency', '0189,9000', ['currency' => 'EUR']))->toBe('0189,9000 EUR')
        ->and(Transforms::apply('with_currency', '189.90', []))->toBe('189.90')
        ->and(Transforms::apply('with_currency', '', ['currency' => 'CHF']))->toBe('');
});

it('makes plain text of markup without losing words or numbers', function (): void {
    expect(Transforms::apply('plain_text', '<p>Robust &amp; leicht</p><ul><li>2 Fächer</li><li>30&nbsp;l</li></ul>', []))
        ->toBe('Robust & leicht 2 Fächer 30 l')
        ->and(Transforms::apply('plain_text', "Zeile 1<br>Zeile\t2", []))->toBe('Zeile 1 Zeile 2');
});

it('writes a category path with one separator, keeping every level', function (): void {
    expect(Transforms::apply('category_path', 'Sport/Outdoor > Zelte\\Kuppelzelte', []))->toBe('Sport > Outdoor > Zelte > Kuppelzelte')
        ->and(Transforms::apply('category_path', ' > Zelte >  ', []))->toBe('Zelte');
});

it('knows availability words in several languages, falls back to the stock, and leaves unknown words alone', function (): void {
    expect(Transforms::apply('availability', 'Auf Lager', []))->toBe('in stock')
        ->and(Transforms::apply('availability', 'épuisé', []))->toBe('out of stock')
        ->and(Transforms::apply('availability', '', ['stock_quantity' => '12']))->toBe('in stock')
        ->and(Transforms::apply('availability', '', ['stock_quantity' => '0']))->toBe('out of stock')
        ->and(Transforms::apply('availability', '', ['stock_quantity' => 'viele']))->toBe('')
        ->and(Transforms::apply('availability', '3-5 Tage', []))->toBe('3-5 Tage');
});

it('formats a description into one canonical shape, and leaves a formatted one as it is', function (): void {
    $loose = '<p>Robuster Schuh.</p> <ul><li>Leder</li><li><b>Wasserdicht</b></li></ul> Trailing text';
    $canonical = DescriptionFormatter::canonical($loose);

    expect($canonical)->toBe('<p>Robuster Schuh. Trailing text</p><ul><li>Leder</li><li>Wasserdicht</li></ul>')
        ->and(DescriptionFormatter::canonical($canonical))->toBe($canonical)
        ->and(DescriptionFormatter::canonical('Ein Zelt, Material: Polyester, Gewicht: 2,4 kg, Farbe: Gelb'))
        ->toBe('<p>Ein Zelt</p><ul><li>Material: Polyester</li><li>Gewicht: 2,4 kg</li><li>Farbe: Gelb</li></ul>')
        // Prose with a colon or two stays prose.
        ->and(DescriptionFormatter::canonical('Hinweis: bitte trocken lagern, danach: lüften'))->toBe('<p>Hinweis: bitte trocken lagern, danach: lüften</p>')
        ->and(DescriptionFormatter::canonical('<br>'))->toBe('');
});

it('makes a short description from the first sentences, in any script', function (): void {
    expect(ShortDescription::make('<p>Ein leichtes Zelt für zwei. Mit Vorzelt und zwei Eingängen. Sehr stabil.</p>'))
        ->toBe('Ein leichtes Zelt für zwei.')
        ->and(ShortDescription::make('Neu. Ein leichtes Zelt für zwei Personen.'))->toBe('Neu. Ein leichtes Zelt für zwei Personen.')
        ->and(ShortDescription::make('軽量なテントです。二人用で、前室があります。'))->toBe('軽量なテントです。二人用で、前室があります。')
        ->and(mb_strlen(ShortDescription::make(str_repeat('テント', 100))))->toBe(160)
        ->and(ShortDescription::make(str_repeat('word ', 60)))->toEndWith('word…')
        ->and(ShortDescription::make('<br>'))->toBe('');
});
