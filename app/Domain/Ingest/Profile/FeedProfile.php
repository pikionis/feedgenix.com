<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Profile;

final readonly class FeedProfile
{
    /**
     * @param  list<ColumnProfile>  $columns  in the order they first appear
     * @param  list<array<array-key, string>>  $sampleRows
     */
    public function __construct(
        public int $rowCount,
        public array $columns,
        public array $sampleRows,
        public ?SkuSuggestion $sku,
    ) {}

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_map(static fn (ColumnProfile $column): string => $column->name, $this->columns);
    }

    /**
     * Identifies the column layout (names in order). The same signature means an earlier mapping
     * still fits (legacy defect D6: a mapping was reused although the columns had changed).
     */
    public function columnSignature(): string
    {
        return hash('sha256', implode("\n", $this->columnNames()));
    }
}
