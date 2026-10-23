<?php

declare(strict_types=1);

namespace App\Domain\AI;

/** What a model call is for: it picks the route (config ai.routes) and whether a fallback is allowed. */
enum AiPurpose: string
{
    case Mapping = 'mapping';
    case Quality = 'quality';
    case Translation = 'translation';

    /** Reasoning purposes may fall back to another provider; translation never does (ADR 0004). */
    public function allowsFallback(): bool
    {
        return $this !== self::Translation;
    }
}
