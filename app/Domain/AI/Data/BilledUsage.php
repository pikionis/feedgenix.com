<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/**
 * Usage billed on one model within an attempt. Usually an attempt has one; a server-side refusal
 * fallback (Claude) bills the declined hop and the serving model separately, each at its own rates.
 */
final readonly class BilledUsage
{
    public function __construct(
        public string $model,
        public AiUsage $usage,
    ) {}
}
