<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

/** What a feed's rows are written in, after any gzip or zip around it is removed. */
enum Format: string
{
    case Csv = 'csv';
    case Xml = 'xml';
    case Json = 'json';
    case NdJson = 'ndjson';
    case Xlsx = 'xlsx';

    /** Text formats are converted to UTF-8 before they are read; XLSX carries its own encoding. */
    public function isText(): bool
    {
        return $this !== self::Xlsx;
    }

    public function extension(): string
    {
        return match ($this) {
            self::NdJson => 'jsonl',
            default => $this->value,
        };
    }
}
