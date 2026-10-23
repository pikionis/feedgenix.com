<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A run read its file into the catalogue and now waits for the translation plan. */
final readonly class CatalogSynced
{
    use Dispatchable;

    public function __construct(
        public int $runId,
        public int $feedId,
    ) {}
}
