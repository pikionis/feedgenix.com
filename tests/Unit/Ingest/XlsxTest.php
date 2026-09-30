<?php

declare(strict_types=1);

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Format;
use App\Domain\Ingest\Readers\XlsxRowReader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

it('reads the first sheet with its header, as text', function (): void {
    $read = readFeed(xlsxFile([
        ['sku', 'title', 'price', 'stock', 'gtin', 'active', 'released'],
        ['A-1', 'Hammer', 19.9, 12, 4006381333931, true, new DateTimeImmutable('2026-09-29')],
        ['A-2', 'Nail', 0.05, 0, 4006381333948, false, new DateTimeImmutable('2026-09-29 14:30:00')],
    ]));

    expect($read['feed']->format)->toBe(Format::Xlsx)
        ->and($read['rows'])->toBe([
            ['sku' => 'A-1', 'title' => 'Hammer', 'price' => '19.9', 'stock' => '12', 'gtin' => '4006381333931', 'active' => 'TRUE', 'released' => '2026-09-29'],
            ['sku' => 'A-2', 'title' => 'Nail', 'price' => '0.05', 'stock' => '0', 'gtin' => '4006381333948', 'active' => 'FALSE', 'released' => '2026-09-29 14:30:00'],
        ]);
});

it('keeps text cells exactly, leading zeros and all', function (): void {
    $rows = readFeed(xlsxFile([['sku', 'gtin', 'price'], ['007', '0012345678905', '1.299,00']]))['rows'];

    expect($rows)->toBe([['sku' => '007', 'gtin' => '0012345678905', 'price' => '1.299,00']]);
});

it('makes duplicate headers distinct, skips blank rows and pads short ones', function (): void {
    $rows = readFeed(xlsxFile([[], ['sku', 'price', 'price', ''], ['A-1', 1, 2, 3], [null, null], ['A-2']]))['rows'];

    expect($rows)->toBe([
        ['sku' => 'A-1', 'price' => '1', 'price (2)' => '2', 'Column 4' => '3'],
        ['sku' => 'A-2', 'price' => '', 'price (2)' => '', 'Column 4' => ''],
    ]);
});

it('reads only the first visible sheet and says so', function (): void {
    $path = ingestScratch().'/sheets.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Products');
    $writer->addRow(Row::fromValues(['sku']));
    $writer->addRow(Row::fromValues(['A-1']));
    $writer->addNewSheetAndMakeItCurrent()->setName('Notes');
    $writer->addRow(Row::fromValues(['ignore me']));
    $writer->close();

    $read = readFeed($path);

    expect($read['rows'])->toBe([['sku' => 'A-1']])
        ->and($read['warnings']->count('xlsx_more_sheets'))->toBe(1);
});

it('writes numbers in their shortest exact form', function (float $number, string $text): void {
    expect(XlsxRowReader::number($number))->toBe($text);
})->with([
    [19.9, '19.9'], [0.1 + 0.2, '0.30000000000000004'], [5.0, '5'], [4006381333931.0, '4006381333931'], [-0.05, '-0.05'], [1.0e20, '1.0e+20'], [INF, ''],
]);

it('streams a large workbook in bounded memory (D5: the legacy loaded it whole)', function (): void {
    $rows = [['sku', 'title', 'price']];
    for ($i = 0; $i < 20_000; $i++) {
        $rows[] = ["SKU-{$i}", str_repeat('Product title ', 5).$i, $i / 100];
    }
    $path = xlsxFile($rows);
    unset($rows);

    $before = memory_get_usage();
    $count = 0;
    foreach ((new XlsxRowReader($path, ingestScratch()))->rows() as $row) {
        $count++;
    }

    expect($count)->toBe(20_000)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(64 * 1024 * 1024);
});

it('refuses a damaged workbook', function (): void {
    $path = xlsxFile([['sku'], ['A-1']]);
    $bytes = (string) file_get_contents($path);
    file_put_contents($path, substr($bytes, 0, (int) (strlen($bytes) * 0.6)));

    expect(fn (): array => readFeed($path))->toThrow(UnreadableFeed::class);
});
