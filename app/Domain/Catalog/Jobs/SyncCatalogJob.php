<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\CatalogSyncer;
use App\Domain\Feeds\Models\PipelineRun;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a run's import into the catalogue, as the workspace that started it, then waits for the
 * translation plan. As long as an import of the largest plan's feed (three hours). One attempt:
 * a failed sync is visible on the run and started again by a person or the next file.
 */
#[Timeout(self::TIMEOUT)]
#[Tries(1)]
final class SyncCatalogJob implements ShouldQueue
{
    use Queueable;

    public const int TIMEOUT = 10_800;

    public function __construct(
        public readonly int $runId,
        public readonly int $tenantId,
    ) {}

    public function handle(CatalogSyncer $syncer): void
    {
        $run = PipelineRun::query()->find($this->runId);
        if (! $run instanceof PipelineRun || $run->stage !== 'sync' || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $mapping = Mapping::query()->find($run->mapping_id);
        $import = Import::query()->find($run->import_id);
        if (! $mapping instanceof Mapping || ! $import instanceof Import || ! $mapping->isApproved()) {
            $this->fail($run, 'mapping_changed', 'The mapping is no longer approved. Approve it again to read the feed.');

            return;
        }

        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
        try {
            $result = $syncer->sync($mapping, $import);
        } catch (Throwable $exception) {
            Log::error('Catalogue sync failed.', ['run_id' => $run->id, 'exception' => $exception->getMessage()]);
            $this->fail($run, 'sync_failed', 'The feed could not be read into the catalogue. Try again; if it keeps failing, contact us.');

            return;
        }

        $run->forceFill([
            'stage' => 'plan',
            'status' => 'awaiting_decision',
            'counts' => $result->counts,
            'warnings' => $result->warnings,
        ])->save();
    }

    /** Timed out or killed: the run must not stay "running". */
    public function failed(?Throwable $exception): void
    {
        resolve(TenantContext::class)->run($this->tenantId, function (): void {
            PipelineRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])
                ->update(['status' => 'failed', 'failure_reason' => 'interrupted', 'failure_message' => 'Reading the feed into the catalogue stopped unexpectedly. Try again.', 'finished_at' => now()]);
        });
    }

    private function fail(PipelineRun $run, string $reason, string $message): void
    {
        $run->forceFill(['status' => 'failed', 'failure_reason' => $reason, 'failure_message' => $message, 'finished_at' => now()])->save();
    }
}
