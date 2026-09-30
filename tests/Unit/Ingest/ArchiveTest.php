<?php

declare(strict_types=1);

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\Format;

function refusedBecause(callable $read): UnreadableReason
{
    try {
        $read();
    } catch (UnreadableFeed $exception) {
        return $exception->reason;
    }

    throw new RuntimeException('expected the feed to be refused');
}

$csv = "sku,title\nA-1,Hammer\nA-2,Nail\n";

it('decompresses a gzip feed (D1: gz feeds were never decompressed)', function () use ($csv): void {
    $read = readFeed(feedFile((string) gzencode($csv, 9), 'feed.csv'));

    expect($read['rows'])->toHaveCount(2)
        ->and($read['feed']->containers)->toBe(['gzip'])
        ->and($read['feed']->format)->toBe(Format::Csv);
});

it('recognises gzip by its content, whatever the file is called', function () use ($csv): void {
    expect(readFeed(feedFile((string) gzencode($csv), 'feed.xml'))['rows'])->toHaveCount(2);
});

it('refuses a truncated gzip instead of importing what arrived (the legacy served one)', function () use ($csv): void {
    $big = $csv.str_repeat("A-3,Saw with a long title\n", 50_000);
    $gzip = (string) gzencode($big, 9);

    expect(refusedBecause(fn (): array => readFeed(feedFile(substr($gzip, 0, (int) (strlen($gzip) * 0.7))))))
        ->toBe(UnreadableReason::TruncatedArchive);
});

it('refuses a gzip whose checksum does not match its data', function () use ($csv): void {
    $gzip = (string) gzencode($csv);
    $gzip[strlen($gzip) - 6] = chr(ord($gzip[strlen($gzip) - 6]) ^ 0xFF); // flip a CRC-32 byte

    expect(refusedBecause(fn (): array => readFeed(feedFile($gzip))))->toBe(UnreadableReason::CorruptArchive);
});

it('reads every member of a concatenated gzip', function (): void {
    $gzip = gzencode("sku,title\nA-1,Hammer\n").gzencode("A-2,Nail\n");

    expect(readRows($gzip))->toBe([['sku' => 'A-1', 'title' => 'Hammer'], ['sku' => 'A-2', 'title' => 'Nail']]);
});

it('refuses a gzip bomb before it fills the disk', function (): void {
    $bomb = (string) gzencode(str_repeat("\0", 20 * 1024 * 1024), 9); // 20 MB of zeros ≈ 20 KB

    expect(refusedBecause(fn (): array => readFeed(feedFile($bomb), limits: testLimits(['ratioAfterBytes' => 1024 * 1024]))))
        ->toBe(UnreadableReason::DecompressionBomb);
});

it('refuses a gzip larger than the inflate cap', function () use ($csv): void {
    $gzip = (string) gzencode(str_repeat($csv, 1000));

    expect(refusedBecause(fn (): array => readFeed(feedFile($gzip), limits: testLimits(['maxInflatedBytes' => 1024]))))
        ->toBe(UnreadableReason::DecompressionBomb);
});

it('takes the feed out of a zip', function () use ($csv): void {
    $read = readFeed(zipFile(['export/products.csv' => $csv]));

    expect($read['rows'])->toHaveCount(2)
        ->and($read['feed']->containers)->toBe(['zip'])
        ->and($read['feed']->innerName)->toBe('products.csv');
});

it('reads the largest feed in a zip with several, ignoring macOS debris, and says so', function () use ($csv): void {
    $read = readFeed(zipFile([
        '__MACOSX/._products.csv' => 'junk',
        '.DS_Store' => 'junk',
        'readme.txt' => "sku\nX\n",
        'products.csv' => $csv,
    ]));

    expect($read['feed']->innerName)->toBe('products.csv')
        ->and($read['warnings']->count('zip_many_files'))->toBe(1);
});

it('never writes an entry by its archived name ("zip slip")', function () use ($csv): void {
    $escape = ingestScratch().'/escaped.csv';
    $read = readFeed(zipFile(['../../escaped.csv' => $csv]));

    expect($read['rows'])->toHaveCount(2)
        ->and(file_exists($escape))->toBeFalse()
        ->and(file_exists(dirname(ingestScratch(), 2).'/escaped.csv'))->toBeFalse();
});

it('reads a gzip inside a zip, and no deeper', function () use ($csv): void {
    expect(readFeed(zipFile(['feed.csv.gz' => (string) gzencode($csv)]))['feed']->containers)->toBe(['zip', 'gzip']);

    $three = zipFile(['inner.zip' => (string) file_get_contents(zipFile(['feed.csv.gz' => (string) gzencode($csv)]))]);
    expect(refusedBecause(fn (): array => readFeed($three)))->toBe(UnreadableReason::UnsupportedFormat);
});

it('refuses damaged, empty, feedless and password-protected zips', function () use ($csv): void {
    // Incompressible content, cut at 60%: the tail and the central directory are gone.
    $damaged = zipFile(['products.csv' => "sku,token\n".bin2hex(random_bytes(20_000))."\n"]);
    $bytes = (string) file_get_contents($damaged);
    file_put_contents($damaged, substr($bytes, 0, (int) (strlen($bytes) * 0.6)));

    $protected = zipFile(['products.csv' => $csv], static function (ZipArchive $zip): void {
        $zip->setEncryptionName('products.csv', ZipArchive::EM_AES_256, 'secret');
    });

    expect(refusedBecause(fn (): array => readFeed($damaged)))->toBe(UnreadableReason::CorruptArchive)
        ->and(refusedBecause(fn (): array => readFeed(zipFile(['logo.png' => 'x', 'terms.pdf' => 'y']))))->toBe(UnreadableReason::NoFeedInArchive)
        ->and(refusedBecause(fn (): array => readFeed($protected)))->toBe(UnreadableReason::EncryptedArchive);
});

it('refuses a zip entry whose bytes do not match its CRC-32', function (): void {
    $path = zipFile(['products.csv' => "sku,title\nA-1,Hammer\n"], static function (ZipArchive $zip): void {
        $zip->setCompressionName('products.csv', ZipArchive::CM_STORE);
    });
    $bytes = (string) file_get_contents($path);
    $offset = strpos($bytes, 'Hammer');
    $bytes[$offset] = 'J'; // same length, different data: only the checksum can tell
    file_put_contents($path, $bytes);

    expect(refusedBecause(fn (): array => readFeed($path)))->toBe(UnreadableReason::CorruptArchive);
});

it('refuses a zip entry that inflates past the cap', function (): void {
    $bomb = zipFile(['products.csv' => str_repeat('0', 5 * 1024 * 1024)]);

    expect(refusedBecause(fn (): array => readFeed($bomb, limits: testLimits(['ratioAfterBytes' => 1024 * 1024]))))
        ->toBe(UnreadableReason::DecompressionBomb);
});
