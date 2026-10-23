<?php

declare(strict_types=1);

namespace App\Domain\Translation;

/**
 * How translation work is cut into gateway calls, and the bounds an estimate shares with them.
 * Long texts travel in smaller batches (legacy: 100 descriptions or 1,500 short texts per batch,
 * 60,000 characters at most; one call there could hold 300 items).
 */
final class UnitSizing
{
    public const int MAX_ITEMS = 40;

    public const int MAX_ITEMS_LONG = 10;

    /** Bytes of source text per call. */
    public const int MAX_BYTES = 24_000;

    /** Fields whose texts are long. */
    private const array LONG = ['description', 'features', 'other_attributes', 'care_instructions'];

    public static function maxItems(string $field): int
    {
        return in_array($field, self::LONG, true) ? self::MAX_ITEMS_LONG : self::MAX_ITEMS;
    }

    /**
     * Room for the answer: a translation is rarely more than 1.5 times its source in tokens (and a
     * token never covers less than a byte), plus the JSON around each item.
     */
    public static function maxOutputTokens(int $sourceBytes, int $items): int
    {
        return min(65_536, (int) ceil($sourceBytes * 1.5) + 40 * $items + 512);
    }
}
