<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

/** The caps from config/ingest.php, typed. Tests build their own with small numbers. */
final readonly class IngestLimits
{
    public function __construct(
        public int $maxBytes,
        public int $maxInflatedBytes,
        public int $maxInflateRatio,
        public int $ratioAfterBytes,
        public int $maxRows,
        public int $maxColumns,
        public int $maxCellBytes,
        public int $maxDepth,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, int> $limits */
        $limits = config('ingest.limits');

        return new self(
            maxBytes: $limits['max_bytes'],
            maxInflatedBytes: $limits['max_inflated_bytes'],
            maxInflateRatio: $limits['max_inflate_ratio'],
            ratioAfterBytes: $limits['ratio_after_bytes'],
            maxRows: $limits['max_rows'],
            maxColumns: $limits['max_columns'],
            maxCellBytes: $limits['max_cell_bytes'],
            maxDepth: $limits['max_depth'],
        );
    }

    /** @param array<string, int> $overrides */
    public function with(array $overrides): self
    {
        return new self(...array_merge(get_object_vars($this), $overrides));
    }
}
