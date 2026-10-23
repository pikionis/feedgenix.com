<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Data;

use InvalidArgumentException;

/**
 * How a composed field is built from several columns (legacy SmartScanner::composeFromRecipe):
 * every non-empty source in order, as "Label: value", joined by the separator. Values are copied as
 * they are; nothing is invented, and a row whose sources are all empty composes to "".
 */
final readonly class Recipe
{
    /** Placeholders feeds write for "no value"; "0" is a value ("Drawers: 0"). */
    private const array EMPTY_MARKERS = ['-', '--', 'n/a', 'na', 'null', 'none'];

    /** @param non-empty-list<array{column: string, label: string}> $sources */
    public function __construct(
        public array $sources,
        public string $separator = "\n",
        public string $labelSeparator = ': ',
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $sources = [];
        foreach ((array) ($data['sources'] ?? []) as $source) {
            if (is_array($source) && is_string($source['column'] ?? null) && $source['column'] !== '') {
                $sources[] = ['column' => $source['column'], 'label' => trim((string) ($source['label'] ?? ''))];
            }
        }
        if ($sources === []) {
            throw new InvalidArgumentException('A recipe needs at least one source column.');
        }

        return new self(
            $sources,
            is_string($data['separator'] ?? null) ? $data['separator'] : "\n",
            is_string($data['label_separator'] ?? null) ? $data['label_separator'] : ': ',
        );
    }

    /** @return array{sources: non-empty-list<array{column: string, label: string}>, separator: string, label_separator: string} */
    public function toArray(): array
    {
        return ['sources' => $this->sources, 'separator' => $this->separator, 'label_separator' => $this->labelSeparator];
    }

    /** @return list<string> */
    public function columns(): array
    {
        return array_map(static fn (array $source): string => $source['column'], $this->sources);
    }

    /** @param array<array-key, string> $row */
    public function compose(array $row): string
    {
        $parts = [];
        foreach ($this->sources as $source) {
            $value = trim((string) ($row[$source['column']] ?? ''));
            if ($value === '' || in_array(mb_strtolower($value), self::EMPTY_MARKERS, true)) {
                continue;
            }
            $parts[] = $source['label'] !== '' ? $source['label'].$this->labelSeparator.$value : $value;
        }

        return implode($this->separator, $parts);
    }
}
