<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * What a build could not do as it should: rows left out (a golden value the dialect cannot write
 * unchanged), and how many rows lack a column the channel requires. Counts, and the first examples.
 */
final class ExportReport
{
    private const int EXAMPLES = 20;

    private int $leftOut = 0;

    /** @var array<string, int> */
    private array $reasons = [];

    /** @var array<string, array<string, int>> file => column => rows */
    private array $missing = [];

    /** @var list<array{file: string, sku: string, column: string, reason: string}> */
    private array $examples = [];

    public function leftOut(string $file, string $sku, string $column, string $reason): void
    {
        $this->leftOut++;
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
        if (count($this->examples) < self::EXAMPLES) {
            $this->examples[] = ['file' => $file, 'sku' => $sku, 'column' => $column, 'reason' => $reason];
        }
    }

    public function missing(string $file, string $column): void
    {
        $this->missing[$file][$column] = ($this->missing[$file][$column] ?? 0) + 1;
    }

    /** @return array{left_out: int, reasons: array<string, int>, missing: array<string, array<string, int>>, examples: list<array{file: string, sku: string, column: string, reason: string}>} */
    public function toArray(): array
    {
        return ['left_out' => $this->leftOut, 'reasons' => $this->reasons, 'missing' => $this->missing, 'examples' => $this->examples];
    }
}
