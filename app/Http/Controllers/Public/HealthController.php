<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness and database readiness, for uptime checks and the post-deploy smoke test.
 *
 * It never reveals versions, hosts or error messages, and it never starts a session.
 */
final class HealthController
{
    /**
     * Health check.
     *
     * `200` when the database answers, `503` when it does not.
     */
    public function __invoke(): JsonResponse
    {
        if (! $this->databaseAnswers()) {
            return response()->json(['status' => 'degraded', 'database' => 'down'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'ok']);
    }

    private function databaseAnswers(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
