<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Data;

/**
 * The pipeline's verdict for one field in one locale: mapped from a column, composed, or left
 * empty (refused, or cleared by a person), with the reason a reviewer reads.
 */
final readonly class Decision
{
    /**
     * @param  'direct'|'compose'|'none'  $strategy
     * @param  'validated'|'mapped'|'needs_input'|'composed'|'rejected'|'cleared'  $status
     * @param  list<array{type: string, severity: string, count: int, message: string}>  $issues
     */
    public function __construct(
        public string $target,
        public ?string $locale,
        public string $strategy,
        public ?string $column,
        public ?Recipe $recipe,
        public ?string $candidate,
        public string $origin,
        public float $confidence,
        public ?string $reason,
        public string $status,
        public array $issues,
    ) {}

    public function key(): string
    {
        return $this->locale === null ? $this->target : "{$this->target}@{$this->locale}";
    }

    public function isFilled(): bool
    {
        return $this->strategy !== 'none';
    }
}
