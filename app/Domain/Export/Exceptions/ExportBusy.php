<?php

declare(strict_types=1);

namespace App\Domain\Export\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A build of this profile is already running: one at a time (409). */
final class ExportBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This export is being built right now. It is published when it is done.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => 'export_busy', 'message' => $this->getMessage()], 409);
    }
}
