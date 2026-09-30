<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Profile;

/** Running statistics for one column while the rows stream past. */
final class ColumnStats
{
    public int $filled = 0;

    public int $maxLength = 0;

    /** @var array<string, true> */
    public array $examples = [];

    /** @var list<string> */
    public array $kindSample = [];

    /** @var array<string, true>|null binary hashes of values; null when this column is not a SKU candidate */
    public ?array $seen = null;

    public int $duplicates = 0;

    public bool $uniquenessKnown = true;

    public function __construct(
        public readonly string $name,
        public readonly int $position,
        public readonly ?int $skuRank,
    ) {
        if ($skuRank !== null) {
            $this->seen = [];
        }
    }

    public function observe(string $value, int $maxExamples, int $kindSampleSize, int $maxTracked): void
    {
        $this->filled++;
        $length = mb_strlen($value);
        if ($length > $this->maxLength) {
            $this->maxLength = $length;
        }

        if (count($this->examples) < $maxExamples) {
            $this->examples[mb_substr($value, 0, 200)] = true;
        }

        if (count($this->kindSample) < $kindSampleSize) {
            $this->kindSample[] = $value;
        }

        if ($this->seen === null) {
            return;
        }

        $hash = hash('xxh128', $value, true);
        if (isset($this->seen[$hash])) {
            $this->duplicates++;
        } elseif (count($this->seen) < $maxTracked) {
            $this->seen[$hash] = true;
        } else {
            $this->uniquenessKnown = false;
        }
    }

    public function kind(): ValueKind
    {
        if ($this->kindSample === []) {
            return ValueKind::Empty;
        }

        $counts = [];
        foreach ($this->kindSample as $value) {
            $kind = ValueKind::classify($value)->value;
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        // Integers are also decimals; codes of digits are also integers: count upwards.
        $total = count($this->kindSample);
        $wins = static fn (int $count): bool => $count >= 0.95 * $total;
        foreach ([ValueKind::Gtin, ValueKind::Integer, ValueKind::Decimal, ValueKind::Boolean, ValueKind::Date, ValueKind::ImageUrl, ValueKind::Url, ValueKind::Html, ValueKind::Code] as $kind) {
            $count = $counts[$kind->value] ?? 0;
            if ($kind === ValueKind::Decimal) {
                $count += $counts[ValueKind::Integer->value] ?? 0;
            }
            if ($kind === ValueKind::Url) {
                $count += $counts[ValueKind::ImageUrl->value] ?? 0;
            }
            if ($wins($count)) {
                return $kind;
            }
        }

        return ValueKind::Text;
    }
}
