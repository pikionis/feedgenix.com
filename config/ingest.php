<?php

declare(strict_types=1);

use App\Support\TypedEnv;

/*
 * Reading customer feeds (ADR 0012). Every cap protects the server from one oversized or hostile
 * file; each refusal names the cap, so a customer is never left guessing.
 */
return [
    'limits' => [
        // Bytes received (upload or download), before any decompression.
        'max_bytes' => 2 * 1024 ** 3,
        // Bytes a gzip or zip may inflate to, and the ratio that marks a decompression bomb once
        // more than `ratio_after_bytes` came out.
        'max_inflated_bytes' => 4 * 1024 ** 3,
        'max_inflate_ratio' => 100,
        'ratio_after_bytes' => 64 * 1024 ** 2,
        'max_rows' => 5_000_000,
        'max_columns' => 2_000,
        'max_cell_bytes' => 1024 ** 2,
        // How deep an XML or JSON item may nest before it is refused.
        'max_depth' => 32,
    ],

    'profile' => [
        // Rows kept for the mapping review, and example values kept per column.
        'sample_rows' => 30,
        'sample_values' => 5,
        // Non-empty values per column used to recognise its kind (number, GTIN, URL…).
        'kind_sample' => 1_000,
        // Distinct values tracked when checking that a SKU column is unique.
        'max_tracked_skus' => 1_000_000,
    ],

    'fetch' => [
        'connect_timeout' => 15,
        'timeout' => 1_800,
        'max_redirects' => 5,
        // Development and end-to-end tests only: lets a feed URL point at 127.0.0.1.
        'allow_private_networks' => TypedEnv::bool('FEEDGENIX_FETCH_ALLOW_PRIVATE', false),
        'user_agent' => 'FeedGenix/1.0 (+https://feedgenix.com)',
    ],
];
