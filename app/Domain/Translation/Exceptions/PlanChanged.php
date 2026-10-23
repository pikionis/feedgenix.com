<?php

declare(strict_types=1);

namespace App\Domain\Translation\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Someone changed the translation plan since it was loaded: reload before saving (409). */
final class PlanChanged extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The translation plan changed since you loaded it. Reload it and try again.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => 'plan_changed', 'message' => $this->getMessage(), 'version' => $this->currentVersion], 409);
    }
}
