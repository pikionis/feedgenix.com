<?php

declare(strict_types=1);

namespace App\Services\Validation;

/**
 * The Golden Rule: never modify prices, stock, barcodes, SKUs, dimensions, or weights.
 * These columns pass through untouched — never translated, never rewritten.
 *
 * Patterns live in config('feedgenix.golden_rule_patterns') and are matched
 * case-insensitively as substrings against column names.
 */
class GoldenRuleValidator
{
    /** @var string[] lowercased patterns */
    private array $patterns;

    public function __construct(?array $patterns = null)
    {
        $patterns = $patterns ?? (array) config('feedgenix.golden_rule_patterns', []);
        $this->patterns = array_values(array_unique(array_map(
            fn ($p) => mb_strtolower((string) $p),
            $patterns
        )));
    }

    /**
     * True if the column name matches any protected pattern.
     */
    public function isProtected(string $columnName): bool
    {
        $needle = mb_strtolower($columnName);
        foreach ($this->patterns as $pattern) {
            if ($pattern !== '' && str_contains($needle, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split a list of column names into protected and regular buckets.
     *
     * @param  string[] $columnNames
     * @return array{protected: string[], regular: string[]}
     */
    public function classify(array $columnNames): array
    {
        $protected = [];
        $regular = [];
        foreach ($columnNames as $name) {
            if ($this->isProtected($name)) {
                $protected[] = $name;
            } else {
                $regular[] = $name;
            }
        }
        return ['protected' => $protected, 'regular' => $regular];
    }

    /**
     * Verify a mapping doesn't route a translatable target to a protected source column.
     *
     * $mapping shape: ['target_field' => 'source_column', ...]
     * $translatableTargets: list of target fields flagged for translation.
     *
     * @param  array<string, string> $mapping
     * @param  string[]              $translatableTargets
     * @return array<int, array{target: string, source: string, reason: string}>
     */
    public function validateMapping(array $mapping, array $translatableTargets): array
    {
        $violations = [];
        $translatable = array_flip($translatableTargets);

        foreach ($mapping as $target => $source) {
            if (!isset($translatable[$target])) {
                continue;
            }
            if (!is_string($source) || $source === '') {
                continue;
            }
            if ($this->isProtected($source)) {
                $violations[] = [
                    'target' => $target,
                    'source' => $source,
                    'reason' => "Source column '{$source}' matches a Golden Rule pattern — cannot be translated.",
                ];
            }
        }

        return $violations;
    }

    /**
     * Produce a pass-through slice of a product row: protected columns with original values untouched.
     *
     * @param  array<string, mixed> $row
     * @param  string[]             $columnNames
     * @return array<string, mixed>
     */
    public function passthrough(array $row, array $columnNames): array
    {
        $out = [];
        foreach ($columnNames as $name) {
            if ($this->isProtected($name) && array_key_exists($name, $row)) {
                $out[$name] = $row[$name];
            }
        }
        return $out;
    }

    /** @return string[] */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
