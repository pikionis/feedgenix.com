<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Profile;

/**
 * The column that most likely identifies each product. "high": every row has a value and no value
 * repeats. "low": the name fits, the evidence does not (a gap or a repeat), so the customer must
 * confirm it.
 */
final readonly class SkuSuggestion
{
    public function __construct(
        public string $column,
        public string $confidence,
        public int $empty,
        public int $duplicates,
    ) {}
}
