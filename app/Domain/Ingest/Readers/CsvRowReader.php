<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Ingest\Warnings;
use Generator;
use League\Csv\Reader;

/**
 * Delimited text (CSV, TSV, semicolon, pipe) in UTF-8. RFC 4180: quoted fields may hold
 * delimiters, quotes ("") and line breaks, and a backslash is an ordinary character (the parser's
 * non-standard escape is switched off, so "C:\" stays "C:\"). The first non-empty line is the
 * header; an Excel "sep=;" line before it names the delimiter.
 */
final class CsvRowReader implements RowReader
{
    private const array CANDIDATES = [',', ';', "\t", '|'];

    private const int SAMPLE_RECORDS = 50;

    private readonly Warnings $warnings;

    private ?string $delimiter = null;

    public function __construct(
        private readonly string $path,
        private readonly ParseOptions $options = new ParseOptions,
    ) {
        $this->warnings = new Warnings;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /** The delimiter in use: the customer's choice, the sep= line, or the one detected. */
    public function delimiter(): string
    {
        return $this->delimiter ??= $this->options->delimiter ?? $this->sepLine() ?? $this->detect();
    }

    public function rows(): Generator
    {
        $delimiter = $this->delimiter();
        $skipFirst = $this->sepLine() !== null;
        $header = null;
        $width = 0;
        $short = 0;
        $long = 0;

        foreach ($this->records($delimiter) as $offset => $record) {
            if ($skipFirst && $offset === 0) {
                continue;
            }

            if ($this->isBlank($record)) {
                continue;
            }

            if ($header === null) {
                $header = ColumnNames::normalize($record);
                $width = count($header);

                continue;
            }

            $count = count($record);
            if ($count < $width) {
                $short++;
                $record = array_pad($record, $width, '');
            } elseif ($count > $width) {
                $extra = array_slice($record, $width);
                if (implode('', array_map(strval(...), $extra)) !== '') {
                    $long++;
                }
                $record = array_slice($record, 0, $width);
            }

            yield array_combine($header, array_map(strval(...), $record));
        }

        if ($header === null) {
            throw UnreadableFeed::because(UnreadableReason::NoHeader, 'The file has no header row.');
        }

        if ($short > 0) {
            $this->warnings->add('short_rows', "{$short} rows had fewer fields than the header; the missing fields are empty.", $short);
        }

        if ($long > 0) {
            $this->warnings->add('long_rows', "{$long} rows had more fields than the header; the extra values were left out. Check the delimiter.", $long);
        }
    }

    /** @return iterable<int, array<int, string|null>> */
    private function records(string $delimiter): iterable
    {
        $csv = Reader::from($this->path);
        $csv->setDelimiter($delimiter);
        // Without quoting, a character that can never occur (NUL was removed on conversion).
        $csv->setEnclosure($this->options->quoted ? '"' : "\0");
        $csv->setEscape('');

        return $csv->getRecords();
    }

    /** @param array<int, string|null> $record */
    private function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if ($value !== null && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function sepLine(): ?string
    {
        $handle = fopen($this->path, 'rb');
        $line = $handle === false ? '' : (string) fgets($handle, 64);
        if ($handle !== false) {
            fclose($handle);
        }

        return preg_match('/^sep=([,;|\t])\R?$/i', $line, $match) === 1 ? $match[1] : null;
    }

    /**
     * The candidate that splits the first records most consistently into two or more fields.
     * Quoted fields are parsed, so a comma inside "Blue, red" does not count.
     */
    private function detect(): string
    {
        $best = ',';
        $bestScore = [-1.0, 0];
        foreach (self::CANDIDATES as $candidate) {
            $counts = [];
            foreach ($this->records($candidate) as $record) {
                if ($this->isBlank($record)) {
                    continue;
                }
                $counts[] = count($record);
                if (count($counts) === self::SAMPLE_RECORDS) {
                    break;
                }
            }

            if ($counts === []) {
                continue;
            }

            $frequencies = array_count_values($counts);
            arsort($frequencies);
            $mode = array_key_first($frequencies);
            if ($mode < 2) {
                continue;
            }

            $score = [$frequencies[$mode] / count($counts), $mode];
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
