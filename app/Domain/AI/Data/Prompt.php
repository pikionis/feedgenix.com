<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/**
 * A versioned prompt (`map@1`). Instructions and content stay apart: the items travel as data, never
 * pasted into the instructions, so product text cannot become an instruction.
 */
final readonly class Prompt
{
    public function __construct(
        public string $name,
        public string $version,
        public string $system,
        public string $instructions,
    ) {}

    public function ref(): string
    {
        return "{$this->name}@{$this->version}";
    }
}
