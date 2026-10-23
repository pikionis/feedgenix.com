<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Catalog\Formatters\DescriptionFormatter;
use App\Domain\Catalog\Formatters\ShortDescription;
use InvalidArgumentException;

/**
 * How a preset presents a value (config/export.php). Texts may be reshaped; a golden value never is:
 * the only transform allowed on one is `with_currency`, which writes the price byte for byte and
 * appends its currency, as Google Merchant requires ("189.90 CHF").
 */
final class Transforms
{
    public const array TEXT = ['plain_text', 'html_canonical', 'short', 'category_path', 'availability'];

    public const array GOLDEN = ['with_currency'];

    /** Availability words in the languages shops write them, by the value Google Merchant knows. */
    private const array AVAILABILITY = [
        'in stock' => ['in stock', 'instock', 'available', 'yes', 'ja', 'lieferbar', 'auf lager', 'verfügbar', 'en stock', 'disponible', 'disponibile', 'in magazzino', 'op voorraad', 'διαθέσιμο', 'σε απόθεμα'],
        'out of stock' => ['out of stock', 'outofstock', 'sold out', 'unavailable', 'no', 'nein', 'ausverkauft', 'nicht lieferbar', 'nicht verfügbar', 'épuisé', 'rupture de stock', 'esaurito', 'non disponibile', 'agotado', 'uitverkocht', 'μη διαθέσιμο', 'εξαντλήθηκε'],
        'preorder' => ['preorder', 'pre-order', 'vorbestellung', 'vorbestellbar', 'précommande', 'preordine'],
        'backorder' => ['backorder', 'back order', 'nachlieferung', 'auf bestellung', 'sur commande'],
    ];

    /** @param array<string, string> $sourceValues the product's golden and pass-through values */
    public static function apply(string $transform, string $value, array $sourceValues): string
    {
        return match ($transform) {
            'plain_text' => self::plainText($value),
            'html_canonical' => DescriptionFormatter::canonical($value),
            'short' => ShortDescription::make($value),
            'category_path' => self::categoryPath($value),
            'availability' => self::availability($value, $sourceValues['stock_quantity'] ?? ''),
            'with_currency' => $value !== '' && ($sourceValues['currency'] ?? '') !== '' ? $value.' '.$sourceValues['currency'] : $value,
            default => throw new InvalidArgumentException("[{$transform}] is not a transform."),
        };
    }

    /** Markup removed, entities decoded, block ends as spaces, white space collapsed. */
    private static function plainText(string $value): string
    {
        $text = (string) preg_replace('/<(br|\/p|\/li|\/div|\/h[1-6]|\/tr)\b[^>]*>/i', ' ', $value);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** "Sport/Outdoor > Zelte" → "Sport > Outdoor > Zelte": the separators the level check knows. */
    private static function categoryPath(string $value): string
    {
        $levels = array_filter(array_map(trim(...), preg_split('~\s*(?:>|/|\\\\)\s*~', $value) ?: []), static fn (string $level): bool => $level !== '');

        return implode(' > ', $levels);
    }

    private static function availability(string $value, string $stock): string
    {
        $key = mb_strtolower(trim($value));
        if ($key === '') {
            // No word for it: what the stock says, when the stock is a whole number.
            return preg_match('/^\s*-?\d+\s*$/', $stock) === 1 ? ((int) $stock > 0 ? 'in stock' : 'out of stock') : '';
        }
        foreach (self::AVAILABILITY as $known => $words) {
            if (in_array($key, $words, true)) {
                return $known;
            }
        }

        return $value;
    }
}
