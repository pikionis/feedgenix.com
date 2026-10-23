<?php

declare(strict_types=1);

/*
 * Exports (ADR 0007, ADR 0022).
 *
 * A preset says which columns a channel wants and how they are written; a profile picks a preset,
 * a dialect (csv or tsv), a layout and the languages. Columns:
 *   header     the column's name in the file
 *   field      a canonical field (config/canonical_schema.php)
 *   transform  how a text is presented (plain_text, html_canonical, short, category_path,
 *              availability) or, for a price, with_currency (the price byte for byte, a space and
 *              the currency). Golden values are never transformed otherwise.
 *   required   the channel refuses an item without it: the export report counts the gaps.
 * A preset with `columns: null` exports every field the feed's mapping fills, golden and
 * pass-through first, texts after (one column per language in the wide layout).
 */
return [
    'presets' => [
        'standard' => [
            'label' => 'Every mapped field',
            'dialects' => ['csv', 'tsv'],
            'layouts' => ['wide', 'per_language'],
            'columns' => null,
        ],
        'google_merchant' => [
            'label' => 'Google Merchant Center',
            'dialects' => ['tsv'],
            'layouts' => ['per_language'],
            'columns' => [
                ['header' => 'id', 'field' => 'sku', 'required' => true],
                ['header' => 'item_group_id', 'field' => 'parent_sku'],
                ['header' => 'title', 'field' => 'title', 'transform' => 'plain_text', 'required' => true],
                ['header' => 'description', 'field' => 'description', 'transform' => 'plain_text', 'required' => true],
                ['header' => 'link', 'field' => 'link', 'required' => true],
                ['header' => 'image_link', 'field' => 'image_link', 'required' => true],
                ['header' => 'additional_image_link', 'field' => 'additional_image_links'],
                ['header' => 'availability', 'field' => 'availability', 'transform' => 'availability', 'required' => true],
                ['header' => 'price', 'field' => 'price', 'transform' => 'with_currency', 'required' => true],
                ['header' => 'sale_price', 'field' => 'sale_price', 'transform' => 'with_currency'],
                ['header' => 'brand', 'field' => 'brand'],
                ['header' => 'gtin', 'field' => 'gtin'],
                ['header' => 'mpn', 'field' => 'mpn'],
                ['header' => 'condition', 'field' => 'condition'],
                ['header' => 'product_type', 'field' => 'category', 'transform' => 'category_path'],
                ['header' => 'color', 'field' => 'color', 'transform' => 'plain_text'],
                ['header' => 'size', 'field' => 'size', 'transform' => 'plain_text'],
                ['header' => 'material', 'field' => 'material', 'transform' => 'plain_text'],
                ['header' => 'custom_label_0', 'field' => 'custom_label_0'],
                ['header' => 'custom_label_1', 'field' => 'custom_label_1'],
                ['header' => 'custom_label_2', 'field' => 'custom_label_2'],
                ['header' => 'custom_label_3', 'field' => 'custom_label_3'],
                ['header' => 'custom_label_4', 'field' => 'custom_label_4'],
            ],
        ],
    ],

    // The shrink guard: a build with fewer than this share of the published rows is not published,
    // when the published one had more than `shrink_min_rows` (a truncated supplier file).
    'shrink_keep_ratio' => 0.7,
    'shrink_min_rows' => 50,

    // Published and superseded builds whose files are kept (to go back to one).
    'keep_builds' => 3,

    // Products read and written per round trip.
    'chunk' => 500,

    // A feed URL with nothing published yet answers 503 with this Retry-After.
    'retry_after_seconds' => 300,

    // How long a rotated token keeps working, so channels have time to switch.
    'token_grace_hours' => 72,
];
