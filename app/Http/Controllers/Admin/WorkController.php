<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\Work;
use Illuminate\Http\JsonResponse;

/** What does not move, across every workspace (ADR 0026). */
final readonly class WorkController
{
    public function __construct(private Work $work) {}

    /** Runs that failed (7 days), wait or seem stuck (30 minutes without a change), held batches by reason, the queue, failed jobs and the reconciler. */
    public function __invoke(): JsonResponse
    {
        $snapshot = $this->work->snapshot();

        return response()->json([
            /** @var array{last_at: string|null, running: bool} */
            'reconciler' => $snapshot['reconciler'],
            /** @var array{pending: int, failed_24h: int} */
            'queue' => $snapshot['queue'],
            /** @var list<array{id: int, tenant_id: int, workspace: string, feed_id: int, feed: string, stage: string, status: string, stuck: bool, failure_message: string|null, updated_at: string|null}> */
            'runs' => $snapshot['runs'],
            /** @var list<array{reason: string, units: int, workspaces: int}> */
            'held' => $snapshot['held'],
            /** @var list<array{id: string, job: string, failed_at: string|null, error: string}> */
            'failed_jobs' => $snapshot['failed_jobs'],
        ]);
    }
}
