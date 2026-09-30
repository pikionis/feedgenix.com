<?php

declare(strict_types=1);

use App\Domain\Ingest\Profile\ColumnProfile;
use App\Domain\Ingest\Profile\ColumnProfiler;
use App\Domain\Ingest\Profile\FeedProfile;
use App\Domain\Ingest\Profile\ValueKind;

/** @param list<array<string, string>> $rows */
function profileOf(array $rows, ?ColumnProfiler $profiler = null): FeedProfile
{
    return ($profiler ?? new ColumnProfiler)->profile($rows);
}

function column(FeedProfile $profile, string $name): ColumnProfile
{
    foreach ($profile->columns as $column) {
        if ($column->name === $name) {
            return $column;
        }
    }

    throw new RuntimeException("no column {$name}");
}

it('counts rows and fill rates, treating blank-only values as empty', function (): void {
    $profile = profileOf([
        ['sku' => 'A-1', 'title' => 'Hammer'],
        ['sku' => 'A-2', 'title' => '   '],
        ['sku' => 'A-3', 'title' => ''],
        ['sku' => 'A-4', 'title' => 'Saw'],
    ]);

    expect($profile->rowCount)->toBe(4)
        ->and(column($profile, 'sku')->fillRate)->toBe(1.0)
        ->and(column($profile, 'title')->fillRate)->toBe(0.5)
        ->and(column($profile, 'title')->filled)->toBe(2);
});

it('lists columns in the order they first appear, including ones that start late (XML/JSON)', function (): void {
    $profile = profileOf([['a' => '1'], ['a' => '2', 'c' => 'x'], ['b' => 'y']]);

    expect($profile->columnNames())->toBe(['a', 'c', 'b'])
        ->and(column($profile, 'c')->fillRate)->toBe(0.3333);
});

it('recognises what each column holds', function (array $values, ValueKind $kind): void {
    $rows = array_map(static fn (string $value): array => ['c' => $value], $values);

    expect(column(profileOf($rows), 'c')->kind)->toBe($kind);
})->with([
    'GTINs (valid check digits)' => [['4006381333931', '5901234123457', '96385074'], ValueKind::Gtin],
    'integers' => [['0', '12', '-3'], ValueKind::Integer],
    'decimals, comma or dot, thousands' => [['19.90', '1.299,00', '0,5', '7'], ValueKind::Decimal],
    'codes with leading zeros are not numbers' => [['0012345', '007', 'SKU-1'], ValueKind::Code],
    'booleans' => [['true', 'FALSE', 'yes'], ValueKind::Boolean],
    'dates' => [['2026-09-29', '2026-09-29 14:30', '29.09.2026'], ValueKind::Date],
    'image URLs' => [['https://img.example/a.jpg', 'https://img.example/b.webp?w=800'], ValueKind::ImageUrl],
    'URLs (images included)' => [['https://shop.example/p/1', 'https://img.example/a.jpg'], ValueKind::Url],
    'HTML' => [['<p>Soft</p>', 'Plain <b>bold</b>'], ValueKind::Html],
    'text' => [['Blue', 'Hammer with a long handle'], ValueKind::Text],
    'nothing' => [['', ' '], ValueKind::Empty],
]);

it('rejects barcodes whose check digit is wrong', function (): void {
    expect(ValueKind::isGtin('4006381333931'))->toBeTrue()
        ->and(ValueKind::isGtin('4006381333932'))->toBeFalse()
        ->and(ValueKind::isGtin('400638133393'))->toBeFalse()
        ->and(ValueKind::isGtin('4006381333A31'))->toBeFalse();
});

it('keeps a few distinct examples per column, and the longest length in characters', function (): void {
    $profile = profileOf(array_map(static fn (int $i): array => ['c' => $i % 2 ? 'Zürich' : 'Ab'], range(1, 20)));

    expect(column($profile, 'c')->examples)->toBe(['Zürich', 'Ab'])
        ->and(column($profile, 'c')->maxLength)->toBe(6);
});

it('keeps the first rows as samples for the mapping review', function (): void {
    $rows = array_map(static fn (int $i): array => ['sku' => "A-{$i}"], range(1, 50));
    $profile = profileOf($rows, new ColumnProfiler(sampleRows: 3));

    expect($profile->sampleRows)->toBe([['sku' => 'A-1'], ['sku' => 'A-2'], ['sku' => 'A-3']]);
});

it('chooses the SKU column on evidence: filled and unique beats a better name', function (): void {
    $profile = profileOf([
        ['sku' => 'A-1', 'artikelnummer' => 'X-1'],
        ['sku' => 'A-1', 'artikelnummer' => 'X-2'], // "sku" repeats
        ['sku' => 'A-3', 'artikelnummer' => 'X-3'],
    ]);

    expect($profile->sku?->column)->toBe('artikelnummer')
        ->and($profile->sku?->confidence)->toBe('high');
});

it('recognises Google Shopping g:id as the SKU', function (): void {
    expect(profileOf([['g:id' => '1', 'title' => 'A'], ['g:id' => '2', 'title' => 'B']])->sku?->column)->toBe('g:id');
});

it('offers a low-confidence SKU with its gaps and repeats when nothing is clean', function (): void {
    $rows = array_merge(
        array_map(static fn (int $i): array => ['sku' => "A-{$i}"], range(1, 18)),
        [['sku' => 'A-1'], ['sku' => '']],
    );

    $sku = profileOf($rows)->sku;

    expect($sku?->column)->toBe('sku')
        ->and($sku?->confidence)->toBe('low')
        ->and($sku?->duplicates)->toBe(1)
        ->and($sku?->empty)->toBe(1);
});

it('suggests no SKU when no column name fits', function (): void {
    expect(profileOf([['title' => 'A'], ['title' => 'B']])->sku)->toBeNull();
});

it('does not claim uniqueness it could not check', function (): void {
    $rows = array_map(static fn (int $i): array => ['sku' => "A-{$i}"], range(1, 10));

    expect(profileOf($rows, new ColumnProfiler(maxTrackedSkus: 5))->sku?->confidence)->toBe('low');
});

it('gives the same column signature for the same columns in the same order only', function (): void {
    $a = profileOf([['sku' => '1', 'title' => 'A']]);
    $b = profileOf([['sku' => '2', 'title' => 'B']]);
    $c = profileOf([['title' => 'A', 'sku' => '1']]);

    expect($a->columnSignature())->toBe($b->columnSignature())
        ->and($a->columnSignature())->not->toBe($c->columnSignature());
});

it('profiles a numeric column name without mixing up the keys', function (): void {
    $profile = profileOf([['2024' => '1', 'sku' => 'A']]);

    expect($profile->columnNames())->toBe(['2024', 'sku']);
});
