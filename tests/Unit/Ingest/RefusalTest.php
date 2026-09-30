<?php

declare(strict_types=1);

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;

function refusedWith(callable $read): UnreadableFeed
{
    try {
        $read();
    } catch (UnreadableFeed $exception) {
        return $exception;
    }

    throw new RuntimeException('expected the feed to be refused');
}

it('refuses what is not a feed, and says what it is instead', function (string $bytes, UnreadableReason $reason, string $says): void {
    $refusal = refusedWith(fn (): array => readFeed(feedFile($bytes)));

    expect($refusal->reason)->toBe($reason)
        ->and($refusal->getMessage())->toContain($says);
})->with([
    'empty file' => ['', UnreadableReason::Empty, 'empty'],
    'only a BOM and blanks' => ["\xEF\xBB\xBF \n\n", UnreadableReason::Empty, 'empty'],
    'old Excel (.xls)' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 512), UnreadableReason::LegacyExcel, '.xlsx or CSV'],
    'a web page (a login page behind a feed URL)' => ["<!DOCTYPE html>\n<html><body>Please log in</body></html>", UnreadableReason::UnsupportedFormat, 'web page'],
    'a PDF' => ["%PDF-1.7\n...", UnreadableReason::UnsupportedFormat, 'PDF'],
    'binary data' => [random_bytes(4096), UnreadableReason::UnsupportedFormat, 'not a CSV'],
]);

it('refuses a file over the size cap before reading it', function (): void {
    $refusal = refusedWith(fn (): array => readFeed(feedFile("sku\n".str_repeat("A-1\n", 1000)), limits: testLimits(['maxBytes' => 1000])));

    expect($refusal->reason)->toBe(UnreadableReason::TooLarge);
});

it('refuses more rows than one import may hold, naming the cap', function (): void {
    $refusal = refusedWith(fn (): array => readFeed(feedFile("sku\nA\nB\nC\n"), limits: testLimits(['maxRows' => 2])));

    expect($refusal->reason)->toBe(UnreadableReason::TooManyRows)
        ->and($refusal->getMessage())->toContain('2 rows');
});

it('refuses more columns than the cap, including columns that appear later in XML', function (): void {
    $xml = '<products><product><a>1</a></product><product><b>2</b><c>3</c></product></products>';

    expect(refusedWith(fn (): array => readFeed(feedFile($xml), limits: testLimits(['maxColumns' => 2])))->reason)
        ->toBe(UnreadableReason::TooManyColumns);
});

it('refuses a single value over the cell cap, naming the row and column', function (): void {
    $refusal = refusedWith(fn (): array => readFeed(feedFile("sku,description\nA-1,short\nA-2,".str_repeat('x', 2048)."\n"), limits: testLimits(['maxCellBytes' => 1024])));

    expect($refusal->reason)->toBe(UnreadableReason::CellTooLarge)
        ->and($refusal->getMessage())->toContain('Row 2')
        ->and($refusal->getMessage())->toContain('description');
});

it('refuses an XML that declares an encoding it cannot convert', function (): void {
    expect(refusedWith(fn (): array => readFeed(feedFile('<?xml version="1.0" encoding="EBCDIC-CP-US"?><p><i>1</i></p>')))->reason)
        ->toBe(UnreadableReason::UnknownEncoding);
});

it('never modifies the received file', function (): void {
    $bytes = "\xEF\xBB\xBFsku;title\nA-1;Schl\xC3\xB6sser\n";
    $path = feedFile($bytes);

    readFeed($path);

    expect(file_get_contents($path))->toBe($bytes);
});
