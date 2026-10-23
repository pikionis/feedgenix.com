<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A person approved a mapping: the feed's rows can now be read into the catalogue. */
final readonly class MappingApproved
{
    use Dispatchable;

    public function __construct(public int $mappingId) {}
}
