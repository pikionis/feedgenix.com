<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Domain\Ingest\Encoding\DetectedEncoding;

/** What a file turned out to be. The encoding is null for XLSX, which carries its own. */
final readonly class Sniffed
{
    public function __construct(
        public Format $format,
        public ?DetectedEncoding $encoding,
    ) {}
}
