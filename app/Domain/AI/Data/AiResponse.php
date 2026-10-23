<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/**
 * A checked answer: exactly one value per item id. $millicredits is what this call was charged
 * (provider cost × 1.1); it is internal and never leaves the server.
 */
final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>  $values  item id => answer
     * @param  list<BilledUsage>  $billed  per-model usage; defaults to $usage on $model
     */
    public function __construct(
        public array $values,
        public AiUsage $usage,
        public string $model,
        public string $provider,
        public int $attempt,
        public int $millicredits = 0,
        public array $billed = [],
    ) {}

    /** @return non-empty-list<BilledUsage> */
    public function billedUsage(): array
    {
        return $this->billed !== [] ? $this->billed : [new BilledUsage($this->model, $this->usage)];
    }

    public function withCharge(int $millicredits): self
    {
        return new self($this->values, $this->usage, $this->model, $this->provider, $this->attempt, $millicredits, $this->billed);
    }
}
