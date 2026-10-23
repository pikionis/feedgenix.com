<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Schema;

/** How a canonical field is treated (ADR 0003). */
enum FieldClass: string
{
    case Golden = 'golden';
    case PassThrough = 'pass_through';
    case Translatable = 'translatable';
    case Composable = 'composable';
    case OptimizeOnly = 'optimize_only';
    case Derived = 'derived';

    /** Byte-identical, and never in a prompt, a composition or a value map (rule 1). */
    public function isGolden(): bool
    {
        return $this === self::Golden;
    }

    /** Stored once per target language. */
    public function isLocalized(): bool
    {
        return in_array($this, [self::Translatable, self::Composable, self::OptimizeOnly], true);
    }

    /** Can be filled from feed columns (optimize-only and derived fields are produced, not mapped). */
    public function isMappable(): bool
    {
        return ! in_array($this, [self::OptimizeOnly, self::Derived], true);
    }
}
