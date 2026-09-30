<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Jobs;

use App\Domain\Ingest\ImportRunner;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Runs one import in the background, as the workspace that queued it. One attempt: a failed
 * import is re-run by the customer or the schedule, never silently retried mid-way.
 */
#[Timeout(3_600)]
#[Tries(1)]
final class ProcessImport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $importId,
        public readonly int $tenantId,
    ) {}

    public function handle(ImportRunner $runner): void
    {
        $import = Import::query()->find($this->importId);
        if (! $import instanceof Import || ! $import->status->isUnfinished()) {
            return;
        }

        $runner->run($import);
    }

    /** Timed out, killed or crashed: the import must not stay "reading" for ever. */
    public function failed(?Throwable $exception): void
    {
        resolve(TenantContext::class)->run($this->tenantId, function (): void {
            Import::query()
                ->whereKey($this->importId)
                ->whereIn('status', [ImportStatus::Queued, ImportStatus::Fetching, ImportStatus::Reading])
                ->update([
                    'status' => ImportStatus::Failed,
                    'failure_reason' => 'interrupted',
                    'failure_message' => 'The import stopped unexpectedly. Try it again; if it keeps failing, contact us.',
                    'finished_at' => now(),
                ]);
        });
    }
}
