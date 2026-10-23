<?php

declare(strict_types=1);

namespace App\Domain\Export\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Someone changed the export since it was loaded: reload before saving (409). */
final class ProfileChanged extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The export changed since you loaded it. Reload it and try again.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => 'export_changed', 'message' => $this->getMessage(), 'version' => $this->currentVersion], 409);
    }
}
