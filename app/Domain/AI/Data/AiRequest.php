<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\AiPurpose;
use InvalidArgumentException;

/**
 * One call to a model through the gateway (ADR 0004). The workspace is explicit, never ambient; the
 * items carry ids and each answer must come back under its id, validated against $valueSchema.
 */
final readonly class AiRequest
{
    /** How items and schemas are encoded for a provider; Pricing bounds the cost on the same encoding. */
    public const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param  list<AiItem>  $items
     * @param  array<string, mixed>  $valueSchema  the JSON schema of one item's answer
     */
    public function __construct(
        public int $tenantId,
        public AiPurpose $purpose,
        public Prompt $prompt,
        public array $items,
        public array $valueSchema,
        public int $maxOutputTokens,
        public ?int $reservationId,
        public int $attempt = 1,
        public ?string $model = null,
        public ?string $provider = null,
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('A model call needs at least one item.');
        }
        $ids = array_map(static fn (AiItem $item): string => $item->id, $items);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Item ids must be unique within a call.');
        }
        if ($maxOutputTokens < 1) {
            throw new InvalidArgumentException('maxOutputTokens must be positive.');
        }
    }

    /** The model and provider the router chose for this attempt. */
    public function routedTo(string $model, string $provider): self
    {
        return new self($this->tenantId, $this->purpose, $this->prompt, $this->items, $this->valueSchema,
            $this->maxOutputTokens, $this->reservationId, $this->attempt, $model, $provider);
    }

    public function withAttempt(int $attempt): self
    {
        return new self($this->tenantId, $this->purpose, $this->prompt, $this->items, $this->valueSchema,
            $this->maxOutputTokens, $this->reservationId, $attempt, $this->model, $this->provider);
    }

    /** @return list<string> */
    public function itemIds(): array
    {
        return array_map(static fn (AiItem $item): string => $item->id, $this->items);
    }
}
