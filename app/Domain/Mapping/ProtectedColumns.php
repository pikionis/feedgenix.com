<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use App\Domain\Ingest\Profile\ValueKind;

/**
 * Columns whose values may be golden (rule 1): prices, stock, identifiers and barcodes, images and
 * links, weights. Their values never reach a prompt and they never feed a composed text field. A
 * column is protected by its name (as words, see ColumnName) or by what its values look like: a
 * column of numbers, codes, barcodes or URLs is withheld even under a meaningless name ("Spalte 7").
 */
final class ProtectedColumns
{
    /** Single words that mark a protected column. */
    private const array WORDS = [
        // prices
        'price', 'prices', 'preis', 'preise', 'prix', 'prezzo', 'precio', 'preço', 'cena', 'cost', 'costs', 'kosten',
        'tarif', 'uvp', 'msrp', 'rrp', 'vk', 'ek', 'chf', 'eur', 'usd', 'gbp', 'vat', 'mwst',
        // stock
        'stock', 'quantity', 'qty', 'inventory', 'bestand', 'lagerbestand', 'lager', 'menge', 'inventaire', 'dispo', 'stück',
        // identifiers and barcodes
        'id', 'sku', 'mpn', 'ean', 'ean13', 'ean8', 'gtin', 'gtin13', 'gtin14', 'upc', 'isbn', 'barcode', 'strichcode',
        'artikelnummer', 'artikelnr', 'herstellernummer', 'reference', 'référence', 'referenz', 'ref',
        // images and links
        'image', 'images', 'img', 'photo', 'photos', 'foto', 'fotos', 'bild', 'bilder', 'picture', 'thumbnail',
        'url', 'urls', 'link', 'links', 'href', 'deeplink',
        // weights
        'weight', 'gewicht', 'poids', 'peso', 'kg',
    ];

    /** Multi-word names that mark one ("art nr", "code barre"). */
    private const array PHRASES = ['art nr', 'code barre', 'item id', 'product id', 'part number', 'mw st'];

    /** Value kinds that are never product text. */
    private const array KINDS = [ValueKind::Gtin, ValueKind::Integer, ValueKind::Decimal, ValueKind::Code, ValueKind::ImageUrl, ValueKind::Url];

    public static function isProtected(string $name, ?ValueKind $kind = null): bool
    {
        return self::byName($name) || ($kind instanceof ValueKind && in_array($kind, self::KINDS, true));
    }

    public static function byName(string $name): bool
    {
        if (array_intersect(ColumnName::tokens($name), self::WORDS) !== []) {
            return true;
        }
        foreach (self::PHRASES as $phrase) {
            if (ColumnName::hasPhrase($name, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
