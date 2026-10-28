<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * What does not move, across every workspace (ADR 0026): runs that failed, wait or seem stuck,
 * batches held by reason, the queue, failed jobs, and whether the reconciler still runs. For staff
 * only; a customer sees their own runs on their own pages.
 */
final readonly class Work
{
    /** The reconciler runs every minute; five quiet minutes mean the scheduler stopped. */
    public const int RECONCILER_SILENT_AFTER_SECONDS = 300;

    /** A run queued or running this long without a change is shown as stuck. */
    public const int STUCK_AFTER_MINUTES = 30;

    /** How far back failed runs are shown. */
    public const int FAILED_DAYS = 7;

    private const string HEARTBEAT = 'ops:reconcile:last';

    private const int ERROR_CHARS = 160;

    public function __construct(private TenantContext $context) {}

    /** Called by the reconciler at the end of every pass. */
    public static function reconcilerRan(): void
    {
        Cache::put(self::HEARTBEAT, CarbonImmutable::now()->toIso8601String(), CarbonImmutable::now()->addDay());
    }

    /**
     * @return array{
     *     reconciler: array{last_at: string|null, running: bool},
     *     queue: array{pending: int, failed_24h: int},
     *     runs: list<array{id: int, tenant_id: int, workspace: string, feed_id: int, feed: string, stage: string, status: string, stuck: bool, failure_message: string|null, updated_at: string|null}>,
     *     held: list<array{reason: string, units: int, workspaces: int}>,
     *     failed_jobs: list<array{id: string, job: string, failed_at: string|null, error: string}>
     * }
     */
    public function snapshot(): array
    {
        $last = Cache::get(self::HEARTBEAT);
        $lastAt = is_string($last) ? CarbonImmutable::parse($last) : null;

        return $this->context->runAsSystem('staff: the work across workspaces', function () use ($lastAt): array {
            $now = CarbonImmutable::now();

            return [
                'reconciler' => [
                    'last_at' => $lastAt?->toIso8601String(),
                    'running' => $lastAt instanceof CarbonImmutable && $lastAt->greaterThan($now->subSeconds(self::RECONCILER_SILENT_AFTER_SECONDS)),
                ],
                'queue' => [
                    'pending' => Queue::size(),
                    'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', $now->subDay())->count(),
                ],
                'runs' => $this->runs($now),
                'held' => array_values(DB::table('ai_work_units')->where('status', 'held')
                    ->selectRaw('coalesce(hold_reason, ?) AS reason, count(*) AS units, count(DISTINCT tenant_id) AS workspaces', ['unknown'])
                    ->groupBy('reason')->orderByDesc('units')->get()
                    ->map(static fn (object $held): array => [
                        'reason' => (string) $held->reason, 'units' => (int) $held->units, 'workspaces' => (int) $held->workspaces,
                    ])->all()),
                'failed_jobs' => array_values(DB::table('failed_jobs')->orderByDesc('id')->limit(10)->get(['uuid', 'payload', 'exception', 'failed_at'])
                    ->map(static fn (object $failed): array => [
                        'id' => (string) $failed->uuid,
                        'job' => self::jobName((string) $failed->payload),
                        'failed_at' => $failed->failed_at === null ? null : CarbonImmutable::parse((string) $failed->failed_at)->toIso8601String(),
                        'error' => self::firstLine((string) $failed->exception),
                    ])->all()),
            ];
        });
    }

    /** @return list<array{id: int, tenant_id: int, workspace: string, feed_id: int, feed: string, stage: string, status: string, stuck: bool, failure_message: string|null, updated_at: string|null}> */
    private function runs(CarbonImmutable $now): array
    {
        $stuckBefore = $now->subMinutes(self::STUCK_AFTER_MINUTES);
        $rows = DB::table('pipeline_runs AS r')
            ->join('tenants AS t', 't.id', '=', 'r.tenant_id')
            ->join('feeds AS f', 'f.id', '=', 'r.feed_id')
            ->where(static function (Builder $runs) use ($now, $stuckBefore): void {
                $runs->where(static fn (Builder $failed) => $failed->where('r.status', 'failed')->where('r.updated_at', '>=', $now->subDays(self::FAILED_DAYS)))
                    ->orWhere('r.status', 'held')
                    ->orWhere(static fn (Builder $stuck) => $stuck->whereIn('r.status', ['queued', 'running'])->where('r.updated_at', '<', $stuckBefore));
            })
            ->latest('r.updated_at')->limit(50)
            ->get(['r.id', 'r.tenant_id', 't.name AS workspace', 'r.feed_id', 'f.name AS feed', 'r.stage', 'r.status', 'r.failure_message', 'r.updated_at']);

        return array_values($rows->map(static function (object $run) use ($stuckBefore): array {
            $updated = $run->updated_at === null ? null : CarbonImmutable::parse((string) $run->updated_at);

            return [
                'id' => (int) $run->id,
                'tenant_id' => (int) $run->tenant_id,
                'workspace' => (string) $run->workspace,
                'feed_id' => (int) $run->feed_id,
                'feed' => (string) $run->feed,
                'stage' => (string) $run->stage,
                'status' => (string) $run->status,
                'stuck' => in_array($run->status, ['queued', 'running'], true) && $updated instanceof CarbonImmutable && $updated->lessThan($stuckBefore),
                'failure_message' => $run->failure_message === null ? null : (string) $run->failure_message,
                'updated_at' => $updated?->toIso8601String(),
            ];
        })->all());
    }

    /** The job's class, without its namespace: what a person needs to find it. */
    private static function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $name = is_array($decoded) && is_string($decoded['displayName'] ?? null) ? $decoded['displayName'] : 'Unknown job';

        return class_basename($name);
    }

    /** The exception's first line, cut: the trace stays in the database. */
    private static function firstLine(string $exception): string
    {
        $line = trim(strtok($exception, "\n") ?: '');

        return mb_strlen($line) > self::ERROR_CHARS ? mb_substr($line, 0, self::ERROR_CHARS).'…' : $line;
    }
}
