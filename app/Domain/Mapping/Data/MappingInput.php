<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Data;

use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\ImportColumn;
use App\Domain\Ingest\Profile\ValueKind;
use App\Domain\Mapping\ProtectedColumns;

/**
 * What a mapping is built from: one profiled import's columns and sample rows. Protection is
 * decided once, here, so every consumer (heuristics, prompts, compositions) sees the same answer.
 */
final readonly class MappingInput
{
    /**
     * @param  list<array{position: int, name: string, kind: ValueKind, fill_rate: float, examples: list<string>, protected: bool}>  $columns
     * @param  list<array<array-key, string>>  $rows  sample rows, column name => value
     * @param  array{column: string, confidence: string}|null  $sku
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public ?array $sku,
    ) {}

    public static function fromImport(Import $import): self
    {
        $columns = [];
        foreach ($import->columns()->orderBy('position')->get() as $column) {
            /** @var ImportColumn $column */
            $kind = ValueKind::tryFrom($column->kind) ?? ValueKind::Text;
            $columns[] = [
                'position' => $column->position,
                'name' => $column->name,
                'kind' => $kind,
                'fill_rate' => (float) $column->fill_rate,
                'examples' => array_map(strval(...), $column->examples),
                'protected' => ProtectedColumns::isProtected($column->name, $kind),
            ];
        }
        $sku = is_array($import->sku) ? ['column' => (string) $import->sku['column'], 'confidence' => (string) $import->sku['confidence']] : null;

        return new self($columns, $import->sample_rows ?? [], $sku);
    }

    public function has(string $column): bool
    {
        return $this->column($column) !== null;
    }

    /** @return array{position: int, name: string, kind: ValueKind, fill_rate: float, examples: list<string>, protected: bool}|null */
    public function column(string $name): ?array
    {
        foreach ($this->columns as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        return null;
    }

    public function isProtected(string $name): bool
    {
        return $this->column($name)['protected'] ?? true;
    }

    /** @return list<string> every non-empty value of the column in the sample rows and its examples */
    public function values(string $name): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            $value = trim((string) ($row[$name] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values !== [] ? $values : ($this->column($name)['examples'] ?? []);
    }

    /** @return list<string> what a recipe composes over the sample rows (non-empty only) */
    public function composed(Recipe $recipe): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            $value = $recipe->compose($row);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
