<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** What a file server says about a file, and about itself; null where it does not say. */
final readonly class FileInfo
{
    public function __construct(
        public ?int $size,
        public ?int $modifiedAt,
        public ?string $hostKey = null,
    ) {}

    /** Plays the part of an ETag: the same size and the same time mean the same file. */
    public function validator(): ?string
    {
        return $this->size === null || $this->modifiedAt === null ? null : "{$this->size}:{$this->modifiedAt}";
    }
}
