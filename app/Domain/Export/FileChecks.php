<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Export\Data\ExportColumn;
use App\Domain\Export\Dialects\Dialect;
use RuntimeException;

/**
 * The checks a build passes before it is published (ADR 0007): every file read back through the
 * dialect that wrote it, and every gzip decompressed back to the file's exact bytes.
 */
final class FileChecks
{
    /** @param  list<ExportColumn>  $columns */
    public static function rows(string $path, array $columns, Dialect $dialect, int $written): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The file could not be read back.');
        }
        $expected = array_map(static fn (ExportColumn $column): string => $column->header, $columns);
        $sku = array_search('sku', array_map(static fn (ExportColumn $column): string => $column->field, $columns), true);
        $rows = -1;
        try {
            foreach ($dialect->read($handle) as $index => $cells) {
                if ($index === 0) {
                    if ($cells !== $expected) {
                        throw new RuntimeException('The header read back differs from the one written.');
                    }

                    continue;
                }
                if (count($cells) !== count($expected)) {
                    throw new RuntimeException("Row {$index} has ".count($cells).' cells instead of '.count($expected).'.');
                }
                if ($sku !== false && trim($cells[$sku]) === '') {
                    throw new RuntimeException("Row {$index} has no SKU.");
                }
                $rows = $index;
            }
        } finally {
            fclose($handle);
        }
        if (max(0, $rows) !== $written) {
            throw new RuntimeException('The file holds '.max(0, $rows)." rows instead of {$written}.");
        }
    }

    /** @return array{gz_bytes: int, gz_sha256: string} */
    public static function gzip(string $path, int $bytes, string $sha256): array
    {
        $source = fopen($path, 'rb');
        $target = gzopen($path.'.gz', 'wb6');
        if ($source === false || $target === false) {
            throw new RuntimeException('The gzip could not be written.');
        }
        try {
            while (! feof($source)) {
                $chunk = (string) fread($source, 1 << 16);
                if ($chunk !== '' && gzwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('The gzip could not be written.');
                }
            }
        } finally {
            fclose($source);
            if (! gzclose($target)) {
                throw new RuntimeException('The gzip could not be finished.');
            }
        }

        self::inflatesTo($path.'.gz', $bytes, $sha256);

        return ['gz_bytes' => (int) filesize($path.'.gz'), 'gz_sha256' => (string) hash_file('sha256', $path.'.gz')];
    }

    /** A gzip that decompresses to exactly these bytes, or an exception. */
    public static function inflatesTo(string $gzip, int $bytes, string $sha256): void
    {
        $check = gzopen($gzip, 'rb');
        if ($check === false) {
            throw new RuntimeException('The gzip could not be read back.');
        }
        $hash = hash_init('sha256');
        $inflated = 0;
        while (! gzeof($check)) {
            $chunk = gzread($check, 1 << 16);
            if ($chunk === false) {
                gzclose($check);

                throw new RuntimeException('The gzip is damaged.');
            }
            $inflated += strlen($chunk);
            hash_update($hash, $chunk);
        }
        gzclose($check);
        if ($inflated !== $bytes || hash_final($hash) !== $sha256) {
            throw new RuntimeException('The gzip does not decompress to the file.');
        }
    }
}
