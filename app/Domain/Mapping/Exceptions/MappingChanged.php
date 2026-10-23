<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Someone changed the mapping since it was loaded: reload before editing (409). */
final class MappingChanged extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The mapping changed since you loaded it. Reload it and try again.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => 'mapping_changed', 'message' => $this->getMessage(), 'version' => $this->currentVersion], 409);
    }
}
