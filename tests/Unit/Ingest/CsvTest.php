<?php

declare(strict_types=1);

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Ingest\Readers\CsvRowReader;

it('reads a comma-separated feed into rows keyed by the header', function (): void {
    expect(readRows("sku,title,price\nA-1,Hammer,9.90\nA-2,Nail,0.05\n"))->toBe([
        ['sku' => 'A-1', 'title' => 'Hammer', 'price' => '9.90'],
        ['sku' => 'A-2', 'title' => 'Nail', 'price' => '0.05'],
    ]);
});

it('detects semicolon, tab and pipe delimiters', function (string $delimiter): void {
    $csv = implode("\n", ["sku{$delimiter}title", "A-1{$delimiter}Hammer", "A-2{$delimiter}Nail"])."\n";

    expect(readRows($csv))->toBe([['sku' => 'A-1', 'title' => 'Hammer'], ['sku' => 'A-2', 'title' => 'Nail']]);
})->with(['semicolon' => ';', 'tab' => "\t", 'pipe' => '|']);

it('is not fooled by commas inside quoted values of a semicolon feed', function (): void {
    $csv = "sku;colour;price\nA-1;\"Blue, red, green\";1,5\nA-2;\"Black, white\";2,5\n";

    expect(readRows($csv))->toBe([
        ['sku' => 'A-1', 'colour' => 'Blue, red, green', 'price' => '1,5'],
        ['sku' => 'A-2', 'colour' => 'Black, white', 'price' => '2,5'],
    ]);
});

it('uses the Excel sep= line and never reads it as the header', function (): void {
    expect(readRows("sep=;\nsku;title\nA-1;Hammer\n"))->toBe([['sku' => 'A-1', 'title' => 'Hammer']]);
});

it('uses the customer’s delimiter over detection', function (): void {
    $rows = readRows("sku,title\nA-1,Hammer\n", new ParseOptions(delimiter: ';'));

    expect($rows)->toBe([['sku,title' => 'A-1,Hammer']]);
});

it('keeps quoted line breaks, doubled quotes and delimiters inside a value (RFC 4180)', function (): void {
    $csv = "sku,description\nA-1,\"Line one\nLine two, with a comma and \"\"quotes\"\"\"\n";

    expect(readRows($csv))->toBe([['sku' => 'A-1', 'description' => "Line one\nLine two, with a comma and \"quotes\""]]);
});

it('treats a backslash as an ordinary character (no non-standard escape)', function (): void {
    $csv = "sku,category,path\nA-1,\"Home\\Garden\",\"C:\\\"\n";

    expect(readRows($csv))->toBe([['sku' => 'A-1', 'category' => 'Home\\Garden', 'path' => 'C:\\']]);
});

it('keeps golden values byte for byte: prices, leading zeros, long barcodes, spaces', function (): void {
    $csv = "sku,gtin,price,stock,weight\n007,0012345678905,\"1.299,00\",  12 ,0.50kg\n";

    expect(readRows($csv))->toBe([['sku' => '007', 'gtin' => '0012345678905', 'price' => '1.299,00', 'stock' => '  12 ', 'weight' => '0.50kg']]);
});

it('makes duplicate and blank headers distinct instead of failing (D3)', function (): void {
    $rows = readRows("sku,price,price,,Price,price (2)\nA-1,1,2,3,4,5\n");

    expect($rows)->toBe([['sku' => 'A-1', 'price' => '1', 'price (2)' => '2', 'Column 4' => '3', 'Price' => '4', 'price (2) (2)' => '5']]);
});

it('pads short rows, drops empty trailing fields and warns about data past the header', function (): void {
    $read = readFeed(feedFile("sku,title,price\nA-1,Hammer\nA-2,Nail,1,,\nA-3,Saw,2,surplus\n"));

    expect($read['rows'])->toBe([
        ['sku' => 'A-1', 'title' => 'Hammer', 'price' => ''],
        ['sku' => 'A-2', 'title' => 'Nail', 'price' => '1'],
        ['sku' => 'A-3', 'title' => 'Saw', 'price' => '2'],
    ])
        ->and($read['warnings']->count('short_rows'))->toBe(1)
        ->and($read['warnings']->count('long_rows'))->toBe(1);
});

it('skips blank lines anywhere, including before the header', function (): void {
    expect(readRows("\n\nsku,title\n\nA-1,Hammer\n , \nA-2,Nail\n\n"))->toBe([
        ['sku' => 'A-1', 'title' => 'Hammer'],
        ['sku' => 'A-2', 'title' => 'Nail'],
    ]);
});

it('reads Windows and old Mac line endings', function (string $newline): void {
    expect(readRows("sku,title{$newline}A-1,Hammer{$newline}A-2,Nail{$newline}"))->toHaveCount(2);
})->with(['CRLF' => "\r\n", 'LF' => "\n"]);

it('reads a single-column feed', function (): void {
    expect(readRows("sku\nA-1\nA-2\n"))->toBe([['sku' => 'A-1'], ['sku' => 'A-2']]);
});

it('reads unquoted fields when quoting is switched off', function (): void {
    $rows = readRows("sku;title\nA-1;12\" Pizza pan\n", new ParseOptions(quoted: false));

    expect($rows)->toBe([['sku' => 'A-1', 'title' => '12" Pizza pan']]);
});

it('refuses a file with only blank lines', function (): void {
    expect(fn (): array => readRows("\n\n  \n"))->toThrow(UnreadableFeed::class, 'empty');
});

it('reports which delimiter it used', function (): void {
    $path = feedFile("sku;title\nA-1;Hammer\n");

    expect((new CsvRowReader($path))->delimiter())->toBe(';');
});

it('names the refusal when there is no header', function (): void {
    try {
        iterator_to_array((new CsvRowReader(feedFile(" , ,\n")))->rows());
        $this->fail('expected a refusal');
    } catch (UnreadableFeed $exception) {
        expect($exception->reason)->toBe(UnreadableReason::NoHeader);
    }
});
