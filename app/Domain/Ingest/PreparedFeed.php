<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Domain\Ingest\Encoding\DetectedEncoding;

/** A feed ready to read: unpacked, and for text formats converted to UTF-8. */
final readonly class PreparedFeed
{
    /** @param list<string> $containers outermost first, e.g. ['zip', 'gzip'] */
    public function __construct(
        public string $path,
        public Format $format,
        public ?DetectedEncoding $encoding,
        public array $containers,
        public ?string $innerName,
        public Warnings $warnings,
    ) {}
}
