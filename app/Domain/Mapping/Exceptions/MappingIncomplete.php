<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The mapping cannot be approved yet (422), with a code the screen can act on. */
final class MappingIncomplete extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->reason, 'message' => $this->getMessage()], 422);
    }
}
