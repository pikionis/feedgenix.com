<?php

declare(strict_types=1);

use App\Domain\Quality\QualityChecker;

/*
 * The checks a translation passes before it is published. Legacy QualityChecker: numbers matched as
 * substrings ("100" found in "1000"), no HTML, script or echo checks, so an exact echo passed.
 */

function quality(string $source, string $translation, string $target = 'en', string $field = 'title'): array
{
    $result = QualityChecker::check($field, $source, $translation, $target);

    return ['failures' => $result->failures, 'hard' => $result->isHardFail()];
}

it('passes a faithful translation', function (): void {
    expect(quality('<p>Wanderschuh aus Leder, 2 Paar, Grösse 42, 189.90 CHF</p>', '<p>Leather hiking boot, 2 pairs, size 42, 189.90 CHF</p>', 'en', 'description'))
        ->toBe(['failures' => [], 'hard' => false]);
});

it('finds a changed, lost or invented number as a whole token, not inside another (legacy substring bug)', function (string $source, string $translation, bool $hard): void {
    expect(quality($source, $translation)['hard'])->toBe($hard);
})->with([
    '100 became 1000' => ['Zelt für 100 kg', 'Tent for 1000 kg', true],
    'a number dropped' => ['Rucksack 30 l, 2 Fächer', 'Backpack 30 l, two compartments', true],
    'a model code dropped' => ['Kocher XT200 aus Stahl', 'Steel stove', true],
    'decimal comma becomes a point' => ['Preis 19,90 für 2 Stück', 'Price 19.90 for 2 pieces', false],
    'numbers kept, reordered' => ['Grösse 42, 2 Paar', '2 pairs, size 42', false],
    'a number invented' => ['Wanderschuh aus Leder', 'Leather hiking boot 999', true],
    'a number repeated' => ['Zelt für 2 Personen', 'Tent for 2 people, 2 doors', true],
]);

it('refuses a translation whose HTML differs', function (): void {
    expect(quality('<ul><li>Leder</li><li>Wasserdicht</li></ul>', '<ul><li>Leather, waterproof</li></ul>', 'en', 'description'))
        ->toMatchArray(['hard' => true])
        ->and(quality('<ul><li>Leder</li><li>Wasserdicht</li></ul>', '<ul><li>Leather, waterproof</li></ul>', 'en', 'description')['failures'])->toContain('html');
});

it('refuses a translation not written in the target script', function (string $target, string $translation, bool $hard): void {
    expect(quality('Wasserdichte Jacke mit Kapuze', $translation, $target)['hard'])->toBe($hard);
})->with([
    'latin into greek' => ['el', 'Waterproof jacket with hood', true],
    'greek into greek' => ['el', 'Αδιάβροχο μπουφάν με κουκούλα', false],
    'japanese into japanese' => ['ja', '防水ジャケット、フード付き', false],
    'latin into arabic' => ['ar', 'Waterproof jacket', true],
]);

it('refuses a category path whose levels changed', function (): void {
    expect(quality('Sport > Outdoor > Zelte', 'Sports > Outdoor tents', 'en', 'category')['failures'])->toContain('levels')
        ->and(quality('Sport > Outdoor > Zelte', 'Sports > Outdoor > Tents', 'en', 'category')['failures'])->toBe([]);
});

it('flags an echo of the source for review, without blocking it alone', function (): void {
    expect(quality('Wasserdichte Jacke mit Kapuze', 'Wasserdichte Jacke mit Kapuze'))->toBe(['failures' => ['echo'], 'hard' => false])
        ->and(quality('Gore-Tex Pro', 'Gore-Tex Pro')['failures'])->toBe([]);
});

it('blocks two soft failures together, and an empty answer', function (): void {
    // Too long, and the brand names gone.
    expect(quality('Hülle für iPhone und MacBook aus Leder', 'A case for a phone and for a laptop, made of leather, with a lot more text that the source never had and that makes it far too long'))
        ->toBe(['failures' => ['length', 'brands'], 'hard' => true])
        ->and(quality('Jacke', '   ')['hard'])->toBeTrue();
});

it('flags brand names that disappeared', function (): void {
    expect(quality('Hülle für iPhone und MacBook', 'Case for phone and laptop')['failures'])->toContain('brands')
        ->and(quality('Hülle für iPhone und MacBook', 'Case for iPhone and MacBook')['failures'])->toBe([]);
});
