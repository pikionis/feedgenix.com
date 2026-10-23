<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/**
 * What the provider billed for one attempt, as it reported it. $inputTokens excludes cached input
 * (counted in $cachedInputTokens at its own price); thinking tokens are billed as output. Providers
 * normalise their reports to this shape.
 */
final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $thinkingTokens = 0,
        public int $cachedInputTokens = 0,
    ) {}

    public function isZero(): bool
    {
        return $this->inputTokens === 0 && $this->outputTokens === 0 && $this->thinkingTokens === 0 && $this->cachedInputTokens === 0;
    }
}
