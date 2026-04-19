<?php

declare(strict_types=1);

namespace App\Services\Import;

use Generator;
use RuntimeException;

/**
 * Minimal CSV parser with auto-detected delimiter and encoding.
 *
 * We don't use league/csv because we only need two things: a fast header +
 * sample-rows extractor for the mapping phase, and a streaming row generator
 * for the import phase. Both fit in ~100 lines and keep the dependency surface
 * small.
 *
 * Supported delimiters: comma, semicolon, tab, pipe. Encoding is sniffed
 * against a small allowlist (UTF-8, UTF-8 BOM, UTF-16 LE/BE, Windows-1252,
 * ISO-8859-1) and re-encoded to UTF-8 on the fly.
 */
class CsvParser
{
    /** Bytes read when sniffing delimiter/encoding. */
    private const SNIFF_BYTES = 8192;

    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Preview a file: return headers + up to $sampleLimit rows + basic counts.
     *
     * @return array{headers: string[], samples: array<int, array<string, mixed>>, row_count: int, column_count: int, delimiter: string, encoding: string}
     */
    public function preview(string $absolutePath, int $sampleLimit = 5): array
    {
        $meta = $this->sniff($absolutePath);
        $handle = $this->openHandle($absolutePath, $meta['encoding']);

        try {
            $headers = $this->readHeaders($handle, $meta['delimiter']);

            $samples = [];
            $rowCount = 0;
            while (($row = fgetcsv($handle, 0, $meta['delimiter'])) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }
                $rowCount++;
                if (count($samples) < $sampleLimit) {
                    $samples[] = $this->rowToAssoc($headers, $row);
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'headers' => $headers,
            'samples' => $samples,
            'row_count' => $rowCount,
            'column_count' => count($headers),
            'delimiter' => $meta['delimiter'],
            'encoding' => $meta['encoding'],
        ];
    }

    /**
     * Stream every data row (no header) as an associative array. Memory-safe
     * for millions of rows because we never materialize the list.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $absolutePath): Generator
    {
        $meta = $this->sniff($absolutePath);
        $handle = $this->openHandle($absolutePath, $meta['encoding']);

        try {
            $headers = $this->readHeaders($handle, $meta['delimiter']);
            $rowNumber = 0;
            while (($row = fgetcsv($handle, 0, $meta['delimiter'])) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }
                $rowNumber++;
                yield $rowNumber => $this->rowToAssoc($headers, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{delimiter: string, encoding: string}
     */
    private function sniff(string $path): array
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open CSV at {$path}");
        }
        $chunk = fread($fp, self::SNIFF_BYTES) ?: '';
        fclose($fp);

        $encoding = $this->detectEncoding($chunk);
        // Re-encode the sniff chunk to UTF-8 so delimiter detection is reliable.
        $utf8Chunk = $this->toUtf8($chunk, $encoding);
        $delimiter = $this->detectDelimiter($utf8Chunk);

        return ['delimiter' => $delimiter, 'encoding' => $encoding];
    }

    private function detectEncoding(string $chunk): string
    {
        if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
            return 'UTF-8-BOM';
        }
        if (str_starts_with($chunk, "\xFF\xFE")) {
            return 'UTF-16LE';
        }
        if (str_starts_with($chunk, "\xFE\xFF")) {
            return 'UTF-16BE';
        }
        if (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($chunk, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($detected) {
                return $detected;
            }
        }
        return 'UTF-8';
    }

    private function detectDelimiter(string $chunk): string
    {
        // Consider the first handful of lines — headers alone aren't always enough.
        $lines = array_slice(preg_split('/\r\n|\r|\n/', $chunk) ?: [], 0, 5);
        $scores = [];
        foreach (self::DELIMITERS as $delim) {
            $counts = array_map(fn ($l) => substr_count($l, $delim), $lines);
            $counts = array_filter($counts, fn ($c) => $c > 0);
            if ($counts === []) {
                $scores[$delim] = 0;
                continue;
            }
            // Prefer delimiters that show consistent counts across lines.
            $min = min($counts);
            $max = max($counts);
            $scores[$delim] = $max === 0 ? 0 : ($min / $max) * $max;
        }
        arsort($scores);
        $top = (string) array_key_first($scores);
        return $scores[$top] > 0 ? $top : ',';
    }

    /** @return resource */
    private function openHandle(string $path, string $encoding)
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open CSV at {$path}");
        }

        // Strip UTF-8 BOM if present.
        if ($encoding === 'UTF-8-BOM') {
            fread($fp, 3);
        }

        // For non-UTF-8, wrap the stream with an iconv filter so downstream
        // fgetcsv sees clean UTF-8 regardless of source encoding.
        if (!in_array($encoding, ['UTF-8', 'UTF-8-BOM'], true)) {
            stream_filter_append($fp, "convert.iconv.{$encoding}/UTF-8//TRANSLIT//IGNORE");
        }

        return $fp;
    }

    /** @return string[] */
    private function readHeaders($handle, string $delimiter): array
    {
        $headers = fgetcsv($handle, 0, $delimiter);
        if ($headers === false || $headers === [null]) {
            throw new RuntimeException('CSV has no header row.');
        }
        return array_map(fn ($h) => trim((string) $h), $headers);
    }

    /**
     * @param  string[] $headers
     * @param  array<int, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToAssoc(array $headers, array $row): array
    {
        $out = [];
        foreach ($headers as $i => $name) {
            $out[$name] = $row[$i] ?? null;
        }
        return $out;
    }

    private function toUtf8(string $bytes, string $encoding): string
    {
        if (in_array($encoding, ['UTF-8', 'UTF-8-BOM'], true)) {
            return $encoding === 'UTF-8-BOM' ? substr($bytes, 3) : $bytes;
        }
        $converted = @iconv($encoding, 'UTF-8//TRANSLIT//IGNORE', $bytes);
        return $converted === false ? $bytes : $converted;
    }
}
