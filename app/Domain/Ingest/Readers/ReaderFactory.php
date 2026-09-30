<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Format;
use App\Domain\Ingest\IngestLimits;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Ingest\PreparedFeed;

final class ReaderFactory
{
    /** @param non-empty-string|null $tempDir scratch space for readers that need it (XLSX) */
    public static function for(PreparedFeed $feed, ParseOptions $options, IngestLimits $limits, ?string $tempDir = null): RowReader
    {
        return match ($feed->format) {
            Format::Csv => new CsvRowReader($feed->path, $options),
            Format::Xml => new XmlRowReader($feed->path, $options->xmlItemPath, $limits->maxDepth),
            Format::Json => new JsonRowReader($feed->path, perLine: false, maxDepth: $limits->maxDepth),
            Format::NdJson => new JsonRowReader($feed->path, perLine: true, maxDepth: $limits->maxDepth),
            Format::Xlsx => new XlsxRowReader($feed->path, $tempDir),
        };
    }
}
