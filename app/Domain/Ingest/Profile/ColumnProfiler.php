<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Profile;

/**
 * One pass over every row: per column the fill rate, longest value, a few examples and the kind of
 * value; the first rows as samples for the mapping review; and the SKU column, chosen on evidence.
 * Memory stays flat except for the SKU uniqueness check, which is capped.
 */
final readonly class ColumnProfiler
{
    public function __construct(
        private int $sampleRows = 30,
        private int $examples = 5,
        private int $kindSample = 1_000,
        private int $maxTrackedSkus = 1_000_000,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, int> $config */
        $config = config('ingest.profile');

        return new self($config['sample_rows'], $config['sample_values'], $config['kind_sample'], $config['max_tracked_skus']);
    }

    /** @param iterable<int, array<array-key, string>> $rows */
    public function profile(iterable $rows): FeedProfile
    {
        /** @var array<string, ColumnStats> $stats */
        $stats = [];
        $samples = [];
        $count = 0;

        foreach ($rows as $row) {
            $count++;
            if (count($samples) < $this->sampleRows) {
                $samples[] = $row;
            }

            foreach ($row as $name => $value) {
                $name = (string) $name;
                $column = $stats[$name] ??= new ColumnStats($name, count($stats), SkuNames::rank($name));
                if (trim($value) !== '') {
                    $column->observe($value, $this->examples, $this->kindSample, $this->maxTrackedSkus);
                }
            }
        }

        $columns = array_map(
            static fn (ColumnStats $column): ColumnProfile => new ColumnProfile(
                name: $column->name,
                position: $column->position,
                filled: $column->filled,
                fillRate: $count === 0 ? 0.0 : round($column->filled / $count, 4),
                maxLength: $column->maxLength,
                kind: $column->kind(),
                examples: array_map(strval(...), array_keys($column->examples)),
            ),
            array_values($stats),
        );

        return new FeedProfile($count, $columns, $samples, $this->sku($stats, $count));
    }

    /** @param array<string, ColumnStats> $stats */
    private function sku(array $stats, int $rows): ?SkuSuggestion
    {
        $candidates = array_filter($stats, static fn (ColumnStats $column): bool => $column->skuRank !== null && $column->filled > 0);
        usort($candidates, static fn (ColumnStats $a, ColumnStats $b): int => [$a->skuRank, $a->position] <=> [$b->skuRank, $b->position]);

        foreach ($candidates as $column) {
            if ($column->filled === $rows && $column->duplicates === 0 && $column->uniquenessKnown) {
                return new SkuSuggestion($column->name, 'high', 0, 0);
            }
        }

        foreach ($candidates as $column) {
            if ($column->filled >= 0.9 * $rows) {
                return new SkuSuggestion($column->name, 'low', $rows - $column->filled, $column->duplicates);
            }
        }

        return null;
    }
}
