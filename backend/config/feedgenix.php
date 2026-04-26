<?php

/**
 * FeedGenix tier definitions.
 *
 * Pricing model is **pass-through translation cost + subscription**:
 *  - The merchant's card is billed for the actual AI provider cost their
 *    catalogue incurred this month (no markup — cost-at-cost).
 *  - The subscription pays for the platform itself (cache, golden rule,
 *    routing, mapping AI, glossary, TM, integrations, support).
 *
 * Quota counting rules:
 *  - "products_per_month" counts billable products only.
 *  - A product is billable when at least one of its translations needed an AI
 *    call this run; products served entirely from cache do not count.
 *  - "target_languages" caps how many target languages a project can have.
 *  - "team_members" caps seats in the workspace.
 *
 * Feature flags drive both UI gates and the CheckFeature middleware.
 * Enterprise has the `*` wildcard which grants every feature, current and
 * future. New features added to tiers should be opt-in for tiers below.
 */

return [
    'tiers' => [
        'free' => [
            'price_monthly' => 0,
            'price_yearly' => 0,
            'products_per_month' => 500,
            'target_languages' => 1,
            'team_members' => 1,
            'features' => [
                'csv_upload',
                'csv_export',
                'smart_cache',
                'golden_rule',
                'basic_mapping',
            ],
        ],

        'starter' => [
            'price_monthly' => 2900,    // €29
            'price_yearly' => 27840,    // €232 (10× monthly = 2 months free)
            'stripe_monthly_id' => env('STRIPE_STARTER_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_STARTER_YEARLY'),
            'products_per_month' => 10_000,
            'target_languages' => 4,
            'team_members' => 3,
            'features' => [
                'csv_upload',
                'csv_export',
                'xlsx_export',
                'json_export',
                'smart_cache',
                'golden_rule',
                'smart_mapping',
                'glossary_basic',         // up to 50 terms
                'change_detection',
                'cost_forecasting',
                'email_support',
            ],
        ],

        'pro' => [
            'price_monthly' => 7900,    // €79
            'price_yearly' => 75840,    // €632 (10× monthly = 2 months free)
            'stripe_monthly_id' => env('STRIPE_PRO_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_PRO_YEARLY'),
            'products_per_month' => 50_000,
            'target_languages' => 7,
            'team_members' => 10,
            'features' => [
                'csv_upload',
                'csv_export',
                'xlsx_export',
                'json_export',
                'xml_export',
                'smart_cache',
                'golden_rule',
                'smart_mapping',
                'glossary_basic',
                'glossary_unlimited',
                'translation_memory',     // fuzzy matching beyond exact-hash cache
                'change_detection',
                'cost_forecasting',
                'approval_workflow',
                'audit_log',
                'api_access',
                'webhooks',
                'priority_support',       // 4-hour SLA
            ],
        ],

        'enterprise' => [
            'price_monthly' => null,    // custom — set per contract
            'price_yearly' => null,
            'stripe_monthly_id' => env('STRIPE_ENTERPRISE_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_ENTERPRISE_YEARLY'),
            'products_per_month' => PHP_INT_MAX,
            'target_languages' => PHP_INT_MAX,
            'team_members' => PHP_INT_MAX,
            'features' => ['*'],        // wildcard — grants everything
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
