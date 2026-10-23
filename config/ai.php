<?php

declare(strict_types=1);

use App\Support\TypedEnv;

/*
 * The AI gateway (ADR 0004). Every model call goes through App\Domain\AI and nothing else.
 *
 * Prices are the providers' list prices in USD per million tokens, written as decimal strings (never
 * floats: every call is charged at exactly provider cost × 1.1, ADR 0005). A model without a
 * confirmed price is never called: the gateway refuses it before any request leaves (fail closed).
 * Fill a price only from the provider's own price page, with the date it was read. Cost, markup and
 * prices never reach a response, page or email.
 */
return [
    // The kill switch: false refuses every call (policy), whatever else is configured.
    'enabled' => TypedEnv::bool('FEEDGENIX_AI_ENABLED', false),

    // Every provider is the fake one (tests and e2e). Refused in production.
    'fake' => TypedEnv::bool('FEEDGENIX_AI_FAKE', false),

    'providers' => [
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'timeout' => 180,
        ],
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            // The official SDK sets the API version and the auth header itself.
            'base_url' => 'https://api.anthropic.com',
            'timeout' => 180,
        ],
    ],

    /*
     * model id => its provider, prices (USD per 1M tokens; thinking tokens are billed as output),
     * the largest prompt the flat price holds for (null: no tier), and the date the price was read.
     * A null price means "not confirmed": the model is not callable.
     *
     * Claude only: `effort` (low … max; Opus 5.5 defaults to medium) and `refusal_fallbacks`, the
     * models Anthropic may serve a policy-declined request with. They are sent only when every one
     * of them is priced, because each hop is billed at its own model's rate.
     */
    'models' => [
        'gemini-2.5-pro' => ['provider' => 'gemini', 'input' => null, 'output' => null, 'cached_input' => null, 'max_input_tokens' => 200_000, 'priced_on' => null],
        'gemini-2.5-flash' => ['provider' => 'gemini', 'input' => null, 'output' => null, 'cached_input' => null, 'max_input_tokens' => null, 'priced_on' => null],
        'claude-opus-5-5' => ['provider' => 'anthropic', 'input' => null, 'output' => null, 'cached_input' => null, 'max_input_tokens' => null, 'priced_on' => null, 'effort' => 'medium', 'refusal_fallbacks' => ['claude-opus-5']],
        'claude-opus-5' => ['provider' => 'anthropic', 'input' => null, 'output' => null, 'cached_input' => null, 'max_input_tokens' => null, 'priced_on' => null],
    ],

    /*
     * purpose => models to try in order. Claude is a fallback for reasoning purposes only (mapping,
     * quality), never for translation (ADR 0004).
     */
    'routes' => [
        'mapping' => ['gemini-2.5-pro', 'claude-opus-5-5'],
        'quality' => ['gemini-2.5-flash', 'claude-opus-5-5'],
        'translation' => ['gemini-2.5-flash'],
    ],

    // Attempts per model; full jitter between them, honouring Retry-After up to the cap.
    'retry' => [
        'attempts' => 4,
        'base_ms' => 1000,
        'cap_ms' => 30_000,
    ],

    // Consecutive non-429 failures before a provider is skipped, and for how long.
    'breaker' => [
        'threshold' => 5,
        'cooldown_seconds' => 60,
    ],

    // EUR per USD, to bill in euros. Update it together with the provider prices.
    'usd_to_eur' => TypedEnv::string('FEEDGENIX_USD_TO_EUR', '0.92'),

    // Model calls never run inside a database transaction (a rollback would erase a billed call's
    // charge). Only the test suite, which wraps every test in one, raises this to its own level.
    'outer_transaction_level' => 0,
];
