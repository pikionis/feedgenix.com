<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** An import was read and profiled: its rows are available to the next stages. */
final readonly class ImportProfiled
{
    use Dispatchable;

    public function __construct(public int $importId) {}
}
