<?php

declare(strict_types=1);

/*
 * Plans — the single source of truth for prices, limits and features (ADR 0005).
 * Read by App\Domain\Billing (entitlements, the 402 contract, the public catalogue) and by Stripe (M6).
 *
 * - The subscription is the platform fee. It includes no AI spend: AI is paid with prepaid credits
 *   at provider cost × 1.1 (M7), which is never shown publicly.
 * - Yearly = 12 × the discounted monthly price, paid once, 12 months' access, and ONE annual product
 *   pool (12 × the monthly allowance).
 * - "Products" = new or changed products that need AI work, each counted once per month.
 * - Above the allowance, paid plans continue with an overage fee in credits; Free waits.
 * - Pro carries every strong feature; Ultra adds scale; Custom adds dedicated infrastructure + API/MCP.
 * - `feed_bytes` / `feed_rows`: the largest feed one import reads, measured uncompressed (a .gz may be
 *   smaller on the wire). The server ceiling in config/ingest.php can only lower them.
 *
 * null = unlimited. `internal` guards never appear on the pricing page or in any public response.
 */
return [
    'annual_discount' => 0.25,

    'recommended' => 'pro',

    // A paid plan stays in force this many days past its paid-through date.
    'grace_days' => 3,

    'plans' => [
        'free' => [
            'name' => 'Free',
            'tagline' => 'Try it on one feed',
            'price_monthly_eur' => 0,
            'limits' => [
                'feeds' => 1,
                'languages' => 1,
                'products_per_month' => 500,
                'worker_slots' => 1,
                'feed_bytes' => 2 * 1024 ** 3,
                'feed_rows' => 5_000_000,
            ],
            'overage' => null, // extra products wait for the next month
            'internal' => [
                'auto_refresh_per_day' => 0,
                'manual_refresh_per_day' => 3,
                'ai_setup_calls_per_day' => 5,
                'previews_per_day' => 3,
                // Private beta only: the owner funds AI up to this per workspace until credits ship.
                'beta_ai_budget_eur_month' => 1,
            ],
            'models' => ['gemini-flash-lite'],
            'features' => [
                'ai_translation' => true,
                'ai_agent' => false,
                'rag_memory' => false,
                'field_instructions' => false,
                'model_choice' => false,
                'priority_queue' => false,
                'dedicated_infrastructure' => false,
                'api_mcp' => false,
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'tagline' => 'Your own AI agent that learns your catalogue',
            'price_monthly_eur' => 40,
            'limits' => [
                'feeds' => 5,
                'languages' => 5,
                'products_per_month' => 50_000,
                'worker_slots' => 2,
                'feed_bytes' => 10 * 1024 ** 3,
                'feed_rows' => 25_000_000,
            ],
            // 10 credits = €0.001 per extra product; break-even against Ultra ≈ 60,000 extra a month.
            'overage' => ['credits_per_product' => 10],
            'internal' => [
                'auto_refresh_per_day' => 1,
                'manual_refresh_per_day' => 10,
                'ai_setup_calls_per_day' => 30,
                'previews_per_day' => 50,
                'beta_ai_budget_eur_month' => 30,
            ],
            'models' => ['gemini-flash-lite', 'gemini-flash', 'gemini-pro'],
            'features' => [
                'ai_translation' => true,
                'ai_agent' => true,
                'rag_memory' => true,
                'field_instructions' => true,
                'model_choice' => true,
                'priority_queue' => true,
                'dedicated_infrastructure' => false,
                'api_mcp' => false,
            ],
        ],

        'ultra' => [
            'name' => 'Ultra',
            'tagline' => 'Everything in Pro, at marketplace scale',
            'price_monthly_eur' => 100,
            'limits' => [
                'feeds' => 20,
                'languages' => 10,
                'products_per_month' => null,
                'worker_slots' => 4,
                'feed_bytes' => 50 * 1024 ** 3,
                'feed_rows' => 100_000_000,
            ],
            'overage' => null, // unlimited products
            'internal' => [
                'auto_refresh_per_day' => 3,
                'manual_refresh_per_day' => 20,
                'ai_setup_calls_per_day' => 60,
                'previews_per_day' => 200,
                'beta_ai_budget_eur_month' => 90,
            ],
            'models' => ['gemini-flash-lite', 'gemini-flash', 'gemini-pro'],
            'features' => [
                'ai_translation' => true,
                'ai_agent' => true,
                'rag_memory' => true,
                'field_instructions' => true,
                'model_choice' => true,
                'priority_queue' => true,
                'dedicated_infrastructure' => false,
                'api_mcp' => false,
            ],
        ],

        'custom' => [
            'name' => 'Custom',
            'tagline' => 'Dedicated infrastructure, no limits',
            'price_monthly_eur' => 180,
            'limits' => [
                'feeds' => null,
                'languages' => null,
                'products_per_month' => null,
                'worker_slots' => 8,
                // Larger feeds by agreement, through tenants.limits_override.
                'feed_bytes' => 50 * 1024 ** 3,
                'feed_rows' => 100_000_000,
            ],
            'overage' => null,
            // Deal-specific values go in tenants.limits_override.
            'internal' => [
                'auto_refresh_per_day' => 3,
                'manual_refresh_per_day' => null,
                'ai_setup_calls_per_day' => null,
                'previews_per_day' => null,
                'beta_ai_budget_eur_month' => 200,
            ],
            'models' => ['gemini-flash-lite', 'gemini-flash', 'gemini-pro'],
            'features' => [
                'ai_translation' => true,
                'ai_agent' => true,
                'rag_memory' => true,
                'field_instructions' => true,
                'model_choice' => true,
                'priority_queue' => true,
                'dedicated_infrastructure' => true,
                'api_mcp' => true,
            ],
        ],
    ],
];
