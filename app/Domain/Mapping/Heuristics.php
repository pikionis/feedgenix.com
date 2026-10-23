<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use App\Domain\Catalog\Schema\CanonicalField;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldClass;
use App\Domain\Catalog\Schema\FieldRef;
use App\Domain\Ingest\Profile\ValueKind;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use InvalidArgumentException;

/**
 * The free first draft: what a feed's own column names and value kinds already say, with no AI.
 * The SKU comes from the profiler's choice, barcodes and image links from what their values are,
 * the rest from the fields' column hints. Every proposal still goes through the pipeline.
 */
final readonly class Heuristics
{
    public function __construct(private CanonicalSchema $schema) {}

    /** @return list<Proposal> */
    public function propose(MappingInput $input, ?string $sourceLocale): array
    {
        $candidates = [];
        if ($input->sku !== null && $input->has($input->sku['column'])) {
            $confidence = match ($input->sku['confidence']) {
                'high' => 0.95,
                'medium' => 0.8,
                default => 0.6,
            };
            $candidates[] = Proposal::direct('sku', null, $input->sku['column'], $confidence, 'Every product has its own value in this column.', 'heuristic');
        }

        $images = 0;
        foreach ($input->columns as $column) {
            if ($column['fill_rate'] === 0.0) {
                continue;
            }
            if ($column['kind'] === ValueKind::Gtin) {
                $candidates[] = Proposal::direct('gtin', null, $column['name'], 0.9, 'The values are valid GTIN/EAN barcodes.', 'heuristic');
            }
            if ($column['kind'] === ValueKind::ImageUrl) {
                $target = $images++ === 0 ? 'image_link' : 'additional_image_links';
                $candidates[] = Proposal::direct($target, null, $column['name'], 0.7, 'The values are image addresses.', 'heuristic');
            }
            foreach ($this->byHints($column['name']) as [$field, $confidence]) {
                $locale = $field->isLocalized() ? $this->locale($input, $column['name'], $sourceLocale) : null;
                if ($field->isLocalized() && $locale === null) {
                    continue; // a text field in an unknown language: left for the reviewer
                }
                $candidates[] = Proposal::direct($field->key, $locale, $column['name'], $confidence, "The column name matches {$field->label}.", 'heuristic');
            }
        }

        return $this->bestOfEach($candidates);
    }

    /** The feed's main language: what most of its text columns are written in. */
    public static function sourceLocale(MappingInput $input): ?string
    {
        $votes = [];
        foreach ($input->columns as $column) {
            if ($column['protected'] || ! in_array($column['kind'], [ValueKind::Text, ValueKind::Html], true)) {
                continue;
            }
            $guess = LanguageGuess::guess(implode(' ', array_slice($input->values($column['name']), 0, 10)));
            if ($guess !== null) {
                $votes[$guess] = ($votes[$guess] ?? 0) + 1;
            }
        }
        arsort($votes);

        return array_key_first($votes);
    }

    /**
     * Fields whose hints the column name matches: the whole name (0.85) or a hint's words inside it (0.65).
     *
     * @return list<array{0: CanonicalField, 1: float}>
     */
    private function byHints(string $name): array
    {
        $normalized = ColumnName::normalized($this->withoutLanguage($name));
        $matches = [];
        foreach ($this->schema->mappable() as $field) {
            foreach ($field->hints as $hint) {
                $hint = ColumnName::normalized($hint);
                if ($hint === '') {
                    continue;
                }
                if ($normalized === $hint) {
                    $matches[$field->key] = [$field, 0.85];
                    break;
                }
                // Short hints ("id", "vk") only as the whole name: inside a name they mean too little.
                if (mb_strlen($hint) >= 4 && ColumnName::hasPhrase($normalized, $hint)) {
                    $matches[$field->key] ??= [$field, $field->class === FieldClass::Golden ? 0.6 : 0.65];
                }
            }
        }

        return array_values($matches);
    }

    private function locale(MappingInput $input, string $column, ?string $sourceLocale): ?string
    {
        $fromName = $this->languageOf($column);
        if ($fromName !== null) {
            return $fromName;
        }
        if (! $input->isProtected($column)) {
            $guess = LanguageGuess::guess(implode(' ', array_slice($input->values($column), 0, 10)));
            if ($guess !== null) {
                return $guess;
            }
        }

        return $sourceLocale;
    }

    /** "Titel_DE", "description-fr-CH", "Name (it)" → the registry locale its last words name. */
    private function languageOf(string $column): ?string
    {
        $tokens = ColumnName::tokens($column);
        $count = count($tokens);
        foreach ([2, 1] as $length) {
            if ($count <= $length) {
                continue;
            }
            try {
                return FieldRef::locale(implode('-', array_slice($tokens, -$length)));
            } catch (InvalidArgumentException) {
                // not a language suffix
            }
        }

        return null;
    }

    private function withoutLanguage(string $column): string
    {
        $language = $this->languageOf($column);
        if ($language === null) {
            return $column;
        }
        $tokens = ColumnName::tokens($column);

        return implode(' ', array_slice($tokens, 0, count($tokens) - count(explode('-', $language))));
    }

    /**
     * One proposal per field and locale, and one field per column: the most confident wins.
     *
     * @param  list<Proposal>  $candidates
     * @return list<Proposal>
     */
    private function bestOfEach(array $candidates): array
    {
        usort($candidates, static fn (Proposal $a, Proposal $b): int => $b->confidence <=> $a->confidence);
        $byKey = [];
        $usedColumns = [];
        foreach ($candidates as $candidate) {
            if (isset($byKey[$candidate->key()]) || isset($usedColumns[(string) $candidate->column])) {
                continue;
            }
            $byKey[$candidate->key()] = $candidate;
            $usedColumns[(string) $candidate->column] = true;
        }

        return array_values($byKey);
    }
}
