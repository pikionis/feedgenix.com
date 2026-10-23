<?php

declare(strict_types=1);

namespace App\Domain\Quality;

/** What the checks found: the failed checks, and those that keep a translation from being published. */
final readonly class QualityResult
{
    /**
     * @param  list<string>  $failures
     * @param  list<string>  $hard
     */
    public function __construct(
        public array $failures,
        public array $hard,
    ) {}

    public function isHardFail(): bool
    {
        return $this->hard !== [];
    }

    public function isClean(): bool
    {
        return $this->failures === [];
    }

    /** @return array{failures: list<string>, hard: bool} */
    public function toArray(): array
    {
        return ['failures' => $this->failures, 'hard' => $this->isHardFail()];
    }
}
