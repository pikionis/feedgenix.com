<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

final readonly class FetchResult
{
    public function __construct(
        public bool $notModified,
        public int $bytes,
        public string $finalUrl,
        public ?string $contentType = null,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public ?string $hostKey = null,
    ) {}
}
