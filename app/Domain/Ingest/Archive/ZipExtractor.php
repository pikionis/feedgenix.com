<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Archive;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\IngestLimits;
use App\Domain\Ingest\Warnings;
use RuntimeException;
use ZipArchive;

/**
 * Takes the feed out of a zip archive. Nothing is ever extracted by its archived name: the one
 * entry that is the feed is streamed into a path FeedGenix chose, so "../" names cannot escape.
 * The entry's size and CRC-32 are checked while it streams, which catches truncated and forged
 * archives alike.
 */
final class ZipExtractor
{
    private const array FEED_EXTENSIONS = ['csv', 'tsv', 'txt', 'xml', 'json', 'jsonl', 'ndjson', 'xlsx', 'gz'];

    private const CHUNK = 1 << 20;

    /** An XLSX workbook is itself a zip: it is read as a spreadsheet, not unpacked. */
    public static function isSpreadsheet(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            return $zip->locateName('xl/workbook.xml') !== false;
        } finally {
            $zip->close();
        }
    }

    public static function extractFeed(string $source, string $destination, IngestLimits $limits, Warnings $warnings): string
    {
        $zip = new ZipArchive;
        $opened = $zip->open($source, ZipArchive::CHECKCONS | ZipArchive::RDONLY);
        if ($opened !== true) {
            throw UnreadableFeed::because(UnreadableReason::CorruptArchive, 'The zip file is damaged or incomplete.');
        }

        try {
            [$entry, $candidates, $files] = self::choose($zip);
            if ($entry === null) {
                $listed = implode(', ', array_slice($files, 0, 5));

                throw UnreadableFeed::because(UnreadableReason::NoFeedInArchive, $files === []
                    ? 'The zip file is empty.'
                    : "The zip file holds no CSV, XML, JSON or Excel feed (it has: {$listed}).");
            }

            if ($entry['encryption_method'] !== ZipArchive::EM_NONE) {
                throw UnreadableFeed::because(UnreadableReason::EncryptedArchive, 'The zip file is password-protected. Upload it without a password.');
            }

            if ($candidates > 1) {
                $warnings->add('zip_many_files', "The zip file holds {$candidates} feed files; FeedGenix read the largest, {$entry['name']}. If that is not the feed, upload the right file on its own.");
            }

            self::guardDeclaredSize($entry, $limits);
            self::copy($zip, $entry, $destination, $limits);

            return basename($entry['name']);
        } finally {
            $zip->close();
        }
    }

    /**
     * The largest feed-like entry; without one, a lone file of any name.
     *
     * @return array{0: array{name: string, index: int, size: int, comp_size: int, crc: int, encryption_method: int}|null, 1: int, 2: list<string>}
     */
    private static function choose(ZipArchive $zip): array
    {
        $feeds = [];
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];
            $base = basename($name);
            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_starts_with($base, '.')) {
                continue;
            }

            $entry = [
                'name' => $name,
                'index' => $index,
                'size' => $stat['size'],
                'comp_size' => $stat['comp_size'],
                'crc' => $stat['crc'],
                'encryption_method' => $stat['encryption_method'],
            ];
            $files[] = $entry;
            if (in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), self::FEED_EXTENSIONS, true)) {
                $feeds[] = $entry;
            }
        }

        $pool = $feeds !== [] ? $feeds : (count($files) === 1 ? $files : []);
        usort($pool, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return [$pool[0] ?? null, count($feeds), array_map(static fn (array $file): string => $file['name'], $files)];
    }

    /** @param array{size: int, comp_size: int} $entry */
    private static function guardDeclaredSize(array $entry, IngestLimits $limits): void
    {
        // Larger than the limit is a large feed; a ratio no real feed has is a bomb.
        if ($entry['size'] > $limits->maxInflatedBytes) {
            throw UnreadableFeed::because(UnreadableReason::TooLarge, sprintf('Uncompressed, the feed is larger than %s MB.', number_format($limits->maxInflatedBytes / 1024 ** 2)));
        }
        if ($entry['size'] > $limits->ratioAfterBytes && $entry['size'] > max(1, $entry['comp_size']) * $limits->maxInflateRatio) {
            throw UnreadableFeed::because(UnreadableReason::DecompressionBomb, 'The zip file expands far beyond any real feed, so it was not read.');
        }
    }

    /** @param array{name: string, index: int, size: int, crc: int} $entry */
    private static function copy(ZipArchive $zip, array $entry, string $destination, IngestLimits $limits): void
    {
        $in = $zip->getStreamIndex($entry['index']);
        $out = fopen($destination, 'wb');
        if ($in === false || $out === false) {
            throw UnreadableFeed::because(UnreadableReason::CorruptArchive, 'The zip file could not be read.');
        }

        $crc = hash_init('crc32b');
        $written = 0;
        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw UnreadableFeed::because(UnreadableReason::CorruptArchive, 'The zip file is damaged.');
                }

                $written += strlen($chunk);
                if ($written > $entry['size'] || $written > $limits->maxInflatedBytes) {
                    // More bytes than the archive declared: a forged header.
                    throw UnreadableFeed::because(UnreadableReason::DecompressionBomb, 'The zip file expands beyond what it declares, so it was not read.');
                }

                hash_update($crc, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Cannot write the unpacked feed (is the disk full?).');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        if ($written !== $entry['size'] || hexdec(hash_final($crc)) !== ($entry['crc'] & 0xFFFFFFFF)) {
            throw UnreadableFeed::because(UnreadableReason::CorruptArchive, 'The zip file is damaged (its data does not match its checksum).');
        }
    }
}
