<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/** One piece of work in a call, with the id its answer must come back under (ADR 0004). */
final readonly class AiItem
{
    public function __construct(
        public string $id,
        public string $text,
    ) {}
}
