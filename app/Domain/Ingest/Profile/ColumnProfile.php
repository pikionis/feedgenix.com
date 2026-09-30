<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Profile;

final readonly class ColumnProfile
{
    /** @param list<string> $examples */
    public function __construct(
        public string $name,
        public int $position,
        public int $filled,
        public float $fillRate,
        public int $maxLength,
        public ValueKind $kind,
        public array $examples,
    ) {}
}
