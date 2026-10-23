<?php

declare(strict_types=1);

use App\Domain\Export\Dialects\Dialect;
use App\Domain\Export\Dialects\Rfc4180Csv;
use App\Domain\Export\Dialects\Tsv;

/*
 * The dialects (ADR 0007): what a cell becomes, and that reading a file back gives every value as
 * it was written. Legacy escapeField turned every " into ' (23" became 23') and its check parsed
 * with other rules than its writer.
 */

/** @return list<list<string>> the rows written and read back */
function roundTrip(Dialect $dialect, array $rows, bool $exact = false): array
{
    $stream = fopen('php://memory', 'w+b');
    foreach ($rows as $row) {
        fwrite($stream, $dialect->line(array_map(fn (string $value): string => (string) $dialect->cell($value, $exact), $row)));
    }
    rewind($stream);

    return iterator_to_array($dialect->read($stream), false);
}

it('writes any value unchanged as CSV, quotes and line breaks included', function (): void {
    $rows = [
        ['sku', 'title', 'description'],
        ['00123', 'Monitor 23" Pro', "Line one\r\nLine two, with a comma"],
        ['A"B', 'C:\\path\\', '"quoted"'],
        ['', ' spaced ', 'tab	inside'],
    ];

    expect(roundTrip(new Rfc4180Csv, $rows, exact: true))->toBe($rows)
        ->and((new Rfc4180Csv)->cell('23" Pro', true))->toBe('"23"" Pro"')
        ->and((new Rfc4180Csv)->line(['a', 'b']))->toBe("a,b\r\n");
});

it('keeps TSV one line per row: texts lose their tabs and line breaks, golden values are never changed', function (): void {
    $tsv = new Tsv;

    expect($tsv->cell("Erste Zeile\r\n\tZweite", false))->toBe('Erste Zeile Zweite')
        ->and($tsv->cell("4006381\t333931", true))->toBeNull()
        ->and($tsv->cell('23" Pro, "Plus"', true))->toBe('23" Pro, "Plus"')
        ->and(roundTrip($tsv, [['sku', 'title'], ['007', 'Zelt "Alpin"; leicht']]))->toBe([['sku', 'title'], ['007', 'Zelt "Alpin"; leicht']]);
});

it('refuses a TSV file whose last line was cut off', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, "sku\ttitle\nAO-1\tZelt\nAO-2\tSchl");
    rewind($stream);

    expect(fn (): array => iterator_to_array((new Tsv)->read($stream)))->toThrow(RuntimeException::class, 'cut off');
});
