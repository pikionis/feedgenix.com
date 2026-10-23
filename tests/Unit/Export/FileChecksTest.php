<?php

declare(strict_types=1);

use App\Domain\Export\Data\ExportColumn;
use App\Domain\Export\Dialects\Rfc4180Csv;
use App\Domain\Export\Dialects\Tsv;
use App\Domain\Export\FileChecks;

/*
 * The checks a build passes before it is published. The legacy check let 1% of the rows go missing
 * and parsed with other rules than the writer; its gzip once decompressed to 218,363 of 319,836 rows.
 */

function checkedFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'fg-check-');
    file_put_contents($path, $contents);

    return $path;
}

function skuAndTitle(): array
{
    return [new ExportColumn('sku', 'sku', null, null, true, true), new ExportColumn('title', 'title', 'de', null, false, false)];
}

it('accepts a whole file with exactly the rows written', function (): void {
    FileChecks::rows(checkedFile("sku,title\r\nA,Zelt\r\nB,\"Zelt, gross\"\r\n"), skuAndTitle(), new Rfc4180Csv, 2);

    expect(true)->toBeTrue();
});

it('refuses a file that differs in any way from what was written', function (string $contents, int $written, string $why): void {
    expect(fn () => FileChecks::rows(checkedFile($contents), skuAndTitle(), new Tsv, $written))->toThrow(RuntimeException::class, $why);
})->with([
    'one row missing' => ["sku\ttitle\nA\tZelt\n", 2, '1 rows instead of 2'],
    'one row too many' => ["sku\ttitle\nA\tZelt\nB\tZelt\nC\tZelt\n", 2, '3 rows instead of 2'],
    'a cell too many' => ["sku\ttitle\nA\tZelt\textra\n", 1, '3 cells instead of 2'],
    'a row without SKU' => ["sku\ttitle\n\tZelt\n", 1, 'no SKU'],
    'another header' => ["id\ttitle\nA\tZelt\n", 1, 'header'],
    'cut off' => ["sku\ttitle\nA\tZelt\nB\tZe", 2, 'cut off'],
]);

it('accepts a gzip only when it decompresses to the exact bytes', function (): void {
    $plain = str_repeat("AO-1001\tWanderschuh aus Leder\n", 5000);
    $path = checkedFile($plain);

    $gzip = FileChecks::gzip($path, strlen($plain), hash('sha256', $plain));
    expect(gzdecode((string) file_get_contents($path.'.gz')))->toBe($plain)
        ->and($gzip['gz_bytes'])->toBe(filesize($path.'.gz'));

    // Cut off, or of another file: refused.
    $whole = (string) file_get_contents($path.'.gz');
    file_put_contents($path.'.gz', substr($whole, 0, (int) (strlen($whole) / 2)));
    expect(fn () => FileChecks::inflatesTo($path.'.gz', strlen($plain), hash('sha256', $plain)))->toThrow(RuntimeException::class);
    file_put_contents($path.'.gz', (string) gzencode($plain.'x'));
    expect(fn () => FileChecks::inflatesTo($path.'.gz', strlen($plain), hash('sha256', $plain)))->toThrow(RuntimeException::class, 'does not decompress');
});
