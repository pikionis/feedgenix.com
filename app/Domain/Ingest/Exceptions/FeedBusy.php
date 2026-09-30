<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The feed already has an import running: one at a time per feed. */
final class FeedBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This feed is already being imported. Wait for that import to finish.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => 'feed_busy', 'message' => $this->getMessage()], 409);
    }
}
