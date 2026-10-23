<?php

declare(strict_types=1);

/*
 * The canonical product schema (ADR 0003): what a feed is mapped to, with English keys. Languages are
 * not part of a key: a translatable field is stored per BCP-47 locale (`title@de-CH`).
 *
 * class:
 *   golden        passes through byte-identical; never reaches a prompt, a composition or a value map
 *   pass_through  copied as it is; may be shown to a model as context (a brand name), never rewritten
 *   translatable  translated per target language (mode: keep, translate or optimize)
 *   composable    translatable, and may be composed from several source columns
 *   optimize_only written by the optimizer (Pro), never mapped from a feed column
 *   derived       computed (a taxonomy id), never mapped
 *
 * kind: how a value is checked (text, html, code, gtin, money, currency, integer, number, url, urls,
 * enum, category).
 *
 * hints: column names that usually carry the field, lower case, in several languages. They help the
 * mapper and the reviewer; a hint is never a mapping on its own.
 */
return [
    'fields' => [
        // ---- Golden ---------------------------------------------------------------------------
        'sku' => ['class' => 'golden', 'kind' => 'code', 'label' => 'SKU', 'description' => "The seller's own product id, unique per product.", 'hints' => ['sku', 'id', 'article number', 'artikelnummer', 'art.-nr.', 'référence', 'item id', 'product id']],
        'parent_sku' => ['class' => 'golden', 'kind' => 'code', 'label' => 'Parent SKU', 'description' => 'The id shared by the variants of one product.', 'hints' => ['parent sku', 'item group id', 'parent id', 'master id', 'group id']],
        'gtin' => ['class' => 'golden', 'kind' => 'gtin', 'label' => 'GTIN / EAN', 'description' => 'The barcode number: EAN-13, UPC-A, GTIN-8 or GTIN-14.', 'hints' => ['gtin', 'ean', 'ean13', 'upc', 'barcode', 'strichcode']],
        'mpn' => ['class' => 'golden', 'kind' => 'code', 'label' => 'MPN', 'description' => "The manufacturer's part number.", 'hints' => ['mpn', 'manufacturer part number', 'herstellernummer', 'part number']],
        'price' => ['class' => 'golden', 'kind' => 'money', 'label' => 'Price', 'description' => 'The regular selling price.', 'hints' => ['price', 'preis', 'prix', 'prezzo', 'precio', 'vk']],
        'sale_price' => ['class' => 'golden', 'kind' => 'money', 'label' => 'Sale price', 'description' => 'The reduced price while a sale runs.', 'hints' => ['sale price', 'special price', 'aktionspreis', 'prix promo']],
        'currency' => ['class' => 'golden', 'kind' => 'currency', 'label' => 'Currency', 'description' => 'The ISO 4217 currency of the prices.', 'hints' => ['currency', 'währung', 'devise', 'valuta']],
        'stock_quantity' => ['class' => 'golden', 'kind' => 'integer', 'label' => 'Stock', 'description' => 'Units in stock.', 'hints' => ['stock', 'quantity', 'qty', 'bestand', 'lagerbestand', 'inventory']],
        'weight' => ['class' => 'golden', 'kind' => 'text', 'label' => 'Weight', 'description' => 'The weight with its unit, as the source writes it.', 'hints' => ['weight', 'gewicht', 'poids', 'shipping weight']],
        'dimensions' => ['class' => 'golden', 'kind' => 'text', 'label' => 'Dimensions', 'description' => 'The measurements with their units, as the source writes them.', 'hints' => ['dimensions', 'masse', 'maße', 'size (cm)']],

        // ---- Pass-through ---------------------------------------------------------------------
        'availability' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Availability', 'description' => 'Whether the product can be ordered (in stock, out of stock, preorder).', 'hints' => ['availability', 'verfügbarkeit', 'disponibilité', 'lieferstatus']],
        'brand' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Brand', 'description' => 'The brand or manufacturer name.', 'hints' => ['brand', 'marke', 'marque', 'hersteller', 'manufacturer']],
        'condition' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Condition', 'description' => 'New, refurbished or used.', 'hints' => ['condition', 'zustand', 'état']],
        'link' => ['class' => 'pass_through', 'kind' => 'url', 'label' => 'Product link', 'description' => "The product's page in the shop.", 'hints' => ['link', 'url', 'product url', 'deeplink']],
        'image_link' => ['class' => 'pass_through', 'kind' => 'url', 'label' => 'Main image', 'description' => 'The main product image.', 'hints' => ['image link', 'image', 'image url', 'bild', 'bild url', 'main image']],
        'additional_image_links' => ['class' => 'pass_through', 'kind' => 'urls', 'label' => 'More images', 'description' => 'Further product images.', 'hints' => ['additional image link', 'images', 'weitere bilder', 'gallery']],
        'custom_label_0' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Custom label 0', 'description' => 'A label the seller uses for campaigns.', 'hints' => ['custom label 0']],
        'custom_label_1' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Custom label 1', 'description' => 'A label the seller uses for campaigns.', 'hints' => ['custom label 1']],
        'custom_label_2' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Custom label 2', 'description' => 'A label the seller uses for campaigns.', 'hints' => ['custom label 2']],
        'custom_label_3' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Custom label 3', 'description' => 'A label the seller uses for campaigns.', 'hints' => ['custom label 3']],
        'custom_label_4' => ['class' => 'pass_through', 'kind' => 'text', 'label' => 'Custom label 4', 'description' => 'A label the seller uses for campaigns.', 'hints' => ['custom label 4']],

        // ---- Translatable ---------------------------------------------------------------------
        'title' => ['class' => 'translatable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Title', 'description' => "The product's name as a shopper reads it.", 'hints' => ['title', 'name', 'product name', 'titel', 'bezeichnung', 'produktname', 'nom', 'titolo', 'nombre']],
        'description' => ['class' => 'translatable', 'kind' => 'html', 'mode' => 'translate', 'label' => 'Description', 'description' => 'The long description; HTML is kept as it is.', 'hints' => ['description', 'beschreibung', 'langtext', 'descrizione', 'descripción', 'long description']],
        'color' => ['class' => 'translatable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Color', 'description' => 'The colour name.', 'hints' => ['color', 'colour', 'farbe', 'couleur', 'colore']],
        'size' => ['class' => 'translatable', 'kind' => 'text', 'mode' => 'keep', 'label' => 'Size', 'description' => 'The size label (S, 42, 10 x 20 cm); usually the same in every language.', 'hints' => ['size', 'grösse', 'größe', 'taille', 'taglia', 'talla']],
        'category' => ['class' => 'translatable', 'kind' => 'category', 'mode' => 'translate', 'label' => 'Category', 'description' => "The shop's own category path (levels separated by > or /).", 'hints' => ['category', 'product type', 'kategorie', 'warengruppe', 'catégorie', 'categoria']],
        'warranty' => ['class' => 'translatable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Warranty', 'description' => 'The warranty terms.', 'hints' => ['warranty', 'garantie', 'gewährleistung', 'garanzia']],

        // ---- Translatable and composable ------------------------------------------------------
        'features' => ['class' => 'composable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Features', 'description' => 'Technical characteristics as "Label: value" lines, often scattered over several spec columns.', 'hints' => ['features', 'specifications', 'merkmale', 'eigenschaften', 'technische daten', 'caractéristiques']],
        'material' => ['class' => 'composable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Material', 'description' => 'What the product is made of, from material, fabric or finish columns.', 'hints' => ['material', 'materials', 'stoff', 'composition', 'matière']],
        'care_instructions' => ['class' => 'composable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Care instructions', 'description' => 'How to wash, clean or maintain it.', 'hints' => ['care', 'care instructions', 'pflegehinweis', 'pflege', 'entretien']],
        'dimensions_text' => ['class' => 'composable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Dimensions (text)', 'description' => 'Measurements as readable text, from separate width, height and depth columns; numbers and units never change.', 'hints' => ['width', 'height', 'depth', 'length', 'breite', 'höhe', 'tiefe', 'länge']],
        'other_attributes' => ['class' => 'composable', 'kind' => 'text', 'mode' => 'translate', 'label' => 'Other attributes', 'description' => 'Useful attributes that fit no other field, as "Label: value" lines.', 'hints' => []],

        // ---- Optimize-only (Pro) and derived --------------------------------------------------
        'short_description' => ['class' => 'optimize_only', 'kind' => 'text', 'mode' => 'optimize', 'label' => 'Short description', 'description' => 'One or two sentences from the most salient facts in the source.', 'hints' => []],
        'meta_title' => ['class' => 'optimize_only', 'kind' => 'text', 'mode' => 'optimize', 'label' => 'Meta title', 'description' => 'The page title for search engines.', 'hints' => []],
        'meta_description' => ['class' => 'optimize_only', 'kind' => 'text', 'mode' => 'optimize', 'label' => 'Meta description', 'description' => 'The page summary for search engines.', 'hints' => []],
        'google_product_category' => ['class' => 'derived', 'kind' => 'integer', 'label' => 'Google product category', 'description' => "The id in Google's product taxonomy.", 'hints' => []],
    ],
];
