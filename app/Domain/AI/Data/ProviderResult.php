<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/** What a provider returned for one attempt, before the gateway checks it. */
final readonly class ProviderResult
{
    /** @var non-empty-list<BilledUsage> every model this attempt was billed on */
    public array $billed;

    /**
     * @param  array<string, mixed>  $values  item id => answer
     * @param  string  $model  the model that produced the answer
     * @param  list<BilledUsage>  $billed  per-model usage; defaults to $usage on $model
     */
    public function __construct(
        public array $values,
        public AiUsage $usage,
        public string $model,
        public bool $truncated = false,
        array $billed = [],
    ) {
        $this->billed = $billed !== [] ? $billed : [new BilledUsage($model, $usage)];
    }
}
