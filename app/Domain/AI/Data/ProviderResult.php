<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/** What a provider returned for one attempt, before the gateway checks it. */
final readonly class ProviderResult
{
    /** @param array<string, mixed> $values  item id => answer */
    public function __construct(
        public array $values,
        public AiUsage $usage,
        public string $model,
        public bool $truncated = false,
    ) {}
}
