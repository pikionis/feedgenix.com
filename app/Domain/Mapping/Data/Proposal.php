<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Data;

/**
 * A suggestion that a field (in a locale) comes from a column, or is composed from several. It
 * comes from the heuristics, the AI or a person, and is only a suggestion until the mapping
 * pipeline has checked it against the columns, the golden rule and the values.
 */
final readonly class Proposal
{
    public function __construct(
        public string $target,
        public ?string $locale,
        public ?string $column,
        public ?Recipe $recipe,
        public float $confidence,
        public ?string $reason,
        public string $origin,
    ) {}

    public static function direct(string $target, ?string $locale, string $column, float $confidence, ?string $reason, string $origin): self
    {
        return new self($target, $locale, $column, null, $confidence, $reason, $origin);
    }

    public static function composed(string $target, ?string $locale, Recipe $recipe, float $confidence, ?string $reason, string $origin): self
    {
        return new self($target, $locale, null, $recipe, $confidence, $reason, $origin);
    }

    /** A person's explicit "leave this empty". */
    public static function cleared(string $target, ?string $locale): self
    {
        return new self($target, $locale, null, null, 1.0, null, 'manual');
    }

    public function key(): string
    {
        return $this->locale === null ? $this->target : "{$this->target}@{$this->locale}";
    }
}
