<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Data;

use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\Target;

/**
 * What a request changes about where a feed comes from. Each "change" flag says whether the value
 * was sent at all: not sent keeps the stored one (legacy D16), sent as null removes it.
 */
final readonly class SourceChange
{
    public function __construct(
        public ?Target $target = null,
        public bool $changeLogin = false,
        public ?Credentials $login = null,
        public bool $changeHostKey = false,
        public ?string $hostKey = null,
    ) {}
}
