<?php

declare(strict_types=1);

use App\Domain\Ingest\Encoding\EncodingDetector;
use App\Domain\Ingest\Encoding\Encodings;
use App\Domain\Ingest\ParseOptions;

$utf16 = static fn (string $text, string $encoding): string => mb_convert_encoding($text, $encoding, 'UTF-8');

it('reads UTF-8 with a byte-order mark without keeping the mark in the first header', function (): void {
    $read = readFeed(feedFile("\xEF\xBB\xBFsku,title\nA-1,Crème brûlée\n"));

    expect($read['rows'])->toBe([['sku' => 'A-1', 'title' => 'Crème brûlée']])
        ->and($read['feed']->encoding?->reason)->toBe('bom');
});

it('reads UTF-16 with a BOM, little- and big-endian', function (string $bom, string $encoding) use ($utf16): void {
    $read = readFeed(feedFile($bom.$utf16("sku;title\nA-1;Überzieher €\n", $encoding)));

    expect($read['rows'])->toBe([['sku' => 'A-1', 'title' => 'Überzieher €']])
        ->and($read['feed']->encoding?->name)->toBe($encoding);
})->with([
    'little-endian' => ["\xFF\xFE", 'UTF-16LE'],
    'big-endian' => ["\xFE\xFF", 'UTF-16BE'],
]);

it('recognises UTF-16 without a BOM from its NUL pattern', function () use ($utf16): void {
    $read = readFeed(feedFile($utf16("sku\ttitle\nA-1\tNiño\nA-2\tÇorap\n", 'UTF-16LE')));

    expect($read['feed']->encoding?->name)->toBe('UTF-16LE')
        ->and($read['feed']->encoding?->reason)->toBe('utf16_pattern')
        ->and($read['rows'][1]['title'])->toBe('Çorap');
});

it('converts Windows-1252 when the bytes are not UTF-8 (D2: auto converted nothing)', function (): void {
    // ö = 0xF6, € = 0x80, “ ” = 0x93 0x94: the legacy feed's encoding.
    $read = readFeed(feedFile("sku;title;price\nA-1;Schl\xF6sser \x93Premium\x94;\x8099\n"));

    expect($read['rows'])->toBe([['sku' => 'A-1', 'title' => 'Schlösser “Premium”', 'price' => '€99']])
        ->and($read['feed']->encoding?->name)->toBe('Windows-1252')
        ->and($read['feed']->encoding?->reason)->toBe('fallback');
});

it('reads Greek and Polish exports when the customer names the encoding', function (string $encoding, string $bytes, string $text): void {
    $rows = readRows("sku;title\nA-1;{$bytes}\n", new ParseOptions(encoding: $encoding));

    expect($rows[0]['title'])->toBe($text);
})->with([
    'Greek Windows (Excel "ANSI")' => ['Windows-1253', "\xD0\xEF\xF4\xE7\xF1\xE9 \xA2\xED\xE8\xF1\xF9\xF0\xEF\xF2", 'Ποτηρι Άνθρωπος'],
    'Greek ISO' => ['ISO-8859-7', "\xE1\xE2\xE3", 'αβγ'],
    'Central European Windows' => ['Windows-1250', "Zestaw garnk\xF3w \xB9 \x9C", 'Zestaw garnków ą ś'],
    'Turkish Windows' => ['Windows-1254', "\xF0\xFD\xFE", 'ğış'],
]);

it('keeps an emoji whose UTF-16 surrogate pair straddles a 1 MB chunk boundary', function () use ($utf16): void {
    // Chunks start after the 2-byte BOM. "sku\n" is 8 bytes, each "a" 2: with 2^19 - 5 of them the
    // high surrogate is the chunk's last unit (bytes 1,048,574-5) and the low one opens the next.
    $padding = str_repeat('a', (1 << 19) - 5);
    $bytes = "\xFF\xFE".$utf16("sku\n{$padding}😀\n", 'UTF-16LE');

    $rows = readRows($bytes);

    expect($rows[0]['sku'])->toBe($padding.'😀');
});

it('keeps a UTF-8 character that straddles a 1 MB chunk boundary', function (): void {
    $padding = str_repeat('a', (1 << 20) - 5); // "sku\n" + padding puts "é" across the boundary
    $rows = readRows("sku\n{$padding}é\n");

    expect($rows[0]['sku'])->toBe($padding.'é');
});

it('replaces and counts invalid bytes in an otherwise UTF-8 file instead of failing or dropping them', function (): void {
    // Valid UTF-8 up front (so detection says UTF-8), one stray byte far past the sample.
    $padding = str_repeat("A-0,ok\n", 200_000);
    $read = readFeed(feedFile("sku,title\n{$padding}A-1,bad \xFF byte\n"));

    expect(end($read['rows'])['title'])->toBe("bad \u{FFFD} byte")
        ->and($read['warnings']->count('invalid_characters'))->toBe(1);
});

it('removes NUL bytes, which PostgreSQL text cannot hold', function (): void {
    $read = readFeed(feedFile("sku,title\nA-1,Ham\0mer\n"));

    expect($read['rows'][0]['title'])->toBe('Hammer')
        ->and($read['warnings']->count('nul_bytes'))->toBe(1);
});

it('knows every encoding it offers, under common spellings', function (string $spelling, string $canonical): void {
    $converter = Encodings::converter($canonical);
    $encoded = (string) UConverter::transcode('a', $converter, 'UTF-8');

    expect(Encodings::canonical($spelling))->toBe($canonical)
        ->and(UConverter::transcode($encoded, 'UTF-8', $converter))->toBe('a');
})->with([
    ['utf8', 'UTF-8'], ['latin1', 'ISO-8859-1'], ['cp1252', 'Windows-1252'], ['windows-1253', 'Windows-1253'],
    ['CP1250', 'Windows-1250'], ['iso_8859_7', 'ISO-8859-7'], ['UTF-16le', 'UTF-16LE'],
]);

it('refuses to pretend it knows an encoding it cannot convert', function (): void {
    expect(Encodings::canonical('EBCDIC-037'))->toBeNull()
        ->and(fn (): ParseOptions => new ParseOptions(encoding: 'EBCDIC-037'))->toThrow(InvalidArgumentException::class);
});

it('detects ASCII and valid UTF-8, even when the sample ends inside a character', function (): void {
    expect(EncodingDetector::detect("sku\nA-1\n", true)->reason)->toBe('ascii')
        ->and(EncodingDetector::detect("sku\nCr\xC3\xA8me \xC3", false)->name)->toBe('UTF-8')
        ->and(EncodingDetector::detect("sku\nCr\xC3\xA8me \xC3", true)->name)->toBe('Windows-1252');
});
