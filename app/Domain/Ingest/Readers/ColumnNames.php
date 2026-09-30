<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

/**
 * Header names that can be used as keys: trimmed, blanks named by position, and duplicates made
 * distinct ("Price", "Price (2)"), so no column silently overwrites another.
 *
 * Legacy defect D3: a CSV with two identical headers threw and the import failed.
 */
final class ColumnNames
{
    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<string>
     */
    public static function normalize(array $raw): array
    {
        $names = [];
        $taken = [];
        foreach (array_values($raw) as $position => $value) {
            $name = trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));
            if ($name === '') {
                $name = 'Column '.($position + 1);
            }

            $base = $name;
            for ($copy = 2; isset($taken[$name]); $copy++) {
                $name = "{$base} ({$copy})";
            }

            $taken[$name] = true;
            $names[] = $name;
        }

        return $names;
    }
}
