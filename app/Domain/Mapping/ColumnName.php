<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * A column name as words. The legacy guard matched `\bean\b`, and since "_" is a word character,
 * "product_ean" and "EAN_Code" were never seen as barcodes. Here a name is split on every
 * non-letter, non-digit and on camelCase, then lower-cased: "productEAN_13" → product, ean, 13.
 */
final class ColumnName
{
    /** @return list<string> */
    public static function tokens(string $name): array
    {
        // camelCase, an acronym before a word ("EANCode" → EAN Code), and letters next to digits.
        $spaced = (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})|(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', ' ', $name);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    /** "Product Title (DE)" → "product title de": the form hints are compared in. */
    public static function normalized(string $name): string
    {
        return implode(' ', self::tokens($name));
    }

    /** Whether the name contains $phrase as consecutive tokens ("art nr" in "Art.-Nr. Lieferant"). */
    public static function hasPhrase(string $name, string $phrase): bool
    {
        $tokens = self::tokens($name);
        $wanted = self::tokens($phrase);
        $length = count($wanted);
        if ($length === 0) {
            return false;
        }
        $counter = count($tokens);
        for ($i = 0; $i + $length <= $counter; $i++) {
            if (array_slice($tokens, $i, $length) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
