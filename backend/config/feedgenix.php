<?php

return [
    'tiers' => [
        'free' => [
            'products_per_month' => 100,
            'target_languages' => 1,
            'team_members' => 1,
            'features' => ['csv_upload', 'basic_mapping', 'csv_export'],
        ],
        'starter' => [
            'price_monthly' => 2900,
            'price_yearly' => 27840,
            'stripe_monthly_id' => env('STRIPE_STARTER_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_STARTER_YEARLY'),
            'products_per_month' => 1000,
            'target_languages' => 3,
            'team_members' => 2,
            'features' => ['csv_upload', 'smart_mapping', 'csv_export',
                          'xlsx_export', 'change_detection'],
        ],
        'pro' => [
            'price_monthly' => 7900,
            'price_yearly' => 75840,
            'stripe_monthly_id' => env('STRIPE_PRO_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_PRO_YEARLY'),
            'products_per_month' => 10000,
            'target_languages' => 10,
            'team_members' => 5,
            'features' => ['csv_upload', 'smart_mapping', 'csv_export',
                          'xlsx_export', 'json_export', 'xml_export',
                          'change_detection', 'continuous_sync',
                          'api_access', 'priority_support'],
        ],
        'enterprise' => [
            'price_monthly' => 19900,
            'price_yearly' => 191040,
            'stripe_monthly_id' => env('STRIPE_ENTERPRISE_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_ENTERPRISE_YEARLY'),
            'products_per_month' => PHP_INT_MAX,
            'target_languages' => PHP_INT_MAX,
            'team_members' => PHP_INT_MAX,
            'features' => ['*'],
        ],
    ],

    'golden_rule_patterns' => [
        'price', 'prix', 'preis', 'precio', 'prezzo', 'cost',
        'stock', 'quantity', 'qty', 'inventory', 'bestand',
        'ean', 'upc', 'gtin', 'barcode', 'isbn',
        'sku', 'ref', 'reference', 'article_number',
        'weight', 'width', 'height', 'length', 'depth',
        'dimension', 'poids', 'gewicht',
    ],

    'translation' => [
        // Hard EUR cap across all paid providers per calendar month.
        // If total spend crosses this, translation calls throw until the cap is raised.
        'monthly_cap_eur' => (float) env('FEEDGENIX_TRANSLATION_MONTHLY_CAP', 10.00),

        // Chunk sizes used by RunTranslation.
        'batch_size' => (int) env('FEEDGENIX_TRANSLATION_BATCH_SIZE', 200),
        'checkpoint_every' => (int) env('FEEDGENIX_TRANSLATION_CHECKPOINT_EVERY', 1000),
    ],
];
