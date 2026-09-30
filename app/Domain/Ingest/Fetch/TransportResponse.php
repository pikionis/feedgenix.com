<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

final readonly class TransportResponse
{
    /** @param array<string, string> $headers lower-case names */
    public function __construct(
        public int $status,
        public array $headers,
        public int $bytes,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
