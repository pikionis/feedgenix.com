<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use LogicException;

final readonly class FetchSettings
{
    public function __construct(
        public int $maxBytes,
        public int $connectTimeout = 15,
        public int $timeout = 1_800,
        public int $maxRedirects = 5,
        public bool $allowPrivateNetworks = false,
        public string $userAgent = 'FeedGenix/1.0',
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{connect_timeout: int, timeout: int, max_redirects: int, allow_private_networks: bool, user_agent: string} $fetch */
        $fetch = config('ingest.fetch');

        // Reaching private addresses is for development and end-to-end tests only.
        if ($fetch['allow_private_networks'] && app()->isProduction()) {
            throw new LogicException('FEEDGENIX_FETCH_ALLOW_PRIVATE must never be on in production.');
        }

        return new self(
            maxBytes: (int) config('ingest.limits.max_bytes'),
            connectTimeout: $fetch['connect_timeout'],
            timeout: $fetch['timeout'],
            maxRedirects: $fetch['max_redirects'],
            allowPrivateNetworks: $fetch['allow_private_networks'],
            userAgent: $fetch['user_agent'],
        );
    }
}
