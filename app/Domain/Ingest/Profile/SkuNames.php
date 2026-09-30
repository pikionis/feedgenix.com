<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Profile;

/**
 * Column names that usually hold the product's own identifier, best first. Ported from the legacy
 * analysis, plus the Google Shopping `g:id`. A name only nominates a column; the profile's
 * evidence (every row filled, every value unique) decides.
 */
final class SkuNames
{
    private const array EXACT = [
        'sku', 'g:id', 'id', 'offer_id', 'product_id', 'productid', 'product_code', 'productcode',
        'article_code', 'code_article', 'code_produit', 'item_number', 'item_no', 'itemno', 'item_id',
        'artikelnummer', 'artikelnr', 'artikel_nr', 'art_nr', 'artnr', 'reference', 'référence',
        'referenz', 'ref', 'part_number', 'partno', 'part_no', 'mpn', 'model_number',
    ];

    private const array CONTAINS = ['sku', 'article', 'artikel', 'product_id', 'item_id', 'reference', 'referenz', 'part_n'];

    /** Lower is better; null when the name suggests nothing. */
    public static function rank(string $column): ?int
    {
        $name = mb_strtolower(trim($column));
        $exact = array_search($name, self::EXACT, true);
        if ($exact !== false) {
            return $exact;
        }

        foreach (self::CONTAINS as $position => $fragment) {
            if (str_contains($name, $fragment)) {
                return 100 + $position;
            }
        }

        return null;
    }
}
